# eelKit Security Controls

This document describes the security controls built into eelKit as of 21 July 2026. It is a code-level control catalogue, not a claim that an application is secure merely because it uses the framework. Downstream applications must use the framework APIs correctly, keep dependencies and PHP current, and deploy the application behind an appropriately configured HTTPS web server.

## Control summary

| Area | Built-in control |
| --- | --- |
| Passwords | Argon2id hashing, application pepper, password policy, transparent rehashing, forced password changes |
| Multi-factor authentication | TOTP enrollment and verification, encrypted secrets, replay prevention, expiring challenge state |
| Request integrity | Session CSRF tokens and single-use authenticated AJAX nonces |
| Sessions | Strict cookie-only PHP sessions, configurable secure/SameSite cookies, session ID rotation, device binding, server-side session validation |
| Brute-force resistance | Login throttling and scoped lockouts; invite-token and identity-verification rate limits |
| Authorization | Authentication gate, role-based card permissions, built-in administrator role, developer-page availability policy |
| Invitations | Cryptographically random expiring tokens, token hashing, identity verification, lockout, revocation, single completion |
| Browser protection | CSP and defensive response headers; no-store signup responses |
| Data handling | Prepared database statements, central HTML escaping, sanitized download filenames |
| Secrets | Non-public security store, generated random facts, file locking, Unix `0600` permissions |
| Auditability | Login history, account audit history, request/device/IP metadata, session replacement events |
| Network trust | Trusted-proxy allowlist before forwarded headers are accepted; SMTP TLS support |
| Bootstrap | Random first-user code stored outside the web root and removed after successful use |

## Password protection

`UserAuthenticationService` applies the following controls:

- Passwords are hashed with PHP's `PASSWORD_ARGON2ID` algorithm and the runtime's default Argon2 memory, time, and thread costs.
- Before hashing, the password is transformed with HMAC-SHA-256 using an application-specific pepper from `SecurityStore`.
- Verification uses `password_verify()` and the same peppered input.
- A successful login transparently replaces an obsolete hash when `password_needs_rehash()` reports that the algorithm or cost settings have changed.
- Password hashes are removed from the arrays returned by public user lookup and authentication methods.
- The server-side password policy requires at least 12 characters, including an uppercase letter, lowercase letter, number, and symbol. Client-side constraints are only a usability aid; the service enforces the policy again.
- An administrator-set password marks the account for a required password change. The user must complete that change before the OTP step.
- Self-service account changes require the current password when protected account details or the password are changed.
- Inactive users and accounts whose status is not `active` cannot authenticate.

The pepper is a separate secret from the password hashes. A database-only compromise therefore does not provide everything needed to test password guesses. Losing or changing the pepper makes existing hashes unverifiable, so it must be backed up securely.

## TOTP multi-factor authentication

`OtpService`, `OtpVerificationService`, `LoginService`, and `SessionAuthenticationService` implement authenticator-app TOTP:

- New secrets contain 20 cryptographically random bytes and are Base32 encoded.
- The default TOTP profile is SHA-1, 6 digits, and a 30-second period, for broad authenticator compatibility.
- Verification accepts the current timestep plus one timestep on either side to tolerate normal clock skew.
- Comparisons use `hash_equals()`.
- The last accepted timestep is stored and an equal or older timestep is rejected, preventing reuse of a valid code.
- A secret is not enabled until a code generated from it is successfully verified.
- Rotation uses a separate pending secret, so the active secret remains usable until the replacement is confirmed.
- Pending enrollment secrets expire after 300 seconds by default and are cleared lazily when found expired.
- Active and pending TOTP secrets are encrypted at rest with AES-256-GCM using a fresh 12-byte nonce and authentication tag for every encryption.
- The encryption key is derived with SHA-256 from a named random fact in `SecurityStore`. Legacy plaintext TOTP secrets are encrypted when read.
- Login OTP state is bound to the current device identifier and expires after 300 seconds by default.
- A pending login challenge permits five failed OTP attempts before the pending state is cleared.
- OTP can be required or optional per user; the default for new users is configurable at `user_defaults.new_user_otp_required` and is `true` in the framework defaults.
- OTP resets, rotations, successful MFA authentication, and challenge outcomes are audited.

TOTP depends on accurate server time and protection of the security key file. Operators should use reliable time synchronization and treat both database contents and the security store as sensitive.

## CSRF protection

`SessionAuthenticationService` creates a 256-bit random CSRF token per PHP session. Validation rejects blank tokens and uses `hash_equals()` for constant-time comparison.

Authentication, logout, and invited-account completion POST requests always require a valid token. The page/card action pipeline is controlled by `security.csrf_mode`:

- `required`: every POST containing `action` or `card_action` must provide a valid token.
- `supplied`: the framework validates a token when one is supplied, but accepts an action request with no token. This is the current compatibility default.
- `off`: the shared action guard does not validate CSRF tokens.

New and production applications should set `security.csrf_mode` to `required`. Forms should render the token through `CsrfGuardFramework::hiddenInput()` or the equivalent session token field. GET requests must remain read-only.

## Single-use AJAX nonces

Authenticated AJAX mutations receive a second request-integrity and replay control in addition to CSRF:

- Each authenticated session maintains a pool of three 256-bit random nonces.
- An authenticated AJAX POST containing a page action, card action, or table-export preparation request must provide `ajax_nonce`.
- A nonce is removed on first successful validation and immediately replaced.
- Missing, unknown, expired, or already-used nonces produce HTTP 409 and instruct the browser to reload.
- Nonce state is cleared on logout or authentication-state changes.
- The browser code reserves nonces while requests are in flight, enabling a small number of concurrent requests without reuse.

These nonces do not replace CSRF validation. They add single-use replay resistance to the authenticated AJAX path.

## Session and cookie security

`SessionAuthenticationService` and `UserSessionService` provide the following controls:

- PHP strict session mode is enabled.
- Sessions use cookies only; URL-based session identifiers are disabled.
- The session cookie is named `ELL_ID`, scoped to `/`, has no persistent lifetime, and is always `HttpOnly`.
- `SameSite` defaults to `Strict` and can be configured as `Strict`, `Lax`, or `None` with `session.cookie_samesite`.
- The `Secure` flag defaults to `auto`. It is enabled when the direct request is HTTPS, a trusted proxy reports HTTPS, or the configured invitation base URL uses HTTPS. It can be explicitly forced on or off with `session.cookie_secure`.
- The PHP session ID is regenerated, with the old session deleted, when authentication enters a pending state, completes, or logs out.
- Authenticated and pending authentication states are bound to a required client device identifier. A mismatch invalidates authentication or clears the pending flow.
- Pending OTP, OTP enrollment, and required-password-change states expire after 300 seconds by default.
- A separate 256-bit random server-side session token is stored as a SHA-256 value in both the PHP session and the user's current-session record.
- Every authenticated request validates the token, device identifier, active flag, and account status against the database.
- Only one current authenticated session is retained per account. A newer login replaces the previous session, and the displaced session is rejected on its next validation.
- Logout clears the server-side current-session record as well as local authentication state.
- Session start, last-seen time, device, IP address, user agent, and browser label are recorded for visibility and incident review.

When `SameSite=None` is selected, `session.cookie_secure` must also resolve to `true` for modern browsers to accept the cookie. Production deployments should force HTTPS and should normally force the Secure flag rather than relying on detection.

## Login throttling and lockout

Failed primary-password authentication is tracked by normalized email address and, where the current schema supports scoped rows, also by client IP address and device identifier:

- From the third consecutive failure, each failure imposes a 30-second delay before another login is allowed.
- The email scope locks after 20 consecutive failures.
- IP and device scopes lock after 10 consecutive failures.
- Locks expire after 15 minutes.
- Expired lockout rows are removed lazily.
- A successful authentication clears the applicable rate-limit state.
- Administrators can review and reset login lockouts; resets are audited.
- Login failures use public-facing responses that do not need to disclose whether the email address exists.

Accurate IP-based controls depend on correct trusted-proxy configuration. Rate limiting is database-backed, so it is shared across application workers that use the same database.

## Authentication and authorization

The front controller and framework authorization services enforce several layers:

- Unauthenticated page requests are stopped by `PageRequestGuard`; AJAX callers receive HTTP 401 and an explicit authentication-required response.
- The current database session is validated before the request reaches protected page behavior.
- `CardAccessFramework` filters rendered card keys using the user's role and the `role_card_permissions` table.
- Users without a valid role receive no role-controlled cards.
- The built-in administrator role (`-1`) receives all requested cards and is reserved by the role-assignment service.
- User and role management services perform server-side administrator authorization checks before mutations.
- Developer-only pages configured in `navigation.developer_only_pages` return HTTP 404 unless `developer_options` is enabled.
- Page and card keys are normalized before they are resolved to framework files.

Hiding a card is not a substitute for authorization inside a downstream mutation service. Downstream actions that can be invoked independently must perform their own server-side permission check or use an established authorized service.

## Invitation and account-completion controls

The invited-user workflow includes:

- Invitation tokens are 256-bit random hexadecimal values.
- A SHA-256 token hash is used for lookup and comparison.
- Invitations expire after five days by default; `invitation.expiry_days` is clamped to 1 through 31 days.
- Generating a new invite revokes other usable invites for the user.
- Invite status tracks pending, sent, opened, verified, completed, expired, revoked, and locked states.
- Revoked, expired, locked, completed, or otherwise unusable invites cannot complete an account.
- Opening and using an invitation records client IP metadata.
- Before setting a password, the invitee must verify both the expected email address and mobile number. Comparisons use `hash_equals()` after normalization.
- Eight failed identity-verification attempts lock the individual invitation for 15 minutes.
- Token probing is separately limited by client IP: five failures inside 120 seconds cause a 15-minute block.
- Identity verification is separately limited by client IP and a SHA-256 hash of the PHP session ID: five failures inside 120 seconds cause a 15-minute block.
- Successful identity verification creates a server-side completion session that expires after 15 minutes.
- Completion requires a valid CSRF token, verified completion session, matching password confirmation, and the normal password policy.
- User activation, password creation, and invite completion occur in a database transaction.
- Signup pages use `Referrer-Policy: no-referrer` and no-store/no-cache headers so token-bearing URLs and sensitive form state are less likely to leak or persist.
- Invite creation, delivery, opening, failed and successful verification, expiry, revocation, locking, and completion are audited.

### Invitation-token storage caveat

The database stores both `token_hash` and `token_value`. The raw value allows an unexpired link to be redisplayed or resent, but a read compromise of `user_account_invites` can therefore expose usable invitation links. Restrict database access, keep invite lifetimes short, revoke suspected links, and avoid copying production data into less trusted environments.

## Browser and response protections

`ResponseFramework` removes the `X-Powered-By` and obsolete `X-XSS-Protection` headers and adds these headers to framework HTML, JSON, and download responses:

- `Content-Security-Policy`: self-only default, scripts, styles, connections, form actions, and base URLs; images may also use `data:`; objects are disabled; framing is limited to the same origin.
- `X-Frame-Options: SAMEORIGIN`.
- `X-Content-Type-Options: nosniff`.
- `Referrer-Policy: strict-origin-when-cross-origin`.
- `Permissions-Policy` disables accelerometer, camera, geolocation, gyroscope, magnetometer, microphone, payment, and USB access.
- `X-Permitted-Cross-Domain-Policies: none`.
- `Cross-Origin-Opener-Policy: same-origin`.
- `Cross-Origin-Resource-Policy: same-origin`.

Downloads additionally use sanitized filenames, an explicit content length, and no-store/no-cache headers.

The framework does not currently emit HTTP Strict Transport Security (HSTS). That should normally be configured at the TLS-terminating web server or reverse proxy after confirming the site is HTTPS-only.

## Output, input, and database handling

- `HelperFramework::escape()` applies `htmlspecialchars()` with `ENT_QUOTES` and UTF-8 and is used throughout framework renderers for dynamic HTML text and attributes.
- Browser-generated flash messages are escaped before insertion into HTML.
- `InterfaceDB` routes parameterized operations through PDO prepared statements and enables exception error mode.
- Table helpers validate identifiers rather than accepting arbitrary user-provided table or column expressions.
- Download and export filenames are restricted to safe filename characters.
- Email addresses, mobile numbers, IP addresses, hostnames, schemes, page keys, card keys, and other structured values are normalized or validated by their owning services.

Downstream code must continue these patterns. Never concatenate request data into SQL, HTML, response headers, filesystem paths, or shell commands.

## Secret and configuration storage

`SecurityStore` keeps application facts and API credentials outside `web_root` by default:

- Password peppers and TOTP encryption material are generated with `random_bytes(32)` when absent.
- Generated facts are hexadecimal 256-bit values.
- Updates take an exclusive file lock and rewrite facts in a stable format.
- On Unix-like systems the security key file is forced to mode `0600`.
- API credentials are selected by provider, tag, and TEST/LIVE environment rather than embedded in application source.
- `secure/README.md` explicitly requires the directory to remain outside public serving paths and out of version control.

Windows ACLs are not managed by the PHP file-mode control. Operators must restrict the `secure` directory using the host operating system's access controls. Secrets should be backed up securely, excluded from logs, and rotated through a planned process.

## First-user bootstrap

When the database has no user, `FirstUserBootstrapService` protects initial administrator creation with a 96-bit random hexadecimal bootstrap code:

- The code is written to `secure/bootstrap_code.txt`, outside `web_root`.
- Submitted codes are format checked, normalized, and compared with `hash_equals()`.
- The code file is deleted after the first user is successfully created.
- Initial user creation still requires CSRF validation and the normal password policy.
- TOTP enrollment begins immediately after the first account is created.

Filesystem access to the bootstrap file is equivalent to permission to create the initial administrator. Confirm that it is absent after provisioning.

## Trusted proxies and transport

`ReverseProxyService` accepts forwarded client IP, host, and scheme values only when the direct peer IP appears in `reverse_proxy.trusted_proxy_ips`:

- Untrusted callers cannot override the direct remote address with forwarding headers.
- Forwarded IP values must parse as valid IPv4 or IPv6 addresses.
- Forwarded hosts are length checked, reject control characters and path/user-info syntax, validate hostname labels or IP literals, and validate port ranges.
- Forwarded schemes are restricted to `http` or `https`.
- The resolved client IP feeds login/signup rate limits and audit metadata.

SMTP delivery supports STARTTLS and implicit TLS. Outbound API requests verify TLS peer and host by default unless a caller explicitly overrides cURL options; production callers should not disable those checks.

## Audit and security visibility

`UserHistoryStore`, `ActivityStore`, and the associated log cards provide persistent security-relevant history:

- Login success and failure, logout, forced logout, session replacement, OTP challenge success/failure, and OTP setup events.
- Account creation, enable/disable, password changes, OTP changes, role changes, lockout resets, and invitation lifecycle events.
- Actor and affected user IDs, reason, structured details, timestamp, device identifier, IP address, and user agent where available.
- Session token hashes connect related login/session events without storing the original random token.
- Application flash/action history captures page/action metadata and request context for operational review.

Audit tables are application records, not an immutable external audit log. Production environments that require tamper evidence or long-term retention should export security events to an access-controlled logging platform.

## Deployment responsibilities and known boundaries

The framework does not replace secure deployment. At minimum, operators should:

1. Serve only `web_root` and keep `secure`, source, tests, tools, and database artifacts outside the document root.
2. Enforce HTTPS, redirect HTTP to HTTPS, set `session.cookie_secure` to `true`, and configure HSTS at the edge when appropriate.
3. Set `security.csrf_mode` to `required` after confirming all downstream mutation forms include `csrf_token`.
4. Keep `session.cookie_samesite` at `Strict` unless a documented integration requires another value.
5. Configure only known reverse-proxy IPs and prevent direct access that bypasses the trusted proxy.
6. Apply restrictive filesystem ACLs to `secure/app.php`, `security.keys`, `api.keys`, and `bootstrap_code.txt`.
7. Use a least-privilege database account, protect backups, and avoid moving raw invitation tokens into non-production databases.
8. Keep PHP, the web server, database, operating system, and dependencies patched.
9. Protect logs because they contain security metadata and may contain personal data.
10. Review downstream pages, cards, actions, services, raw responses, uploads, and integrations for controls that the framework cannot enforce automatically.
11. Back up the password pepper and TOTP encryption fact securely; test recovery without exposing the values.
12. Monitor login, signup, invite, session-replacement, and administrative audit events.

## Primary implementation references

- `web_root/classes/service/UserAuthenticationService.php`
- `web_root/classes/service/OtpService.php`
- `web_root/classes/service/OtpVerificationService.php`
- `web_root/classes/service/SessionAuthenticationService.php`
- `web_root/classes/service/UserSessionService.php`
- `web_root/classes/service/LoginService.php`
- `web_root/classes/framework/CsrfGuardFramework.php`
- `web_root/classes/guard/PageRequestGuard.php`
- `web_root/classes/framework/CardAccessFramework.php`
- `web_root/classes/framework/PageAccessFramework.php`
- `web_root/classes/framework/ResponseFramework.php`
- `web_root/classes/service/AccountInviteService.php`
- `web_root/classes/service/AccountCompletionService.php`
- `web_root/classes/service/AccountCompletionSessionService.php`
- `web_root/classes/service/SignupTokenRateLimitService.php`
- `web_root/classes/service/SignupVerificationRateLimitService.php`
- `web_root/classes/store/SecurityStore.php`
- `web_root/classes/store/UserHistoryStore.php`
- `web_root/classes/service/FirstUserBootstrapService.php`
- `web_root/classes/service/ReverseProxyService.php`
- `web_root/classes/db/InterfaceDB.php`
- `web_root/classes/db/PdoDB.php`
- `db_schema/eelKit.schema.sql`

Review this document whenever one of these security-sensitive components or its configuration defaults changes.
