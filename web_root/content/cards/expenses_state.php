<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expenses_stateCard extends CardBaseFramework
{
    private const PAGE_SIZE = 13;

    public function key(): string
    {
        return 'expenses_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => \eel_accounts\Service\ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company.id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Expense Claims';
    }

    public function tables(array $context): array
    {
        return [$this->configuredClaimsTable($context)];
    }

    public function handleError(string $serviceKey, array $error, array $context): string
    {
        return '';
    }

    public function render(array $context): string
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $claimants = (array)($data['claimants'] ?? []);
        $accountingPeriods = (array)($data['accounting_periods'] ?? []);
        $claims = (array)($data['claims'] ?? []);
        $claimHeatmapLines = (array)($data['claim_heatmap_lines'] ?? $data['claim_heatmap_claims'] ?? []);
        $filters = (array)($data['filters'] ?? []);

        return '
            <div id="expenses-app">
                <div class="settings-stack">
                    ' . $this->renderClaimsPanel($context, $claimHeatmapLines, $claimants, $accountingPeriods, $filters, $companyId, (int)($company['accounting_period_id'] ?? 0)) . '
                </div>
            </div>';
    }

    private function renderClaimsPanel(
        array $context,
        array $claimHeatmapLines,
        array $claimants,
        array $accountingPeriods,
        array $filters,
        int $companyId,
        int $companyAccountingPeriodId
    ): string {
        $query = (string)($filters['query'] ?? '');
        $status = (string)($filters['status'] ?? 'all');
        $heatmapClaimantId = $this->selectedHeatmapClaimantId($claimants, (int)($filters['heatmap_claimant_id'] ?? 0));
        $heatmapPeriod = $this->selectedHeatmapPeriod($accountingPeriods, $companyAccountingPeriodId);
        $heatmapDate = $heatmapPeriod !== null
            ? $this->normaliseHeatmapDate((string)($filters['heatmap_date'] ?? ''), (string)$heatmapPeriod['start'], (string)$heatmapPeriod['end'])
            : '';
        $heatmapFormId = 'expense-claim-heatmap-form';
        $claimsTable = $this->configuredClaimsTable($context);
        $claimsTableHiddenFields = array_merge(
            $this->claimsTableHiddenFields($context, $filters, $companyId),
            ['cards[]' => (array)($context['page']['page_cards'] ?? [])]
        );

        return '<div class="expense-claims-stack">
        <section class="panel-soft">
            ' . $this->withoutEmptyActionRows($claimsTable->renderToolbar($context, $claimsTableHiddenFields)) . '
            ' . $this->renderClaimHeatmap($heatmapFormId, $claimHeatmapLines, $claimants, $accountingPeriods, $heatmapClaimantId, $heatmapPeriod, $heatmapDate, $query, $status, $companyId) . '
            ' . $claimsTable->renderTable() . '
            ' . $claimsTable->renderFooter() . '
        </section>
        </div>';
    }

    private function configuredClaimsTable(array $context): TableFramework
    {
        $data = (array)($context['services']['expensesPageData'] ?? []);
        $filters = (array)($data['filters'] ?? []);
        $company = (array)($context['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $claimants = (array)($data['claimants'] ?? []);
        $heatmapClaimantId = $this->selectedHeatmapClaimantId($claimants, (int)($filters['heatmap_claimant_id'] ?? 0));
        $table = $this->claimsTable((array)($data['claims'] ?? []), $context);
        $pagination = HelperFramework::paginateArray($table->sortedRows(), $this->paginationPage($context), self::PAGE_SIZE);

        return $table
            ->toolbarActions($this->claimsTableToolbarHtml($context, $claimants, $filters, $companyId, $heatmapClaimantId))
            ->visibleRows((array)$pagination['items'])
            ->pagination(
                $pagination,
                'Expense claims',
                $this->paginationPageField(),
                $this->claimsTableHiddenFields($context, $filters, $companyId)
            );
    }

    private function claimsTable(array $claims, array $context): TableFramework
    {
        return TableFramework::make($this->key(), $this->claimTableRows($claims))
            ->filename('expense-claims')
            ->exportLimit(1000)
            ->empty('No expense claims were found.')
            ->textColumn('claim_reference_code', 'Reference')
            ->textColumn('claimant_name', 'Claimant')
            ->textColumn('claim_period_label', 'Month')
            ->column(
                'A',
                'A',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['A'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['A'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'B',
                'B',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['B'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['B'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'C',
                'C',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['C'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['C'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'D',
                'D',
                html: static fn(array $row): string => HelperFramework::escape(FormattingFramework::money($row['D'] ?? 0)),
                export: static fn(array $row): string => number_format((float)($row['D'] ?? 0), 2, '.', ''),
                cellClass: 'numeric',
                exportType: 'number'
            )
            ->column(
                'status',
                'Status',
                html: fn(array $row): string => $this->claimStatusHtml($row),
                export: fn(array $row): string => $this->claimStatusLabel($row)
            )
            ->textColumn('last_updated', 'Updated')
            ->column(
                'action',
                '',
                html: fn(array $row): string => $this->claimOpenForm($row, $context),
                exportable: false,
                cellClass: 'cell-fit'
            );
    }

    private function claimTableRows(array $claims): array
    {
        $rows = [];

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }

            $claim['claim_period_label'] = $this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0));
            $rows[] = $claim;
        }

        return $rows;
    }

    private function withoutEmptyActionRows(string $html): string
    {
        return preg_replace('/<div class="actions-row">\s*<\/div>\s*/', '', $html) ?? $html;
    }

    private function claimsTableHiddenFields(array $context, array $filters, int $companyId): array
    {
        return [
            'page' => (string)($context['page']['page_id'] ?? 'expenses'),
            '_pagination' => '1',
            '_invalidate_fact' => $this->tableInvalidationFact(),
            'cards[]' => [$this->key()],
            'company_id' => $companyId,
            'expense_query' => (string)($filters['query'] ?? ''),
            'expense_status' => (string)($filters['status'] ?? 'all'),
            'expense_heatmap_claimant_id' => (int)($filters['heatmap_claimant_id'] ?? 0),
            'expense_heatmap_date' => (string)($filters['heatmap_date'] ?? ''),
        ];
    }

    private function claimsTableToolbarHtml(array $context, array $claimants, array $filters, int $companyId, int $selectedClaimantId): string
    {
        return '</div>
            <div class="actions-row">'
            . $this->claimantFilterToolbarForm($context, $claimants, $filters, $companyId, $selectedClaimantId)
            . '</div>
            <div class="actions-row">'
            . $this->statusFilterToolbarForm($context, $filters, $companyId, $selectedClaimantId)
            . '</div>
            <div class="actions-row">
                ' . $this->claimsTableSearchToolbarForm($context, $filters, $companyId, $selectedClaimantId) . '
            </div>
            <div class="actions-row">';
    }

    private function claimsTableSearchToolbarForm(array $context, array $filters, int $companyId, int $selectedClaimantId): string
    {
        return '<form id="expense-search-form" method="get" action="?page=expenses" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="' . HelperFramework::escape((string)($context['page']['page_id'] ?? 'expenses')) . '">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_status" value="' . HelperFramework::escape((string)($filters['status'] ?? 'all')) . '">
                <input type="hidden" name="expense_heatmap_claimant_id" value="' . $selectedClaimantId . '">
                <input type="hidden" name="expense_heatmap_date" value="' . HelperFramework::escape((string)($filters['heatmap_date'] ?? '')) . '">
                <div class="mini-field">
                    <input class="input" id="expense-search-query" name="expense_query" type="search" value="' . HelperFramework::escape((string)($filters['query'] ?? '')) . '" placeholder="EXP-...">
                </div>
                <button class="button primary" type="submit">Search</button>
            </form>';
    }

    private function statusFilterToolbarForm(array $context, array $filters, int $companyId, int $selectedClaimantId): string
    {
        $options = '';
        $selectedStatus = (string)($filters['status'] ?? 'all');
        foreach ($this->statusFilterOptions() as $value => $label) {
            $value = (string)$value;
            $options .= '<option value="' . HelperFramework::escape($value) . '"' . ($value === $selectedStatus ? ' selected' : '') . '>' . HelperFramework::escape((string)$label) . '</option>';
        }

        return '<form method="post" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="' . HelperFramework::escape((string)($context['page']['page_id'] ?? 'expenses')) . '">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_query" value="' . HelperFramework::escape((string)($filters['query'] ?? '')) . '">
                <input type="hidden" name="expense_heatmap_claimant_id" value="' . $selectedClaimantId . '">
                <input type="hidden" name="expense_heatmap_date" value="' . HelperFramework::escape((string)($filters['heatmap_date'] ?? '')) . '">
                <div class="form-row table-filter-row">
                    <label for="table-filter-expenses_state-expense_status">Show</label>
                    <select class="selector-input" id="table-filter-expenses_state-expense_status" name="expense_status">' . $options . '</select>
                </div>
            </form>';
    }

    private function claimantFilterToolbarForm(array $context, array $claimants, array $filters, int $companyId, int $selectedClaimantId): string
    {
        return '<form method="get" action="?page=expenses" data-ajax="true" class="toolbar">
                <input type="hidden" name="page" value="' . HelperFramework::escape((string)($context['page']['page_id'] ?? 'expenses')) . '">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_query" value="' . HelperFramework::escape((string)($filters['query'] ?? '')) . '">
                <input type="hidden" name="expense_status" value="' . HelperFramework::escape((string)($filters['status'] ?? 'all')) . '">
                <label for="expense-claimant">Claimant</label>
                <select class="select" id="expense-claimant" name="claimant_id">' . $this->claimantOptions($claimants, $selectedClaimantId) . '</select>
            </form>';
    }

    private function claimOpenForm(array $claim, array $context): string
    {
        $companyId = (int)(($context['company'] ?? [])['id'] ?? 0);
        $claimId = (int)($claim['id'] ?? 0);
        $filters = (array)((($context['services']['expensesPageData'] ?? [])['filters'] ?? []));

        if ($companyId <= 0 || $claimId <= 0) {
            return '';
        }

        return '<form method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="select_claim">
            <input type="hidden" name="claim_id" value="' . $claimId . '">
            <input type="hidden" name="expense_status" value="' . HelperFramework::escape((string)($filters['status'] ?? 'all')) . '">
            <input type="hidden" name="expense_query" value="' . HelperFramework::escape((string)($filters['query'] ?? '')) . '">
            <button class="button button-inline" type="submit" data-show-card="expense_claim_editor">Open</button>
        </form>';
    }

    private function renderClaimHeatmap(
        string $formId,
        array $claimLines,
        array $claimants,
        array $accountingPeriods,
        int $selectedClaimantId,
        ?array $selectedPeriod,
        string $selectedDate,
        string $query,
        string $status,
        int $companyId
    ): string {
        $hasClaimants = $claimants !== [];
        $chartHtml = '<div class="helper">Add a claimant first to see claim activity.</div>';

        if ($hasClaimants && $selectedPeriod === null) {
            $chartHtml = '<div class="helper">Select an accounting period to see claim activity.</div>';
        } elseif ($hasClaimants) {
            $chartHtml = $this->withClaimEditorTarget((new ChartService())->calendarHeatmap(
                $selectedClaimantId > 0
                    ? $this->claimHeatmapDays($claimLines, $selectedClaimantId, (string)$selectedPeriod['start'], (string)$selectedPeriod['end'])
                    : [],
                [
                    'title' => 'Claim calendar',
                    'id' => 'expense-claim-calendar',
                    'start_date' => (string)$selectedPeriod['start'],
                    'end_date' => (string)$selectedPeriod['end'],
                    'selected_date' => $selectedDate,
                    'range_control' => ['type' => 'date', 'options' => []],
                    'value_label' => 'claim lines',
                    'input_name' => 'expense_heatmap_date',
                    'ajax_target' => 'expenses-app',
                ]
            ));
        }

        return '<div class="expense-claim-heatmap">
            <form id="' . $formId . '" method="get" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="page" value="expenses">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="filter_claims">
                <input type="hidden" name="expense_query" value="' . HelperFramework::escape($query) . '">
                <input type="hidden" name="expense_status" value="' . HelperFramework::escape($status) . '">
                <input type="hidden" name="expense_heatmap_claimant_id" value="' . $selectedClaimantId . '">
                ' . $chartHtml . '
            </form>
        </div>';
    }

    private function claimHeatmapDays(array $claimLines, int $selectedClaimantId, string $periodStart, string $periodEnd): array
    {
        $days = [];

        foreach ($claimLines as $claimLine) {
            if ((int)($claimLine['claimant_id'] ?? 0) !== $selectedClaimantId) {
                continue;
            }

            $date = $this->claimHeatmapDate($claimLine);
            if ($date === '') {
                continue;
            }

            if ($date < $periodStart || $date > $periodEnd) {
                continue;
            }

            if (!isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'value' => 0,
                    'references' => [],
                ];
            }

            $days[$date]['value']++;
            $reference = trim((string)($claimLine['claim_reference_code'] ?? ''));
            if ($reference !== '') {
                $days[$date]['references'][] = $reference;
            }
        }

        foreach ($days as $date => $day) {
            $count = (int)$day['value'];
            $references = array_values(array_unique((array)$day['references']));
            $suffix = $references !== [] ? ': ' . implode(', ', $references) : '';
            $days[$date]['title'] = $count . ' ' . ($count === 1 ? 'claim line' : 'claim lines') . ' on ' . (new DateTimeImmutable($date))->format('j F Y') . $suffix;
            unset($days[$date]['references']);
        }

        return array_values($days);
    }

    private function withClaimEditorTarget(string $chartHtml): string
    {
        return preg_replace(
            '/<button class="(calendar-heatmap-day calendar-heatmap-day-level-[1-4][^"]*)"/',
            '<button class="$1" data-show-card="expense_claim_editor"',
            $chartHtml
        ) ?? $chartHtml;
    }

    private function selectedHeatmapClaimantId(array $claimants, int $requestedClaimantId): int
    {
        foreach ($claimants as $claimant) {
            if ((int)($claimant['id'] ?? 0) === $requestedClaimantId && (int)($claimant['is_active'] ?? 0) === 1) {
                return $requestedClaimantId;
            }
        }

        return 0;
    }

    private function claimantOptions(array $claimants, int $selectedClaimantId): string
    {
        $options = '';

        foreach ($claimants as $claimant) {
            if (!is_array($claimant) || (int)($claimant['is_active'] ?? 0) !== 1) {
                continue;
            }

            $claimantId = (int)($claimant['id'] ?? 0);
            $selected = $claimantId === $selectedClaimantId ? ' selected' : '';
            $options .= '<option value="' . $claimantId . '"' . $selected . '>' . HelperFramework::escape((string)($claimant['claimant_name'] ?? '')) . '</option>';
        }

        return '<option value=""' . ($selectedClaimantId <= 0 ? ' selected' : '') . '>Choose Claimant...</option>' . $options;
    }

    private function selectedHeatmapPeriod(array $accountingPeriods, int $companyAccountingPeriodId): ?array
    {
        $normalisedPeriods = $this->normalisedHeatmapPeriods($accountingPeriods);

        foreach ($normalisedPeriods as $period) {
            if ($companyAccountingPeriodId > 0 && (int)$period['id'] === $companyAccountingPeriodId) {
                return $period;
            }
        }

        return null;
    }

    private function normaliseHeatmapDate(string $date, string $periodStart, string $periodEnd): string
    {
        $date = $this->normaliseDate($date);
        if ($date === '') {
            return '';
        }

        return $date >= $periodStart && $date <= $periodEnd ? $date : '';
    }

    private function normalisedHeatmapPeriods(array $accountingPeriods): array
    {
        $periods = [];

        foreach ($accountingPeriods as $period) {
            if (!is_array($period)) {
                continue;
            }

            $start = $this->normaliseDate((string)($period['period_start'] ?? ''));
            $end = $this->normaliseDate((string)($period['period_end'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }

            if ($end < $start) {
                [$start, $end] = [$end, $start];
            }

            $label = trim((string)($period['label'] ?? ''));
            $periods[] = [
                'id' => (int)($period['id'] ?? 0),
                'label' => $label !== '' ? $label : $start . ' to ' . $end,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $periods;
    }

    private function normaliseDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $parsed->format('Y-m-d');
    }

    private function claimHeatmapDate(array $claim): string
    {
        $date = trim((string)($claim['expense_date'] ?? ''));
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed instanceof DateTimeImmutable && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d');
            }
        }

        $date = trim((string)($claim['period_start'] ?? ''));
        if ($date !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed instanceof DateTimeImmutable && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d');
            }
        }

        $year = (int)($claim['claim_year'] ?? 0);
        $month = (int)($claim['claim_month'] ?? 0);
        if ($year < 1900 || $year > 2200 || $month < 1 || $month > 12) {
            return '';
        }

        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function statusFilterOptions(): array
    {
        return ['all' => 'All', 'draft' => 'Draft', 'posted' => 'Posted'];
    }

    private function claimStatusHtml(array $claim): string
    {
        return '<span class="badge ' . ((string)($claim['status'] ?? '') === 'posted' ? 'success' : 'warning') . '">'
            . HelperFramework::escape($this->claimStatusLabel($claim))
            . '</span>';
    }

    private function claimStatusLabel(array $claim): string
    {
        return HelperFramework::labelFromKey((string)($claim['status'] ?? ''), '_');
    }

    private function monthLabel(int $month, int $year): string
    {
        if ($month < 1 || $month > 12 || $year <= 0) {
            return '';
        }

        return $this->monthName($month) . ' ' . (string)$year;
    }

    private function monthName(int $month): string
    {
        $names = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        return (string)($names[$month] ?? '');
    }

    private function tableInvalidationFact(): string
    {
        return (string)($this->invalidationFacts()[0] ?? 'expenses.state');
    }
}
