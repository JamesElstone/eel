<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _year_end extends PageContextFramework
{
    public function id(): string
    {
        return 'year_end';
    }

    public function title(): string
    {
        return 'Year End';
    }

    public function subtitle(): string
    {
        return 'Work through the year-end checklist, review the workspace, and inspect recent year-end audit activity.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'year_end_checklist',
            'year_end_director_loan_offset',
            'year_end_expenses_confirmation',
            'year_end_retained_earnings',
            'year_end_tax_readiness',
            'year_end_empty_month_confirmations',
            'year_end_notes',
            'year_end_state',
            'year_end_audit_log',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Checklist',
                'cards' => [
                    'year_end_checklist',
                ],
            ],
            [
                'tab' => 'Director',
                'cards' => [
                    'year_end_director_loan_offset',
                ],
            ],
            [
                'tab' => 'Expenses',
                'cards' => [
                    'year_end_expenses_confirmation',
                ],
            ],
            [
                'tab' => 'Retained Earnings',
                'cards' => [
                    'year_end_retained_earnings',
                ],
            ],
            [
                'tab' => 'Tax',
                'cards' => [
                    'year_end_tax_readiness',
                ],
            ],
            [
                'tab' => 'Transactions',
                'cards' => [
                    'year_end_empty_month_confirmations',
                ],
            ],
            [
                'tab' => 'Notes',
                'cards' => [
                    'year_end_notes',
                ],
            ],
            [
                'tab' => 'Final',
                'cards' => [
                    'year_end_state',
                ],
            ],
            [
                'tab' => 'Audit',
                'cards' => [
                    'year_end_audit_log',
                ],
            ],
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
        $checklist = $companyId > 0 && $accountingPeriodId > 0
            ? ((new \eel_accounts\Service\YearEndChecklistService())->fetchChecklist($companyId, $accountingPeriodId, false) ?? [])
            : [];

        return [
            'year_end' => [
                'checklist' => $checklist,
                'checklist_has_warnings' => $this->checklistHasWarnings($checklist),
            ],
            'year_end_audit_rows' => (new \eel_accounts\Repository\AccountingAuditRepository())->fetchRecentYearEndAudit(200),
        ];
    }

    private function checklistHasWarnings(array $checklist): bool
    {
        foreach ((array)($checklist['checks_flat'] ?? []) as $check) {
            if (in_array((string)(((array)$check)['status'] ?? ''), ['fail', 'needs_attention', 'warning', 'not_started'], true)) {
                return true;
            }
        }

        return false;
    }
}
