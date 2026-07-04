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
            ])
            . '<h3>Calculation</h3>'
            . \eel_accounts\Ui\TaxCardRenderer::table(
                ['Line', 'Source', 'Amount', 'Running total'],
                $this->calculationRows($context, $summary, (array)($workings['aia_allocation'] ?? [])),
                'No capital allowance calculation rows were found for this period.'
            );
    }

    private function calculationRows(array $context, array $summary, array $aiaAllocation): array
    {
        $rows = [];
        $runningTotal = 0.0;

        foreach ($aiaAllocation as $row) {
            $amount = round((float)($row['allowance_amount'] ?? 0), 2);
            if ($amount == 0.0) {
                continue;
            }
            $runningTotal = round($runningTotal + $amount, 2);
            $asset = trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''));
            $date = trim((string)($row['purchase_date'] ?? ''));
            $source = trim($asset . ($date !== '' ? ' bought ' . $date : ''));
            $addition = \eel_accounts\Ui\TaxCardRenderer::money($context, $row['addition_amount'] ?? 0);

            $rows[] = [
                \eel_accounts\Ui\TaxCardRenderer::escape('AIA claimed'),
                \eel_accounts\Ui\TaxCardRenderer::escape($source !== '' ? $source . ' from addition ' . $addition : 'AIA allocation row'),
                \eel_accounts\Ui\TaxCardRenderer::escape(\eel_accounts\Ui\TaxCardRenderer::money($context, $amount)),
                \eel_accounts\Ui\TaxCardRenderer::escape(\eel_accounts\Ui\TaxCardRenderer::money($context, $runningTotal)),
            ];
        }

        if ($rows === []) {
            $this->appendCalculationRow($rows, $context, $runningTotal, 'AIA claimed', 'capital_allowances_summary.aia_claimed', (float)($summary['aia_claimed'] ?? 0));
        }

        $this->appendCalculationRow($rows, $context, $runningTotal, 'FYA claimed', 'capital_allowances_summary.fya_claimed', (float)($summary['fya_claimed'] ?? 0));
        $this->appendCalculationRow($rows, $context, $runningTotal, 'WDA claimed', 'capital_allowances_summary.wda_claimed', (float)($summary['wda_claimed'] ?? 0));
        $this->appendCalculationRow($rows, $context, $runningTotal, 'Balancing allowances', 'capital_allowances_summary.balancing_allowance', (float)($summary['balancing_allowance'] ?? 0));
        $this->appendCalculationRow($rows, $context, $runningTotal, 'Less balancing charges', 'capital_allowances_summary.balancing_charge', -1 * (float)($summary['balancing_charge'] ?? 0));

        $expected = round((float)($summary['net_capital_allowances'] ?? $runningTotal), 2);
        if (round($runningTotal, 2) != $expected) {
            $this->appendCalculationRow($rows, $context, $runningTotal, 'Rounding/check adjustment', 'capital_allowances_summary.net_capital_allowances', $expected - $runningTotal);
        }

        return $rows;
    }

    private function appendCalculationRow(array &$rows, array $context, float &$runningTotal, string $line, string $source, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            return;
        }
        $runningTotal = round($runningTotal + $amount, 2);

        $rows[] = [
            \eel_accounts\Ui\TaxCardRenderer::escape($line),
            \eel_accounts\Ui\TaxCardRenderer::escape($source),
            \eel_accounts\Ui\TaxCardRenderer::escape(\eel_accounts\Ui\TaxCardRenderer::money($context, $amount)),
            \eel_accounts\Ui\TaxCardRenderer::escape(\eel_accounts\Ui\TaxCardRenderer::money($context, $runningTotal)),
        ];
    }
}
