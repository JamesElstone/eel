<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Pure, integer-pence prepayment calculator.
 *
 * Recognition is calculated cumulatively at each boundary and adjacent
 * cumulative values are subtracted.  This deliberately avoids distributing a
 * rounded daily rate, which can otherwise leave a penny residual.
 */
final class PrepaymentAllocationService
{
    /**
     * @param list<array<string, mixed>> $accountingPeriods
     * @return array<string, mixed>
     */
    public function calculateSchedule(
        int $totalPence,
        string $serviceStartDate,
        string $serviceEndDate,
        array $accountingPeriods
    ): array {
        if ($totalPence <= 0) {
            throw new \InvalidArgumentException('The prepayment amount must be greater than zero.');
        }

        $serviceStart = $this->date($serviceStartDate, 'service start');
        $serviceEnd = $this->date($serviceEndDate, 'service end');
        if ($serviceEnd < $serviceStart) {
            throw new \InvalidArgumentException('The service end date must be on or after the service start date.');
        }

        $totalDays = $this->inclusiveDays($serviceStart, $serviceEnd);
        $periods = $this->normalisePeriods($accountingPeriods);
        $allocations = [];
        $allocatedPence = 0;

        foreach ($periods as $period) {
            $periodStart = $period['period_start_date'];
            $periodEnd = $period['period_end_date'];
            $overlapStart = $periodStart > $serviceStart ? $periodStart : $serviceStart;
            $overlapEnd = $periodEnd < $serviceEnd ? $periodEnd : $serviceEnd;
            if ($overlapEnd < $overlapStart) {
                if (!empty($period['force_source_deferral']) && $periodEnd < $serviceStart) {
                    $allocations[] = [
                        'accounting_period_id' => $period['accounting_period_id'],
                        'period_start' => $periodStart->format('Y-m-d'),
                        'period_end' => $periodEnd->format('Y-m-d'),
                        'overlap_start' => null,
                        'overlap_end' => null,
                        'overlap_days' => 0,
                        'expense_pence' => 0,
                        'recognised_through_pence' => 0,
                        'opening_deferred_pence' => 0,
                        'closing_deferred_pence' => $totalPence,
                        'is_source_period' => !empty($period['is_source_period']),
                        'allocation_hash' => hash('sha256', implode('|', [
                            (string)$period['accounting_period_id'],
                            $periodStart->format('Y-m-d'),
                            $periodEnd->format('Y-m-d'),
                            '',
                            '',
                            '0',
                            (string)$totalPence,
                            !empty($period['is_source_period']) ? '1' : '0',
                        ])),
                    ];
                }
                continue;
            }

            $beforeOverlap = $overlapStart->modify('-1 day');
            $recognisedBefore = $this->recognisedThrough($totalPence, $totalDays, $serviceStart, $serviceEnd, $beforeOverlap);
            $recognisedThrough = $this->recognisedThrough($totalPence, $totalDays, $serviceStart, $serviceEnd, $overlapEnd);
            $expensePence = $recognisedThrough - $recognisedBefore;
            $allocatedPence += $expensePence;

            $allocations[] = [
                'accounting_period_id' => $period['accounting_period_id'],
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                'overlap_start' => $overlapStart->format('Y-m-d'),
                'overlap_end' => $overlapEnd->format('Y-m-d'),
                'overlap_days' => $this->inclusiveDays($overlapStart, $overlapEnd),
                'expense_pence' => $expensePence,
                'recognised_through_pence' => $recognisedThrough,
                'opening_deferred_pence' => !empty($period['is_source_period'])
                    ? 0
                    : $totalPence - $recognisedBefore,
                'closing_deferred_pence' => $totalPence - $recognisedThrough,
                'is_source_period' => !empty($period['is_source_period']),
                'allocation_hash' => hash('sha256', implode('|', [
                    (string)$period['accounting_period_id'],
                    $periodStart->format('Y-m-d'),
                    $periodEnd->format('Y-m-d'),
                    $overlapStart->format('Y-m-d'),
                    $overlapEnd->format('Y-m-d'),
                    (string)$expensePence,
                    (string)($totalPence - $recognisedThrough),
                    !empty($period['is_source_period']) ? '1' : '0',
                ])),
            ];
        }

        return [
            'total_pence' => $totalPence,
            'total_days' => $totalDays,
            'service_start_date' => $serviceStart->format('Y-m-d'),
            'service_end_date' => $serviceEnd->format('Y-m-d'),
            'allocations' => $allocations,
            'allocated_pence' => $allocatedPence,
            'unallocated_pence' => max(0, $totalPence - $allocatedPence),
        ];
    }

    public function recognisedThroughDate(
        int $totalPence,
        string $serviceStartDate,
        string $serviceEndDate,
        string $throughDate
    ): int {
        if ($totalPence <= 0) {
            throw new \InvalidArgumentException('The prepayment amount must be greater than zero.');
        }

        $start = $this->date($serviceStartDate, 'service start');
        $end = $this->date($serviceEndDate, 'service end');
        if ($end < $start) {
            throw new \InvalidArgumentException('The service end date must be on or after the service start date.');
        }

        return $this->recognisedThrough(
            $totalPence,
            $this->inclusiveDays($start, $end),
            $start,
            $end,
            $this->date($throughDate, 'recognition cutoff')
        );
    }

    /** @param list<array<string, mixed>> $periods */
    private function normalisePeriods(array $periods): array
    {
        $normalised = [];
        foreach ($periods as $period) {
            $accountingPeriodId = (int)($period['accounting_period_id'] ?? $period['id'] ?? 0);
            $periodStart = $this->date((string)($period['period_start'] ?? $period['start'] ?? ''), 'accounting period start');
            $periodEnd = $this->date((string)($period['period_end'] ?? $period['end'] ?? ''), 'accounting period end');
            if ($accountingPeriodId <= 0 || $periodEnd < $periodStart) {
                throw new \InvalidArgumentException('Every accounting period needs a valid ID and date range.');
            }
            $normalised[] = [
                'accounting_period_id' => $accountingPeriodId,
                'period_start_date' => $periodStart,
                'period_end_date' => $periodEnd,
                'force_source_deferral' => !empty($period['force_source_deferral']),
                'is_source_period' => !empty($period['is_source_period']),
            ];
        }

        usort($normalised, static fn(array $left, array $right): int => [
            $left['period_start_date']->format('Y-m-d'),
            $left['accounting_period_id'],
        ] <=> [
            $right['period_start_date']->format('Y-m-d'),
            $right['accounting_period_id'],
        ]);

        $previousEnd = null;
        foreach ($normalised as $period) {
            if ($previousEnd instanceof \DateTimeImmutable && $period['period_start_date'] <= $previousEnd) {
                throw new \InvalidArgumentException('Accounting periods used for a prepayment schedule must not overlap.');
            }
            $previousEnd = $period['period_end_date'];
        }

        return $normalised;
    }

    private function recognisedThrough(
        int $totalPence,
        int $totalDays,
        \DateTimeImmutable $serviceStart,
        \DateTimeImmutable $serviceEnd,
        \DateTimeImmutable $through
    ): int {
        if ($through < $serviceStart) {
            return 0;
        }
        if ($through >= $serviceEnd) {
            return $totalPence;
        }

        $elapsedDays = $this->inclusiveDays($serviceStart, $through);
        return $this->roundHalfUpRatio($totalPence, $elapsedDays, $totalDays);
    }

    private function roundHalfUpRatio(int $amount, int $numerator, int $denominator): int
    {
        if ($amount < 0 || $numerator < 0 || $denominator <= 0) {
            throw new \InvalidArgumentException('A positive ratio is required for prepayment apportionment.');
        }

        return intdiv(($amount * $numerator * 2) + $denominator, $denominator * 2);
    }

    private function inclusiveDays(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int)$start->diff($end)->format('%a') + 1;
    }

    private function date(string $value, string $label): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException('Enter a valid ' . $label . ' date.');
        }

        return $date;
    }
}
