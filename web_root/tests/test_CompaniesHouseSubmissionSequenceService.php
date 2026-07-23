<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseSubmissionSequenceService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\CompaniesHouseSubmissionSequenceService::class,
            'allocates numeric presenter-wide numbers across companies and isolates TEST from LIVE',
            static function () use ($h): void {
                $presenter = '12345678901';
                $fingerprint = hash('sha256', $presenter);
                $companyIds = [98701, 98711, 98721];
                $periodIds = [98702, 98712, 98722];
                $submissionIds = [];
                $now = '2026-07-23 10:00:00';
                try {
                    foreach ($companyIds as $index => $companyId) {
                        $periodId = $periodIds[$index];
                        InterfaceDB::prepareExecute(
                            'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                             VALUES (:id, :name, :number, 1, :created_at)',
                            [
                                'id' => $companyId,
                                'name' => 'Sequence Test ' . $companyId,
                                'number' => (string)$companyId,
                                'created_at' => $now,
                            ]
                        );
                        InterfaceDB::prepareExecute(
                            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                             VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                            [
                                'id' => $periodId,
                                'company_id' => $companyId,
                                'label' => 'SEQUENCE-' . $periodId,
                                'start' => '2025-10-01',
                                'end' => '2026-09-30',
                                'created_at' => $now,
                            ]
                        );
                        InterfaceDB::prepareExecute(
                            'INSERT INTO companies_house_accounts_eligibility (
                                company_id, accounting_period_id, original_transaction_id,
                                original_document_external_id, original_filing_channel,
                                decision, evidence_text, decided_by, decided_at
                             ) VALUES (
                                :company_id, :period_id, :transaction_id,
                                :document_id, :channel, :decision, :evidence, :actor, :decided_at
                             )',
                            [
                                'company_id' => $companyId,
                                'period_id' => $periodId,
                                'transaction_id' => 'SEQ-TXN-' . $companyId,
                                'document_id' => 'SEQ-DOC-' . $companyId,
                                'channel' => 'software',
                                'decision' => 'eligible',
                                'evidence' => 'Test evidence',
                                'actor' => 'test',
                                'decided_at' => $now,
                            ]
                        );
                        $eligibilityId = (int)InterfaceDB::fetchColumn(
                            InterfaceDB::driverName() === 'sqlite'
                                ? 'SELECT last_insert_rowid()'
                                : 'SELECT LAST_INSERT_ID()'
                        );
                        InterfaceDB::prepareExecute(
                            'INSERT INTO companies_house_accounts_submissions (
                                eligibility_id, company_id, accounting_period_id,
                                original_transaction_id, original_document_external_id,
                                environment, lifecycle, revised_artifact_path,
                                revised_artifact_sha256, basis_hash, idempotency_key,
                                revision_declarations_json, prepared_by
                             ) VALUES (
                                :eligibility_id, :company_id, :period_id,
                                :transaction_id, :document_id, :environment, :lifecycle,
                                :artifact_path, :artifact_hash, :basis_hash,
                                :idempotency_key, :declarations, :prepared_by
                             )',
                            [
                                'eligibility_id' => $eligibilityId,
                                'company_id' => $companyId,
                                'period_id' => $periodId,
                                'transaction_id' => 'SEQ-TXN-' . $companyId,
                                'document_id' => 'SEQ-DOC-' . $companyId,
                                'environment' => $index === 2 ? 'LIVE' : 'TEST',
                                'lifecycle' => 'prepared',
                                'artifact_path' => 'fixture-' . $companyId . '.xhtml',
                                'artifact_hash' => hash('sha256', 'artifact-' . $companyId),
                                'basis_hash' => hash('sha256', 'basis-' . $companyId),
                                'idempotency_key' => hash('sha256', 'idempotency-' . $companyId),
                                'declarations' => '{}',
                                'prepared_by' => 'test',
                            ]
                        );
                        $submissionIds[] = (int)InterfaceDB::fetchColumn(
                            InterfaceDB::driverName() === 'sqlite'
                                ? 'SELECT last_insert_rowid()'
                                : 'SELECT LAST_INSERT_ID()'
                        );
                    }

                    $service = new \eel_accounts\Service\CompaniesHouseSubmissionSequenceService();
                    $first = $service->allocate($submissionIds[0], 'TEST', $presenter);
                    $h->assertSame('000001', $first['submission_number']);
                    $blocked = false;
                    try {
                        $service->allocate($submissionIds[1], 'TEST', $presenter);
                    } catch (RuntimeException $exception) {
                        $blocked = str_contains($exception->getMessage(), 'unresolved transport state');
                    }
                    $h->assertSame(true, $blocked);

                    $service->releaseResolved($submissionIds[0], 'TEST', $fingerprint);
                    $second = $service->allocate($submissionIds[1], 'TEST', $presenter);
                    $h->assertSame('000002', $second['submission_number']);
                    $live = $service->allocate($submissionIds[2], 'LIVE', $presenter);
                    $h->assertSame('000001', $live['submission_number']);
                    $h->assertSame('000003', $service->status('TEST', $presenter)['next_number']);
                    $h->assertSame('000002', $service->status('TEST', $presenter)['last_issued_number']);

                    $conversation = new \eel_accounts\Service\CompaniesHouseProtocolConversationService(
                        null,
                        str_repeat('k', 32)
                    );
                    $cycle = $conversation->createStatusCycle($submissionIds[1]);
                    $service->acquireStatusLock($submissionIds[1], $cycle, 'TEST', $fingerprint);
                    $status = $service->status('TEST', $presenter);
                    $h->assertSame($submissionIds[1], $status['status_in_flight_submission_id']);
                    $h->assertSame($cycle, $status['status_in_flight_cycle_id']);

                    $otherCycle = $conversation->createStatusCycle($submissionIds[0]);
                    $statusBlocked = false;
                    try {
                        $service->acquireStatusLock(
                            $submissionIds[0],
                            $otherCycle,
                            'TEST',
                            $fingerprint
                        );
                    } catch (RuntimeException $exception) {
                        $statusBlocked = str_contains($exception->getMessage(), 'status/acknowledgement');
                    }
                    $h->assertSame(true, $statusBlocked);
                    $service->releaseStatusLock(
                        $submissionIds[1],
                        $cycle,
                        'TEST',
                        $fingerprint
                    );
                    $h->assertSame(
                        null,
                        $service->status('TEST', $presenter)['status_in_flight_submission_id']
                    );

                    $snapshot = InterfaceDB::fetchOne(
                        'SELECT id, manifest_sha256 FROM companies_house_schema_snapshots
                         WHERE is_active = 1 ORDER BY id DESC LIMIT 1'
                    );
                    if (is_array($snapshot)) {
                        $submission = [
                            'id' => $submissionIds[1],
                            'company_id' => $companyIds[1],
                            'accounting_period_id' => $periodIds[1],
                            'company_number' => (string)$companyIds[1],
                            'environment' => 'TEST',
                        ];
                        $preflight = $conversation->beginPreflight(
                            $submission,
                            'TEST',
                            (int)$snapshot['id'],
                            (string)$snapshot['manifest_sha256'],
                            hash('sha256', 'OUTPUT-PRESENTER'),
                            'ABC123',
                            'user:test',
                            true
                        );
                        $conversation->finishPreflight((int)$preflight['id'], [
                            'success' => true,
                            'authenticated' => true,
                            'environment' => 'TEST',
                            'company_number' => (string)$companyIds[1],
                            'company_name' => 'Sequence Test ' . $companyIds[1],
                        ]);
                        $wrongBindingBlocked = false;
                        try {
                            $conversation->consumePreflight(
                                (int)$preflight['id'],
                                $submission,
                                'WRONG1',
                                'user:test',
                                true
                            );
                        } catch (RuntimeException $exception) {
                            $wrongBindingBlocked = true;
                        }
                        $h->assertSame(true, $wrongBindingBlocked);
                        $conversation->consumePreflight(
                            (int)$preflight['id'],
                            $submission,
                            'ABC123',
                            'user:test',
                            true
                        );
                        $stored = InterfaceDB::fetchOne(
                            'SELECT binding_hmac, consumed_at
                             FROM companies_house_company_auth_preflights WHERE id = :id',
                            ['id' => (int)$preflight['id']]
                        );
                        $h->assertSame(null, $stored['binding_hmac'] ?? null);
                        $h->assertSame(true, trim((string)($stored['consumed_at'] ?? '')) !== '');
                    }
                } finally {
                    InterfaceDB::prepareExecute(
                        'DELETE FROM companies_house_submission_sequences WHERE presenter_fingerprint = :fingerprint',
                        ['fingerprint' => $fingerprint]
                    );
                    foreach ($companyIds as $companyId) {
                        InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                    }
                }
            }
        );
    }
);
