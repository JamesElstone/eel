<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _add_userCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'add_user';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'password_policy',
                'service' => UserManagementService::class,
                'method' => 'passwordPolicyDescription',
            ],
            [
                'key' => 'current_users_dashboard',
                'service' => UserManagementService::class,
                'method' => 'currentUsersDashboard',
            ],
            [
                'key' => 'invite_availability',
                'service' => UserManagementService::class,
                'method' => 'userCreationInviteAvailability',
            ],
        ];
    }

    public function title(): string
    {
        return 'Create User';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function helper(array $context): string
    {
        return 'Create an active user directly, or invite a pending user when a live delivery channel is available.';
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $passwordPolicy = HelperFramework::escape((string)(($context['services'] ?? [])['password_policy'] ?? ''));
        $inviteAvailability = (array)(($context['services'] ?? [])['invite_availability'] ?? []);
        $inviteAvailable = !empty($inviteAvailability['available']);
        $inviteEmailAvailable = !empty($inviteAvailability['smtp_ready']);
        $inviteSmsAvailable = !empty($inviteAvailability['sms_ready']);
        $addPanelId = 'add-user-create-direct-panel';
        $invitePanelId = 'add-user-create-invite-panel';
        $addPanelAttributes = $inviteAvailable
            ? ' id="' . HelperFramework::escape($addPanelId) . '" data-user-create-mode-panel="add" role="tabpanel"'
            : '';

        $addForm = '
            <form method="post" action="?page=users" data-ajax="true" class="form-grid"' . $addPanelAttributes . '>
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="action" value="users-create-user">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <div class="form-row full">
                    <div class="warning-box" data-password-requirements-panel data-password-requirements-for="add-user-password">
                        <strong>Password requirements</strong>
                        <p class="helper">' . $passwordPolicy . '</p>
                    </div>
                </div>
                <div class="form-row half">
                    <label for="add-user-display-name">Display name</label>
                    <input class="input" id="add-user-display-name" name="new_display_name" type="text" required>
                </div>
                <div class="form-row half">
                    <label for="add-user-email-address">Email address</label>
                    <input class="input" id="add-user-email-address" name="new_email_address" type="email" required>
                </div>
                <div class="form-row full">
                    <label for="add-user-mobile-number">Mobile number</label>
                    <div class="input-action-row">
                        <select class="selector-input mobile-country-code" id="add-user-mobile-country-code" name="new_mobile_country_code" autocomplete="tel-country-code">
                            ' . $this->mobileCountryCodeOptionsHtml(UserManagementService::defaultMobileCountryCode()) . '
                        </select>
                        <input class="input mobile-number-input" id="add-user-mobile-number" name="new_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16">
                    </div>
                </div>
                <div class="form-row full">
                    <label for="add-user-password">Password</label>
                    <input class="input" id="add-user-password" name="new_password" type="password" autocomplete="new-password" minlength="12" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{12,}" title="' . $passwordPolicy . '" required>
                </div>
                <div class="form-row full">
                    <button class="button primary" type="submit">Add user</button>
                </div>
            </form>
        ';

        if (!$inviteAvailable) {
            return $addForm;
        }

        $inviteEmailField = $inviteEmailAvailable ? '
                <div class="form-row half">
                    <label for="invite-user-email-address">Email address</label>
                    <input class="input" id="invite-user-email-address" name="invite_email_address" type="email" data-invite-contact-field="email">
                </div>' : '';
        $inviteMobileField = $inviteSmsAvailable ? '
                <div class="form-row full">
                    <label for="invite-user-mobile-number">Mobile number</label>
                    <div class="input-action-row">
                        <select class="selector-input mobile-country-code" id="invite-user-mobile-country-code" name="invite_mobile_country_code" autocomplete="tel-country-code" data-no-submit-on-change="true">
                            ' . $this->mobileCountryCodeOptionsHtml(UserManagementService::defaultMobileCountryCode()) . '
                        </select>
                        <input class="input mobile-number-input" id="invite-user-mobile-number" name="invite_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16" data-invite-contact-field="mobile">
                    </div>
                </div>' : '';

        return '
            <div class="form-row full segmented-control" role="tablist" aria-label="User creation mode">
                <button class="segmented-option" type="button" role="tab" aria-selected="true" aria-controls="' . HelperFramework::escape($addPanelId) . '" data-user-create-mode-button="add">Add directly</button>
                <button class="segmented-option" type="button" role="tab" aria-selected="false" aria-controls="' . HelperFramework::escape($invitePanelId) . '" data-user-create-mode-button="invite">Invite</button>
            </div>
            ' . $addForm . '
            <form method="post" action="?page=users" data-ajax="true" data-require-invite-contact="true" class="form-grid" id="' . HelperFramework::escape($invitePanelId) . '" data-user-create-mode-panel="invite" role="tabpanel" hidden>
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="action" value="users-create-invited-user">
                <input type="hidden" name="csrf_token" value="' . HelperFramework::escape($csrfToken) . '">
                <div class="form-row half">
                    <label for="invite-user-display-name">Display name</label>
                    <input class="input" id="invite-user-display-name" name="invite_display_name" type="text" required>
                </div>
                ' . $inviteEmailField . '
                ' . $inviteMobileField . '
                <div class="form-row half">
                    <label for="invite-user-role">Role</label>
                    <select class="selector-input" id="invite-user-role" name="invite_role_id" data-no-submit-on-change="true">
                        ' . $this->roleOptionsHtml($context) . '
                    </select>
                </div>
                <div class="form-row full">
                    <button class="button primary" type="submit">Create invitation</button>
                </div>
            </form>
        ';
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

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . HelperFramework::escape((string)$cardKey) . '">';
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
}
