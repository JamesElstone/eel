<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_income_breakdownCard extends CardBaseFramework
{
    public function key(): string { return 'pl_income_breakdown'; }

    public function title(): string { return 'Income Breakdown'; }

    protected function additionalInvalidationFacts(): array { return ['page.context']; }

    public function render(array $context): string
    {
        return $this->rowsTable((array)($context['profit_loss']['breakdown']['income'] ?? []), 'No income journals have been posted for this period.');
    }

    private function rowsTable(array $rows, string $empty): string
    {
        if ($rows === []) {
            return '<div class="helper">' . HelperFramework::escape($empty) . '</div>';
        }
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . HelperFramework::escape((string)($row['code'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['name'] ?? '')) . '</td><td>' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)) . '</td></tr>';
        }
        return '<div class="table-scroll"><table><thead><tr><th>Code</th><th>Nominal</th><th>Amount</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }
}
