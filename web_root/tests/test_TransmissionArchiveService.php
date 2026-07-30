<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\TransmissionArchiveService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\TransmissionArchiveService::class,
            'stores immutable exact bytes in the company authority environment reference hierarchy',
            static function () use ($h): void {
                $companyId = 98731;
                $periodId = 98732;
                $root = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR . 'transmission-' . bin2hex(random_bytes(4))
                );
                try {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'Transmission Archive Test Limited',
                            'number' => '09873100',
                            'created_at' => '2026-07-23 10:00:00',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $periodId,
                            'company_id' => $companyId,
                            'label' => 'ARCHIVE-98732',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => '2026-07-23 10:00:00',
                        ]
                    );
                    $service = new \eel_accounts\Service\TransmissionArchiveService($root);
                    $request = '<GovTalkMessage>exact request</GovTalkMessage>';
                    $stored = $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        '000001',
                        'submitting',
                        'submission-request.xml',
                        $request
                    );
                    $expected = $root . DIRECTORY_SEPARATOR . '09873100'
                        . DIRECTORY_SEPARATOR . 'companies_house'
                        . DIRECTORY_SEPARATOR . 'test'
                        . DIRECTORY_SEPARATOR . '000001';
                    $h->assertSame($expected . DIRECTORY_SEPARATOR . 'submission-request.xml', $stored['path']);
                    $h->assertSame($request, (string)file_get_contents($stored['path']));
                    $h->assertTrue(is_file($expected . DIRECTORY_SEPARATOR . 'manifest.json'));

                    $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        '000001',
                        'pending',
                        'submission-response.xml',
                        '<GovTalkMessage>acknowledged</GovTalkMessage>'
                    );
                    $archive = $service->find(
                        $companyId,
                        'companies_house',
                        'TEST',
                        '000001'
                    );
                    $h->assertSame(
                        $expected . DIRECTORY_SEPARATOR . 'submission-request.xml',
                        (string)($archive['request_path'] ?? '')
                    );
                    $h->assertSame(hash('sha256', $request), (string)($archive['request_sha256'] ?? ''));
                    $h->assertSame(
                        $expected . DIRECTORY_SEPARATOR . 'submission-response.xml',
                        (string)($archive['response_path'] ?? '')
                    );
                    $h->assertSame(
                        hash('sha256', '<GovTalkMessage>acknowledged</GovTalkMessage>'),
                        (string)($archive['response_sha256'] ?? '')
                    );
                    $manifest = json_decode(
                        (string)file_get_contents($expected . DIRECTORY_SEPARATOR . 'manifest.json'),
                        true
                    );
                    $h->assertSame('eel-transmission-archive-v2', (string)$manifest['format']);
                    $h->assertSame('pending', (string)$manifest['lifecycle']);
                    $h->assertSame(2, count((array)$manifest['files']));
                    $h->assertSame(
                        hash('sha256', $request),
                        (string)$manifest['files'][0]['sha256']
                    );

                    $immutable = false;
                    try {
                        $service->store(
                            $companyId,
                            $periodId,
                            'companies_house',
                            'TEST',
                            '000001',
                            'pending',
                            'submission-request.xml',
                            '<different/>'
                        );
                    } catch (RuntimeException $exception) {
                        $immutable = str_contains($exception->getMessage(), 'immutable');
                    }
                    $h->assertTrue($immutable);

                    $pendingReference = \eel_accounts\Service\TransmissionArchiveService
                        ::companiesHousePendingReference(731);
                    $pending = $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        $pendingReference,
                        'prepared',
                        'company-data-abc123-request.xml',
                        '<CompanyDataRequest/>'
                    );
                    $h->assertSame(
                        $root . DIRECTORY_SEPARATOR . '09873100'
                            . DIRECTORY_SEPARATOR . 'companies_house'
                            . DIRECTORY_SEPARATOR . 'test'
                            . DIRECTORY_SEPARATOR . '_pending'
                            . DIRECTORY_SEPARATOR . 'submission-731'
                            . DIRECTORY_SEPARATOR . 'company-data-abc123-request.xml',
                        (string)$pending['path']
                    );
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );

        $h->check(
            \eel_accounts\Service\TransmissionArchiveService::class,
            'promotes the complete pending CompanyData conversation without changing bytes',
            static function () use ($h): void {
                $companyId = 98741;
                $periodId = 98742;
                $submissionId = 98743;
                $root = test_register_cleanup_path(
                    test_tmp_directory() . DIRECTORY_SEPARATOR . 'transmission-promotion-' . bin2hex(random_bytes(4))
                );
                try {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'Transmission Promotion Test Limited',
                            'number' => '09874100',
                            'created_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $periodId,
                            'company_id' => $companyId,
                            'label' => 'ARCHIVE-98742',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_accounts_submissions (
                            id, company_id, accounting_period_id, environment, filing_type,
                            lifecycle, basis_hash, idempotency_key, prepared_by,
                            prepared_at, status_updated_at, created_at, updated_at
                         ) VALUES (
                            :id, :company_id, :period_id, :environment, :filing_type,
                            :lifecycle, :basis_hash, :idempotency_key, :prepared_by,
                            :prepared_at, :status_updated_at, :created_at, :updated_at
                         )',
                        [
                            'id' => $submissionId,
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'environment' => 'TEST',
                            'filing_type' => 'original',
                            'lifecycle' => 'prepared',
                            'basis_hash' => str_repeat('a', 64),
                            'idempotency_key' => str_repeat('b', 64),
                            'prepared_by' => 'test',
                            'prepared_at' => '2026-07-30 10:00:00',
                            'status_updated_at' => '2026-07-30 10:00:00',
                            'created_at' => '2026-07-30 10:00:00',
                            'updated_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    $service = new \eel_accounts\Service\TransmissionArchiveService($root);
                    $pendingReference = $service::companiesHousePendingReference($submissionId);
                    $requestXml = '<CompanyDataRequest transaction="PROMOTE1"/>';
                    $responseXml = '<CompanyDataResponse transaction="PROMOTE1"/>';
                    $request = $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        $pendingReference,
                        'prepared',
                        'company-data-promote1-request.xml',
                        $requestXml
                    );
                    $response = $service->store(
                        $companyId,
                        $periodId,
                        'companies_house',
                        'TEST',
                        $pendingReference,
                        'received',
                        'company-data-promote1-response.xml',
                        $responseXml
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_company_auth_preflights (
                            submission_id, company_id, accounting_period_id, environment,
                            output_presenter_fingerprint, outcome, archive_reference,
                            request_path, request_sha256, response_path, response_sha256,
                            created_at, updated_at
                         ) VALUES (
                            :submission_id, :company_id, :period_id, :environment,
                            :fingerprint, :outcome, :archive_reference,
                            :request_path, :request_sha256, :response_path, :response_sha256,
                            :created_at, :updated_at
                         )',
                        [
                            'submission_id' => $submissionId,
                            'company_id' => $companyId,
                            'period_id' => $periodId,
                            'environment' => 'TEST',
                            'fingerprint' => str_repeat('c', 64),
                            'outcome' => 'verified',
                            'archive_reference' => $pendingReference,
                            'request_path' => $request['path'],
                            'request_sha256' => $request['sha256'],
                            'response_path' => $response['path'],
                            'response_sha256' => $response['sha256'],
                            'created_at' => '2026-07-30 10:00:00',
                            'updated_at' => '2026-07-30 10:00:00',
                        ]
                    );
                    $preflight = InterfaceDB::fetchOne(
                        'SELECT id FROM companies_house_company_auth_preflights
                         WHERE submission_id = :submission_id ORDER BY id DESC LIMIT 1',
                        ['submission_id' => $submissionId]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies_house_protocol_exchanges (
                            submission_id, preflight_id, operation, environment,
                            transaction_id, exchange_state, request_path, request_sha256,
                            response_path, response_sha256, response_status_code,
                            sent_at, received_at, created_at, updated_at
                         ) VALUES (
                            :submission_id, :preflight_id, :operation, :environment,
                            :transaction_id, :state, :request_path, :request_sha256,
                            :response_path, :response_sha256, :status_code,
                            :sent_at, :received_at, :created_at, :updated_at
                         )',
                        [
                            'submission_id' => $submissionId,
                            'preflight_id' => (int)$preflight['id'],
                            'operation' => 'company_data',
                            'environment' => 'TEST',
                            'transaction_id' => 'PROMOTE1',
                            'state' => 'succeeded',
                            'request_path' => $request['path'],
                            'request_sha256' => $request['sha256'],
                            'response_path' => $response['path'],
                            'response_sha256' => $response['sha256'],
                            'status_code' => 200,
                            'sent_at' => '2026-07-30 10:00:01',
                            'received_at' => '2026-07-30 10:00:02',
                            'created_at' => '2026-07-30 10:00:00',
                            'updated_at' => '2026-07-30 10:00:02',
                        ]
                    );
                    InterfaceDB::prepareExecute(
                        'UPDATE companies_house_accounts_submissions
                         SET submission_number = :submission_number
                         WHERE id = :id',
                        ['submission_number' => '000731', 'id' => $submissionId]
                    );

                    $promoted = $service->promoteCompaniesHousePendingBundle(
                        $companyId,
                        $periodId,
                        'TEST',
                        $submissionId,
                        '000731'
                    );
                    $target = $root . DIRECTORY_SEPARATOR . '09874100'
                        . DIRECTORY_SEPARATOR . 'companies_house'
                        . DIRECTORY_SEPARATOR . 'test'
                        . DIRECTORY_SEPARATOR . '000731';
                    $h->assertSame($target, (string)$promoted['path']);
                    $h->assertSame(
                        $requestXml,
                        (string)file_get_contents($target . DIRECTORY_SEPARATOR . 'company-data-promote1-request.xml')
                    );
                    $h->assertSame(
                        $responseXml,
                        (string)file_get_contents($target . DIRECTORY_SEPARATOR . 'company-data-promote1-response.xml')
                    );
                    $h->assertFalse(is_dir(
                        $root . DIRECTORY_SEPARATOR . '09874100'
                            . DIRECTORY_SEPARATOR . 'companies_house'
                            . DIRECTORY_SEPARATOR . 'test'
                            . DIRECTORY_SEPARATOR . '_pending'
                            . DIRECTORY_SEPARATOR . 'submission-' . $submissionId
                    ));
                    $exchange = InterfaceDB::fetchOne(
                        'SELECT request_path, response_path
                         FROM companies_house_protocol_exchanges
                         WHERE submission_id = :submission_id LIMIT 1',
                        ['submission_id' => $submissionId]
                    );
                    $h->assertTrue(str_starts_with((string)$exchange['request_path'], $target));
                    $h->assertTrue(str_starts_with((string)$exchange['response_path'], $target));
                    $manifest = json_decode(
                        (string)file_get_contents($target . DIRECTORY_SEPARATOR . 'manifest.json'),
                        true
                    );
                    $h->assertSame(1, count((array)$manifest['exchanges']));
                    $h->assertSame('PROMOTE1', (string)$manifest['exchanges'][0]['transaction_id']);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );
    }
);
