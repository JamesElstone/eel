<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class JournalCutOffReviewService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Service\AccountingPeriodAccessService $accessService = null,
    ) {
    }

    /** @return array{acknowledgement: ?array, access: array} */
    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $access = ($this->accessService ?? new \eel_accounts\Service\AccountingPeriodAccessService())
            ->fetchDataEntryState($companyId, $accountingPeriodId);
        $acknowledgements = $this->acknowledgementService
            ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = $acknowledgements->fetch(
            $companyId,
            $accountingPeriodId,
            'cut_off_journals_review'
        );

        if (!is_array($acknowledgement)) {
            return [
                'acknowledgement' => null,
                'access' => $access,
            ];
        }

        $basis = $this->currentBasis($companyId, $accountingPeriodId, $acknowledgements);
        $evaluation = $acknowledgements->evaluate($acknowledgement, $basis, !empty($access['is_locked']));
        $acknowledgement['state'] = (string)($evaluation['state'] ?? 'unverifiable');
        $acknowledgement['current'] = !empty($evaluation['current']);

        return [
            'acknowledgement' => $acknowledgement,
            'access' => $access,
        ];
    }

    private function currentBasis(
        int $companyId,
        int $accountingPeriodId,
        \eel_accounts\Service\YearEndAcknowledgementService $acknowledgements
    ): ?array {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService(
            preTaxProfitLossService: new \eel_accounts\Service\PreTaxProfitLossService(
                new \eel_accounts\Service\PeriodLedgerReadService()
            )
        );
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($accountingPeriod)) {
            return null;
        }

        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        if ($periodStart === '' || $periodEnd === '') {
            return null;
        }

        $lock = new \eel_accounts\Service\YearEndLockService();
        $facts = [
            'trial_balance' => $metrics->trialBalanceSummary(
                $companyId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd
            ),
            'posted_source_work' => $metrics->postedSourceWorkSummary(
                $companyId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd
            ),
            'prepayment_review' => (new \eel_accounts\Service\PrepaymentReviewService($metrics, $lock))
                ->fetchContext($companyId, $accountingPeriodId),
        ];

        return $acknowledgements->buildBasis('cut_off_journals_review', $facts);
    }
}
