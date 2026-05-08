<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _set_new_otp_secretCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'set_new_otp_secret';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'current_user_otp_dashboard',
                'service' => UserManagementService::class,
                'method' => 'currentUserOtpDashboard',
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $otpDashboard = (array)(($context['services'] ?? [])['current_user_otp_dashboard'] ?? []);
        $otpState = (array)($otpDashboard['current_user_otp'] ?? []);
        $setup = (array)($otpDashboard['otp_setup'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $isEnabled = !empty($otpState['is_enabled']);
        $hasSecret = !empty($otpState['has_secret']);
        $hasPending = !empty($setup['has_pending']);
        $statusClass = $isEnabled && !$hasPending ? 'success' : 'warning';
        $statusTitle = match (true) {
            $hasPending => 'A new two-factor secret is waiting for confirmation.',
            $isEnabled => 'Two-factor authentication is enabled.',
            $hasSecret => 'Two-factor authentication needs to be confirmed.',
            default => 'Two-factor authentication needs to be enrolled.',
        };
        $statusBadge = match (true) {
            $hasPending => 'Pending rotation',
            $isEnabled => 'Enabled',
            $hasSecret => 'Pending',
            default => 'Not enrolled',
        };

        $statusHtml = '<div class="status-panel ' . $statusClass . '">
            <div class="status-head">
                <strong>' . HelperFramework::escape($statusTitle) . '</strong>
                <span class="status-badge ' . $statusClass . '">' . HelperFramework::escape($statusBadge) . '</span>
            </div>
            <p class="helper">Use this card to generate and confirm a fresh OTP secret for your account.</p>
        </div>';

        if ($hasPending) {
            $actionHtml = '<div class="auth-qr"><!-- ' . str_replace('--', '%2D%2D', (string)($setup['otpauth_uri'] ?? '')) . ' -->' . (string)($setup['qr_svg'] ?? '') . '</div>
                <div class="auth-secret">
                    <div class="auth-secret-label">Manual entry secret</div>
                    <code class="auth-secret-value">' . HelperFramework::escape((string)($setup['manual_secret'] ?? '')) . '</code>
                </div>
                <form method="post" action="?page=users" data-ajax="true" class="form-grid">
                    ' . $this->hiddenFields($context) . '
                    <input type="hidden" name="action" value="users-complete-otp-rotation">
                    <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                    <div class="form-row half">
                        <label for="users-otp-code">OTP code</label>
                        <input class="input" id="users-otp-code" name="otp_code" type="text" inputmode="numeric" pattern="\\d{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <div class="form-row full">
                        <div class="actions-row">
                            <button class="button primary" type="submit">Confirm new OTP secret</button>
                        </div>
                    </div>
                </form>';
        } else {
            $actionHtml = '<form method="post" action="?page=users" data-ajax="true" class="otp-rotation-form">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="action" value="users-begin-otp-rotation">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <div class="actions-row">
                    <button class="button primary" type="submit">Set new OTP secret</button>
                </div>
            </form>';
        }

        return $statusHtml . $actionHtml;
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
        }

        return $html;
    }
}
