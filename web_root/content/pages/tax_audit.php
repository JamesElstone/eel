<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _tax_audit extends PageContextFramework
{
    public function id(): string { return 'tax_audit'; }
    public function title(): string { return 'Tax Audit'; }
    public function subtitle(): string
    {
        return 'Trace each Corporation Tax computation area to its accounting sources and frozen historic evidence.';
    }
    public function ajaxPendingBlurScope(): string { return 'page'; }
    public function cards(): array { return ['tax_audit_areas', 'tax_audit_detail']; }

    protected function handlePageAction(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        if ($request->action() !== 'select-tax-audit-area') {
            return ActionResultFramework::none();
        }

        $areaCode = strtolower(trim((string)$request->input('tax_audit_area', '')));
        if ($areaCode !== '' && !\eel_accounts\Service\TaxAuditBasisService::isSupportedArea($areaCode)) {
            return new ActionResultFramework(false, ['tax.audit.selection'], [[
                'type' => 'error',
                'message' => 'The selected Tax Audit area is not supported.',
            ]], []);
        }

        return ActionResultFramework::success(
            ['tax.audit.selection'],
            [],
            [
                'ct_period_id' => max(0, (int)$request->input('ct_period_id', 0)),
                'tax_audit_area' => $areaCode,
                'tax_audit_page' => max(1, (int)$request->input('tax_audit_page', 1)),
            ]
        );
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $available = $companyId > 0 && $accountingPeriodId > 0
            ? (new \eel_accounts\Service\CorporationTaxComputationService())
                ->activeCtPeriodsForAccountingPeriod($companyId, $accountingPeriodId)
            : ['periods' => [], 'errors' => []];
        $periods = array_values(array_filter(
            (array)($available['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $query = $actionResult->query();
        $requestedPeriodId = (int)($query['ct_period_id'] ?? $request->input('ct_period_id', 0));
        $selectedPeriodId = $this->selectedPeriodId($periods, $requestedPeriodId);
        if ($selectedPeriodId <= 0) {
            $selectedPeriodId = (int)($periods[0]['id'] ?? 0);
        }
        $requestedArea = strtolower(trim((string)($query['tax_audit_area'] ?? $request->input('tax_audit_area', ''))));
        $selectedArea = \eel_accounts\Service\TaxAuditBasisService::isSupportedArea($requestedArea)
            ? $requestedArea
            : '';

        return [
            'tax_audit' => [
                'ct_periods' => $periods,
                'selected_ct_period_id' => $selectedPeriodId,
                'selected_area' => $selectedArea,
                'selected_area_label' => \eel_accounts\Service\TaxAuditBasisService::areaCatalogue()[$selectedArea] ?? '',
                'detail_page' => max(1, (int)($query['tax_audit_page'] ?? $request->input('tax_audit_page', 1))),
                'sync_errors' => (array)($available['errors'] ?? []),
                'handled_by_cards' => [],
            ],
        ];
    }

    private function selectedPeriodId(array $periods, int $requested): int
    {
        foreach ($periods as $period) {
            if ((int)($period['id'] ?? 0) === $requested) {
                return $requested;
            }
        }
        return 0;
    }
}
