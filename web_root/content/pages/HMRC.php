<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _hmrc extends PageContextFramework
{
    public function id(): string { return 'HMRC'; }

    public function title(): string { return 'HMRC'; }

    public function subtitle(): string { return 'Track HMRC obligations, Corporation Tax deadlines, filings, payments, penalties, interest, and submissions.'; }

    public function ajaxPendingBlurScope(): string { return 'page'; }

    public function cards(): array
    {
        return [
            'hmrc_obligations_summary', 'hmrc_obligations_timeline', 'hmrc_obligations_period_checklist',
            'hmrc_obligations_action_panel', 'hmrc_fines_table', 'hmrc_submission_unavailable',
        ];
    }

    public function cardLayout(): array
    {
        return [
            ['tab' => 'Overview', 'cards' => ['hmrc_obligations_summary', 'hmrc_obligations_action_panel']],
            ['tab' => 'Timeline', 'cards' => ['hmrc_obligations_timeline']],
            ['tab' => 'Selected Period', 'cards' => ['hmrc_obligations_period_checklist']],
            ['tab' => 'Fines & Interest', 'cards' => ['hmrc_fines_table']],
            ['tab' => 'Submit', 'cards' => ['hmrc_submission_unavailable']],
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
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $filter = trim((string)$request->input('hmrc_filter', 'all'));
        $service = new \eel_accounts\Service\HmrcObligationService();
        $accountingPeriods = $companyId > 0
            ? (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId)
            : [];
        if ($companyId > 0) {
            $service->syncObligationsForCompany($companyId);
        } else {
            $service->ensureSchema();
        }

        $allObligations = $service->listObligations($companyId, ['filter' => 'all']);
        $timelineObligations = $service->listObligations($companyId, ['filter' => $filter]);
        $selectedPeriodEnd = trim((string)(($baseContext['accounting_period'] ?? [])['period_end'] ?? ''));
        $firstPeriodStart = trim((string)($accountingPeriods[0]['period_start'] ?? ''));
        $scopedObligations = $this->obligationsThroughSelectedPeriod($allObligations, $firstPeriodStart, $selectedPeriodEnd);
        $scopedTimeline = $this->obligationsThroughSelectedPeriod($timelineObligations, $firstPeriodStart, $selectedPeriodEnd);
        $laterObligationCount = count(array_filter(
            $allObligations,
            static fn(array $item): bool => $selectedPeriodEnd !== ''
                && (string)($item['period_start'] ?? '') > $selectedPeriodEnd
        ));
        $laterObligationWarning = $laterObligationCount === 1
            ? 'There is 1 additional HMRC obligation in a later accounting period.'
            : 'There are ' . $laterObligationCount . ' additional HMRC obligations in later accounting periods.';
        return [
            'hmrc_obligations' => [
                'filter' => $filter, 'filters' => $service->filters(),
                'summary' => $service->getOutstandingSummary($companyId, $scopedObligations),
                'timeline' => $scopedTimeline, 'all_obligations' => $scopedObligations,
                'later_obligation_count' => $laterObligationCount,
                'later_obligation_warning' => $laterObligationWarning,
                'checklist' => $service->periodChecklist($companyId, $accountingPeriodId),
                'guidance' => $service->getGuidanceState($companyId),
            ],
        ];
    }

    private function obligationsThroughSelectedPeriod(array $obligations, string $firstPeriodStart, string $selectedPeriodEnd): array
    {
        if ($firstPeriodStart === '' || $selectedPeriodEnd === '') {
            return [];
        }

        return array_values(array_filter(
            $obligations,
            static fn(array $item): bool => (string)($item['period_start'] ?? '') >= $firstPeriodStart
                && (string)($item['period_end'] ?? '') <= $selectedPeriodEnd
        ));
    }
}
