<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentApprovalContextService
{
    private const CHECK_CODE = 'prepayment_approvals';

    public function __construct(
        private readonly ?\eel_accounts\Service\PrepaymentReviewService $reviewService = null,
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\eel_accounts\Service\AccountingPeriodAccessService $accessService = null,
    ) {
    }

    /** @return array{review: array, approval: ?array} */
    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $review = ($this->reviewService ?? new \eel_accounts\Service\PrepaymentReviewService())
            ->fetchContext($companyId, $accountingPeriodId);
        $acknowledgements = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = $acknowledgements->fetch($companyId, $accountingPeriodId, self::CHECK_CODE);

        if (!is_array($acknowledgement)) {
            return [
                'review' => $review,
                'approval' => null,
            ];
        }

        $basis = !empty($review['available'])
            ? $acknowledgements->buildBasis(self::CHECK_CODE, $review)
            : null;
        $access = ($this->accessService ?? new \eel_accounts\Service\AccountingPeriodAccessService())
            ->fetchDataEntryState($companyId, $accountingPeriodId);
        $evaluation = $acknowledgements->evaluate($acknowledgement, $basis, !empty($access['is_locked']));

        $acknowledgement['state'] = (string)($evaluation['state'] ?? 'absent');
        $acknowledgement['current'] = !empty($evaluation['current']);

        return [
            'review' => $review,
            'approval' => $acknowledgement,
        ];
    }
}
