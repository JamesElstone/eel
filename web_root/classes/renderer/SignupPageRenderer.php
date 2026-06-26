<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SignupPageRenderer
{
    public function __construct(
        private readonly string $appName,
        private readonly string $appStrapline,
    ) {
    }

    public function errorPage(SessionAuthenticationService $session, array $errors): string
    {
        return $this->shell('Complete account setup', 'Enter the details from your invitation to continue.', $errors, '');
    }

    public function verificationPage(SessionAuthenticationService $session, array $errors = [], array $invite = []): string
    {
        $firstName = $this->firstDisplayNameWord((string)($invite['display_name'] ?? ''));
        $nameHtml = $firstName !== ''
            ? '<p class="auth-copy">Hi ' . HelperFramework::escape($firstName) . '</p>'
            : '';

        return $this->shell(
            'Verify your details',
            'Enter the details your administrator already has for you, including the email address or mobile number where your invite link was sent.',
            $errors,
            '<form method="post" action="/signup/index.php" autocomplete="on" class="auth-form">
                <input type="hidden" name="signup_action" value="verify_identity">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($session->csrfToken()) . '">
                <label class="auth-label" for="verify_email_address">Email address</label>
                <input class="auth-input" id="verify_email_address" name="email_address" type="email" autocomplete="email" autofocus>
                <label class="auth-label" for="verify_mobile_number">Mobile number</label>
                <div class="auth-composite-input">
                    <select class="auth-input auth-select" name="mobile_country_code" autocomplete="tel-country-code">
                        ' . $this->mobileCountryCodeOptionsHtml(MobileNumberService::DEFAULT_COUNTRY_CODE) . '
                    </select>
                    <input class="auth-input" id="verify_mobile_number" name="mobile_number" type="tel" autocomplete="tel-national" inputmode="tel">
                </div>
                <button class="auth-button" type="submit">Continue</button>
            </form>',
            $nameHtml
        );
    }

    public function completionPage(SessionAuthenticationService $session, array $invite, array $errors = []): string
    {
        $passwordPolicy = HelperFramework::escape(UserAuthenticationService::passwordPolicyDescription());
        $mobileParts = MobileNumberService::parts((string)($invite['mobile_number'] ?? ''));

        return $this->shell(
            'Set up your account',
            'Confirm your account details and choose a password.',
            $errors,
            '<form method="post" action="/signup/index.php" autocomplete="on" class="auth-form">
                <input type="hidden" name="signup_action" value="complete_account">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($session->csrfToken()) . '">
                <label class="auth-label" for="display_name">Display name</label>
                <input class="auth-input" id="display_name" name="display_name" type="text" autocomplete="name" value="' . HelperFramework::escape((string)($invite['display_name'] ?? '')) . '" required>
                <label class="auth-label" for="email_address">Email address</label>
                <input class="auth-input" id="email_address" name="email_address" type="email" autocomplete="username" value="' . HelperFramework::escape((string)($invite['email_address'] ?? '')) . '" required>
                <label class="auth-label" for="mobile_number">Mobile number</label>
                <div class="auth-composite-input">
                    <select class="auth-input auth-select" name="mobile_country_code" autocomplete="tel-country-code">
                        ' . $this->mobileCountryCodeOptionsHtml((string)($mobileParts['country_code'] ?? MobileNumberService::DEFAULT_COUNTRY_CODE)) . '
                    </select>
                    <input class="auth-input" id="mobile_number" name="mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" value="' . HelperFramework::escape((string)($mobileParts['local_number'] ?? '')) . '">
                </div>
                <div class="auth-countdown" data-password-requirements-panel data-password-requirements-for="password">
                    <strong>Password requirements</strong><br>' . $passwordPolicy . '
                </div>
                <label class="auth-label" for="password">Password</label>
                <input class="auth-input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{12,}" title="' . $passwordPolicy . '" required>
                <label class="auth-label" for="password_confirm">Confirm password</label>
                <input class="auth-input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="12" required>
                <button class="auth-button" type="submit">Complete Setup</button>
            </form>'
        );
    }

    private function shell(string $title, string $message, array $errors, string $formHtml, string $beforeMessageHtml = ''): string
    {
        $brandMarkHtml = BrandMarkRenderer::html('auth-logo-mark-image');
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= '<div class="auth-error">' . HelperFramework::escape((string)$error) . '</div>';
        }

        $rawHtml = '<!DOCTYPE html>
            <html lang="en">
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <title>' . HelperFramework::escape($title) . ' | ' . HelperFramework::escape($this->appName) . '</title>
                    <link rel="icon" type="image/x-icon" href="/favicon.ico">
                    <link rel="stylesheet" href="/css/auth.css">
                </head>
                <body>
                    <main class="auth-shell">
                        <div class="auth-logo">
                            <div class="auth-logo-mark">' . $brandMarkHtml . '</div>
                            <div class="auth-logo-copy">
                                <div class="auth-logo-title">' . HelperFramework::escape($this->appName) . '</div>
                                <div class="auth-logo-subtitle">' . HelperFramework::escape($this->appStrapline) . '</div>
                            </div>
                        </div>
                        <h1>' . HelperFramework::escape($title) . '</h1>
                        ' . $beforeMessageHtml . '<p class="auth-copy">' . HelperFramework::escape($message) . '</p>
                        ' . $errorHtml . $formHtml . '
                    </main>
                    <script src="/js/index.js"></script>
                </body>
            </html>';

        return preg_replace('/[\r\n]+|(    )/m', '', $rawHtml);
    }

    private function mobileCountryCodeOptionsHtml(string $selectedCountryCode): string
    {
        $html = '';
        foreach (MobileNumberService::countryCodeOptions() as $countryCode => $label) {
            $html .= '<option value="' . HelperFramework::escape($countryCode) . '"' . ($countryCode === $selectedCountryCode ? ' selected' : '') . '>'
                . HelperFramework::escape($countryCode)
                . '</option>';
        }

        return $html;
    }

    private function firstDisplayNameWord(string $displayName): string
    {
        $displayName = trim(preg_replace('/\s+/', ' ', $displayName) ?? $displayName);
        if ($displayName === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $displayName) ?: [];

        return trim((string)($parts[0] ?? ''));
    }
}
