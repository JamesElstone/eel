<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _current_user_detailsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'current_user_details';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'current_user',
                'service' => UserManagementService::class,
                'method' => 'currentUserDetails',
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
        $user = (array)(($context['services'] ?? [])['current_user'] ?? []);
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $mobileParts = UserManagementService::mobileNumberParts((string)($user['mobile_number'] ?? ''));

        return '
            <p class="helper">Update your display name, email address, mobile number, or password. A current password is required before changing account details.</p>
            <form method="post" action="?page=users" data-ajax="true" class="form-flex-flow" autocomplete="off">
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
                <div class="form-row full">
                    <label for="users-mobile-number">Mobile number</label>
                    <div class="mobile-input-row">
                        <select class="selector-input mobile-country-code" id="users-mobile-country-code" name="mobile_country_code" autocomplete="tel-country-code">
                            ' . $this->mobileCountryCodeOptionsHtml((string)($mobileParts['country_code'] ?? UserManagementService::defaultMobileCountryCode())) . '
                        </select>
                        <input class="input" id="users-mobile-number" name="mobile_number" type="tel" value="' . HelperFramework::escape((string)($mobileParts['local_number'] ?? '')) . '" autocomplete="tel-national" inputmode="tel" data-lpignore="true" data-form-type="other">
                    </div>
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
