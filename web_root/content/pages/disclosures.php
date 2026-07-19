<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _disclosures extends PageContextFramework
{
    public function id(): string
    {
        return 'disclosures';
    }

    public function title(): string
    {
        return 'Digital Data Export';
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
            'ixbrl_accounts_disclosures',
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
                'tab' => 'Disclosures',
                'cards' => ['ixbrl_accounts_disclosures'],
            ],
            [
                'tab' => 'Accounts Mapping',
                'cards' => ['ixbrl_accounts_mapping'],
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
        try {
            $readiness = (new \eel_accounts\Service\IxbrlReadinessService())
                ->getReadiness($companyId, $accountingPeriodId);
        } catch (Throwable $exception) {
            $readiness = [
                'company' => $company,
                'accounting_period' => [],
                'checks' => [[
                    'key' => 'ixbrl_read_model_error',
                    'label' => 'iXBRL source data reconciles',
                    'complete' => false,
                    'blocking' => true,
                    'blocking_stages' => ['build', 'generate', 'filing'],
                    'status' => 'danger',
                    'status_label' => 'Build blocked',
                    'detail' => $exception->getMessage(),
                ]],
                'blocking_errors' => [$exception->getMessage()],
                'generation_errors' => [$exception->getMessage()],
                'filing_errors' => [$exception->getMessage()],
                'can_build_facts' => false,
                'can_generate' => false,
                'can_validate' => false,
                'ready_for_filing' => false,
                'disclosures' => [],
            ];
        }
        $latestRun = $builder->getLatestRun($companyId, $accountingPeriodId);
        $facts = is_array($latestRun) ? $builder->getFacts((int)$latestRun['id']) : [];
        try {
            $accountsMapping = (new \eel_accounts\Service\IxbrlAccountsMappingService())
                ->getAccountsMapping($companyId, $accountingPeriodId);
        } catch (Throwable $exception) {
            $accountsMapping = ['errors' => [$exception->getMessage()]];
        }
        try {
            $periodProjection = (new \eel_accounts\Service\CorporationTaxPeriodService())
                ->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $filingReadiness = (new \eel_accounts\Service\Ct600FilingReadinessService())->fetch(
                $companyId,
                $accountingPeriodId,
                (array)($periodProjection['periods'] ?? []),
                $company,
                $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : []
            );
        } catch (Throwable $exception) {
            $filingReadiness = [[
                'label' => 'CT600 filing prerequisites',
                'ready' => false,
                'detail' => $exception->getMessage(),
            ]];
        }

        return [
            'ixbrl' => [
                'readiness' => $readiness,
                'ct600_filing_readiness' => $filingReadiness,
                'disclosures' => (array)($readiness['disclosures'] ?? []),
                'accounts_mapping' => $accountsMapping,
                'latest_run' => $latestRun,
                'facts' => $facts,
            ],
        ];
    }
}
