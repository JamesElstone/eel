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

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $dividends = $this->dividendService ?? new \eel_accounts\Service\DividendService();
        $hasPeriod = $companyId > 0 && $accountingPeriodId > 0;

        $capacityContext = $hasPeriod
            ? $dividends->getDividendCapacityContext($companyId, $accountingPeriodId)
            : [
                'capacity' => [
                    'available' => false,
                    'errors' => ['Select a company and accounting period before reviewing dividends.'],
                ],
                'reserve_review' => [
                    'available' => false,
                    'errors' => ['Select a company and accounting period before reviewing dividend reserves.'],
                ],
            ];
        $capacity = (array)($capacityContext['capacity'] ?? []);

        $nominals = $companyId > 0
            ? $dividends->ensureDividendNominals($companyId)
            : ['available' => false, 'accounts' => [], 'errors' => []];

        return [
            'capacity' => $capacity,
            'history' => $hasPeriod ? $dividends->listDividends($companyId, $accountingPeriodId) : [],
            'vouchers' => $hasPeriod ? $dividends->listDividendVouchers($companyId, $accountingPeriodId) : [],
            'reconciliation_candidates' => $hasPeriod ? $dividends->listDividendReconciliationCandidates($companyId, $accountingPeriodId) : [],
            'warnings' => $dividends->getDividendWarningsForCapacity($companyId, $accountingPeriodId, $capacity),
            'reserve_review' => (array)($capacityContext['reserve_review'] ?? []),
            'nominals' => (array)($nominals['accounts'] ?? []),
            'nominal_errors' => (array)($nominals['errors'] ?? []),
            'is_locked' => $hasPeriod && ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId),
        ];
    }

    public function fetchCapacityContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'capacity' => ['available' => false, 'errors' => ['Select a company and accounting period before reviewing dividends.']],
                'warnings' => [],
            ];
        }

        $dividends = $this->dividendService ?? new \eel_accounts\Service\DividendService();
        $context = $dividends->getDividendCapacityContext($companyId, $accountingPeriodId);
        $capacity = (array)($context['capacity'] ?? []);

        return [
            'capacity' => $capacity,
            'warnings' => $dividends->getDividendWarningsForCapacity($companyId, $accountingPeriodId, $capacity),
        ];
    }
}
