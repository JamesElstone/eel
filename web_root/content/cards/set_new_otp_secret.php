<?php
declare(strict_types=1);

final class _set_new_otp_secretCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'set_new_otp_secret';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $dashboard = (array)($context['page']['users_dashboard'] ?? []);
        $otpState = (array)($dashboard['current_user_otp'] ?? []);
        $setup = (array)($dashboard['otp_setup'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $hasPending = !empty($setup['has_pending']);

        $statusHtml = '<div class="status-panel ' . (!empty($otpState['is_enabled']) ? 'success' : 'warning') . '">
            <div class="status-head">
                <strong>' . (!empty($otpState['is_enabled']) ? 'Two-factor authentication is enabled.' : 'Two-factor authentication still needs to be enrolled.') . '</strong>
                <span class="status-badge ' . (!empty($otpState['is_enabled']) ? 'success' : 'warning') . '">' . (!empty($otpState['is_enabled']) ? 'Enabled' : 'Pending') . '</span>
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
            $actionHtml = '<form method="post" action="?page=users" data-ajax="true">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="action" value="users-begin-otp-rotation">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <div class="actions-row">
                    <button class="button primary" type="submit">Set new OTP secret</button>
                </div>
            </form>';
        }

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Set New OTP Secret</h2>
                </div>
            </div>
            <div class="card-body stack">'
                . $statusHtml .
                $actionHtml .
            '</div>
        </div>';
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
