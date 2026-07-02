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
        $pageContext[$this->key()] = array_merge(
            (array)($pageContext[$this->key()] ?? []),
            [
                'keyword' => trim((string)$request->input('transaction_search_keyword', '')),
                'source_account_id' => max(0, (int)$request->input('transaction_search_source_account_id', 0)),
                'nominal_account_ids' => $this->normaliseIds($request->input('transaction_search_nominal_account_ids', [])),
                'amount' => $this->normaliseAmount((string)$request->input('transaction_search_amount', '')),
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
            . $table->renderToolbar($context, $hiddenFields)
            . $table->renderTable()
            . $this->footerWithAmountTotal($table->renderFooter(), (array)$tableState['visible_rows']);
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function configuredTableState(array $context): array
    {
        $hiddenFields = $this->hiddenFields($context);
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);
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
        ];
    }

    private function table(array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('transaction-search')
            ->exportLimit(5000)
            ->empty($this->hasSearchCriteria($context) ? 'No transactions match this search.' : 'Enter a keyword or choose a filter to search transactions.')
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
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['amount'] ?? 0)),
                export: static fn(array $row): string => FormattingFramework::money($row['amount'] ?? 0),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column('currency', 'FX')
            ->column(
                'balance',
                'Balance',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::nullableMoney($row['balance'] ?? null, '')),
                export: static fn(array $row): string => FormattingFramework::nullableMoney($row['balance'] ?? null, ''),
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
        $sourceAccountId = $this->sourceAccountId($context);
        $selectedNominalIds = $this->nominalAccountIds($context);

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
                <button class="button primary" type="submit">Search</button>
                <a class="button" href="?page=transactions&amp;show_card=transaction_search">Clear</a>
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
            'transaction_search_source_account_id' => $this->sourceAccountId($context),
            'transaction_search_nominal_account_ids' => implode(',', $this->nominalAccountIds($context)),
        ];
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

    private function footerWithAmountTotal(string $footer, array $visibleRows): string
    {
        if ($footer === '') {
            return '';
        }

        $totalHtml = '<div class="transaction-search-amount-total">'
            . '<span>Amount total:</span> '
            . '<strong>' . HelperFramework::escape(FormattingFramework::money($this->amountTotal($visibleRows))) . '</strong>'
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
        return $this->normaliseAmount((string)(($context[$this->key()] ?? [])['amount'] ?? ''));
    }

    private function nominalAccountIds(array $context): array
    {
        return $this->normaliseIds(($context[$this->key()] ?? [])['nominal_account_ids'] ?? []);
    }

    private function hasSearchCriteria(array $context): bool
    {
        return $this->keyword($context) !== ''
            || $this->amount($context) !== ''
            || $this->sourceAccountId($context) > 0
            || $this->nominalAccountIds($context) !== [];
    }

    private function normaliseAmount(string $value): string
    {
        $value = trim(str_replace("\xC2\xA3", '', $value));

        if ($value === '' || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = round((float)$value, 2);
        if (abs($amount) < 0.005) {
            $amount = 0.0;
        }

        return number_format($amount, 2, '.', '');
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

    private function journalStatusLabel(array $row): string
    {
        return (int)($row['has_derived_journal'] ?? 0) === 1 ? 'Journal exists' : 'No journal';
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
