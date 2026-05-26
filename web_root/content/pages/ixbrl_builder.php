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
        return 'FRS 105 Accounts / iXBRL Preview';
    }

    public function subtitle(): string
    {
        return 'Prepare a traceable FRS 105 micro-entity accounts preview from journals through trial balance, statutory mapping, facts, and generated XHTML.';
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
        $taxYearId = (int)($company['tax_year_id'] ?? 0);

        $builder = new IxbrlFactBuilderService();
        $builder->ensureSchema();
        $readiness = (new IxbrlReadinessService())->getReadiness($companyId, $taxYearId);
        $latestRun = $builder->getLatestRun($companyId, $taxYearId);
        $facts = is_array($latestRun) ? $builder->getFacts((int)$latestRun['id']) : [];

        return [
            'ixbrl' => [
                'readiness' => $readiness,
                'trial_balance' => (new IxbrlTrialBalanceService())->getTrialBalance($companyId, $taxYearId),
                'trial_balance_totals' => (new IxbrlTrialBalanceService())->getTotals($companyId, $taxYearId),
                'accounts_mapping' => (new IxbrlAccountsMappingService())->getAccountsMapping($companyId, $taxYearId),
                'latest_run' => $latestRun,
                'facts' => $facts,
            ],
        ];
    }
}
