<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class AccountingPeriodAccessService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    /** @return array{permitted: bool, is_locked: bool, reason_code: string, reason: string} */
    public function fetchDataEntryState(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'permitted' => false,
                'is_locked' => false,
                'reason_code' => 'missing_context',
                'reason' => 'Select a company and accounting period before entering or changing data.',
            ];
        }

        $isLocked = ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())
            ->isLocked($companyId, $accountingPeriodId);

        return [
            'permitted' => !$isLocked,
            'is_locked' => $isLocked,
            'reason_code' => $isLocked ? 'period_locked' : '',
            'reason' => $isLocked
                ? 'This accounting period is locked, so data entry is not permitted.'
                : '',
        ];
    }

    public function isDataEntryPermitted(int $companyId, int $accountingPeriodId): bool
    {
        return $this->fetchDataEntryState($companyId, $accountingPeriodId)['permitted'];
    }

    public function assertDataEntryPermitted(
        int $companyId,
        int $accountingPeriodId,
        string $actionLabel = 'change this period'
    ): void {
        $state = $this->fetchDataEntryState($companyId, $accountingPeriodId);
        if (!empty($state['permitted'])) {
            return;
        }

        $actionLabel = trim($actionLabel);
        $actionLabel = $actionLabel !== '' ? $actionLabel : 'change this period';

        if ((string)$state['reason_code'] === 'period_locked') {
            throw new \RuntimeException('This accounting period is locked, so you cannot ' . $actionLabel . '.');
        }

        throw new \RuntimeException('Select a company and accounting period before you can ' . $actionLabel . '.');
    }
}
