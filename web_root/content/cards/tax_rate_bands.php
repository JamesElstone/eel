<?php
declare(strict_types=1);

final class _tax_rate_bandsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_rate_bands'; }
    public function title(): string { return 'Tax Rate Bands / Marginal Relief'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $summary = (array)($workings['summary'] ?? []);
        $rows = [];
        foreach ((array)($workings['rate_bands'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['financial_year'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['taxable_profit'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::percent($row['main_rate'] ?? null)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::percent($row['small_profits_rate'] ?? null)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['marginal_relief'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['liability'] ?? 0)),
                \eel_accounts\Renderer\TaxCardRenderer::escape(HelperFramework::labelFromKey((string)($row['basis'] ?? ''), '_')),
            ];
        }
        $prefix = \eel_accounts\Renderer\TaxCardRenderer::summaryGrid([
            ['Associated companies', (string)(int)($summary['associated_company_count'] ?? 0)],
        ]);
        return \eel_accounts\Renderer\TaxCardRenderer::header('marginal_relief')
            . $prefix
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Financial Year (FY)', 'Taxable profit', 'Main rate', 'Small profits', 'Marginal relief', 'Liability', 'Basis'], $rows, 'No rate bands apply because taxable profit is nil.');
    }
}
