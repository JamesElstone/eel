<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TrialBalanceStateService
{
    public function fetchState(int $companyId, int $accountingPeriodId): array
    {
        $preTax = new PreTaxProfitLossService();
        $metrics = new YearEndMetricsService(null, null, null, null, $preTax);
        $trialBalanceService = new TrialBalanceService($metrics);
        $snapshot = $trialBalanceService->fetchStateSnapshot($companyId, $accountingPeriodId);

        if (empty($snapshot['available'])) {
            return [
                'trial_balance' => $snapshot,
                'validation' => [
                    'available' => false,
                    'errors' => (array)($snapshot['errors'] ?? []),
                ],
            ];
        }

        $validationService = new TrialBalanceValidationService($trialBalanceService, $metrics);
        $validation = $validationService->fetchValidationFromSnapshot($companyId, $accountingPeriodId, $snapshot);

        return [
            'trial_balance' => [
                'available' => true,
                'summary' => (array)($snapshot['summary'] ?? []),
                'totals' => (array)($snapshot['totals'] ?? []),
                'has_rows' => !empty($snapshot['has_rows']),
                'source_basis' => (string)($snapshot['source_basis'] ?? 'posted_journals_only'),
                'summary_source_basis' => (string)($snapshot['summary_source_basis'] ?? 'posted_journals_plus_year_end_close_preview'),
                'summary_basis_note' => (string)($snapshot['summary_basis_note'] ?? ''),
            ],
            'validation' => $validation,
        ];
    }
}
