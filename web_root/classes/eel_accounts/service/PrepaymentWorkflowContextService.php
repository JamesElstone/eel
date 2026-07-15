<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentWorkflowContextService
{
    public function __construct(
        private readonly ?PrepaymentApprovalContextService $approvalService = null,
        private readonly ?PrepaymentHistoricalCorrectionService $historicalCorrectionService = null,
    ) {
    }

    /** @return array{review: array, approval: ?array, historical_correction: array} */
    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $approvalService = $this->approvalService;
        $historicalCorrectionService = $this->historicalCorrectionService;
        if ($approvalService === null && $historicalCorrectionService === null) {
            $sourceService = new PrepaymentSourceService();
            $scheduleService = new PrepaymentScheduleService(sourceService: $sourceService);
            $reviewService = new PrepaymentReviewService(
                sourceService: $sourceService,
                scheduleService: $scheduleService
            );
            $approvalService = new PrepaymentApprovalContextService(reviewService: $reviewService);
            $historicalCorrectionService = new PrepaymentHistoricalCorrectionService(scheduleService: $scheduleService);
        }

        $approvalContext = ($approvalService ?? new PrepaymentApprovalContextService())
            ->fetchContext($companyId, $accountingPeriodId);
        $review = (array)($approvalContext['review'] ?? []);
        $historicalCorrection = ($historicalCorrectionService ?? new PrepaymentHistoricalCorrectionService())
            ->fetchContext(
                $companyId,
                $accountingPeriodId,
                is_array($review['schedule_context'] ?? null) ? $review['schedule_context'] : null
            );

        return [
            'review' => $review,
            'approval' => is_array($approvalContext['approval'] ?? null) ? $approvalContext['approval'] : null,
            'historical_correction' => $historicalCorrection,
        ];
    }
}
