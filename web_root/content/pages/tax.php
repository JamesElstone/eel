<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax extends PageContextFramework
{
    public function id(): string
    {
        return 'tax';
    }

    public function title(): string
    {
        return 'Tax';
    }

    public function subtitle(): string
    {
        return 'Inspect read-only Corporation Tax workings, capital allowance pools, losses, and tax data warnings.';
    }

    public function cards(): array
    {
        return [
            'tax_period_selector',
            'tax_corporation_tax_summary',
            'tax_taxable_profit_bridge',
            'tax_disallowable_add_backs',
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
        ];
    }

    protected function moduleContext(RequestFramework $request, PageServiceFramework $services, ActionResultFramework $actionResult, array $baseContext): array
    {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $ctPeriodService = new \eel_accounts\Service\CorporationTaxPeriodService();
        $sync = $companyId > 0 && $accountingPeriodId > 0
            ? $ctPeriodService->syncForAccountingPeriod($companyId, $accountingPeriodId)
            : ['periods' => []];
        $ctPeriods = array_values(array_filter((array)($sync['periods'] ?? []), static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'));
        $requestedCtPeriodId = max(0, (int)$request->input('ct_period_id', 0));
        $selectedCtPeriodId = $this->selectedCtPeriodId($ctPeriods, $requestedCtPeriodId);
        if ($selectedCtPeriodId <= 0) {
            $selectedCtPeriodId = $ctPeriodService->defaultCtPeriodId($companyId, $accountingPeriodId);
        }
        $selectedCtPeriod = $this->selectedCtPeriod($ctPeriods, $selectedCtPeriodId);

        return [
            'tax' => [
                'ct_periods' => $ctPeriods,
                'selected_ct_period_id' => $selectedCtPeriodId,
                'selected_ct_period' => $selectedCtPeriod,
                'selected_ct_period_helper' => $this->selectedCtPeriodHelper($selectedCtPeriod),
                'sync_errors' => (array)($sync['errors'] ?? []),
            ],
        ];
    }

    private function selectedCtPeriodId(array $ctPeriods, int $requestedCtPeriodId): int
    {
        if ($requestedCtPeriodId <= 0) {
            return 0;
        }
        foreach ($ctPeriods as $period) {
            if ((int)($period['id'] ?? 0) === $requestedCtPeriodId) {
                return $requestedCtPeriodId;
            }
        }

        return 0;
    }

    private function selectedCtPeriod(array $ctPeriods, int $selectedCtPeriodId): array
    {
        if ($selectedCtPeriodId <= 0) {
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
        $sequenceNo = (int)($selectedCtPeriod['sequence_no'] ?? 0);
        $startDate = trim((string)($selectedCtPeriod['period_start'] ?? ''));
        $endDate = trim((string)($selectedCtPeriod['period_end'] ?? ''));
        if ($sequenceNo <= 0 || $startDate === '' || $endDate === '') {
            return '';
        }

        return 'Showing Tax Period ' . $sequenceNo . ': ' . $startDate . ' to ' . $endDate;
    }
}
