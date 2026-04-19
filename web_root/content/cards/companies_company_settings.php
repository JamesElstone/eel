<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_company_settingsCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_company_settings';
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
        $page = (array)($context['page'] ?? []);
        if (empty($page['has_valid_selected_company'])) {
            return '';
        }

        $settings = (array)($page['settings'] ?? []);
        $utrMissing = trim((string)($settings['utr'] ?? '')) === '';

        return '<div class="card settings-section" data-section="company">
            <div class="card-header">
                <h2 class="card-title">Company Settings</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="company_name">Company name</label>
                        <input class="input" id="company_name" name="company_name" value="' . HelperFramework::escape((string)($settings['company_name'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="companies_house_number">Companies House number</label>
                        <input class="input" id="companies_house_number" name="companies_house_number" value="' . HelperFramework::escape((string)($settings['companies_house_number'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="utr">HMRC Uniquie Tax Reference (UTR)</label>
                        <input class="input' . ($utrMissing ? ' input-missing-required' : '') . '" id="utr" name="utr" value="' . HelperFramework::escape((string)($settings['utr'] ?? '')) . '" placeholder="Enter corporation tax UTR"' . ($utrMissing ? ' style="border-color: #991b1b; box-shadow: 0 0 0 1px rgba(153, 27, 27, 0.18);"' : '') . '>
                    </div>
                    <div class="form-row">
                        <label for="incorporation_date">Detected incorporation date</label>
                        <input class="input" id="incorporation_date" value="' . HelperFramework::escape((string)($settings['incorporation_date'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="default_currency">Currency</label>
                        <select class="select" id="default_currency" name="default_currency">
                            <option' . ((string)($settings['default_currency'] ?? '') === 'GBP' ? ' selected' : '') . '>GBP</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="date_format">Display Date Format</label>
                        <select class="select" id="date_format" name="date_format">
                            <option' . ((string)($settings['date_format'] ?? '') === 'Y-m-d' ? ' selected' : '') . '>Y-m-d</option>
                            <option' . ((string)($settings['date_format'] ?? '') === 'd/m/Y' ? ' selected' : '') . '>d/m/Y</option>
                            <option' . ((string)($settings['date_format'] ?? '') === 'd-m-Y' ? ' selected' : '') . '>d-m-Y</option>
                        </select>
                    </div>
                    <div class="form-row full">
                        <label>VAT Status</label>
                        ' . (!empty($settings['is_vat_registered'])
                            ? '<div class="helper">
                                VAT registered.'
                                . (in_array(trim((string)($settings['vat_validation_status'] ?? '')), ['valid', 'mismatch_override'], true)
                                    && trim((string)($settings['vat_country_code'] ?? '')) !== ''
                                    && trim((string)($settings['vat_number'] ?? '')) !== ''
                                    ? ' Validated number: ' . HelperFramework::escape((string)$settings['vat_country_code']) . HelperFramework::escape((string)$settings['vat_number']) . '.'
                                    : (trim((string)($settings['vat_number'] ?? '')) !== ''
                                        ? ' Current number: ' . HelperFramework::escape((string)$settings['vat_country_code']) . HelperFramework::escape((string)$settings['vat_number']) . '.'
                                        : ''))
                                . ' Manage VAT registration on the VAT page.
                            </div>'
                            : '<div class="helper">Not VAT registered.</div>') . '
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <button class="button section-save-button" type="submit" disabled onclick="document.getElementById(\'settings_action_field\').value=\'save_company\'" data-ajax-card-update="companies-company-settings,companies-setup-health">Save Company Settings</button>
                </div>
            </div>
        </div>';
    }
}
