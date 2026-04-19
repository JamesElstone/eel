<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _otp_status_testCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'otp_status_test';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['test.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '[' . $serviceKey . '] ' . (string)($error['type'] ?? 'error') . ': ' . (string)($error['message'] ?? '');
    }

    public function render(array $context): string
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $sessionAuthenticationService->authenticatedUserId($currentDeviceId);

        if ($userId <= 0) {
            $statusHtml = '<div class="status-panel warning">
                <div class="status-head">
                    <strong>No authenticated user</strong>
                    <span class="status-badge warning">Session missing</span>
                </div>
                <p class="helper">This card only renders OTP details when a user is currently signed in on this device.</p>
            </div>';
        } else {
            $otpService = new OtpService('EEL Accounts');
            $hasSecret = $otpService->hasOTPsecret($userId);
            $isEnabled = $otpService->isOTPenabled($userId);

            $statusHtml = '<div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">User ID</div>
                    <div class="summary-value">' . HelperFramework::escape((string)$userId) . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">TOTP Secret</div>
                    <div class="summary-value">' . ($hasSecret ? 'Yes' : 'No') . '</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">OTP Enabled</div>
                    <div class="summary-value">' . ($isEnabled ? 'Yes' : 'No') . '</div>
                </div>
            </div>
            <div class="status-panel ' . ($isEnabled ? 'success' : 'warning') . '">
                <div class="status-head">
                    <strong>' . ($isEnabled ? 'Two-factor authentication is active.' : 'Two-factor authentication still needs setup.') . '</strong>
                    <span class="status-badge ' . ($isEnabled ? 'success' : 'warning') . '">' . ($isEnabled ? 'Enabled' : 'Pending') . '</span>
                </div>
                <p class="helper">Current device id: ' . HelperFramework::escape($currentDeviceId !== '' ? $currentDeviceId : 'Unavailable') . '</p>
            </div>';
        }

        return '<div class="card">
            <div class="card-header card-header-has-eyebrow">
                <div>
                    <h2 class="card-title">OTP status</h2>
                </div>
                <p class="eyebrow card-header-corner-eyebrow">Card: ' . HelperFramework::escape($this->key()) . '</p>
            </div>
            <div class="card-body stack">
                <p class="helper">This card inspects the authenticated session on the current device and reports the OTP state for that logged-in user.</p>
                ' . $statusHtml . '
            </div>
        </div>';
    }
}
