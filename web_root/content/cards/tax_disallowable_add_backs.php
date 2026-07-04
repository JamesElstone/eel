<?php
declare(strict_types=1);

final class _tax_disallowable_add_backsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_disallowable_add_backs'; }
    public function title(): string { return 'Disallowable Expenses / Add-Backs'; }
    public function services(): array { return [\eel_accounts\Ui\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Ui\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) {
            return \eel_accounts\Ui\TaxCardRenderer::emptyState($workings);
        }
        $rows = [];
        foreach ((array)($workings['disallowable_add_backs'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Ui\TaxCardRenderer::escape(trim((string)($row['nominal_code'] ?? '') . ' ' . (string)($row['nominal_name'] ?? ''))),
                \eel_accounts\Ui\TaxCardRenderer::escape(HelperFramework::labelFromKey((string)($row['tax_treatment'] ?? 'unknown'), '_')),
                \eel_accounts\Ui\TaxCardRenderer::escape(\eel_accounts\Ui\TaxCardRenderer::money($context, $row['amount'] ?? 0)),
            ];
        }
        return \eel_accounts\Ui\TaxCardRenderer::header('company_tax_returns')
            . \eel_accounts\Ui\TaxCardRenderer::table(['Nominal', 'Tax treatment', 'Add-back'], $rows, 'No disallowable expense add-backs were found for this period.');
    }
}
