<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _application_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'application_settings';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function title(): string
    {
        return 'Application Settings';
    }

    public function helper(array $context): string
    {
        return 'Edit the stored app.php settings used for branding, navigation, anti-fraud headers, and session cookies.';
    }

    public function render(array $context): string
    {
        $config = AppConfigurationStore::config();
        $navigationOrder = is_array($config['navigation']['default_order'] ?? null)
            ? $config['navigation']['default_order']
            : [];
        $topbarDisabledPages = is_array($config['navigation']['topbar_disabled_pages'] ?? null)
            ? $config['navigation']['topbar_disabled_pages']
            : [];
        $csrfToken = (string)($context['page']['csrf_token'] ?? '');
        $cookieSecure = $this->cookieSecureDisplayValue($config['session']['cookie_secure'] ?? 'auto');
        $cookieSameSite = (string)($config['session']['cookie_samesite'] ?? 'Strict');
        $hideCollapsedLinkInitials = !empty($config['navigation']['hide_collapsed_link_initials']);
        $newUserOtpRequired = $this->otpRequiredDisplayValue($config['user_defaults']['new_user_otp_required'] ?? true);
        $tableCondensedDefault = !empty($config['table_condensed_default']);

        return '
            <form method="post" action="?page=settings" data-ajax="true" class="form-grid application-settings-form">
                ' . $this->hiddenFields($context) . '
                <input type="hidden" name="card_action" value="ApplicationSettings">
                <input type="hidden" name="csrf_token" value="' . \eel_accounts\Support\Utf8::html($csrfToken) . '">

                <fieldset class="form-row full settings-fieldset">
                    <legend>Branding</legend>
                    <div class="form-grid">
                        <div class="form-row half">
                            <label for="settings-app-name">Application name</label>
                            <input class="input" id="settings-app-name" name="app_name" type="text" value="' . \eel_accounts\Support\Utf8::html((string)($config['app_name'] ?? '')) . '" required>
                        </div>
                        <div class="form-row half">
                            <label for="settings-brand-mark">Brand mark</label>
                            <input class="input" id="settings-brand-mark" name="brand_mark" type="text" value="' . \eel_accounts\Support\Utf8::html((string)($config['brand-mark'] ?? '')) . '" maxlength="255" required>
                        </div>
                        <div class="form-row full">
                            <label for="settings-app-strapline">Application strapline</label>
                            <input class="input" id="settings-app-strapline" name="app_strapline" type="text" value="' . \eel_accounts\Support\Utf8::html((string)($config['app_strapline'] ?? '')) . '">
                        </div>
                        <div class="form-row full">
                            <label for="settings-app-footer">Application footer</label>
                            <input class="input" id="settings-app-footer" name="app_footer" type="text" value="' . \eel_accounts\Support\Utf8::html((string)($config['app_footer'] ?? '')) . '" maxlength="255">
                        </div>
                        <div class="form-row full">
                            <button class="button primary" type="submit" data-processing-text="Saving" data-processing-state="disabled">Save</button>
                        </div>
                    </div>
                </fieldset>
                <fieldset class="form-row full settings-fieldset">
                    <legend>Developer Options</legend>
                    <label class="checkbox-item" for="settings-developer-options">
                        <input type="hidden" name="developer_options" value="0">
                        <input id="settings-developer-options" name="developer_options" type="checkbox" value="1" data-submit-on-change="true"' . (!empty($config['developer_options']) ? ' checked' : '') . '>
                        <span class="checkbox-copy">
                            <span>Show developer-only tools, cards, and diagnostics where enabled.</span>
                        </span>
                    </label>
                    <label class="checkbox-item" for="settings-table-condensed-default">
                        <input type="hidden" name="table_condensed_default" value="0">
                        <input id="settings-table-condensed-default" name="table_condensed_default" type="checkbox" value="1" data-submit-on-change="true"' . ($tableCondensedDefault ? ' checked' : '') . '>
                        <span class="checkbox-copy">
                            <span>Start table builder tables in condensed view by default.</span>
                        </span>
                    </label>
                </fieldset>

                <fieldset class="form-row full settings-fieldset">
                    <legend>User Defaults</legend>
                    <div class="form-grid">
                        <div class="form-row half">
                            <label for="settings-new-user-otp-required">New user OTP requirement</label>
                            <select class="select" id="settings-new-user-otp-required" name="new_user_otp_required">
                                ' . $this->option('1', 'Required', $newUserOtpRequired) . '
                                ' . $this->option('0', 'Optional', $newUserOtpRequired) . '
                            </select>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-row full settings-fieldset">
                    <legend>Navigation order</legend>
                    <label class="checkbox-item" for="settings-hide-collapsed-link-initials">
                        <input type="hidden" name="hide_collapsed_link_initials" value="0">
                        <input id="settings-hide-collapsed-link-initials" name="hide_collapsed_link_initials" type="checkbox" value="1" data-submit-on-change="true"' . ($hideCollapsedLinkInitials ? ' checked' : '') . '>
                        <span class="checkbox-copy">
                            <span>Hide collapsed sidebar link initials.</span>
                        </span>
                    </label>
                    <div class="checkbox-grid">
                        ' . $this->navigationOrderFields($navigationOrder, $topbarDisabledPages) . '
                    </div>
                </fieldset>

                <fieldset class="form-row full settings-fieldset">
                    <legend>Default API Anti-Fraud Headers</legend>
                    <div class="form-grid">
                        ' . $this->textField('settings-vendor-license-ids', 'Vendor license IDs', 'antifraud_vendor_license_ids', (string)($config['antifraud']['vendor_license_ids'] ?? '')) . '
                        ' . $this->textField('settings-vendor-product-name', 'Vendor product name', 'antifraud_vendor_product_name', (string)($config['antifraud']['vendor_product_name'] ?? '')) . '
                        ' . $this->vendorPublicIpField((string)($config['antifraud']['vendor_public_ip'] ?? '')) . '
                        ' . $this->textField('settings-vendor-version', 'Vendor version', 'antifraud_vendor_version', (string)($config['antifraud']['vendor_version'] ?? '')) . '
                        <div class="form-row full">
                            <button class="button primary" type="submit" data-processing-text="Saving" data-processing-state="disabled">Save</button>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="form-row full settings-fieldset">
                    <legend>Session cookies</legend>
                    <div class="form-grid">
                        <div class="form-row half">
                            <label for="settings-cookie-secure">Secure cookie</label>
                            <select class="select" id="settings-cookie-secure" name="session_cookie_secure">
                                ' . $this->option('auto', 'Auto', $cookieSecure) . '
                                ' . $this->option('true', 'Always secure', $cookieSecure) . '
                                ' . $this->option('false', 'Not secure', $cookieSecure) . '
                            </select>
                        </div>
                        <div class="form-row half">
                            <label for="settings-cookie-samesite">SameSite policy</label>
                            <select class="select" id="settings-cookie-samesite" name="session_cookie_samesite">
                                ' . $this->option('Strict', 'Strict', $cookieSameSite) . '
                                ' . $this->option('Lax', 'Lax', $cookieSameSite) . '
                                ' . $this->option('None', 'None', $cookieSameSite) . '
                            </select>
                        </div>
                    </div>
                </fieldset>

            </form>
        ';
    }

    private function navigationOrderFields(array $navigationOrder, array $topbarDisabledPages): string
    {
        $html = '';
        $rows = $this->navigationOrderRows($navigationOrder);
        $topbarDisabledLookup = array_fill_keys(array_values(array_filter(
            array_map(
                fn(mixed $pageKey): ?string => $this->normalisePageKey((string)$pageKey),
                $topbarDisabledPages
            )
        )), true);

        foreach ($rows as $index => $row) {
            $pageKey = (string)$row['key'];
            $isFirst = $index === 0;
            $isLast = $index === count($rows) - 1;
            $isOrphan = empty($row['exists']);
            $orphanBadge = $isOrphan ? '<span class="badge warning">Orphan</span>' : '';
            $removeButton = $isOrphan
                ? '<button class="button button-inline danger" type="submit" name="navigation_order_action" value="remove:' . \eel_accounts\Support\Utf8::html($pageKey) . '">Remove orphan</button>'
                : '';
            $topbarChecked = isset($topbarDisabledLookup[$pageKey]) ? '' : ' checked';

            $html .= '<div class="settings-order-row">
                <input type="hidden" name="navigation_order_keys[]" value="' . \eel_accounts\Support\Utf8::html($pageKey) . '">
                <div class="settings-order-label">
                    <strong>' . \eel_accounts\Support\Utf8::html((string)$row['label']) . '</strong>
                    ' . $orphanBadge . '
                </div>
                <div class="settings-order-actions">
                    <label class="settings-order-topbar-toggle" for="settings-topbar-enabled-' . \eel_accounts\Support\Utf8::html($pageKey) . '">
                        <input id="settings-topbar-enabled-' . \eel_accounts\Support\Utf8::html($pageKey) . '" name="topbar_enabled_pages[]" type="checkbox" value="' . \eel_accounts\Support\Utf8::html($pageKey) . '" data-submit-on-change="true"' . $topbarChecked . '>
                        <span>Topbar</span>
                    </label>
                    <button class="button button-inline" type="' . ($isFirst ? 'button' : 'submit') . '" name="navigation_order_action" value="up:' . \eel_accounts\Support\Utf8::html($pageKey) . '"' . ($isFirst ? ' disabled' : '') . ' title="Move up" aria-label="Move ' . \eel_accounts\Support\Utf8::html((string)$row['label']) . ' up">+</button>
                    <button class="button button-inline" type="' . ($isLast ? 'button' : 'submit') . '" name="navigation_order_action" value="down:' . \eel_accounts\Support\Utf8::html($pageKey) . '"' . ($isLast ? ' disabled' : '') . ' title="Move down" aria-label="Move ' . \eel_accounts\Support\Utf8::html((string)$row['label']) . ' down">-</button>
                    ' . $removeButton . '
                </div>
            </div>';
        }

        if ($html === '') {
            return '<p class="helper">No navigation order values are currently set.</p>';
        }

        return $html;
    }

    private function navigationOrderRows(array $navigationOrder): array
    {
        $pageKeys = $this->availablePageKeys();
        $pageLookup = array_fill_keys($pageKeys, true);
        $orders = [];

        foreach ($navigationOrder as $pageKey => $order) {
            $pageKey = $this->normalisePageKey((string)$pageKey);
            if ($pageKey === null) {
                continue;
            }

            $orders[$pageKey] = is_numeric($order) ? (int)$order : 1000;
        }

        foreach ($pageKeys as $pageKey) {
            if (!array_key_exists($pageKey, $orders)) {
                $orders[$pageKey] = 1000;
            }
        }

        $rows = [];
        foreach ($orders as $pageKey => $order) {
            $rows[] = [
                'key' => $pageKey,
                'label' => HelperFramework::labelFromKey($pageKey),
                'order' => $order,
                'exists' => isset($pageLookup[$pageKey]),
            ];
        }

        usort(
            $rows,
            static function (array $left, array $right): int {
                $orderComparison = ((int)$left['order']) <=> ((int)$right['order']);
                if ($orderComparison !== 0) {
                    return $orderComparison;
                }

                return strcasecmp((string)$left['label'], (string)$right['label']);
            }
        );

        return $rows;
    }

    private function availablePageKeys(): array
    {
        if (!defined('APP_PAGES')) {
            return [];
        }

        return (new NavigationFramework(APP_PAGES, 'settings'))->pageKeys();
    }

    private function normalisePageKey(string $pageKey): ?string
    {
        $pageKey = strtolower(trim($pageKey));
        $pageKey = str_replace('-', '_', $pageKey);

        return preg_match('/^[a-z][a-z0-9_]*$/', $pageKey) === 1 ? $pageKey : null;
    }

    private function textField(string $id, string $label, string $name, string $value): string
    {
        return '<div class="form-row half">
            <label for="' . \eel_accounts\Support\Utf8::html($id) . '">' . \eel_accounts\Support\Utf8::html($label) . '</label>
            <input class="input" id="' . \eel_accounts\Support\Utf8::html($id) . '" name="' . \eel_accounts\Support\Utf8::html($name) . '" type="text" value="' . \eel_accounts\Support\Utf8::html($value) . '">
        </div>';
    }

    private function vendorPublicIpField(string $value): string
    {
        return '<div class="form-row half">
            <label for="settings-vendor-public-ip">Vendor public IP</label>
            <div class="input-action-row">
                <input class="input" id="settings-vendor-public-ip" name="antifraud_vendor_public_ip" type="text" value="' . \eel_accounts\Support\Utf8::html($value) . '">
                <button class="button button-inline primary" type="submit" name="lookup_vendor_public_ip" value="1" data-processing-text="Looking up" data-processing-state="disabled">Lookup IP</button>
            </div>
        </div>';
    }

    private function option(string $value, string $label, string $currentValue): string
    {
        $selected = strcasecmp($value, $currentValue) === 0 ? ' selected' : '';

        return '<option value="' . \eel_accounts\Support\Utf8::html($value) . '"' . $selected . '>' . \eel_accounts\Support\Utf8::html($label) . '</option>';
    }

    private function cookieSecureDisplayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = strtolower(trim((string)$value));

        return in_array($value, ['auto', 'true', 'false'], true) ? $value : 'auto';
    }

    private function otpRequiredDisplayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string)$value) === '0' ? '0' : '1';
    }

    private function hiddenFields(array $context): string
    {
        $html = '';

        foreach ((array)($context['page']['page_cards'] ?? []) as $cardKey) {
            $html .= '<input type="hidden" name="cards[]" value="' . \eel_accounts\Support\Utf8::html((string)$cardKey) . '">';
        }

        return $html;
    }
}
