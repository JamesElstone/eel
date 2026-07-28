<?php
declare(strict_types=1);

final class _tax_depreciation_add_backCard extends CardBaseFramework
{
    public function key(): string { return 'tax_depreciation_add_back'; }
    public function title(): string { return 'Depreciation Add-Back'; }
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

        return \eel_accounts\Renderer\TaxCardRenderer::header('capital_allowances')
            . $this->table($this->tableRows($context))->render($context, [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]);
    }

    /** @return list<array<string, float|string>> */
    private function tableRows(array $context): array
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        $rows = [];
        foreach ((array)($workings['depreciation_add_back'] ?? []) as $row) {
            $asset = trim((string)($row['asset_code'] ?? '') . ' ' . (string)($row['description'] ?? ''));
            $rows[] = [
                'asset' => $asset !== '' ? $asset : 'Period adjustment',
                'direction' => (string)($row['direction'] ?? 'add'),
                'amount' => (float)($row['amount'] ?? 0),
                'amount_html' => \eel_accounts\Renderer\TaxCardRenderer::money($context, $row['amount'] ?? 0),
            ];
        }

        return $rows;
    }

    /** @param list<array<string, float|string>> $rows */
    private function table(array $rows): TableFramework
    {
        return TableFramework::make($this->key(), $rows)
            ->filename('tax-depreciation-add-back')
            ->exportLimit(5000)
            ->empty('No depreciation add-back rows were found for this period.')
            ->textColumn('asset', 'Asset', 'Period adjustment')
            ->textColumn('direction', 'Direction', 'add')
            ->column(
                'amount',
                'Amount',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['amount_html'] ?? '')),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                headerClass: 'numeric',
                cellClass: 'numeric',
                exportType: 'number'
            );
    }
}
