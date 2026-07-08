<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_expense_breakdownCard extends CardBaseFramework
{
    public function key(): string { return 'pl_expense_breakdown'; }

    public function title(): string { return 'Expense Breakdown'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        $breakdown = (array)($context['profit_loss']['breakdown'] ?? []);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return '<div class="settings-stack">
            ' . $this->group('Cost of sales', (array)($breakdown['cost_of_sales'] ?? []), 'No cost of sales journals have been posted for this period.', $companySettings) . '
            ' . $this->group('Expenses', (array)($breakdown['expense'] ?? []), 'No expense journals have been posted for this period.', $companySettings) . '
            ' . $this->group('Tax Charge', (array)($breakdown['tax_charge'] ?? []), 'No Corporation Tax charge has been posted for this period.', $companySettings) . '
        </div>';
    }

    private function group(string $title, array $rows, string $empty, array $companySettings): string
    {
        if ($rows === []) {
            return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="helper">' . HelperFramework::escape($empty) . '</div></section>';
        }
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . HelperFramework::escape((string)($row['code'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['name'] ?? '')) . '</td><td>' . HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)) . '</td></tr>';
        }
        return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="table-scroll"><table><thead><tr><th>Code</th><th>Nominal</th><th>Amount</th></tr></thead><tbody>' . $html . '</tbody></table></div></section>';
    }

    private function money(array $companySettings, mixed $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
    }
}
