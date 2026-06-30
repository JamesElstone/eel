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
        return '
            <div class="helper">Transaction-derived journals are read-only here. Use Review Transaction to change the source posting.</div>
            ' . $this->configuredTable($context)->render(
                $context,
                [
                    'cards[]' => (array)($context['page']['page_cards'] ?? []),
                ]
            );
    }

    public function tables(array $context): array
    {
        return [
            $this->configureTableSorting($this->table($context), $context, $this->tableHiddenFields($context)),
        ];
    }

    private function configuredTable(array $context): TableFramework
    {
        $hiddenFields = $this->tableHiddenFields($context);
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $rows = $table->sortedRows();
        $pagination = HelperFramework::paginateArray($rows, $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->visibleRows((array)$pagination['items'])
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

        return TableFramework::make($this->key(), $this->journalRows($context))
            ->filename('journals-list')
            ->exportLimit(1000)
            ->empty('Posted transaction journals will appear here once transactions have been categorised and posted.')
            ->column(
                'journal_date',
                'Date',
                html: static fn(array $row): string => HelperFramework::escape((string)($row['journal_date'] ?? '')),
                exportType: 'date'
            )
            ->textColumn('description', 'Description')
            ->column(
                'destination_nominal',
                'Destination Nominal',
                html: fn(array $row): string => $this->destinationNominalHtml($row),
                export: fn(array $row): string => $this->destinationNominalLabel($row),
                sort: fn(array $row): string => $this->destinationNominalLabel($row)
            )
            ->column(
                'source_type',
                'Source',
                html: fn(array $row): string => $this->sourceHtml($row),
                export: fn(array $row): string => $this->sourceExport($row)
            )
            ->column(
                'lines',
                'Lines',
                html: fn(array $row): string => $this->linesHtml($row),
                export: fn(array $row): string => $this->linesExport($row),
                sort: false
            )
            ->column(
                'total_debit',
                'Total',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money((float)($row['total_debit'] ?? 0))),
                export: static fn(array $row): string => FormattingFramework::money((float)($row['total_debit'] ?? 0)),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->badgeColumn(
                'is_posted',
                'Posted',
                badgeClassFormatter: static fn(array $row): string => (int)($row['is_posted'] ?? 0) === 1 ? 'success' : 'warning',
                labelFormatter: static fn(string $value, array $row): string => (int)($row['is_posted'] ?? 0) === 1 ? 'Posted' : 'Draft'
            )
            ->column(
                'actions',
                '',
                html: fn(array $row): string => $this->actionHtml($row, $companyId, $accountingPeriodId),
                exportable: false,
                sort: false
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

    private function destinationNominalHtml(array $journal): string
    {
        $label = $this->destinationNominalLabel($journal);
        if ($label === '') {
            return '<span class="helper">No destination nominal</span>';
        }

        $helper = $this->destinationNominalHelper($journal);

        return HelperFramework::escape($label)
            . ($helper !== '' ? '<div class="helper">' . HelperFramework::escape($helper) . '</div>' : '');
    }

    private function destinationNominalLabel(array $journal): string
    {
        $destinationLines = $this->destinationLines($journal);
        if ($destinationLines === []) {
            return '';
        }

        return implode(', ', array_values(array_unique(array_map(
            fn(array $line): string => $this->nominalLabel($line),
            $destinationLines
        ))));
    }

    private function destinationNominalHelper(array $journal): string
    {
        $destinationLines = $this->destinationLines($journal);
        $companyAccounts = array_values(array_filter(array_map(
            static fn(array $line): string => trim((string)($line['company_account_name'] ?? '')),
            $destinationLines
        )));

        return implode(', ', array_values(array_unique($companyAccounts)));
    }

    private function destinationLines(array $journal): array
    {
        $lines = array_values(array_filter(
            (array)($journal['lines'] ?? []),
            static fn(mixed $line): bool => is_array($line)
        ));

        $nominalLines = array_values(array_filter(
            $lines,
            static fn(array $line): bool => (int)($line['company_account_id'] ?? 0) <= 0
        ));

        if ($nominalLines !== []) {
            return $nominalLines;
        }

        $debitLines = array_values(array_filter(
            $lines,
            static fn(array $line): bool => (float)($line['debit'] ?? 0) > 0
        ));

        return $debitLines !== [] ? $debitLines : $lines;
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

    private function linesHtml(array $journal): string
    {
        $linesHtml = '';
        foreach ((array)($journal['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }

            $linesHtml .= '<span class="helper">' . HelperFramework::escape($this->lineLabel($line)) . '</span>';
        }

        return '<div class="document-stack">' . $linesHtml . '</div>';
    }

    private function linesExport(array $journal): string
    {
        $labels = [];
        foreach ((array)($journal['lines'] ?? []) as $line) {
            if (is_array($line)) {
                $labels[] = $this->lineLabel($line);
            }
        }

        return implode(' | ', $labels);
    }

    private function lineLabel(array $line): string
    {
        return $this->nominalLabel($line) . ': Dr '
            . FormattingFramework::money((float)($line['debit'] ?? 0)) . ' / Cr '
            . FormattingFramework::money((float)($line['credit'] ?? 0));
    }

    private function nominalLabel(array $line): string
    {
        $code = trim((string)($line['nominal_code'] ?? ''));
        $name = trim((string)($line['nominal_name'] ?? ''));

        if ($code !== '' && $name !== '') {
            return $code . ' - ' . $name;
        }

        return $code !== '' ? $code : $name;
    }

    private function actionHtml(array $journal, int $companyId, int $accountingPeriodId): string
    {
        $sourceTransactionId = $this->journalSourceTransactionId($journal);
        if ((string)($journal['source_type'] ?? '') === 'bank_csv' && $sourceTransactionId > 0) {
            return '<a class="button" href="' . HelperFramework::escape($this->buildTransactionsUrl(
                $companyId,
                $accountingPeriodId,
                $this->monthKeyFromDate((string)($journal['journal_date'] ?? ''))
            )) . '#transaction-' . $sourceTransactionId . '">Review Transaction</a>';
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

}
