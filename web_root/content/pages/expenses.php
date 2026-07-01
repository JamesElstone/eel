<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expenses extends PageContextFramework
{
    public function id(): string
    {
        return 'expenses';
    }

    public function title(): string
    {
        return 'Expenses';
    }

    public function subtitle(): string
    {
        return 'Manage expense claims, supporting receipts, and the expense workspace for the selected company.';
    }

    public function cards(): array
    {
        return ['expense_claimants', 'expenses_state', 'expense_claim_editor'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $expenseFilters = (array)($actionResult->context()['expense_filters'] ?? []);

        if ($expenseFilters === []) {
            $expenseFilters = [
                'query' => trim((string)$request->input('query', $request->input('expense_query', ''))),
                'status' => trim((string)$request->input('status', $request->input('expense_status', 'all'))),
                'claim_id' => max(0, (int)$request->input('claim_id', 0)),
                'claim_reference_code' => trim((string)$request->input('claim_reference_code', '')),
                'payment_query' => trim((string)$request->input('payment_query', '')),
                'heatmap_claimant_id' => max(0, (int)$request->input('expense_heatmap_claimant_id', 0)),
                'heatmap_period_start' => $this->normaliseHeatmapPeriodStart((string)$request->input('expense_heatmap_period_start', '')),
                'heatmap_date' => trim((string)$request->input('expense_heatmap_date', '')),
            ];
        }

        $expenseFilters = array_filter(
            $expenseFilters,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0
        );

        return [
            'expense_filters' => $expenseFilters,
            'expense_page_settings' => $this->expensePageSettings((array)($baseContext['company']['settings'] ?? [])),
            'expense_bulk_preview_input' => [
                'claim_id' => max(0, (int)$request->input('claim_id', 0)),
                'pasted_lines' => (string)$request->input('pasted_lines', ''),
                'date_format' => (string)$request->input('date_format', (string)(($baseContext['company']['settings'] ?? [])['date_format'] ?? 'd/m/Y')),
            ],
        ];
    }

    private function expensePageSettings(array $settings): array
    {
        return [
            'incorporation_date' => (string)($settings['incorporation_date'] ?? ''),
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
            'director_loan_nominal_id' => $this->settingId($settings, 'director_loan_nominal_id'),
            'default_bank_nominal_id' => $this->settingId($settings, 'default_bank_nominal_id'),
            'default_expense_nominal_id' => $this->settingId($settings, 'default_expense_nominal_id'),
        ];
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

    private function settingId(array $settings, string $key): int
    {
        $value = trim((string)($settings[$key] ?? ''));

        return $value !== '' ? (int)$value : 0;
    }
}
