<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlFilingSetGenerationService $unused
    ): void {
        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'keeps HMRC generation independent when Companies House revision prerequisites are blocked',
            static function () use ($harness): void {
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'ct600' => 0, 'companies_house' => 0];
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => true,
                        'ready' => false,
                        'errors' => [
                            'The revision approval date must be later than the original accounts approval date (2025-06-28).',
                        ],
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 7, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'revised',
                        'can_prepare' => false,
                        'preparation_blockers' => [
                            'The revision approval date must be later than the original accounts approval date (2025-06-28).',
                        ],
                    ],
                    accountsGenerator: static function () use (&$calls): array {
                        $calls['accounts']++;
                        return ['success' => true];
                    },
                    accountsValidator: static function () use (&$calls): array {
                        $calls['validation']++;
                        return ['status' => 'passed'];
                    },
                    computationStatusResolver: static fn(): array => ['ready' => true, 'fileable' => true],
                    computationGenerator: static function () use (&$calls): array {
                        $calls['computation']++;
                        return ['success' => true];
                    },
                    ct600Generator: static function () use (&$calls): array {
                        $calls['ct600']++;
                        return ['success' => true];
                    },
                    companiesHousePreparer: static function () use (&$calls): array {
                        $calls['companies_house']++;
                        return ['success' => true];
                    },
                );

                $result = $service->generate(49001, 80001, 'test');
                $harness->assertFalse((bool)$result['success']);
                $harness->assertSame('partial', (string)$result['outcome']);
                $harness->assertSame(
                    ['accounts' => 1, 'validation' => 1, 'computation' => 1, 'ct600' => 1, 'companies_house' => 0],
                    $calls
                );
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['hmrc_accounts']['outcome']
                );
                $harness->assertSame(
                    'failed',
                    (string)$result['stages']['companies_house_accounts']['outcome']
                );
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['errors']),
                    'Companies House accounts: The revision approval date must be later'
                ));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'allows current-basis artifact preparation while an older Companies House submission is pending',
            static function () use ($harness): void {
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 7, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'revised',
                        'can_prepare' => true,
                        'preparation_blockers' => [],
                        'prepared_artifact' => [],
                        'active_submission' => [
                            'id' => 49,
                            'submission_number' => '000002',
                            'lifecycle' => 'pending',
                        ],
                    ],
                    computationStatusResolver: static fn(): array => ['ready' => true, 'fileable' => true],
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => true,
                        'ready' => true,
                    ],
                );

                $plan = $service->plan(49, 79);
                $harness->assertTrue((bool)$plan['ready']);
                $harness->assertSame('prepare', (string)$plan['companies_house']['state']);
                $harness->assertSame([], (array)$plan['errors']);
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'regenerates every artifact when the complete filing set is already current',
            static function () use ($harness): void {
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'ct600' => 0, 'companies_house' => 0];
                $progressMessages = [];
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 7, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'revised',
                        'prepared_artifact' => [
                            'state' => 'current',
                            'current' => true,
                            'filename' => 'accounts.xhtml',
                        ],
                        'revised_validation' => ['status' => 'passed'],
                    ],
                    accountsGenerator: static function () use (&$calls): array {
                        $calls['accounts']++;
                        return ['success' => true];
                    },
                    accountsValidator: static function () use (&$calls): array {
                        $calls['validation']++;
                        return ['status' => 'passed'];
                    },
                    computationStatusResolver: static fn(): array => ['ready' => true, 'fileable' => true],
                    computationGenerator: static function () use (&$calls): array {
                        $calls['computation']++;
                        return ['success' => true];
                    },
                    ct600Generator: static function () use (&$calls): array {
                        $calls['ct600']++;
                        return ['success' => true];
                    },
                    companiesHousePreparer: static function () use (&$calls): array {
                        $calls['companies_house']++;
                        return ['success' => true];
                    },
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(
                    49002,
                    80002,
                    'test',
                    static function (string $message, int $percent) use (&$progressMessages): void {
                        $progressMessages[] = $message;
                    }
                );
                $harness->assertTrue((bool)$result['success']);
                $harness->assertSame(
                    ['accounts' => 1, 'validation' => 1, 'computation' => 1, 'ct600' => 1, 'companies_house' => 1],
                    $calls
                );
                $harness->assertFalse(str_contains(
                    implode(' ', (array)$result['messages']),
                    'reused'
                ));
                $harness->assertTrue(in_array('Generating the HMRC accounts iXBRL…', $progressMessages, true));
                $harness->assertTrue(in_array(
                    'Generating HMRC computations iXBRL for Corporation Tax period 1 of 1…',
                    $progressMessages,
                    true
                ));
                $harness->assertTrue(in_array(
                    'Preparing the Companies House revised-accounts iXBRL…',
                    $progressMessages,
                    true
                ));
                $harness->assertFalse(str_contains(implode(' ', $progressMessages), 'Reusing'));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'reuses the accepted Revised lineage when the imported AAMD filing is reconciled',
            static function () use ($harness): void {
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'ct600' => 0, 'companies_house' => 0];
                $companiesHouseContext = static fn(): array => [
                    'filing_required' => true,
                    'filing_outstanding' => false,
                    'filing_kind' => 'revised',
                    'correction_required' => true,
                    'revision_required' => true,
                    'reconciliation' => [
                        'reconciliation_state' => 'verified',
                        'revision_reconciled' => true,
                        'filing_outstanding' => false,
                    ],
                    'submission' => [
                        'id' => 79,
                        'lifecycle' => 'accepted',
                        'filing_type' => 'revised',
                    ],
                    'prepared_artifact' => [
                        'state' => 'retained',
                        'current' => true,
                        'reusable' => true,
                        'accepted' => true,
                        'errors' => [],
                        'filename' => 'accepted-revised.xhtml',
                        'path' => 'accepted-revised.xhtml',
                        'sha256' => hash('sha256', 'accepted-revised'),
                    ],
                    'revised_validation' => ['status' => 'passed'],
                    'can_prepare' => false,
                    'preparation_blockers' => [
                        'The latest revised Companies House filing reconciles with the approved correction; no further filing is outstanding.',
                    ],
                ];
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 8, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: $companiesHouseContext,
                    accountsGenerator: static function () use (&$calls): array {
                        $calls['accounts']++;
                        return ['success' => true];
                    },
                    accountsValidator: static function () use (&$calls): array {
                        $calls['validation']++;
                        return ['status' => 'passed'];
                    },
                    computationStatusResolver: static fn(): array => ['ready' => true, 'fileable' => true],
                    computationGenerator: static function () use (&$calls): array {
                        $calls['computation']++;
                        return ['success' => true];
                    },
                    ct600Generator: static function () use (&$calls): array {
                        $calls['ct600']++;
                        return ['success' => true];
                    },
                    companiesHousePreparer: static function () use (&$calls): array {
                        $calls['companies_house']++;
                        return ['success' => true];
                    },
                    revisionReadinessResolver: static function (): array {
                        throw new RuntimeException(
                            'Historic revised-account preparation readiness must not be re-evaluated after reconciliation.'
                        );
                    },
                );

                $plan = $service->plan(49, 79);
                $harness->assertTrue((bool)$plan['ready']);
                $harness->assertSame('reconciled', (string)$plan['companies_house']['state']);
                $harness->assertSame('revised', (string)$plan['companies_house']['filing_kind']);

                $result = $service->generate(49, 79, 'test');
                $harness->assertTrue((bool)$result['success']);
                $harness->assertSame(
                    ['accounts' => 1, 'validation' => 1, 'computation' => 1, 'ct600' => 1, 'companies_house' => 0],
                    $calls
                );
                $companiesHouse = (array)$result['stages']['companies_house_accounts'];
                $harness->assertSame('succeeded', (string)$companiesHouse['outcome']);
                $harness->assertSame('revised', (string)$companiesHouse['filing_kind']);
                $harness->assertFalse((bool)$companiesHouse['filing_outstanding']);
                $harness->assertTrue((bool)$companiesHouse['artifact_reused']);
                $harness->assertSame(
                    'accepted-revised.xhtml',
                    (string)(($companiesHouse['artifact'] ?? [])['filename'] ?? '')
                );
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['messages']),
                    'accepted filing lineage is retained'
                ));
                $harness->assertFalse(str_contains(
                    implode(' ', (array)$result['messages']),
                    'No Companies House filing artifact is required'
                ));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'keeps reconciliation final when the retained accepted artifact is missing or tampered',
            static function () use ($harness): void {
                foreach ([
                    'missing' => 'The retained local artifact is missing.',
                    'tampered' => 'The retained local artifact failed its integrity check.',
                ] as $artifactState => $artifactError) {
                    $companiesHouseCalls = 0;
                    $context = static fn(): array => [
                        'filing_required' => true,
                        'filing_outstanding' => false,
                        'filing_kind' => 'revised',
                        'reconciliation' => [
                            'reconciliation_state' => 'verified',
                            'revision_reconciled' => true,
                            'filing_outstanding' => false,
                        ],
                        'submission' => [
                            'id' => 80,
                            'lifecycle' => 'accepted',
                            'filing_type' => 'revised',
                        ],
                        'prepared_artifact' => [
                            'state' => $artifactState,
                            'current' => false,
                            'reusable' => false,
                            'accepted' => true,
                            'filename' => '',
                            'path' => '',
                            'errors' => [$artifactError],
                        ],
                        'can_prepare' => false,
                        'preparation_blockers' => ['No further filing is outstanding.'],
                    ];
                    $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                        readinessResolver: static fn(): array => [
                            'can_generate' => true,
                            'ready_for_filing' => true,
                        ],
                        periodProjectionResolver: static fn(): array => [
                            'periods' => [['ct_period_id' => 8, 'sequence_no' => 1, 'status' => 'current']],
                        ],
                        companiesHouseResolver: $context,
                        accountsGenerator: static fn(): array => ['success' => true],
                        accountsValidator: static fn(): array => ['status' => 'passed'],
                        computationStatusResolver: static fn(): array => ['ready' => true, 'fileable' => true],
                        computationGenerator: static fn(): array => ['success' => true],
                        ct600Generator: static fn(): array => ['success' => true],
                        companiesHousePreparer: static function () use (&$companiesHouseCalls): array {
                            $companiesHouseCalls++;
                            return ['success' => true];
                        },
                        revisionReadinessResolver: static function (): array {
                            throw new RuntimeException('Reconciled filings must not re-enter preparation readiness.');
                        },
                    );

                    $plan = $service->plan(49, 80);
                    $harness->assertTrue((bool)$plan['ready']);
                    $harness->assertSame('reconciled', (string)$plan['companies_house']['state']);
                    $harness->assertFalse((bool)$plan['companies_house']['artifact_reused']);

                    $result = $service->generate(49, 80, 'test');
                    $harness->assertTrue((bool)$result['success']);
                    $harness->assertSame(0, $companiesHouseCalls);
                    $stage = (array)$result['stages']['companies_house_accounts'];
                    $harness->assertSame('succeeded', (string)$stage['outcome']);
                    $harness->assertFalse((bool)$stage['artifact_reused']);
                    $harness->assertFalse(array_key_exists('artifact', $stage));
                    $warning = implode(' ', (array)$stage['warnings']);
                    $harness->assertTrue(str_contains($warning, 'Attention:'));
                    $harness->assertTrue(str_contains($warning, $artifactError));
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'an explicit retry regenerates current Accounting before retrying a failed computation',
            static function () use ($harness): void {
                $accountsGenerationCalls = 0;
                $accountsValidationCalls = 0;
                $generationCalls = 0;
                $ct600Calls = 0;
                $fileable = false;
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 7, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => false,
                        'filing_kind' => '',
                    ],
                    accountsGenerator: static function () use (&$accountsGenerationCalls): array {
                        $accountsGenerationCalls++;
                        return ['success' => true, 'warnings' => []];
                    },
                    accountsValidator: static function () use (&$accountsValidationCalls): array {
                        $accountsValidationCalls++;
                        return ['status' => 'passed', 'warnings' => []];
                    },
                    computationStatusResolver: static function () use (&$fileable): array {
                        return ['ready' => true, 'fileable' => $fileable];
                    },
                    computationGenerator: static function () use (&$generationCalls, &$fileable): array {
                        $generationCalls++;
                        if ($generationCalls === 1) {
                            return ['success' => false, 'errors' => ['Injected CT generation failure.']];
                        }
                        $fileable = true;
                        return ['success' => true];
                    },
                    ct600Generator: static function () use (&$ct600Calls): array {
                        $ct600Calls++;
                        return ['success' => true];
                    },
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $first = $service->generate(49003, 80003, 'test');
                $second = $service->generate(49003, 80003, 'test');
                $harness->assertFalse((bool)$first['success']);
                $harness->assertTrue((bool)$second['success']);
                $harness->assertSame(2, $accountsGenerationCalls);
                $harness->assertSame(2, $accountsValidationCalls);
                $harness->assertSame(2, $generationCalls);
                $harness->assertSame(1, $ct600Calls);
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingOperationLockService::class,
            'rejects a concurrent operation for the same company and period',
            static function () use ($harness): void {
                $locks = new \eel_accounts\Service\IxbrlFilingOperationLockService();
                $caught = null;
                $locks->execute(49004, 80004, static function () use ($locks, &$caught): void {
                    try {
                        $locks->execute(49004, 80004, static fn(): null => null);
                    } catch (RuntimeException $exception) {
                        $caught = $exception;
                    }
                });

                $harness->assertTrue($caught instanceof RuntimeException);
                $harness->assertTrue(str_contains(
                    (string)$caught?->getMessage(),
                    'already running'
                ));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'runs HMRC before Companies House without sharing the Companies House artifact',
            static function () use ($harness): void {
                $accountsCurrent = false;
                $ctCurrent = [71 => false, 72 => false];
                $companiesHouseCurrent = false;
                $progress = [];
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static function () use (&$accountsCurrent): array {
                        return [
                            'can_generate' => true,
                            'ready_for_filing' => $accountsCurrent,
                        ];
                    },
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [
                            ['ct_period_id' => 71, 'sequence_no' => 1, 'status' => 'current'],
                            ['ct_period_id' => 72, 'sequence_no' => 2, 'status' => 'current'],
                        ],
                    ],
                    companiesHouseResolver: static function () use (&$companiesHouseCurrent): array {
                        return [
                            'filing_required' => true,
                            'filing_kind' => 'revised',
                            'can_prepare' => true,
                            'preparation_blockers' => [],
                            'prepared_artifact' => $companiesHouseCurrent
                                ? ['state' => 'current', 'current' => true, 'filename' => 'revised.xhtml']
                                : [],
                            'revised_validation' => $companiesHouseCurrent
                                ? ['status' => 'passed']
                                : [],
                        ];
                    },
                    accountsGenerator: static fn(): array => ['success' => true, 'warnings' => []],
                    accountsValidator: static function () use (&$accountsCurrent): array {
                        $accountsCurrent = true;
                        return ['status' => 'passed', 'warnings' => []];
                    },
                    computationStatusResolver: static function (
                        int $companyId,
                        int $accountingPeriodId,
                        int $ctPeriodId
                    ) use (&$ctCurrent): array {
                        return ['ready' => true, 'fileable' => $ctCurrent[$ctPeriodId]];
                    },
                    computationGenerator: static function (
                        int $companyId,
                        int $accountingPeriodId,
                        int $ctPeriodId,
                        callable $beforeValidation
                    ) use (&$ctCurrent): array {
                        $beforeValidation();
                        $ctCurrent[$ctPeriodId] = true;
                        return ['success' => true, 'warnings' => []];
                    },
                    ct600Generator: static function () use (&$companiesHouseCurrent): array {
                        if ($companiesHouseCurrent) {
                            return ['success' => false, 'errors' => [
                                'Companies House must not be prepared before the HMRC package.',
                            ]];
                        }
                        return ['success' => true, 'warnings' => []];
                    },
                    companiesHousePreparer: static function (
                        int $companyId,
                        int $accountingPeriodId,
                        string $actor,
                        callable $report
                    ) use (&$companiesHouseCurrent): array {
                        $report('Checking Companies House iXBRL preparation requirements…', 0);
                        $report('Preparing the filing-evidence bundle…', 15);
                        $report('Reserving the Companies House iXBRL evidence record…', 30);
                        $report('Generating the Companies House revised-accounts iXBRL…', 40);
                        $report('Running Arelle validation for the Companies House revised-accounts iXBRL…', 45);
                        $report('Recording the validated Companies House iXBRL…', 92);
                        $report('Creating the Companies House filing record…', 96);
                        $report('Companies House iXBRL prepared and validated.', 100);
                        $companiesHouseCurrent = true;
                        return ['success' => true, 'warnings' => []];
                    },
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => true,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(
                    49005,
                    80005,
                    'test',
                    static function (string $message, int $percent) use (&$progress): void {
                        $progress[] = [$message, $percent];
                    }
                );

                $harness->assertTrue((bool)$result['success']);
                $harness->assertSame('complete', (string)$result['outcome']);
                $messages = array_column($progress, 0);
                $firstCt600 = array_search(
                    'Generating CT600 XML for Corporation Tax period 1 of 2…',
                    $messages,
                    true
                );
                $companiesHouse = array_search(
                    'Preparing the Companies House revised-accounts iXBRL…',
                    $messages,
                    true
                );
                $harness->assertTrue(is_int($firstCt600));
                $harness->assertTrue(is_int($companiesHouse));
                $harness->assertTrue($firstCt600 < $companiesHouse);
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['hmrc_ct600'][71]['outcome']
                );
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['companies_house_accounts']['outcome']
                );
                $harness->assertSame(
                    'hmrc_accounts_ixbrl',
                    (string)$result['stages']['hmrc_accounts']['artifact']['kind']
                );
                $harness->assertSame(
                    71,
                    (int)$result['stages']['hmrc_computations'][71]['artifact']['ct_period_id']
                );
                $harness->assertSame(
                    'ct600_xml',
                    (string)$result['stages']['hmrc_ct600'][71]['artifact']['kind']
                );
                $harness->assertSame(
                    'COMPANIES_HOUSE',
                    (string)$result['stages']['companies_house_accounts']['artifact']['authority']
                );
                $harness->assertSame(
                    'The authority-specific filing iXBRL set is complete.',
                    (string)end($messages)
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'retains a successful Companies House artifact when the HMRC branch fails',
            static function () use ($harness): void {
                $companiesHouseCalls = 0;
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => false,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 81, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'original',
                        'can_prepare' => true,
                        'prepared_artifact' => [],
                    ],
                    accountsGenerator: static fn(): array => [
                        'success' => false,
                        'errors' => ['Injected HMRC accounts profile failure.'],
                    ],
                    computationStatusResolver: static fn(): array => [
                        'ready' => true,
                        'fileable' => true,
                    ],
                    computationGenerator: static fn(): array => ['success' => true],
                    ct600Generator: static fn(): array => ['success' => true],
                    companiesHousePreparer: static function () use (&$companiesHouseCalls): array {
                        $companiesHouseCalls++;
                        return ['success' => true];
                    },
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(49006, 80006, 'test');

                $harness->assertSame('partial', (string)$result['outcome']);
                $harness->assertSame(1, $companiesHouseCalls);
                $harness->assertSame(
                    'failed',
                    (string)$result['stages']['hmrc_accounts']['outcome']
                );
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['hmrc_computations'][81]['outcome']
                );
                $harness->assertSame(
                    'skipped',
                    (string)$result['stages']['hmrc_ct600'][81]['outcome']
                );
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['companies_house_accounts']['outcome']
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'retains successful HMRC outputs when Companies House preparation fails',
            static function () use ($harness): void {
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => true,
                        'ready_for_filing' => true,
                    ],
                    periodProjectionResolver: static fn(): array => [
                        'periods' => [['ct_period_id' => 82, 'sequence_no' => 1, 'status' => 'current']],
                    ],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'original',
                        'can_prepare' => true,
                        'prepared_artifact' => [],
                    ],
                    accountsGenerator: static fn(): array => ['success' => true],
                    accountsValidator: static fn(): array => ['status' => 'passed'],
                    computationStatusResolver: static fn(): array => [
                        'ready' => true,
                        'fileable' => true,
                    ],
                    computationGenerator: static fn(): array => ['success' => true],
                    ct600Generator: static fn(): array => ['success' => true],
                    companiesHousePreparer: static fn(): array => [
                        'success' => false,
                        'errors' => ['Injected Companies House profile failure.'],
                    ],
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(49007, 80007, 'test');

                $harness->assertSame('partial', (string)$result['outcome']);
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['hmrc_accounts']['outcome']
                );
                $harness->assertSame(
                    'succeeded',
                    (string)$result['stages']['hmrc_ct600'][82]['outcome']
                );
                $harness->assertSame(
                    'failed',
                    (string)$result['stages']['companies_house_accounts']['outcome']
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'reports failed when no authority-specific output succeeds',
            static function () use ($harness): void {
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static fn(): array => [
                        'can_generate' => false,
                        'ready_for_filing' => false,
                        'generation_errors' => ['HMRC accounts basis is unavailable.'],
                    ],
                    periodProjectionResolver: static fn(): array => ['periods' => []],
                    companiesHouseResolver: static fn(): array => [
                        'filing_required' => true,
                        'filing_kind' => 'original',
                        'can_prepare' => false,
                        'preparation_blockers' => ['Companies House accounts basis is unavailable.'],
                    ],
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(49008, 80008, 'test');

                $harness->assertFalse((bool)$result['success']);
                $harness->assertSame('failed', (string)$result['outcome']);
                $harness->assertSame(
                    'failed',
                    (string)$result['stages']['hmrc_accounts']['outcome']
                );
                $harness->assertSame(
                    'failed',
                    (string)$result['stages']['companies_house_accounts']['outcome']
                );
            }
        );
    }
);
