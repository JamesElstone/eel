<?php
declare(strict_types=1);

final class _tax_depreciation_add_backCard extends CardBaseFramework
{
    public function key(): string { return 'tax_depreciation_add_back'; }
    public function title(): string { return 'Depreciation Add-Back'; }
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
        foreach ((array)($workings['depreciation_add_back'] ?? []) as $row) {
            $asset = trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''));
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape($asset !== '' ? $asset : 'Period adjustment'),
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['direction'] ?? 'add')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['amount'] ?? 0)),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('capital_allowances')
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Asset', 'Direction', 'Amount'], $rows, 'No depreciation add-back rows were found for this period.');
    }
}
