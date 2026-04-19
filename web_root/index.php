<?php
declare(strict_types=1);

// Automatic Class Loader
require_once __DIR__ . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$sessionAuthenticationService = new SessionAuthenticationService();
$sessionAuthenticationService->startSession();
$userSessionService = new UserSessionService();

// Get http request details
$request = RequestFramework::fromGlobals();
$currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
$sessionAuthenticationService->invalidateForDeviceMismatch($currentDeviceId);
$authenticatedUserId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);
if ($authenticatedUserId > 0) {
    $sessionValidation = $userSessionService->validateAuthenticatedSession(
        $authenticatedUserId,
        $sessionAuthenticationService->authenticatedSessionTokenHash(),
        $currentDeviceId
    );

    if (empty($sessionValidation['valid'])) {
        $sessionAuthenticationService->logout((array)($sessionValidation['logout_notice'] ?? []));
    }
}
$userAuthenticationService = new UserAuthenticationService();

$loginService = new LoginService(
    $userAuthenticationService,
    new OtpService('EEL Accounts'),
    new QrCodeService(),
    $sessionAuthenticationService,
    $userSessionService,
    new UserHistoryStore()
);
$requiresInitialUserSetup = $authenticatedUserId <= 0 && !$userAuthenticationService->hasAnyUsers();
$bootstrapState = $requiresInitialUserSetup ? eel_bootstrap_code_state() : null;

$authAction = trim((string)$request->input('auth_action', ''));
$authResponse = eel_auth_response(
    $request,
    $authAction,
    $currentDeviceId,
    $loginService,
    $sessionAuthenticationService,
    $userAuthenticationService,
    $requiresInitialUserSetup,
    $bootstrapState
);

if ($authResponse instanceof ResponseFramework) {
    $authResponse->send();
    return;
}

$pageFactory = new PageFactoryFramework();
$page = $pageFactory->create($request->getPage());
$pageAccessResponse = eel_page_access_response(
    $request,
    $page,
    $sessionAuthenticationService,
    $currentDeviceId
);

if ($pageAccessResponse instanceof ResponseFramework) {
    $pageAccessResponse->send();
    return;
}

$config = AppConfigurationStore::config();
$uploadBasePath = (string)($config['uploads']['upload_base_dir'] ?? '');
$appServices = new AppService($uploadBasePath);
$pageServices = new PageServiceFramework($appServices->getMany($page->services()));
$response = $page->handle($request, $pageServices);
$response->send();

function eel_auth_response(
    RequestFramework $request,
    string $authAction,
    string $currentDeviceId,
    LoginService $loginService,
    SessionAuthenticationService $sessionAuthenticationService,
    UserAuthenticationService $userAuthenticationService,
    bool $requiresInitialUserSetup,
    ?array $bootstrapState = null
): ?ResponseFramework {
    if ($authAction === 'logout') {
        if (!$request->isPost() || !$sessionAuthenticationService->isValidCsrfToken((string)$request->post('csrf_token', ''))) {
            return ResponseFramework::html(
                eel_render_auth_state_page(
                    $sessionAuthenticationService,
                    $currentDeviceId,
                    $requiresInitialUserSetup,
                    $loginService,
                    ['Your security token expired. Please try again.']
                ),
                403
            );
        }

        $loginService->logout();

        return ResponseFramework::html(
            eel_render_auth_state_page(
                $sessionAuthenticationService,
                $currentDeviceId,
                $requiresInitialUserSetup,
                $loginService
            )
        );
    }

    if ($sessionAuthenticationService->isAuthenticated($currentDeviceId)) {
        return null;
    }

    if ($request->isPost()) {
        if (!$sessionAuthenticationService->isValidCsrfToken((string)$request->post('csrf_token', ''))) {
            return ResponseFramework::html(
                eel_render_auth_state_page(
                    $sessionAuthenticationService,
                    $currentDeviceId,
                    $requiresInitialUserSetup,
                    $loginService,
                    ['Your security token expired. Please try again.']
                ),
                403
            );
        }

        try {
            if ($requiresInitialUserSetup && $authAction === 'create_initial_user') {
                $enteredBootstrapCode = (string)$request->post('bootstrap_code', '');
                $bootstrapError = eel_validate_bootstrap_code($enteredBootstrapCode, $bootstrapState);

                if ($bootstrapError !== null) {
                    return ResponseFramework::html(
                        eel_render_initial_user_page(
                            $sessionAuthenticationService,
                            [$bootstrapError],
                            $enteredBootstrapCode
                        ),
                        400
                    );
                }

                $result = $userAuthenticationService->createInitialUser(
                    (string)$request->post('display_name', ''),
                    (string)$request->post('email_address', ''),
                    (string)$request->post('password', '')
                );

                if (!empty($result['success']) && (int)($result['user_id'] ?? 0) > 0) {
                    eel_delete_bootstrap_code_file($bootstrapState);
                    $setupData = $loginService->beginOtpSetup(
                        (int)$result['user_id'],
                        $currentDeviceId,
                        true
                    );

                    return ResponseFramework::html(
                        eel_render_otp_setup_page($sessionAuthenticationService, $setupData),
                        200
                    );
                }

                return ResponseFramework::html(
                    eel_render_initial_user_page(
                        $sessionAuthenticationService,
                        (array)($result['errors'] ?? []),
                        $enteredBootstrapCode
                    ),
                    !empty($result['errors']) ? 400 : 200
                );
            }

            if ($authAction === 'login') {
                $result = $loginService->startLogin(
                    (string)$request->post('email_address', ''),
                    (string)$request->post('password', ''),
                    $currentDeviceId
                );

                if (!empty($result['authenticated'])) {
                    eel_redirect_to_index();
                }

                if (!empty($result['requires_otp'])) {
                    return ResponseFramework::html(eel_render_otp_page($sessionAuthenticationService));
                }

                if (!empty($result['requires_otp_setup'])) {
                    $setupData = $loginService->pendingOtpSetupViewData($currentDeviceId);

                    return ResponseFramework::html(
                        $setupData === null
                            ? eel_render_login_page($sessionAuthenticationService, ['OTP setup could not be started. Please try again.'])
                            : eel_render_otp_setup_page($sessionAuthenticationService, $setupData),
                        200
                    );
                }

                return ResponseFramework::html(
                    eel_render_login_page(
                        $sessionAuthenticationService,
                        (array)($result['errors'] ?? []),
                        (array)($result['rate_limit'] ?? [])
                    ),
                    !empty($result['errors']) ? 401 : 200
                );
            }

            if ($authAction === 'verify_otp') {
                $result = $loginService->completeOtpLogin(
                    (string)$request->post('otp_code', ''),
                    $currentDeviceId
                );

                if (!empty($result['authenticated'])) {
                    eel_redirect_to_index();
                }

                if (!empty($result['requires_otp'])) {
                    return ResponseFramework::html(
                        eel_render_otp_page($sessionAuthenticationService, (array)($result['errors'] ?? [])),
                        401
                    );
                }

                return ResponseFramework::html(
                    eel_render_login_page($sessionAuthenticationService, (array)($result['errors'] ?? [])),
                    401
                );
            }

            if ($authAction === 'verify_otp_setup') {
                $result = $loginService->completeOtpSetup(
                    (string)$request->post('otp_code', ''),
                    $currentDeviceId
                );

                if (!empty($result['authenticated'])) {
                    eel_redirect_to_index();
                }

                $setupData = $loginService->pendingOtpSetupViewData($currentDeviceId);

                return ResponseFramework::html(
                    $setupData === null
                        ? eel_render_login_page($sessionAuthenticationService, (array)($result['errors'] ?? []))
                        : eel_render_otp_setup_page($sessionAuthenticationService, $setupData, (array)($result['errors'] ?? [])),
                    401
                );
            }
        } catch (Throwable $exception) {
            return ResponseFramework::html(
                eel_render_auth_state_page(
                    $sessionAuthenticationService,
                    $currentDeviceId,
                    $requiresInitialUserSetup,
                    $loginService,
                    [$exception->getMessage()]
                ),
                400
            );
        }
    }

    if ($requiresInitialUserSetup) {
        return ResponseFramework::html(eel_render_initial_user_page($sessionAuthenticationService));
    }

    $setupData = $loginService->pendingOtpSetupViewData($currentDeviceId);
    if (is_array($setupData)) {
        return ResponseFramework::html(eel_render_otp_setup_page($sessionAuthenticationService, $setupData));
    }

    if ($sessionAuthenticationService->hasPendingOtp($currentDeviceId)) {
        return ResponseFramework::html(eel_render_otp_page($sessionAuthenticationService));
    }

    return ResponseFramework::html(eel_render_login_page($sessionAuthenticationService));
}

function eel_render_auth_state_page(
    SessionAuthenticationService $sessionAuthenticationService,
    string $currentDeviceId,
    bool $requiresInitialUserSetup,
    LoginService $loginService,
    array $errors = []
): string {
    $errors = eel_merge_auth_errors($sessionAuthenticationService, $errors);

    if ($requiresInitialUserSetup) {
        return eel_render_initial_user_page($sessionAuthenticationService, $errors);
    }

    $setupData = $loginService->pendingOtpSetupViewData($currentDeviceId);
    if (is_array($setupData)) {
        return eel_render_otp_setup_page($sessionAuthenticationService, $setupData, $errors);
    }

    if ($sessionAuthenticationService->hasPendingOtp($currentDeviceId)) {
        return eel_render_otp_page($sessionAuthenticationService, $errors);
    }

    return eel_render_login_page($sessionAuthenticationService, $errors);
}

function eel_page_access_response(
    RequestFramework $request,
    PageInterfaceFramework $page,
    SessionAuthenticationService $sessionAuthenticationService,
    string $currentDeviceId
): ?ResponseFramework {
    if ($sessionAuthenticationService->isAuthenticated($currentDeviceId)) {
        return null;
    }

    $message = 'Please sign in to access the requested page.';

    if ($request->isAjax()) {
        return ResponseFramework::json(
            [
                'success' => false,
                'errors' => [$message],
                'requires_authentication' => true,
            ],
            401
        );
    }

    return ResponseFramework::html(
        eel_render_login_page($sessionAuthenticationService, [$message]),
        401
    );
}

function eel_merge_auth_errors(SessionAuthenticationService $sessionAuthenticationService, array $errors = []): array
{
    $notice = $sessionAuthenticationService->consumeLogoutNotice();

    if (is_array($notice) && trim((string)($notice['message'] ?? '')) !== '') {
        array_unshift($errors, (string)$notice['message']);
    }

    return $errors;
}

function eel_redirect_to_index(): never
{
    header('Location: /');
    exit;
}

function eel_render_login_page(
    SessionAuthenticationService $sessionAuthenticationService,
    array $errors = [],
    array $loginState = []
): string
{
    $errors = eel_merge_auth_errors($sessionAuthenticationService, $errors);
    $retryAfterSeconds = max(0, (int)($loginState['retry_after_seconds'] ?? 0));
    $isLocked = !empty($loginState['is_locked']);
    $countdownHtml = '';

    if ($retryAfterSeconds > 0 && !$isLocked) {
        $countdownHtml = '<div class="auth-countdown" data-login-countdown="' . HelperFramework::escape((string)$retryAfterSeconds) . '">
            You can try again in <span data-login-countdown-value>' . HelperFramework::escape((string)$retryAfterSeconds) . '</span>s.
        </div>';
    }

    return eel_render_auth_shell(
        'Sign in',
        'Enter your email address and password to continue.',
        $errors,
        '<form method="post" autocomplete="on" class="auth-form">
            <input type="hidden" name="auth_action" value="login">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
            <label class="auth-label" for="email_address">Email address</label>
            <input class="auth-input" id="email_address" name="email_address" type="email" autocomplete="username" autofocus required>
            <label class="auth-label" for="password">Password</label>
            <input class="auth-input" id="password" name="password" type="password" autocomplete="current-password" required>
            ' . $countdownHtml . '
            <button class="auth-button" type="submit"' . (($retryAfterSeconds > 0 && !$isLocked) ? ' disabled data-login-submit-disabled="true"' : '') . '>Continue</button>
        </form>'
    );
}

function eel_render_otp_setup_page(
    SessionAuthenticationService $sessionAuthenticationService,
    array $setupData,
    array $errors = []
): string {
    $errors = eel_merge_auth_errors($sessionAuthenticationService, $errors);
    $qrHtml = (string)($setupData['qr_svg'] ?? '');
    $otpauthUri = str_replace('--', '%2D%2D', (string)($setupData['otpauth_uri'] ?? ''));
    $manualSecret = HelperFramework::escape((string)($setupData['manual_secret'] ?? ''));

    return eel_render_auth_shell(
        'Enabled Two Factor Authentication (MFA)',
        'Scan this QR code in your authenticator app, or enter the secret manually, then confirm with the six-digit code.',
        $errors,
        '<div class="auth-qr"><!-- ' . $otpauthUri . ' -->' . $qrHtml . '</div>
        <div class="auth-secret">
            <div class="auth-secret-label">Manual entry secret</div>
            <code class="auth-secret-value">' . $manualSecret . '</code>
        </div>
        <form method="post" autocomplete="one-time-code" class="auth-form">
            <input type="hidden" name="auth_action" value="verify_otp_setup">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
            <label class="auth-label" for="otp_code">OTP code</label>
            <input class="auth-input auth-input-code" id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="\\d{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
            <button class="auth-button" type="submit">Enable OTP</button>
        </form>'
    );
}

function eel_render_initial_user_page(
    SessionAuthenticationService $sessionAuthenticationService,
    array $errors = [],
    string $bootstrapCode = ''
): string
{
    $errors = eel_merge_auth_errors($sessionAuthenticationService, $errors);

    return eel_render_auth_shell(
        'Create first account',
        'No users exist yet. Create the first EEL Accounts user to unlock the app.',
        $errors,
        '<form method="post" autocomplete="on" class="auth-form">
            <input type="hidden" name="auth_action" value="create_initial_user">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
            <label class="auth-label" for="display_name">Name</label>
            <input class="auth-input" id="display_name" name="display_name" type="text" autocomplete="name" required>
            <label class="auth-label" for="email_address">Email address</label>
            <input class="auth-input" id="email_address" name="email_address" type="email" autocomplete="username" required>
            <label class="auth-label" for="password">Password</label>
            <input class="auth-input" id="password" name="password" type="password" autocomplete="new-password" required>
            <label class="auth-label" for="bootstrap_code">Bootstrap code</label>
            <input class="auth-input" id="bootstrap_code" name="bootstrap_code" type="text" value="' . HelperFramework::escape($bootstrapCode) . '" autocomplete="one-time-code" required>
            <button class="auth-button" type="submit">Create account</button>
        </form>'
    );
}

function eel_bootstrap_code_state(): array
{
    $path = eel_bootstrap_code_path();

    if (!is_file($path)) {
        $code = HelperFramework::generateBootstrapCode();
        $payload = "EEL Accounts bootstrap code\n\nCode: " . $code . "\n";

        if (@file_put_contents($path, $payload, LOCK_EX) === false || !is_file($path)) {
            throw new RuntimeException('The bootstrap code file could not be created. Check /secure/bootstrap_code.txt permissions.');
        }
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        throw new RuntimeException('The bootstrap code file could not be read. Check /secure/bootstrap_code.txt.');
    }

    if (preg_match('/Code:\s*([0-9A-Fa-f\s]+)/', $contents, $matches) !== 1) {
        throw new RuntimeException('The bootstrap code file does not contain a valid bootstrap code.');
    }

    $storedCode = trim((string)($matches[1] ?? ''));
    if ($storedCode === '' || !HelperFramework::isValidBootstrapCodeFormat($storedCode)) {
        throw new RuntimeException('The bootstrap code file does not contain a valid bootstrap code.');
    }

    return [
        'path' => $path,
        'code' => $storedCode,
    ];
}

function eel_validate_bootstrap_code(string $enteredCode, ?array $bootstrapState): ?string
{
    if ($bootstrapState === null) {
        return 'Bootstrap code validation is unavailable.';
    }

    if (!HelperFramework::isValidBootstrapCodeFormat($enteredCode)) {
        return 'Bootstrap code must contain only hexadecimal characters and spaces.';
    }

    if (!HelperFramework::bootstrapCodeMatches($enteredCode, (string)($bootstrapState['code'] ?? ''))) {
        return 'Bootstrap code was not recognised.';
    }

    return null;
}

function eel_delete_bootstrap_code_file(?array $bootstrapState): void
{
    $path = is_array($bootstrapState) ? trim((string)($bootstrapState['path'] ?? '')) : '';

    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('The bootstrap code file could not be removed after first-user creation.');
    }

    if (!@unlink($path) && is_file($path)) {
        throw new RuntimeException('The bootstrap code file could not be removed after first-user creation.');
    }
}

function eel_bootstrap_code_path(): string
{
    $appRoot = rtrim(APP_ROOT, '\\/');
    $repositoryRoot = dirname($appRoot);

    return $repositoryRoot . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'bootstrap_code.txt';
}

function eel_render_otp_page(SessionAuthenticationService $sessionAuthenticationService, array $errors = []): string
{
    $errors = eel_merge_auth_errors($sessionAuthenticationService, $errors);

    return eel_render_auth_shell(
        'Two-step verification',
        'Enter the six-digit code from your authenticator app.',
        $errors,
        '<form method="post" autocomplete="one-time-code" class="auth-form">
            <input type="hidden" name="auth_action" value="verify_otp">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
            <label class="auth-label" for="otp_code">OTP code</label>
            <input class="auth-input auth-input-code" id="otp_code" name="otp_code" type="text" inputmode="numeric" pattern="\\d{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
            <button class="auth-button" type="submit">Verify code</button>
        </form>'
    );
}

function eel_render_auth_shell(string $title, string $message, array $errors, string $formHtml): string
{
    $escapedTitle = HelperFramework::escape($title);
    $escapedMessage = HelperFramework::escape($message);
    $errorHtml = '';

    foreach ($errors as $error) {
        $errorHtml .= '<div class="auth-error">' . HelperFramework::escape((string)$error) . '</div>';
    }

    $rawHtml = '
        <!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>' . $escapedTitle . ' | EEL Accounts</title>
                <link rel="icon" type="image/x-icon" href="favicon.ico">
                <link rel="stylesheet" href="css/auth.css">
            </head>
            <body>
                <main class="auth-shell">
                    <div class="auth-logo">
                        <div class="auth-logo-mark">E</div>
                        <div class="auth-logo-copy">
                            <div class="auth-logo-title">EEL Accounts</div>
                            <div class="auth-logo-subtitle">Secure accounting access</div>
                        </div>
                    </div>
                    <h1>' . $escapedTitle . '</h1>
                    <p class="auth-copy">' . $escapedMessage . '</p>
                    ' . $errorHtml . '
                    ' . $formHtml . '
                </main>
                <script src="js/index.js"></script>
            </body>
        </html>';

    return preg_replace('/[\r\n]+|(    )/m', "", $rawHtml);
}
