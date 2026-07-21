<?php
declare(strict_types=1);

final class _tax_corporation_tax_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'tax_corporation_tax_summary'; }
    public function title(): string { return 'Corporation Tax Summary'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        }
        $summary = (array)$workings['summary'];
        $provision = (array)($workings['provision'] ?? []);
        $rows = [
            ['Taxable profit', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['taxable_profit'] ?? 0)],
            ['Taxable loss', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['taxable_loss'] ?? 0)],
            ['Ordinary Corporation Tax [CT600 box 475]', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['ordinary_corporation_tax'] ?? 0)],
            ['Net S455 tax (included within A80)', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['s455_tax'] ?? 0)],
            ['CT600A net tax payable [A80 / CT600 box 480]', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['ct600a_tax'] ?? 0)],
            ['Total tax payable [CT600 boxes 510 / 525]', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['tax_payable'] ?? $summary['estimated_corporation_tax'] ?? 0)],
            ['Effective ordinary CT rate', \eel_accounts\Renderer\TaxCardRenderer::percent($summary['estimated_rate'] ?? 0)],
            ['Posted CT provision', \eel_accounts\Renderer\TaxCardRenderer::money($context, $provision['posted_corporation_tax_charge'] ?? 0)],
            ['Unposted P&L impact', \eel_accounts\Renderer\TaxCardRenderer::money($context, $provision['unposted_corporation_tax_adjustment'] ?? 0)],
        ];
        if (($summary['payment_outstanding'] ?? null) !== null) {
            $rows[] = ['HMRC payments recorded for accounting period', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['amount_paid'] ?? 0)];
            $rows[] = ['HMRC payment outstanding for accounting period', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['payment_outstanding'])];
        }
        if ((float)($summary['accounting_period_l2p_relief_receivable'] ?? 0) >= 0.005) {
            $rows[] = ['Separate L2P relief receivable for accounting period', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['accounting_period_l2p_relief_receivable'])];
            $rows[] = ['Net tax charge in the accounts for accounting period', \eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['accounting_period_estimated_tax_charge'] ?? 0)];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('corporation_tax')
            . \eel_accounts\Renderer\TaxCardRenderer::summaryGrid($rows)
            . '<div class="helper">Net S455 tax is supporting evidence within CT600A A80. It is not added to the total a second time.</div>';
    }
}
