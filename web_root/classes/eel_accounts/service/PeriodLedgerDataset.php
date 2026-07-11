<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final readonly class PeriodLedgerDataset
{
    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(
        public PeriodLedgerScope $scope,
        public array $rows,
        public int $journalCount,
    ) {
    }
}
