<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Applies the period-start micro-entity size thresholds for the supported profile. */
final class IxbrlMicroEntityEligibilityService
{
    /** @return array<string, mixed> */
    public function evaluate(
        string $periodStart,
        string $periodEnd,
        float $turnover,
        float $balanceSheetTotal,
        int $employees
    ): array
    {
        $start = new \DateTimeImmutable($periodStart);
        $end = new \DateTimeImmutable($periodEnd);
        if ($end < $start) {
            throw new \InvalidArgumentException('Accounting period end must not precede its start.');
        }
        $inclusiveDays = (int)$start->diff($end)->days + 1;
        $normalAnniversaryEnd = $start->modify('+1 year')->modify('-1 day');
        $turnoverFactor = $end->format('Y-m-d') === $normalAnniversaryEnd->format('Y-m-d')
            ? 1.0
            : $inclusiveDays / 365;
        $ruleService = new TaxRateRuleService();
        $rules = [];
        foreach (['turnover', 'balance_sheet_total', 'employees'] as $key) {
            $rule = $ruleService->fetchRuleForDate('company_size', 'frs105_micro_entity', $key, $periodStart);
            if (!is_array($rule) || $rule['amount_value'] === null) {
                return [
                    'thresholds_available' => false,
                    'threshold_error' => 'FRS 105 size thresholds are unavailable for the accounting-period start date.',
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'period_days' => $inclusiveDays,
                    'turnover_threshold_factor' => $turnoverFactor,
                    'metrics' => [
                        'turnover' => round(abs($turnover), 2),
                        'balance_sheet_total' => round(abs($balanceSheetTotal), 2),
                        'employees' => max(0, $employees),
                    ],
                    'passes' => [],
                    'pass_count' => 0,
                    'qualifies' => false,
                ];
            }
            $rules[$key] = $rule;
        }
        $baseThresholds = [
            'turnover' => (float)$rules['turnover']['amount_value'],
            'balance_sheet_total' => (float)$rules['balance_sheet_total']['amount_value'],
            'employees' => (int)$rules['employees']['amount_value'],
        ];
        $thresholds = $baseThresholds;
        $thresholds['turnover'] = round($baseThresholds['turnover'] * $turnoverFactor, 2);
        $metrics = [
            'turnover' => round(abs($turnover), 2),
            'balance_sheet_total' => round(abs($balanceSheetTotal), 2),
            'employees' => max(0, $employees),
        ];
        $passes = [
            'turnover' => $metrics['turnover'] <= $thresholds['turnover'],
            'balance_sheet_total' => $metrics['balance_sheet_total'] <= $thresholds['balance_sheet_total'],
            'employees' => $metrics['employees'] <= $thresholds['employees'],
        ];
        $passCount = count(array_filter($passes));

        return [
            'thresholds_available' => true,
            'law_effective_from' => (string)($rules['turnover']['period_start'] ?? $periodStart),
            'threshold_source' => (string)($rules['turnover']['source_url'] ?? ''),
            'threshold_source_checked_at' => (string)($rules['turnover']['source_checked_at'] ?? ''),
            'threshold_effective_period' => [
                'start' => (string)($rules['turnover']['period_start'] ?? ''),
                'end' => (string)($rules['turnover']['period_end'] ?? ''),
            ],
            'threshold_rule_versions' => array_map(
                static fn(array $rule): string => (string)($rule['rule_version'] ?? ''),
                $rules
            ),
            'period_days' => $inclusiveDays,
            'turnover_threshold_factor' => $turnoverFactor,
            'base_thresholds' => $baseThresholds,
            'thresholds' => $thresholds,
            'metrics' => $metrics,
            'passes' => $passes,
            'pass_count' => $passCount,
            'qualifies' => $passCount === 3,
        ];
    }

    public function detail(array $result): string
    {
        if (empty($result['thresholds_available'])) {
            return (string)($result['threshold_error'] ?? 'FRS 105 size thresholds are unavailable.');
        }
        $thresholds = (array)($result['thresholds'] ?? []);
        $baseThresholds = (array)($result['base_thresholds'] ?? []);
        $metrics = (array)($result['metrics'] ?? []);
        $passes = (array)($result['passes'] ?? []);
        $format = static fn(float $value): string => '£' . number_format($value, 2, '.', ',');

        return sprintf(
            'Period-start thresholds: turnover %s / %s applied (base %s, %d-day period) (%s); balance sheet total %s / %s (%s); employees %d / %d (%s). %d of 3 tests passed; all 3 tests are required.',
            $format((float)($metrics['turnover'] ?? 0)),
            $format((float)($thresholds['turnover'] ?? 0)),
            $format((float)($baseThresholds['turnover'] ?? 0)),
            (int)($result['period_days'] ?? 0),
            !empty($passes['turnover']) ? 'pass' : 'fail',
            $format((float)($metrics['balance_sheet_total'] ?? 0)),
            $format((float)($thresholds['balance_sheet_total'] ?? 0)),
            !empty($passes['balance_sheet_total']) ? 'pass' : 'fail',
            (int)($metrics['employees'] ?? 0),
            (int)($thresholds['employees'] ?? 0),
            !empty($passes['employees']) ? 'pass' : 'fail',
            (int)($result['pass_count'] ?? 0)
        );
    }
}
