<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_rates extends PageContextFramework
{
    public function id(): string
    {
        return 'tax_rates';
    }

    public function title(): string
    {
        return 'Tax Rates';
    }

    public function subtitle(): string
    {
        return 'Review the Corporation Tax rate rules used by the calculation engine.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['company_id', 'tax_year_id'];
    }

    public function cards(): array
    {
        return [
            'tax_rates',
            'tax_treatment_rules',
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $service = new CorporationTaxRateRuleService();

        return [
            'tax_rates' => [
                'rules' => $service->fetchRules(),
                'source_url' => CorporationTaxRateRuleService::SOURCE_URL,
            ],
            'tax_treatment_rules' => [
                'rules' => (new CorporationTaxTreatmentRuleService())->fetchRules(),
            ],
        ];
    }
}
