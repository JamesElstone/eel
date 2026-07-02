<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_claims extends PageContextFramework
{
    public function id(): string
    {
        return 'expense_claims';
    }

    public function title(): string
    {
        return 'Expense Claims';
    }

    public function subtitle(): string
    {
        return 'Manage expense claims, supporting receipts, and the expense workspace for the selected company.';
    }

    public function cards(): array
    {
        return ['expense_statistics', 'expense_claimants', 'expense_add_claimant', 'expense_claim_create', 'expenses_state', 'expense_claim_editor'];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Summary',
                'cards' => [
                    'expense_statistics',
                ],
            ],
            [
                'tab' => 'Claimants',
                'cards' => [
                    'expense_claimants',
                    'expense_add_claimant',
                ],
            ],
            [
                'tab' => 'Claims',
                'cards' => [
                    'expenses_state',
                    'expense_claim_create',
                ],
            ],
            [
                'tab' => 'Editor',
                'cards' => [
                    'expense_claim_editor',
                ],
            ],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $expenseFilters = (array)($actionResult->context()['expense_filters'] ?? []);

        if ($expenseFilters === []) {
            $submittedHeatmapClaimantId = $request->input('claimant_id', null);
            $expenseFilters = [
                'query' => trim((string)$request->input('query', $request->input('expense_query', ''))),
                'status' => trim((string)$request->input('status', $request->input('expense_status', 'all'))),
                'claim_id' => max(0, (int)$request->input('claim_id', 0)),
                'claim_reference_code' => trim((string)$request->input('claim_reference_code', '')),
                'payment_query' => trim((string)$request->input('payment_query', '')),
                'heatmap_claimant_id' => max(0, (int)($submittedHeatmapClaimantId !== null ? $submittedHeatmapClaimantId : $request->input('expense_heatmap_claimant_id', 0))),
                'heatmap_period_start' => $this->normaliseHeatmapPeriodStart((string)$request->input('expense_heatmap_period_start', '')),
                'heatmap_date' => trim((string)$request->input('expense_heatmap_date', '')),
            ];
        }

        $expenseFilters = array_filter(
            $expenseFilters,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0
        );
        $expenseFilters = array_merge($expenseFilters, $this->selectedAccountingPeriodFilters($baseContext));

        return [
            'expense_filters' => $expenseFilters,
            'expense_page_settings' => $this->expensePageSettings((array)($baseContext['company']['settings'] ?? [])),
        ];
    }

    private function expensePageSettings(array $settings): array
    {
        $directorLoanNominalId = $this->settingId($settings, 'director_loan_liability_nominal_id');
        if ($directorLoanNominalId <= 0) {
            $directorLoanNominalId = $this->settingId($settings, 'director_loan_nominal_id');
        }

        return [
            'incorporation_date' => (string)($settings['incorporation_date'] ?? ''),
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
            'default_currency_symbol' => (string)($settings['default_currency_symbol'] ?? '&#163;'),
            'director_loan_nominal_id' => $directorLoanNominalId,
            'default_bank_nominal_id' => $this->settingId($settings, 'default_bank_nominal_id'),
            'default_expense_nominal_id' => $this->settingId($settings, 'default_expense_nominal_id'),
        ];
    }

    private function selectedAccountingPeriodFilters(array $context): array
    {
        $company = (array)($context['company'] ?? []);
        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        $accountingPeriodId = max(0, (int)($accountingPeriod['id'] ?? $company['accounting_period_id'] ?? 0));
        $filters = [];

        if ($accountingPeriodId > 0) {
            $filters['accounting_period_id'] = $accountingPeriodId;
        }

        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));
        if ($this->isValidDate($periodStart) && $this->isValidDate($periodEnd)) {
            $filters['accounting_period_start'] = $periodStart;
            $filters['accounting_period_end'] = $periodEnd;
        }

        return $filters;
    }

    private function normaliseHeatmapPeriodStart(string $date): string
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

    private function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();

        return $parsed instanceof DateTimeImmutable
            && $parsed->format('Y-m-d') === $date
            && (!is_array($errors) || ((int)($errors['warning_count'] ?? 0) === 0 && (int)($errors['error_count'] ?? 0) === 0));
    }

    private function settingId(array $settings, string $key): int
    {
        $value = trim((string)($settings[$key] ?? ''));

        return $value !== '' ? (int)$value : 0;
    }
}
