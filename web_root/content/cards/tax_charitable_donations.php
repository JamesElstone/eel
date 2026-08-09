<?php
declare(strict_types=1);

final class _tax_charitable_donationsCard extends CardBaseFramework
{
    public function key(): string { return 'tax_charitable_donations'; }
    public function title(): string { return 'Qualifying Charitable Donations'; }
    public function helper(array $context): string { return \eel_accounts\Renderer\TaxCardRenderer::selectedPeriodHelper($context); }
    public function services(): array { return [\eel_accounts\Renderer\TaxCardRenderer::serviceDefinition()]; }
    public function handleError(string $serviceKey, array $error, array $context): string { return ''; }

    public function render(array $context): string
    {
        $workings = \eel_accounts\Renderer\TaxCardRenderer::workings($context);
        if (empty($workings['available'])) return \eel_accounts\Renderer\TaxCardRenderer::emptyState($workings);
        $rows = [];
        foreach ((array)($workings['charitable_donations'] ?? []) as $row) {
            $rows[] = [
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['txn_date'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape((string)($row['registered_name'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(strtoupper((string)($row['authority'] ?? '')) . ' ' . (string)($row['registration_number'] ?? '')),
                \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $row['amount'] ?? 0)),
            ];
        }
        $summary = (array)($workings['summary'] ?? []);
        $totals = '<div class="summary-grid">'
            . '<div class="summary-card"><div class="summary-label">Paid</div><div class="summary-value">' . \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['qualifying_charitable_donations_paid'] ?? 0)) . '</div></div>'
            . '<div class="summary-card"><div class="summary-label">Claimed [box 305]</div><div class="summary-value">' . \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['qualifying_charitable_donations_claimed'] ?? 0)) . '</div></div>'
            . '<div class="summary-card"><div class="summary-label">Unrelieved</div><div class="summary-value">' . \eel_accounts\Renderer\TaxCardRenderer::escape(\eel_accounts\Renderer\TaxCardRenderer::money($context, $summary['unrelieved_qualifying_charitable_donations'] ?? 0)) . '</div></div>'
            . '</div>';
        return \eel_accounts\Renderer\TaxCardRenderer::header('company_tax_returns') . $totals
            . \eel_accounts\Renderer\TaxCardRenderer::table(['Payment date', 'Registered charity', 'Register number', 'Amount'], $rows, 'No verified qualifying charitable donations were paid in this CT period.');
    }
}
