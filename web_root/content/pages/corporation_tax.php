<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _corporation_tax extends PageContextFramework
{
    public function id(): string
    {
        return 'corporation_tax';
    }

    public function title(): string
    {
        return 'Tax';
    }

    public function subtitle(): string
    {
        return 'Inspect read-only Corporation Tax workings, capital allowance pools, losses, and tax data warnings.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'tax_period_selector',
            'tax_corporation_tax_summary',
            'tax_taxable_profit_bridge',
            'tax_prepayment_treatment',
            'tax_disallowable_add_backs',
            'tax_capital_add_backs',
            'tax_depreciation_add_back',
            'tax_capital_allowances_summary',
            'tax_aia_allocation',
            'tax_main_rate_pool',
            'tax_special_rate_pool',
            'tax_car_co2_treatment',
            'tax_disposals_balancing',
            'tax_losses',
            'tax_rate_bands',
            'tax_warnings',
            'tax_ct_period_facts',
            'year_end_tax_readiness',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Corporation Tax',
                'cards' => [
                    'tax_period_selector',
                    'tax_corporation_tax_summary',
                    'tax_taxable_profit_bridge',
                    'tax_prepayment_treatment',
                    'tax_disallowable_add_backs',
                    'tax_capital_add_backs',
                    'tax_depreciation_add_back',
                    'tax_capital_allowances_summary',
                    'tax_aia_allocation',
                    'tax_main_rate_pool',
                    'tax_special_rate_pool',
                    'tax_car_co2_treatment',
                    'tax_disposals_balancing',
                    'tax_losses',
                    'tax_rate_bands',
                    'tax_warnings',
                ],
            ],
            [
                'tab' => 'CT Period Facts',
                'cards' => [
                    'tax_ct_period_facts',
                ],
            ],
            [
                'tab' => 'Year End Review',
                'cards' => [
                    'year_end_tax_readiness',
                ],
            ],
        ];
    }

    protected function moduleContext(RequestFramework $request, PageServiceFramework $services, ActionResultFramework $actionResult, array $baseContext): array
    {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        /** @var \eel_accounts\Service\VatSupportScopeService $vatSupportScopeService */
        $vatSupportScopeService = $services->get(\eel_accounts\Service\VatSupportScopeService::class);
        $vatSupportScope = $vatSupportScopeService->fetchForCompany($companyId);
        /** @var \eel_accounts\Service\CorporationTaxComputationService $taxComputationService */
        $taxComputationService = $services->get(\eel_accounts\Service\CorporationTaxComputationService::class);
        $available = $companyId > 0 && $accountingPeriodId > 0
            ? $taxComputationService->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)
            : ['periods' => [], 'errors' => []];
        $ctPeriods = array_values(array_filter(
            (array)($available['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $requestedCtPeriodId = (int)$request->input('ct_period_id', 0);
        $selectedCtPeriodId = $this->selectedCtPeriodId($ctPeriods, $requestedCtPeriodId);
        if ($selectedCtPeriodId === 0) {
            $selectedCtPeriodId = !empty($vatSupportScope['tax_year_end_read_only'])
                ? $this->historicalDefaultCtPeriodId($ctPeriods)
                : $this->defaultCtPeriodId($ctPeriods);
        }
        $selectedCtPeriod = $this->selectedCtPeriod($ctPeriods, $selectedCtPeriodId);

        return [
            'vat_support_scope' => $vatSupportScope,
            'tax' => [
                'ct_periods' => $ctPeriods,
                'selected_ct_period_id' => $selectedCtPeriodId,
                'selected_ct_period' => $selectedCtPeriod,
                'selected_ct_period_helper' => $this->selectedCtPeriodHelper($selectedCtPeriod),
                'sync_errors' => (array)($available['errors'] ?? []),
            ],
        ];
    }

    private function selectedCtPeriodId(array $ctPeriods, int $requestedCtPeriodId): int
    {
        if ($requestedCtPeriodId === 0) {
            return 0;
        }
        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) === $requestedCtPeriodId) {
                return $requestedCtPeriodId;
            }
        }

        return 0;
    }

    private function defaultCtPeriodId(array $ctPeriods): int
    {
        foreach ($ctPeriods as $period) {
            if ((string)($period['status'] ?? '') !== 'accepted') {
                return (int)($period['id'] ?? 0);
            }
        }

        return (int)($ctPeriods[0]['id'] ?? 0);
    }

    private function historicalDefaultCtPeriodId(array $ctPeriods): int
    {
        foreach ($ctPeriods as $period) {
            if ((int)($period['latest_computation_run_id'] ?? 0) > 0) {
                return (int)($period['id'] ?? 0);
            }
        }
        foreach ($ctPeriods as $period) {
            if (in_array((string)($period['status'] ?? ''), ['accepted', 'submitted'], true)) {
                return (int)($period['id'] ?? 0);
            }
        }

        return (int)($ctPeriods[0]['id'] ?? 0);
    }

    private function selectedCtPeriod(array $ctPeriods, int $selectedCtPeriodId): array
    {
        if ($selectedCtPeriodId === 0) {
            return [];
        }

        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) === $selectedCtPeriodId) {
                return $period;
            }
        }

        return [];
    }

    private function selectedCtPeriodHelper(array $selectedCtPeriod): string
    {
        $sequenceNo = (int)($selectedCtPeriod['display_sequence_no'] ?? ($selectedCtPeriod['sequence_no'] ?? 0));
        $startDate = trim((string)($selectedCtPeriod['period_start'] ?? ''));
        $endDate = trim((string)($selectedCtPeriod['period_end'] ?? ''));
        if ($sequenceNo <= 0 || $startDate === '' || $endDate === '') {
            return '';
        }

        return 'Showing Tax Period ' . $sequenceNo . ': ' . $startDate . ' to ' . $endDate;
    }
}
