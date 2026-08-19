<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function hmrcCtTestSuccessReceipt(string $reference): string
{
    return '<SuccessResponse xmlns="http://www.inlandrevenue.gov.uk/SuccessResponse">'
        . '<IRmarkReceipt><Message code="0000">HMRC has received the HMRC-CT-CT600 document ref: '
        . $reference . ' at 07.35 on 04/08/2026. The associated IRmark was: TEST.'
        . '</Message></IRmarkReceipt><Message code="077001">Thank you for your submission</Message>'
        . '</SuccessResponse>';
}

function hmrcCtTestSuccessEnvelope(string $reference): string
{
    return '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body>'
        . hmrcCtTestSuccessReceipt($reference)
        . '</Body></GovTalkMessage>';
}

final class HmrcCtTestTransport implements \eel_accounts\Client\HmrcCtTransactionEngineTransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $submitResponses = [];

    /** @var list<array<string,mixed>> */
    public array $pollResponses = [];

    /** @var list<array<string,mixed>> */
    public array $deleteResponses = [];

    /** @var list<array<string,mixed>> */
    public array $archivedResponses = [];

    public int $submitCalls = 0;
    public int $pollCalls = 0;
    public int $deleteCalls = 0;
    public int $archivedParseCalls = 0;
    public ?Closure $archivedParseHook = null;
    public bool $credentialsPlaceholder = false;
    private static int $exchangeSequence = 0;
    /** @var list<string> */
    public array $configurationEnvironments = [];
    /** @var list<string> */
    public array $submittedBodies = [];
    /** @var list<string> */
    public array $submittedEnvironments = [];
    /** @var list<string> */
    public array $polledEndpoints = [];
    /** @var list<string> */
    public array $polledOriginalTransactions = [];
    /** @var list<list<string>> */
    public array $polledBoundTransactions = [];
    /** @var list<string> */
    public array $deletedEndpoints = [];
    /** @var list<string> */
    public array $deletedOriginalTransactions = [];
    /** @var list<list<string>> */
    public array $deletedBoundTransactions = [];
    /** @var list<list<string>> */
    public array $archivedBoundTransactions = [];

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
        string $expectedOriginalSubmissionTransactionId,
        ?string $transactionId = null,
        array $boundConversationTransactionIds = []
    ): array {
        $this->pollCalls++;
        $this->polledEndpoints[] = $responseEndpoint;
        $this->polledOriginalTransactions[] = $expectedOriginalSubmissionTransactionId;
        $this->polledBoundTransactions[] = $boundConversationTransactionIds;
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
        string $expectedOriginalSubmissionTransactionId,
        ?string $transactionId = null,
        array $boundConversationTransactionIds = []
    ): array {
        $this->deleteCalls++;
        $this->deletedEndpoints[] = $responseEndpoint;
        $this->deletedOriginalTransactions[] = $expectedOriginalSubmissionTransactionId;
        $this->deletedBoundTransactions[] = $boundConversationTransactionIds;
        $request = $this->request('delete', $environment, $correlationId, $transactionId);
        $conversation->captureRequest($request);
        $conversation->markSendStarted($request);
        $response = array_shift($this->deleteResponses) ?? $this->failure('Missing fake delete response.');
        $response['transaction_id'] = (string)$request['transaction_id'];
        $conversation->captureResponse($this->response($request, $response));
        return $response;
    }

    public function parseArchivedResponse(
        string $responseXml,
        string $operation,
        string $environment,
        string $expectedCorrelationId,
        string $expectedOriginalSubmissionTransactionId,
        string $expectedTransactionId,
        array $boundConversationTransactionIds = []
    ): array {
        unset(
            $responseXml,
            $operation,
            $environment,
            $expectedCorrelationId,
            $expectedOriginalSubmissionTransactionId
        );
        $this->archivedParseCalls++;
        $this->archivedBoundTransactions[] = $boundConversationTransactionIds;
        $response = array_shift($this->archivedResponses)
            ?? $this->failure('Missing fake archived response.');
        $hook = $this->archivedParseHook;
        $this->archivedParseHook = null;
        if ($hook instanceof Closure) {
            $hook();
        }
        $response['transaction_id'] = $expectedTransactionId;
        $response['response_transaction_id'] = (string)($response['response_transaction_id'] ?? '');
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
            ? sprintf('FACE%012d', ++self::$exchangeSequence)
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
                $evidenceId = 'EEL-FE-00000000000000000000000000098623';
                $evidenceHash = hash('sha256', 'hmrc-request-file-evidence-98623');
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews
                        (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                     VALUES (:company_id, :period_id, 1, :locked_at, :locked_by)',
                    [
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'locked_at' => $now,
                        'locked_by' => 'test',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO filing_evidence_bundles
                        (evidence_id, company_id, accounting_period_id, evidence_version,
                         application_name, application_version, calculation_build,
                         locked_at, locked_by, bundle_hash)
                     VALUES (:evidence_id, :company_id, :period_id, :version,
                             :name, :app_version, :build,
                             :locked_at, :locked_by, :bundle_hash)',
                    [
                        'evidence_id' => $evidenceId,
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'version' => 'filing-evidence-v1',
                        'name' => 'EEL Accounts tests',
                        'app_version' => 'test',
                        'build' => 'test',
                        'locked_at' => $now,
                        'locked_by' => 'test',
                        'bundle_hash' => $evidenceHash,
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
                    $unmarkedBody = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
                        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
                        . '<Sender>Company</Sender></IRheader>'
                        . '<CompanyTaxReturn/></IRenvelope>';
                    $marked = (new \eel_accounts\Service\HmrcIrmarkService())->apply(
                        '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                        . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails/></Header>'
                        . '<GovTalkDetails><Keys/></GovTalkDetails><Body>' . $unmarkedBody
                        . '</Body></GovTalkMessage>'
                    );
                    if (empty($marked['ok'])) {
                        throw new RuntimeException('Unable to IRmark the request-file CT600 fixture.');
                    }
                    $markedDocument = new DOMDocument();
                    $markedDocument->loadXML((string)$marked['xml'], LIBXML_NONET | LIBXML_NOBLANKS);
                    $markedXpath = new DOMXPath($markedDocument);
                    $markedNodes = $markedXpath->query(
                        '/*[local-name()="GovTalkMessage"]/*[local-name()="Body"]/*'
                    );
                    $markedNode = $markedNodes === false ? null : $markedNodes->item(0);
                    $body = $markedNode instanceof DOMElement
                        ? (string)$markedDocument->saveXML($markedNode)
                        : '';
                    if ($body === '') {
                        throw new RuntimeException('Unable to extract the IRmarked request-file CT600 fixture.');
                    }
                    $ct600Path = $artifactDirectory . DIRECTORY_SEPARATOR . 'ct600.xml';
                    if (file_put_contents($ct600Path, $body) !== strlen($body)) {
                        throw new RuntimeException('Unable to create the request-file CT600 fixture.');
                    }
                    $manifest = [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'ct_period_id' => $ctPeriodId,
                        'basis' => 'request-file-fixture',
                        'filing_evidence_id' => $evidenceId,
                        'filing_evidence_bundle_hash' => $evidenceHash,
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
                        'accounts_artifact_id' => 1,
                        'accounts_validation_run_id' => 2,
                        'computation_validation_run_id' => 3,
                        'hmrc_ct_filing_approval_hash' => str_repeat('c', 64),
                        'ct600_xml_path' => $ct600Path,
                        'validation' => ['mode' => $mode],
                    ];
                    $transportCalled = false;
                    $loadedCredentialProfiles = [];
                    $transactionSequence = 0;
                    $transport = new \eel_accounts\Client\HmrcCtTransactionEngineClient(
                        static function (array $request) use (&$transportCalled): array {
                            unset($request);
                            $transportCalled = true;
                            throw new RuntimeException('Request-file generation must not use HTTP transport.');
                        },
                        static function (string $environment) use (&$loadedCredentialProfiles): array {
                            $loadedCredentialProfiles[] = $environment;
                            return [
                                'sender_id' => $environment . '-SENDER',
                                'password' => $environment . '-PASSWORD',
                                'software_reference' => $environment === 'TEST' ? '1234' : '9348',
                                'product' => 'EEL Accounts Tests',
                                'version' => '1.0',
                                'email' => 'tests@example.test',
                            ];
                        },
                        static function () use (&$transactionSequence): string {
                            $transactionSequence++;
                            return sprintf('FACE%012X', $transactionSequence);
                        }
                    );
                    $before = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        transport: $transport,
                        artifactRoot: $artifactRoot,
                        packagePreparer: $package,
                        manifestResolver: static fn(int $requestedCompanyId, int $requestedCtPeriodId): array => [
                            'ok' => $requestedCompanyId === $companyId && $requestedCtPeriodId === $ctPeriodId,
                            'errors' => [],
                            'warnings' => [],
                            'source_manifest' => $manifest,
                            'body_sha256' => hash('sha256', $body),
                        ],
                        xmlEnvironmentResolver: static fn(): string => 'LIVE'
                    );
                    $generatedByMode = [];
                    foreach (['TEST', 'TIL', 'LIVE'] as $mode) {
                        $generatedByMode[$mode] = $service->generateRequestFile(
                            $companyId,
                            $ctPeriodId,
                            42,
                            null,
                            $mode
                        );
                    }
                    $defaultLive = $service->generateRequestFile($companyId, $ctPeriodId, 42);
                    $after = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );

                    $h->assertFalse($transportCalled);
                    $h->assertSame($before, $after);
                    $h->assertSame(['TEST', 'LIVE', 'LIVE'], $loadedCredentialProfiles);
                    $h->assertSame(true, (bool)$defaultLive['success']);
                    $h->assertSame('LIVE', (string)$defaultLive['mode']);
                    $h->assertSame('existing', (string)$defaultLive['status']);
                    $h->assertSame('generated', (string)$defaultLive['artifact_source']);
                    $h->assertSame(
                        (string)$generatedByMode['LIVE']['path'],
                        (string)$defaultLive['path']
                    );
                    $h->assertSame(3, (int)InterfaceDB::fetchColumn(
                        "SELECT COUNT(*) FROM filing_evidence_artifacts
                         WHERE artifact_role LIKE 'hmrc_govtalk_developer_request_%'"
                    ));
                    $expectations = [
                        'TEST' => [
                            'endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/submission',
                            'class' => 'HMRC-CT-CT600',
                            'gateway_test' => '1',
                            'sender' => 'TEST-SENDER',
                            'password' => 'TEST-PASSWORD',
                            'vendor' => '1234',
                        ],
                        'TIL' => [
                            'endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
                            'class' => 'HMRC-CT-CT600-TIL',
                            'gateway_test' => '0',
                            'sender' => 'LIVE-SENDER',
                            'password' => 'LIVE-PASSWORD',
                            'vendor' => '9348',
                        ],
                        'LIVE' => [
                            'endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
                            'class' => 'HMRC-CT-CT600',
                            'gateway_test' => '0',
                            'sender' => 'LIVE-SENDER',
                            'password' => 'LIVE-PASSWORD',
                            'vendor' => '9348',
                        ],
                    ];
                    foreach ($expectations as $mode => $expected) {
                        $generated = $generatedByMode[$mode];
                        $h->assertSame([], (array)($generated['errors'] ?? []));
                        $h->assertSame(true, (bool)$generated['success']);
                        $h->assertSame('not_sent', (string)$generated['protocol_state']);
                        $h->assertSame($mode, (string)$generated['mode']);
                        $h->assertSame($expected['endpoint'], (string)$generated['endpoint']);
                        $h->assertFalse((bool)$generated['credentials_placeholder']);
                        $h->assertTrue(str_contains(
                            implode(' ', (array)$generated['warnings']),
                            'contains configured HMRC sender credentials'
                        ));
                        $h->assertSame($artifactDirectory, dirname((string)$generated['path']));
                        $h->assertTrue(is_file((string)$generated['path']));
                        $h->assertTrue(str_starts_with(
                            (string)$generated['filename'],
                            'govtalk_ctperiod-98623_' . strtolower($mode) . '_'
                        ));
                        $stored = (string)file_get_contents((string)$generated['path']);
                        $h->assertTrue(str_contains($stored, '<Class>' . $expected['class'] . '</Class>'));
                        $h->assertTrue(str_contains(
                            $stored,
                            '<GatewayTest>' . $expected['gateway_test'] . '</GatewayTest>'
                        ));
                        $h->assertTrue(str_contains($stored, '<SenderID>' . $expected['sender'] . '</SenderID>'));
                        $h->assertTrue(str_contains($stored, '<Value>' . $expected['password'] . '</Value>'));
                        $h->assertTrue(str_contains($stored, '<URI>' . $expected['vendor'] . '</URI>'));
                        $h->assertSame(hash('sha256', $stored), (string)$generated['sha256']);
                        $h->assertSame(strlen($stored), (int)$generated['bytes']);
                    }

                    $status = $service->status($companyId, $accountingPeriodId);
                    $periodStatus = (array)(($status['periods'] ?? [])[0] ?? []);
                    foreach (['TEST', 'TIL', 'LIVE'] as $mode) {
                        $descriptor = (array)(($periodStatus['request_artifacts'] ?? [])[$mode] ?? []);
                        $h->assertTrue((bool)($descriptor['available'] ?? false));
                        $h->assertSame('generated', (string)($descriptor['source'] ?? ''));
                        $h->assertFalse(array_key_exists('storage_path', $descriptor));
                    }
                    $download = $service->requestArtifactForDownload(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        'LIVE'
                    );
                    $h->assertSame('generated', (string)$download['source']);
                    $h->assertSame((string)$generatedByMode['LIVE']['path'], (string)$download['path']);
                    $h->assertThrows(
                        static fn(): array => $service->requestArtifactForDownload(
                            $companyId,
                            $accountingPeriodId + 1,
                            $ctPeriodId,
                            'LIVE'
                        ),
                        RuntimeException::class
                    );
                    $h->assertThrows(
                        static fn(): array => $service->requestArtifactForDownload(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            'PRODUCTION'
                        ),
                        InvalidArgumentException::class
                    );

                    $livePath = (string)$generatedByMode['LIVE']['path'];
                    $liveBytes = (string)file_get_contents($livePath);
                    file_put_contents($livePath, $liveBytes . "\n<!-- tampered -->\n");
                    $h->assertThrows(
                        static fn(): array => $service->requestArtifactForDownload(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            'LIVE'
                        ),
                        RuntimeException::class
                    );
                    file_put_contents($livePath, $liveBytes);

                    InterfaceDB::prepareExecute(
                        'UPDATE filing_evidence_artifacts
                         SET storage_path = :path
                         WHERE artifact_role = :role AND ct_period_id = :ct_period_id',
                        [
                            'path' => $livePath . '.missing',
                            'role' => 'hmrc_govtalk_developer_request_live',
                            'ct_period_id' => $ctPeriodId,
                        ]
                    );
                    $h->assertThrows(
                        static fn(): array => $service->requestArtifactForDownload(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            'LIVE'
                        ),
                        RuntimeException::class
                    );
                    $outsidePath = test_register_cleanup_path(
                        test_tmp_directory() . DIRECTORY_SEPARATOR
                            . 'outside-hmrc-request-' . bin2hex(random_bytes(4)) . '.xml'
                    );
                    file_put_contents($outsidePath, $liveBytes);
                    InterfaceDB::prepareExecute(
                        'UPDATE filing_evidence_artifacts
                         SET storage_path = :path, filename = :filename
                         WHERE artifact_role = :role AND ct_period_id = :ct_period_id',
                        [
                            'path' => $outsidePath,
                            'filename' => basename($outsidePath),
                            'role' => 'hmrc_govtalk_developer_request_live',
                            'ct_period_id' => $ctPeriodId,
                        ]
                    );
                    $h->assertThrows(
                        static fn(): array => $service->requestArtifactForDownload(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            'LIVE'
                        ),
                        RuntimeException::class
                    );
                    InterfaceDB::prepareExecute(
                        'UPDATE filing_evidence_artifacts
                         SET storage_path = :path, filename = :filename
                         WHERE artifact_role = :role AND ct_period_id = :ct_period_id',
                        [
                            'path' => $livePath,
                            'filename' => basename($livePath),
                            'role' => 'hmrc_govtalk_developer_request_live',
                            'ct_period_id' => $ctPeriodId,
                        ]
                    );

                    $changedManifest = array_replace($manifest, ['basis' => 'request-file-fixture-changed']);
                    $changedManifestService = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        transport: $transport,
                        artifactRoot: $artifactRoot,
                        manifestResolver: static fn(): array => [
                            'ok' => true,
                            'errors' => [],
                            'warnings' => [],
                            'source_manifest' => $changedManifest,
                            'body_sha256' => hash('sha256', $body),
                        ],
                        xmlEnvironmentResolver: static fn(): string => 'LIVE'
                    );
                    $changedManifestStatus = $changedManifestService->status($companyId, $accountingPeriodId);
                    foreach ((array)(($changedManifestStatus['periods'][0] ?? [])['request_artifacts'] ?? []) as $descriptor) {
                        $h->assertFalse((bool)($descriptor['available'] ?? false));
                    }
                    $changedBodyService = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        transport: $transport,
                        artifactRoot: $artifactRoot,
                        manifestResolver: static fn(): array => [
                            'ok' => true,
                            'errors' => [],
                            'warnings' => [],
                            'source_manifest' => $manifest,
                            'body_sha256' => hash('sha256', $body . '<changed/>'),
                        ],
                        xmlEnvironmentResolver: static fn(): string => 'LIVE'
                    );
                    $changedBodyStatus = $changedBodyService->status($companyId, $accountingPeriodId);
                    foreach ((array)(($changedBodyStatus['periods'][0] ?? [])['request_artifacts'] ?? []) as $descriptor) {
                        $h->assertFalse((bool)($descriptor['available'] ?? false));
                    }

                    $testConfiguredService = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        transport: $transport,
                        artifactRoot: $artifactRoot,
                        packagePreparer: $package,
                        xmlEnvironmentResolver: static fn(): string => 'TEST'
                    );
                    $disallowed = $testConfiguredService->generateRequestFile(
                        $companyId,
                        $ctPeriodId,
                        42,
                        null,
                        'TIL'
                    );
                    $h->assertFalse((bool)$disallowed['success']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$disallowed['errors']),
                        'not permitted'
                    ));
                    $invalid = $service->generateRequestFile(
                        $companyId,
                        $ctPeriodId,
                        42,
                        null,
                        'PRODUCTION'
                    );
                    $h->assertFalse((bool)$invalid['success']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$invalid['errors']),
                        'must be TEST, TIL or LIVE'
                    ));
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
                    $currentBody = $body;
                    $currentManifestData = $manifest;
                    $package = static function (
                        int $requestedCompanyId,
                        int $requestedCtPeriodId,
                        string $mode
                    ) use ($accountingPeriodId, &$currentBody, &$currentManifestData): array {
                        return [
                            'ok' => true,
                            'errors' => [],
                            'warnings' => [],
                            'company_id' => $requestedCompanyId,
                            'accounting_period_id' => $accountingPeriodId,
                            'ct_period_id' => $requestedCtPeriodId,
                            'utr' => '0123456789',
                            'filing_body_xml' => $currentBody,
                            'source_manifest' => $currentManifestData,
                            'body_sha256' => hash('sha256', $currentBody),
                            'accounts_ixbrl_path' => 'fixture/accounts.html',
                            'accounts_artifact_id' => 11,
                            'accounts_validation_run_id' => 12,
                            'accounts_run_id' => 1,
                            'accounts_sha256' => str_repeat('a', 64),
                            'computations_ixbrl_path' => 'fixture/computations.html',
                            'computation_run_id' => 2,
                            'computation_validation_run_id' => 13,
                            'computations_sha256' => str_repeat('b', 64),
                            'hmrc_ct_filing_approval_hash' => str_repeat('c', 64),
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
                    };
                    $currentManifest = static function (
                        int $requestedCompanyId,
                        int $requestedCtPeriodId
                    ) use ($companyId, $ctPeriodId, &$currentBody, &$currentManifestData): array {
                        return [
                            'ok' => $requestedCompanyId === $companyId
                                && $requestedCtPeriodId === $ctPeriodId,
                            'errors' => [],
                            'warnings' => [],
                            'source_manifest' => $currentManifestData,
                            'body_sha256' => hash('sha256', $currentBody),
                        ];
                    };
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
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
                        'poll_interval' => 10,
                        'cleanup_required' => true,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => hmrcCtTestSuccessEnvelope('8596148860'),
                        'body_xml' => hmrcCtTestSuccessReceipt('8596148860'),
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
                    $beforeTil = $service->submitLive($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$beforeTil['success']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$beforeTil['errors']),
                        'must pass HMRC Test in Live'
                    ));
                    $h->assertSame(0, $transport->submitCalls);
                    $submitted = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertSame(true, (bool)$submitted['success']);
                    $h->assertSame(true, (bool)$submitted['needs_poll']);
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
                    $h->assertSame(true, is_file((string)$persisted['request_body_path']));
                    $h->assertSame(hash('sha256', $body), (string)$persisted['body_sha256']);
                    $h->assertSame(true, trim((string)$persisted['source_manifest_sha256']) !== '');

                    $previewBytes = '<GovTalkMessage>developer preview</GovTalkMessage>';
                    $previewPath = dirname((string)$persisted['request_body_path'])
                        . DIRECTORY_SEPARATOR . 'developer-preview-til.xml';
                    file_put_contents($previewPath, $previewBytes);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO filing_evidence_artifacts (
                            artifact_id, transaction_hex, bundle_id, ct_period_id,
                            artifact_role, artifact_status, filename, storage_path, sha256,
                            generator_name, generator_version, validation_status,
                            identifier_embedded, metadata_json, completed_at
                         ) VALUES (
                            :artifact_id, :transaction_hex, :bundle_id, :ct_period_id,
                            :artifact_role, :artifact_status, :filename, :storage_path, :sha256,
                            :generator_name, :generator_version, :validation_status,
                            1, :metadata_json, :completed_at
                         )',
                        [
                            'artifact_id' => 'EEL-AR-00000000000000000000000000098601',
                            'transaction_hex' => '00000000000000000000000000098601',
                            'bundle_id' => (int)$persisted['evidence_bundle_id'],
                            'ct_period_id' => $ctPeriodId,
                            'artifact_role' => 'hmrc_govtalk_developer_request_til',
                            'artifact_status' => 'generated',
                            'filename' => basename($previewPath),
                            'storage_path' => $previewPath,
                            'sha256' => hash('sha256', $previewBytes),
                            'generator_name' => 'EEL Accounts tests',
                            'generator_version' => 'test',
                            'validation_status' => 'passed',
                            'metadata_json' => json_encode([
                                'artifact_source' => 'generated',
                                'environment' => 'TIL',
                                'body_sha256' => (string)$persisted['body_sha256'],
                                'source_manifest_sha256' => (string)$persisted['source_manifest_sha256'],
                                'transmitted' => false,
                            ], JSON_THROW_ON_ERROR),
                            'completed_at' => $now,
                        ]
                    );
                    $artifactStatus = $service->status($companyId, $accountingPeriodId);
                    $tilArtifact = (array)(
                        ($artifactStatus['periods'][0]['request_artifacts']['TIL'] ?? [])
                    );
                    $h->assertTrue((bool)($tilArtifact['available'] ?? false));
                    $h->assertSame('submitted', (string)($tilArtifact['source'] ?? ''));
                    $h->assertSame($submissionId, (int)($tilArtifact['submission_id'] ?? 0));
                    $submittedDownload = $service->requestArtifactForDownload(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        'TIL'
                    );
                    $h->assertSame('submitted', (string)$submittedDownload['source']);
                    $h->assertSame(
                        realpath((string)$persisted['request_body_path']),
                        realpath((string)$submittedDownload['path'])
                    );
                    $h->assertFalse(strcasecmp((string)$submittedDownload['path'], $previewPath) === 0);

                    $now = (string)$persisted['next_poll_at'];
                    $timedOut = $service->poll($submissionId, 42);
                    $h->assertFalse((bool)$timedOut['success']);
                    $h->assertSame(true, (bool)$timedOut['needs_poll']);
                    $h->assertSame('awaiting_poll', $timedOut['protocol_state']);

                    $now = (string)InterfaceDB::fetchColumn(
                        'SELECT next_poll_at FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $polled = $service->poll($submissionId, 42);
                    $h->assertSame(true, (bool)$polled['success']);
                    $h->assertSame('delete_pending', $polled['protocol_state']);
                    $h->assertSame('til_validated', $polled['business_outcome']);
                    $h->assertSame(2, $transport->pollCalls);
                    $h->assertSame(0, $transport->deleteCalls);
                    $h->assertSame('8596148860', (string)InterfaceDB::fetchColumn(
                        'SELECT hmrc_submission_reference FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    ));

                    $acceptedPollExchange = InterfaceDB::fetchOne(
                        'SELECT * FROM govtalk_protocol_exchanges
                         WHERE hmrc_submission_id = :submission_id
                           AND operation = :operation
                           AND exchange_state = :exchange_state
                         ORDER BY id DESC LIMIT 1',
                        [
                            'submission_id' => $submissionId,
                            'operation' => 'poll',
                            'exchange_state' => 'succeeded',
                        ]
                    );
                    $h->assertTrue(is_array($acceptedPollExchange));
                    $invalidReceipt = '(count(ancestor-or-self::node()|/gti:GovTalkMessage/gti:Body)=1)';
                    InterfaceDB::prepareExecute(
                        'UPDATE hmrc_ct600_submissions
                         SET hmrc_submission_reference = :reference WHERE id = :id',
                        ['reference' => $invalidReceipt, 'id' => $submissionId]
                    );
                    $historyRows = (new \eel_accounts\Service\GovTalkTransmissionHistoryService())
                        ->submissionHistory($companyId, $accountingPeriodId, 'hmrc', 'TIL');
                    $historyRow = array_values(array_filter(
                        $historyRows,
                        static fn(array $row): bool => (int)$row['conversation_id'] === $submissionId
                    ))[0] ?? [];
                    $h->assertSame(
                        sprintf('%06d', $submissionId),
                        (string)($historyRow['submission_reference'] ?? '')
                    );
                    $h->assertSame('', (string)($historyRow['hmrc_document_reference'] ?? ''));
                    $h->assertSame('Submitted — cleanup required', (string)($historyRow['latest_status'] ?? ''));
                    $h->assertSame('success', (string)($historyRow['status_tone'] ?? ''));
                    $h->assertSame(
                        (int)$acceptedPollExchange['id'],
                        (int)($historyRow['response_reprocess_exchange_id'] ?? 0)
                    );
                    $evidenceBundleId = (int)InterfaceDB::fetchColumn(
                        'SELECT evidence_bundle_id FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $evidenceOverview = (new \eel_accounts\Service\FilingEvidenceService())
                        ->overview($companyId, $evidenceBundleId);
                    $evidenceSubmission = (array)(($evidenceOverview['hmrc_submissions'] ?? [])[0] ?? []);
                    $h->assertSame(null, $evidenceSubmission['hmrc_submission_reference'] ?? null);
                    $h->assertSame(
                        sprintf('%06d', $submissionId),
                        (string)($evidenceSubmission['internal_submission_reference'] ?? '')
                    );
                    $submissionSnapshot = InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, business_outcome, idempotency_key,
                                hmrc_correlation_id, response_endpoint, poll_interval_seconds,
                                next_poll_at, final_response_at, cleanup_completed_at,
                                cleanup_error, created_at, updated_at
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $exchangeSnapshot = InterfaceDB::fetchOne(
                        'SELECT exchange_state, outcome_code, correlation_id, transaction_id,
                                sent_at, received_at, created_at, updated_at
                         FROM govtalk_protocol_exchanges WHERE id = :id',
                        ['id' => (int)$acceptedPollExchange['id']]
                    );
                    $artifactCount = iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($artifactRoot, FilesystemIterator::SKIP_DOTS)
                    ));
                    $eventCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_submission_events WHERE submission_id = :id',
                        ['id' => $submissionId]
                    );
                    $evidenceEventCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM filing_evidence_events WHERE bundle_id = (
                            SELECT evidence_bundle_id FROM hmrc_ct600_submissions WHERE id = :id
                         )',
                        ['id' => $submissionId]
                    );
                    $auditCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM year_end_audit_log
                         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                    );
                    $transport->archivedResponses[] = [
                        'success' => true,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'accepted',
                        'correlation_id' => 'CAFE1234',
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
                        'poll_interval' => 10,
                        'cleanup_required' => true,
                        'body_xml' => hmrcCtTestSuccessReceipt('8596148860'),
                        'errors' => [],
                        'error' => '',
                    ];
                    $networkCounts = [
                        $transport->submitCalls,
                        $transport->pollCalls,
                        $transport->deleteCalls,
                    ];
                    $wrongContextRepair = $service->reprocessArchivedResponse(
                        $companyId + 1,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        (int)$acceptedPollExchange['id'],
                        42
                    );
                    $h->assertFalse((bool)$wrongContextRepair['success']);
                    $repaired = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        (int)$acceptedPollExchange['id'],
                        42
                    );
                    $h->assertTrue((bool)$repaired['success']);
                    $h->assertSame('8596148860', (string)InterfaceDB::fetchColumn(
                        'SELECT hmrc_submission_reference FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    ));
                    $repairedHistoryRows = (new \eel_accounts\Service\GovTalkTransmissionHistoryService())
                        ->submissionHistory($companyId, $accountingPeriodId, 'hmrc', 'TIL');
                    $repairedHistory = array_values(array_filter(
                        $repairedHistoryRows,
                        static fn(array $row): bool => (int)$row['conversation_id'] === $submissionId
                    ))[0] ?? [];
                    $h->assertSame('8596148860', (string)($repairedHistory['hmrc_document_reference'] ?? ''));
                    $h->assertSame(0, (int)($repairedHistory['response_reprocess_exchange_id'] ?? 0));
                    $h->assertSame($submissionSnapshot, InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, business_outcome, idempotency_key,
                                hmrc_correlation_id, response_endpoint, poll_interval_seconds,
                                next_poll_at, final_response_at, cleanup_completed_at,
                                cleanup_error, created_at, updated_at
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    ));
                    $h->assertSame($exchangeSnapshot, InterfaceDB::fetchOne(
                        'SELECT exchange_state, outcome_code, correlation_id, transaction_id,
                                sent_at, received_at, created_at, updated_at
                         FROM govtalk_protocol_exchanges WHERE id = :id',
                        ['id' => (int)$acceptedPollExchange['id']]
                    ));
                    $h->assertSame($artifactCount, iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($artifactRoot, FilesystemIterator::SKIP_DOTS)
                    )));
                    $h->assertSame($networkCounts, [
                        $transport->submitCalls,
                        $transport->pollCalls,
                        $transport->deleteCalls,
                    ]);
                    $h->assertSame($eventCount + 1, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_submission_events WHERE submission_id = :id',
                        ['id' => $submissionId]
                    ));
                    $h->assertSame($evidenceEventCount + 1, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM filing_evidence_events WHERE bundle_id = (
                            SELECT evidence_bundle_id FROM hmrc_ct600_submissions WHERE id = :id
                         )',
                        ['id' => $submissionId]
                    ));
                    $h->assertSame($auditCount + 1, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM year_end_audit_log
                         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                    ));
                    $repeatedRepair = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        (int)$acceptedPollExchange['id'],
                        42
                    );
                    $h->assertFalse((bool)$repeatedRepair['success']);

                    $nextCleanupAt = (string)InterfaceDB::fetchColumn(
                        'SELECT next_poll_at FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $now = (new DateTimeImmutable($nextCleanupAt, new DateTimeZone('UTC')))
                        ->modify('-1 second')
                        ->format('Y-m-d H:i:s');
                    $tooEarlyCleanup = $service->poll($submissionId, 42);
                    $h->assertFalse((bool)$tooEarlyCleanup['success']);
                    $h->assertSame(true, (bool)$tooEarlyCleanup['needs_poll']);
                    $h->assertSame(0, $transport->deleteCalls);

                    $now = $nextCleanupAt;
                    $cleanupFailed = $service->poll($submissionId, 42);
                    $h->assertFalse((bool)$cleanupFailed['success']);
                    $h->assertSame('delete_pending', $cleanupFailed['protocol_state']);
                    $h->assertSame(1, $transport->deleteCalls);

                    $now = (string)InterfaceDB::fetchColumn(
                        'SELECT next_poll_at FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $cleaned = $service->poll($submissionId, 42);
                    $h->assertSame(true, (bool)$cleaned['success']);
                    $h->assertSame('closed', $cleaned['protocol_state']);
                    $h->assertSame(2, $transport->deleteCalls);
                    $h->assertSame([
                        'https://transaction-engine.tax.service.gov.uk/submission',
                        'https://transaction-engine.tax.service.gov.uk/submission',
                    ], $transport->deletedEndpoints);
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
                    $exchangeRows = InterfaceDB::fetchAll(
                        'SELECT operation, transaction_id, request_path, response_path
                         FROM govtalk_protocol_exchanges
                         WHERE hmrc_submission_id = :submission_id
                         ORDER BY id',
                        ['submission_id' => $submissionId]
                    );
                    $h->assertSame(5, count($exchangeRows));
                    $exchangePaths = [];
                    foreach ($exchangeRows as $exchangeRow) {
                        $operation = strtolower((string)$exchangeRow['operation']);
                        $transaction = preg_replace(
                            '/[^a-z0-9]+/',
                            '',
                            strtolower((string)$exchangeRow['transaction_id'])
                        );
                        foreach (['request', 'response'] as $direction) {
                            $path = (string)$exchangeRow[$direction . '_path'];
                            if ($path === '') {
                                $h->assertSame('response', $direction);
                                continue;
                            }
                            $h->assertSame(
                                $operation . '-' . $transaction . '-' . $direction . '.xml',
                                basename($path)
                            );
                            $h->assertSame(true, is_file($path));
                            $exchangePaths[] = $path;
                        }
                    }
                    $h->assertSame(8, count($exchangePaths));
                    $h->assertSame(count($exchangePaths), count(array_unique($exchangePaths)));
                    $h->assertSame(true, is_file($archiveDirectory . DIRECTORY_SEPARATOR . 'manifest.json'));

                    $status = $service->status($companyId, $accountingPeriodId);
                    $h->assertSame(true, (bool)$status['success']);
                    $dependencies = (array)($status['periods'][0]['filing_dependencies'] ?? []);
                    $h->assertSame(true, (bool)($dependencies[0]['ready'] ?? false));
                    $h->assertSame(true, (bool)($dependencies[1]['ready'] ?? false));
                    $h->assertSame(true, (bool)($dependencies[2]['ready'] ?? false));
                    $h->assertSame(false, (bool)($dependencies[3]['ready'] ?? true));
                    $h->assertSame('The computation artifact filing basis is stale.', (string)($dependencies[3]['detail'] ?? ''));
                    $h->assertFalse(array_key_exists('declaration', $status['periods'][0]));
                    $h->assertFalse((bool)$status['periods'][0]['live_ready']);

                    $currentBody = str_replace('<CompanyTaxReturn/>', '<CompanyTaxReturn><Changed/></CompanyTaxReturn>', $body);
                    $changedBody = $service->submitLive($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$changedBody['success']);
                    $h->assertSame(1, $transport->submitCalls);
                    $currentBody = $body;
                    $currentManifestData = array_replace($manifest, ['basis' => 'fixture-b']);
                    $changedManifest = $service->submitLive($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$changedManifest['success']);
                    $h->assertSame(1, $transport->submitCalls);
                    $currentManifestData = $manifest;

                    $transport->submitResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'accepted',
                        'transaction_id' => 'ABCDEF1234567890',
                        'correlation_id' => 'BEEF5678',
                        'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/submission',
                        'poll_interval' => 10,
                        'cleanup_required' => true,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Accepted Live</GovTalkMessage>',
                        'body_xml' => hmrcCtTestSuccessReceipt('HMRC-LIVE-REF'),
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
                    $h->assertSame(true, (bool)$live['success']);
                    $h->assertSame('live_accepted', $live['business_outcome']);
                    $h->assertSame('delete_pending', $live['protocol_state']);
                    $h->assertSame($submissionId, (int)$live['submission']['test_submission_id']);
                    $now = (string)InterfaceDB::fetchColumn(
                        'SELECT next_poll_at FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => (int)$live['submission_id']]
                    );
                    $liveCleaned = $service->poll((int)$live['submission_id'], 42);
                    $h->assertSame(true, (bool)$liveCleaned['success']);
                    $h->assertSame('closed', $liveCleaned['protocol_state']);
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
            'closes a rejected TEST conversation before allowing only a revised filing body',
            static function () use ($h): void {
                $companyId = 98641;
                $accountingPeriodId = 98642;
                $ctPeriodId = 98643;
                $initialTime = '2026-08-04 09:30:00';
                foreach ([
                    [
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'HMRC Rejected Lifecycle Test Limited',
                            'number' => '09864100',
                            'created_at' => $initialTime,
                        ],
                    ],
                    [
                        'INSERT INTO accounting_periods
                            (id, company_id, label, period_start, period_end, created_at)
                         VALUES (:id, :company_id, :label, :start, :end, :created_at)',
                        [
                            'id' => $accountingPeriodId,
                            'company_id' => $companyId,
                            'label' => 'HMRC-REJECTED-98642',
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'created_at' => $initialTime,
                        ],
                    ],
                    [
                        'INSERT INTO corporation_tax_periods (
                            id, company_id, accounting_period_id, sequence_no,
                            period_start, period_end, status, created_at, updated_at
                         ) VALUES (
                            :id, :company_id, :period_id, 1,
                            :start, :end, :status, :created_at, :updated_at
                         )',
                        [
                            'id' => $ctPeriodId,
                            'company_id' => $companyId,
                            'period_id' => $accountingPeriodId,
                            'start' => '2025-10-01',
                            'end' => '2026-09-30',
                            'status' => 'ready',
                            'created_at' => $initialTime,
                            'updated_at' => $initialTime,
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
                        'locked_at' => $initialTime,
                        'locked_by' => 'test',
                    ]
                );
                $evidenceId = 'EEL-FE-00000000000000000000000000098641';
                $evidenceHash = hash('sha256', 'hmrc-rejected-lifecycle-evidence-98641');
                InterfaceDB::prepareExecute(
                    'INSERT INTO filing_evidence_bundles (
                        evidence_id, company_id, accounting_period_id, evidence_version,
                        application_name, application_version, calculation_build,
                        locked_at, locked_by, bundle_hash
                     ) VALUES (
                        :evidence_id, :company_id, :period_id, :version,
                        :name, :app_version, :build,
                        :locked_at, :locked_by, :bundle_hash
                     )',
                    [
                        'evidence_id' => $evidenceId,
                        'company_id' => $companyId,
                        'period_id' => $accountingPeriodId,
                        'version' => 'filing-evidence-v1',
                        'name' => 'EEL Accounts tests',
                        'app_version' => 'test',
                        'build' => 'test',
                        'locked_at' => $initialTime,
                        'locked_by' => 'test',
                        'bundle_hash' => $evidenceHash,
                    ]
                );

                try {
                    $originalBody = '<IRenvelope><CompanyTaxReturn>original</CompanyTaxReturn></IRenvelope>';
                    $revisedBody = '<IRenvelope><CompanyTaxReturn>revised</CompanyTaxReturn></IRenvelope>';
                    $originalManifest = [
                        'basis' => 'original-rejected',
                        'ct_period_id' => $ctPeriodId,
                        'filing_evidence_id' => $evidenceId,
                        'filing_evidence_bundle_hash' => $evidenceHash,
                    ];
                    $revisedManifest = array_replace($originalManifest, [
                        'basis' => 'revised-after-rejection',
                        'revision' => 2,
                    ]);
                    $currentBody = $originalBody;
                    $currentManifest = $originalManifest;
                    $package = static function (
                        int $requestedCompanyId,
                        int $requestedCtPeriodId,
                        string $mode
                    ) use (
                        $accountingPeriodId,
                        &$currentBody,
                        &$currentManifest
                    ): array {
                        return [
                            'ok' => true,
                            'errors' => [],
                            'warnings' => [],
                            'company_id' => $requestedCompanyId,
                            'accounting_period_id' => $accountingPeriodId,
                            'ct_period_id' => $requestedCtPeriodId,
                            'utr' => '0123456789',
                            'filing_body_xml' => $currentBody,
                            'source_manifest' => $currentManifest,
                            'body_sha256' => hash('sha256', $currentBody),
                            'accounts_ixbrl_path' => 'fixture/accounts.html',
                            'accounts_artifact_id' => 41,
                            'accounts_validation_run_id' => 42,
                            'accounts_run_id' => 1,
                            'accounts_sha256' => str_repeat('a', 64),
                            'computations_ixbrl_path' => 'fixture/computations.html',
                            'computation_run_id' => 2,
                            'computation_validation_run_id' => 43,
                            'computations_sha256' => str_repeat('b', 64),
                            'hmrc_ct_filing_approval_hash' => str_repeat('c', 64),
                            'year_end_locked_at' => '2026-08-04 09:30:00',
                            'irmark' => 'REJECTED-LIFECYCLE',
                            'schema_version' => 'V3/V1.994',
                            'validation' => ['mode' => $mode],
                            'approval_declaration' => [
                                'declarant_name' => 'Jane Director',
                                'declarant_status' => 'Director',
                                'declaration_at' => '2026-08-04 09:30:00',
                                'approved_at' => '2026-08-04 09:30:00',
                                'approved_by' => 'user:42',
                                'declaration_confirmed' => true,
                                'authority_confirmed' => true,
                                'original_unfiled_confirmed' => true,
                            ],
                        ];
                    };
                    $manifestResolver = static function (
                        int $requestedCompanyId,
                        int $requestedCtPeriodId
                    ) use (
                        $companyId,
                        $ctPeriodId,
                        &$currentBody,
                        &$currentManifest
                    ): array {
                        return [
                            'ok' => $requestedCompanyId === $companyId
                                && $requestedCtPeriodId === $ctPeriodId,
                            'errors' => [],
                            'warnings' => [],
                            'source_manifest' => $currentManifest,
                            'body_sha256' => hash('sha256', $currentBody),
                        ];
                    };
                    $transport = new HmrcCtTestTransport();
                    $transport->submitResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'rejected',
                        'correlation_id' => 'D3C2B9E5F98449A19863D934273FA052',
                        'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/submission',
                        'poll_interval' => 10,
                        'cleanup_required' => true,
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'response_xml' => '<GovTalkMessage>Rejected by HMRC business validation</GovTalkMessage>',
                        'body_xml' => '<ErrorResponse><Error><Number>3001</Number></Error></ErrorResponse>',
                        'errors' => [[
                            'raised_by' => 'Department',
                            'number' => '3001',
                            'type' => 'business',
                            'texts' => ['The submission failed departmental business logic.'],
                            'locations' => [],
                        ]],
                        'error' => 'HMRC error 3001: the filing was rejected by departmental business logic.',
                    ];
                    $clock = $initialTime;
                    $artifactRoot = test_register_cleanup_path(
                        test_tmp_directory() . DIRECTORY_SEPARATOR
                            . 'hmrc-rejected-lifecycle-' . bin2hex(random_bytes(4))
                    );
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static function () use (&$clock): string {
                            return $clock;
                        },
                        $artifactRoot,
                        $package,
                        $manifestResolver,
                        xmlEnvironmentResolver: static fn(): string => 'TEST'
                    );

                    $rejected = $service->submitTest($companyId, $ctPeriodId, 42);
                    $rejectedId = (int)$rejected['submission_id'];
                    $h->assertFalse((bool)$rejected['success']);
                    $h->assertTrue($rejectedId > 0);
                    $h->assertSame('rejected', $rejected['status']);
                    $h->assertSame('delete_pending', $rejected['protocol_state']);
                    $h->assertSame('rejected', $rejected['business_outcome']);
                    $h->assertSame(['TEST'], $transport->submittedEnvironments);
                    $rejectedRow = InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, business_outcome, idempotency_key,
                                transaction_id, response_body_path, response_sha256,
                                response_endpoint, next_poll_at
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $rejectedId]
                    );
                    $h->assertTrue(is_array($rejectedRow));
                    $originalIdempotencyKey = (string)$rejectedRow['idempotency_key'];
                    $originalResponsePath = (string)$rejectedRow['response_body_path'];
                    $originalResponseHash = (string)$rejectedRow['response_sha256'];
                    $originalTransactionId = (string)$rejectedRow['transaction_id'];
                    $h->assertTrue($originalIdempotencyKey !== '');
                    $h->assertTrue(is_file($originalResponsePath));
                    $h->assertSame($originalResponseHash, hash_file('sha256', $originalResponsePath));

                    $currentBody = $revisedBody;
                    $currentManifest = $revisedManifest;
                    $blockedRevision = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$blockedRevision['success']);
                    $h->assertSame([
                        'HMRC rejected this submission, but GovTalk cleanup is still pending. '
                            . 'In the History tab, select Check Submission Status before transmitting the revised return.',
                    ], (array)$blockedRevision['errors']);
                    $h->assertSame(1, $transport->submitCalls);

                    $clock = (new DateTimeImmutable(
                        (string)$rejectedRow['next_poll_at'],
                        new DateTimeZone('UTC')
                    ))->modify('-1 second')->format('Y-m-d H:i:s');
                    $tooEarly = $service->poll($rejectedId, 42);
                    $h->assertFalse((bool)$tooEarly['success']);
                    $h->assertSame('delete_pending', $tooEarly['protocol_state']);
                    $h->assertSame(0, $transport->deleteCalls);

                    $transport->deleteResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'deleted',
                        'business_outcome' => null,
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'response_xml' => '<GovTalkMessage>Deleted rejected conversation</GovTalkMessage>',
                        'errors' => [],
                        'error' => '',
                    ];
                    $clock = (string)$rejectedRow['next_poll_at'];
                    $cleaned = $service->poll($rejectedId, 42);
                    $h->assertTrue((bool)$cleaned['success']);
                    $h->assertSame('rejected', $cleaned['status']);
                    $h->assertSame('closed', $cleaned['protocol_state']);
                    $h->assertSame('rejected', $cleaned['business_outcome']);
                    $h->assertSame(1, $transport->deleteCalls);
                    $h->assertSame(
                        ['https://test-transaction-engine.tax.service.gov.uk/submission'],
                        $transport->deletedEndpoints
                    );
                    $h->assertSame([$originalTransactionId], $transport->deletedOriginalTransactions);
                    $closedRow = InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, business_outcome, idempotency_key,
                                response_body_path, response_sha256, cleanup_completed_at,
                                cleanup_response_path, cleanup_response_sha256
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $rejectedId]
                    );
                    $h->assertSame('rejected', $closedRow['status']);
                    $h->assertSame('closed', $closedRow['protocol_state']);
                    $h->assertSame('rejected', $closedRow['business_outcome']);
                    $h->assertSame($originalIdempotencyKey, $closedRow['idempotency_key']);
                    $h->assertSame($originalResponsePath, $closedRow['response_body_path']);
                    $h->assertSame($originalResponseHash, $closedRow['response_sha256']);
                    $h->assertSame($originalResponseHash, hash_file(
                        'sha256',
                        (string)$closedRow['response_body_path']
                    ));
                    $h->assertTrue(trim((string)$closedRow['cleanup_completed_at']) !== '');
                    $h->assertTrue(is_file((string)$closedRow['cleanup_response_path']));
                    $h->assertSame(
                        (string)$closedRow['cleanup_response_sha256'],
                        hash_file('sha256', (string)$closedRow['cleanup_response_path'])
                    );

                    $currentBody = $originalBody;
                    $currentManifest = $originalManifest;
                    $unchanged = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$unchanged['success']);
                    $h->assertSame($rejectedId, (int)$unchanged['submission_id']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$unchanged['errors']),
                        'already processed this exact filing basis'
                    ));
                    $h->assertSame(1, $transport->submitCalls);

                    $currentBody = $revisedBody;
                    $currentManifest = $revisedManifest;
                    $transport->submitResponses[] = [
                        'success' => true,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'acknowledged',
                        'business_outcome' => null,
                        'correlation_id' => 'A4C2B9E5F98449A19863D934273FA052',
                        'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => 10,
                        'cleanup_required' => false,
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'response_xml' => '<GovTalkMessage>Acknowledged revised body</GovTalkMessage>',
                        'body_xml' => '',
                        'errors' => [],
                        'error' => '',
                    ];
                    $revised = $service->submitTest($companyId, $ctPeriodId, 42);
                    $revisedId = (int)$revised['submission_id'];
                    $h->assertTrue((bool)$revised['success']);
                    $h->assertTrue($revisedId > $rejectedId);
                    $h->assertSame('TEST', $revised['mode']);
                    $h->assertSame('awaiting_poll', $revised['protocol_state']);
                    $h->assertSame(2, $transport->submitCalls);
                    $h->assertSame(['TEST', 'TEST'], $transport->submittedEnvironments);
                    $h->assertSame([$originalBody, $revisedBody], $transport->submittedBodies);
                    $revisedRow = InterfaceDB::fetchOne(
                        'SELECT environment, protocol_state, idempotency_key, body_sha256
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $revisedId]
                    );
                    $h->assertSame('TEST', $revisedRow['environment']);
                    $h->assertSame('awaiting_poll', $revisedRow['protocol_state']);
                    $h->assertFalse(hash_equals(
                        $originalIdempotencyKey,
                        (string)$revisedRow['idempotency_key']
                    ));
                    $h->assertSame(hash('sha256', $revisedBody), $revisedRow['body_sha256']);
                    $h->assertSame(2, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions
                         WHERE company_id = :company_id AND ct_period_id = :ct_period_id',
                        ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
                    ));
                } finally {
                    InterfaceDB::prepareExecute(
                        'DELETE FROM companies WHERE id = :id',
                        ['id' => $companyId]
                    );
                }
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'blocks blind retry and reprocesses a verified archived acknowledgement without retransmission',
            static function () use ($h): void {
                $companyId = 98611;
                $accountingPeriodId = 98612;
                $ctPeriodId = 98613;
                foreach ([
                    [
                        'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                         VALUES (:id, :name, :number, 1, :created_at)',
                        [
                            'id' => $companyId,
                            'name' => 'HMRC Uncertain Test Limited',
                            'number' => '09861100',
                            'created_at' => '2026-07-19 11:00:00',
                        ],
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
                        'accounts_ixbrl_path' => 'fixture/accounts.html',
                        'accounts_artifact_id' => 21,
                        'accounts_validation_run_id' => 22,
                        'accounts_run_id' => 1,
                        'accounts_sha256' => str_repeat('a', 64),
                        'computations_ixbrl_path' => 'fixture/computations.html',
                        'computation_run_id' => 2,
                        'computation_validation_run_id' => 23,
                        'computations_sha256' => str_repeat('b', 64),
                        'hmrc_ct_filing_approval_hash' => str_repeat('c', 64),
                        'year_end_locked_at' => '2026-07-19 11:00:00',
                        'irmark' => 'UNCERTAIN',
                        'schema_version' => 'V3/V1.994',
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
                    $acknowledgementXml = '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                        . '<EnvelopeVersion>2.0</EnvelopeVersion><Header><MessageDetails>'
                        . '<Class>HMRC-CT-CT600</Class><Qualifier>acknowledgement</Qualifier><Function>submit</Function>'
                        . '<TransactionID/><CorrelationID>D3C2B9E5F98449A19863D934273FA052</CorrelationID>'
                        . '<ResponseEndPoint PollInterval="10">https://test-transaction-engine.tax.service.gov.uk/poll</ResponseEndPoint>'
                        . '</MessageDetails><SenderDetails/></Header><GovTalkDetails><Keys/></GovTalkDetails><Body/></GovTalkMessage>';
                    $transport->submitResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => true,
                        'protocol_state' => 'failed',
                        'business_outcome' => null,
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => $acknowledgementXml,
                        'errors' => [],
                        'error' => 'MISSING_TRANSACTION_ID: HMRC response omitted the transaction ID.',
                    ];
                    $clock = '2026-07-19 11:00:00';
                    $artifactRoot = test_register_cleanup_path(
                        test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-uncertain-' . bin2hex(random_bytes(4))
                    );
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static function () use (&$clock): string {
                            return $clock;
                        },
                        $artifactRoot,
                        $package,
                        $resolver,
                        xmlEnvironmentResolver: static fn(): string => 'LIVE'
                    );
                    $first = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$first['success']);
                    $h->assertSame('transport_uncertain', $first['protocol_state']);
                    $second = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$second['success']);
                    $h->assertTrue(str_contains(implode(' ', $second['errors']), 'uncertain'));
                    $h->assertSame(1, $transport->submitCalls);

                    $submissionId = (int)$first['submission_id'];
                    $submitExchange = InterfaceDB::fetchOne(
                        'SELECT e.id, e.transaction_id, e.exchange_state,
                                e.request_path, e.response_path,
                                e.transmission_archive_id, a.submission_reference
                         FROM govtalk_protocol_exchanges e
                         INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
                         WHERE e.hmrc_submission_id = :submission_id AND e.operation = :operation',
                        ['submission_id' => $submissionId, 'operation' => 'submit']
                    );
                    $h->assertTrue(is_array($submitExchange));
                    $exchangeId = (int)$submitExchange['id'];
                    $stateSnapshot = static fn(): array => (array)InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, transaction_id, response_body_path,
                                response_sha256, recovery_attempts
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $beforeNegativeChecks = $stateSnapshot();

                    $wrongExchange = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        $exchangeId + 999999,
                        42
                    );
                    $h->assertFalse((bool)$wrongExchange['success']);
                    $h->assertSame($beforeNegativeChecks, $stateSnapshot());

                    $responsePath = (string)$submitExchange['response_path'];
                    $originalResponseBytes = (string)file_get_contents($responsePath);
                    try {
                        file_put_contents($responsePath, $originalResponseBytes . '<tampered/>');
                        $tamperedResponse = $service->reprocessArchivedResponse(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            $submissionId,
                            $exchangeId,
                            42
                        );
                        $h->assertFalse((bool)$tamperedResponse['success']);
                        $h->assertSame($beforeNegativeChecks, $stateSnapshot());
                    } finally {
                        file_put_contents($responsePath, $originalResponseBytes);
                    }

                    $requestPath = (string)$submitExchange['request_path'];
                    $originalRequestBytes = (string)file_get_contents($requestPath);
                    try {
                        file_put_contents($requestPath, $originalRequestBytes . '<tampered/>');
                        $tamperedRequest = $service->reprocessArchivedResponse(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            $submissionId,
                            $exchangeId,
                            42
                        );
                        $h->assertFalse((bool)$tamperedRequest['success']);
                        $h->assertSame($beforeNegativeChecks, $stateSnapshot());
                    } finally {
                        file_put_contents($requestPath, $originalRequestBytes);
                    }

                    InterfaceDB::prepareExecute(
                        'UPDATE transmission_archives
                         SET submission_reference = :reference WHERE id = :id',
                        [
                            'reference' => 'submission-999999',
                            'id' => (int)$submitExchange['transmission_archive_id'],
                        ]
                    );
                    try {
                        $wrongArchive = $service->reprocessArchivedResponse(
                            $companyId,
                            $accountingPeriodId,
                            $ctPeriodId,
                            $submissionId,
                            $exchangeId,
                            42
                        );
                        $h->assertFalse((bool)$wrongArchive['success']);
                        $h->assertSame($beforeNegativeChecks, $stateSnapshot());
                    } finally {
                        InterfaceDB::prepareExecute(
                            'UPDATE transmission_archives
                             SET submission_reference = :reference WHERE id = :id',
                            [
                                'reference' => (string)$submitExchange['submission_reference'],
                                'id' => (int)$submitExchange['transmission_archive_id'],
                            ]
                        );
                    }

                    InterfaceDB::prepareExecute(
                        'UPDATE hmrc_ct600_submissions
                         SET protocol_state = :state WHERE id = :id',
                        ['state' => 'ready', 'id' => $submissionId]
                    );
                    $staleSnapshot = $stateSnapshot();
                    $stale = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        $exchangeId,
                        42
                    );
                    $h->assertFalse((bool)$stale['success']);
                    $h->assertSame($staleSnapshot, $stateSnapshot());
                    InterfaceDB::prepareExecute(
                        'UPDATE hmrc_ct600_submissions
                         SET protocol_state = :state WHERE id = :id',
                        ['state' => 'transport_uncertain', 'id' => $submissionId]
                    );
                    $h->assertSame(0, $transport->archivedParseCalls);
                    $h->assertSame(1, $transport->submitCalls);
                    $h->assertSame(0, $transport->pollCalls);
                    $h->assertSame(0, $transport->deleteCalls);

                    $transport->archivedResponses[] = [
                        'success' => true,
                        'protocol_state' => 'acknowledged',
                        'business_outcome' => null,
                        'correlation_id' => 'D3C2B9E5F98449A19863D934273FA052',
                        'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => 10,
                        'errors' => [],
                        'error' => '',
                    ];
                    $transport->archivedParseHook = static function () use ($exchangeId): void {
                        InterfaceDB::prepareExecute(
                            'UPDATE govtalk_protocol_exchanges
                             SET exchange_state = :state WHERE id = :id',
                            ['state' => 'prepared', 'id' => $exchangeId]
                        );
                    };
                    $exchangeChangedDuringParse = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        $submissionId,
                        $exchangeId,
                        42
                    );
                    $h->assertFalse((bool)$exchangeChangedDuringParse['success']);
                    $h->assertSame($beforeNegativeChecks, $stateSnapshot());
                    $h->assertSame(1, $transport->archivedParseCalls);
                    InterfaceDB::prepareExecute(
                        'UPDATE govtalk_protocol_exchanges
                         SET exchange_state = :state WHERE id = :id',
                        [
                            'state' => (string)$submitExchange['exchange_state'],
                            'id' => $exchangeId,
                        ]
                    );

                    $transport->archivedResponses[] = [
                        'success' => true,
                        'protocol_state' => 'acknowledged',
                        'business_outcome' => null,
                        'correlation_id' => 'D3C2B9E5F98449A19863D934273FA052',
                        'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/poll',
                        'poll_interval' => 10,
                        'errors' => [],
                        'error' => '',
                    ];
                    $recovered = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        (int)$first['submission_id'],
                        $exchangeId,
                        42
                    );
                    $h->assertTrue((bool)$recovered['success']);
                    $h->assertSame('awaiting_poll', $recovered['protocol_state']);
                    $h->assertTrue((bool)$recovered['needs_poll']);
                    $h->assertSame(2, $transport->archivedParseCalls);
                    $h->assertSame(
                        [(string)$submitExchange['transaction_id']],
                        $transport->archivedBoundTransactions[1]
                    );
                    $h->assertSame(1, $transport->submitCalls);
                    $h->assertSame(0, $transport->pollCalls);
                    $h->assertSame(1, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    ));
                    $persisted = InterfaceDB::fetchOne(
                        'SELECT protocol_state, hmrc_correlation_id, response_endpoint,
                                poll_interval_seconds, next_poll_at, idempotency_key
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => (int)$first['submission_id']]
                    );
                    $h->assertSame('awaiting_poll', $persisted['protocol_state']);
                    $h->assertSame(
                        'D3C2B9E5F98449A19863D934273FA052',
                        $persisted['hmrc_correlation_id']
                    );
                    $h->assertSame(
                        'https://test-transaction-engine.tax.service.gov.uk/poll',
                        $persisted['response_endpoint']
                    );
                    $h->assertSame(10, (int)$persisted['poll_interval_seconds']);
                    $h->assertTrue(trim((string)$persisted['next_poll_at']) !== '');
                    $h->assertTrue(trim((string)$persisted['idempotency_key']) !== '');
                    $exchange = InterfaceDB::fetchOne(
                        'SELECT exchange_state, outcome_code, correlation_id, transaction_id
                         FROM govtalk_protocol_exchanges
                         WHERE hmrc_submission_id = :submission_id AND operation = :operation',
                        ['submission_id' => (int)$first['submission_id'], 'operation' => 'submit']
                    );
                    $h->assertSame('succeeded', $exchange['exchange_state']);
                    $h->assertSame('acknowledged', $exchange['outcome_code']);
                    $h->assertSame(
                        'D3C2B9E5F98449A19863D934273FA052',
                        $exchange['correlation_id']
                    );

                    $repeated = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        (int)$first['submission_id'],
                        $exchangeId,
                        42
                    );
                    $h->assertFalse((bool)$repeated['success']);
                    $h->assertSame(2, $transport->archivedParseCalls);

                    $tooEarly = $service->poll((int)$first['submission_id'], 42);
                    $h->assertFalse((bool)$tooEarly['success']);
                    $h->assertTrue((bool)$tooEarly['needs_poll']);
                    $h->assertSame(0, $transport->pollCalls);

                    $transport->pollResponses[] = [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'failed',
                        'business_outcome' => null,
                        'correlation_id' => 'D3C2B9E5F98449A19863D934273FA052',
                        'status_code' => 200,
                        'headers' => [],
                        'response_xml' => '<GovTalkMessage>Archived departmental rejection</GovTalkMessage>',
                        'body_xml' => '',
                        'errors' => [],
                        'error' => 'The archived poll response could not yet be verified.',
                    ];
                    $clock = (string)$persisted['next_poll_at'];
                    $polled = $service->poll((int)$first['submission_id'], 42);
                    $h->assertFalse((bool)$polled['success']);
                    $h->assertSame('awaiting_poll', $polled['protocol_state']);
                    $h->assertSame(1, $transport->pollCalls);
                    $h->assertSame(
                        ['https://test-transaction-engine.tax.service.gov.uk/poll'],
                        $transport->polledEndpoints
                    );
                    $h->assertSame(
                        [(string)$exchange['transaction_id']],
                        $transport->polledOriginalTransactions
                    );
                    $h->assertSame(
                        [[(string)$exchange['transaction_id']]],
                        $transport->polledBoundTransactions
                    );

                    $pollExchange = InterfaceDB::fetchOne(
                        'SELECT id, transaction_id, response_path, response_sha256
                         FROM govtalk_protocol_exchanges
                         WHERE hmrc_submission_id = :submission_id AND operation = :operation
                         ORDER BY id DESC LIMIT 1',
                        ['submission_id' => (int)$first['submission_id'], 'operation' => 'poll']
                    );
                    $h->assertTrue(is_array($pollExchange));
                    $responsePath = (string)$pollExchange['response_path'];
                    $responseHash = hash_file('sha256', $responsePath);
                    $exchangeCount = (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM govtalk_protocol_exchanges WHERE hmrc_submission_id = :submission_id',
                        ['submission_id' => (int)$first['submission_id']]
                    );
                    $fileCount = iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($artifactRoot, FilesystemIterator::SKIP_DOTS)
                    ));
                    $departmentErrors = [[
                        'raised_by' => 'Department',
                        'number' => '3001',
                        'type' => 'fatal',
                        'texts' => ['The submission failed HMRC business validation.'],
                        'locations' => [],
                        'scope' => 'envelope',
                    ]];
                    for ($detail = 1; $detail <= 69; $detail++) {
                        $departmentErrors[] = [
                            'raised_by' => 'ChRIS',
                            'number' => 'BR-' . str_pad((string)$detail, 3, '0', STR_PAD_LEFT),
                            'type' => 'error',
                            'texts' => ['Detailed Corporation Tax validation error ' . $detail . '.'],
                            'locations' => ['/CompanyTaxReturn/Error[' . $detail . ']'],
                            'scope' => 'department',
                        ];
                    }
                    $transport->archivedResponses[] = [
                        'success' => false,
                        'protocol_state' => 'final_response',
                        'business_outcome' => 'rejected',
                        'correlation_id' => 'D3C2B9E5F98449A19863D934273FA052',
                        'response_transaction_id' => (string)$exchange['transaction_id'],
                        'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/submission',
                        'poll_interval' => 10,
                        'cleanup_required' => true,
                        'body_xml' => '<ErrorResponse><Error><Number>3001</Number></Error></ErrorResponse>',
                        'errors' => $departmentErrors,
                        'error' => 'HMRC error 3001: the filing was rejected with 69 detailed business-rule errors.',
                    ];
                    $reprocessed = $service->reprocessArchivedResponse(
                        $companyId,
                        $accountingPeriodId,
                        $ctPeriodId,
                        (int)$first['submission_id'],
                        (int)$pollExchange['id'],
                        42
                    );
                    $h->assertTrue((bool)$reprocessed['success']);
                    $h->assertSame('rejected', $reprocessed['status']);
                    $h->assertSame('delete_pending', $reprocessed['protocol_state']);
                    $h->assertSame('rejected', $reprocessed['business_outcome']);
                    $cleanupBlocker = 'HMRC rejected this submission, but GovTalk cleanup is still pending. '
                        . 'In the History tab, select Check Submission Status before transmitting the revised return.';
                    $status = $service->status($companyId, $accountingPeriodId);
                    $h->assertSame(1, count((array)$status['periods']));
                    $h->assertTrue(in_array(
                        $cleanupBlocker,
                        (array)$status['periods'][0]['blockers'],
                        true
                    ));
                    $blockedRetry = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$blockedRetry['success']);
                    $h->assertSame([$cleanupBlocker], array_values((array)$blockedRetry['errors']));
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$reprocessed['warnings']),
                        'View the GovTalk conversation'
                    ));
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$reprocessed['warnings']),
                        'Recorded result from the archived HMRC response (no request was sent)'
                    ));
                    $h->assertSame(3, $transport->archivedParseCalls);
                    $h->assertSame(
                        [
                            (string)$exchange['transaction_id'],
                            (string)$pollExchange['transaction_id'],
                        ],
                        $transport->archivedBoundTransactions[2]
                    );
                    $h->assertSame(1, $transport->pollCalls);
                    $h->assertSame(0, $transport->deleteCalls);
                    $rejected = InterfaceDB::fetchOne(
                        'SELECT status, protocol_state, business_outcome, transaction_id,
                                hmrc_correlation_id, response_endpoint, poll_interval_seconds,
                                next_poll_at, idempotency_key
                         FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => (int)$first['submission_id']]
                    );
                    $h->assertSame('rejected', $rejected['status']);
                    $h->assertSame('delete_pending', $rejected['protocol_state']);
                    $h->assertSame('rejected', $rejected['business_outcome']);
                    $h->assertSame((string)$pollExchange['transaction_id'], $rejected['transaction_id']);
                    $h->assertSame('D3C2B9E5F98449A19863D934273FA052', $rejected['hmrc_correlation_id']);
                    $h->assertSame('https://test-transaction-engine.tax.service.gov.uk/submission', $rejected['response_endpoint']);
                    $h->assertSame(10, (int)$rejected['poll_interval_seconds']);
                    $h->assertTrue(trim((string)$rejected['next_poll_at']) !== '');
                    $h->assertTrue(trim((string)$rejected['idempotency_key']) !== '');
                    $completedPollExchange = InterfaceDB::fetchOne(
                        'SELECT exchange_state, outcome_code, correlation_id, govtalk_errors_json
                         FROM govtalk_protocol_exchanges WHERE id = :id',
                        ['id' => (int)$pollExchange['id']]
                    );
                    $h->assertSame('rejected', $completedPollExchange['exchange_state']);
                    $h->assertSame('rejected', $completedPollExchange['outcome_code']);
                    $h->assertSame('D3C2B9E5F98449A19863D934273FA052', $completedPollExchange['correlation_id']);
                    $h->assertSame(70, count((array)json_decode((string)$completedPollExchange['govtalk_errors_json'], true)));
                    $h->assertSame($exchangeCount, (int)InterfaceDB::fetchColumn(
                        'SELECT COUNT(*) FROM govtalk_protocol_exchanges WHERE hmrc_submission_id = :submission_id',
                        ['submission_id' => (int)$first['submission_id']]
                    ));
                    $h->assertSame($fileCount, iterator_count(new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($artifactRoot, FilesystemIterator::SKIP_DOTS)
                    )));
                    $h->assertSame($responseHash, hash_file('sha256', $responsePath));
                } finally {
                    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
                }
            }
        );

        $h->check(
            \eel_accounts\Service\HmrcCorporationTaxSubmissionService::class,
            'persists a definitive Gateway rejection and releases only its idempotency reservation',
            static function () use ($h): void {
                $companyId = 98631;
                $accountingPeriodId = 98632;
                $ctPeriodId = 98633;
                $now = '2026-07-31 22:20:00';
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (id, company_name, company_number, is_active, created_at)
                     VALUES (:id, :name, :number, 1, :created_at)',
                    ['id' => $companyId, 'name' => 'HMRC Gateway Test Limited', 'number' => '09863100', 'created_at' => $now]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end, created_at)
                     VALUES (:id, :company_id, :label, :period_start, :period_end, :created_at)',
                    [
                        'id' => $accountingPeriodId,
                        'company_id' => $companyId,
                        'label' => 'HMRC-GATEWAY-98632',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
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
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                        'status' => 'ready',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
                try {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO year_end_reviews
                            (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                         VALUES (:company_id, :period_id, 1, :locked_at, :locked_by)',
                        [
                            'company_id' => $companyId,
                            'period_id' => $accountingPeriodId,
                            'locked_at' => $now,
                            'locked_by' => 'test',
                        ]
                    );
                    $evidenceId = 'EEL-FE-00000000000000000000000000098631';
                    $bundleHash = hash('sha256', 'hmrc-gateway-evidence');
                    InterfaceDB::prepareExecute(
                        'INSERT INTO filing_evidence_bundles
                            (evidence_id, company_id, accounting_period_id, evidence_version, application_name,
                             application_version, calculation_build, locked_at, locked_by, bundle_hash)
                         VALUES (:evidence_id, :company_id, :period_id, :version, :name,
                                 :app_version, :build, :locked_at, :locked_by, :bundle_hash)',
                        [
                            'evidence_id' => $evidenceId,
                            'company_id' => $companyId,
                            'period_id' => $accountingPeriodId,
                            'version' => 'filing-evidence-v1',
                            'name' => 'EEL Accounts tests',
                            'app_version' => 'test',
                            'build' => 'test',
                            'locked_at' => $now,
                            'locked_by' => 'test',
                            'bundle_hash' => $bundleHash,
                        ]
                    );
                    $manifest = [
                        'accounting_period_id' => $accountingPeriodId,
                        'basis' => 'gateway-retry-fixture',
                        'company_id' => $companyId,
                        'ct_period_id' => $ctPeriodId,
                        'filing_evidence_id' => $evidenceId,
                        'filing_evidence_bundle_hash' => $bundleHash,
                    ];
                    $body = '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
                        . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
                        . '<IRmark Type="generic">GATEWAY</IRmark></IRheader>'
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
                        'accounts_artifact_id' => 31,
                        'accounts_validation_run_id' => 32,
                        'accounts_run_id' => 1,
                        'accounts_sha256' => str_repeat('a', 64),
                        'computations_ixbrl_path' => 'fixture/computations.html',
                        'computation_run_id' => 2,
                        'computation_validation_run_id' => 33,
                        'computations_sha256' => str_repeat('b', 64),
                        'hmrc_ct_filing_approval_hash' => str_repeat('c', 64),
                        'year_end_locked_at' => $now,
                        'irmark' => 'GATEWAY',
                        'schema_version' => 'V3/V1.994',
                        'validation' => ['status' => 'passed', 'mode' => $mode],
                        'approval_declaration' => [
                            'declarant_name' => 'Jane Director',
                            'declarant_status' => 'Director',
                            'declaration_at' => $now,
                            'approved_at' => $now,
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
                    $service = new \eel_accounts\Service\HmrcCorporationTaxSubmissionService(
                        $transport,
                        null,
                        static fn(): string => $now,
                        test_register_cleanup_path(
                            test_tmp_directory() . DIRECTORY_SEPARATOR . 'hmrc-gateway-' . bin2hex(random_bytes(4))
                        ),
                        $package,
                        $currentManifest,
                        xmlEnvironmentResolver: static fn(): string => 'TEST'
                    );
                    $idempotencyKey = hash('sha256', 'gateway-rejection-fixture');
                    $canonical = (new ReflectionClass($service))->getMethod('canonicalJson');
                    $canonical->setAccessible(true);
                    $manifestHash = hash('sha256', (string)$canonical->invoke($service, $manifest));
                    InterfaceDB::prepareExecute(
                        'INSERT INTO hmrc_ct600_submissions (
                            company_id, accounting_period_id, ct_period_id, mode, environment,
                            status, protocol_state, business_outcome, idempotency_key,
                            transaction_id, source_manifest_sha256, body_sha256, created_at, updated_at
                         ) VALUES (
                            :company_id, :accounting_period_id, :ct_period_id, :mode, :environment,
                            :status, :protocol_state, :business_outcome, :idempotency_key,
                            :transaction_id, :manifest_hash, :body_hash, :created_at, :updated_at
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $accountingPeriodId,
                            'ct_period_id' => $ctPeriodId,
                            'mode' => 'TEST',
                            'environment' => 'TEST',
                            'status' => 'submitting',
                            'protocol_state' => 'submitting',
                            'business_outcome' => 'none',
                            'idempotency_key' => $idempotencyKey,
                            'transaction_id' => 'FACE000000000001',
                            'manifest_hash' => $manifestHash,
                            'body_hash' => $bodyHash,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]
                    );
                    $submissionId = (int)InterfaceDB::fetchColumn(
                        'SELECT MAX(id) FROM hmrc_ct600_submissions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    $method = (new ReflectionClass($service))->getMethod('applyConversationResult');
                    $method->setAccessible(true);
                    $command = $method->invoke($service, $submissionId, [
                        'success' => false,
                        'pre_send_failure' => false,
                        'transport_unknown' => false,
                        'protocol_state' => 'gateway_rejected',
                        'business_outcome' => null,
                        'transaction_id' => 'FACE000000000001',
                        'correlation_id' => '',
                        'cleanup_required' => false,
                        'status_code' => 200,
                        'headers' => ['content-type' => 'text/xml'],
                        'response_xml' => '',
                        'errors' => [[
                            'raised_by' => 'Gateway',
                            'number' => '1046',
                            'type' => 'fatal',
                            'texts' => ['Authentication Failure. The supplied user credentials failed validation for the requested service.'],
                            'locations' => [],
                        ]],
                        'error' => '1046: Authentication Failure. The supplied user credentials failed validation for the requested service.',
                    ], 42, false);

                    $persisted = InterfaceDB::fetchOne(
                        'SELECT * FROM hmrc_ct600_submissions WHERE id = :id',
                        ['id' => $submissionId]
                    );
                    $h->assertFalse((bool)$command['success']);
                    $h->assertSame('failed', $persisted['status']);
                    $h->assertSame('gateway_rejected', $persisted['protocol_state']);
                    $h->assertSame('error', $persisted['business_outcome']);
                    $h->assertSame(null, $persisted['idempotency_key']);
                    $h->assertSame(null, $persisted['hmrc_correlation_id']);
                    $h->assertSame(null, $persisted['next_poll_at']);
                    $h->assertSame($now, $persisted['final_response_at']);
                    $h->assertSame(0, (int)$persisted['poll_attempts']);
                    $h->assertSame(0, (int)$persisted['cleanup_attempts']);
                    $messages = implode(' ', (array)$command['errors']);
                    $h->assertTrue(str_contains($messages, '1046: Authentication Failure'));
                    $h->assertTrue(str_contains($messages, 'HMRC / XML / CT600_XML / TEST|LIVE'));

                    $ordinary = $service->submitTest($companyId, $ctPeriodId, 42);
                    $h->assertFalse((bool)$ordinary['success']);
                    $h->assertTrue(str_contains(
                        implode(' ', (array)$ordinary['errors']),
                        'Ordinary resubmission is blocked'
                    ));
                    $h->assertSame(0, $transport->submitCalls);

                    $previousDeveloperOptions = AppConfigurationStore::get('developer_options', false);
                    try {
                        AppConfigurationStore::set('developer_options', false);
                        $developerBlocked = $service->submitTest(
                            $companyId,
                            $ctPeriodId,
                            42,
                            null,
                            true
                        );
                        $h->assertFalse((bool)$developerBlocked['success']);
                        $h->assertTrue(str_contains(
                            implode(' ', (array)$developerBlocked['errors']),
                            'Developer Options must be enabled'
                        ));
                        $h->assertSame(0, $transport->submitCalls);

                        AppConfigurationStore::set('developer_options', true);
                        $transport->submitResponses[] = [
                            'success' => false,
                            'pre_send_failure' => false,
                            'transport_unknown' => false,
                            'protocol_state' => 'gateway_rejected',
                            'business_outcome' => null,
                            'correlation_id' => '',
                            'response_endpoint' => '',
                            'poll_interval' => null,
                            'cleanup_required' => false,
                            'status_code' => 200,
                            'headers' => ['content-type' => 'text/xml'],
                            'response_xml' => '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope">'
                                . '<Header><MessageDetails><Class>UndefinedClass</Class><Qualifier>error</Qualifier>'
                                . '<Function>submit</Function><TransactionID/><CorrelationID/></MessageDetails></Header>'
                                . '<GovTalkDetails><GovTalkErrors><Error><RaisedBy>Gateway</RaisedBy>'
                                . '<Number>1046</Number><Type>fatal</Type><Text>Authentication Failure.</Text>'
                                . '<Text>Credentials failed validation.</Text><Location>/Header/SenderDetails</Location>'
                                . '</Error></GovTalkErrors></GovTalkDetails><Body/></GovTalkMessage>',
                            'errors' => [[
                                'raised_by' => 'Gateway',
                                'number' => '1046',
                                'type' => 'fatal',
                                'texts' => [
                                    'Authentication Failure.',
                                    'Credentials failed validation.',
                                ],
                                'locations' => ['/Header/SenderDetails'],
                            ]],
                            'error' => '1046: Authentication Failure.',
                        ];
                        $retry = $service->submitTest(
                            $companyId,
                            $ctPeriodId,
                            42,
                            null,
                            true
                        );
                        $retryId = (int)$retry['submission_id'];
                        $h->assertFalse((bool)$retry['success']);
                        $h->assertSame('gateway_rejected', $retry['protocol_state']);
                        $h->assertTrue($retryId > $submissionId);
                        $retryRow = InterfaceDB::fetchOne(
                            'SELECT transaction_id, idempotency_key FROM hmrc_ct600_submissions WHERE id = :id',
                            ['id' => $retryId]
                        );
                        $h->assertFalse(hash_equals(
                            (string)$persisted['transaction_id'],
                            (string)$retryRow['transaction_id']
                        ));
                        $h->assertSame(null, $retryRow['idempotency_key']);
                        $h->assertSame(1, $transport->submitCalls);
                        $exchange = InterfaceDB::fetchOne(
                            'SELECT exchange_state, outcome_code, govtalk_errors_json
                             FROM govtalk_protocol_exchanges
                             WHERE hmrc_submission_id = :submission_id',
                            ['submission_id' => $retryId]
                        );
                        $h->assertSame('failed', $exchange['exchange_state']);
                        $h->assertSame('gateway_rejected', $exchange['outcome_code']);
                        $ledgerErrors = json_decode(
                            (string)$exchange['govtalk_errors_json'],
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                        $h->assertSame('Gateway', $ledgerErrors[0]['raised_by']);
                        $h->assertSame('1046', $ledgerErrors[0]['number']);
                        $h->assertSame('fatal', $ledgerErrors[0]['type']);
                        $h->assertSame(
                            ['Authentication Failure.', 'Credentials failed validation.'],
                            $ledgerErrors[0]['texts']
                        );
                        $h->assertSame(
                            ['/Header/SenderDetails'],
                            $ledgerErrors[0]['locations']
                        );
                    } finally {
                        AppConfigurationStore::set(
                            'developer_options',
                            (bool)$previousDeveloperOptions
                        );
                    }
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
                $h->assertTrue(str_contains((string)$status, 'fetchForAccountingPeriod('));
                $h->assertTrue(str_contains((string)$status, 'requestArtifactCandidatesForAccountingPeriod('));
                $h->assertTrue(str_contains((string)$status, 'requestArtifactFromCandidates('));
                $h->assertFalse(str_contains((string)$status, 'fetchForCtPeriod('));
                $h->assertFalse(str_contains((string)$status, 'requestArtifactRecordForHashes('));

                $snapshot = strstr($source, 'private function filingSnapshot(');
                $snapshot = strstr((string)$snapshot, 'private function firstDiagnostic(', true);
                $h->assertTrue(is_string($snapshot));
                $h->assertTrue(str_contains((string)$snapshot, "\$manifest['readiness']"));
                $h->assertFalse(str_contains((string)$snapshot, 'Ct600ReturnModelService'));

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
