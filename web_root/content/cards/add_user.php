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

    public function helper(array $context): string
    {
        return 'Create a new active user account. OTP enrollment will be required on first successful sign-in.';
    }

    public function render(array $context): string
    {
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $passwordPolicy = HelperFramework::escape((string)(($context['services'] ?? [])['password_policy'] ?? ''));

        return '
            <form method="post" action="?page=users" data-ajax="true" class="form-grid">
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
                    <div class="mobile-input-row">
                        <select class="selector-input mobile-country-code" id="add-user-mobile-country-code" name="new_mobile_country_code" autocomplete="tel-country-code">
                            ' . $this->mobileCountryCodeOptionsHtml(UserManagementService::defaultMobileCountryCode()) . '
                        </select>
                        <input class="input" id="add-user-mobile-number" name="new_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel">
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
