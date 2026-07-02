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
        $breakdown = (array)($context['profit_loss']['breakdown'] ?? []);
        $incomeRows = (array)($breakdown['income'] ?? []);
        $positiveNonIncomeReceipts = (array)($breakdown['positive_non_income_receipts'] ?? []);
        $salesRows = [];
        $otherIncomeRows = [];

        foreach ($incomeRows as $row) {
            if ($this->isSalesIncomeRow((array)$row)) {
                $salesRows[] = (array)$row;
            } else {
                $otherIncomeRows[] = (array)$row;
            }
        }

        return '<div class="settings-stack">
            ' . $this->group('Sales', $salesRows, 'No sales income journals have been posted for this period.') . '
            ' . $this->group('Other income sources', $otherIncomeRows, 'No other income journals have been posted for this period.', $this->nonIncomeReceiptNote($positiveNonIncomeReceipts)) . '
        </div>';
    }

    private function group(string $title, array $rows, string $empty, string $emptyNote = ''): string
    {
        if ($rows === []) {
            return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="helper">' . HelperFramework::escape($empty) . '</div>' . $emptyNote . '</section>';
        }
        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr><td>' . HelperFramework::escape((string)($row['code'] ?? '')) . '</td><td>' . HelperFramework::escape((string)($row['name'] ?? '')) . '</td><td>' . HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)) . '</td></tr>';
        }
        return '<section class="panel-soft"><h3 class="card-title">' . HelperFramework::escape($title) . '</h3><div class="table-scroll"><table><thead><tr><th>Code</th><th>Nominal</th><th>Amount</th></tr></thead><tbody>' . $html . '</tbody></table></div></section>';
    }

    private function isSalesIncomeRow(array $row): bool
    {
        return (string)($row['account_subtype_code'] ?? '') === 'turnover';
    }

    private function nonIncomeReceiptNote(array $rows): string
    {
        $rows = array_values(array_filter($rows, static fn(mixed $row): bool => is_array($row) && (float)($row['amount'] ?? 0) > 0));
        if ($rows === []) {
            return '';
        }

        $labels = [];
        foreach ($rows as $row) {
            $labels[] = $this->nominalLabel($row) . ' (' . FormattingFramework::money($row['amount'] ?? 0) . ')';
        }

        return '<div class="helper">Positive bank receipts posted to nominal(s) '
            . HelperFramework::escape(implode(', ', $labels))
            . ' are excluded from income because those nominal accounts do not affect P&amp;L income. Director loans, capital introduced, internal transfers, and other balance-sheet movements are not income.</div>';
    }

    private function nominalLabel(array $row): string
    {
        $code = trim((string)($row['code'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        if ($code !== '' && $name !== '') {
            return $code . ' - ' . $name;
        }

        return $code !== '' ? $code : $name;
    }
}
