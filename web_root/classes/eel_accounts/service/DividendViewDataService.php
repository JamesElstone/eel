<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class DividendViewDataService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\DividendService $dividendService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchCapacityContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'capacity' => ['available' => false, 'errors' => ['Select a company and accounting period before reviewing dividends.']],
                'reserve_review' => ['available' => false, 'errors' => ['Select a company and accounting period before reviewing dividend reserves.']],
                'warnings' => [],
                'is_locked' => false,
            ];
        }

        $dividends = $this->dividendService ?? new \eel_accounts\Service\DividendService();
        $context = $dividends->getDividendCapacityContext($companyId, $accountingPeriodId);
        $capacity = (array)($context['capacity'] ?? []);

        return [
            'capacity' => $capacity,
            'reserve_review' => (array)($context['reserve_review'] ?? []),
            'warnings' => $dividends->getDividendWarningsForCapacity($companyId, $accountingPeriodId, $capacity),
            'is_locked' => ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())
                ->isLocked($companyId, $accountingPeriodId),
        ];
    }
}
