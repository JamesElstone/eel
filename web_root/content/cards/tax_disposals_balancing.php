<?php
declare(strict_types=1);

final class _tax_disposals_balancingCard extends CardBaseFramework
{
    public function key(): string { return 'tax_disposals_balancing'; }
    public function title(): string { return 'Disposals / Balancing Charges'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $rows = [];
        foreach ((array)($workings['disposals_balancing'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape(trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''))),
                \eel_accounts\Renderer\TaxCardRenderer::escape(HelperFramework::labelFromKey((string)($row['pool_type'] ?? ''), '_')),
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['disposal_date'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['disposal_value'] ?? ($row['disposal_proceeds'] ?? 0))),
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['allowance_type'] ?? 'disposal')),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('capital_allowances')
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Asset', 'Pool', 'Disposal date', 'Disposal value', 'Tax treatment'], $rows, 'No disposal or balancing rows were found for this period.');
    }
}
