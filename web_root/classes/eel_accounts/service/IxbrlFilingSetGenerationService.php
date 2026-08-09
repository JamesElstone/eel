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
        try {
            $readiness = $this->readiness($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            $readiness = [
                'ready_for_filing' => false,
                'can_generate' => false,
                'generation_errors' => [$exception->getMessage()],
            ];
        }
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

        try {
            $projection = $this->periodProjection($companyId, $accountingPeriodId);
        } catch (\Throwable $exception) {
            $projection = [
                'periods' => [],
                'errors' => [$exception->getMessage()],
            ];
        }
        $periods = array_values(array_filter(
            (array)($projection['periods'] ?? []),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $computations = [];
        if ($periods === []) {
            $projectionErrors = (array)($projection['errors'] ?? []);
            if ($projectionErrors === []) {
                $projectionErrors[] = 'No current CT periods are available for computations generation.';
            }
            $computations[] = [
                'ct_period_id' => 0,
                'sequence_no' => 0,
            ] + $this->stage('blocked', $projectionErrors);
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
                try {
                    $status = $this->computationStatus($companyId, $accountingPeriodId, $ctPeriodId);
                } catch (\Throwable $exception) {
                    $status = [
                        'ready' => false,
                        'fileable' => false,
                        'errors' => [$exception->getMessage()],
                    ];
                }
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

        try {
            $companiesHouseContext = $this->companiesHouseContext($companyId, $accountingPeriodId);
            $companiesHouse = $this->companiesHouseStage($companiesHouseContext);
            $revisionReadiness = $this->revisionReadiness($companyId, $accountingPeriodId);
            if (!empty($companiesHouseContext['filing_required'])
                && !empty($revisionReadiness['applicable'])
                && empty($revisionReadiness['ready'])) {
                $revisionErrors = (array)($revisionReadiness['errors'] ?? [
                    'The Companies House revised-accounts prerequisites are incomplete.',
                ]);
                $companiesHouse = ['filing_kind' => (string)($companiesHouseContext['filing_kind'] ?? 'revised')]
                    + $this->stage('blocked', $revisionErrors);
            }
        } catch (\Throwable $exception) {
            $companiesHouseContext = [];
            $companiesHouse = ['filing_kind' => '']
                + $this->stage('blocked', [$exception->getMessage()]);
        }
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
        $messages = [];
        $warnings = [];
        $stageResults = [
            'hmrc_accounts' => $this->executionStage('pending'),
            'hmrc_computations' => [],
            'hmrc_ct600' => [],
            'companies_house_accounts' => $this->executionStage('pending'),
        ];

        $accountsReady = false;
        if ((string)($plan['accounts']['state'] ?? '') === 'blocked') {
            $stageResults['hmrc_accounts'] = $this->executionStage(
                'failed',
                (array)($plan['accounts']['errors'] ?? ['HMRC accounts iXBRL is blocked.'])
            );
        } else {
            try {
                $this->report($progress, 'Generating the HMRC accounts iXBRL…', 8);
                $generated = $this->generateAccounts($companyId, $accountingPeriodId);
                $warnings = array_merge($warnings, (array)($generated['warnings'] ?? []));
                if (empty($generated['success'])) {
                    $stageResults['hmrc_accounts'] = $this->executionStage(
                        'failed',
                        (array)($generated['errors'] ?? ['HMRC accounts iXBRL generation failed.']),
                        (array)($generated['warnings'] ?? []),
                        [],
                        ['artifact' => $this->artifactDescriptor(
                            'hmrc_accounts_ixbrl',
                            'HMRC',
                            $companyId,
                            $accountingPeriodId,
                            null,
                            $generated
                        )]
                    );
                } else {
                    $this->report($progress, 'Running HMRC-profile Arelle validation for the accounts iXBRL…', 13);
                    $validated = $this->validateAccounts($companyId, $accountingPeriodId);
                    $accountsArtifact = $this->artifactDescriptor(
                        'hmrc_accounts_ixbrl',
                        'HMRC',
                        $companyId,
                        $accountingPeriodId,
                        null,
                        $generated,
                        $validated
                    );
                    $warnings = array_merge($warnings, (array)($validated['warnings'] ?? []));
                    if ((string)($validated['status'] ?? '') !== 'passed') {
                        $stageResults['hmrc_accounts'] = $this->executionStage(
                            'failed',
                            (array)($validated['errors'] ?? [
                                'The HMRC accounts iXBRL did not pass its authority validation profile.',
                            ]),
                            (array)($validated['warnings'] ?? []),
                            ['The HMRC accounts artifact was generated but is not filing-ready.'],
                            ['artifact' => $accountsArtifact]
                        );
                    } else {
                        \eel_accounts\Support\RequestCache::clear();
                        $readiness = $this->readiness($companyId, $accountingPeriodId);
                        if (empty($readiness['ready_for_filing'])) {
                            $stageResults['hmrc_accounts'] = $this->executionStage(
                                'failed',
                                (array)($readiness['filing_errors'] ?? [
                                    'The generated HMRC accounts iXBRL is not filing-ready.',
                                ]),
                                [],
                                [],
                                ['artifact' => $accountsArtifact]
                            );
                        } else {
                            $accountsReady = true;
                            $messages[] = 'HMRC accounts iXBRL generated and validated.';
                            $stageResults['hmrc_accounts'] = $this->executionStage(
                                'succeeded',
                                [],
                                array_merge(
                                    (array)($generated['warnings'] ?? []),
                                    (array)($validated['warnings'] ?? [])
                                ),
                                ['HMRC accounts iXBRL generated and validated.'],
                                ['artifact' => $accountsArtifact]
                            );
                        }
                    }
                }
            } catch (\Throwable $exception) {
                $stageResults['hmrc_accounts'] = $this->executionStage(
                    'failed',
                    [$exception->getMessage()]
                );
            }
        }

        $periodCount = count((array)$plan['computations']);
        foreach ((array)$plan['computations'] as $index => $computationStage) {
            $ctPeriodId = (int)$computationStage['ct_period_id'];
            $sequence = (int)$computationStage['sequence_no'];
            $periodShare = 48 / max(1, $periodCount);
            $percent = 18 + (int)floor(max(0, $index) * $periodShare);
            $validationPercent = min(
                64,
                $percent + max(1, (int)floor($periodShare * 0.2))
            );
            if ((string)($computationStage['state'] ?? '') === 'blocked') {
                $periodErrors = array_map(
                    static fn(mixed $error): string => (string)$error,
                    (array)($computationStage['errors'] ?? ['Computations iXBRL generation is blocked.'])
                );
                $stageResults['hmrc_computations'][$ctPeriodId] = $this->executionStage(
                    'failed',
                    $periodErrors,
                    [],
                    [],
                    ['ct_period_id' => $ctPeriodId, 'sequence_no' => $sequence]
                );
                $stageResults['hmrc_ct600'][$ctPeriodId] = $this->executionStage(
                    'skipped',
                    ['CT600 XML was not generated because its computation artifact is blocked.'],
                    [],
                    [],
                    ['ct_period_id' => $ctPeriodId, 'sequence_no' => $sequence]
                );
                continue;
            }
            $this->report(
                $progress,
                'Generating HMRC computations iXBRL for Corporation Tax period '
                    . ($index + 1) . ' of ' . $periodCount . '…',
                $percent
            );
            try {
                $computation = $this->generateComputation(
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    function () use ($progress, $index, $periodCount, $validationPercent): void {
                        $this->report(
                            $progress,
                            'Running HMRC-profile Arelle validation for Corporation Tax period '
                                . ($index + 1) . ' of ' . $periodCount . '…',
                            $validationPercent
                        );
                    }
                );
                $computationArtifact = $this->artifactDescriptor(
                    'hmrc_computation_ixbrl',
                    'HMRC',
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    $computation,
                    (array)($computation['validation'] ?? [])
                );
                $warnings = array_merge($warnings, (array)($computation['warnings'] ?? []));
                if (empty($computation['success'])) {
                    $stageResults['hmrc_computations'][$ctPeriodId] = $this->executionStage(
                        'failed',
                        (array)($computation['errors'] ?? ['Computations iXBRL generation failed.']),
                        (array)($computation['warnings'] ?? []),
                        [],
                        [
                            'ct_period_id' => $ctPeriodId,
                            'sequence_no' => $sequence,
                            'artifact' => $computationArtifact,
                        ]
                    );
                } else {
                    $status = $this->computationStatus($companyId, $accountingPeriodId, $ctPeriodId);
                    if (empty($status['fileable'])) {
                        $stageResults['hmrc_computations'][$ctPeriodId] = $this->executionStage(
                            'failed',
                            (array)($status['fileable_errors'] ?? [
                                'The generated HMRC computation is not filing-ready.',
                            ]),
                            (array)($computation['warnings'] ?? []),
                            ['The computation artifact was generated but is not filing-ready.'],
                            [
                                'ct_period_id' => $ctPeriodId,
                                'sequence_no' => $sequence,
                                'artifact' => $computationArtifact,
                            ]
                        );
                    } else {
                        $computationMessage = 'Corporation Tax period ' . $sequence
                            . ' HMRC computations iXBRL generated and validated.';
                        $messages[] = $computationMessage;
                        $stageResults['hmrc_computations'][$ctPeriodId] = $this->executionStage(
                            'succeeded',
                            [],
                            (array)($computation['warnings'] ?? []),
                            [$computationMessage],
                            [
                                'ct_period_id' => $ctPeriodId,
                                'sequence_no' => $sequence,
                                'artifact' => $computationArtifact,
                            ]
                        );
                    }
                }
            } catch (\Throwable $exception) {
                $stageResults['hmrc_computations'][$ctPeriodId] = $this->executionStage(
                    'failed',
                    [$exception->getMessage()],
                    [],
                    [],
                    ['ct_period_id' => $ctPeriodId, 'sequence_no' => $sequence]
                );
            }

            if (!$accountsReady
                || (string)($stageResults['hmrc_computations'][$ctPeriodId]['outcome'] ?? '') !== 'succeeded') {
                $reason = !$accountsReady
                    ? 'CT600 XML was not generated because the HMRC accounts artifact is not filing-ready.'
                    : 'CT600 XML was not generated because its HMRC computation artifact is not filing-ready.';
                $stageResults['hmrc_ct600'][$ctPeriodId] = $this->executionStage(
                    'skipped',
                    [$reason],
                    [],
                    [],
                    ['ct_period_id' => $ctPeriodId, 'sequence_no' => $sequence]
                );
                continue;
            }

            $ct600Percent = min(
                66,
                $validationPercent + max(1, (int)floor($periodShare * 0.35))
            );
            $this->report(
                $progress,
                'Generating CT600 XML for Corporation Tax period '
                    . ($index + 1) . ' of ' . $periodCount . '…',
                $ct600Percent
            );
            $ct600EndPercent = min(
                68,
                $ct600Percent + max(1, (int)floor($periodShare * 0.4))
            );
            try {
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
                        $this->report($progress, $message, min(68, $mapped));
                    }
                );
                $ct600Source = (array)($ct600['artifact'] ?? $ct600);
                $ct600Artifact = $this->artifactDescriptor(
                    'ct600_xml',
                    'HMRC',
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId,
                    $ct600Source
                );
                $warnings = array_merge($warnings, (array)($ct600['warnings'] ?? []));
                if (empty($ct600['success'])) {
                    $stageResults['hmrc_ct600'][$ctPeriodId] = $this->executionStage(
                        'failed',
                        (array)($ct600['errors'] ?? ['CT600 XML generation failed.']),
                        (array)($ct600['warnings'] ?? []),
                        [],
                        [
                            'ct_period_id' => $ctPeriodId,
                            'sequence_no' => $sequence,
                            'artifact' => $ct600Artifact,
                        ]
                    );
                } else {
                    $ct600Message = 'Corporation Tax period ' . $sequence
                        . ' CT600 XML generated and validated.';
                    $messages[] = $ct600Message;
                    $stageResults['hmrc_ct600'][$ctPeriodId] = $this->executionStage(
                        'succeeded',
                        [],
                        (array)($ct600['warnings'] ?? []),
                        [$ct600Message],
                        [
                            'ct_period_id' => $ctPeriodId,
                            'sequence_no' => $sequence,
                            'artifact' => $ct600Artifact,
                        ]
                    );
                }
            } catch (\Throwable $exception) {
                $stageResults['hmrc_ct600'][$ctPeriodId] = $this->executionStage(
                    'failed',
                    [$exception->getMessage()],
                    [],
                    [],
                    ['ct_period_id' => $ctPeriodId, 'sequence_no' => $sequence]
                );
            }
        }

        \eel_accounts\Support\RequestCache::clear();
        try {
            $companiesHouseContext = $this->companiesHouseContext($companyId, $accountingPeriodId);
            $companiesHouseStage = $this->companiesHouseStage($companiesHouseContext);
            $revisionReadiness = $this->revisionReadiness($companyId, $accountingPeriodId);
            if (!empty($companiesHouseContext['filing_required'])
                && !empty($revisionReadiness['applicable'])
                && empty($revisionReadiness['ready'])) {
                $companiesHouseStage = $this->stage(
                    'blocked',
                    (array)($revisionReadiness['errors'] ?? [
                        'The Companies House revised-accounts prerequisites are incomplete.',
                    ])
                );
            }

            if ((string)$companiesHouseStage['state'] === 'blocked') {
                $stageResults['companies_house_accounts'] = $this->executionStage(
                    'failed',
                    (array)$companiesHouseStage['errors'],
                    [],
                    [],
                    ['filing_kind' => (string)($companiesHouseContext['filing_kind'] ?? '')]
                );
            } elseif ((string)$companiesHouseStage['state'] === 'not_required') {
                $message = 'No Companies House filing artifact is required for this accounting period.';
                $messages[] = $message;
                $stageResults['companies_house_accounts'] = $this->executionStage(
                    'not_required',
                    [],
                    [],
                    [$message],
                    ['filing_kind' => (string)($companiesHouseContext['filing_kind'] ?? '')]
                );
            } else {
                $kind = (string)($companiesHouseContext['filing_kind'] ?? '');
                $this->report(
                    $progress,
                    'Preparing the Companies House ' . $kind . '-accounts iXBRL…',
                    72
                );
                $companiesHouseProgress = function (string $message, int $percent) use ($progress): void {
                    $this->report($progress, $message, min(99, 72 + (int)floor($percent * 0.27)));
                };
                $prepared = $this->prepareCompaniesHouse(
                    $companyId,
                    $accountingPeriodId,
                    $actor,
                    $companiesHouseProgress
                );
                $companiesHouseSource = (array)($prepared['artifact'] ?? $prepared['submission'] ?? []);
                if (!isset($companiesHouseSource['path']) && isset($companiesHouseSource['artifact_path'])) {
                    $companiesHouseSource['path'] = $companiesHouseSource['artifact_path'];
                    $companiesHouseSource['sha256'] = $companiesHouseSource['artifact_sha256'] ?? '';
                }
                $companiesHouseArtifact = $this->artifactDescriptor(
                    'companies_house_accounts_ixbrl',
                    'COMPANIES_HOUSE',
                    $companyId,
                    $accountingPeriodId,
                    null,
                    $companiesHouseSource,
                    (array)($companiesHouseSource['validation'] ?? [])
                );
                $warnings = array_merge($warnings, (array)($prepared['warnings'] ?? []));
                if (empty($prepared['success'])) {
                    $stageResults['companies_house_accounts'] = $this->executionStage(
                        'failed',
                        (array)($prepared['errors'] ?? [
                            'Companies House accounts iXBRL preparation failed.',
                        ]),
                        (array)($prepared['warnings'] ?? []),
                        [],
                        ['filing_kind' => $kind, 'artifact' => $companiesHouseArtifact]
                    );
                } else {
                    $message = 'Companies House ' . ucfirst($kind) . ' accounts iXBRL prepared.';
                    $messages[] = $message;
                    $stageResults['companies_house_accounts'] = $this->executionStage(
                        'succeeded',
                        [],
                        (array)($prepared['warnings'] ?? []),
                        [$message],
                        ['filing_kind' => $kind, 'artifact' => $companiesHouseArtifact]
                    );
                }
            }
        } catch (\Throwable $exception) {
            $stageResults['companies_house_accounts'] = $this->executionStage(
                'failed',
                [$exception->getMessage()]
            );
        }

        return $this->completeResult($stageResults, $warnings, $messages, $plan, $progress);
    }

    private function companiesHouseStage(array $context): array
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
        if (!empty($context['can_prepare'])) {
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

    private function executionStage(
        string $outcome,
        array $errors = [],
        array $warnings = [],
        array $messages = [],
        array $details = []
    ): array {
        return $details + [
            'outcome' => $outcome,
            'errors' => $this->cleanMessages($errors),
            'warnings' => $this->cleanMessages($warnings),
            'messages' => $this->cleanMessages($messages),
        ];
    }

    /** @return array<string,mixed> */
    private function artifactDescriptor(
        string $kind,
        string $authority,
        int $companyId,
        int $accountingPeriodId,
        ?int $ctPeriodId,
        array $source,
        array $validation = []
    ): array {
        $path = trim((string)($source['path'] ?? $source['output_path'] ?? ''));
        $filename = trim((string)($source['filename'] ?? $source['output_filename'] ?? ''));
        if ($filename === '' && $path !== '') {
            $filename = basename($path);
        }
        $sha256 = strtolower(trim((string)(
            $source['sha256'] ?? $source['output_sha256'] ?? ''
        )));
        $validationStatus = trim((string)(
            $validation['status']
                ?? $source['validation_status']
                ?? (!empty($source['success']) ? 'passed' : '')
        ));
        $validationLogPath = trim((string)(
            $validation['log_path']
                ?? $source['validation_log_path']
                ?? ''
        ));

        return [
            'kind' => $kind,
            'authority' => $authority,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'filename' => $filename,
            'path' => $path,
            'sha256' => $sha256,
            'validation_status' => $validationStatus,
            'validation_log_path' => $validationLogPath,
            'validation_json' => (string)($source['validation_json'] ?? ''),
            'validation' => $validation !== []
                ? $validation
                : (array)($source['validation'] ?? []),
        ];
    }

    private function completeResult(
        array $stages,
        array $warnings,
        array $messages,
        array $plan,
        mixed $progress
    ): array {
        $terminalStages = [
            ['label' => 'HMRC accounts', 'stage' => $stages['hmrc_accounts']],
            ['label' => 'Companies House accounts', 'stage' => $stages['companies_house_accounts']],
        ];
        foreach ((array)$stages['hmrc_computations'] as $ctPeriodId => $stage) {
            $terminalStages[] = [
                'label' => 'HMRC CT period #' . (int)$ctPeriodId . ' computation',
                'stage' => $stage,
            ];
        }
        foreach ((array)$stages['hmrc_ct600'] as $ctPeriodId => $stage) {
            $terminalStages[] = [
                'label' => 'HMRC CT period #' . (int)$ctPeriodId . ' CT600 XML',
                'stage' => $stage,
            ];
        }

        $errors = [];
        $succeeded = 0;
        $incomplete = 0;
        foreach ($terminalStages as $entry) {
            $label = (string)$entry['label'];
            $stage = (array)$entry['stage'];
            $outcome = (string)($stage['outcome'] ?? 'failed');
            if ($outcome === 'succeeded') {
                $succeeded++;
            } elseif ($outcome !== 'not_required') {
                $incomplete++;
                foreach ((array)($stage['errors'] ?? []) as $error) {
                    $error = trim((string)$error);
                    if ($error !== '') {
                        $errors[] = $label . ': ' . $error;
                    }
                }
            }
            $warnings = array_merge($warnings, (array)($stage['warnings'] ?? []));
            $messages = array_merge($messages, (array)($stage['messages'] ?? []));
        }

        if ($incomplete === 0) {
            $outcome = 'complete';
            $this->report($progress, 'The authority-specific filing iXBRL set is complete.', 100);
        } elseif ($succeeded > 0) {
            $outcome = 'partial';
            $this->report(
                $progress,
                'The filing-set run completed partially; successful authority artifacts were retained.',
                100
            );
        } else {
            $outcome = 'failed';
            $this->report($progress, 'The filing-set run failed.', 100);
        }

        return [
            'success' => $outcome === 'complete',
            'outcome' => $outcome,
            'errors' => $this->cleanMessages($errors),
            'warnings' => $this->cleanMessages($warnings),
            'messages' => $this->cleanMessages($messages),
            'stages' => $stages,
            'plan' => $plan,
        ];
    }

    private function cleanMessages(array $messages): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            $messages
        ))));
    }

    private function failure(
        array $errors,
        array $warnings = [],
        array $messages = [],
        array $plan = []
    ): array {
        return [
            'success' => false,
            'outcome' => 'failed',
            'errors' => array_values(array_unique(array_filter(array_map(
                static fn(mixed $error): string => trim((string)$error),
                $errors
            )))),
            'warnings' => array_values(array_unique($warnings)),
            'messages' => array_values(array_unique($messages)),
            'stages' => [],
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
