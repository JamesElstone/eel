<?php
declare(strict_types=1);

final class _tax_capital_allowances_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'tax_capital_allowances_summary'; }
    public function title(): string { return 'Capital Allowances Summary'; }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $summary = (array)($workings['capital_allowances_summary'] ?? []);
        return \eel_accounts\Ui\TaxCardRenderer::header('capital_allowances')
            . \eel_accounts\Ui\TaxCardRenderer::summaryGrid([
                ['AIA', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['aia_claimed'] ?? 0)],
                ['FYA', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['fya_claimed'] ?? 0)],
                ['WDA', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['wda_claimed'] ?? 0)],
                ['Balancing charges', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['balancing_charge'] ?? 0)],
                ['Balancing allowances', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['balancing_allowance'] ?? 0)],
                ['Net capital allowances', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['net_capital_allowances'] ?? 0)],
            ]);
    }
}
