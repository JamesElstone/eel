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
    public function key(): string
    {
        return 'expenses_state';
    }

    public function services(): array
    {
        return [
            [
                'key' => 'expensesPageData',
                'service' => ExpenseClaimService::class,
                'method' => 'fetchPageData',
                'params' => [
                    'companyId' => ':company_id',
                    'filters' => ':expense_filters',
                ],
            ],
        ];
    }

    public function title(): string
    {
        return 'Expense Claims';
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
        $companySettings = (array)($context['expense_page_settings'] ?? $company['settings'] ?? []);
        $claimants = (array)($data['claimants'] ?? []);
        $activeClaimantCount = (int)($data['active_claimant_count'] ?? 0);
        $claims = (array)($data['claims'] ?? []);
        $filters = (array)($data['filters'] ?? []);
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');

        return '
            <div id="expenses-app">
                <div class="settings-stack">
                    ' . $this->renderClaimsPanel($claims, $claimants, $activeClaimantCount, $filters, $currentYear, $currentMonth, $companySettings, $companyId) . '
                </div>
            </div>';
    }

    private function renderClaimsPanel(
        array $claims,
        array $claimants,
        int $activeClaimantCount,
        array $filters,
        int $currentYear,
        int $currentMonth,
        array $companySettings,
        int $companyId
    ): string {
        $createDisabled = $activeClaimantCount <= 0;
        $createFormId = 'expense-create-claim-form';
        $searchFormId = 'expense-search-form';
        $query = (string)($filters['query'] ?? '');
        $status = (string)($filters['status'] ?? 'all');

        return '<section class="panel-soft">
            <div class="status-head">
                <h3 class="card-title">Claims</h3>
            </div>
            <div class="helper">' . ($createDisabled
                ? 'Create Expense Claim is disabled because there are no active claimants.'
                : 'Create or open a monthly expense claim for an active claimant.') . '</div>
            <form id="' . $createFormId . '" method="post" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="create_claim">
                <input type="hidden" name="incorporation_date" value="' . HelperFramework::escape((string)($companySettings['incorporation_date'] ?? '')) . '">
            </form>
            <div class="toolbar">
                <div class="mini-field">
                    <label for="expense-create-claimant">Claimant</label>
                    <select class="select" id="expense-create-claimant" name="claimant_id" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->claimantOptions($claimants, true) . '</select>
                </div>
                <div class="mini-field">
                    <label for="expense-create-year">Year</label>
                    <select class="select" id="expense-create-year" name="claim_year" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->yearOptions($currentYear) . '</select>
                </div>
                <div class="mini-field">
                    <label for="expense-create-month">Month</label>
                    <select class="select" id="expense-create-month" name="claim_month" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->monthOptions($currentMonth) . '</select>
                </div>
                <button class="button primary" type="submit" form="' . $createFormId . '" data-show-card="expense_claim_editor"' . ($createDisabled ? ' disabled' : '') . '>Create Expense Claim</button>
            </div>
            <form id="' . $searchFormId . '" method="get" action="?page=expenses" data-ajax="true">
                <input type="hidden" name="page" value="expenses">
                <input type="hidden" name="card_action" value="Expense">
                <input type="hidden" name="company_id" value="' . $companyId . '">
                <input type="hidden" name="intent" value="filter_claims">
            </form>
            <div class="toolbar">
                <div class="mini-field">
                    <label for="expense-search-query">Search reference</label>
                    <input class="input" id="expense-search-query" name="expense_query" form="' . $searchFormId . '" type="search" value="' . HelperFramework::escape($query) . '" placeholder="EXP-...">
                </div>
                <div class="mini-field">
                    <label for="expense-search-status">Status</label>
                    <select class="select" id="expense-search-status" name="expense_status" form="' . $searchFormId . '">' . $this->statusOptions($status) . '</select>
                </div>
                <button class="button" type="submit" form="' . $searchFormId . '">Search</button>
            </div>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Reference</th><th>Claimant</th><th>Month</th><th>A</th><th>B</th><th>C</th><th>D</th><th>Status</th><th>Updated</th><th></th></tr></thead>
                    <tbody>' . $this->claimRows($claims, $status, $query, $companyId) . '</tbody>
                </table>
            </div>
        </section>';
    }

    private function claimantOptions(array $claimants, bool $activeOnly): string
    {
        $options = '<option value="">Choose claimant...</option>';
        foreach ($claimants as $claimant) {
            if ($activeOnly && (int)($claimant['is_active'] ?? 0) !== 1) {
                continue;
            }

            $options .= '<option value="' . (int)($claimant['id'] ?? 0) . '">' . HelperFramework::escape((string)($claimant['claimant_name'] ?? '')) . '</option>';
        }

        return $options;
    }

    private function yearOptions(int $selectedYear): string
    {
        $html = '';
        for ($year = $selectedYear - 4; $year <= $selectedYear + 5; $year++) {
            $selected = $year === $selectedYear ? ' selected' : '';
            $html .= '<option value="' . $year . '"' . $selected . '>' . $year . '</option>';
        }

        return $html;
    }

    private function monthOptions(int $selectedMonth): string
    {
        $html = '';
        for ($month = 1; $month <= 12; $month++) {
            $selected = $month === $selectedMonth ? ' selected' : '';
            $html .= '<option value="' . $month . '"' . $selected . '>' . HelperFramework::escape($this->monthName($month)) . '</option>';
        }

        return $html;
    }

    private function statusOptions(string $selectedStatus): string
    {
        $html = '';
        foreach (['all' => 'All', 'draft' => 'Draft', 'posted' => 'Posted'] as $value => $label) {
            $selected = $value === $selectedStatus ? ' selected' : '';
            $html .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
        }

        return $html;
    }

    private function claimRows(array $claims, string $status, string $query, int $companyId): string
    {
        $rows = '';
        foreach ($claims as $claim) {
            $claimId = (int)($claim['id'] ?? 0);
            $rows .= '<tr>
                <td>' . HelperFramework::escape((string)($claim['claim_reference_code'] ?? '')) . '</td>
                <td>' . HelperFramework::escape((string)($claim['claimant_name'] ?? '')) . '</td>
                <td>' . HelperFramework::escape($this->monthLabel((int)($claim['claim_month'] ?? 0), (int)($claim['claim_year'] ?? 0))) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($claim['A'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($claim['B'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($claim['C'] ?? 0)) . '</td>
                <td>' . HelperFramework::escape(FormattingFramework::money($claim['D'] ?? 0)) . '</td>
                <td><span class="badge ' . ((string)($claim['status'] ?? '') === 'posted' ? 'success' : 'warning') . '">' . HelperFramework::escape(HelperFramework::labelFromKey((string)($claim['status'] ?? ''), '_')) . '</span></td>
                <td>' . HelperFramework::escape((string)($claim['last_updated'] ?? '')) . '</td>
                <td>
                    <form method="post" action="?page=expenses" data-ajax="true">
                        <input type="hidden" name="card_action" value="Expense">
                        <input type="hidden" name="company_id" value="' . $companyId . '">
                        <input type="hidden" name="intent" value="select_claim">
                        <input type="hidden" name="claim_id" value="' . $claimId . '">
                        <input type="hidden" name="expense_status" value="' . HelperFramework::escape($status) . '">
                        <input type="hidden" name="expense_query" value="' . HelperFramework::escape($query) . '">
                        <button class="button button-inline" type="submit" data-show-card="expense_claim_editor">Open</button>
                    </form>
                </td>
            </tr>';
        }

        return $rows !== '' ? $rows : '<tr><td colspan="10" class="helper">No expense claims were found.</td></tr>';
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
}
