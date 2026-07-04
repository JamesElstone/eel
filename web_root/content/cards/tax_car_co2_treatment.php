<?php
declare(strict_types=1);

final class _tax_car_co2_treatmentCard extends CardBaseFramework
{
    public function key(): string { return 'tax_car_co2_treatment'; }
    public function title(): string { return 'Car CO2 Treatment'; }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $rows = [];
        foreach ((array)($workings['car_co2_treatment'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Ui\TaxCardRenderer::escape(trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''))),
                \eel_accounts\Ui\TaxCardRenderer::escape((string)($row['registration_mark'] ?? '')),
                \eel_accounts\Ui\TaxCardRenderer::escape((string)($row['co2_emissions_g_km'] ?? 'Missing')),
                \eel_accounts\Ui\TaxCardRenderer::escape(HelperFramework::labelFromKey((string)($row['acquisition_condition'] ?? ''), '_')),
                \eel_accounts\Ui\TaxCardRenderer::escape((int)($row['is_zero_emission'] ?? 0) === 1 ? 'Yes' : 'No'),
                \eel_accounts\Ui\TaxCardRenderer::escape(HelperFramework::labelFromKey((string)($row['pool_type'] ?? 'unreviewed'), '_')),
                \eel_accounts\Ui\TaxCardRenderer::escape(implode('; ', array_map('strval', (array)($row['warnings'] ?? [])))),
            ];
        }
        return \eel_accounts\Ui\TaxCardRenderer::header('business_cars')
            . \eel_accounts\Ui\TaxCardRenderer::table(['Asset', 'Registration', 'CO2 g/km', 'Condition', 'Zero emission', 'Treatment', 'Warnings'], $rows, 'No car CO2 treatment rows were found for this period.');
    }
}
