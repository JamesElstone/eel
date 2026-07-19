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

        /** @var \eel_accounts\Service\IxbrlFactBuilderService $builder */
        $builder = $services->get(\eel_accounts\Service\IxbrlFactBuilderService::class);
        /** @var \eel_accounts\Service\IxbrlReadinessService $readinessService */
        $readinessService = $services->get(\eel_accounts\Service\IxbrlReadinessService::class);
        try {
            $readiness = $readinessService->getReadiness($companyId, $accountingPeriodId);
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
        /** @var \eel_accounts\Service\IxbrlAccountsMappingService $accountsMappingService */
        $accountsMappingService = $services->get(\eel_accounts\Service\IxbrlAccountsMappingService::class);
        try {
            $accountsMapping = $accountsMappingService->getAccountsMapping($companyId, $accountingPeriodId);
        } catch (Throwable $exception) {
            $accountsMapping = ['errors' => [$exception->getMessage()]];
        }
        /** @var \eel_accounts\Service\CorporationTaxPeriodService $corporationTaxPeriodService */
        $corporationTaxPeriodService = $services->get(\eel_accounts\Service\CorporationTaxPeriodService::class);
        /** @var \eel_accounts\Service\IxbrlTaxComputationService $computationService */
        $computationService = $services->get(\eel_accounts\Service\IxbrlTaxComputationService::class);
        /** @var \eel_accounts\Service\Ct600FilingReadinessService $filingReadinessService */
        $filingReadinessService = $services->get(\eel_accounts\Service\Ct600FilingReadinessService::class);
        try {
            $periodProjection = $corporationTaxPeriodService->projectForAccountingPeriod($companyId, $accountingPeriodId);
            $ctPeriods = array_values(array_filter(
                (array)($periodProjection['periods'] ?? []),
                static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
            ));
            $computationPeriods = [];
            foreach ($ctPeriods as $period) {
                $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
                $computationPeriods[] = [
                    'ct_period' => $period,
                    'status' => $computationService->status($companyId, $accountingPeriodId, $ctPeriodId),
                ];
            }
            $filingReadiness = $filingReadinessService->fetch(
                $companyId,
                $accountingPeriodId,
                $ctPeriods,
                $company,
                $companyId > 0 ? (new \eel_accounts\Store\CompanySettingsStore($companyId))->all() : []
            );
        } catch (Throwable $exception) {
            $computationPeriods = [[
                'ct_period' => [],
                'status' => ['ready' => false, 'fresh' => false, 'fileable' => false, 'errors' => [$exception->getMessage()]],
            ]];
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
                'computation_periods' => $computationPeriods ?? [],
            ],
        ];
    }
}
