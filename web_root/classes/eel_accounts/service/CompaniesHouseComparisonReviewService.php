<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseComparisonReviewService
{
    public const MISMATCH_CHECK_CODE = 'companies_house_mismatch_acknowledgement';
    public const NO_FILING_CHECK_CODE = 'companies_house_no_filing_acknowledgement';

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndCompaniesHouseComparisonService $comparisonService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Service\AccountingPeriodAccessService $accessService = null,
        private readonly ?\eel_accounts\Service\CompaniesHouseAccountsSubmissionService $accountsSubmissionService = null,
    ) {
    }

    /**
     * @return array{
     *     comparison: array,
     *     acknowledgement: ?array,
     *     access: array,
     *     mismatch_count: int
     * }
     */
    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $comparison = ($this->comparisonService ?? new \eel_accounts\Service\YearEndCompaniesHouseComparisonService())
            ->fetchComparison($companyId, $accountingPeriodId);
        $access = ($this->accessService ?? new \eel_accounts\Service\AccountingPeriodAccessService())
            ->fetchDataEntryState($companyId, $accountingPeriodId);
        $acknowledgements = $this->acknowledgementService
            ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $hasExactFiling = !empty($comparison['has_exact_filing']);
        $checkCode = $hasExactFiling ? self::MISMATCH_CHECK_CODE : self::NO_FILING_CHECK_CODE;
        $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, $checkCode);

        if (is_array($acknowledgement)) {
            $basis = !empty($comparison['available'])
                ? $acknowledgements->buildBasis($checkCode, $comparison)
                : null;
            $evaluation = $acknowledgements->evaluate(
                $acknowledgement,
                $basis,
                !empty($access['is_locked'])
            );
            $acknowledgement['state'] = (string)($evaluation['state'] ?? 'unverifiable');
            $acknowledgement['current'] = !empty($evaluation['current']);
        }

        $comparisonCanBeAcknowledged = array_key_exists('can_acknowledge', $comparison)
            ? !empty($comparison['can_acknowledge'])
            : !empty($comparison['available']);
        $eligibility = ($this->accountsSubmissionService ?? new \eel_accounts\Service\CompaniesHouseAccountsSubmissionService())
            ->fetchEligibility($companyId, $accountingPeriodId);
        $eligibilityRecorded = in_array((string)($eligibility['decision'] ?? 'pending'), ['eligible', 'ineligible'], true);
        $isLocked = !empty($access['is_locked']);

        $requiresAcknowledgement = !$hasExactFiling || $this->mismatchCount($comparison) > 0;
        $requiresEligibility = $hasExactFiling && $requiresAcknowledgement;

        return [
            'comparison' => $comparison,
            'acknowledgement' => is_array($acknowledgement) ? $acknowledgement : null,
            'access' => $access,
            'eligibility' => $eligibility,
            'mismatch_count' => $this->mismatchCount($comparison),
            'acknowledgement_check_code' => $checkCode,
            'acknowledgement_subject' => $hasExactFiling ? 'Companies House comparison' : 'No exact Companies House filing',
            'requires_acknowledgement' => $requiresAcknowledgement,
            'can_acknowledge' => $comparisonCanBeAcknowledged && !$isLocked && (!$requiresEligibility || $eligibilityRecorded),
            'acknowledgement_blocked_reason' => !$comparisonCanBeAcknowledged
                ? (string)(($comparison['warnings'] ?? [])[0] ?? 'Complete and lock the prior accounting period before approving this comparison.')
                : (!$isLocked && $requiresEligibility && !$eligibilityRecorded
                    ? 'Record whether the company is eligible for XML based web filing before completing this Year End Confirmation.'
                    : ''),
        ];
    }

    private function mismatchCount(array $comparison): int
    {
        $count = 0;
        foreach ((array)($comparison['rows'] ?? []) as $row) {
            if (is_array($row) && (string)($row['status'] ?? '') === 'fail') {
                $count++;
            }
        }

        return $count;
    }
}
