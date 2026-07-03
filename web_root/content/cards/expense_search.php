<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_searchCard extends CardBaseFramework
{
    private const PAGE_SIZE = 15;

    public function key(): string
    {
        return 'expense_search';
    }

    public function title(): string
    {
        return 'Expenses Search';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expense_search_results',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'searchExpenseLines',
                'params' => [
                    'companyId' => ':company.id',
                    'accountingPeriodId' => ':company.accounting_period_id',
                    'filters' => ':expense_search',
                ],
            ],
            [
                'key' => 'expense_search_claimants',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchClaimants',
                'params' => [
                    'companyId' => ':company.id',
                    'activeOnly' => false,
                ],
            ],
            [
                'key' => 'expense_search_nominals',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchExpenseNominals',
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

        $period = $this->normalisePeriod(
            (string)$request->input('expense_search_period', ''),
            $this->dateFormat($pageContext)
        );

        $pageContext[$this->key()] = array_merge(
            (array)($pageContext[$this->key()] ?? []),
            [
                'keyword' => trim((string)$request->input('expense_search_keyword', '')),
                'amount' => $this->normaliseAmount((string)$request->input('expense_search_amount', '')),
                'claimant_id' => max(0, (int)$request->input('expense_search_claimant_id', 0)),
                'period' => (string)($period['display'] ?? ''),
                'claim_year' => (int)($period['year'] ?? 0),
                'claim_month' => (int)($period['month'] ?? 0),
                'statuses' => $this->normaliseStatuses($request->input('expense_search_statuses', [])),
                'nominal_account_ids' => $this->normaliseIds($request->input('expense_search_nominal_account_ids', [])),
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
        $settings = (array)($company['settings'] ?? []);
        if ((int)($company['id'] ?? 0) <= 0) {
            return '<div class="helper">A company has to be added and selected before expenses can be searched.</div>';
        }

        $tableState = $this->configuredTableState($context);
        /** @var TableFramework $table */
        $table = $tableState['table'];
        $hiddenFields = (array)$tableState['hidden_fields'];

        return $this->searchForm($context)
            . $table->renderToolbar($context, $hiddenFields)
            . $table->renderTable()
            . $this->footerWithAmountTotal(
                $table->renderFooter(),
                (array)$tableState['visible_rows'],
                (array)$tableState['query_rows'],
                $settings
            );
    }

    public function tables(array $context): array
    {
        return [$this->table($context)];
    }

    private function configuredTableState(array $context): array
    {
        $hiddenFields = $this->hiddenFields($context);
        $table = $this->configureTableSorting($this->table($context), $context, $hiddenFields);
        $queryRows = $table->sortedRows();
        $pagination = HelperFramework::paginateArray($queryRows, $this->paginationPage($context), self::PAGE_SIZE);
        $visibleRows = (array)$pagination['items'];

        $table = $table
            ->visibleRows($visibleRows)
            ->pagination(
                $pagination,
                'Expense lines',
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

    private function table(array $context): TableFramework
    {
        $companySettings = (array)(($context['company'] ?? [])['settings'] ?? []);

        return TableFramework::make($this->key(), $this->rows($context))
            ->filename('expense-search')
            ->exportLimit(5000)
            ->empty($this->hasSearchCriteria($context) ? $this->noMatchesMessage() : 'Enter a keyword or choose a filter to search expense lines.')
            ->textColumn('claim_reference_code', 'Reference')
            ->textColumn('claimant_name', 'Claimant')
            ->column(
                'claim_period',
                'Period',
                html: fn(array $row): string => HelperFramework::escape($this->displayClaimPeriod($row, $this->dateFormat($context))),
                export: fn(array $row): string => $this->displayClaimPeriod($row, $this->dateFormat($context)),
                sort: static fn(array $row): string => (string)($row['claim_period'] ?? '')
            )
            ->column(
                'expense_date',
                'Date',
                html: fn(array $row): string => HelperFramework::escape($this->displayDate((string)($row['expense_date'] ?? ''))),
                export: static fn(array $row): string => (string)($row['expense_date'] ?? ''),
                exportType: 'date'
            )
            ->textColumn('description', 'Description')
            ->textColumn('notes', 'Notes')
            ->column(
                'amount',
                'Amount',
                html: fn(array $row): string => HelperFramework::escape($this->money($companySettings, $row['amount'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'nominal',
                'Charge To',
                html: fn(array $row): string => HelperFramework::escape($this->nominalLabel($row)),
                export: fn(array $row): string => $this->nominalLabel($row)
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => $this->statusHtml((string)($row['status'] ?? '')),
                export: fn(array $row): string => HelperFramework::labelFromKey((string)($row['status'] ?? ''), '_')
            )
            ->textColumn('updated_at', 'Updated')
            ->column(
                'action',
                '',
                html: fn(array $row): string => $this->openAction($row, $context),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function searchForm(array $context): string
    {
        $keyword = $this->keyword($context);
        $amount = $this->amount($context);
        $claimantId = $this->claimantId($context);
        $period = $this->period($context);
        $statuses = $this->statuses($context);
        $selectedNominalIds = $this->nominalAccountIds($context);
        $periodFormat = $this->periodDisplayFormat($this->dateFormat($context));

        return '<form class="card-toolbar" method="post" action="?page=expense_claims" data-ajax="true">
            <input type="hidden" name="show_card" value=".self">
            <input type="hidden" name="_pagination" value="1">
            <input type="hidden" name="_invalidate_fact" value="' . HelperFramework::escape($this->tableInvalidationFact()) . '">
            <input type="hidden" name="' . HelperFramework::escape($this->paginationPageField()) . '" value="1">
            <div class="actions-row expense-search-controls">
                <div class="mini-field">
                    <label for="expense_search_keyword">Keyword</label>
                    <input class="input" id="expense_search_keyword" name="expense_search_keyword" value="' . HelperFramework::escape($keyword) . '">
                </div>
                <div class="mini-field">
                    <label for="expense_search_amount">Amount</label>
                    <input class="input" id="expense_search_amount" name="expense_search_amount" inputmode="decimal" value="' . HelperFramework::escape($amount) . '">
                </div>
                <div class="mini-field">
                    <label for="expense_search_claimant_id">Claimant</label>
                    <select class="select" id="expense_search_claimant_id" name="expense_search_claimant_id">
                        ' . $this->claimantOptions($context, $claimantId) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="expense_search_period">Period</label>
                    <input class="input" id="expense_search_period" name="expense_search_period" placeholder="' . HelperFramework::escape($periodFormat) . '" value="' . HelperFramework::escape($period) . '">
                </div>
                <div class="mini-field">
                    <label for="expense_search_statuses">Status</label>
                    <select class="select" id="expense_search_statuses" name="expense_search_statuses[]" multiple size="2">
                        ' . $this->statusOptions($statuses) . '
                    </select>
                </div>
                <div class="mini-field">
                    <label for="expense_search_nominal_account_ids">Nominals</label>
                    <select class="select" id="expense_search_nominal_account_ids" name="expense_search_nominal_account_ids[]" multiple size="6">
                        ' . $this->nominalOptions($context, $selectedNominalIds) . '
                    </select>
                </div>
                <button class="button primary" type="submit">Search</button>
                <a class="button" href="?page=expense_claims&amp;show_card=expense_search">Clear</a>
            </div>
        </form>';
    }

    private function hiddenFields(array $context): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? 'expense_claims'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'show_card' => $this->key(),
            'expense_search_keyword' => $this->keyword($context),
            'expense_search_amount' => $this->amount($context),
            'expense_search_claimant_id' => $this->claimantId($context),
            'expense_search_period' => $this->period($context),
            'expense_search_statuses' => implode(',', $this->statuses($context)),
            'expense_search_nominal_account_ids' => implode(',', $this->nominalAccountIds($context)),
        ];
    }

    private function rows(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['expense_search_results'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function claimantOptions(array $context, int $selectedClaimantId): string
    {
        $html = '<option value="">Any</option>';
        foreach ($this->claimants($context) as $claimant) {
            $id = (int)($claimant['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $html .= '<option value="' . $id . '"' . ($id === $selectedClaimantId ? ' selected' : '') . '>'
                . HelperFramework::escape((string)($claimant['claimant_name'] ?? 'Claimant #' . $id))
                . '</option>';
        }

        return $html;
    }

    private function statusOptions(array $selectedStatuses): string
    {
        $selected = array_fill_keys($selectedStatuses, true);
        $html = '';

        foreach (['draft' => 'Draft', 'posted' => 'Posted'] as $value => $label) {
            $html .= '<option value="' . HelperFramework::escape($value) . '"' . (isset($selected[$value]) ? ' selected' : '') . '>'
                . HelperFramework::escape($label)
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

    private function footerWithAmountTotal(string $footer, array $visibleRows, array $queryRows, array $companySettings): string
    {
        if ($footer === '') {
            return '';
        }

        $totalHtml = '<div class="expense-search-amount-total">'
            . '<span>Amount total:</span> '
            . '<span>Page</span> '
            . '<strong>' . HelperFramework::escape($this->money($companySettings, $this->amountTotal($visibleRows))) . '</strong> '
            . '<span>Query</span> '
            . '<strong>' . HelperFramework::escape($this->money($companySettings, $this->amountTotal($queryRows))) . '</strong>'
            . '</div>';

        $footer = str_replace(
            'class="card-toolbar table-footer"',
            'class="card-toolbar table-footer expense-search-table-footer"',
            $footer
        );

        $withTotal = preg_replace('/<div class="actions-row">/', $totalHtml . '<div class="actions-row">', $footer, 1);

        return is_string($withTotal) ? $withTotal : $footer;
    }

    private function money(array $companySettings, float|int|string|null $value): string
    {
        return (new \eel_accounts\Service\CompanySettingsService())->money($companySettings, $value);
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

    private function openAction(array $row, array $context): string
    {
        $companyId = (int)(($context['company'] ?? [])['id'] ?? 0);
        $claimId = (int)($row['expense_claim_id'] ?? 0);

        if ($companyId <= 0 || $claimId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=expense_claims" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="select_claim">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <button class="button button-inline" type="submit" data-show-card="expense_claim_editor">Open</button>
        </form>';
    }

    private function statusHtml(string $status): string
    {
        $status = strtolower(trim($status));
        $class = $status === 'posted' ? 'success' : 'warning';

        return '<span class="badge ' . $class . '">' . HelperFramework::escape(HelperFramework::labelFromKey($status, '_')) . '</span>';
    }

    private function nominalLabel(array $row): string
    {
        return FormattingFramework::nominalLabel([
            'code' => (string)($row['nominal_code'] ?? ''),
            'name' => (string)($row['nominal_name'] ?? ''),
        ]);
    }

    private function displayClaimPeriod(array $row, string $dateFormat): string
    {
        $year = (int)($row['claim_year'] ?? 0);
        $month = (int)($row['claim_month'] ?? 0);
        if ($year <= 0 || $month < 1 || $month > 12) {
            return '';
        }

        return $this->formatPeriod($year, $month, $dateFormat);
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

    private function claimants(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['expense_search_claimants'] ?? []),
            static fn(mixed $row): bool => is_array($row)
        ));
    }

    private function nominalAccounts(array $context): array
    {
        return array_values(array_filter(
            (array)(($context['services'] ?? [])['expense_search_nominals'] ?? []),
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

    private function claimantId(array $context): int
    {
        return max(0, (int)(($context[$this->key()] ?? [])['claimant_id'] ?? 0));
    }

    private function period(array $context): string
    {
        $search = (array)($context[$this->key()] ?? []);
        $period = trim((string)($search['period'] ?? ''));
        if ($period !== '') {
            return (string)($this->normalisePeriod($period, $this->dateFormat($context))['display'] ?? '');
        }

        $year = (int)($search['claim_year'] ?? 0);
        $month = (int)($search['claim_month'] ?? 0);
        if ($year <= 0 || $month < 1 || $month > 12) {
            return '';
        }

        return $this->formatPeriod($year, $month, $this->dateFormat($context));
    }

    private function statuses(array $context): array
    {
        return $this->normaliseStatuses(($context[$this->key()] ?? [])['statuses'] ?? []);
    }

    private function nominalAccountIds(array $context): array
    {
        return $this->normaliseIds(($context[$this->key()] ?? [])['nominal_account_ids'] ?? []);
    }

    private function hasSearchCriteria(array $context): bool
    {
        return $this->keyword($context) !== ''
            || $this->amount($context) !== ''
            || $this->claimantId($context) > 0
            || $this->period($context) !== ''
            || $this->statuses($context) !== []
            || $this->nominalAccountIds($context) !== [];
    }

    private function normaliseAmount(string $value): string
    {
        $value = trim(str_replace("\xC2\xA3", '', $value));

        if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = round((float)$value, 2);
        if ($amount <= 0.0) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function normaliseStatuses(mixed $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        } elseif (!is_array($values)) {
            $values = [$values];
        }

        $statuses = [];
        foreach ($values as $value) {
            $status = strtolower(trim((string)$value));
            if (in_array($status, ['draft', 'posted'], true)) {
                $statuses[$status] = $status;
            }
        }

        return array_values($statuses);
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

    private function normalisePeriod(string $value, string $dateFormat): array
    {
        $value = trim($value);
        if ($value === '') {
            return ['display' => '', 'year' => 0, 'month' => 0];
        }

        if ($this->periodYearFirst($dateFormat)) {
            if (preg_match('/^(\d{4})[-\/](\d{1,2})$/', $value, $matches) !== 1) {
                return ['display' => '', 'year' => 0, 'month' => 0];
            }

            $year = (int)$matches[1];
            $month = (int)$matches[2];
        } else {
            if (preg_match('/^(\d{1,2})[-\/](\d{4})$/', $value, $matches) !== 1) {
                return ['display' => '', 'year' => 0, 'month' => 0];
            }

            $month = (int)$matches[1];
            $year = (int)$matches[2];
        }

        if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) {
            return ['display' => '', 'year' => 0, 'month' => 0];
        }

        return [
            'display' => $this->formatPeriod($year, $month, $dateFormat),
            'year' => $year,
            'month' => $month,
        ];
    }

    private function formatPeriod(int $year, int $month, string $dateFormat): string
    {
        if ($this->periodYearFirst($dateFormat)) {
            return sprintf('%04d-%02d', $year, $month);
        }

        return sprintf('%02d%s%04d', $month, $this->periodSeparator($dateFormat), $year);
    }

    private function periodDisplayFormat(string $dateFormat): string
    {
        return $this->periodYearFirst($dateFormat)
            ? 'YYYY-MM'
            : 'MM' . $this->periodSeparator($dateFormat) . 'YYYY';
    }

    private function periodYearFirst(string $dateFormat): bool
    {
        $yearPosition = $this->firstPosition($dateFormat, ['Y', 'y']);
        $monthPosition = $this->firstPosition($dateFormat, ['m', 'n', 'M', 'F']);

        return $yearPosition >= 0 && ($monthPosition < 0 || $yearPosition < $monthPosition);
    }

    private function periodSeparator(string $dateFormat): string
    {
        return str_contains($dateFormat, '-') ? '-' : '/';
    }

    private function firstPosition(string $value, array $needles): int
    {
        $positions = [];
        foreach ($needles as $needle) {
            $position = strpos($value, $needle);
            if ($position !== false) {
                $positions[] = $position;
            }
        }

        return $positions === [] ? -1 : min($positions);
    }

    private function dateFormat(array $context): string
    {
        return (string)(($context['expense_page_settings'] ?? [])['date_format'] ?? (($context['company']['settings'] ?? [])['date_format'] ?? 'd/m/Y'));
    }

    private function noMatchesMessage(): string
    {
        return 'No expense lines match this search [' . (new DateTimeImmutable())->format('Y-m-d H:i:s') . '].';
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? $this->key());
    }
}
