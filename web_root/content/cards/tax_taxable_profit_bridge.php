<?php
declare(strict_types=1);

final class _tax_taxable_profit_bridgeCard extends CardBaseFramework
{
    public function key(): string { return 'tax_taxable_profit_bridge'; }
    public function title(): string { return 'Taxable Profit Bridge'; }
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
        foreach ((array)($workings['bridge'] ?? []) as $step) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape($step['label'] ?? ''),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $step['amount'] ?? 0)),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns', [
            'bim35201' => 'HMRC - BIM35201: Depreciation',
            'ctm01405' => 'HMRC - CTM01405: Apportionment',
        ])
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Step', 'Amount'], $rows, 'No taxable profit bridge is available.');
    }
}
