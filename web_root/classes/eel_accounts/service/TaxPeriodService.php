<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class TaxPeriodService
{
    private const ACCOUNTING_PERIOD_LABEL_FORMAT = 'd/m/Y';

    public static function accountingPeriodLabel(
        \DateTimeInterface|string $periodStart,
        \DateTimeInterface|string $periodEnd
    ): string {
        $start = $periodStart instanceof \DateTimeInterface ? $periodStart : new \DateTimeImmutable((string)$periodStart);
        $end = $periodEnd instanceof \DateTimeInterface ? $periodEnd : new \DateTimeImmutable((string)$periodEnd);

        return $start->format(self::ACCOUNTING_PERIOD_LABEL_FORMAT)
            . ' to '
            . $end->format(self::ACCOUNTING_PERIOD_LABEL_FORMAT);
    }

    public function suggestFirstPeriod(
        \DateTimeImmutable $incorporationDate,
        ?int $companyId = null
    ): array
    {
        $start = $incorporationDate;
        $end = $this->firstPeriodEnd($incorporationDate);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => self::accountingPeriodLabel($start, $end),
            'source' => 'suggested_first_period',
        ];
    }

    public function suggestPeriodsThroughDate(
        \DateTimeImmutable $incorporationDate,
        \DateTimeImmutable $referenceDate,
        ?int $companyId = null
    ): array {
        $periods = [];
        $currentStart = $incorporationDate;
        $currentEnd = $this->firstPeriodEnd($incorporationDate);

        while ($currentStart <= $referenceDate || empty($periods)) {
            $periods[] = [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
                'label' => self::accountingPeriodLabel($currentStart, $currentEnd),
                'source' => empty($periods) ? 'suggested_first_period' : 'suggested_follow_on_period',
            ];

            $currentStart = $currentEnd->modify('+1 day');
            $currentEnd = $this->followOnPeriodEnd($currentEnd);
        }

        return $periods;
    }

    public function suggestFollowOnPeriodsThroughDate(
        \DateTimeImmutable $previousPeriodEnd,
        \DateTimeImmutable $referenceDate,
        ?int $companyId = null
    ): array {
        $periods = [];
        $currentStart = $previousPeriodEnd->modify('+1 day');
        $currentEnd = $this->followOnPeriodEnd($previousPeriodEnd);

        while ($currentStart <= $referenceDate || empty($periods)) {
            $periods[] = [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
                'label' => self::accountingPeriodLabel($currentStart, $currentEnd),
                'source' => 'suggested_follow_on_period',
            ];

            $currentStart = $currentEnd->modify('+1 day');
            $currentEnd = $this->followOnPeriodEnd($currentEnd);
        }

        return $periods;
    }

    public function missingSuggestedPeriods(array $existingPeriods, array $suggestedPeriods): array
    {
        $existingKeys = [];

        foreach ($existingPeriods as $period) {
            $start = (string)($period['period_start'] ?? $period['start'] ?? '');
            $end = (string)($period['period_end'] ?? $period['end'] ?? '');

            if ($start === '' || $end === '') {
                continue;
            }

            $existingKeys[$start . '|' . $end] = true;
        }

        return array_values(array_filter($suggestedPeriods, static function (array $period) use ($existingKeys): bool {
            $key = $period['start'] . '|' . $period['end'];

            return !isset($existingKeys[$key]);
        }));
    }

    public function derive(
        string $accountingPeriodStart,
        string $accountingPeriodEnd,
        ?int $companyId = null
    ): array {
        $start = new \DateTimeImmutable($accountingPeriodStart);
        $end = new \DateTimeImmutable($accountingPeriodEnd);

        if ($start > $end) {
            throw new \RuntimeException('Accounting period start must be on or before the accounting period end.');
        }

        $periods = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $maxEnd = $cursor->modify('+1 year')->modify('-1 day');
            $ctEnd = $maxEnd < $end ? $maxEnd : $end;

            $periods[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $ctEnd->format('Y-m-d'),
                'label' => self::accountingPeriodLabel($cursor, $ctEnd),
            ];

            $cursor = $ctEnd->modify('+1 day');
        }

        $this->validateDerivedCoverage($periods, $start, $end);

        return $periods;
    }

    /**
     * Validate the statutory calendar limit of twelve months. The inclusive
     * day count may therefore be 366 when the period spans 29 February.
     *
     * @return array{valid: bool, days: int, maximum_end: string, error: string}
     */
    public function validateMaximumPeriodLength(string $periodStart, string $periodEnd): array
    {
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) !== 1
        ) {
            return [
                'valid' => false,
                'days' => 0,
                'maximum_end' => '',
                'error' => 'CT period dates must use YYYY-MM-DD.',
            ];
        }

        try {
            $start = new \DateTimeImmutable($periodStart);
            $end = new \DateTimeImmutable($periodEnd);
        } catch (\Throwable) {
            return [
                'valid' => false,
                'days' => 0,
                'maximum_end' => '',
                'error' => 'CT period dates are invalid.',
            ];
        }
        if ($start->format('Y-m-d') !== $periodStart || $end->format('Y-m-d') !== $periodEnd || $end < $start) {
            return [
                'valid' => false,
                'days' => 0,
                'maximum_end' => '',
                'error' => 'The CT period end must be on or after its start.',
            ];
        }

        $maximumEnd = $start->modify('+1 year')->modify('-1 day');
        $valid = $end <= $maximumEnd;

        return [
            'valid' => $valid,
            'days' => (int)$start->diff($end)->days + 1,
            'maximum_end' => $maximumEnd->format('Y-m-d'),
            'error' => $valid ? '' : 'The CT period exceeds 12 months.',
        ];
    }

    private function validateDerivedCoverage(array $periods, \DateTimeImmutable $accountingStart, \DateTimeImmutable $accountingEnd): void {
        if (empty($periods)) {
            throw new \RuntimeException('At least one CT period must be derived.');
        }

        $expectedStart = $accountingStart;

        foreach ($periods as $index => $period) {
            $periodStart = new \DateTimeImmutable($period['start']);
            $periodEnd = new \DateTimeImmutable($period['end']);

            if ($periodStart->format('Y-m-d') !== $expectedStart->format('Y-m-d')) {
                throw new \RuntimeException('CT periods must be continuous without gaps.');
            }

            if ($periodStart > $periodEnd) {
                throw new \RuntimeException('A CT period start cannot be after its end.');
            }

            $length = $this->validateMaximumPeriodLength(
                $periodStart->format('Y-m-d'),
                $periodEnd->format('Y-m-d')
            );
            if (empty($length['valid'])) {
                throw new \RuntimeException((string)($length['error'] ?? 'A CT period cannot exceed 12 months.'));
            }

            if ($index > 0) {
                $previousEnd = new \DateTimeImmutable($periods[$index - 1]['end']);

                if ($periodStart->format('Y-m-d') !== $previousEnd->modify('+1 day')->format('Y-m-d')) {
                    throw new \RuntimeException('CT periods must continue immediately after the previous period.');
                }
            }

            $expectedStart = $periodEnd->modify('+1 day');
        }

        $finalEnd = new \DateTimeImmutable($periods[count($periods) - 1]['end']);

        if ($finalEnd->format('Y-m-d') !== $accountingEnd->format('Y-m-d')) {
            throw new \RuntimeException('CT periods must cover the full accounting period.');
        }
    }

    private function firstPeriodEnd(\DateTimeImmutable $incorporationDate): \DateTimeImmutable
    {
        $anniversaryMonth = $incorporationDate->modify('+1 year');

        return $anniversaryMonth->modify('last day of this month');
    }

    private function followOnPeriodEnd(\DateTimeImmutable $previousEnd): \DateTimeImmutable
    {
        return $previousEnd->modify('+1 year');
    }
}
