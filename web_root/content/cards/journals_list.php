<?php
declare(strict_types=1);

final class _journals_listCard implements CardInterfaceFramework
{
    public function key(): string
    {
        return 'journals_list';
    }

    public function services(): array
    {
        return [];
    }

    public function invalidationFacts(): array
    {
        return ['page.context'];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $page = (array)($context['page'] ?? []);
        $journalEntries = (array)($page['journal_entries'] ?? []);
        $selectedCompanyId = (int)($page['selected_company_id'] ?? $page['company_id'] ?? 0);
        $selectedTaxYearId = (int)($page['selected_tax_year_id'] ?? $page['tax_year_id'] ?? 0);

        if ($journalEntries === []) {
            return '<div class="card">
                <div class="card-header">
                    <h2 class="card-title">Journals</h2>
                </div>
                <div class="card-body">
                    <div class="helper" style="margin-bottom: 14px;">`bank_csv` journals are derived from source transactions and cannot be edited directly here. Review the source transaction instead if a posting needs to change.</div>
                    <div class="helper">No journals exist yet for the selected company and accounting period.</div>
                </div>
            </div>';
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
                    $selectedCompanyId,
                    $selectedTaxYearId,
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

        return '<div class="card">
            <div class="card-header">
                <h2 class="card-title">Journals</h2>
            </div>
            <div class="card-body">
                <div class="helper" style="margin-bottom: 14px;">`bank_csv` journals are derived from source transactions and cannot be edited directly here. Review the source transaction instead if a posting needs to change.</div>
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
            </div>
        </div>';
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

    private function buildTransactionsUrl(int $companyId, int $taxYearId, string $monthKey): string
    {
        return '?' . http_build_query([
            'page' => 'transactions',
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'month_key' => $monthKey,
            'category_filter' => 'all',
        ]);
    }

}
