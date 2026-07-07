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
                    'filters' => ':journals_list',
                ],
            ],
            [
                'key' => 'company_accounts',
                'service' => \eel_accounts\Service\CompanyAccountService::class,
                'method' => 'fetchAccounts',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => false,
                ],
            ],
            [
                'key' => 'nominal_accounts',
                'service' => \eel_accounts\Repository\NominalAccountRepository::class,
                'method' => 'fetchNominalAccounts',
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
        $pageContext = $this->applyTableSortContext($request, $pageContext, $this->key());
        $pageContext[$this->key()] = array_merge(
            (array)($pageContext[$this->key()] ?? []),
            [
                'keyword' => trim((string)$request->input('journals_list_keyword', '')),
                'amount' => $this->normaliseAmount((string)$request->input('journals_list_amount', '')),
                'side' => $this->normaliseSide((string)$request->input('journals_list_side', 'any')),
                'source_account_id' => max(0, (int)$request->input('journals_list_source_account_id', 0)),
                'nominal_account_ids' => $this->normaliseIds($request->input('journals_list_nominal_account_ids', [])),
            ]
        );

        return $pageContext;
    }

    public function render(array $context): string
    {
        return $this->searchForm($context)
            . $this->configuredTable($context)->render(
            $context,
            $this->tableHiddenFields($context)
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
            ->exportLimit(5000)
            ->empty($this->hasSearchCriteria($context) ? $this->noMatchesMessage() : 'Posted transaction journals will appear here once transactions have been categorised and posted.')
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
            'page' => $this->pageId($context),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'journals_list_keyword' => $this->keyword($context),
            'journals_list_amount' => $this->amount($context),
            'journals_list_side' => $this->side($context),
            'journals_list_source_account_id' => $this->sourceAccountId($context),
            'journals_list_nominal_account_ids' => implode(',', $this->nominalAccountIds($context)),
        ];
    }

    private function searchForm(array $context): string
    {
        $pageId = $this->pageId($context);
        $keyword = $this->keyword($context);
        $amount = $this->amount($context);
        $side = $this->side($context);
        $sourceAccountId = $this->sourceAccountId($context);
        $selectedNominalIds = $this->nominalAccountIds($context);

        return '<form class="card-toolbar" method="post" action="?page=' . HelperFramework::escape(rawurlencode($pageId)) . '" data-ajax="true">
            <input type="hidden" name="show_card" value=".self">
            <input type="hidden" name="_pagination" value="1">
            <input type="hidden" name="_invalidate_fact" value="' . HelperFramework::escape($this->tableInvalidationFact()) . '">
            <input type="hidden" name="' . HelperFramework::escape($this->paginationPageField()) . '" value="1">
            <div class="actions-row journal-search-controls">
                <div class="mini-field">
                    <label for="journals_list_keyword">Keyword</label>
                    <input class="input" id="journals_list_keyword" name="journals_list_keyword" value="' . HelperFramework::escape($keyword) . '">
                </div>
                <div class="mini-field">
                    <label for="journals_list_amount">Amount</label>
                    <input class="input" id="journals_list_amount" name="journals_list_amount" inputmode="decimal" value="' . HelperFramework::escape($amount) . '">
                </div>
                <div class="mini-field">
                    <label for="journals_list_side">Side</label>
                    <select class="select" id="journals_list_side" name="journals_list_side">
                        ' . $this->sideOptions($side) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="journals_list_source_account_id">Source Account</label>
                    <select class="select" id="journals_list_source_account_id" name="journals_list_source_account_id">
                        ' . $this->sourceAccountOptions($context, $sourceAccountId) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="journals_list_nominal_account_ids">Nominals</label>
                    <select class="select" id="journals_list_nominal_account_ids" name="journals_list_nominal_account_ids[]" multiple size="6">
                        ' . $this->nominalOptions($context, $selectedNominalIds) . '
                    </select>
                </div>
                <button class="button primary" type="submit">Search</button>
                <a class="button" href="?page=' . HelperFramework::escape(rawurlencode($pageId)) . '&amp;show_card=' . HelperFramework::escape($this->key()) . '">Clear</a>
            </div>
        </form>';
    }

    private function sideOptions(string $selectedSide): string
    {
        $options = [
            'any' => 'Any',
            'dr' => 'DR',
            'cr' => 'CR',
        ];
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedSide ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
    }

    private function sourceAccountOptions(array $context, int $selectedAccountId): string
    {
        $html = '<option value="">Any</option>';
        foreach ($this->companyAccounts($context) as $account) {
            $id = (int)($account['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $html .= '<option value="' . $id . '"' . ($id === $selectedAccountId ? ' selected' : '') . '>'
                . HelperFramework::escape($this->companyAccountLabel($account))
                . '</option>';
        }

        return $html;
    }

    private function nominalOptions(array $context, array $selectedNominalIds): string
    {
        $selected = array_fill_keys($selectedNominalIds, true);
        $html = '<option value="">Any</option>';
        foreach ($this->nominalAccounts($context) as $nominal) {
            $id = (int)($nominal['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $html .= '<option value="' . $id . '"' . (isset($selected[$id]) ? ' selected' : '') . '>'
                . HelperFramework::escape(FormattingFramework::nominalLabel($nominal))
                . '</option>';
        }

        return $html;
    }

    private function journalRows(array $context): array
    {
        return array_values(array_filter(
            (array)($context['services']['journal_entries'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function companyAccounts(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['company_accounts'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function nominalAccounts(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['nominal_accounts'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function keyword(array $context): string
    {
        return trim((string)(($context[$this->key()] ?? [])['keyword'] ?? ''));
    }

    private function amount(array $context): string
    {
        return $this->normaliseAmount((string)(($context[$this->key()] ?? [])['amount'] ?? ''));
    }

    private function side(array $context): string
    {
        return $this->normaliseSide((string)(($context[$this->key()] ?? [])['side'] ?? 'any'));
    }

    private function sourceAccountId(array $context): int
    {
        return max(0, (int)(($context[$this->key()] ?? [])['source_account_id'] ?? 0));
    }

    private function nominalAccountIds(array $context): array
    {
        return $this->normaliseIds(($context[$this->key()] ?? [])['nominal_account_ids'] ?? []);
    }

    private function hasSearchCriteria(array $context): bool
    {
        return $this->keyword($context) !== ''
            || $this->amount($context) !== ''
            || $this->side($context) !== 'any'
            || $this->sourceAccountId($context) > 0
            || $this->nominalAccountIds($context) !== [];
    }

    private function noMatchesMessage(): string
    {
        return 'No journals match this search [' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '].';
    }

    private function normaliseAmount(string $value): string
    {
        $value = trim(str_replace("\xC2\xA3", '', $value));
        if ($value === '' || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = abs(round((float)$value, 2));
        if ($amount < 0.005) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function normaliseSide(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['dr', 'cr'], true) ? $value : 'any';
    }

    private function normaliseIds(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        } elseif (!is_array($values)) {
            $values = [$values];
        }

        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function companyAccountLabel(array $account): string
    {
        $name = trim((string)($account['account_name'] ?? ''));
        $institution = trim((string)($account['institution_name'] ?? ''));
        $identifier = trim((string)($account['account_identifier'] ?? ''));
        $parts = array_values(array_filter([$name, $institution, $identifier], static fn(string $value): bool => $value !== ''));

        return $parts !== [] ? implode(' / ', $parts) : 'Account #' . (int)($account['id'] ?? 0);
    }

    private function pageId(array $context): string
    {
        $pageId = trim((string)(($context['page'] ?? [])['page_id'] ?? 'journal'));

        return $pageId !== '' ? $pageId : 'journal';
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
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
                'transactions',
                'Review Transaction',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'month_key' => $this->monthKeyFromDate((string)($journal['journal_date'] ?? '')),
                    'category_filter' => 'all',
                    'transaction_id' => $sourceTransactionId,
                ],
                'button button-inline primary'
            );
        }

        $sourceRef = trim((string)($journal['source_ref'] ?? ''));
        if ((string)($journal['source_type'] ?? '') === 'expense_register' && $sourceRef !== '') {
            return \eel_accounts\Renderer\WorkflowHandoffRenderer::button(
                'expense_claims',
                'Review Claim',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'show_card' => 'expense_claim_editor',
                    'claim_reference_code' => $sourceRef,
                ],
                'button button-inline primary'
            );
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

}
