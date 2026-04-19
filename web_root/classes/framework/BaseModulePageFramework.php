<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

abstract class BaseModulePageFramework extends BasePageFramework
{
    public function services(): array
    {
        return [CompanyAccountService::class];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array {
        $companyId = $request->companyId();
        $taxYearId = $request->taxYearId();
        $company = $companyId > 0 ? (new CompanyRepository())->fetchSettingsCompany($companyId) : null;
        $settings = $companyId > 0 ? (new CompanySettingsStore($companyId))->all() : CompanySettingsStore::defaults();

        if (is_array($company) && $company !== []) {
            $settings = array_merge($company, $settings);
        }

        $context = [
            'page_id' => $this->id(),
            'page_cards' => $this->cards(),
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'has_valid_selected_company' => $companyId > 0 && is_array($company),
            'settings' => $settings,
            'default_bank_nominal_id' => (int)($settings['default_bank_nominal_id'] ?? 0),
        ];

        return array_merge($context, $this->moduleContext($request, $services, $actionResult, $context));
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        return [];
    }
}
