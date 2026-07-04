<?php
declare(strict_types=1);

final class _tax_corporation_tax_summaryCard extends CardBaseFramework
{
    public function key(): string { return 'tax_corporation_tax_summary'; }
    public function title(): string { return 'Corporation Tax Summary'; }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $summary = (array)$workings['summary'];
        return \eel_accounts\Ui\TaxCardRenderer::header('corporation_tax')
            . '<div class="status-head">'
            . \eel_accounts\Ui\TaxCardRenderer::badge((string)($summary['calculation_status'] ?? 'estimate'), HelperFramework::labelFromKey((string)($summary['calculation_status'] ?? 'estimate'), '_'))
            . \eel_accounts\Ui\TaxCardRenderer::badge((string)($summary['confidence_status'] ?? 'review_required'), (string)($summary['confidence_label'] ?? 'Review required'))
            . '</div>'
            . \eel_accounts\Ui\TaxCardRenderer::summaryGrid([
                ['Taxable profit', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['taxable_profit'] ?? 0)],
                ['Taxable loss', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['taxable_loss'] ?? 0)],
                ['Estimated CT', \eel_accounts\Ui\TaxCardRenderer::money($context, $summary['estimated_corporation_tax'] ?? 0)],
                ['Effective rate', \eel_accounts\Ui\TaxCardRenderer::percent($summary['estimated_rate'] ?? 0)],
            ]);
    }
}
