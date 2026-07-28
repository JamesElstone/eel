<?php
declare(strict_types=1);

final class _tax_main_rate_poolCard extends CardBaseFramework
{
    public function key(): string { return 'tax_main_rate_pool'; }
    public function title(): string { return 'Main-Rate Pool'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        return $this->poolHtml($context, 'main_rate_pool');
    }

    private function poolHtml(array $context, string $key): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $pool = (array)($workings[$key] ?? []);
        $openingWdv = (float)($pool['opening_wdv'] ?? 0);
        $residualAdditions = (float)($pool['additions'] ?? 0);
        $aiaClaimed = (float)($pool['aia_claimed'] ?? 0);
        $fyaClaimed = (float)($pool['fya_claimed'] ?? 0);
        $disposalValue = (float)($pool['disposal_value'] ?? 0);
        $qualifyingAdditions = round($residualAdditions + $aiaClaimed + $fyaClaimed, 2);
        $availableQualifyingExpenditure = round(
            $openingWdv + $qualifyingAdditions - $aiaClaimed - $fyaClaimed - $disposalValue,
            2
        );
        $rows = [
            ['Opening Written Down Value (WDV)', \eel_accounts\Renderer\TaxCardRenderer::money($context, $openingWdv)],
            ['Qualifying additions', \eel_accounts\Renderer\TaxCardRenderer::money($context, $qualifyingAdditions)],
            ['Less: Annual Investment Allowance (AIA) claimed', \eel_accounts\Renderer\TaxCardRenderer::money($context, $aiaClaimed)],
        ];
        if ($fyaClaimed !== 0.0) {
            $rows[] = ['Less: First Year Allowance (FYA) claimed', \eel_accounts\Renderer\TaxCardRenderer::money($context, $fyaClaimed)];
        }
        $rows[] = ['Less: disposal values', \eel_accounts\Renderer\TaxCardRenderer::money($context, $disposalValue)];
        $rows[] = ['Available qualifying expenditure', \eel_accounts\Renderer\TaxCardRenderer::money($context, $availableQualifyingExpenditure)];
        $rows[] = ['Writing Down Allowance (WDA)', \eel_accounts\Renderer\TaxCardRenderer::money($context, $pool['wda_claimed'] ?? 0)];
        if ((float)($pool['balancing_allowance'] ?? 0) !== 0.0) {
            $rows[] = ['Balancing allowance', \eel_accounts\Renderer\TaxCardRenderer::money($context, $pool['balancing_allowance'])];
        }
        if ((float)($pool['balancing_charge'] ?? 0) !== 0.0) {
            $rows[] = ['Balancing charge', \eel_accounts\Renderer\TaxCardRenderer::money($context, $pool['balancing_charge'])];
        }
        $rows[] = ['Closing Written Down Value (WDV)', \eel_accounts\Renderer\TaxCardRenderer::money($context, $pool['closing_wdv'] ?? 0)];

        return \eel_accounts\Renderer\TaxCardRenderer::header('wda')
            . \eel_accounts\Renderer\TaxCardRenderer::summaryGrid($rows);
    }
}
