<?php
declare(strict_types=1);

final class _add_userCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'add_user';
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
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $passwordPolicy = HelperFramework::escape(UserAuthenticationService::passwordPolicyDescription());

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Add User</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Create a new active user account. OTP enrollment will be required on first successful sign-in.</p>
                <div class="warning-box">
                    <strong>Password requirements</strong>
                    <p class="helper">' . $passwordPolicy . '</p>
                </div>
                <form method="post" action="?page=users" data-ajax="true" class="form-grid">
                    ' . $this->hiddenFields($context) . '
                    <input type="hidden" name="action" value="users-create-user">
                    <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                    <div class="form-row half">
                        <label for="add-user-display-name">Display name</label>
                        <input class="input" id="add-user-display-name" name="new_display_name" type="text" required>
                    </div>
                    <div class="form-row half">
                        <label for="add-user-email-address">Email address</label>
                        <input class="input" id="add-user-email-address" name="new_email_address" type="email" required>
                    </div>
                    <div class="form-row full">
                        <label for="add-user-password">Password</label>
                        <input class="input" id="add-user-password" name="new_password" type="password" autocomplete="new-password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{12,}" title="' . $passwordPolicy . '" required>
                    </div>
                    <div class="form-row full">
                        <div class="actions-row">
                            <button class="button primary" type="submit">Add user</button>
                        </div>
                    </div>
                </form>
            </div>
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
