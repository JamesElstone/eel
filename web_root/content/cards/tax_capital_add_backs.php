<?php
declare(strict_types=1);

final class _tax_capital_add_backsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_capital_add_backs'; }
    public function title(): string { return 'Capital Expenditure Add-Backs'; }
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
        foreach ((array)($workings['capital_add_backs'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape(trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? ''))),
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['source_label'] ?? 'Posted ledger')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['amount'] ?? 0)),
            ];
        }

        return \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns')
            . \eel_accounts\Renderer\TaxCardRenderer::table(
                ['Nominal', 'Source', 'Signed add-back'],
                $rows,
                'No capital expenditure add-backs were found for this period.'
            );
    }
}
