<?php
declare(strict_types=1);

final class _tax_corporation_tax_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'tax_corporation_tax_summary'; }
    public function title(): string { return 'Corporation Tax Summary'; }
    public function helper(array $context): string { return \eel_accounts\Ui\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $summary = (array)$workings['summary'];
        $provision = (array)($workings['provision'] ?? []);
        return \eel_accounts\Ui\TaxCardRenderer::header('corporation_tax')
            . '<div class="status-head">'
            . \eel_accounts\Ui\TaxCardRenderer::badge((string)($summary['calculation_status'] ?? 'estimate'), HelperFramework::labelFromKey((string)($summary['calculation_status'] ?? 'estimate'), '_'))
            . \eel_accounts\Ui\TaxCardRenderer::badge((string)($summary['confidence_status'] ?? 'review_required'), (string)($summary['confidence_label'] ?? 'Review required'))
            . '</div>'
            . \eel_accounts\Ui\TaxCardRenderer::summaryGrid([
                ['Taxable profit', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['taxable_profit'] ?? 0)],
                ['Taxable loss', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['taxable_loss'] ?? 0)],
                ['Estimated Corporation Tax (CT)', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['estimated_corporation_tax'] ?? 0)],
                ['Effective rate', \eel_accounts\Ui\TaxCardRenderer::percent($summary['estimated_rate'] ?? 0)],
                ['Posted CT provision', \eel_accounts\Ui\TaxCardRenderer::money($context, $provision['posted_corporation_tax_charge'] ?? 0)],
                ['Unposted P&L impact', \eel_accounts\Ui\TaxCardRenderer::money($context, $provision['unposted_corporation_tax_adjustment'] ?? 0)],
            ])
            . $this->provisionAction($context, $provision);
    }

    private function provisionAction(array $context, array $provision): string
    {
        if (empty($provision['available'])) {
            return '';
        }
        $company = (array)($context['company'] ?? []);
        $tax = (array)($context['tax'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $ctPeriodId = (int)($tax['selected_ct_period_id'] ?? 0);
        $estimate = round((float)($provision['estimated_corporation_tax'] ?? 0), 2);
        $unposted = round((float)($provision['unposted_corporation_tax_adjustment'] ?? 0), 2);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0 || $estimate <= 0 || abs($unposted) < 0.005) {
            return '';
        }

        return '<form method="post" action="?page=tax&amp;ct_period_id=' . $ctPeriodId . '" data-ajax="true" class="actions-row">
            <input type="hidden" name="card_action" value="Tax">
            <input type="hidden" name="intent" value="post_ct_provision">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="ct_period_id" value="' . $ctPeriodId . '">
            <button class="button primary" type="submit">Post CT provision</button>
        </form>';
    }
}
