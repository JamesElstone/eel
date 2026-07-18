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
        return 'Rates / Thresholds';
    }

    public function subtitle(): string
    {
        return 'Review sourced Corporation Tax rules, VAT rates, VAT registration thresholds and FRS 105 size thresholds.';
    }

    public function hiddenSiteContextSelectors(): array
    {
        return ['company_id', 'accounting_period_id'];
    }

    public function cards(): array
    {
        return [
            'tax_rates_ct',
            'tax_rates_ct600_rim',
            'tax_rates_vat',
            'tax_thresholds_vat',
            'tax_treatment_rules',
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $service = new \eel_accounts\Service\TaxRateRuleService();

        return [
            'tax_rates_ct' => [
                'rules' => $service->fetchRules(),
                'source_url' => \eel_accounts\Service\TaxRateRuleService::HMRC_RATES_COLLECTION_URL,
            ],
            'hmrc_ct_rim' => [
                'source_url' => \eel_accounts\Service\HmrcCtRimCatalogueService::SOURCE_URL,
            ],
            'tax_treatment_rules' => [
                'rules' => (new \eel_accounts\Service\CorporationTaxTreatmentRuleService())->fetchRules(),
            ],
        ];
    }
}
