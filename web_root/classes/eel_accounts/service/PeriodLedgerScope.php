<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final readonly class PeriodLedgerScope
{
    public function __construct(
        public int $companyId,
        public int $accountingPeriodId,
        public string $periodStart,
        public string $accountingPeriodEnd,
        public string $asAtDate,
    ) {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \InvalidArgumentException('A company and accounting period are required.');
        }
        foreach ([$periodStart, $accountingPeriodEnd, $asAtDate] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Ledger scope dates must use YYYY-MM-DD.');
            }
        }
        if ($periodStart > $accountingPeriodEnd || $asAtDate < $periodStart || $asAtDate > $accountingPeriodEnd) {
            throw new \InvalidArgumentException('The as-at date must fall inside the accounting period.');
        }
    }

    public function cacheKey(): string
    {
        return implode(':', [$this->companyId, $this->accountingPeriodId, $this->periodStart, $this->asAtDate]);
    }
}
