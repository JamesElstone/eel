<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _transaction_searchCard extends CardBaseFramework
{
    private const PAGE_SIZE = 15;

    public function key(): string
    {
        return 'transaction_search';
    }

    public function title(): string
    {
        return 'Transaction Search';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'transaction_search_results',
                'service' => \eel_accounts\Repository\DashboardRepository::class,
                'method' => 'searchTransactions',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'keyword' => ':transaction_search.keyword',
                    'sourceAccountId' => ':transaction_search.source_account_id',
                    'nominalAccountIds' => ':transaction_search.nominal_account_ids',
                    'amount' => ':transaction_search.amount',
                    'flow' => ':transaction_search.flow',
                    'categoryStatus' => ':transaction_search.category_status',
                    'autoApprovalFilter' => ':transaction_search.auto_approval_filter',
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

    public function handle(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $pageContext = parent::handle($request, $services, $pageContext, $actionResult);
        $pageContext = $this->applyTableSortContext($request, $pageContext, $this->key());
        $flow = $this->normaliseFlow((string)$request->input('transaction_search_flow', 'any'));
        $pageContext[$this->key()] = array_merge(
            (array)($pageContext[$this->key()] ?? []),
            [
                'keyword' => trim((string)$request->input('transaction_search_keyword', '')),
                'source_account_id' => max(0, (int)$request->input('transaction_search_source_account_id', 0)),
                'nominal_account_ids' => $this->normaliseIds($request->input('transaction_search_nominal_account_ids', [])),
                'amount' => $this->normaliseAmount((string)$request->input('transaction_search_amount', ''), $flow),
                'flow' => $flow,
                'category_status' => $this->normaliseCategoryStatus((string)$request->input('transaction_search_category_status', '')),
                'auto_approval_filter' => $this->normaliseAutoApprovalFilter((string)$request->input('transaction_search_auto_approval_filter', '')),
            ]
        );

        return $pageContext;
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
        $company = (array)($context['company'] ?? []);
        if ((int)($company['id'] ?? 0) <= 0) {
            return '<div class="helper">A company has to be added and selected before transactions can be searched.</div>';
        }

        $tableState = $this->configuredTableState($context);
        /** @var TableFramework $table */
        $table = $tableState['table'];
        $hiddenFields = (array)$tableState['hidden_fields'];

        return $this->searchForm($context)
            . $this->periodPostConfirmationForm($context, (array)$tableState['query_rows'])
            . $table->renderToolbar($context, $hiddenFields)
            . $table->renderTable()
            . $this->footerWithAmountTotal(
                $table->renderFooter(),
                (array)$tableState['visible_rows'],
                (array)$tableState['query_rows'],
                (array)($company['settings'] ?? [])
            );
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function configuredTableState(array $context): array
    {
        $hiddenFields = $this->hiddenFields($context);
        $table = $this->configureSearchTableSorting($this->table($context), $context, $hiddenFields);
        $queryRows = $table->sortedRows();
        $pagination = HelperFramework::paginateArray($queryRows, $this->paginationPage($context), self::PAGE_SIZE);
        $visibleRows = (array)$pagination['items'];

        $table = $table
            ->visibleRows($visibleRows)
            ->pagination(
                $pagination,
                'Transactions',
                $this->paginationPageField(),
                $hiddenFields
            );

        return [
            'table' => $table,
            'hidden_fields' => $hiddenFields,
            'visible_rows' => $visibleRows,
            'query_rows' => $queryRows,
        ];
    }

    private function configureSearchTableSorting(TableFramework $table, array $context, array $hiddenFields): TableFramework
    {
        $sortKey = $this->tableSortKey($context, $table->key());
        $direction = $this->tableSortDirection($context, $table->key());

        if ($sortKey === '' || $direction === '') {
            $sortKey = 'txn_date';
            $direction = 'desc';
        }

        return $table->sorting($sortKey, $direction, $hiddenFields);
    }

    private function table(array $context): TableFramework
    {
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('transaction-search')
            ->exportLimit(5000)
            ->empty($this->hasSearchCriteria($context) ? $this->noMatchesMessage() : 'Enter a keyword or choose a filter to search transactions.')
            ->column('id', 'ID', exportType: 'number')
            ->column(
                'txn_date',
                'Date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['txn_date'] ?? ''))),
                export: static fn(array $row): string => (string)($row['txn_date'] ?? ''),
                exportType: 'date'
            )
            ->column('description', 'Description')
            ->column('reference', 'Reference')
            ->column('txn_type', 'Type')
            ->column(
                'source_account',
                'Source',
                html: fn(array $row): string => HelperFramework::escape($this->sourceAccountLabel($row)),
                export: fn(array $row): string => $this->sourceAccountLabel($row)
            )
            ->column(
                'amount',
                'Amount',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column('currency', 'FX')
            ->column(
                'balance',
                'Balance',
                html: static fn(array $row): string => HelperFramework::escape((new \eel_accounts\Service\MoneyFormatService())->format($companySettings, $row['balance'] ?? null, '')),
                export: static fn(array $row): string => ($row['balance'] ?? null) === null || ($row['balance'] ?? '') === '' ? '' : number_format((float)$row['balance'], 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'nominal',
                'Nominal',
                html: fn(array $row): string => HelperFramework::escape($this->nominalLabel($row)),
                export: fn(array $row): string => $this->nominalLabel($row)
            )
            ->column(
                'category_status',
                'Cat.',
                html: fn(array $row): string => '<span class="badge ' . HelperFramework::escape($this->categorisationBadgeClass((string)($row['category_status'] ?? ''))) . '">'
                    . HelperFramework::escape($this->categorisationLabel((string)($row['category_status'] ?? '')))
                    . '</span>',
                export: fn(array $row): string => $this->categorisationLabel((string)($row['category_status'] ?? ''))
            )
            ->column(
                'auto_rule',
                'Auto Rule',
                html: fn(array $row): string => HelperFramework::escape($this->autoRuleLabel($row)),
                export: fn(array $row): string => $this->autoRuleLabel($row)
            )
            ->column(
                'auto_approval',
                'Auto Decision',
                html: fn(array $row): string => $this->autoApprovalHtml($row),
                export: fn(array $row): string => $this->autoApprovalExport($row)
            )
            ->column(
                'flags',
                'Flags',
                html: fn(array $row): string => $this->flagsHtml($row),
                export: fn(array $row): string => $this->flagsExport($row)
            )
            ->column(
                'has_derived_journal',
                'Journal',
                html: fn(array $row): string => '<span class="badge ' . (((int)($row['has_derived_journal'] ?? 0) === 1) ? 'success' : 'warning') . '">'
                    . HelperFramework::escape($this->journalStatusLabel($row))
                    . '</span>',
                export: fn(array $row): string => $this->journalStatusLabel($row)
            )
            ->column('statement_upload_id', 'Upload', exportType: 'number')
            ->column(
                'document_download_status',
                'Doc.',
                html: fn(array $row): string => HelperFramework::escape(HelperFramework::labelFromKey((string)($row['document_download_status'] ?? 'skipped'), '_', 'Skipped')),
                export: fn(array $row): string => HelperFramework::labelFromKey((string)($row['document_download_status'] ?? 'skipped'), '_', 'Skipped')
            )
            ->column(
                'open_month',
                'Open Month',
                html: fn(array $row): string => $this->openMonthLink($row),
                export: fn(array $row): string => (string)($row['month_key'] ?? ''),
                sort: static fn(array $row): string => (string)($row['month_key'] ?? '')
            );
    }

    private function searchForm(array $context): string
    {
        $keyword = $this->keyword($context);
        $amount = $this->amount($context);
        $flow = $this->flow($context);
        $sourceAccountId = $this->sourceAccountId($context);
        $selectedNominalIds = $this->nominalAccountIds($context);
        $categoryStatus = $this->categoryStatus($context);
        $autoApprovalFilter = $this->autoApprovalFilter($context);

        return '<form class="card-toolbar" method="post" action="?page=transactions" data-ajax="true">
            <input type="hidden" name="show_card" value=".self">
            <input type="hidden" name="_pagination" value="1">
            <input type="hidden" name="_invalidate_fact" value="' . HelperFramework::escape($this->tableInvalidationFact()) . '">
            <input type="hidden" name="' . HelperFramework::escape($this->paginationPageField()) . '" value="1">
            <div class="actions-row transaction-search-controls">
                <div class="mini-field">
                    <label for="transaction_search_keyword">Keyword</label>
                    <input class="input" id="transaction_search_keyword" name="transaction_search_keyword" value="' . HelperFramework::escape($keyword) . '">
                </div>
                <div class="mini-field">
                    <label for="transaction_search_amount">Amount</label>
                    <input class="input" id="transaction_search_amount" name="transaction_search_amount" inputmode="decimal" value="' . HelperFramework::escape($amount) . '">
                </div>
                <div class="mini-field">
                    <label for="transaction_search_flow">Flow</label>
                    <select class="select" id="transaction_search_flow" name="transaction_search_flow">
                        ' . $this->flowOptions($flow) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="transaction_search_source_account_id">Source Account</label>
                    <select class="select" id="transaction_search_source_account_id" name="transaction_search_source_account_id">
                        ' . $this->sourceAccountOptions($context, $sourceAccountId) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="transaction_search_nominal_account_ids">Nominals</label>
                    <select class="select" id="transaction_search_nominal_account_ids" name="transaction_search_nominal_account_ids[]" multiple size="6">
                        ' . $this->nominalOptions($context, $selectedNominalIds) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="transaction_search_category_status">Categorisation</label>
                    <select class="select" id="transaction_search_category_status" name="transaction_search_category_status">
                        ' . $this->categoryStatusOptions($categoryStatus) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="transaction_search_auto_approval_filter">Auto Decision</label>
                    <select class="select" id="transaction_search_auto_approval_filter" name="transaction_search_auto_approval_filter">
                        ' . $this->autoApprovalFilterOptions($autoApprovalFilter) . '
                    </select>
                </div>
                <button class="button primary" type="submit">Search</button>
                ' . $this->accountingPeriodPill($context) . '
                <a class="button" href="?page=transactions&amp;show_card=transaction_search">Clear</a>
            </div>
        </form>';
    }

    private function accountingPeriodPill(array $context): string
    {
        $period = (array)($context['accounting_period'] ?? []);
        $start = trim((string)($period['period_start'] ?? ''));
        $end = trim((string)($period['period_end'] ?? ''));
        if ($start === '' || $end === '') {
            return '<span class="pill">No accounting period selected</span>';
        }

        return '<span class="pill">Accounting period: '
            . HelperFramework::escape(HelperFramework::displayDate($start) . ' to ' . HelperFramework::displayDate($end))
            . '</span>';
    }

    private function periodPostConfirmationForm(array $context, array $queryRows): string
    {
        if ($this->categoryStatus($context) !== 'auto' || $this->autoApprovalFilter($context) !== 'post_pending' || $queryRows === []) {
            return '';
        }

        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        return '<form class="card-toolbar" method="post" action="?page=transactions" data-ajax="true">
                ' . HelperFramework::csrfHiddenInput((new SessionAuthenticationService())->csrfToken()) . '
            <input type="hidden" name="card_action" value="Transaction">
            <input type="hidden" name="global_action" value="post_categorised_transactions">
            <input type="hidden" name="post_scope" value="period">
            <input type="hidden" name="confirm_auto_categorisations" value="1">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="accounting_period_id" value="' . $accountingPeriodId . '">
            <input type="hidden" name="show_card" value="transaction_search">
            <input type="hidden" name="transaction_search_category_status" value="auto">
            <input type="hidden" name="transaction_search_auto_approval_filter" value="post_pending">
            <div class="actions-row">
                <button class="button primary" type="submit"
                    data-chicken-check="true"
                    data-chicken-title="Post all checked auto decisions"
                    data-chicken-message="This will post categorised transactions and confirm all checked auto decision(s) awaiting post confirmation for this accounting period.<br><br>Continue?"
                    data-chicken-confirm-text="Post All Checked Auto Decisions"
                    data-chicken-button-class="button primary">Post All Checked Auto Decisions</button>
                <span class="pill">' . count($queryRows) . ' awaiting post confirmation</span>
            </div>
        </form>';
    }

    private function hiddenFields(array $context): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? 'transactions'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'transaction_search_keyword' => $this->keyword($context),
            'transaction_search_amount' => $this->amount($context),
            'transaction_search_flow' => $this->flow($context),
            'transaction_search_source_account_id' => $this->sourceAccountId($context),
            'transaction_search_nominal_account_ids' => implode(',', $this->nominalAccountIds($context)),
            'transaction_search_category_status' => $this->categoryStatus($context),
            'transaction_search_auto_approval_filter' => $this->autoApprovalFilter($context),
        ];
    }

    private function flowOptions(string $selectedFlow): string
    {
        $options = [
            'any' => 'Any',
            'in' => 'In (positive)',
            'out' => 'Out (negative)',
        ];
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedFlow ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
    }

    private function categoryStatusOptions(string $selectedStatus): string
    {
        $options = [
            '' => 'Any',
            'uncategorised' => 'Uncategorised',
            'auto' => 'Auto Categorisation',
            'manual' => 'Manual',
        ];
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedStatus ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
    }

    private function autoApprovalFilterOptions(string $selectedFilter): string
    {
        $options = [
            '' => 'Any',
            'pending' => 'Unconfirmed',
            'post_pending' => 'Awaiting post confirmation',
            'confirmed' => 'Correct',
        ];
        $html = '';

        foreach ($options as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedFilter ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
                . '</option>';
        }

        return $html;
    }

    private function sourceAccountOptions(array $context, int $selectedAccountId): string
    {
        $html = '<option value="">All source accounts</option>';
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

    private function rows(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['transaction_search_results'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function noMatchesMessage(): string
    {
        return 'No transactions match this search [' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '].';
    }

    private function footerWithAmountTotal(string $footer, array $visibleRows, array $queryRows, array $companySettings): string
    {
        if ($footer === '') {
            return '';
        }

        $totalHtml = '<div class="transaction-search-amount-total">'
            . '<span>Amount total:</span> '
            . '<span>Page</span> '
            . '<strong>' . HelperFramework::escape($this->money($companySettings, $this->amountTotal($visibleRows))) . '</strong> '
            . '<span>Query</span> '
            . '<strong>' . HelperFramework::escape($this->money($companySettings, $this->amountTotal($queryRows))) . '</strong>'
            . '</div>';

        $footer = str_replace(
            'class="card-toolbar table-footer"',
            'class="card-toolbar table-footer transaction-search-table-footer"',
            $footer
        );

        $withTotal = preg_replace('/<div class="actions-row">/', $totalHtml . '<div class="actions-row">', $footer, 1);

        return is_string($withTotal) ? $withTotal : $footer;
    }

    private function amountTotal(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            if (is_array($row)) {
                $total += (float)($row['amount'] ?? 0);
            }
        }

        return round($total, 2);
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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

    private function sourceAccountId(array $context): int
    {
        return max(0, (int)(($context[$this->key()] ?? [])['source_account_id'] ?? 0));
    }

    private function amount(array $context): string
    {
        return $this->normaliseAmount((string)(($context[$this->key()] ?? [])['amount'] ?? ''), $this->flow($context));
    }

    private function flow(array $context): string
    {
        return $this->normaliseFlow((string)(($context[$this->key()] ?? [])['flow'] ?? 'any'));
    }

    private function nominalAccountIds(array $context): array
    {
        return $this->normaliseIds(($context[$this->key()] ?? [])['nominal_account_ids'] ?? []);
    }

    private function categoryStatus(array $context): string
    {
        return $this->normaliseCategoryStatus((string)(($context[$this->key()] ?? [])['category_status'] ?? ''));
    }

    private function autoApprovalFilter(array $context): string
    {
        return $this->normaliseAutoApprovalFilter((string)(($context[$this->key()] ?? [])['auto_approval_filter'] ?? ''));
    }

    private function hasSearchCriteria(array $context): bool
    {
        return $this->keyword($context) !== ''
            || $this->amount($context) !== ''
            || $this->flow($context) !== 'any'
            || $this->sourceAccountId($context) > 0
            || $this->nominalAccountIds($context) !== []
            || $this->categoryStatus($context) !== ''
            || $this->autoApprovalFilter($context) !== '';
    }

    private function normaliseAmount(string $value, string $flow = 'any'): string
    {
        $value = trim(str_replace("\xC2\xA3", '', $value));
        $flow = $this->normaliseFlow($flow);

        if ($value === '' || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = round((float)$value, 2);
        if ($flow === 'in') {
            $amount = abs($amount);
        } elseif ($flow === 'out') {
            $amount = 0 - abs($amount);
        }

        if (abs($amount) < 0.005) {
            $amount = 0.0;
        }

        return number_format($amount, 2, '.', '');
    }

    private function normaliseFlow(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['in', 'out'], true) ? $value : 'any';
    }

    private function normaliseCategoryStatus(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['uncategorised', 'auto', 'manual'], true) ? $value : '';
    }

    private function normaliseAutoApprovalFilter(string $value): string
    {
        $value = strtolower(trim($value));
        $aliases = [
            'unconfirmed' => 'pending',
            'correct' => 'confirmed',
            'awaiting_post_confirmation' => 'post_pending',
            'awaiting post confirmation' => 'post_pending',
        ];
        $value = $aliases[$value] ?? $value;

        return in_array($value, ['pending', 'confirmed', 'post_pending'], true) ? $value : '';
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

    private function sourceAccountLabel(array $row): string
    {
        $parts = [];
        foreach (['owned_account_name', 'owned_institution_name', 'source_account_label'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return $parts !== [] ? implode(' / ', $parts) : 'Unassigned';
    }

    private function companyAccountLabel(array $account): string
    {
        $name = trim((string)($account['account_name'] ?? ''));
        $institution = trim((string)($account['institution_name'] ?? ''));
        $identifier = trim((string)($account['account_identifier'] ?? ''));
        $parts = array_values(array_filter([$name, $institution, $identifier], static fn(string $value): bool => $value !== ''));

        return $parts !== [] ? implode(' / ', $parts) : 'Account #' . (int)($account['id'] ?? 0);
    }

    private function nominalLabel(array $row): string
    {
        return FormattingFramework::nominalLabel([
            'code' => (string)($row['nominal_code'] ?? ''),
            'name' => (string)($row['assigned_nominal'] ?? ''),
        ]);
    }

    private function categorisationLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'auto' => 'Auto',
            'manual' => 'Manual',
            default => 'Uncategorised',
        };
    }

    private function categorisationBadgeClass(string $status): string
    {
        return match (strtolower(trim($status))) {
            'auto' => 'info',
            'manual' => 'success',
            default => 'warning',
        };
    }

    private function autoRuleLabel(array $row): string
    {
        $ruleId = (int)($row['auto_rule_id'] ?? 0);
        if ($ruleId <= 0) {
            return '';
        }

        $parts = ['Rule #' . $ruleId];
        $descriptionMatch = trim((string)($row['auto_rule_match_value'] ?? ''));
        $referenceMatch = trim((string)($row['auto_rule_reference_match_value'] ?? ''));
        if ($descriptionMatch !== '') {
            $parts[] = 'Description: ' . $descriptionMatch;
        }
        if ($referenceMatch !== '') {
            $parts[] = 'Reference: ' . $referenceMatch;
        }

        return implode(' | ', $parts);
    }

    private function autoApprovalHtml(array $row): string
    {
        if (!$this->isRuleBasedAutoTransaction($row)) {
            return '<span class="helper">-</span>';
        }

        if ($this->autoApprovalCheckedCurrent($row)) {
            return '<span class="badge success">Correct</span>';
        }

        return '<span class="badge warning">Unconfirmed</span>';
    }

    private function autoApprovalExport(array $row): string
    {
        if (!$this->isRuleBasedAutoTransaction($row)) {
            return '-';
        }

        return $this->autoApprovalCheckedCurrent($row) ? 'Correct' : 'Unconfirmed';
    }

    private function flagsHtml(array $row): string
    {
        $html = '<div class="document-stack">';
        if ($this->autoApprovalConfirmedCurrent($row)) {
            $html .= '<span class="badge success">Auto Correct</span>';
        }
        if ((int)($row['is_auto_excluded'] ?? 0) === 1) {
            $html .= '<span class="badge warning">Deferred</span>';
        }

        return $html . '</div>';
    }

    private function flagsExport(array $row): string
    {
        $flags = [];
        if ($this->autoApprovalConfirmedCurrent($row)) {
            $flags[] = 'Auto Correct';
        }
        if ((int)($row['is_auto_excluded'] ?? 0) === 1) {
            $flags[] = 'Deferred';
        }

        return implode(' | ', $flags);
    }

    private function journalStatusLabel(array $row): string
    {
        return (int)($row['has_derived_journal'] ?? 0) === 1 ? 'Journal exists' : 'No journal';
    }

    private function isRuleBasedAutoTransaction(array $row): bool
    {
        return strtolower(trim((string)($row['category_status'] ?? ''))) === 'auto'
            && (int)($row['auto_rule_id'] ?? 0) > 0;
    }

    private function autoApprovalCheckedCurrent(array $row): bool
    {
        return (int)($row['auto_approval_checked_current'] ?? 0) === 1;
    }

    private function autoApprovalConfirmedCurrent(array $row): bool
    {
        return (int)($row['auto_approval_confirmed_current'] ?? 0) === 1;
    }

    private function openMonthLink(array $row): string
    {
        $monthKey = trim((string)($row['month_key'] ?? ''));
        if ($monthKey === '') {
            return '';
        }

        $url = '?page=transactions&show_card=transactions_imported&month_key=' . rawurlencode($monthKey) . '&category_filter=all';

        return '<a class="button" href="' . HelperFramework::escape($url) . '">Open&nbsp;Month</a>';
    }

    private function displayDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        try {
            return HelperFramework::displayDate($value);
        } catch (Throwable) {
            return $value;
        }
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
