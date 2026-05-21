<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _pl_uncategorised_watchCard extends CardBaseFramework
{
    public function key(): string { return 'pl_uncategorised_watch'; }

    public function title(): string { return 'Uncategorised Watch'; }

    protected function additionalInvalidationFacts(): array { return ['transactions.imported', 'page.context']; }

    public function render(array $context): string
    {
        $rows = (array)($context['profit_loss']['uncategorised_watch'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $taxYearId = (int)($context['company']['tax_year_id'] ?? 0);
        $link = '?' . http_build_query(['page' => 'transactions', 'company_id' => $companyId, 'tax_year_id' => $taxYearId, 'category_filter' => 'uncategorised']);

        if ($rows === []) {
            return '<div class="helper">No uncategorised transactions found for this period.</div><div class="actions-row"><a class="button" href="' . HelperFramework::escape($link) . '">Review Transactions</a></div>';
        }

        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>
                <td>' . HelperFramework::escape((string)($row['txn_date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '<div class="helper">' . HelperFramework::escape((string)($row['counterparty_name'] ?? $row['source_account_label'] ?? '')) . '</div></td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)) . '</td>
                <td><span class="badge warning">' . HelperFramework::escape((string)($row['category_status'] ?? 'uncategorised')) . '</span></td>
            </tr>';
        }

        return '<div class="actions-row"><a class="button" href="' . HelperFramework::escape($link) . '">Review Transactions</a></div>
            <div class="table-scroll"><table><thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Status</th></tr></thead><tbody>' . $html . '</tbody></table></div>';
    }
}
