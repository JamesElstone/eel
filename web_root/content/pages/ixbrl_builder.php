<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _ixbrl_builder extends PageContextFramework
{
    public function id(): string
    {
        return 'ixbrl_builder';
    }

    public function title(): string
    {
        return 'FRS 105 Accounts / iXBRL Export';
    }

    public function subtitle(): string
    {
        return 'Prepare a traceable FRS 105 micro-entity accounts iXBRL export from journals through trial balance, statutory mapping, facts, validation, and generated XHTML.';
    }

    public function ajaxPendingBlurScope(): string
    {
        return 'page';
    }

    public function cards(): array
    {
        return [
            'ixbrl_readiness',
            'ixbrl_trial_balance',
            'ixbrl_accounts_mapping',
            'ixbrl_facts_preview',
            'ixbrl_generation',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Overview',
                'cards' => ['ixbrl_readiness'],
            ],
            [
                'tab' => 'Trial Balance',
                'cards' => ['ixbrl_trial_balance', 'ixbrl_accounts_mapping'],
            ],
            [
                'tab' => 'Facts',
                'cards' => ['ixbrl_facts_preview'],
            ],
            [
                'tab' => 'Generation',
                'cards' => ['ixbrl_generation'],
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

        $builder = new \eel_accounts\Service\IxbrlFactBuilderService();
        $builder->ensureSchema();
        $readiness = (new \eel_accounts\Service\IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
        $latestRun = $builder->getLatestRun($companyId, $accountingPeriodId);
        $facts = is_array($latestRun) ? $builder->getFacts((int)$latestRun['id']) : [];

        return [
            'ixbrl' => [
                'readiness' => $readiness,
                'trial_balance' => (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTrialBalance($companyId, $accountingPeriodId),
                'trial_balance_totals' => (new \eel_accounts\Service\IxbrlTrialBalanceService())->getTotals($companyId, $accountingPeriodId),
                'accounts_mapping' => (new \eel_accounts\Service\IxbrlAccountsMappingService())->getAccountsMapping($companyId, $accountingPeriodId),
                'latest_run' => $latestRun,
                'facts' => $facts,
            ],
        ];
    }
}
