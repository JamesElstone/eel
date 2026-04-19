<?php
declare(strict_types=1);

final class _companies_stored_detailCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'companies_stored_detail';
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
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $dateFormat = (string)($settings['date_format'] ?? '');
        $addressLines = $this->companiesHouseStoredAddressLines($settings);

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Stored Companies House Detail</h2>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-row"><label>Status</label><input class="input" value="' . HelperFramework::escape((string)((($settings['company_status'] ?? '') !== '') ? $settings['company_status'] : 'Not stored yet')) . '" readonly></div>
                    <div class="form-row"><label>API Environment</label><input class="input" value="' . HelperFramework::escape((string)((($settings['companies_house_environment'] ?? '') !== '') ? $settings['companies_house_environment'] : 'Not stored yet')) . '" readonly></div>
                    <div class="form-row"><label>Last profile refresh</label><input class="input" value="' . HelperFramework::escape((string)((($settings['companies_house_last_checked_at'] ?? '') !== '') ? HelperFramework::displayDateTime((string)$settings['companies_house_last_checked_at'], $selectedCompanyId, $dateFormat) : 'Not stored yet')) . '" readonly></div>
                    <div class="form-row"><label>ETag</label><input class="input" value="' . HelperFramework::escape((string)((($settings['companies_house_etag'] ?? '') !== '') ? $settings['companies_house_etag'] : 'Not stored yet')) . '" readonly></div>
                    <div class="form-row full">
                        <label>Registered office</label>
                        <textarea class="input" rows="5" readonly>' . HelperFramework::escape($addressLines !== [] ? implode(PHP_EOL, $addressLines) : 'No registered office address has been stored yet.') . '</textarea>
                    </div>
                </div>
                <div class="table-scroll" style="margin-top: 16px;">
                    <table><tbody>
                        <tr><th scope="row">Can file</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['can_file'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Has charges</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['has_charges'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Has insolvency history</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['has_insolvency_history'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Has been liquidated</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['has_been_liquidated'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Registered office in dispute</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['registered_office_is_in_dispute'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Undeliverable registered office</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['undeliverable_registered_office_address'] ?? null)) . '</td></tr>
                        <tr><th scope="row">Has super secure PSCs</th><td>' . HelperFramework::escape($this->companiesHouseFlagLabel($settings['has_super_secure_pscs'] ?? null)) . '</td></tr>
                    </tbody></table>
                </div>
                <div style="margin-top: 16px;">
                    <button class="button primary" type="submit" onclick="document.getElementById(\'settings_action_field\').value=\'refresh_company_profile\'" data-ajax-card-update="companies-stored-detail,companies-accounting,companies-setup-health">Refresh Stored Companies House Detail and Filings</button>
                </div>
            </div>
        </div>';
    }

    private function companiesHouseStoredAddressLines(array $settings): array
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
            $value = trim((string)($settings[$field] ?? ''));
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
