<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _companies_stored_detailCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'companies_stored_detail';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'company_detail',
                'service' => CompanyRepository::class,
                'method' => 'fetchCompanyDetails',
                'params' => ['companyId' => ':company.id'],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function helper(array $context) : string {
        if ((int)($context['company']['id'] ?? 0) < 0) {
            return 'No company selected.';
        }
        return 'This is the information returned by the Companies House API service.';
    }

    public function title() :string {
        return 'Company Detail';
    }

    public function render(array $context): string
    {
        if ((int)($context['company']['id'] ?? 0) <= 0) {
            return '';
        }

        $serviceResponse = (array)($context['services']['company_detail'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $addressLines = $this->companiesHouseStoredAddressLines($serviceResponse);
        $sicLines = array_values(array_filter(array_map(
            static fn(mixed $line): string => trim((string)$line),
            (array)($serviceResponse['resolved_sic_code_lines'] ?? [])
        ), static fn(string $line): bool => $line !== ''));

        return '
            <div class="form-grid">
                <div class="actions-row">
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="Company">
                        <input type="hidden" name="intent" value="refresh_company">
                        <button class="button primary" data-processing-text="Syncronising with Companies House..." data-processing-state="disabled" type="submit">Syncronise with Companies House</button>
                    </form>
                    <form method="post" data-ajax="true">
                        <input type="hidden" name="card_action" value="Company">
                        <input type="hidden" name="intent" value="refresh_sic">
                        <button class="button primary" data-processing-text="Syncing SIC codes..." data-processing-state="disabled" type="submit">Refresh SIC Lookup Data</button>
                    </form>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-row"><label>Status</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['company_status'] ?? '') !== '') ? $serviceResponse['company_status'] : 'Not stored yet')) . '" readonly></div>
                <div class="form-row"><label>API Environment</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['companies_house_environment'] ?? '') !== '') ? $serviceResponse['companies_house_environment'] : 'Not stored yet')) . '" readonly></div>
                <div class="form-row"><label>Last profile refresh</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['companies_house_last_checked_at'] ?? '') !== '') ? HelperFramework::displayDateTime((string)$serviceResponse['companies_house_last_checked_at']) : 'Not stored yet')) . '" readonly></div>
                <div class="form-row"><label>ETag</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['companies_house_etag'] ?? '') !== '') ? $serviceResponse['companies_house_etag'] : 'Not stored yet')) . '" readonly></div>
                <div class="form-row"><label>Type</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['companies_house_type'] ?? '') !== '') ? $serviceResponse['companies_house_type'] : 'Not stored yet')) . '" readonly></div>
                <div class="form-row"><label>Jurisdiction</label><input class="input" value="' . HelperFramework::escape((string)((($serviceResponse['companies_house_jurisdiction'] ?? '') !== '') ? $serviceResponse['companies_house_jurisdiction'] : 'Not stored yet')) . '" readonly></div>
                <div class="form-row">
                    <label>SIC codes</label>
                    <textarea class="input" rows="4" readonly>' . HelperFramework::escape($sicLines !== [] ? implode(PHP_EOL, $sicLines) : 'No SIC codes have been stored yet.') . '</textarea>
                </div>
                <div class="form-row">
                    <label>Registered office</label>
                    <textarea class="input" rows="6" readonly>' . HelperFramework::escape($addressLines !== [] ? implode(PHP_EOL, $addressLines) : 'No registered office address has been stored yet.') . '</textarea>
                </div>
            </div>
            <div class="table-scroll-mini">
                <table>
                    <thead><th>Property</th><th>Value</th></thead>
                    <tbody>
                        <tr><td scope="row">Can file</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['can_file'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Has charges</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['has_charges'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Has insolvency history</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['has_insolvency_history'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Has been liquidated</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['has_been_liquidated'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Registered office in dispute</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['registered_office_is_in_dispute'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Undeliverable registered office</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['undeliverable_registered_office_address'] ?? null)) . '</td></tr>
                        <tr><td scope="row">Has super secure PSCs</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($serviceResponse['has_super_secure_pscs'] ?? null)) . '</td></tr>
                    </tbody>
                </table>
            </div>
        ';
    }

    private function companiesHouseStoredAddressLines(array $serviceResponse): array
    {
        $fields = [
            'registered_office_premises',
            'registered_office_address_line_1',
            'registered_office_address_line_2',
            'registered_office_locality',
            'registered_office_region',
            'registered_office_postal_code',
            'registered_office_country',
        ];
        $lines = [];
        foreach ($fields as $field) {
            $value = trim((string)($serviceResponse[$field] ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }
        return $lines;
    }

    private function companiesHouseFlagLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Not stored yet';
        }
        return (int)$value === 1 ? 'Yes' : 'No';
    }
}
