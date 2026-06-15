<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _invite_userCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'invite_user';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'current_users_dashboard',
                'service' => UserManagementService::class,
                'method' => 'currentUsersDashboard',
            ],
        ];
    }

    public function title(): string
    {
        return 'Invite User';
    }

    public function helper(array $context): string
    {
        return 'Create a pending invited user with a display name and at least one contact method.';
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');

        return '<form method="post" action="?page=users" data-ajax="true" class="form-grid">
            ' . $this->hiddenFields($context) . '
            <input type="hidden" name="action" value="users-create-invited-user">
            <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
            <div class="form-row half">
                <label for="invite-user-display-name">Display name</label>
                <input class="input" id="invite-user-display-name" name="invite_display_name" type="text" required>
            </div>
            <div class="form-row half">
                <label for="invite-user-email-address">Email address</label>
                <input class="input" id="invite-user-email-address" name="invite_email_address" type="email">
            </div>
            <div class="form-row full">
                <label for="invite-user-mobile-number">Mobile number</label>
                <div class="input-action-row">
                    <select class="selector-input mobile-country-code" id="invite-user-mobile-country-code" name="invite_mobile_country_code" autocomplete="tel-country-code" data-no-submit-on-change="true">
                        ' . $this->mobileCountryCodeOptionsHtml(UserManagementService::defaultMobileCountryCode()) . '
                    </select>
                    <input class="input mobile-number-input" id="invite-user-mobile-number" name="invite_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16">
                </div>
            </div>
            <div class="form-row half">
                <label for="invite-user-role">Role</label>
                <select class="selector-input" id="invite-user-role" name="invite_role_id" data-no-submit-on-change="true">
                    ' . $this->roleOptionsHtml($context) . '
                </select>
            </div>
            <div class="form-row full">
                <button class="button primary" type="submit">Create Pending User</button>
            </div>
        </form>';
    }

    private function roleOptionsHtml(array $context): string
    {
        $roles = (array)((($context['services'] ?? [])['current_users_dashboard'] ?? [])['roles'] ?? []);
        $html = '';

        foreach ($roles as $role) {
            $roleId = (int)($role['id'] ?? 0);
            $roleName = trim((string)($role['role_name'] ?? ''));
            if ($roleName === '') {
                continue;
            }

            $html .= '<option value="' . HelperFramework::escape((string)$roleId) . '">'
                . HelperFramework::escape($roleName)
                . '</option>';
        }

        return $html;
    }

    private function mobileCountryCodeOptionsHtml(string $selectedCountryCode): string
    {
        $html = '';

        foreach (UserManagementService::mobileCountryCodeOptions() as $countryCode => $label) {
            $selected = $countryCode === $selectedCountryCode ? ' selected' : '';
            $html .= '<option value="' . HelperFramework::escape($countryCode) . '"' . $selected . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
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
