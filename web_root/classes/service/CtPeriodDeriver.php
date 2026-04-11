<?php
declare(strict_types=1);

final class CtPeriodDeriver
{
    public function derive(string $accountingPeriodStart, string $accountingPeriodEnd): array {
        $start = new DateTimeImmutable($accountingPeriodStart);
        $end = new DateTimeImmutable($accountingPeriodEnd);

        if ($start > $end) {
            throw new RuntimeException('Accounting period start must be on or before the accounting period end.');
        }

        $periods = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $maxEnd = $cursor->modify('+1 year')->modify('-1 day');
            $ctEnd = $maxEnd < $end ? $maxEnd : $end;

            $periods[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $ctEnd->format('Y-m-d'),
                'label' => $cursor->format('d/m/Y') . ' to ' . $ctEnd->format('d/m/Y'),
            ];

            $cursor = $ctEnd->modify('+1 day');
        }

        $this->validateDerivedCoverage($periods, $start, $end);

        return $periods;
    }

    private function validateDerivedCoverage(array $periods, DateTimeImmutable $accountingStart, DateTimeImmutable $accountingEnd): void {
        if (empty($periods)) {
            throw new RuntimeException('At least one CT period must be derived.');
        }

        $expectedStart = $accountingStart;

        foreach ($periods as $index => $period) {
            $periodStart = new DateTimeImmutable($period['start']);
            $periodEnd = new DateTimeImmutable($period['end']);

            if ($periodStart->format('Y-m-d') !== $expectedStart->format('Y-m-d')) {
                throw new RuntimeException('CT periods must be continuous without gaps.');
            }

            if ($periodStart > $periodEnd) {
                throw new RuntimeException('A CT period start cannot be after its end.');
            }

            $maxEnd = $periodStart->modify('+1 year')->modify('-1 day');

            if ($periodEnd > $maxEnd) {
                throw new RuntimeException('A CT period cannot exceed 12 months.');
            }

            if ($index > 0) {
                $previousEnd = new DateTimeImmutable($periods[$index - 1]['end']);

                if ($periodStart->format('Y-m-d') !== $previousEnd->modify('+1 day')->format('Y-m-d')) {
                    throw new RuntimeException('CT periods must continue immediately after the previous period.');
                }
            }

            $expectedStart = $periodEnd->modify('+1 day');
        }

        $finalEnd = new DateTimeImmutable($periods[count($periods) - 1]['end']);

        if ($finalEnd->format('Y-m-d') !== $accountingEnd->format('Y-m-d')) {
            throw new RuntimeException('CT periods must cover the full accounting period.');
        }
    }
}
