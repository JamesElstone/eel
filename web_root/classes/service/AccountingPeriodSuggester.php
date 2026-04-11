<?php
declare(strict_types=1);

final class AccountingPeriodSuggester
{
    public function suggestFirstPeriod(DateTimeImmutable $incorporationDate): array {
        $start = $incorporationDate;
        $end = $this->firstPeriodEnd($incorporationDate);

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'label' => ctrl_accounting_period_label($start, $end),
            'source' => 'suggested_first_period',
        ];
    }

    public function suggestPeriodsThroughDate(
        DateTimeImmutable $incorporationDate,
        DateTimeImmutable $referenceDate
    ): array {
        $periods = [];
        $currentStart = $incorporationDate;
        $currentEnd = $this->firstPeriodEnd($incorporationDate);

        while ($currentStart <= $referenceDate || empty($periods)) {
            $periods[] = [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
                'label' => ctrl_accounting_period_label($currentStart, $currentEnd),
                'source' => empty($periods) ? 'suggested_first_period' : 'suggested_follow_on_period',
            ];

            $currentStart = $currentEnd->modify('+1 day');
            $currentEnd = $this->followOnPeriodEnd($currentEnd);
        }

        return $periods;
    }

    public function suggestFollowOnPeriodsThroughDate(
        DateTimeImmutable $previousPeriodEnd,
        DateTimeImmutable $referenceDate
    ): array {
        $periods = [];
        $currentStart = $previousPeriodEnd->modify('+1 day');
        $currentEnd = $this->followOnPeriodEnd($previousPeriodEnd);

        while ($currentStart <= $referenceDate || empty($periods)) {
            $periods[] = [
                'start' => $currentStart->format('Y-m-d'),
                'end' => $currentEnd->format('Y-m-d'),
                'label' => ctrl_accounting_period_label($currentStart, $currentEnd),
                'source' => 'suggested_follow_on_period',
            ];

            $currentStart = $currentEnd->modify('+1 day');
            $currentEnd = $this->followOnPeriodEnd($currentEnd);
        }

        return $periods;
    }

    public function missingSuggestedPeriods(array $existingPeriods, array $suggestedPeriods): array {
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

    private function firstPeriodEnd(DateTimeImmutable $incorporationDate): DateTimeImmutable {
        $anniversaryMonth = $incorporationDate->modify('+1 year');

        return $anniversaryMonth->modify('last day of this month');
    }

    private function followOnPeriodEnd(DateTimeImmutable $previousEnd): DateTimeImmutable {
        return $previousEnd->modify('+1 year');
    }

}
