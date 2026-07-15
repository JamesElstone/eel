<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class VatTurnoverMonitoringService
{
    public function __construct(
        private readonly ?VatThresholdRuleService $thresholdRules = null,
    ) {
    }

    /**
     * $asAtDate is an injectable clock value for deterministic previews/tests; null means the current local date.
     */
    public function fetchMonitoring(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->unavailable('Select a company and accounting period to monitor gross income.');
        }

        $period = (new \eel_accounts\Repository\AccountingPeriodRepository())
            ->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->unavailable('The selected accounting period could not be found.');
        }

        try {
            $periodStart = new \DateTimeImmutable((string)$period['period_start']);
            $periodEnd = new \DateTimeImmutable((string)$period['period_end']);
            $requestedAsAt = new \DateTimeImmutable($asAtDate !== null && trim($asAtDate) !== '' ? $asAtDate : 'today');
        } catch (\Throwable) {
            return $this->unavailable('The accounting period or monitoring date is invalid.');
        }

        $effective = $requestedAsAt < $periodEnd ? $requestedAsAt : $periodEnd;
        $thresholdDate = $effective < $periodStart ? $periodStart : $effective;
        $threshold = ($this->thresholdRules ?? new VatThresholdRuleService())->fetchForDate(
            $thresholdDate->format('Y-m-d'),
            VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES
        );

        if ($effective < $periodStart) {
            return [
                'available' => true,
                'not_started' => true,
                'message' => 'This accounting period has not started.',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                'effective_date' => null,
                'ap_to_date_gross_income' => 0.0,
                'trailing_12_month_gross_income' => 0.0,
                'threshold' => $threshold,
                'threshold_percentage_used' => null,
                'threshold_headroom' => null,
                'months' => [],
                'bar_points' => [],
                'rolling_points' => [],
                'threshold_points' => [],
                'coverage' => ['complete' => false, 'required_start' => '', 'required_end' => '', 'gaps' => []],
                'warnings' => $this->standardCaveats(),
                'uncategorised_positive_count' => 0,
            ];
        }

        $monthPoints = $this->monthPoints($periodStart, $effective);
        $earliestPoint = $monthPoints[0]['point_date'] ?? $effective;
        $queryStart = $earliestPoint->modify('-1 year')->modify('+1 day');
        $incomeByDate = $this->incomeByDate($companyId, $queryStart, $effective);
        $months = [];
        $barPoints = [];
        $rollingPoints = [];
        $thresholdPoints = [];

        foreach ($monthPoints as $point) {
            $monthStart = $point['month_start'] < $periodStart ? $periodStart : $point['month_start'];
            $pointDate = $point['point_date'];
            $monthlyIncome = $this->sumDateRange($incomeByDate, $monthStart, $pointDate);
            $rollingStart = $pointDate->modify('-1 year')->modify('+1 day');
            $rollingIncome = $this->sumDateRange($incomeByDate, $rollingStart, $pointDate);
            $pointThreshold = ($this->thresholdRules ?? new VatThresholdRuleService())->fetchForDate(
                $pointDate->format('Y-m-d'),
                VatThresholdRuleService::TYPE_TAXABLE_SUPPLIES
            );
            $pointRegistrationThreshold = !empty($pointThreshold['available'])
                ? (float)$pointThreshold['registration_threshold']
                : null;
            $pointCoverage = $this->coverage($companyId, $rollingStart, $pointDate);
            $label = $pointDate->format('M y');

            $months[] = [
                'month' => $point['month_start']->format('Y-m'),
                'label' => $label,
                'start_date' => $monthStart->format('Y-m-d'),
                'end_date' => $pointDate->format('Y-m-d'),
                'gross_income' => $monthlyIncome,
                'rolling_12_month_gross_income' => $rollingIncome,
                'registration_threshold' => $pointRegistrationThreshold,
                'threshold_headroom' => $pointRegistrationThreshold !== null
                    ? round($pointRegistrationThreshold - $rollingIncome, 2)
                    : null,
                'coverage_complete' => !empty($pointCoverage['complete']),
                'coverage_label' => !empty($pointCoverage['complete']) ? 'Complete' : 'Incomplete',
            ];
            $barPoints[] = ['label' => $label, 'value' => $monthlyIncome];
            $rollingPoints[] = ['label' => $label, 'value' => $rollingIncome];
            if (!empty($pointThreshold['available'])) {
                $thresholdPoints[] = ['label' => $label, 'value' => (float)$pointThreshold['registration_threshold']];
            }
        }

        $apToDate = $this->sumDateRange($incomeByDate, $periodStart, $effective);
        $trailingStart = $effective->modify('-1 year')->modify('+1 day');
        $trailingIncome = $this->sumDateRange($incomeByDate, $trailingStart, $effective);
        $registrationThreshold = !empty($threshold['available']) ? (float)$threshold['registration_threshold'] : null;
        $coverage = $this->coverage($companyId, $trailingStart, $effective);
        $uncategorisedCount = $this->uncategorisedPositiveCount($companyId, $trailingStart, $effective);
        $warnings = $this->standardCaveats();

        if (empty($threshold['available'])) {
            $warnings[] = (string)($threshold['message'] ?? 'The VAT threshold is unavailable for this date.');
        }
        if (empty($coverage['complete'])) {
            $warnings[] = 'The trailing 12-month window is not fully covered by configured accounting periods, so the comparison may be incomplete.';
        }
        if ($uncategorisedCount > 0) {
            $warnings[] = $uncategorisedCount . ' positive transaction(s) in the trailing window remain uncategorised and may not yet be represented correctly in posted income.';
        }
        if (array_filter($months, static fn(array $month): bool => (float)$month['gross_income'] < 0.0) !== []) {
            $warnings[] = 'Negative monthly income adjustments are shown exactly in the table and below the zero axis in the monthly chart.';
        }

        return [
            'available' => true,
            'not_started' => false,
            'message' => '',
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
            'effective_date' => $effective->format('Y-m-d'),
            'ap_to_date_gross_income' => $apToDate,
            'trailing_12_month_gross_income' => $trailingIncome,
            'threshold' => $threshold,
            'threshold_percentage_used' => $registrationThreshold !== null && $registrationThreshold > 0
                ? round(($trailingIncome / $registrationThreshold) * 100, 1)
                : null,
            'threshold_headroom' => $registrationThreshold !== null
                ? round($registrationThreshold - $trailingIncome, 2)
                : null,
            'months' => $months,
            'bar_points' => $barPoints,
            'rolling_points' => $rollingPoints,
            'threshold_points' => $thresholdPoints,
            'coverage' => $coverage,
            'warnings' => array_values(array_unique(array_filter($warnings))),
            'uncategorised_positive_count' => $uncategorisedCount,
        ];
    }

    private function monthPoints(\DateTimeImmutable $periodStart, \DateTimeImmutable $effective): array
    {
        $cursor = $periodStart->modify('first day of this month');
        $lastMonth = $effective->modify('first day of this month');
        $points = [];

        while ($cursor <= $lastMonth) {
            $monthEnd = $cursor->modify('last day of this month');
            $points[] = [
                'month_start' => $cursor,
                'point_date' => $monthEnd < $effective ? $monthEnd : $effective,
            ];
            $cursor = $cursor->modify('first day of next month');
        }

        return $points;
    }

    private function incomeByDate(int $companyId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $metadataExclusion = \InterfaceDB::tableExists('journal_entry_metadata')
            ? " AND NOT EXISTS (
                    SELECT 1
                      FROM journal_entry_metadata retained_close
                     WHERE retained_close.journal_id = j.id
                       AND retained_close.journal_tag = 'year_end_retained_earnings_close'
                )"
            : '';
        $rows = \InterfaceDB::fetchAll(
            'SELECT j.journal_date, jl.debit, jl.credit
               FROM journals j
               INNER JOIN journal_lines jl ON jl.journal_id = j.id
               INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
              WHERE j.company_id = :company_id
                AND j.is_posted = 1
                AND j.journal_date BETWEEN :start_date AND :end_date
                AND na.account_type = :account_type'
                . $metadataExclusion . '
              ORDER BY j.journal_date, jl.id',
            [
                'company_id' => $companyId,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'account_type' => 'income',
            ]
        );

        $byDate = [];
        foreach ($rows as $row) {
            $date = (string)($row['journal_date'] ?? '');
            if ($date === '') {
                continue;
            }
            $byDate[$date] = round(
                (float)($byDate[$date] ?? 0.0)
                + (float)($row['credit'] ?? 0)
                - (float)($row['debit'] ?? 0),
                2
            );
        }

        return $byDate;
    }

    private function sumDateRange(array $incomeByDate, \DateTimeImmutable $start, \DateTimeImmutable $end): float
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        $total = 0.0;

        foreach ($incomeByDate as $date => $amount) {
            if ($date >= $startDate && $date <= $endDate) {
                $total += (float)$amount;
            }
        }

        return round($total, 2);
    }

    private function coverage(int $companyId, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array
    {
        $company = (new \eel_accounts\Repository\CompanyRepository())->fetchCompanyDetails($companyId) ?? [];
        $requiredStart = $windowStart;
        $incorporationDate = trim((string)($company['incorporation_date'] ?? ''));
        if ($incorporationDate !== '') {
            try {
                $incorporated = new \DateTimeImmutable($incorporationDate);
                if ($incorporated > $requiredStart) {
                    $requiredStart = $incorporated;
                }
            } catch (\Throwable) {
            }
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT period_start, period_end
               FROM accounting_periods
              WHERE company_id = :company_id
                AND period_end >= :window_start
                AND period_start <= :window_end
              ORDER BY period_start, period_end',
            [
                'company_id' => $companyId,
                'window_start' => $requiredStart->format('Y-m-d'),
                'window_end' => $windowEnd->format('Y-m-d'),
            ]
        );

        $cursor = $requiredStart;
        $gaps = [];
        foreach ($periods as $period) {
            try {
                $start = new \DateTimeImmutable((string)$period['period_start']);
                $end = new \DateTimeImmutable((string)$period['period_end']);
            } catch (\Throwable) {
                continue;
            }
            if ($end < $cursor) {
                continue;
            }
            if ($start > $cursor) {
                $gapEnd = $start->modify('-1 day');
                $gaps[] = ['start' => $cursor->format('Y-m-d'), 'end' => $gapEnd->format('Y-m-d')];
            }
            $next = $end->modify('+1 day');
            if ($next > $cursor) {
                $cursor = $next;
            }
            if ($cursor > $windowEnd) {
                break;
            }
        }
        if ($cursor <= $windowEnd) {
            $gaps[] = ['start' => $cursor->format('Y-m-d'), 'end' => $windowEnd->format('Y-m-d')];
        }

        return [
            'complete' => $gaps === [],
            'required_start' => $requiredStart->format('Y-m-d'),
            'required_end' => $windowEnd->format('Y-m-d'),
            'gaps' => $gaps,
        ];
    }

    private function uncategorisedPositiveCount(int $companyId, \DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        if (!\InterfaceDB::tableExists('transactions')) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
               FROM transactions
              WHERE company_id = :company_id
                AND txn_date BETWEEN :start_date AND :end_date
                AND amount > 0
                AND category_status = :category_status',
            [
                'company_id' => $companyId,
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'category_status' => 'uncategorised',
            ]
        );
    }

    private function standardCaveats(): array
    {
        return [
            'Posted accounting income is only a proxy for VAT-taxable turnover because exempt and out-of-scope income classification is not implemented.',
            'The legal test for taxable turnover expected in the next 30 days cannot be inferred from historic journals.',
        ];
    }

    private function unavailable(string $message): array
    {
        return [
            'available' => false,
            'not_started' => false,
            'message' => $message,
            'months' => [],
            'warnings' => [],
        ];
    }
}
