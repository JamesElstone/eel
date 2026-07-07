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
    private const PAGE_SIZE = 5;

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

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    public function render(array $context): string
    {
        $company = (array)($context['company'] ?? []);
        $data = (array)($context['services']['vehicleRegister'] ?? []);
        $isLocked = (new \eel_accounts\Service\YearEndLockService())->isLocked((int)($company['id'] ?? 0), (int)($company['accounting_period_id'] ?? 0));

        if (array_key_exists('schema_ready', $data) && empty($data['schema_ready'])) {
            return '<div class="helper">Run the vehicle register migration before reviewing vehicles.</div>';
        }

        return $this->warningPanel((array)($data['warnings'] ?? []))
            . ($isLocked ? '<div class="helper"><span class="badge warning">Period locked</span> Vehicle facts are read only.</div>' : '')
            . $this->configuredTable($context)->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]);
    }

    private function configuredTable(array $context): TableFramework
    {
        $company = (array)($context['company'] ?? []);
        $table = $this->table($context);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Vehicles',
                $this->paginationPageField(),
                [
                    'page' => (string)($context['page']['page_id'] ?? 'vehicles'),
                    '_pagination' => '1',
                    '_invalidate_fact' => $this->tableInvalidationFact(),
                    'cards[]' => [$this->key()],
                    'company_id' => (int)($company['id'] ?? 0),
                    'accounting_period_id' => (int)($company['accounting_period_id'] ?? 0),
                ]
            );
    }

    private function table(array $context): TableFramework
    {
        $company = (array)($context['company'] ?? []);
        $settings = (array)($company['settings'] ?? []);
        $data = (array)($context['services']['vehicleRegister'] ?? []);
        $vehicleTypes = (array)($data['vehicle_types'] ?? []);
        $conditions = (array)($data['acquisition_conditions'] ?? []);
        $vehicleColours = (array)($data['vehicle_colours'] ?? \eel_accounts\Service\VehicleService::vehicleColourOptions());
        $isLocked = (new \eel_accounts\Service\YearEndLockService())->isLocked((int)($company['id'] ?? 0), (int)($company['accounting_period_id'] ?? 0));

        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('vehicle-register')
            ->exportLimit(5000)
            ->classes('vehicle-register-table')
            ->empty('No vehicle assets are waiting for review in the selected accounting period.')
            ->column(
                'asset',
                'Asset',
                html: fn(array $row): string => $this->assetHtml($row, $settings),
                export: fn(array $row): string => $this->assetExport($row, $settings)
            )
            ->column(
                'vehicle_facts',
                'Vehicle facts',
                html: fn(array $row): string => $this->vehicleFactsHtml($row, $vehicleTypes, $vehicleColours, $company, $isLocked),
                export: fn(array $row): string => $this->vehicleFactsExport($row, $vehicleTypes, $vehicleColours)
            )
            ->column(
                'tax_facts',
                'Tax facts',
                html: fn(array $row): string => $this->taxFactsHtml($row, $conditions, $isLocked),
                export: fn(array $row): string => $this->taxFactsExport($row, $conditions)
            )
            ->column(
                'tax_review_status',
                'Status',
                html: fn(array $row): string => $this->statusHtml($row),
                export: fn(array $row): string => $this->statusExport($row)
            )
            ->column(
                'actions',
                'Action',
                html: fn(array $row): string => $this->actionHtml($row, $isLocked),
                exportable: false
            );
    }

    private function rows(array $context): array
    {
        $data = (array)($context['services']['vehicleRegister'] ?? []);

        return array_values(array_filter((array)($data['rows'] ?? []), static fn(mixed $row): bool => is_array($row)));
    }

    private function assetHtml(array $row, array $settings): string
    {
        return '<div><strong>' . HelperFramework::escape((string)($row['asset_code'] ?? '')) . '</strong></div>'
            . '<div>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</div>'
            . '<div class="helper">' . HelperFramework::escape($this->displayDate((string)($row['purchase_date'] ?? '')) . ' - ' . $this->money($settings, $row['cost'] ?? 0)) . '</div>'
            . '<div class="helper">' . HelperFramework::escape((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? '')) . '</div>';
    }

    private function vehicleFactsHtml(array $row, array $vehicleTypes, array $vehicleColours, array $company, bool $isLocked): string
    {
        $companyId = (int)($company['id'] ?? 0);
        $assetId = (int)($row['id'] ?? 0);
        $formId = $this->formId($row);
        $vehicleType = $this->vehicleType($row);

        return '<form id="' . HelperFramework::escape($formId) . '" method="post" action="?page=vehicles" data-ajax="true" data-vehicle-row="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
                <input type="hidden" name="card_action" value="Vehicle">
                <input type="hidden" name="intent" value="save_vehicle_details">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="asset_id" value="' . $assetId . '">
                <input type="hidden" name="default_bank_nominal_id" value="' . (int)(($company['settings'] ?? [])['default_bank_nominal_id'] ?? 0) . '">
                <div class="form-grid compact vehicle-register-controls vehicle-facts-controls">
                    <label>Type<select class="select" name="vehicle_type" data-vehicle-watch data-no-submit-on-change="true"' . ($isLocked ? ' disabled' : '') . '>' . $this->options($vehicleTypes, $vehicleType) . '</select></label>
                    <label>Registration<input class="input" name="registration_mark" value="' . HelperFramework::escape((string)($row['registration_mark'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                    <label>Make / model<input class="input" name="make_model" value="' . HelperFramework::escape((string)($row['make_model'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                    <label>Colour<select class="select" name="colour" data-vehicle-watch data-no-submit-on-change="true"' . ($isLocked ? ' disabled' : '') . '>' . $this->options($vehicleColours, (string)($row['colour'] ?? '')) . '</select></label>
                </div>
            </form>';
    }

    private function taxFactsHtml(array $row, array $conditions, bool $isLocked): string
    {
        $formId = $this->formId($row);

        return '<div class="form-grid compact vehicle-register-controls vehicle-tax-controls">
                <label>Condition<select class="select" name="acquisition_condition" form="' . HelperFramework::escape($formId) . '" data-vehicle-watch data-no-submit-on-change="true"' . ($isLocked ? ' disabled' : '') . '>' . $this->options($conditions, (string)($row['acquisition_condition'] ?? '')) . '</select></label>
                <label>CO2 g/km<input class="input" type="number" min="0" step="1" name="co2_emissions_g_km" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['co2_emissions_g_km'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                <label>Engine cc<input class="input" type="number" min="0" step="1" name="engine_capacity_cc" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['engine_capacity_cc'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                <label>Payload kg<input class="input" type="number" min="0" step="0.01" name="payload_kg" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['payload_kg'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                <label>First registered<input class="input" type="date" name="first_registered_date" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['first_registered_date'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                <label class="checkbox-row"><span>Zero emission</span><input type="checkbox" name="is_zero_emission" value="1" form="' . HelperFramework::escape($formId) . '"' . ((int)($row['is_zero_emission'] ?? 0) === 1 ? ' checked' : '') . ' data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
                <label>Notes<input class="input" name="notes" form="' . HelperFramework::escape($formId) . '" value="' . HelperFramework::escape((string)($row['notes'] ?? '')) . '" data-vehicle-watch' . ($isLocked ? ' disabled' : '') . '></label>
            </div>';
    }

    private function statusHtml(array $row): string
    {
        $warnings = '';
        foreach ((array)($row['warnings'] ?? []) as $warning) {
            $warnings .= '<div class="helper">' . HelperFramework::escape((string)$warning) . '</div>';
        }
        $status = (string)($row['tax_review_status'] ?? 'unreviewed');
        return '<span class="badge ' . HelperFramework::escape($status === 'reviewed' ? 'success' : 'warning') . '">' . HelperFramework::escape($status !== '' ? $status : 'unreviewed') . '</span>' . $warnings;
    }

    private function actionHtml(array $row, bool $isLocked): string
    {
        return '<button class="button primary" type="submit" form="' . HelperFramework::escape($this->formId($row)) . '" data-vehicle-save disabled' . ($isLocked ? ' title="Period locked"' : '') . '>Save</button>';
    }

    private function assetExport(array $row, array $settings): string
    {
        return $this->joinExportParts([
            (string)($row['asset_code'] ?? ''),
            (string)($row['description'] ?? ''),
            (string)($row['purchase_date'] ?? ''),
            $this->money($settings, $row['cost'] ?? 0),
            trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? '')),
        ]);
    }

    private function vehicleFactsExport(array $row, array $vehicleTypes, array $vehicleColours): string
    {
        $vehicleType = $this->vehicleType($row);

        return $this->joinExportParts([
            'Type: ' . (string)($vehicleTypes[$vehicleType] ?? $vehicleType),
            'Registration: ' . (string)($row['registration_mark'] ?? ''),
            'Make / model: ' . (string)($row['make_model'] ?? ''),
            'Colour: ' . (string)($vehicleColours[(string)($row['colour'] ?? '')] ?? (string)($row['colour'] ?? '')),
        ]);
    }

    private function taxFactsExport(array $row, array $conditions): string
    {
        return $this->joinExportParts([
            'Condition: ' . (string)($conditions[(string)($row['acquisition_condition'] ?? '')] ?? (string)($row['acquisition_condition'] ?? '')),
            'CO2 g/km: ' . (string)($row['co2_emissions_g_km'] ?? ''),
            'Engine cc: ' . (string)($row['engine_capacity_cc'] ?? ''),
            'Payload kg: ' . (string)($row['payload_kg'] ?? ''),
            'First registered: ' . (string)($row['first_registered_date'] ?? ''),
            'Zero emission: ' . ((int)($row['is_zero_emission'] ?? 0) === 1 ? 'Yes' : 'No'),
            'Notes: ' . (string)($row['notes'] ?? ''),
        ]);
    }

    private function statusExport(array $row): string
    {
        return $this->joinExportParts(array_merge(
            [(string)($row['tax_review_status'] ?? 'unreviewed')],
            array_map('strval', (array)($row['warnings'] ?? []))
        ));
    }

    private function joinExportParts(array $parts): string
    {
        return implode(' | ', array_values(array_filter(array_map(
            static fn(mixed $part): string => trim((string)$part),
            $parts
        ), static fn(string $part): bool => $part !== '' && !str_ends_with($part, ':'))));
    }

    private function formId(array $row): string
    {
        return 'vehicle-row-' . (int)($row['id'] ?? 0);
    }

    private function vehicleType(array $row): string
    {
        $vehicleType = (string)($row['vehicle_type'] ?? 'unreviewed');
        if ($vehicleType === '') {
            $vehicleType = (string)($row['nominal_code'] ?? '') === '1321' ? 'car' : ((string)($row['nominal_code'] ?? '') === '1322' ? 'van' : 'unreviewed');
        }

        return $vehicleType;
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
            $isSelected = (string)$value === $selected || ($selected !== '' && strcasecmp((string)$value, $selected) === 0);
            $html .= '<option value="' . HelperFramework::escape((string)$value) . '"' . ($isSelected ? ' selected' : '') . '>'
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

    private function tableInvalidationFact(): string
    {
        return $this->invalidationFacts()[0] ?? $this->key();
    }
}
