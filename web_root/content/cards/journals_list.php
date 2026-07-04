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
    private const PAGE_SIZE = 30;

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

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);

        return $this->applyTableSortContext($request, $pageContext, $this->key());
    }

    public function render(array $context): string
    {
        return $this->configuredTable($context)->render(
            $context,
            [
                'cards[]' => (array)($context['page']['page_cards'] ?? []),
            ]
        );
    }

    public function tables(array $context): array
    {
        return [
            $this->table($context),
        ];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = $this->tableHiddenFields($context);
        $journals = $this->journalRows($context);
        $pagination = HelperFramework::paginateArray($journals, $this->paginationPage($context), self::PAGE_SIZE);

        return $this->table($context)
            ->visibleRows($this->journalLineRows((array)$pagination['items']))
            ->pagination(
                $pagination,
                'Journals',
                $this->paginationPageField(),
                $hiddenFields
            );
    }

    private function table(array $context): TableFramework
    {
        $companyId = (int)($context['company']['id'] ?? 0);
        $accountingPeriodId = (int)($context['company']['accounting_period_id'] ?? 0);
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);
        $settingsService = new \eel_accounts\Service\CompanySettingsService();

        return TableFramework::make($this->key(), $this->journalLineRows($this->journalRows($context)))
            ->filename('journals-list')
            ->exportLimit(1000)
            ->empty('Posted transaction journals will appear here once transactions have been categorised and posted.')
            ->column(
                'journal_date',
                'Date',
                html: fn(array $row): string => $this->journalCell($row, (string)($row['journal_date'] ?? '')),
                export: fn(array $row): string => $this->journalExportValue($row, (string)($row['journal_date'] ?? '')),
                exportType: 'date'
            )
            ->column(
                'description',
                'Description',
                html: fn(array $row): string => $this->journalCell($row, (string)($row['description'] ?? '')),
                export: fn(array $row): string => $this->journalExportValue($row, (string)($row['description'] ?? ''))
            )
            ->column(
                'source_type',
                'Source',
                html: fn(array $row): string => $this->sourceCellHtml($row, $companyId, $accountingPeriodId),
                export: fn(array $row): string => $this->journalExportValue($row, $this->sourceExport($row)),
                cellClass: 'journal-source-cell'
            )
            ->column(
                'is_posted',
                'Status',
                html: fn(array $row): string => $this->statusCellHtml($row),
                export: fn(array $row): string => $this->journalExportValue($row, $this->statusLabel($row))
            )
            ->column(
                'total_debit',
                'Total',
                html: fn(array $row): string => $this->journalCell($row, $settingsService->money($companySettings, $row['total_debit'] ?? 0)),
                export: fn(array $row): string => $this->journalExportValue($row, number_format((float)($row['total_debit'] ?? 0), 2, '.', '')),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->textColumn('line_nominal_code', 'Code')
            ->textColumn('line_nominal_label', 'Label')
            ->column(
                'credit',
                'CR',
                html: static fn(array $row): string => (float)($row['credit'] ?? 0) > 0
                    ? HelperFramework::escape($settingsService->money($companySettings, $row['credit'] ?? 0))
                    : '',
                export: static fn(array $row): string => (float)($row['credit'] ?? 0) > 0
                    ? number_format((float)($row['credit'] ?? 0), 2, '.', '')
                    : '',
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'debit',
                'DR',
                html: static fn(array $row): string => (float)($row['debit'] ?? 0) > 0
                    ? HelperFramework::escape($settingsService->money($companySettings, $row['debit'] ?? 0))
                    : '',
                export: static fn(array $row): string => (float)($row['debit'] ?? 0) > 0
                    ? number_format((float)($row['debit'] ?? 0), 2, '.', '')
                    : '',
                cellClass: 'numeric',
                exportType: 'number'
            );
    }

    private function tableHiddenFields(array $context): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? ''),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
        ];
    }

    private function journalRows(array $context): array
    {
        return array_values(array_filter(
            (array)($context['services']['journal_entries'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function journalLineRows(array $journals): array
    {
        $rows = [];
        foreach ($journals as $journal) {
            if (!is_array($journal)) {
                continue;
            }

            $lines = array_values(array_filter(
                (array)($journal['lines'] ?? []),
                static fn(mixed $line): bool => is_array($line)
            ));
            if ($lines === []) {
                $lines = [[]];
            }

            foreach ($lines as $index => $line) {
                $rows[] = array_merge($journal, [
                    'journal_row_start' => $index === 0,
                    'line_nominal_code' => trim((string)($line['nominal_code'] ?? '')),
                    'line_nominal_label' => trim((string)($line['nominal_name'] ?? '')),
                    'line_company_account_name' => trim((string)($line['company_account_name'] ?? '')),
                    'debit' => (float)($line['debit'] ?? 0),
                    'credit' => (float)($line['credit'] ?? 0),
                ]);
            }
        }

        return $rows;
    }

    private function journalCell(array $row, string $value): string
    {
        return !empty($row['journal_row_start']) ? HelperFramework::escape($value) : '';
    }

    private function journalExportValue(array $row, string $value): string
    {
        return !empty($row['journal_row_start']) ? $value : '';
    }

    private function sourceCellHtml(array $journal, int $companyId, int $accountingPeriodId): string
    {
        if (empty($journal['journal_row_start'])) {
            return '';
        }

        return '<div class="journal-source-line">'
            . $this->sourceHtml($journal)
            . '<div class="helper">' . $this->actionHtml($journal, $companyId, $accountingPeriodId) . '</div>'
            . '</div>';
    }

    private function sourceHtml(array $journal): string
    {
        $sourceType = (string)($journal['source_type'] ?? '');
        $sourceTransactionId = $this->journalSourceTransactionId($journal);
        $sourceRef = trim((string)($journal['source_ref'] ?? ''));

        return '<span class="badge ' . HelperFramework::escape($sourceType === 'bank_csv' ? 'info' : 'success') . '">'
            . HelperFramework::escape($this->sourceTypeLabel($sourceType))
            . '</span>'
            . ($sourceTransactionId > 0
                ? '<div class="helper">Transaction #' . $sourceTransactionId . '</div>'
                : ($sourceRef !== '' ? '<div class="helper">' . HelperFramework::escape($sourceRef) . '</div>' : ''));
    }

    private function statusCellHtml(array $row): string
    {
        if (empty($row['journal_row_start'])) {
            return '';
        }

        return '<span class="badge ' . HelperFramework::escape((int)($row['is_posted'] ?? 0) === 1 ? 'success' : 'warning') . '">'
            . HelperFramework::escape($this->statusLabel($row))
            . '</span>';
    }

    private function statusLabel(array $row): string
    {
        return (int)($row['is_posted'] ?? 0) === 1 ? 'Posted' : 'Draft';
    }

    private function sourceExport(array $journal): string
    {
        $sourceType = $this->sourceTypeLabel((string)($journal['source_type'] ?? ''));
        $sourceTransactionId = $this->journalSourceTransactionId($journal);
        $sourceRef = trim((string)($journal['source_ref'] ?? ''));

        if ($sourceTransactionId > 0) {
            return trim($sourceType . ' | Transaction #' . $sourceTransactionId);
        }

        return trim($sourceType . ($sourceRef !== '' ? ' | ' . $sourceRef : ''));
    }

    private function sourceTypeLabel(string $sourceType): string
    {
        return match (trim($sourceType)) {
            'bank_csv' => 'Bank CSV',
            default => HelperFramework::labelFromKey($sourceType, '_', $sourceType),
        };
    }

    private function actionHtml(array $journal, int $companyId, int $accountingPeriodId): string
    {
        $sourceTransactionId = $this->journalSourceTransactionId($journal);
        if ((string)($journal['source_type'] ?? '') === 'bank_csv' && $sourceTransactionId > 0) {
            return '<a class="button button-inline primary" href="' . HelperFramework::escape($this->buildTransactionsUrl(
                $companyId,
                $accountingPeriodId,
                $this->monthKeyFromDate((string)($journal['journal_date'] ?? ''))
            )) . '#transaction-' . $sourceTransactionId . '">Review Transaction</a>';
        }

        $sourceRef = trim((string)($journal['source_ref'] ?? ''));
        if ((string)($journal['source_type'] ?? '') === 'expense_register' && $sourceRef !== '') {
            return '<a class="button button-inline primary" href="' . HelperFramework::escape($this->buildExpenseClaimUrl(
                $companyId,
                $accountingPeriodId,
                $sourceRef
            )) . '">Review Claim</a>';
        }

        return '<span class="helper">Review at source</span>';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
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

    private function buildExpenseClaimUrl(int $companyId, int $accountingPeriodId, string $claimReferenceCode): string
    {
        return '?' . http_build_query([
            'page' => 'expense_claims',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'show_card' => 'expense_claim_editor',
            'claim_reference_code' => $claimReferenceCode,
        ]);
    }

}
