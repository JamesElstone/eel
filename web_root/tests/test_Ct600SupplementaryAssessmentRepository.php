<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'Ct600SupplementaryAssessmentTestFixture.php';

use eel_accounts\Service\Ct600SupplementaryAssessmentRepository;

(new GeneratedServiceClassTestHarness())->run(
    Ct600SupplementaryAssessmentRepository::class,
    static function (GeneratedServiceClassTestHarness $harness, Ct600SupplementaryAssessmentRepository $repository): void {
        $harness->check(
            Ct600SupplementaryAssessmentRepository::class,
            'persists and verifies all nineteen immutable matrix rows against the exact lock and run',
            static function () use ($harness, $repository): void {
                $ids = ct600_supplement_seed();
                $binding = $repository->currentBinding(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id']
                );
                $harness->assertSame((int)$ids['computation_run_id'], $binding['computation_run_id']);
                $harness->assertSame((string)$ids['locked_at'], $binding['year_end_locked_at']);

                $approvedAt = new DateTimeImmutable('2026-07-17 09:30:00');
                $created = $repository->create(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id'],
                    (int)$ids['computation_run_id'],
                    (string)$ids['locked_at'],
                    ct600_supplement_complete_rows(),
                    'user:42',
                    $approvedAt
                );
                $harness->assertCount(19, (array)$created['rows']);
                $harness->assertTrue(!empty($created['hash_valid']));
                $harness->assertSame(64, strlen((string)$created['assessment_hash']));

                $again = $repository->create(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id'],
                    (int)$ids['computation_run_id'],
                    (string)$ids['locked_at'],
                    ct600_supplement_complete_rows(),
                    'user:42',
                    $approvedAt
                );
                $harness->assertSame((int)$created['id'], (int)$again['id']);
                $current = $repository->fetchCurrent(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id'],
                    (int)$ids['computation_run_id'],
                    (string)$ids['locked_at']
                );
                $harness->assertSame((int)$created['id'], (int)($current['id'] ?? 0));
            }
        );

        $harness->check(
            Ct600SupplementaryAssessmentRepository::class,
            'detects row tampering through the immutable assessment hash',
            static function () use ($harness, $repository): void {
                $ids = ct600_supplement_seed();
                $created = $repository->create(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id'],
                    (int)$ids['computation_run_id'],
                    (string)$ids['locked_at'],
                    ct600_supplement_complete_rows(),
                    'user:42',
                    new DateTimeImmutable('2026-07-17 09:31:00')
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE ct600_supplement_assessment_rows
                     SET detail = :detail
                     WHERE assessment_id = :assessment_id AND contract_key = :contract_key',
                    [
                        'detail' => 'Tampered after approval.',
                        'assessment_id' => (int)$created['id'],
                        'contract_key' => 'ct600l',
                    ]
                );
                $tampered = $repository->fetchById((int)$created['id']);
                $harness->assertFalse(!empty($tampered['hash_valid']));
                $harness->assertFalse($repository->verify((array)$tampered));
            }
        );

        $harness->check(
            Ct600SupplementaryAssessmentRepository::class,
            'does not reuse an assessment after the computation run or Year End lock changes',
            static function () use ($harness, $repository): void {
                $ids = ct600_supplement_seed();
                $repository->create(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id'],
                    (int)$ids['computation_run_id'],
                    (string)$ids['locked_at'],
                    ct600_supplement_complete_rows(),
                    'user:42',
                    new DateTimeImmutable('2026-07-17 09:32:00')
                );
                $nextRunId = 98284;
                \InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_computation_runs (
                        id, company_id, accounting_period_id, ct_period_id, period_start, period_end,
                        status, computation_hash, summary_json
                     ) VALUES (
                        :id, :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
                        :status, :computation_hash, :summary_json
                     )',
                    [
                        'id' => $nextRunId,
                        'company_id' => $ids['company_id'],
                        'accounting_period_id' => $ids['accounting_period_id'],
                        'ct_period_id' => $ids['ct_period_id'],
                        'period_start' => '2022-09-05',
                        'period_end' => '2023-09-04',
                        'status' => 'generated',
                        'computation_hash' => str_repeat('b', 64),
                        'summary_json' => '{}',
                    ]
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :id',
                    ['run_id' => $nextRunId, 'id' => $ids['ct_period_id']]
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE year_end_reviews SET locked_at = :locked_at
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                    [
                        'locked_at' => '2026-07-17 10:00:00',
                        'company_id' => $ids['company_id'],
                        'accounting_period_id' => $ids['accounting_period_id'],
                    ]
                );
                $binding = $repository->currentBinding(
                    (int)$ids['company_id'],
                    (int)$ids['accounting_period_id'],
                    (int)$ids['ct_period_id']
                );
                $harness->assertSame($nextRunId, $binding['computation_run_id']);
                $harness->assertSame(
                    null,
                    $repository->fetchCurrent(
                        (int)$ids['company_id'],
                        (int)$ids['accounting_period_id'],
                        (int)$ids['ct_period_id'],
                        $nextRunId,
                        '2026-07-17 10:00:00'
                    )
                );

                $staleRejected = false;
                try {
                    $repository->create(
                        (int)$ids['company_id'],
                        (int)$ids['accounting_period_id'],
                        (int)$ids['ct_period_id'],
                        (int)$ids['computation_run_id'],
                        (string)$ids['locked_at'],
                        ct600_supplement_complete_rows(),
                        'user:42'
                    );
                } catch (DomainException $exception) {
                    $staleRejected = str_contains($exception->getMessage(), 'current locked computation');
                }
                $harness->assertTrue($staleRejected);
            }
        );

        $harness->check(
            Ct600SupplementaryAssessmentRepository::class,
            'requires migration-backed tables and contains no runtime DDL',
            static function () use ($harness, $repository): void {
                $repository->requireSchema();
                $source = file_get_contents(
                    APP_CLASSES . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'Ct600SupplementaryAssessmentRepository.php'
                );
                $harness->assertTrue(is_string($source));
                $harness->assertFalse((bool)preg_match('/\bCREATE\s+TABLE\b/i', (string)$source));
                $harness->assertFalse((bool)preg_match('/\bALTER\s+TABLE\b/i', (string)$source));
            }
        );
    }
);
