<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_company_settingsCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_company_settings';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'company_detail',
                'service' => \eel_accounts\Repository\CompanyRepository::class,
                'method' => 'fetchCompanyDetails',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }

    public function contextTitle(array $context) : string
    {
        $company = (array)($context['company'] ?? []);
        $companyName = (string)($company['name'] ?? '');
        $companyNumber = (string)($company['number'] ?? '');

        return 'Company Settings'
            . ($companyName !== '' && $companyNumber !== '' ? ' for ' . $companyName . ' (' . $companyNumber . ')' : '');
    }

    private function associatedCompanyCountOptions(int $selectedCount): string
    {
        $options = '';

        for ($count = 0; $count <= 10; $count++) {
            $label = $count === 0
                ? '0 - none'
                : (string)$count . ' other compan' . ($count === 1 ? 'y' : 'ies');
            $options .= '<option value="' . $count . '"' . ($selectedCount === $count ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        if ($selectedCount > 10) {
            $options .= '<option value="' . $selectedCount . '" selected>'
                . HelperFramework::escape((string)$selectedCount . ' other companies')
                . '</option>';
        }

        return $options;
    }

    public function render(array $context): string
    {

        if ((string)($context['company']['id'] ?? 0) <= 0) {
            return '
                <div class="standout helper">
                    A company needs to be added before company settings can be configured.
                </div>
                <div class="actions-row">
                    <button class="button primary" type="button" data-page-card-switch-tab="Add">Add company</button>
                </div>
            ';
        }

        $settings = (array)($context['company']['settings'] ?? []);
        $utrMissing = ($context['company']['settings']['utr'] ?? 0) === 0;
        $utr = $utrMissing ? '' : (string)($context['company']['settings']['utr'] ?? '');
        $associatedCompanyCount = max(0, (int)($context['company']['settings']['associated_company_count'] ?? 0));
        $defaultCurrency = (string)($context['company']['settings']['default_currency'] ?? '');
        $dateFormat = (string)($context['company']['settings']['date_format'] ?? '');
        $companyDetail = (array)($context['services']['company_detail'] ?? []);
        $activeDirectorCount = $companyDetail['companies_house_active_director_count'] ?? null;
        $activeDirectorLabel = $activeDirectorCount === null || $activeDirectorCount === ''
            ? 'Not checked yet'
            : (string)(int)$activeDirectorCount;

        return '
            <form method="post" data-ajax="true">
                <input type="hidden" name="card_action" value="Company">
                <input type="hidden" name="intent" value="save_company">
                <section data-state-fields="utr,associated_company_count,default_currency,date_format" data-state-target="save_company_settings_button">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="company_name">Company name</label>
                        <input class="input" id="company_name" name="company_name" value="' . HelperFramework::escape((string)($context['company']['name'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="companies_house_number">Companies House number</label>
                        <input class="input" id="companies_house_number" name="companies_house_number" value="' . HelperFramework::escape((string)($context['company']['number'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="utr">HMRC Uniquie Tax Reference (UTR)</label>
                        <div class="actions-row">
                            <input class="input' . ($utrMissing ? ' input-missing-required' : '') . '" id="utr" name="utr" value="' . HelperFramework::escape($utr) . '" placeholder="Enter corporation tax UTR">
                            <button class="button primary" id="save_company_settings_button" type="submit" disabled>Save</button>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="incorporation_date">Detected incorporation date</label>
                        <input class="input" id="incorporation_date" value="' . HelperFramework::escape((string)($companyDetail['incorporation_date'] ?? '')) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label>Companies House active directors</label>
                        <input class="input" value="' . HelperFramework::escape($activeDirectorLabel) . '" readonly>
                    </div>
                    <div class="form-row">
                        <label for="associated_company_count">Associated companies excluding this company</label>
                        <select class="select" id="associated_company_count" name="associated_company_count" data-state-default="' . HelperFramework::escape((string)$associatedCompanyCount) . '">
                            ' . $this->associatedCompanyCountOptions($associatedCompanyCount) . '
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="default_currency">Currency</label>
                        <input type="hidden" id="company_default_currency_state" value="' . HelperFramework::escape($defaultCurrency) . '" data-state-default="' . HelperFramework::escape($defaultCurrency) . '">
                        <select class="select" id="default_currency" name="default_currency">
                            <option value="GBP"' . ($defaultCurrency === 'GBP' ? ' selected' : '') . '>GBP</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="date_format">Display Date Format</label>
                        <input type="hidden" id="company_date_format_state" value="' . HelperFramework::escape($dateFormat) . '" data-state-default="' . HelperFramework::escape($dateFormat) . '">
                        <select class="select" id="date_format" name="date_format">
                            <option value="Y-m-d"' . ($dateFormat === 'Y-m-d' ? ' selected' : '') . '>Y-m-d</option>
                            <option value="d/m/Y"' . ($dateFormat === 'd/m/Y' ? ' selected' : '') . '>d/m/Y</option>
                            <option value="d-m-Y"' . ($dateFormat === 'd-m-Y' ? ' selected' : '') . '>d-m-Y</option>
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
            </form>
       ';
    }
}
