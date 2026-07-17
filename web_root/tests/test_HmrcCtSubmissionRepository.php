<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function hmrc_ct_repository_seed(): void
{
    \InterfaceDB::beginTransaction();
    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number) VALUES (:id, :name, :number)',
        ['id' => 98049, 'name' => 'Synthetic CT600 Repository Ltd', 'number' => '09999999']
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => 98079,
            'company_id' => 98049,
            'label' => 'AP79 synthetic',
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-30',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (
            company_id, accounting_period_id, is_locked, locked_at, locked_by,
            review_notes, created_at, updated_at
         ) VALUES (
            :company_id, :accounting_period_id, 1, :locked_at, :locked_by,
            NULL, :created_at, :updated_at
         )',
        [
            'company_id' => 98049,
            'accounting_period_id' => 98079,
            'locked_at' => '2026-07-16 12:00:00',
            'locked_by' => 'synthetic-test',
            'created_at' => '2026-07-16 12:00:00',
            'updated_at' => '2026-07-16 12:00:00',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO corporation_tax_periods (
            id, company_id, accounting_period_id, sequence_no, period_start, period_end, status
         ) VALUES (
            :id, :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status
         )',
        [
            'id' => 98006,
            'company_id' => 98049,
            'accounting_period_id' => 98079,
            'sequence_no' => 1,
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
            'status' => 'ready',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO ixbrl_generation_runs (
            id, company_id, accounting_period_id, status, export_type
         ) VALUES (:id, :company_id, :accounting_period_id, :status, :export_type)',
        [
            'id' => 98101,
            'company_id' => 98049,
            'accounting_period_id' => 98079,
            'status' => 'generated',
            'export_type' => 'filing',
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO corporation_tax_computation_runs (
            id, company_id, accounting_period_id, ct_period_id, period_start, period_end,
            status, computation_hash, summary_json
         ) VALUES (
            :id, :company_id, :accounting_period_id, :ct_period_id, :period_start, :period_end,
            :status, :computation_hash, :summary_json
         )',
        [
            'id' => 98102,
            'company_id' => 98049,
            'accounting_period_id' => 98079,
            'ct_period_id' => 98006,
            'period_start' => '2022-09-05',
            'period_end' => '2023-09-04',
            'status' => 'generated',
            'computation_hash' => str_repeat('b', 64),
            'summary_json' => '{}',
        ]
    );
}

/** @return array<string, mixed> */
function hmrc_ct_repository_source(string $environment, string $salt): array
{
    $packageHash = hash('sha256', 'package-' . $salt);
    return [
        'company_id' => 98049,
        'accounting_period_id' => 98079,
        'ct_period_id' => 98006,
        'environment' => $environment,
        'submission_type' => 'original',
        'ct600_xml_path' => 'packages/' . $packageHash . '/ct600.xml',
        'accounts_ixbrl_path' => 'packages/' . $packageHash . '/accounts.html',
        'accounts_run_id' => 98101,
        'accounts_sha256' => hash('sha256', 'accounts'),
        'computations_ixbrl_path' => 'packages/' . $packageHash . '/computations.html',
        'computation_run_id' => 98102,
        'computations_sha256' => hash('sha256', 'computations'),
        'year_end_locked_at' => '2026-07-16 12:00:00',
        'package_hash' => $packageHash,
        'idempotency_key' => hash('sha256', 'idempotency-' . $salt),
        'transaction_id' => strtoupper(substr(hash('sha256', 'transaction-' . $salt), 0, 32)),
        'irmark' => base64_encode(hash('sha1', 'irmark-' . $salt, true)),
        'schema_version' => '2026-v1.994',
        'body_sha256' => hash('sha256', 'body-' . $salt),
        'ct600_sha256' => hash('sha256', 'ct600-' . $salt),
        'manifest_path' => 'packages/' . $packageHash . '/manifest.json',
        'validation' => ['xsd' => 'passed', 'schematron' => 'passed'],
        'declarant_name' => 'A Director',
        'declarant_status' => 'Proper officer',
    ];
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtSubmissionRepository::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\HmrcCtSubmissionRepository::class,
            'persists an idempotent frozen source and the complete asynchronous lifecycle',
            static function () use ($harness): void {
                hmrc_ct_repository_seed();
                $repository = new \eel_accounts\Service\HmrcCtSubmissionRepository();
                $source = hmrc_ct_repository_source('TEST', 'lifecycle');
                $prepared = $repository->createPrepared($source, 'preparer@example.test');
                $again = $repository->createPrepared($source, 'preparer@example.test');
                $harness->assertSame((int)$prepared['id'], (int)$again['id']);
                $harness->assertSame('prepared', $prepared['protocol_state']);
                $harness->assertSame('not_applicable', $prepared['statutory_sync_state']);
                $harness->assertSame(null, $repository->fetchOwned((int)$prepared['id'], 12345));

                $changedDeclarationSource = $source;
                $changedDeclarationSource['declarant_name'] = 'A Different Director';
                $sourceMismatchThrown = false;
                try {
                    $repository->createPrepared($changedDeclarationSource, 'preparer@example.test');
                } catch (\DomainException $exception) {
                    $sourceMismatchThrown = str_contains($exception->getMessage(), 'different frozen source data');
                }
                $harness->assertSame(true, $sourceMismatchThrown);

                $mismatchThrown = false;
                try {
                    $repository->approve(
                        (int)$prepared['id'],
                        98049,
                        [
                            'name' => 'A Different Director',
                            'status' => 'Proper officer',
                            'confirmed' => true,
                            'scope_confirmed' => true,
                            'original_unfiled_confirmed' => true,
                        ],
                        'approver@example.test'
                    );
                } catch (\DomainException $exception) {
                    $mismatchThrown = str_contains($exception->getMessage(), 'does not match');
                }
                $harness->assertSame(true, $mismatchThrown);

                $approved = $repository->approve(
                    (int)$prepared['id'],
                    98049,
                    [
                        'name' => 'A Director',
                        'status' => 'Proper officer',
                        'confirmed' => true,
                        'scope_confirmed' => true,
                        'original_unfiled_confirmed' => true,
                    ],
                    'approver@example.test'
                );
                $harness->assertSame($approved['package_hash'], $approved['approved_package_hash']);

                $submitting = $repository->markSubmitting(
                    (int)$prepared['id'],
                    98049,
                    'submitter@example.test',
                    42,
                    'packages/redacted-request.xml',
                    ['Authorization' => 'Bearer credential-must-not-persist']
                );
                $harness->assertSame('submitting', $submitting['protocol_state']);
                $harness->assertFalse(str_contains(
                    (string)$submitting['request_headers_json'],
                    'credential-must-not-persist'
                ));

                $acknowledged = $repository->markAcknowledged((int)$prepared['id'], 98049, [
                    'correlation_id' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
                    'response_endpoint' => 'https://transaction-engine.example.test/poll',
                    'poll_interval_seconds' => 2,
                    'next_poll_at' => '2026-07-17 12:00:02',
                    'response_code' => 200,
                    'summary' => 'Acknowledged only',
                ]);
                $harness->assertSame('awaiting_poll', $acknowledged['protocol_state']);
                $harness->assertSame('none', $acknowledged['business_outcome']);

                $polled = $repository->markPollAttempt(
                    (int)$prepared['id'],
                    98049,
                    '2026-07-17 12:00:04'
                );
                $harness->assertSame(1, (int)$polled['poll_attempts']);

                $final = $repository->markFinal((int)$prepared['id'], 98049, [
                    'accepted' => true,
                    'response_code' => 200,
                    'summary' => 'Synthetic TEST acceptance',
                    'response_body_path' => 'packages/responses/final.xml',
                    'response_sha256' => hash('sha256', 'final response'),
                ]);
                $harness->assertSame('final_received', $final['protocol_state']);
                $harness->assertSame('sandbox_passed', $final['business_outcome']);
                $harness->assertSame('not_applicable', $final['statutory_sync_state']);

                $repository->markCleanupPending((int)$prepared['id'], 98049);
                $closed = $repository->markCleanupComplete(
                    (int)$prepared['id'],
                    98049,
                    'packages/responses/delete.xml',
                    hash('sha256', 'delete response')
                );
                $harness->assertSame('closed', $closed['protocol_state']);

                $repository->recordEvent(
                    (int)$prepared['id'],
                    98049,
                    'info',
                    'Sanitisation check',
                    ['password' => 'never-persist-this', 'large' => str_repeat('x', 30_000)]
                );
                $events = $repository->fetchEvents((int)$prepared['id'], 98049);
                $encodedEvents = json_encode($events);
                $harness->assertTrue(is_string($encodedEvents));
                $harness->assertFalse(str_contains((string)$encodedEvents, 'never-persist-this'));
                $harness->assertTrue(count($events) >= 8);
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcCtSubmissionRepository::class,
            'marks only a final LIVE acceptance pending for statutory projection',
            static function () use ($harness): void {
                hmrc_ct_repository_seed();
                $repository = new \eel_accounts\Service\HmrcCtSubmissionRepository();
                $declaration = [
                    'name' => 'A Director',
                    'status' => 'Proper officer',
                    'confirmed' => true,
                    'scope_confirmed' => true,
                    'original_unfiled_confirmed' => true,
                ];

                $live = $repository->createPrepared(
                    hmrc_ct_repository_source('LIVE', 'live-sync-state'),
                    'preparer'
                );
                $repository->approve((int)$live['id'], 98049, $declaration, 'approver');
                $repository->markSubmitting((int)$live['id'], 98049, 'submitter', 42);
                $live = $repository->markFinal((int)$live['id'], 98049, [
                    'accepted' => true,
                    'response_code' => 200,
                    'summary' => 'Final LIVE acceptance',
                    'response_body_path' => 'packages/responses/live-final.xml',
                    'response_sha256' => hash('sha256', 'live final response'),
                ]);
                $harness->assertSame('live_accepted', $live['business_outcome']);
                $harness->assertSame('pending', $live['statutory_sync_state']);
                $harness->assertSame(null, $live['statutory_sync_error']);
                $harness->assertSame(null, $live['statutory_synced_at']);

                $til = $repository->createPrepared(
                    hmrc_ct_repository_source('TIL', 'til-sync-state'),
                    'preparer'
                );
                $repository->approve((int)$til['id'], 98049, $declaration, 'approver');
                $repository->markSubmitting((int)$til['id'], 98049, 'submitter', 42);
                $til = $repository->markFinal((int)$til['id'], 98049, [
                    'accepted' => true,
                    'response_code' => 200,
                    'summary' => 'Final TIL validation',
                    'response_body_path' => 'packages/responses/til-final.xml',
                    'response_sha256' => hash('sha256', 'til final response'),
                ]);
                $harness->assertSame('til_validated', $til['business_outcome']);
                $harness->assertSame('not_applicable', $til['statutory_sync_state']);
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcCtSubmissionRepository::class,
            'serialises LIVE originals per CT period and bulk-invalidates every unsubmitted package',
            static function () use ($harness): void {
                hmrc_ct_repository_seed();
                $repository = new \eel_accounts\Service\HmrcCtSubmissionRepository();
                $first = $repository->createPrepared(
                    hmrc_ct_repository_source('LIVE', 'live-first'),
                    'preparer'
                );
                $second = $repository->createPrepared(
                    hmrc_ct_repository_source('LIVE', 'live-second'),
                    'preparer'
                );
                $declaration = [
                    'name' => 'A Director',
                    'status' => 'Proper officer',
                    'confirmed' => true,
                    'scope_confirmed' => true,
                    'original_unfiled_confirmed' => true,
                ];
                $repository->approve((int)$first['id'], 98049, $declaration, 'approver');
                $repository->approve((int)$second['id'], 98049, $declaration, 'approver');
                $repository->markSubmitting((int)$first['id'], 98049, 'submitter', 42);

                $activeRemoteBlocked = false;
                try {
                    $repository->assertNoActiveRemoteTransactionsForAccountingPeriod(98049, 98079);
                } catch (\DomainException $exception) {
                    $activeRemoteBlocked = str_contains($exception->getMessage(), 'cannot be unlocked');
                }
                $harness->assertSame(true, $activeRemoteBlocked);

                $thrown = false;
                try {
                    $repository->markSubmitting((int)$second['id'], 98049, 'submitter', 42);
                } catch (\DomainException $exception) {
                    $thrown = str_contains($exception->getMessage(), 'already active or accepted');
                }
                $harness->assertSame(true, $thrown);

                $invalidated = $repository->invalidateUnsubmittedForAccountingPeriod(
                    98049,
                    98079,
                    'The locked source hash changed.',
                    'year-end-lock-service'
                );
                $harness->assertSame(1, $invalidated);
                $harness->assertSame(
                    'submitting',
                    (string)($repository->fetchById((int)$first['id'])['protocol_state'] ?? '')
                );
                $harness->assertSame(
                    'invalidated',
                    (string)($repository->fetchById((int)$second['id'])['protocol_state'] ?? '')
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\HmrcCtSubmissionRepository::class,
            'contains no runtime DDL and requires the downstream migration columns',
            static function () use ($harness): void {
                $repository = new \eel_accounts\Service\HmrcCtSubmissionRepository();
                $repository->requireSchema();
                $source = file_get_contents(
                    APP_CLASSES . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'HmrcCtSubmissionRepository.php'
                );
                $harness->assertTrue(is_string($source));
                $harness->assertFalse((bool)preg_match('/\bCREATE\s+TABLE\b/i', (string)$source));
                $harness->assertFalse((bool)preg_match('/\bALTER\s+TABLE\b/i', (string)$source));
            }
        );
    }
);
