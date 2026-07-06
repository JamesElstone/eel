<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _vehicle_registerCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'vehicle_register';
    }

    public function title(): string
    {
        return 'Vehicle Register';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'vehicleRegister',
                'service' => \eel_accounts\Service\VehicleService::class,
                'method' => 'fetchRegister',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['asset.register', 'asset.tax', 'year.end.checklist'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return 'Vehicle data could not be loaded: ' . (string)($error['message'] ?? 'service error');
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $data = (array)($context['services']['vehicleRegister'] ?? []);
        $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn(mixed $row): bool => is_array($row)));

        if (array_key_exists('schema_ready', $data) && empty($data['schema_ready'])) {
            return '<div class="helper">Run the vehicle register migration before reviewing vehicles.</div>';
        }

        if ($rows === []) {
            return '<div class="helper">No vehicle assets are waiting for review in the selected accounting period.</div>';
        }

        $warningHtml = $this->warningPanel((array)($data['warnings'] ?? []));
        $body = '';
        foreach ($rows as $row) {
            $body .= $this->rowHtml($row, (array)($data['vehicle_types'] ?? []), (array)($data['acquisition_conditions'] ?? []), $company, $settings);
        }

        return $warningHtml . '<div class="table-scroll"><table>
            <thead><tr>
                <th>Asset</th>
                <th>Vehicle facts</th>
                <th>Tax facts</th>
                <th>Status</th>
                <th>Action</th>
            </tr></thead>
            <tbody>' . $body . '</tbody>
        </table></div>';
    }

    private function rowHtml(array $row, array $vehicleTypes, array $conditions, array $company, array $settings): string
    {
        $companyId = (int)($company['id'] ?? 0);
        $assetId = (int)($row['id'] ?? 0);
        $formId = 'vehicle-row-' . $assetId;
        $vehicleType = (string)($row['vehicle_type'] ?? 'unreviewed');
        if ($vehicleType === '') {
            $vehicleType = (string)($row['nominal_code'] ?? '') === '1321' ? 'car' : ((string)($row['nominal_code'] ?? '') === '1322' ? 'van' : 'unreviewed');
        }

        $assetHtml = '<div><strong>' . HelperFramework::escape((string)($row['asset_code'] ?? '')) . '</strong></div>'
            . '<div>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</div>'
            . '<div class="helper">' . HelperFramework::escape($this->displayDate((string)($row['purchase_date'] ?? '')) . ' - ' . $this->money($settings, $row['cost'] ?? 0)) . '</div>'
            . '<div class="helper">' . HelperFramework::escape((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? '')) . '</div>';

        $vehicleFacts = '<form id="' . HelperFramework::escape($formId) . '" method="post" action="?page=vehicles" data-ajax="true" data-vehicle-row="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Vehicle">
                <input type="hidden" name="intent" value="save_vehicle_details">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="asset_id" value="' . $assetId . '">
                <input type="hidden" name="default_bank_nominal_id" value="' . (int)(($company['settings'] ?? [])['default_bank_nominal_id'] ?? 0) . '">
                <div class="form-grid compact">
                    <label>Type<select class="select" name="vehicle_type" data-vehicle-watch>' . $this->options($vehicleTypes, $vehicleType) . '</select></label>
                    <label>Registration<input class="input" name="registration_mark" value="' . HelperFramework::escape((string)($row['registration_mark'] ?? '')) . '" data-vehicle-watch></label>
                    <label>Make / model<input class="input" name="make_model" value="' . HelperFramework::escape((string)($row['make_model'] ?? '')) . '" data-vehicle-watch></label>
                    <label>Colour<input class="input" name="colour" value="' . HelperFramework::escape((string)($row['colour'] ?? '')) . '" data-vehicle-watch></label>
                </div>
            </form>';

        $taxFacts = '<div class="form-grid compact">
                <label>Condition<select class="select" name="acquisition_condition" form="' . HelperFramework::escape($formId) . '" data-vehicle-watch>' . $this->options($conditions, (string)($row['acquisition_condition'] ?? '')) . '</select></label>
                <label>CO2 g/km<input class="input" type="number" min="0" step="1" name="co2_emissions_g_km" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['co2_emissions_g_km'] ?? '')) . '" data-vehicle-watch></label>
                <label>Engine cc<input class="input" type="number" min="0" step="1" name="engine_capacity_cc" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['engine_capacity_cc'] ?? '')) . '" data-vehicle-watch></label>
                <label>Payload kg<input class="input" type="number" min="0" step="0.01" name="payload_kg" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['payload_kg'] ?? '')) . '" data-vehicle-watch></label>
                <label>First registered<input class="input" type="date" name="first_registered_date" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['first_registered_date'] ?? '')) . '" data-vehicle-watch></label>
                <label>Contract date<input class="input" type="date" name="contract_date" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['contract_date'] ?? '')) . '" data-vehicle-watch></label>
                <label class="checkbox-row"><input type="checkbox" name="is_zero_emission" value="1" form="' . HelperFramework::escape($formId) . '"' . ((int)($row['is_zero_emission'] ?? 0) === 1 ? ' checked' : '') . ' data-vehicle-watch><span>Zero emission</span></label>
                <label>Notes<input class="input" name="notes" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['notes'] ?? '')) . '" data-vehicle-watch></label>
            </div>';

        $warnings = '';
        foreach ((array)($row['warnings'] ?? []) as $warning) {
            $warnings .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }
        $status = (string)($row['tax_review_status'] ?? 'unreviewed');
        $statusHtml = '<span class="badge ' . HelperFramework::escape($status === 'reviewed' ? 'success' : 'warning') . '">' . HelperFramework::escape($status !== '' ? $status : 'unreviewed') . '</span>' . $warnings;

        return '<tr>
            <td>' . $assetHtml . '</td>
            <td>' . $vehicleFacts . '</td>
            <td>' . $taxFacts . '</td>
            <td>' . $statusHtml . '</td>
            <td><button class="button primary" type="submit" form="' . HelperFramework::escape($formId) . '" data-vehicle-save disabled>Save</button></td>
        </tr>';
    }

    private function warningPanel(array $warnings): string
    {
        $warnings = array_values(array_unique(array_filter(array_map('strval', $warnings))));
        if ($warnings === []) {
            return '';
        }

        $html = '';
        foreach ($warnings as $warning) {
            $html .= '<div class="helper">' . HelperFramework::escape($warning) . '</div>';
        }

        return '<section class="panel-soft settings-stack"><span class="badge warning">Warning</span>' . $html . '</section>';
    }

    private function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>'
                . HelperFramework::escape((string)$label)
                . '</option>';
        }

        return $html;
    }

    private function money(array $settings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($settings, $value);
    }

    private function displayDate(string $value): string
    {
        return trim($value) !== '' ? HelperFramework::displayDate($value) : '';
    }
}
