<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class YearEndTaxReadinessService
{
    public function __construct(
        private readonly ?YearEndMetricsService $metricsService = null,
        private readonly ?CorporationTaxComputationService $taxComputationService = null,
    ) {
    }

    public function fetchSummary(int $companyId, int $taxYearId): array {
        $service = $this->taxComputationService ?? new CorporationTaxComputationService(
            $this->metricsService ?? new YearEndMetricsService()
        );

        return $service->fetchSummary($companyId, $taxYearId);
    }
}
