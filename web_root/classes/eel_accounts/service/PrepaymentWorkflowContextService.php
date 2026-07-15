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
        private readonly ?PrepaymentScheduleService $scheduleService = null,
    ) {
    }

    /** @return array{review: array, approval: ?array, repair: array} */
    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $approvalService = $this->approvalService;
        $scheduleService = $this->scheduleService;
        if ($approvalService === null && $scheduleService === null) {
            $sourceService = new PrepaymentSourceService();
            $scheduleService = new PrepaymentScheduleService(sourceService: $sourceService);
            $reviewService = new PrepaymentReviewService(
                sourceService: $sourceService,
                scheduleService: $scheduleService
            );
            $approvalService = new PrepaymentApprovalContextService(reviewService: $reviewService);
        }

        $approvalContext = ($approvalService ?? new PrepaymentApprovalContextService())
            ->fetchContext($companyId, $accountingPeriodId);

        return [
            'review' => (array)($approvalContext['review'] ?? []),
            'approval' => is_array($approvalContext['approval'] ?? null) ? $approvalContext['approval'] : null,
            'repair' => ($scheduleService ?? new PrepaymentScheduleService())
                ->fetchRepairContext($companyId, $accountingPeriodId),
        ];
    }
}
