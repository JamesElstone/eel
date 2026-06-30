<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journals_listCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'journals_list';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'journal_entries',
                'service' => \eel_accounts\Service\TransactionJournalService::class,
                'method' => 'fetchJournals',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                ],
            ],
        ];
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $journalEntries = (array)($context['services']['journal_entries'] ?? []);
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);

        if ($journalEntries === []) {
            return '
                <div class="helper">Posted transaction journals will appear here once transactions have been categorised and posted.</div>
                <div class="helper">To change a transaction-derived journal, review the source transaction and repost it.</div>
            ';
        }

        $rowsHtml = '';
        foreach ($journalEntries as $journal) {
            $sourceTransactionId = $this->journalSourceTransactionId((array)$journal);
            $linesHtml = '';
            foreach ((array)($journal['lines'] ?? []) as $line) {
                $nominalLabel = trim((string)($line['nominal_code'] ?? '')) !== ''
                    ? (string)$line['nominal_code'] . ' - ' . (string)($line['nominal_name'] ?? '')
                    : (string)($line['nominal_name'] ?? '');
                $linesHtml .= '<span class="helper">' . HelperFramework::escape($nominalLabel) . ': Dr '
                    . HelperFramework::escape(FormattingFramework::money((float)($line['debit'] ?? 0))) . ' / Cr '
                    . HelperFramework::escape(FormattingFramework::money((float)($line['credit'] ?? 0))) . '</span>';
            }

            $actionHtml = (string)($journal['source_type'] ?? '') === 'bank_csv' && $sourceTransactionId > 0
                ? '<a class="button" href="' . HelperFramework::escape($this->buildTransactionsUrl(
                    $companyId,
                    $accountingPeriodId,
                    $this->monthKeyFromDate((string)($journal['journal_date'] ?? ''))
                )) . '#transaction-' . $sourceTransactionId . '">Review Transaction</a>'
                : '<span class="helper">Manual and non-bank journals stay separate from source-derived posting.</span>';

            $rowsHtml .= '<tr>
                <td>' . HelperFramework::escape((string)($journal['journal_date'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($journal['description'] ?? '')) . '</td>
                <td>
                    <span class="badge ' . HelperFramework::escape((string)($journal['source_type'] ?? '') === 'bank_csv' ? 'info' : 'success') . '">' . HelperFramework::escape((string)($journal['source_type'] ?? '')) . '</span>'
                    . ($sourceTransactionId > 0
                        ? '<div class="helper">Transaction #' . $sourceTransactionId . '</div>'
                        : (trim((string)($journal['source_ref'] ?? '')) !== ''
                            ? '<div class="helper">' . HelperFramework::escape((string)$journal['source_ref']) . '</div>'
                            : '')) . '
                </td>
                <td><div class="document-stack">' . $linesHtml . '</div></td>
                <td>' . HelperFramework::escape(FormattingFramework::money((float)($journal['total_debit'] ?? 0))) . '</td>
                <td>' . $actionHtml . '</td>
            </tr>';
        }

        return '
            <div class="helper">Transaction-derived journals are read-only here. Use Review Transaction to change the source posting.</div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Source</th>
                            <th>Lines</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>' . $rowsHtml . '</tbody>
                </table>
            </div>
        ';
    }

    private function journalSourceTransactionId(array $journal): int
    {
        $sourceType = trim((string)($journal['source_type'] ?? ''));
        if ($sourceType !== 'bank_csv') {
            return 0;
        }

        $sourceRef = trim((string)($journal['source_ref'] ?? ''));
        if (preg_match('/transaction:(\d+)/', $sourceRef, $matches) === 1) {
            return (int)$matches[1];
        }

        return 0;
    }

    private function monthKeyFromDate(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return substr($value, 0, 7) . '-01';
    }

    private function buildTransactionsUrl(int $companyId, int $accountingPeriodId, string $monthKey): string
    {
        return '?' . http_build_query([
            'page' => 'transactions',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'month_key' => $monthKey,
            'category_filter' => 'all',
        ]);
    }

}
