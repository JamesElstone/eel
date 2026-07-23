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
    private const CHECK_CODE = 'cut_off_journals_review';
    private const CACHE_NAMESPACE = 'year-end.journal-cut-off-basis';

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
            self::CHECK_CODE
        );

        if (!is_array($acknowledgement)) {
            return [
                'acknowledgement' => null,
                'access' => $access,
            ];
        }

        $basisResult = $this->fetchApprovalBasis($companyId, $accountingPeriodId);
        $basis = !empty($basisResult['available']) && is_array($basisResult['basis'] ?? null)
            ? $basisResult['basis']
            : null;
        $evaluation = $acknowledgements->evaluate($acknowledgement, $basis, !empty($access['is_locked']));
        $acknowledgement['state'] = (string)($evaluation['state'] ?? 'unverifiable');
        $acknowledgement['current'] = !empty($evaluation['current']);

        return [
            'acknowledgement' => $acknowledgement,
            'access' => $access,
        ];
    }

    /** @return array{available: bool, basis: ?array, errors: list<string>} */
    public function fetchApprovalBasis(int $companyId, int $accountingPeriodId): array
    {
        return (array)\eel_accounts\Support\RequestCache::remember(
            self::CACHE_NAMESPACE,
            \eel_accounts\Support\RequestCache::key($companyId, $accountingPeriodId),
            fn(): array => $this->buildApprovalBasis($companyId, $accountingPeriodId)
        );
    }

    /** @return array{available: bool, basis: ?array, errors: list<string>} */
    private function buildApprovalBasis(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'basis' => null,
                'errors' => ['Select a company and accounting period before approving journal cut-off review.'],
            ];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService(
            preTaxProfitLossService: new \eel_accounts\Service\PreTaxProfitLossService(
                new \eel_accounts\Service\PeriodLedgerReadService()
            )
        );
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($accountingPeriod)) {
            return [
                'available' => false,
                'basis' => null,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $periodStart = (string)($accountingPeriod['period_start'] ?? '');
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        if (!$this->validDate($periodStart) || !$this->validDate($periodEnd) || $periodStart > $periodEnd) {
            return [
                'available' => false,
                'basis' => null,
                'errors' => ['The selected accounting period does not have valid start and end dates.'],
            ];
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

        $basis = ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->buildBasis(self::CHECK_CODE, $facts);

        return [
            'available' => true,
            'basis' => $basis,
            'errors' => [],
        ];
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
