<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AuthPageRenderer
{
    public function __construct(private readonly string $appName)
    {
    }

    public function authStatePage(
        SessionAuthenticationService $sessionAuthenticationService,
        string $currentDeviceId,
        bool $requiresInitialUserSetup,
        LoginService $loginService,
        array $errors = []
    ): string {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);

        if ($requiresInitialUserSetup) {
            return $this->initialUserPage($sessionAuthenticationService, $errors);
        }

        if ($sessionAuthenticationService->hasPendingPasswordChange($currentDeviceId)) {
            return $this->requiredPasswordChangePage($sessionAuthenticationService, $errors);
        }

        $setupData = $loginService->pendingOtpSetupViewData($currentDeviceId);
        if (is_array($setupData)) {
            return $this->otpSetupPage($sessionAuthenticationService, $setupData, $errors);
        }

        if ($sessionAuthenticationService->hasPendingOtp($currentDeviceId)) {
            return $this->otpPage($sessionAuthenticationService, $errors);
        }

        return $this->loginPage($sessionAuthenticationService, $errors);
    }

    public function loginPage(
        SessionAuthenticationService $sessionAuthenticationService,
        array $errors = [],
        array $loginState = []
    ): string {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);
        $retryAfterSeconds = max(0, (int)($loginState['retry_after_seconds'] ?? 0));
        $isLocked = !empty($loginState['is_locked']);
        $countdownHtml = '';

        if ($retryAfterSeconds > 0 && !$isLocked) {
            $countdownHtml = '<div class="auth-countdown" data-login-countdown="' . HelperFramework::escape((string)$retryAfterSeconds) . '">
                You can try again in <span data-login-countdown-value>' . HelperFramework::escape((string)$retryAfterSeconds) . '</span>s.
            </div>';
        }

        return $this->shell(
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

    public function otpSetupPage(
        SessionAuthenticationService $sessionAuthenticationService,
        array $setupData,
        array $errors = []
    ): string {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);
        $qrHtml = (string)($setupData['qr_svg'] ?? '');
        $otpauthUri = str_replace('--', '%2D%2D', (string)($setupData['otpauth_uri'] ?? ''));
        $manualSecret = HelperFramework::escape((string)($setupData['manual_secret'] ?? ''));

        return $this->shell(
            'Enable Two Factor Authentication (MFA)',
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

    public function initialUserPage(
        SessionAuthenticationService $sessionAuthenticationService,
        array $errors = [],
        string $bootstrapCode = ''
    ): string {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);
        $passwordPolicy = HelperFramework::escape(UserAuthenticationService::passwordPolicyDescription());

        return $this->shell(
            'Create first account',
            'No users exist yet. Create the first ' . $this->appName . ' user to unlock the app.',
            $errors,
            '<form method="post" autocomplete="on" class="auth-form">
                <input type="hidden" name="auth_action" value="create_initial_user">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
                <label class="auth-label" for="display_name">Name</label>
                <input class="auth-input" id="display_name" name="display_name" type="text" autocomplete="name" required>
                <label class="auth-label" for="email_address">Email address</label>
                <input class="auth-input" id="email_address" name="email_address" type="email" autocomplete="username" required>
                <div class="auth-countdown" data-password-requirements-panel data-password-requirements-for="password">
                    <strong>Password requirements</strong><br>
                    ' . $passwordPolicy . '
                </div>
                <label class="auth-label" for="password">Password</label>
                <input class="auth-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}" title="' . $passwordPolicy . '" required>
                <label class="auth-label" for="bootstrap_code">Bootstrap code</label>
                <input class="auth-input" id="bootstrap_code" name="bootstrap_code" type="text" value="' . HelperFramework::escape($bootstrapCode) . '" autocomplete="one-time-code" required>
                <button class="auth-button" type="submit">Create account</button>
            </form>'
        );
    }

    public function requiredPasswordChangePage(
        SessionAuthenticationService $sessionAuthenticationService,
        array $errors = []
    ): string {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);
        $passwordPolicy = HelperFramework::escape(UserAuthenticationService::passwordPolicyDescription());

        return $this->shell(
            'Change password',
            'An administrator has requested a password change before two-step verification.',
            $errors,
            '<form method="post" autocomplete="on" class="auth-form">
                <input type="hidden" name="auth_action" value="change_required_password">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($sessionAuthenticationService->csrfToken()) . '">
                <div class="auth-countdown" data-password-requirements-panel data-password-requirements-for="password">
                    <strong>Password requirements</strong><br>
                    ' . $passwordPolicy . '
                </div>
                <label class="auth-label" for="password">New password</label>
                <input class="auth-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}" title="' . $passwordPolicy . '" autofocus required>
                <label class="auth-label" for="password_confirm">Confirm new password</label>
                <input class="auth-input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required>
                <button class="auth-button" type="submit">Change password</button>
            </form>'
        );
    }

    public function otpPage(SessionAuthenticationService $sessionAuthenticationService, array $errors = []): string
    {
        $errors = $this->mergeAuthErrors($sessionAuthenticationService, $errors);

        return $this->shell(
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

    public function mergeAuthErrors(SessionAuthenticationService $sessionAuthenticationService, array $errors = []): array
    {
        $notice = $sessionAuthenticationService->consumeLogoutNotice();

        if (is_array($notice) && trim((string)($notice['message'] ?? '')) !== '') {
            array_unshift($errors, (string)$notice['message']);
        }

        return $errors;
    }

    private function shell(string $title, string $message, array $errors, string $formHtml): string
    {
        $escapedTitle = HelperFramework::escape($title);
        $escapedMessage = HelperFramework::escape($message);
        $escapedAppName = HelperFramework::escape($this->appName);
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
                    <title>' . $escapedTitle . ' | ' . $escapedAppName . '</title>
                    <link rel="icon" type="image/x-icon" href="favicon.ico">
                    <link rel="stylesheet" href="css/auth.css">
                </head>
                <body>
                    <main class="auth-shell">
                        <div class="auth-logo">
                            <div class="auth-logo-mark">E</div>
                            <div class="auth-logo-copy">
                                <div class="auth-logo-title">' . $escapedAppName . '</div>
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
}
