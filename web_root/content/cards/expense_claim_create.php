<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _expense_claim_createCard extends CardBaseFramework
{
    public function key(): string
    {
        return 'expense_claim_create';
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
        return 'Create Expense Claim';
    }

    protected function additionalInvalidationFacts(): array
    {
        return ['expense.claimants'];
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
        $accountingPeriod = (array)($context['accounting_period'] ?? []);
        $claimants = (array)($data['claimants'] ?? []);
        $activeClaimantCount = (int)($data['active_claimant_count'] ?? 0);
        $createDisabled = $activeClaimantCount <= 0;
        $createFormId = 'expense-create-claim-form';
        $claimPeriodDefaults = $this->claimPeriodDefaults($accountingPeriod);

        return '<div class="expense-claims-stack">
        <form id="' . $createFormId . '" method="post" action="?page=expenses" data-ajax="true">
            <input type="hidden" name="card_action" value="Expense">
            <input type="hidden" name="company_id" value="' . $companyId . '">
            <input type="hidden" name="intent" value="create_claim">
        </form>
        ' . ($createDisabled ? '<div class="helper">Create Expense Claim is disabled because there are no active claimants.</div>' : '') . '
        <div class="create-expense-claim">
                <div class="mini-field">
                    <label for="expense-create-claimant">Claimant</label>
                    <select class="select" id="expense-create-claimant" name="claimant_id" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->claimantOptions($claimants, $createDisabled ? 'Add a claimant first...' : 'Choose claimant...') . '</select>
                </div>
                <div class="mini-field">
                    <label for="expense-create-year">Year</label>
                    <select class="select" id="expense-create-year" name="claim_year" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->yearOptions((int)$claimPeriodDefaults['year'], (string)($companySettings['incorporation_date'] ?? ''), $accountingPeriod) . '</select>
                </div>
                <div class="mini-field">
                    <label for="expense-create-month">Month</label>
                    <select class="select" id="expense-create-month" name="claim_month" form="' . $createFormId . '"' . ($createDisabled ? ' disabled' : '') . '>' . $this->monthOptions((int)$claimPeriodDefaults['month']) . '</select>
                </div>
                <button class="button primary" type="submit" form="' . $createFormId . '" data-show-card="expense_claim_editor"' . ($createDisabled ? ' disabled' : '') . '>Create Expense Claim</button>
        </div>
        </div>';
    }

    private function claimantOptions(array $claimants, string $emptyLabel): string
    {
        $options = '';
        $optionCount = 0;
        foreach ($claimants as $claimant) {
            if (!is_array($claimant) || (int)($claimant['is_active'] ?? 0) !== 1) {
                continue;
            }

            $options .= '<option value="' . (int)($claimant['id'] ?? 0) . '">' . HelperFramework::escape((string)($claimant['claimant_name'] ?? '')) . '</option>';
            $optionCount++;
        }

        if ($optionCount === 0) {
            return '<option value="" selected>' . HelperFramework::escape($emptyLabel) . '</option>';
        }

        return '<option value="">' . HelperFramework::escape($emptyLabel) . '</option>' . $options;
    }

    private function claimPeriodDefaults(array $accountingPeriod): array
    {
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n');
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if (!$this->validDate($periodStart) || !$this->validDate($periodEnd)) {
            return ['year' => $currentYear, 'month' => $currentMonth];
        }

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        if ($today >= $periodStart && $today <= $periodEnd) {
            return ['year' => $currentYear, 'month' => $currentMonth];
        }

        $startDate = new DateTimeImmutable($periodStart);
        return [
            'year' => (int)$startDate->format('Y'),
            'month' => (int)$startDate->format('n'),
        ];
    }

    private function yearOptions(int $selectedYear, string $incorporationDate, array $accountingPeriod): string
    {
        $html = '';
        $years = $this->accountingPeriodYears($accountingPeriod);

        if ($years === []) {
            $firstYear = $this->firstClaimYear($selectedYear, $incorporationDate);
            $years = range($firstYear, $selectedYear);
        }

        foreach ($years as $year) {
            $selected = $year === $selectedYear ? ' selected' : '';
            $html .= '<option value="' . $year . '"' . $selected . '>' . $year . '</option>';
        }

        return $html;
    }

    private function accountingPeriodYears(array $accountingPeriod): array
    {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if (!$this->validDate($periodStart) || !$this->validDate($periodEnd)) {
            return [];
        }

        $startYear = (int)(new DateTimeImmutable($periodStart))->format('Y');
        $endYear = (int)(new DateTimeImmutable($periodEnd))->format('Y');
        if ($endYear < $startYear) {
            return [];
        }

        return range($startYear, $endYear);
    }

    private function firstClaimYear(int $currentYear, string $incorporationDate): int
    {
        if (!$this->validDate($incorporationDate)) {
            return $currentYear - 4;
        }

        $incorporatedAt = new DateTimeImmutable($incorporationDate);
        return min((int)$incorporatedAt->format('Y'), $currentYear);
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

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value
            && (!is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
