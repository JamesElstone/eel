<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc_obligations extends PageContextFramework
{
    public function id(): string
    {
        return 'hmrc_obligations';
    }

    public function title(): string
    {
        return 'HMRC Obligations';
    }

    public function subtitle(): string
    {
        return 'Track CT deadlines, HMRC filings, payments, penalties, interest, and historic unresolved periods.';
    }

    public function cards(): array
    {
        return [
            'hmrc_obligations_summary',
            'hmrc_obligations_timeline',
            'hmrc_obligations_period_checklist',
            'hmrc_obligations_action_panel',
            'hmrc_fines_table',
        ];
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'Overview', 'cards' => ['hmrc_obligations_summary', 'hmrc_obligations_action_panel']],
            ['tab' => 'Timeline', 'cards' => ['hmrc_obligations_timeline']],
            ['tab' => 'Selected Period', 'cards' => ['hmrc_obligations_period_checklist']],
            ['tab' => 'Fines & Interest', 'cards' => ['hmrc_fines_table']],
        ];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $taxYearId = (int)($company['tax_year_id'] ?? 0);
        $filter = trim((string)$request->input('hmrc_filter', 'all'));
        $service = new HmrcObligationService();
        if ($companyId > 0) {
            $service->syncObligationsForCompany($companyId);
        } else {
            $service->ensureSchema();
        }

        return [
            'hmrc_obligations' => [
                'filter' => $filter,
                'filters' => $service->filters(),
                'summary' => $service->getOutstandingSummary($companyId),
                'timeline' => $service->listObligations($companyId, ['filter' => $filter]),
                'all_obligations' => $service->listObligations($companyId, ['filter' => 'all']),
                'checklist' => $service->periodChecklist($companyId, $taxYearId),
                'guidance' => $service->getGuidanceState($companyId),
            ],
        ];
    }
}
