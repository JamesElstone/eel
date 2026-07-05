<?php
declare(strict_types=1);

final class _tax_main_rate_poolCard extends CardBaseFramework
{
    public function key(): string { return 'tax_main_rate_pool'; }
    public function title(): string { return 'Main-Rate Pool'; }
    public function helper(array $context): string { return \eel_accounts\Ui\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        return $this->poolHtml($context, 'main_rate_pool');
    }

    private function poolHtml(array $context, string $key): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $pool = (array)($workings[$key] ?? []);
        return \eel_accounts\Ui\TaxCardRenderer::header('wda')
            . \eel_accounts\Ui\TaxCardRenderer::summaryGrid([
                ['Opening Written Down Value (WDV)', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['opening_wdv'] ?? 0)],
                ['Additions', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['additions'] ?? 0)],
                ['Annual Investment Allowance (AIA)', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['aia_claimed'] ?? 0)],
                ['First Year Allowance (FYA)', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['fya_claimed'] ?? 0)],
                ['Disposals', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['disposal_value'] ?? 0)],
                ['Writing Down Allowance (WDA)', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['wda_claimed'] ?? 0)],
                ['Balancing charge', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['balancing_charge'] ?? 0)],
                ['Closing Written Down Value (WDV)', \eel_accounts\Ui\TaxCardRenderer::money($context, $pool['closing_wdv'] ?? 0)],
            ]);
    }
}
