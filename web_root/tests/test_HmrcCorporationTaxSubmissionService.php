<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class HmrcCtTestTransport implements \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $submitResponses = [];

    /** @var list<array<string,mixed>> */
    public array $pollResponses = [];

    /** @var list<array<string,mixed>> */
    public array $deleteResponses = [];

    public int $submitCalls = 0;
    public int $pollCalls = 0;
    public int $deleteCalls = 0;

    public function configurationStatus(string $environment): array
    {
        return [
            'ready' => true,
            'credentials_configured' => true,
            'environment' => $environment,
            'credential_environment' => $environment === 'TEST' ? 'TEST' : 'LIVE',
            'class' => $environment === 'TIL' ? 'HMRC-CT-CT600-TIL' : 'HMRC-CT-CT600',
            'endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
            'poll_endpoint' => 'https://transaction-engine.tax.service.gov.uk/poll',
            'statutory' => $environment === 'LIVE',
            'blockers' => [],
        ];
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $this->submitCalls++;
        $request = $this->request('submit', $environment, '', $transactionId);
        if ($beforeSend !== null) {
            $beforeSend($request);
        }
        if ($filingBodyXml === '' || $utr !== '0123456789') {
            throw new RuntimeException('The service did not pass the prepared package to the transport.');
        }
        return array_shift($this->submitResponses) ?? $this->failure('Missing fake submit response.');
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $this->pollCalls++;
        $request = $this->request('poll', $environment, $correlationId, $transactionId);
        if ($beforeSend !== null) {
            $beforeSend($request);
        }
        if ($responseEndpoint === '') {
            throw new RuntimeException('The service omitted the response endpoint.');
        }
        return array_shift($this->pollResponses) ?? $this->failure('Missing fake poll response.');
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        ?string $transactionId = null,
        ?callable $beforeSend = null
    ): array {
        $this->deleteCalls++;
        $request = $this->request('delete', $environment, $correlationId, $transactionId);
        if ($beforeSend !== null) {
            $beforeSend($request);
        }
        return array_shift($this->deleteResponses) ?? $this->failure('Missing fake delete response.');
    }

    /** @return array<string,mixed> */
    private function request(
        string $operation,
        string $environment,
        string $correlationId,
        ?string $transactionId
    ): array {
        $transactionId = $transactionId === null || $transactionId === ''
            ? 'ABCDEF1234567890'
            : $transactionId;
        $xml = '<GovTalkMessage><Operation>' . $operation . '</Operation></GovTalkMessage>';
        return [
            'operation' => $operation,
            'environment' => $environment,
            'endpoint' => 'https://transaction-engine.tax.service.gov.uk/' . $operation,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'request_xml' => $xml,
            'request_sha256' => hash('sha256', $xml),
            'request_bytes' => strlen($xml),
        ];
    }

    /** @return array<string,mixed> */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'pre_send_failure' => false,
            'transport_unknown' => false,
            'protocol_state' => 'failed',
            'business_outcome' => null,
            'status_code' => 500,
            'headers' => [],
            'response_xml' => '',
            'error' => $message,
            'errors' => [],
        ];
    }
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
    static function (
        GeneratedServiceClassTestHarness $h,
        \eel_accounts\Service\HmrcCorporationTaxSubmissionService $unused
    ): void {
        unset($unused);

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'persists acknowledgement, polling, final TIL acceptance, cleanup and the matching LIVE gate',
            static function () use ($h): void {
                $companyId = 98601;
                $accountingPeriodId = 98602;
                $ctPeriodId = 98603;
                $now = '2026-07-19 10:00:00';
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                     VALUES (:id, :name, :number, 1, :created_at)',
                    [
                        'id' => $companyId,
                        'name' => 'HMRC Transport Test Limited',
                        'number' => '09860100',
                        'created_at' => $now,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                     VALUES (:id, :company_id, :label, :period_start, :period_end, :created_at)',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'HMRC-TRANSPORT-98602',
                        'period_start' => '2025-10-01',
                        'period_end' => '2026-09-30',
                        'created_at' => $now,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO corporation_tax_periods (
                        id, company_id, accounting_period_id, sequence_no,
                        period_start, period_end, status, created_at, updated_at
                     ) VALUES (
                        :id, :company_id, :accounting_period_id, 1,
                        :period_start, :period_end, :status, :created_at, :updated_at
                     )',
                    [
                        'id' => $ctPeriodId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'period_start' => '2025-10-01',
                        'period_end' => '2026-09-30',
                        'status' => 'ready',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews
                        (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                     VALUES (:company_id, :period_id, 1, :locked_at, :locked_by)',
                    ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'locked_at' => $now, 'locked_by' => 'test']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO filing_evidence_bundles
                        (evidence_id, company_id, accounting_period_id, evidence_version, application_name,
                         application_version, calculation_build, locked_at, locked_by, bundle_hash)
                     VALUES (:evidence_id, :company_id, :period_id, :version, :name,
                             :app_version, :build, :locked_at, :locked_by, :bundle_hash)',
                    [
                        'evidence_id' => 'EEL-FE-00000000000000000000000000098601',
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'version' => 'filing-evidence-v1',
                        'name' => 'EEL Accounts tests',
                        'app_version' => 'test',
                        'build' => 'test',
                        'locked_at' => $now,
                        'locked_by' => 'test',
                        'bundle_hash' => hash('sha256', 'hmrc-evidence-98601'),
                    ]
                );

                try {
                    $manifest = [
                        'accounting_period_id' => $accountingPeriodId,
                        'basis' => 'fixture-a',
                        'company_id' => $companyId,
                        'ct_period_id' => $ctPeriodId,
                    ];
                    $body = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
                        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
                        . '<IRmark Type="generic">FIXTURE</IRmark></IRheader>'
                        . '<CompanyTaxReturn/></IRenvelope>';
                    $bodyHash = hash('sha256', $body);
                    $package = static fn(int $requestedCompanyId, int $requestedCtPeriodId, string $mode, array $declaration): array => [
                        'ok' => true,
                        'errors' => [],
                        'warnings' => [],
                        'company_id' => $requestedCompanyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $requestedCtPeriodId,
                        'utr' => '0123456789',
                        'filing_body_xml' => $body,
                        'source_manifest' => $manifest,
                        'body_sha256' => $bodyHash,
                        'accounts_ixbrl_path' => 'fixture/accounts.html',
                        'accounts_run_id' => 1,
                        'accounts_sha256' => str_repeat('a', 64),
                        'computations_ixbrl_path' => 'fixture/computations.html',
                        'computation_run_id' => 2,
                        'computations_sha256' => str_repeat('b', 64),
                        'year_end_locked_at' => '2026-07-18 10:00:00',
                        'irmark' => 'FIXTURE',
                        'schema_version' => 'V3/V1.994',
                        'validation' => ['status' => 'passed', 'mode' => $mode, 'declaration' => $declaration],
                    ];
                    $currentManifest = static fn(int $requestedCompanyId, int $requestedCtPeriodId): array => [
                        'ok' => $requestedCompanyId === $companyId && $requestedCtPeriodId === $ctPeriodId,
                        'errors' => [],
                        'warnings' => [],
                        'source_manifest' => $manifest,
                        'body_sha256' => $bodyHash,
                    ];
                    $transport = new HmrcCtTestTransport();
                    $transport->submitResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'acknowledged',
                        'business_outcome' => null,
                        'transaction_id' => 'ABCDEF1234567890',
                        'correlation_id' => 'CAFE1234',
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => 5,
                        'cleanup_required' => false,
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'response_xml' => '<GovTalkMessage>Acknowledged</GovTalkMessage>',
                        'body_xml' => '',
                        'errors' => [],
                        'error' => '',
                    ];
                    $transport->pollResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'failed',
                        'business_outcome' => null,
                        'status_code' => 0,
                        'headers' => [],
                        'response_xml' => '',
                        'body_xml' => '',
                        'errors' => [],
                        'error' => 'The HMRC poll timed out.',
                    ];
                    $transport->pollResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'accepted',
                        'transaction_id' => 'ABCDEF1234567890',
                        'correlation_id' => 'CAFE1234',
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => null,
                        'cleanup_required' => true,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Accepted</GovTalkMessage>',
                        'body_xml' => '<Result><SubmissionReference>HMRC-TIL-REF</SubmissionReference></Result>',
                        'errors' => [],
                        'error' => '',
                    ];
                    $transport->deleteResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'failed',
                        'business_outcome' => null,
                        'status_code' => 503,
                        'headers' => [],
                        'response_xml' => '',
                        'errors' => [],
                        'error' => 'HMRC cleanup was temporarily unavailable.',
                    ];
                    $transport->deleteResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'deleted',
                        'business_outcome' => null,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Deleted</GovTalkMessage>',
                        'errors' => [],
                        'error' => '',
                    ];
                    $artifactRoot = test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'hmrc-ct-service-' . bin2hex(random_bytes(4));
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static function () use (&$now): string {
                            return $now;
                        },
                        $artifactRoot,
                        $package,
                        $currentManifest
                    );
                    $declaration = [
                        'declaration_name' => 'Jane Director',
                        'declaration_status' => 'Director',
                        'declaration_confirmed' => true,
                        'authority_confirmed' => true,
                        'supplementary_scope_confirmed' => true,
                        'original_unfiled_confirmed' => true,
                    ];
                    $submitted = $service->submitTest($companyId, $ctPeriodId, 42, $declaration);
                    $h->assertTrue((bool)$submitted['success']);
                    $h->assertTrue((bool)$submitted['needs_poll']);
                    $h->assertSame('awaiting_poll', $submitted['protocol_state']);
                    $submissionId = (int)$submitted['submission_id'];
                    $persisted = InterfaceDB::fetchOne(
                        'SELECT * FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $h->assertSame('TIL', (string)$persisted['environment']);
                    $h->assertSame(1, (int)$persisted['authority_confirmed']);
                    $h->assertSame('2026-07-19 10:00:00', (string)$persisted['authority_confirmed_at']);
                    $h->assertSame('user:42', (string)$persisted['authority_confirmed_by']);
                    $h->assertTrue(is_file((string)$persisted['request_body_path']));
                    $h->assertSame(hash('sha256', $body), (string)$persisted['body_sha256']);
                    $h->assertTrue(trim((string)$persisted['source_manifest_sha256']) !== '');

                    $now = '2026-07-19 10:00:05';
                    $timedOut = $service->poll($submissionId, 42);
                    $h->assertFalse((bool)$timedOut['success']);
                    $h->assertTrue((bool)$timedOut['needs_poll']);
                    $h->assertSame('awaiting_poll', $timedOut['protocol_state']);

                    $now = '2026-07-19 10:00:10';
                    $polled = $service->poll($submissionId, 42);
                    $h->assertTrue((bool)$polled['success']);
                    $h->assertSame('delete_pending', $polled['protocol_state']);
                    $h->assertSame('til_validated', $polled['business_outcome']);
                    $h->assertTrue((array)$polled['warnings'] !== []);
                    $h->assertSame(2, $transport->pollCalls);
                    $h->assertSame(1, $transport->deleteCalls);

                    $cleaned = $service->poll($submissionId, 42);
                    $h->assertTrue((bool)$cleaned['success']);
                    $h->assertSame('closed', $cleaned['protocol_state']);
                    $h->assertSame(2, $transport->deleteCalls);
                    $cleanedRow = InterfaceDB::fetchOne(
                        'SELECT cleanup_attempts FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $h->assertSame(2, (int)($cleanedRow['cleanup_attempts'] ?? 0));
                    $h->assertTrue(is_file(
                        $artifactRoot . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submissionId
                        . DIRECTORY_SEPARATOR . 'delete-0001-request-redacted.xml'
                    ));
                    $h->assertTrue(is_file(
                        $artifactRoot . DIRECTORY_SEPARATOR . 'submissions' . DIRECTORY_SEPARATOR . $submissionId
                        . DIRECTORY_SEPARATOR . 'delete-0002-request-redacted.xml'
                    ));

                    $status = $service->status($companyId, $accountingPeriodId);
                    $h->assertTrue((bool)$status['success']);
                    $h->assertSame('Jane Director', (string)$status['periods'][0]['declaration']['declaration_name']);
                    $h->assertFalse((bool)$status['periods'][0]['declaration']['declaration_confirmed']);
                    $h->assertTrue((bool)$status['periods'][0]['live_ready']);

                    $transport->submitResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'accepted',
                        'transaction_id' => 'ABCDEF1234567890',
                        'correlation_id' => 'BEEF5678',
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => null,
                        'cleanup_required' => true,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Accepted Live</GovTalkMessage>',
                        'body_xml' => '<Result><SubmissionReference>HMRC-LIVE-REF</SubmissionReference></Result>',
                        'errors' => [],
                        'error' => '',
                    ];
                    $transport->deleteResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'deleted',
                        'business_outcome' => null,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Deleted Live</GovTalkMessage>',
                        'errors' => [],
                        'error' => '',
                    ];
                    $live = $service->submitLive($companyId, $ctPeriodId, 42, $declaration);
                    $h->assertTrue((bool)$live['success']);
                    $h->assertSame('live_accepted', $live['business_outcome']);
                    $h->assertSame('closed', $live['protocol_state']);
                    $h->assertSame($submissionId, (int)$live['submission']['test_submission_id']);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'blocks blind retry after a transport-uncertain submit',
            static function () use ($h): void {
                $companyId = 98611;
                $accountingPeriodId = 98612;
                $ctPeriodId = 98613;
                foreach ([
                    [
                        'INSERT INTO companies (id, company_name, is_active, created_at)
                         VALUES (:id, :name, 1, :created_at)',
                        ['id' => $companyId, 'name' => 'HMRC Uncertain Test Limited', 'created_at' => '2026-07-19 11:00:00'],
                    ],
                    [
                        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $accountingPeriodId,
                            'company_id' => $companyId,
                            'label' => 'HMRC-UNCERTAIN-98612',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => '2026-07-19 11:00:00',
                        ],
                    ],
                    [
                        'INSERT INTO corporation_tax_periods (
                            id, company_id, accounting_period_id, sequence_no,
                            period_start, period_end, status, created_at, updated_at
                         ) VALUES (:id, :company_id, :period_id, 1, :start, :end, :status, :created_at, :updated_at)',
                        [
                            'id' => $ctPeriodId,
                            'company_id' => $companyId,
                            'period_id' => $accountingPeriodId,
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'status' => 'ready',
                            'created_at' => '2026-07-19 11:00:00',
                            'updated_at' => '2026-07-19 11:00:00',
                        ],
                    ],
                ] as [$sql, $params]) {
                    InterfaceDB::prepareExecute($sql, $params);
                }
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews
                        (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                     VALUES (:company_id, :period_id, 1, :locked_at, :locked_by)',
                    [
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'locked_at' => '2026-07-19 11:00:00',
                        'locked_by' => 'test',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO filing_evidence_bundles
                        (evidence_id, company_id, accounting_period_id, evidence_version, application_name,
                         application_version, calculation_build, locked_at, locked_by, bundle_hash)
                     VALUES (:evidence_id, :company_id, :period_id, :version, :name,
                             :app_version, :build, :locked_at, :locked_by, :bundle_hash)',
                    [
                        'evidence_id' => 'EEL-FE-00000000000000000000000000098611',
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'version' => 'filing-evidence-v1',
                        'name' => 'EEL Accounts tests',
                        'app_version' => 'test',
                        'build' => 'test',
                        'locked_at' => '2026-07-19 11:00:00',
                        'locked_by' => 'test',
                        'bundle_hash' => hash('sha256', 'hmrc-evidence-98611'),
                    ]
                );

                try {
                    $manifest = ['basis' => 'uncertain', 'ct_period_id' => $ctPeriodId];
                    $body = '<IRenvelope>uncertain</IRenvelope>';
                    $bodyHash = hash('sha256', $body);
                    $package = static fn(int $company, int $ctPeriod, string $mode, array $declaration): array => [
                        'ok' => true,
                        'company_id' => $company,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $ctPeriod,
                        'utr' => '0123456789',
                        'filing_body_xml' => $body,
                        'source_manifest' => $manifest,
                        'body_sha256' => $bodyHash,
                        'validation' => ['mode' => $mode, 'declaration' => $declaration],
                    ];
                    $resolver = static fn(int $company, int $ctPeriod): array => [
                        'ok' => $company === $companyId && $ctPeriod === $ctPeriodId,
                        'source_manifest' => $manifest,
                        'body_sha256' => $bodyHash,
                        'errors' => [],
                    ];
                    $transport = new HmrcCtTestTransport();
                    $transport->submitResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => true,
                        'protocol_state' => 'failed',
                        'business_outcome' => null,
                        'status_code' => 0,
                        'headers' => [],
                        'response_xml' => '',
                        'errors' => [],
                        'error' => 'Connection timed out after request transmission.',
                    ];
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static fn(): string => '2026-07-19 11:00:00',
                        test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-uncertain-' . bin2hex(random_bytes(4)),
                        $package,
                        $resolver
                    );
                    $declaration = [
                        'declaration_name' => 'Jane Director',
                        'declaration_status' => 'Director',
                        'declaration_confirmed' => true,
                        'authority_confirmed' => true,
                        'supplementary_scope_confirmed' => true,
                        'original_unfiled_confirmed' => true,
                    ];
                    $first = $service->submitTest($companyId, $ctPeriodId, 42, $declaration);
                    $h->assertFalse((bool)$first['success']);
                    $h->assertSame('transport_uncertain', $first['protocol_state']);
                    $second = $service->submitTest($companyId, $ctPeriodId, 42, $declaration);
                    $h->assertFalse((bool)$second['success']);
                    $h->assertTrue(str_contains(implode(' ', $second['errors']), 'uncertain'));
                    $h->assertSame(1, $transport->submitCalls);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );
    }
);
