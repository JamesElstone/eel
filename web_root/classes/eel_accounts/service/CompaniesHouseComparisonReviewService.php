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
    private const CHECK_CODE = 'companies_house_mismatch_acknowledgement';

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndCompaniesHouseComparisonService $comparisonService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Service\AccountingPeriodAccessService $accessService = null,
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
        $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, self::CHECK_CODE);

        if (is_array($acknowledgement)) {
            $basis = !empty($comparison['available'])
                ? $acknowledgements->buildBasis(self::CHECK_CODE, $comparison)
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

        return [
            'comparison' => $comparison,
            'acknowledgement' => is_array($acknowledgement) ? $acknowledgement : null,
            'access' => $access,
            'mismatch_count' => $this->mismatchCount($comparison),
            'can_acknowledge' => $comparisonCanBeAcknowledged && empty($access['is_locked']),
            'acknowledgement_blocked_reason' => !$comparisonCanBeAcknowledged
                ? (string)(($comparison['warnings'] ?? [])[0] ?? 'Complete and lock the prior accounting period before approving this comparison.')
                : '',
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
