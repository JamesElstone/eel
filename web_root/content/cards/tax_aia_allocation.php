<?php
declare(strict_types=1);

final class _tax_aia_allocationCard extends CardBaseFramework
{
    public function key(): string { return 'tax_aia_allocation'; }
    public function title(): string { return 'Annual Investment Allowance (AIA) Allocation'; }
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
        $used = 0.0;
        foreach ((array)($workings['aia_allocation'] ?? []) as $row) {
            $used += (float)($row['allowance_amount'] ?? 0);
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['purchase_date'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''))),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['addition_amount'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['allowance_amount'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $used)),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('aia')
            . \eel_accounts\Renderer\TaxCardRenderer::computationPersistenceNotice($workings)
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Purchase date', 'Asset', 'Addition', 'Annual Investment Allowance (AIA) claimed', 'Annual Investment Allowance (AIA) used'], $rows, 'No Annual Investment Allowance (AIA) allocation rows were found for this period.');
    }
}
