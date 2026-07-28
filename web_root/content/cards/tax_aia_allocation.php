<?php
declare(strict_types=1);

final class _tax_aia_allocationCard extends CardBaseFramework
{
    public function key(): string { return 'tax_aia_allocation'; }
    public function title(): string { return 'Annual Investment Allowance (AIA) Allocation'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function tables(array $context): array
    {
        return [$this->table($this->tableRows($context))];
    }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }

        return \eel_accounts\Renderer\TaxCardRenderer::header('aia')
            . $this->table($this->tableRows($context))->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]);
    }

    /** @return list<array<string, float|string>> */
    private function tableRows(array $context): array
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        $rows = [];
        $used = 0.0;
        foreach ((array)($workings['aia_allocation'] ?? []) as $row) {
            $used += (float)($row['allowance_amount'] ?? 0);
            $rows[] = [
                'purchase_date' => (string)($row['purchase_date'] ?? ''),
                'asset' => trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? '')),
                'addition' => (float)($row['addition_amount'] ?? 0),
                'addition_html' => \eel_accounts\Renderer\TaxCardRenderer::money($context, $row['addition_amount'] ?? 0),
                'allowance' => (float)($row['allowance_amount'] ?? 0),
                'allowance_html' => \eel_accounts\Renderer\TaxCardRenderer::money($context, $row['allowance_amount'] ?? 0),
                'used' => $used,
                'used_html' => \eel_accounts\Renderer\TaxCardRenderer::money($context, $used),
            ];
        }

        return $rows;
    }

    /** @param list<array<string, float|string>> $rows */
    private function table(array $rows): TableFramework
    {
        return TableFramework::make($this->key(), $rows)
            ->filename('tax-aia-allocation')
            ->exportLimit(5000)
            ->empty('No Annual Investment Allowance (AIA) allocation rows were found for this period.')
            ->textColumn('purchase_date', 'Purchase date', exportType: 'date')
            ->textColumn('asset', 'Asset')
            ->column('addition', 'Addition', html: static fn(array $row): string => HelperFramework::escape((string)($row['addition_html'] ?? '')), export: static fn(array $row): string => number_format((float)($row['addition'] ?? 0), 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('allowance', 'Annual Investment Allowance (AIA) claimed', html: static fn(array $row): string => HelperFramework::escape((string)($row['allowance_html'] ?? '')), export: static fn(array $row): string => number_format((float)($row['allowance'] ?? 0), 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number')
            ->column('used', 'Cumulative Annual Investment Allowance (AIA) used', html: static fn(array $row): string => HelperFramework::escape((string)($row['used_html'] ?? '')), export: static fn(array $row): string => number_format((float)($row['used'] ?? 0), 2, '.', ''), headerClass: 'numeric', cellClass: 'numeric', exportType: 'number');
    }
}
