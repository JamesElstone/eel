<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndTaxReadinessService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxComputationService $taxComputationService = null,
    ) {
    }

    public function fetchSummary(int $companyId, int $accountingPeriodId): array {
        $service = $this->taxComputationService ?? new \eel_accounts\Service\CorporationTaxComputationService(
            $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService()
        );

        return $service->fetchSummary($companyId, $accountingPeriodId);
    }
}
