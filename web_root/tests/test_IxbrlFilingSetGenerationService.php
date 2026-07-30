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
            'fails complete preflight before invoking any generator',
            static function () use ($harness): void {
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'ct600' => 0, 'companies_house' => 0];
                $service = new \eel_accounts\Service\IxbrlFilingSetGenerationService(
                    readinessResolver: static function (): array {
                        throw new RuntimeException('General iXBRL readiness must not run before the revision gate.');
                    },
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
                        'can_prepare_after_accounts_generation' => false,
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
                $harness->assertSame(
                    ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'ct600' => 0, 'companies_house' => 0],
                    $calls
                );
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['errors']),
                    'must be later'
                ));
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
                $harness->assertTrue(in_array('Generating the Accounting iXBRL…', $progressMessages, true));
                $harness->assertTrue(in_array(
                    'Generating iXBRL for Corporation Tax period 1 of 1…',
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
            'reports separate validation boundaries with time-weighted filing-set progress',
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
                            'can_prepare_after_accounts_generation' => true,
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
                    ct600Generator: static fn(): array => [
                        'success' => true,
                        'warnings' => [],
                    ],
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
                $harness->assertSame([
                    ['Checking the complete filing-set prerequisites…', 0],
                    ['Generating the Accounting iXBRL…', 12],
                    ['Running Arelle validation for the Accounting iXBRL…', 15],
                    ['Generating iXBRL for Corporation Tax period 1 of 2…', 49],
                    ['Running Arelle validation for Corporation Tax period 1 of 2…', 51],
                    ['Generating CT600 XML for Corporation Tax period 1 of 2…', 55],
                    ['Generating iXBRL for Corporation Tax period 2 of 2…', 61],
                    ['Running Arelle validation for Corporation Tax period 2 of 2…', 63],
                    ['Generating CT600 XML for Corporation Tax period 2 of 2…', 67],
                    ['Preparing the Companies House revised-accounts iXBRL…', 73],
                    ['Checking Companies House iXBRL preparation requirements…', 73],
                    ['Preparing the filing-evidence bundle…', 76],
                    ['Reserving the Companies House iXBRL evidence record…', 80],
                    ['Generating the Companies House revised-accounts iXBRL…', 83],
                    ['Running Arelle validation for the Companies House revised-accounts iXBRL…', 84],
                    ['Recording the validated Companies House iXBRL…', 96],
                    ['Creating the Companies House filing record…', 97],
                    ['Companies House iXBRL prepared and validated.', 99],
                    ['The filing iXBRL set is complete.', 100],
                ], $progress);
            }
        );
    }
);
