<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountingPeriodCoverageService
{
    public function summarise(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array {
        if ($companyId <= 0 || $periodStart === '' || $periodEnd === '') {
            return [
                'months' => [],
                'missing_months' => [],
                'total_txn_count' => 0,
                'outside_period_count' => 0,
            ];
        }

        $monthMap = $this->emptyMonthMap($periodStart, $periodEnd, $companyId);
        foreach (InterfaceDB::fetchAll( "SELECT DATE_FORMAT(txn_date, '%Y-%m-01') AS month_key, COUNT(*) AS txn_count
             FROM transactions
             WHERE company_id = ?
               AND txn_date BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(txn_date, '%Y-%m-01')
             ORDER BY month_key", [$companyId, $periodStart, $periodEnd]) as $row) {
            $monthKey = (string)$row['month_key'];

            if (!isset($monthMap[$monthKey])) {
                continue;
            }

            $monthMap[$monthKey]['txn_count'] = (int)$row['txn_count'];
        }

        $outsidePeriodCount = 0;

        if ($accountingPeriodId > 0) {
            $outsidePeriodCount = (int)InterfaceDB::fetchColumn( 'SELECT COUNT(*)
                 FROM transactions
                 WHERE company_id = ?
                   AND accounting_period_id = ?
                   AND (txn_date < ? OR txn_date > ?)', [$companyId, $accountingPeriodId, $periodStart, $periodEnd]);
        }

        $missingMonths = [];

        foreach ($monthMap as $month) {
            if ($month['txn_count'] === 0) {
                $missingMonths[] = $month['label'];
            }
        }

        return [
            'months' => array_values($monthMap),
            'missing_months' => $missingMonths,
            'total_txn_count' => array_sum(array_column($monthMap, 'txn_count')),
            'outside_period_count' => $outsidePeriodCount,
        ];
    }

    private function emptyMonthMap(
        string $periodStart,
        string $periodEnd,
        int $companyId
    ): array {
        $months = [];
        $cursor = new DateTimeImmutable($periodStart);
        $cursor = $cursor->modify('first day of this month');
        $end = (new DateTimeImmutable($periodEnd))->modify('first day of this month');

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-01');
            $months[$key] = [
                'month_key' => $key,
                'label' => HelperFramework::displayMonthYear($cursor),
                'txn_count' => 0,
            ];

            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }
}
