<?php
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
