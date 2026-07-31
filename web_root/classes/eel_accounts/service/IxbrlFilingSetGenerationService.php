<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Plans and generates the complete filing iXBRL set. Planning reports current
 * artifacts, while an explicit generation command rebuilds every required file.
 */
final class IxbrlFilingSetGenerationService
{
    private ?\Closure $readinessResolver;
    private ?\Closure $periodProjectionResolver;
    private ?\Closure $companiesHouseResolver;
    private ?\Closure $accountsGenerator;
    private ?\Closure $accountsValidator;
    private ?\Closure $computationStatusResolver;
    private ?\Closure $computationGenerator;
    private ?\Closure $ct600Generator;
    private ?\Closure $companiesHousePreparer;
    private ?\Closure $revisionReadinessResolver;

    public function __construct(
        ?callable $readinessResolver = null,
        ?callable $periodProjectionResolver = null,
        ?callable $companiesHouseResolver = null,
        ?callable $accountsGenerator = null,
        ?callable $accountsValidator = null,
        ?callable $computationStatusResolver = null,
        ?callable $computationGenerator = null,
        ?callable $companiesHousePreparer = null,
        ?callable $revisionReadinessResolver = null,
        ?callable $ct600Generator = null,
        private readonly ?IxbrlFilingOperationLockService $lockService = null,
    ) {
        $this->readinessResolver = $this->closure($readinessResolver);
        $this->periodProjectionResolver = $this->closure($periodProjectionResolver);
        $this->companiesHouseResolver = $this->closure($companiesHouseResolver);
        $this->accountsGenerator = $this->closure($accountsGenerator);
        $this->accountsValidator = $this->closure($accountsValidator);
        $this->computationStatusResolver = $this->closure($computationStatusResolver);
        $this->computationGenerator = $this->closure($computationGenerator);
        $this->ct600Generator = $this->closure($ct600Generator);
        $this->companiesHousePreparer = $this->closure($companiesHousePreparer);
        $this->revisionReadinessResolver = $this->closure($revisionReadinessResolver);
    }

    public function plan(int $companyId, int $accountingPeriodId): array
    {
        $revisionReadiness = $this->revisionReadiness($companyId, $accountingPeriodId);
        if (!empty($revisionReadiness['applicable']) && empty($revisionReadiness['ready'])) {
            $errors = (array)($revisionReadiness['errors'] ?? [
                'The Companies House revised-accounts prerequisites are incomplete.',
            ]);
            return [
                'ready' => false,
                'errors' => array_values(array_unique($errors)),
                'accounts' => $this->stage('blocked', $errors),
                'computations' => [],
                'companies_house' => ['filing_kind' => 'revised']
                    + $this->stage('blocked', $errors),
                'basis' => [],
            ];
        }
        $readiness = $this->readiness($companyId, $accountingPeriodId);
        $accounts = !empty($readiness['ready_for_filing'])
            ? $this->stage('current')
            : (!empty($readiness['can_generate'])
                ? $this->stage('generate')
                : $this->stage(
                    'blocked',
                    (array)($readiness['generation_errors'] ?? [
                        'The Accounting iXBRL is not ready to generate.',
                    ])
                ));

        $projection = $this->periodProjection($companyId, $accountingPeriodId);
        $periods = array_values(array_filter(
            (array)($projection['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $computations = [];
        if ($periods === []) {
            $computations[] = [
                'ct_period_id' => 0,
                'sequence_no' => 0,
            ] + $this->stage('blocked', ['No current CT periods are available for computations generation.']);
        } else {
            foreach ($periods as $period) {
                $ctPeriodId = (int)($period['ct_period_id'] ?? $period['id'] ?? 0);
                $sequence = (int)($period['sequence_no'] ?? $ctPeriodId);
                if ($ctPeriodId <= 0) {
                    $computations[] = [
                        'ct_period_id' => 0,
                        'sequence_no' => $sequence,
                    ] + $this->stage('blocked', ['A projected CT period has no valid identifier.']);
                    continue;
                }
                $status = $this->computationStatus($companyId, $accountingPeriodId, $ctPeriodId);
                $stage = !empty($status['fileable'])
                    ? $this->stage('current')
                    : (!empty($status['ready'])
                        ? $this->stage('generate')
                        : $this->stage('blocked', array_values(array_unique(array_merge(
                            (array)($status['errors'] ?? []),
                            (array)($status['artifact_errors'] ?? [])
                        )))));
                $computations[] = [
                    'ct_period_id' => $ctPeriodId,
                    'sequence_no' => $sequence,
                ] + $stage;
            }
        }

        $companiesHouseContext = $this->companiesHouseContext($companyId, $accountingPeriodId);
        $companiesHouse = $this->companiesHouseStage($companiesHouseContext, $accounts['state']);
        $errors = $accounts['state'] === 'blocked' ? (array)$accounts['errors'] : [];
        foreach ($computations as $computationStage) {
            if ((string)$computationStage['state'] !== 'blocked') {
                continue;
            }
            foreach ((array)$computationStage['errors'] as $error) {
                $errors[] = 'CT period #' . (int)$computationStage['ct_period_id']
                    . ': ' . (string)$error;
            }
        }
        if ($companiesHouse['state'] === 'blocked') {
            $errors = array_merge($errors, (array)$companiesHouse['errors']);
        }

        return [
            'ready' => $errors === [],
            'errors' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            )))),
            'accounts' => $accounts,
            'computations' => $computations,
            'companies_house' => $companiesHouse,
            'basis' => [
                'filing_approval_id' => (int)(($readiness['latest_run'] ?? [])['filing_approval_id'] ?? 0),
                'filing_approval_hash' => (string)(($readiness['latest_run'] ?? [])['filing_approval_hash'] ?? ''),
                'companies_house_classification_hash' => (string)(
                    ($companiesHouseContext['filing_classification'] ?? [])['approval_basis_hash'] ?? ''
                ),
            ],
        ];
    }

    public function generate(
        int $companyId,
        int $accountingPeriodId,
        string $actor,
        mixed $progress = null
    ): array {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        try {
            return (array)($this->lockService ?? new IxbrlFilingOperationLockService())->execute(
                $companyId,
                $accountingPeriodId,
                fn(): array => $this->generateLocked(
                    $companyId,
                    $accountingPeriodId,
                    $actor,
                    $progress
                )
            );
        } catch (\Throwable $exception) {
            return $this->failure([$exception->getMessage()]);
        }
    }

    private function generateLocked(
        int $companyId,
        int $accountingPeriodId,
        string $actor,
        mixed $progress
    ): array {
        $this->report($progress, 'Checking the complete filing-set prerequisites…', 0);
        $plan = $this->plan($companyId, $accountingPeriodId);
        if (empty($plan['ready'])) {
            return $this->failure((array)$plan['errors'], [], [], $plan);
        }

        $messages = [];
        $warnings = [];
        $this->report($progress, 'Generating the Accounting iXBRL…', 12);
        $generated = $this->generateAccounts($companyId, $accountingPeriodId);
        if (empty($generated['success'])) {
            return $this->failure((array)($generated['errors'] ?? [
                'Accounting iXBRL generation failed.',
            ]), (array)($generated['warnings'] ?? []), $messages, $plan);
        }
        $this->report($progress, 'Running Arelle validation for the Accounting iXBRL…', 15);
        $validated = $this->validateAccounts($companyId, $accountingPeriodId);
        if ((string)($validated['status'] ?? '') !== 'passed') {
            return $this->failure((array)($validated['errors'] ?? [
                'The Accounting iXBRL did not pass Arelle validation.',
            ]), (array)($validated['warnings'] ?? []), $messages, $plan);
        }
        \eel_accounts\Support\RequestCache::clear();
        $readiness = $this->readiness($companyId, $accountingPeriodId);
        if (empty($readiness['ready_for_filing'])) {
            return $this->failure((array)($readiness['filing_errors'] ?? [
                'The generated Accounting iXBRL is not filing-ready.',
            ]), [], $messages, $plan);
        }
        $messages[] = 'Accounting iXBRL generated and validated.';
        $warnings = array_merge($warnings, (array)($generated['warnings'] ?? []));

        // HMRC must attach the same revised statutory accounts artifact used
        // for Companies House. Prepare it before CT600 generation so the
        // package builder can resolve the current shared artifact.
        $preparedRevisedAccounts = false;
        $companiesHouseContext = $this->companiesHouseContext($companyId, $accountingPeriodId);
        $companiesHouseStage = $this->companiesHouseStage($companiesHouseContext, 'current');
        if ((string)$companiesHouseStage['state'] === 'blocked') {
            return $this->failure((array)$companiesHouseStage['errors'], $warnings, $messages, $plan);
        }
        if ((string)$companiesHouseStage['state'] !== 'not_required'
            && (string)($companiesHouseContext['filing_kind'] ?? '') === 'revised') {
            $this->report($progress, 'Preparing the Companies House revised-accounts iXBRL…', 20);
            $prepared = $this->prepareCompaniesHouse(
                $companyId,
                $accountingPeriodId,
                $actor,
                function (string $message, int $percent) use ($progress): void {
                    $this->report($progress, $message, min(47, 20 + (int)floor($percent * 0.27)));
                }
            );
            if (empty($prepared['success'])) {
                return $this->failure(
                    (array)($prepared['errors'] ?? ['Companies House revised accounts iXBRL preparation failed.']),
                    array_merge($warnings, (array)($prepared['warnings'] ?? [])),
                    $messages,
                    $plan
                );
            }
            $messages[] = 'Companies House Revised accounts iXBRL prepared.';
            $warnings = array_merge($warnings, (array)($prepared['warnings'] ?? []));
            $preparedRevisedAccounts = true;
            \eel_accounts\Support\RequestCache::clear();
        }

        $periodCount = count((array)$plan['computations']);
        foreach ((array)$plan['computations'] as $index => $computationStage) {
            $ctPeriodId = (int)$computationStage['ct_period_id'];
            $sequence = (int)$computationStage['sequence_no'];
            $periodShare = 24 / max(1, $periodCount);
            $percent = 49 + (int)floor(max(0, $index) * $periodShare);
            $validationPercent = min(
                72,
                $percent + max(1, (int)floor($periodShare * 0.2))
            );
            $this->report(
                $progress,
                'Generating iXBRL for Corporation Tax period '
                    . ($index + 1) . ' of ' . $periodCount . '…',
                $percent
            );
            $computation = $this->generateComputation(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                function () use ($progress, $index, $periodCount, $validationPercent): void {
                    $this->report(
                        $progress,
                        'Running Arelle validation for Corporation Tax period '
                            . ($index + 1) . ' of ' . $periodCount . '…',
                        $validationPercent
                    );
                }
            );
            if (empty($computation['success'])) {
                return $this->failure(array_map(
                    static fn(mixed $error): string => 'CT period #' . $ctPeriodId . ': ' . (string)$error,
                    (array)($computation['errors'] ?? ['Computations iXBRL generation failed.'])
                ), array_merge($warnings, (array)($computation['warnings'] ?? [])), $messages, $plan);
            }
            $status = $this->computationStatus($companyId, $accountingPeriodId, $ctPeriodId);
            if (empty($status['fileable'])) {
                return $this->failure(array_map(
                    static fn(mixed $error): string => 'CT period #' . $ctPeriodId . ': ' . (string)$error,
                    (array)($status['fileable_errors'] ?? ['The generated computation is not filing-ready.'])
                ), $warnings, $messages, $plan);
            }
            $messages[] = 'Corporation Tax period ' . $sequence . ' iXBRL generated and validated.';
            $warnings = array_merge($warnings, (array)($computation['warnings'] ?? []));
            $ct600Percent = min(
                72,
                $validationPercent + max(1, (int)floor($periodShare * 0.35))
            );
            $this->report(
                $progress,
                'Generating CT600 XML for Corporation Tax period '
                    . ($index + 1) . ' of ' . $periodCount . '…',
                $ct600Percent
            );
            $ct600EndPercent = min(
                72,
                $ct600Percent + max(1, (int)floor($periodShare * 0.4))
            );
            $ct600 = $this->generateCt600(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                function (string $message, int $artifactPercent) use (
                    $progress,
                    $ct600Percent,
                    $ct600EndPercent
                ): void {
                    $span = max(1, $ct600EndPercent - $ct600Percent);
                    $mapped = $ct600Percent + (int)floor(
                        max(0, min(100, $artifactPercent)) * $span / 100
                    );
                    $this->report($progress, $message, min(72, $mapped));
                }
            );
            if (empty($ct600['success'])) {
                return $this->failure(array_map(
                    static fn(mixed $error): string => 'CT period #' . $ctPeriodId
                        . ' CT600 XML: ' . (string)$error,
                    (array)($ct600['errors'] ?? ['CT600 XML generation failed.'])
                ), array_merge($warnings, (array)($ct600['warnings'] ?? [])), $messages, $plan);
            }
            $messages[] = 'Corporation Tax period ' . $sequence
                . ' CT600 XML generated and validated.';
            $warnings = array_merge($warnings, (array)($ct600['warnings'] ?? []));
        }

        \eel_accounts\Support\RequestCache::clear();
        $companiesHouseContext = $this->companiesHouseContext($companyId, $accountingPeriodId);
        $companiesHouseStage = $this->companiesHouseStage($companiesHouseContext, 'current');
        if ((string)$companiesHouseStage['state'] === 'blocked') {
            return $this->failure(
                (array)$companiesHouseStage['errors'],
                $warnings,
                $messages,
                $plan
            );
        }
        if ($preparedRevisedAccounts) {
            // Already prepared before CT600 generation because HMRC shares it.
        } elseif ((string)$companiesHouseStage['state'] === 'not_required') {
            $messages[] = 'No Companies House filing artifact is required for this accounting period.';
        } else {
            $kind = (string)($companiesHouseContext['filing_kind'] ?? '');
            $this->report(
                $progress,
                'Preparing the Companies House ' . $kind . '-accounts iXBRL…',
                73
            );
            $companiesHouseProgress = function (string $message, int $percent) use ($progress): void {
                $this->report($progress, $message, min(99, 73 + (int)floor($percent * 0.26)));
            };
            $prepared = $this->prepareCompaniesHouse(
                $companyId,
                $accountingPeriodId,
                $actor,
                $companiesHouseProgress
            );
            if (empty($prepared['success'])) {
                return $this->failure(
                    (array)($prepared['errors'] ?? ['Companies House accounts iXBRL preparation failed.']),
                    array_merge($warnings, (array)($prepared['warnings'] ?? [])),
                    $messages,
                    $plan
                );
            }
            $messages[] = 'Companies House ' . ucfirst($kind) . ' accounts iXBRL prepared.';
            $warnings = array_merge($warnings, (array)($prepared['warnings'] ?? []));
        }

        $this->report($progress, 'The filing iXBRL set is complete.', 100);
        return [
            'success' => true,
            'errors' => [],
            'warnings' => array_values(array_unique($warnings)),
            'messages' => array_values(array_unique($messages)),
            'plan' => $plan,
        ];
    }

    private function companiesHouseStage(array $context, string $accountsState): array
    {
        if (empty($context['filing_required'])) {
            return ['filing_kind' => (string)($context['filing_kind'] ?? '')]
                + $this->stage('not_required');
        }
        $artifact = (array)($context['prepared_artifact'] ?? []);
        $filingKind = strtolower(trim((string)($context['filing_kind'] ?? '')));
        $validationCurrent = $filingKind !== 'revised'
            || (string)(($context['revised_validation'] ?? [])['status'] ?? '') === 'passed';
        $current = (!empty($artifact['current']) || (string)($artifact['state'] ?? '') === 'current')
            && trim((string)($artifact['filename'] ?? '')) !== ''
            && $validationCurrent;
        if ($current) {
            return ['filing_kind' => (string)($context['filing_kind'] ?? '')]
                + $this->stage('current');
        }
        if (!empty($context['can_prepare'])
            || ($accountsState === 'generate'
                && !empty($context['can_prepare_after_accounts_generation']))) {
            return ['filing_kind' => (string)($context['filing_kind'] ?? '')]
                + $this->stage('prepare');
        }
        return ['filing_kind' => (string)($context['filing_kind'] ?? '')]
            + $this->stage('blocked', (array)($context['preparation_blockers'] ?? [
                'The Companies House accounts iXBRL is not ready to prepare.',
            ]));
    }

    private function stage(string $state, array $errors = []): array
    {
        return [
            'state' => $state,
            'errors' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            )))),
        ];
    }

    private function failure(
        array $errors,
        array $warnings = [],
        array $messages = [],
        array $plan = []
    ): array {
        return [
            'success' => false,
            'errors' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            )))),
            'warnings' => array_values(array_unique($warnings)),
            'messages' => array_values(array_unique($messages)),
            'plan' => $plan,
        ];
    }

    private function readiness(int $companyId, int $accountingPeriodId): array
    {
        return $this->readinessResolver !== null
            ? (array)($this->readinessResolver)($companyId, $accountingPeriodId)
            : (new IxbrlReadinessService())->getReadiness($companyId, $accountingPeriodId);
    }

    private function revisionReadiness(int $companyId, int $accountingPeriodId): array
    {
        return $this->revisionReadinessResolver !== null
            ? (array)($this->revisionReadinessResolver)($companyId, $accountingPeriodId)
            : (new CompaniesHouseRevisedAccountsReadinessService())->assess(
                $companyId,
                $accountingPeriodId
            );
    }

    private function periodProjection(int $companyId, int $accountingPeriodId): array
    {
        return $this->periodProjectionResolver !== null
            ? (array)($this->periodProjectionResolver)($companyId, $accountingPeriodId)
            : (new CorporationTaxPeriodService())->projectForAccountingPeriod($companyId, $accountingPeriodId);
    }

    private function companiesHouseContext(int $companyId, int $accountingPeriodId): array
    {
        return $this->companiesHouseResolver !== null
            ? (array)($this->companiesHouseResolver)($companyId, $accountingPeriodId)
            : (new CompaniesHouseAccountsSubmissionService())->fetchContext($companyId, $accountingPeriodId);
    }

    private function generateAccounts(int $companyId, int $accountingPeriodId): array
    {
        return $this->accountsGenerator !== null
            ? (array)($this->accountsGenerator)($companyId, $accountingPeriodId)
            : (new IxbrlAccountingService())->generateFilingExport($companyId, $accountingPeriodId);
    }

    private function validateAccounts(int $companyId, int $accountingPeriodId): array
    {
        return $this->accountsValidator !== null
            ? (array)($this->accountsValidator)($companyId, $accountingPeriodId)
            : (new IxbrlExternalValidationService())->validateLatestRun($companyId, $accountingPeriodId);
    }

    private function computationStatus(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        return $this->computationStatusResolver !== null
            ? (array)($this->computationStatusResolver)(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId
            )
            : (new IxbrlTaxComputationService())->status(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId
            );
    }

    private function generateComputation(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        ?\Closure $beforeExternalValidation = null
    ): array {
        return $this->computationGenerator !== null
            ? (array)($this->computationGenerator)(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $beforeExternalValidation
            )
            : (new IxbrlTaxComputationService())->generateFilingExport(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $beforeExternalValidation
            );
    }

    private function prepareCompaniesHouse(
        int $companyId,
        int $accountingPeriodId,
        string $actor,
        mixed $progress
    ): array {
        return $this->companiesHousePreparer !== null
            ? (array)($this->companiesHousePreparer)(
                $companyId,
                $accountingPeriodId,
                $actor,
                $progress
            )
            : (new CompaniesHouseAccountsSubmissionService())->prepareAccounts(
                $companyId,
                $accountingPeriodId,
                [],
                $actor,
                $progress
            );
    }

    private function generateCt600(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        mixed $progress = null
    ): array {
        return $this->ct600Generator !== null
            ? (array)($this->ct600Generator)(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $progress
            )
            : (new Ct600GenerationService())->generate(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                is_callable($progress) ? $progress : null
            );
    }

    private function report(mixed $progress, string $message, int $percent): void
    {
        if ($progress instanceof \ActionProgressFramework) {
            $progress->report($message, $percent);
        } elseif (is_callable($progress)) {
            $progress($message, $percent);
        }
    }

    private function closure(?callable $callback): ?\Closure
    {
        return $callback !== null ? \Closure::fromCallable($callback) : null;
    }
}
