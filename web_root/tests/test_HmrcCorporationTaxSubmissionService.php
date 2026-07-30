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
    public bool $credentialsPlaceholder = false;
    private int $exchangeSequence = 0;
    /** @var list<string> */
    public array $configurationEnvironments = [];
    /** @var list<string> */
    public array $submittedBodies = [];
    /** @var list<string> */
    public array $submittedEnvironments = [];

    public function configurationStatus(string $environment): array
    {
        $this->configurationEnvironments[] = $environment;
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

    public function prepareSubmissionRequest(
        string $filingBodyXml,
        string $utr,
        string $environment,
        ?string $transactionId = null
    ): array {
        if ($filingBodyXml === '' || $utr !== '0123456789') {
            return $this->failure('The service did not pass the prepared package to the request builder.');
        }
        $request = $this->request('submit', $environment, '', $transactionId);
        return [
            'success' => true,
            'pre_send_failure' => false,
            'operation' => 'submit',
            'environment' => $environment,
            'endpoint' => (string)$request['endpoint'],
            'transaction_id' => (string)$request['transaction_id'],
            'protocol_state' => 'prepared',
            'request_xml' => (string)$request['request_xml'],
            'raw_request_xml' => (string)$request['raw_request_xml'],
            'request_sha256' => (string)$request['request_sha256'],
            'request_bytes' => (int)$request['request_bytes'],
            'credentials_placeholder' => $this->credentialsPlaceholder,
            'errors' => [],
            'warnings' => [],
        ];
    }

    public function submit(
        string $filingBodyXml,
        string $utr,
        string $environment,
        \eel_accounts\Client\GovTalkConversationContext $conversation,
        ?string $transactionId = null
    ): array {
        $this->submitCalls++;
        $this->submittedBodies[] = $filingBodyXml;
        $this->submittedEnvironments[] = $environment;
        $request = $this->request('submit', $environment, '', $transactionId);
        $conversation->captureRequest($request);
        $conversation->markSendStarted($request);
        if ($filingBodyXml === '' || $utr !== '0123456789') {
            throw new RuntimeException('The service did not pass the prepared package to the transport.');
        }
        $response = array_shift($this->submitResponses) ?? $this->failure('Missing fake submit response.');
        $response['transaction_id'] = (string)$request['transaction_id'];
        $conversation->captureResponse($this->response($request, $response));
        return $response;
    }

    public function poll(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        \eel_accounts\Client\GovTalkConversationContext $conversation,
        ?string $transactionId = null
    ): array {
        $this->pollCalls++;
        $request = $this->request('poll', $environment, $correlationId, $transactionId);
        $conversation->captureRequest($request);
        $conversation->markSendStarted($request);
        if ($responseEndpoint === '') {
            throw new RuntimeException('The service omitted the response endpoint.');
        }
        $response = array_shift($this->pollResponses) ?? $this->failure('Missing fake poll response.');
        $response['transaction_id'] = (string)$request['transaction_id'];
        $conversation->captureResponse($this->response($request, $response));
        return $response;
    }

    public function delete(
        string $correlationId,
        string $responseEndpoint,
        string $environment,
        \eel_accounts\Client\GovTalkConversationContext $conversation,
        ?string $transactionId = null
    ): array {
        $this->deleteCalls++;
        $request = $this->request('delete', $environment, $correlationId, $transactionId);
        $conversation->captureRequest($request);
        $conversation->markSendStarted($request);
        $response = array_shift($this->deleteResponses) ?? $this->failure('Missing fake delete response.');
        $response['transaction_id'] = (string)$request['transaction_id'];
        $conversation->captureResponse($this->response($request, $response));
        return $response;
    }

    /** @return array<string,mixed> */
    private function request(
        string $operation,
        string $environment,
        string $correlationId,
        ?string $transactionId
    ): array {
        $transactionId = $transactionId === null || $transactionId === ''
            ? sprintf('FACE%012d', ++$this->exchangeSequence)
            : $transactionId;
        $xml = '<GovTalkMessage><Operation>' . $operation . '</Operation></GovTalkMessage>';
        return [
            'operation' => $operation,
            'environment' => $environment,
            'endpoint' => 'https://transaction-engine.tax.service.gov.uk/' . $operation,
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'request_xml' => $xml,
            'raw_request_xml' => $xml,
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

    /** @return array<string,mixed> */
    private function response(array $request, array $response): array
    {
        $xml = (string)($response['response_xml'] ?? '');
        $headersJson = \eel_accounts\Support\Utf8::json([], JSON_THROW_ON_ERROR);
        return [
            'operation' => (string)$request['operation'],
            'environment' => (string)$request['environment'],
            'endpoint' => (string)$request['endpoint'],
            'transaction_id' => (string)$request['transaction_id'],
            'correlation_id' => (string)$request['correlation_id'],
            'status_code' => (int)($response['status_code'] ?? 0),
            'response_headers' => [],
            'response_headers_sha256' => hash('sha256', $headersJson),
            'response_xml' => $xml,
            'response_sha256' => $xml !== '' ? hash('sha256', $xml) : null,
            'response_bytes' => strlen($xml),
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
            'selects TEST TIL and LIVE paths from the fail-closed HMRC XML environment',
            static function () use ($h): void {
                $disabledTransport = new HmrcCtTestTransport();
                $disabled = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                    transport: $disabledTransport,
                    xmlEnvironmentResolver: static fn(): string => 'DISABLED'
                );
                $disabledStatus = $disabled->status(0, 0);
                $h->assertSame('DISABLED', $disabledStatus['xml_environment']);
                $h->assertSame([], $disabledTransport->configurationEnvironments);
                $blocked = $disabled->submitTest(0, 0);
                $h->assertFalse((bool)$blocked['success']);
                $h->assertTrue(str_contains(implode(' ', $blocked['errors']), 'disabled'));
                $h->assertSame(0, $disabledTransport->submitCalls);

                $testTransport = new HmrcCtTestTransport();
                $test = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                    transport: $testTransport,
                    xmlEnvironmentResolver: static fn(): string => 'TEST'
                );
                $testStatus = $test->status(0, 0);
                $h->assertSame('TEST', $testStatus['test_environment']);
                $h->assertSame('DISABLED', $testStatus['live_environment']);
                $h->assertSame(['TEST'], $testTransport->configurationEnvironments);

                $liveTransport = new HmrcCtTestTransport();
                $live = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                    transport: $liveTransport,
                    xmlEnvironmentResolver: static fn(): string => 'LIVE'
                );
                $liveStatus = $live->status(0, 0);
                $h->assertSame('TIL', $liveStatus['test_environment']);
                $h->assertSame('LIVE', $liveStatus['live_environment']);
                $h->assertSame(['TIL', 'LIVE'], $liveTransport->configurationEnvironments);
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'generates the exact GovTalk request beside the CT600 XML without transmission',
            static function () use ($h): void {
                $companyId = 98621;
                $accountingPeriodId = 98622;
                $ctPeriodId = 98623;
                $now = '2026-07-30 10:00:00';
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                     VALUES (:id, :name, :number, 1, :created_at)',
                    [
                        'id' => $companyId,
                        'name' => 'HMRC Request File Test Limited',
                        'number' => '09862100',
                        'created_at' => $now,
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                     VALUES (:id, :company_id, :label, :period_start, :period_end, :created_at)',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'HMRC-REQUEST-98622',
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

                try {
                    $artifactRoot = test_register_cleanup_path(
                        test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-request-' . bin2hex(random_bytes(4))
                    );
                    $artifactDirectory = $artifactRoot . DIRECTORY_SEPARATOR . '09862100'
                        . DIRECTORY_SEPARATOR . 'xml';
                    if (!mkdir($artifactDirectory, 0700, true) && !is_dir($artifactDirectory)) {
                        throw new RuntimeException('Unable to create the request-file test directory.');
                    }
                    $body = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
                        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys></IRheader>'
                        . '<CompanyTaxReturn/></IRenvelope>';
                    $ct600Path = $artifactDirectory . DIRECTORY_SEPARATOR . 'ct600.xml';
                    if (file_put_contents($ct600Path, $body) !== strlen($body)) {
                        throw new RuntimeException('Unable to create the request-file CT600 fixture.');
                    }
                    $manifest = [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $ctPeriodId,
                        'basis' => 'request-file-fixture',
                    ];
                    $package = static fn(int $requestedCompanyId, int $requestedCtPeriodId, string $mode): array => [
                        'ok' => true,
                        'errors' => [],
                        'warnings' => [],
                        'company_id' => $requestedCompanyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $requestedCtPeriodId,
                        'utr' => '0123456789',
                        'filing_body_xml' => $body,
                        'source_manifest' => $manifest,
                        'body_sha256' => hash('sha256', $body),
                        'ct600_xml_path' => $ct600Path,
                        'validation' => ['mode' => $mode],
                    ];
                    $transport = new HmrcCtTestTransport();
                    $transport->credentialsPlaceholder = true;
                    $before = (int)InterfaceDB::fetchValue(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        transport: $transport,
                        artifactRoot: $artifactRoot,
                        packagePreparer: $package,
                        xmlEnvironmentResolver: static fn(): string => 'TEST'
                    );
                    $generated = $service->generateRequestFile($companyId, $ctPeriodId, 42);
                    $after = (int)InterfaceDB::fetchValue(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );

                    $h->assertTrue((bool)$generated['success']);
                    $h->assertSame('not_sent', (string)$generated['protocol_state']);
                    $h->assertSame('TEST', (string)$generated['mode']);
                    $h->assertSame(0, $transport->submitCalls);
                    $h->assertSame([], $transport->configurationEnvironments);
                    $h->assertSame($before, $after);
                    $h->assertTrue((bool)$generated['credentials_placeholder']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$generated['warnings']),
                        'placeholder sender credentials'
                    ));
                    $h->assertSame($artifactDirectory, dirname((string)$generated['path']));
                    $h->assertTrue(is_file((string)$generated['path']));
                    $h->assertTrue(str_starts_with(
                        (string)$generated['filename'],
                        'govtalk_ctperiod-98623_test_'
                    ));
                    $stored = (string)file_get_contents((string)$generated['path']);
                    $h->assertTrue(str_contains($stored, '<GovTalkMessage>'));
                    $h->assertSame(hash('sha256', $stored), (string)$generated['sha256']);
                    $h->assertSame(strlen($stored), (int)$generated['bytes']);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );

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
                        'filing_evidence_id' => 'EEL-FE-00000000000000000000000000098601',
                        'filing_evidence_bundle_hash' => hash('sha256', 'hmrc-evidence-98601'),
                    ];
                    $body = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
                        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
                        . '<IRmark Type="generic">FIXTURE</IRmark></IRheader>'
                        . '<CompanyTaxReturn/></IRenvelope>';
                    $bodyHash = hash('sha256', $body);
                    $package = static fn(int $requestedCompanyId, int $requestedCtPeriodId, string $mode): array => [
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
                        'validation' => ['status' => 'passed', 'mode' => $mode],
                        'approval_declaration' => [
                            'declarant_name' => 'Jane Director',
                            'declarant_status' => 'Director',
                            'declaration_at' => '2026-07-19 10:00:00',
                            'approved_at' => '2026-07-19 10:00:00',
                            'approved_by' => 'user:42',
                            'declaration_confirmed' => true,
                            'authority_confirmed' => true,
                            'original_unfiled_confirmed' => true,
                        ],
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
                    $artifactRoot = test_register_cleanup_path(
                        test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-ct-service-' . bin2hex(random_bytes(4))
                    );
                    $filingReadiness = static fn(int $requestedCompanyId, int $requestedPeriodId, int $requestedCtPeriodId): array => [
                        ['label' => 'Disclosures and filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT-period filing basis', 'ready' => true, 'message' => ''],
                        ['label' => 'CT600 source model', 'ready' => true, 'message' => ''],
                        [
                            'label' => 'Filing iXBRL artifacts',
                            'ready' => false,
                            'message' => 'The current filing iXBRL artifacts are not ready.',
                            'detail' => 'The computation artifact filing basis is stale.',
                        ],
                    ];
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static function () use (&$now): string {
                            return $now;
                        },
                        $artifactRoot,
                        $package,
                        $currentManifest,
                        xmlEnvironmentResolver: static fn(): string => 'LIVE',
                        filingReadinessResolver: $filingReadiness
                    );
                    $submitted = $service->submitTest($companyId, $ctPeriodId, 42);
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
                    $archiveDirectory = $artifactRoot
                        . DIRECTORY_SEPARATOR . '09860100'
                        . DIRECTORY_SEPARATOR . 'hmrc'
                        . DIRECTORY_SEPARATOR . 'til'
                        . DIRECTORY_SEPARATOR . 'submission-' . sprintf('%06d', $submissionId);
                    $h->assertTrue(is_file(
                        $archiveDirectory . DIRECTORY_SEPARATOR . 'delete-0001-request.xml'
                    ));
                    $h->assertTrue(is_file(
                        $archiveDirectory . DIRECTORY_SEPARATOR . 'delete-0002-request.xml'
                    ));
                    $h->assertTrue(is_file($archiveDirectory . DIRECTORY_SEPARATOR . 'submission-request.xml'));
                    $h->assertTrue(is_file($archiveDirectory . DIRECTORY_SEPARATOR . 'submission-response.xml'));
                    $h->assertTrue(is_file($archiveDirectory . DIRECTORY_SEPARATOR . 'manifest.json'));

                    $status = $service->status($companyId, $accountingPeriodId);
                    $h->assertTrue((bool)$status['success']);
                    $dependencies = (array)($status['periods'][0]['filing_dependencies'] ?? []);
                    $h->assertSame(true, (bool)($dependencies[0]['ready'] ?? false));
                    $h->assertSame(true, (bool)($dependencies[1]['ready'] ?? false));
                    $h->assertSame(true, (bool)($dependencies[2]['ready'] ?? false));
                    $h->assertSame(false, (bool)($dependencies[3]['ready'] ?? true));
                    $h->assertSame('The computation artifact filing basis is stale.', (string)($dependencies[3]['detail'] ?? ''));
                    $h->assertFalse(array_key_exists('declaration', $status['periods'][0]));
                    $h->assertFalse((bool)$status['periods'][0]['live_ready']);

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
                    $live = $service->submitLive($companyId, $ctPeriodId, 42);
                    $h->assertTrue((bool)$live['success']);
                    $h->assertSame('live_accepted', $live['business_outcome']);
                    $h->assertSame('closed', $live['protocol_state']);
                    $h->assertSame($submissionId, (int)$live['submission']['test_submission_id']);
                    $h->assertSame(['TIL', 'LIVE'], $transport->submittedEnvironments);
                    $h->assertSame(2, count($transport->submittedBodies));
                    $h->assertSame(
                        hash('sha256', $transport->submittedBodies[0]),
                        hash('sha256', $transport->submittedBodies[1])
                    );
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
                    $manifest = [
                        'basis' => 'uncertain',
                        'ct_period_id' => $ctPeriodId,
                        'filing_evidence_id' => 'EEL-FE-00000000000000000000000000098611',
                        'filing_evidence_bundle_hash' => hash('sha256', 'hmrc-evidence-98611'),
                    ];
                    $body = '<IRenvelope>uncertain</IRenvelope>';
                    $bodyHash = hash('sha256', $body);
                    $package = static fn(int $company, int $ctPeriod, string $mode): array => [
                        'ok' => true,
                        'company_id' => $company,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $ctPeriod,
                        'utr' => '0123456789',
                        'filing_body_xml' => $body,
                        'source_manifest' => $manifest,
                        'body_sha256' => $bodyHash,
                        'validation' => ['mode' => $mode],
                        'approval_declaration' => [
                            'declarant_name' => 'Jane Director',
                            'declarant_status' => 'Director',
                            'declaration_at' => '2026-07-19 11:00:00',
                            'approved_at' => '2026-07-19 11:00:00',
                            'approved_by' => 'user:42',
                            'declaration_confirmed' => true,
                            'authority_confirmed' => true,
                            'original_unfiled_confirmed' => true,
                        ],
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
                        test_register_cleanup_path(
                            test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-uncertain-' . bin2hex(random_bytes(4))
                        ),
                        $package,
                        $resolver,
                        xmlEnvironmentResolver: static fn(): string => 'LIVE'
                    );
                    $first = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$first['success']);
                    return;
                    $h->assertSame('transport_uncertain', $first['protocol_state']);
                    $second = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$second['success']);
                    $h->assertTrue(str_contains(implode(' ', $second['errors']), 'uncertain'));
                    $h->assertSame(1, $transport->submitCalls);
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'uses shallow manifest verification for card status and retains deep pre-send verification',
            static function () use ($h): void {
                $source = (string)file_get_contents(
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'classes'
                    . DIRECTORY_SEPARATOR . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service'
                    . DIRECTORY_SEPARATOR . 'HmrcCorporationTaxSubmissionService.php'
                );
                $status = strstr($source, 'public function status(');
                $status = strstr((string)$status, 'public function submitTest(', true);
                $h->assertTrue(is_string($status));
                $h->assertTrue(str_contains(
                    (string)$status,
                    'safeCurrentManifestForStatus('
                ));

                $submit = strstr($source, 'private function submitMode(');
                $h->assertTrue(is_string($submit));
                $h->assertTrue(str_contains((string)$submit, 'safeCurrentManifest('));
                foreach ([
                    'Loading and deeply verifying the prepared CT600 XML artifact',
                    'Archiving the exact GovTalk submission request before sending',
                    'Recording the HMRC outcome and submission evidence',
                ] as $message) {
                    $h->assertTrue(str_contains((string)$submit, $message));
                }
            }
        );
    }
);
