<?php
declare(strict_types=1);

final class _tax_disallowable_add_backsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_disallowable_add_backs'; }
    public function title(): string { return 'Disallowable Expenses / Add-Backs'; }
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
        foreach ((array)($workings['disallowable_add_backs'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['journal_date'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? ''))),
                \eel_accounts\Renderer\TaxCardRenderer::escape(trim(
                    (string)($row['source_label'] ?? 'Posted ledger')
                    . ((int)($row['journal_id'] ?? 0) > 0 ? ' / Journal #' . (int)$row['journal_id'] : '')
                    . (trim((string)($row['line_description'] ?? $row['journal_description'] ?? '')) !== ''
                        ? ' — ' . trim((string)($row['line_description'] ?? $row['journal_description'] ?? '')) : '')
                )),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['amount'] ?? 0)),
            ];
        }
        return \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns')
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Date', 'Nominal', 'Source evidence', 'Signed add-back'], $rows, 'No disallowable expense add-backs were found for this period.');
    }
}
