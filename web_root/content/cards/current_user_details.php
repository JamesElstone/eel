<?php
declare(strict_types=1);

final class _current_user_detailsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'current_user_details';
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
        $user = (array)($dashboard['current_user'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Current User Details</h2>
                </div>
            </div>
            <div class="card-body stack">
                <p class="helper">Update your display name, email address, or password. A current password is only required when changing your own password.</p>
                <form method="post" action="?page=users" data-ajax="true" class="form-grid" autocomplete="off">
                    ' . $this->hiddenFields($context) . '
                    <input type="hidden" name="action" value="users-update-current-user">
                    <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                    <div class="autofill-trap" aria-hidden="true">
                        <input type="text" name="fake_username" autocomplete="username" tabindex="-1">
                        <input type="password" name="fake_password" autocomplete="current-password" tabindex="-1">
                    </div>
                    <div class="form-row half">
                        <label for="users-display-name">Display name</label>
                        <input class="input" id="users-display-name" name="display_name" type="text" value="' . HelperFramework::escape((string)($user['display_name'] ?? '')) . '" autocomplete="off" required>
                    </div>
                    <div class="form-row half">
                        <label for="users-email-address">Email address</label>
                        <input class="input" id="users-email-address" name="email_address" type="email" value="' . HelperFramework::escape((string)($user['email_address'] ?? '')) . '" autocomplete="off" data-lpignore="true" data-form-type="other" required>
                    </div>
                    <div class="form-row half">
                        <label for="users-current-password">Current password</label>
                        <input class="input" id="users-current-password" name="current_password" type="password" value="" autocomplete="off" data-lpignore="true" data-form-type="other">
                    </div>
                    <div class="form-row half">
                        <label for="users-new-password">New password</label>
                        <input class="input" id="users-new-password" name="new_password" type="password" value="" autocomplete="new-password" data-lpignore="true" data-form-type="other">
                    </div>
                    <div class="form-row full">
                        <div class="actions-row">
                            <button class="button primary" type="submit">Save current user details</button>
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
