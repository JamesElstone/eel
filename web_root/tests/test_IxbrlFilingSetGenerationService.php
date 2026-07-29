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
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'companies_house' => 0];
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
                    companiesHousePreparer: static function () use (&$calls): array {
                        $calls['companies_house']++;
                        return ['success' => true];
                    },
                );

                $result = $service->generate(49001, 80001, 'test');
                $harness->assertFalse((bool)$result['success']);
                $harness->assertSame(
                    ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'companies_house' => 0],
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
            'reuses a completely current filing set without regeneration',
            static function () use ($harness): void {
                $calls = ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'companies_house' => 0];
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
                    companiesHousePreparer: static function () use (&$calls): array {
                        $calls['companies_house']++;
                        return ['success' => true];
                    },
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $result = $service->generate(49002, 80002, 'test');
                $harness->assertTrue((bool)$result['success']);
                $harness->assertSame(
                    ['accounts' => 0, 'validation' => 0, 'computation' => 0, 'companies_house' => 0],
                    $calls
                );
                $harness->assertTrue(str_contains(
                    implode(' ', (array)$result['messages']),
                    'reused'
                ));
            }
        );

        $harness->check(
            \eel_accounts\Service\IxbrlFilingSetGenerationService::class,
            'retry skips current stages and resumes the failed computation',
            static function () use ($harness): void {
                $generationCalls = 0;
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
                    accountsGenerator: static function (): array {
                        throw new RuntimeException('Current Accounting iXBRL must not be regenerated.');
                    },
                    accountsValidator: static function (): array {
                        throw new RuntimeException('Current Accounting iXBRL must not be revalidated.');
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
                    revisionReadinessResolver: static fn(): array => [
                        'applicable' => false,
                        'ready' => true,
                    ],
                );

                $first = $service->generate(49003, 80003, 'test');
                $second = $service->generate(49003, 80003, 'test');
                $harness->assertFalse((bool)$first['success']);
                $harness->assertTrue((bool)$second['success']);
                $harness->assertSame(2, $generationCalls);
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
    }
);
