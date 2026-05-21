<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividend_historyCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'dividend_history';
    }

    public function title(): string
    {
        return 'Dividend History';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function render(array $context): string
    {
        $rows = (array)($context['dividends']['history'] ?? []);
        if ($rows === []) {
            return '<div class="helper">No dividend journals exist yet for the selected company and accounting period.</div>';
        }

        $rowsHtml = '';
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? 'posted');
            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($row['journal_date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($row['description'] ?? '')) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape((string)($row['settlement_account'] ?? '')) . '</td>
                <td><div class="helper">' . HelperFramework::escape((string)($row['source_ref'] ?? '')) . '</div></td>
                <td><span class="badge ' . ($status === 'posted' ? 'success' : 'warning') . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span></td>
            </tr>';
        }

        return '<div class="table-scroll">
            <table>
                <thead><tr><th>Date</th><th>Description</th><th>Amount</th><th>Settlement account</th><th>Source reference</th><th>Status</th></tr></thead>
                <tbody>' . $rowsHtml . '</tbody>
            </table>
        </div>';
    }
}
