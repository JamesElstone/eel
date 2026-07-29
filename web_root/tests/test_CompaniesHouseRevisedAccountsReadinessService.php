<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHouseRevisedAccountsReadinessService $service
    ): void {
        $harness->check(
            $service::class,
            'enforces a valid approval date strictly later than the original',
            static function () use ($harness, $service): void {
                $harness->assertTrue(str_contains(
                    (string)$service->revisionApprovalDateError('2025-06-28', ''),
                    'missing or invalid'
                ));
                $harness->assertTrue(str_contains(
                    (string)$service->revisionApprovalDateError('2025-06-28', '2025-06-28'),
                    'must be later'
                ));
                $harness->assertSame(
                    null,
                    $service->revisionApprovalDateError('2025-06-28', '2026-07-29')
                );
            }
        );

        $harness->check(
            $service::class,
            'uses the frozen approval date and rejects supplied or current conflicts',
            static function () use ($harness, $service): void {
                $approval = [
                    'basis_json' => json_encode([
                        'disclosures' => [
                            'values' => ['accounts_approval_date' => '2026-07-29'],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ];
                $harness->assertSame(
                    '2026-07-29',
                    $service->resolveApprovalDate(
                        $approval,
                        ['revision_approval_date' => '2026-07-29'],
                        '2026-07-29',
                        '2025-06-28'
                    )
                );

                foreach ([
                    [['revision_approval_date' => '2026-07-28'], '2026-07-29'],
                    [[], '2026-07-28'],
                ] as [$input, $currentDate]) {
                    $caught = null;
                    try {
                        $service->resolveApprovalDate(
                            $approval,
                            $input,
                            $currentDate,
                            '2025-06-28'
                        );
                    } catch (RuntimeException $exception) {
                        $caught = $exception;
                    }
                    $harness->assertTrue($caught instanceof RuntimeException);
                    $harness->assertTrue(str_contains(
                        mb_strtolower((string)$caught?->getMessage()),
                        'conflicts'
                    ));
                }
            }
        );

        $harness->check(
            $service::class,
            'marks legacy equal-date declarations invalid without changing them',
            static function () use ($harness, $service): void {
                $declarations = [
                    'original_approval_date' => '2025-06-28',
                    'revision_approval_date' => '2025-06-28',
                ];
                $result = $service->validateStoredDeclarations($declarations);
                $harness->assertFalse((bool)$result['valid']);
                $harness->assertSame('2025-06-28', $declarations['revision_approval_date']);
            }
        );
    }
);
