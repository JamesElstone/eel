<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

use eel_accounts\Client\FakeHmrcCtGatewayClient;
use eel_accounts\Client\HmrcCtGatewayClientInterface;
use eel_accounts\Service\Ct600IxbrlArtifact;
use eel_accounts\Service\Ct600ReturnData;
use eel_accounts\Service\HmrcCtSubmissionOrchestrator;

final class HmrcCtOrchestratorClock
{
    public function __construct(public DateTimeImmutable $now)
    {
    }

    public function __invoke(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}

final class HmrcCtOrchestratorConfiguration
{
    public function __construct(public string $environment, public bool $synthetic = true)
    {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function isSyntheticTestCompany(int $companyId): bool
    {
        return $this->synthetic && $companyId === 49;
    }

    public function profile(?string $environment = null): array
    {
        $environment = strtoupper((string)($environment ?? $this->environment));
        $test = $environment === 'TEST';

        return [
            'environment' => $environment,
            'vendor_id' => '1234',
            'product' => 'EEL Accounts Test',
            'version' => '1.0',
            'schema_path' => 'fixture-ct600.xsd',
            'envelope_schema_path' => 'fixture-envelope.xsd',
            'poll_endpoint' => $test
                ? 'https://test-transaction-engine.tax.service.gov.uk/poll'
                : 'https://transaction-engine.tax.service.gov.uk/poll',
        ];
    }
}

final class HmrcCtOrchestratorReadiness
{
    public bool $submitReady = true;
    public array $submitBlockers = [];

    public function __construct(
        public string $accountsHash,
        public string $computationsHash,
    ) {
    }

    public function assess(int $companyId, int $accountingPeriodId, int $ctPeriodId, string $environment): array
    {
        return [
            'can_prepare' => true,
            'can_submit' => $this->submitReady,
            'blockers' => [],
            'submit_blockers' => $this->submitBlockers,
            'warnings' => [],
            'environment' => $environment,
            'utr' => '0123456789',
            'company' => ['id' => $companyId],
            'accounting_period' => ['id' => $accountingPeriodId],
            'ct_period' => ['id' => $ctPeriodId],
            'lock' => ['is_locked' => true, 'locked_at' => '2026-07-16 12:00:00'],
            'accounts' => ['ok' => true, 'run_id' => 83, 'hash' => $this->accountsHash],
            'computations' => ['ok' => true, 'run_id' => 84, 'hash' => $this->computationsHash],
        ];
    }
}

final class HmrcCtOrchestratorReturnFactory
{
    public function __construct(
        private readonly string $accountsPath,
        private readonly string $computationsPath,
    ) {
    }

    public function build(array $readiness, array $declaration): array
    {
        $status = match ((string)($declaration['status'] ?? '')) {
            'proper_officer' => 'Proper officer',
            'authorised_person' => 'Authorised person',
            default => throw new RuntimeException('Invalid test declarant status.'),
        };
        $calculation = [
            Ct600ReturnData::TURNOVER => 1000,
            Ct600ReturnData::TRADING_PROFITS => 0,
            Ct600ReturnData::LOSSES_BROUGHT_FORWARD => 0,
            Ct600ReturnData::NET_TRADING_PROFITS => 0,
            Ct600ReturnData::PROFITS_BEFORE_OTHER_DEDUCTIONS => 0,
            Ct600ReturnData::CAPITAL_ALLOWANCES => 0,
            Ct600ReturnData::TRADING_LOSSES => 563,
            Ct600ReturnData::TRADING_LOSSES_CARRIED_FORWARD => 563,
            Ct600ReturnData::PROFITS_BEFORE_DONATIONS_AND_GROUP_RELIEF => 0,
            Ct600ReturnData::CHARGEABLE_PROFITS => 0,
            Ct600ReturnData::AIA => 629,
            Ct600ReturnData::LOSS_ARISING => 563,
            Ct600ReturnData::CORPORATION_TAX => 0,
            Ct600ReturnData::NET_CORPORATION_TAX => 0,
            Ct600ReturnData::TOTAL_RELIEFS_AND_DEDUCTIONS => 0,
            Ct600ReturnData::TAX_PAYABLE => 0,
        ];
        $return = new Ct600ReturnData(
            companyId: 49,
            accountingPeriodId: 79,
            ctPeriodId: 6,
            ctPeriodSequence: 1,
            accountsRunId: 83,
            computationRunId: 84,
            companyName: 'Synthetic AP79 Ltd',
            registrationNumber: '09999999',
            utr: '0123456789',
            companyType: 0,
            accountingPeriodStart: '2022-09-05',
            accountingPeriodEnd: '2023-09-30',
            periodStart: '2022-09-05',
            periodEnd: '2023-09-04',
            declarationName: trim((string)($declaration['name'] ?? '')),
            declarationStatus: $status,
            declarationConfirmed: ($declaration['confirmed'] ?? null) === true,
            calculation: $calculation,
            multipleReturns: true,
        );
        $accountsHash = hash_file('sha256', $this->accountsPath);
        $computationsHash = hash_file('sha256', $this->computationsPath);
        if (!is_string($accountsHash) || !is_string($computationsHash)) {
            throw new RuntimeException('Unable to hash test iXBRL.');
        }

        return [
            'return' => $return,
            'accounts' => new Ct600IxbrlArtifact(
                Ct600IxbrlArtifact::ACCOUNTS,
                83,
                $this->accountsPath,
                basename($this->accountsPath),
                $accountsHash,
                $accountsHash,
                true,
                '2022-09-05',
                '2023-09-30',
                'frc-2026-frs-105',
                '2026-01-01',
                '09999999',
                null,
            ),
            'computation' => new Ct600IxbrlArtifact(
                Ct600IxbrlArtifact::COMPUTATION,
                84,
                $this->computationsPath,
                basename($this->computationsPath),
                $computationsHash,
                $computationsHash,
                true,
                '2022-09-05',
                '2023-09-04',
                'hmrc-computation-2025',
                '2025-01-01',
                '09999999',
                '0123456789',
            ),
            'mapping' => ['boxes' => ['780' => 563, '690' => 629]],
            'validation' => ['ok' => true, 'hashes_reverified' => true],
        ];
    }
}

final class HmrcCtOrchestratorXmlBuilder
{
    public function build(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
        ?string $schemaPath = null,
        ?callable $crossDocumentValidator = null,
    ): array {
        if ($crossDocumentValidator !== null) {
            $decision = $crossDocumentValidator($return, $accounts, $computation);
            if (!empty($decision['errors'])) {
                throw new DomainException(implode(' ', (array)$decision['errors']));
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
            . '<IRheader><Keys><Key Type="UTR">0123456789</Key></Keys>'
            . '<IRmark Type="generic"></IRmark></IRheader><CompanyTaxReturn ReturnType="new"/>'
            . '</IRenvelope>';

        return [
            'xml' => $xml,
            'body_sha256' => hash('sha256', $xml),
            'schema_validation' => ['status' => 'passed', 'errors' => []],
        ];
    }
}

final class HmrcCtOrchestratorTaxonomy
{
    public int $calls = 0;

    public function validate(
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
    ): array {
        $this->calls++;
        return [
            'accepted' => true,
            'errors' => [],
            'warnings' => ['Pinned taxonomy catalog test decision.'],
            'catalog_checked_at' => '2026-07-17',
            'accounts' => ['release' => 2026],
            'computation' => ['release' => 2025],
        ];
    }
}

final class HmrcCtOrchestratorEnvelopeBuilder
{
    public function buildSubmission(
        string $irEnvelopeXml,
        string $environment,
        string $transactionId,
        string $senderId,
        string $password,
        string $utr,
        string $vendorId,
        string $product,
        string $productVersion,
        ?string $envelopeSchemaPath = null,
    ): array {
        $irmark = base64_encode(hash('sha1', 'orchestrator-fixed-body', true));
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<IRenvelope xmlns="http://www.govtalk.gov.uk/taxation/CT/5">'
            . '<IRheader><Keys><Key Type="UTR">' . $utr . '</Key></Keys>'
            . '<IRmark Type="generic">' . $irmark . '</IRmark></IRheader>'
            . '<CompanyTaxReturn ReturnType="new"/></IRenvelope>';
        $full = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body>'
            . $body . '</Body></GovTalkMessage>';

        return [
            'xml' => $full,
            'ir_envelope_xml' => $body,
            'irmark' => $irmark,
            'irmark_display' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'canonical_body_sha256' => hash('sha256', 'orchestrator-canonical-body'),
        ];
    }
}

final class HmrcCtOrchestratorValidator
{
    public function validateFinalPackage(string $xml): array
    {
        return [
            'ok' => true,
            'status' => 'passed',
            'validator' => 'test-validator',
            'rim_version' => '1.994',
            'errors' => [],
            'warnings' => [],
            'checks' => ['xsd' => ['status' => 'passed']],
        ];
    }
}

final class HmrcCtOrchestratorStorage
{
    /** @var array<string, string> */
    public array $files = [];

    public function storePreparedPackage(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $environment,
        string $packageHash,
        string $ct600IrEnvelope,
        string $accountsIxbrl,
        string $computationsIxbrl,
        array|JsonSerializable|string $manifest,
    ): array {
        $directory = 'companies/' . $companyId . '/ap/' . $accountingPeriodId . '/ct/' . $ctPeriodId
            . '/' . $environment . '/' . $packageHash;
        $manifestBytes = is_string($manifest) ? $manifest : json_encode($manifest, JSON_THROW_ON_ERROR);
        $paths = [
            'ct600_path' => $directory . '/ct600.xml',
            'accounts_ixbrl_path' => $directory . '/accounts.html',
            'computations_ixbrl_path' => $directory . '/computations.html',
            'manifest_path' => $directory . '/manifest.json',
        ];
        $this->files[$paths['ct600_path']] = $ct600IrEnvelope;
        $this->files[$paths['accounts_ixbrl_path']] = $accountsIxbrl;
        $this->files[$paths['computations_ixbrl_path']] = $computationsIxbrl;
        $this->files[$paths['manifest_path']] = $manifestBytes;

        return $paths + [
            'directory' => $directory,
            'package_hash' => $packageHash,
            'ct600_sha256' => hash('sha256', $ct600IrEnvelope),
            'accounts_sha256' => hash('sha256', $accountsIxbrl),
            'computations_sha256' => hash('sha256', $computationsIxbrl),
            'manifest_sha256' => hash('sha256', $manifestBytes),
        ];
    }

    public function verify(string $path, string $hash): bool
    {
        return isset($this->files[$path]) && hash_equals(strtolower($hash), hash('sha256', $this->files[$path]));
    }

    public function readVerified(string $path, ?string $hash = null): string
    {
        if (!isset($this->files[$path]) || ($hash !== null && !$this->verify($path, $hash))) {
            throw new RuntimeException('Test artifact verification failed.');
        }

        return $this->files[$path];
    }

    public function storeRedactedRequest(string $directory, string $xml): array
    {
        return $this->put($directory . '/request.xml', $xml);
    }

    public function storeResponse(string $directory, string $kind, string $contents, string $identifier = ''): array
    {
        return $this->put($directory . '/responses/' . $kind . '-' . $identifier . '.xml', $contents);
    }

    private function put(string $path, string $contents): array
    {
        $this->files[$path] = $contents;
        return ['path' => $path, 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents)];
    }
}

final class HmrcCtOrchestratorRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public array $events = [];
    private int $nextId = 1;

    public function requireSchema(): void
    {
    }

    public function createPrepared(array $source, string $actor): array
    {
        foreach ($this->rows as $row) {
            if ($row['idempotency_key'] === $source['idempotency_key']) {
                return $row;
            }
        }
        $id = $this->nextId++;
        return $this->rows[$id] = $source + [
            'id' => $id,
            'status' => 'ready',
            'protocol_state' => 'prepared',
            'business_outcome' => 'none',
            'declaration_confirmed' => 0,
            'supplementary_scope_confirmed' => 0,
            'original_unfiled_confirmed' => 0,
            'approved_package_hash' => null,
            'hmrc_correlation_id' => null,
            'response_endpoint' => null,
            'poll_interval_seconds' => null,
            'next_poll_at' => null,
            'poll_attempts' => 0,
            'recovery_attempts' => 0,
            'submitted_at' => null,
        ];
    }

    public function fetchById(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function approve(int $id, int $companyId, array $declaration, string $actor): array
    {
        $row = $this->rows[$id];
        if ($row['declarant_name'] !== $declaration['name'] || $row['declarant_status'] !== $declaration['status']) {
            throw new DomainException('Declaration mismatch.');
        }
        return $this->update($id, [
            'protocol_state' => 'ready',
            'declaration_confirmed' => 1,
            'supplementary_scope_confirmed' => 1,
            'original_unfiled_confirmed' => 1,
            'approved_package_hash' => $row['package_hash'],
        ]);
    }

    public function markSubmitting(
        int $id,
        int $companyId,
        string $actor,
        ?int $authenticatedUserId = null,
        ?string $redactedRequestPath = null,
        array $requestHeaders = [],
    ): array {
        if ($this->rows[$id]['protocol_state'] !== 'ready') {
            throw new DomainException('Not ready.');
        }
        return $this->update($id, [
            'protocol_state' => 'submitting',
            'submitted_at' => '2026-07-17 09:00:00',
        ]);
    }

    public function markAcknowledged(int $id, int $companyId, array $ack): array
    {
        return $this->update($id, [
            'protocol_state' => 'awaiting_poll',
            'hmrc_correlation_id' => $ack['correlation_id'],
            'response_endpoint' => $ack['response_endpoint'],
            'poll_interval_seconds' => $ack['poll_interval_seconds'],
            'next_poll_at' => $ack['next_poll_at'],
        ]);
    }

    public function markPollAttempt(int $id, int $companyId, ?string $next = null, array $headers = []): array
    {
        return $this->update($id, [
            'protocol_state' => 'awaiting_poll',
            'next_poll_at' => $next,
            'poll_attempts' => (int)$this->rows[$id]['poll_attempts'] + 1,
        ]);
    }

    public function markFinal(int $id, int $companyId, array $final): array
    {
        $environment = $this->rows[$id]['environment'];
        $outcome = !empty($final['accepted'])
            ? match ($environment) {
                'TEST' => 'sandbox_passed',
                'TIL' => 'til_validated',
                'LIVE' => 'live_accepted',
            }
            : (!empty($final['error']) ? 'error' : 'rejected');
        return $this->update($id, [
            'protocol_state' => 'final_received',
            'business_outcome' => $outcome,
            'status' => !empty($final['accepted']) ? 'accepted' : 'rejected',
            'response_body_path' => $final['response_body_path'],
            'response_sha256' => $final['response_sha256'],
            'next_poll_at' => null,
        ]);
    }

    public function markCleanupPending(int $id, int $companyId): array
    {
        return $this->update($id, ['protocol_state' => 'delete_pending']);
    }

    public function markCleanupComplete(int $id, int $companyId, string $path, string $hash): array
    {
        return $this->update($id, ['protocol_state' => 'closed', 'cleanup_response_path' => $path]);
    }

    public function markCleanupFailed(int $id, int $companyId, string $error): array
    {
        return $this->update($id, ['protocol_state' => 'delete_pending', 'cleanup_error' => $error]);
    }

    public function markTransportUncertain(int $id, int $companyId, string $summary, ?string $next = null): array
    {
        return $this->update($id, [
            'protocol_state' => 'transport_uncertain',
            'next_poll_at' => $next,
            'hmrc_response_summary' => $summary,
        ]);
    }

    public function markRecovered(int $id, int $companyId, array $recovery): array
    {
        return $this->update($id, [
            'protocol_state' => 'awaiting_poll',
            'hmrc_correlation_id' => $recovery['correlation_id'],
            'response_endpoint' => $recovery['response_endpoint'],
            'poll_interval_seconds' => $recovery['poll_interval_seconds'],
            'next_poll_at' => $recovery['next_poll_at'],
            'recovery_attempts' => (int)$this->rows[$id]['recovery_attempts'] + 1,
        ]);
    }

    public function invalidatePrepared(int $id, int $companyId, string $reason, string $actor): array
    {
        return $this->update($id, ['protocol_state' => 'invalidated', 'invalidation_reason' => $reason]);
    }

    public function transition(
        int $id,
        int $companyId,
        array $states,
        array $changes,
        string $level,
        string $message,
        array $context = [],
    ): array {
        if (!in_array($this->rows[$id]['protocol_state'], $states, true)) {
            throw new DomainException('Unexpected fake state.');
        }
        return $this->update($id, $changes);
    }

    public function recordEvent(int $id, int $companyId, string $level, string $message, array $context = []): void
    {
        $this->events[] = compact('id', 'level', 'message', 'context');
    }

    private function update(int $id, array $changes): array
    {
        return $this->rows[$id] = array_replace($this->rows[$id], $changes);
    }
}

final class HmrcCtOrchestratorRecoveryGateway implements HmrcCtGatewayClientInterface
{
    public array $calls = [];
    private int $deleteAttempts = 0;

    public function configurationStatus(string $environment): array
    {
        return [
            'ready' => true,
            'class' => 'HMRC-CT-CT600',
            'gateway_test' => $environment === 'TEST' ? '1' : '0',
            'blockers' => [],
        ];
    }

    public function submit(string $body, string $utr, string $environment, ?string $transactionId = null): array
    {
        $this->calls[] = 'submit';
        return $this->result('submit', [
            'transport_unknown' => true,
            'protocol_state' => 'failed',
            'transaction_id' => $transactionId,
            'error' => 'Connection ended after request transmission.',
        ]);
    }

    public function poll(string $correlationId, string $endpoint, string $environment, ?string $transactionId = null): array
    {
        $this->calls[] = 'poll';
        return $this->result('poll', [
            'success' => true,
            'protocol_state' => 'final_response',
            'business_outcome' => 'accepted',
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
            'response_endpoint' => $endpoint,
            'cleanup_required' => true,
        ]);
    }

    public function delete(string $correlationId, string $endpoint, string $environment, ?string $transactionId = null): array
    {
        $this->calls[] = 'delete';
        $this->deleteAttempts++;
        if ($this->deleteAttempts === 1) {
            return $this->result('delete', [
                'transaction_id' => $transactionId,
                'correlation_id' => $correlationId,
                'error' => 'Temporary cleanup failure.',
            ]);
        }
        return $this->result('delete', [
            'success' => true,
            'protocol_state' => 'deleted',
            'transaction_id' => $transactionId,
            'correlation_id' => $correlationId,
        ]);
    }

    public function requestData(array $criteria, string $environment, ?string $transactionId = null): array
    {
        $this->calls[] = 'data_request';
        return $this->result('data_request', [
            'success' => true,
            'protocol_state' => 'data_response',
            'poll_interval' => 1,
            'response_endpoint' => 'https://test-transaction-engine.tax.service.gov.uk/poll',
            'status_records' => [[
                'transaction_id' => $transactionId,
                'correlation_id' => str_repeat('A', 32),
                'normalised_status' => 'final_response',
            ]],
        ]);
    }

    private function result(string $operation, array $overrides): array
    {
        return array_replace([
            'success' => false,
            'transport_unknown' => false,
            'operation' => $operation,
            'status_code' => 200,
            'headers' => [],
            'protocol_state' => 'failed',
            'business_outcome' => null,
            'transaction_id' => '',
            'correlation_id' => '',
            'response_endpoint' => '',
            'poll_interval' => null,
            'cleanup_required' => false,
            'errors' => [],
            'request_xml' => '',
            'response_xml' => '',
            'status_records' => [],
            'irmark' => '',
            'error' => '',
        ], $overrides);
    }
}

/** @return array<string, mixed> */
function hmrc_ct_orchestrator_fixture(string $environment, ?HmrcCtGatewayClientInterface $gateway = null): array
{
    $accountsPath = tempnam(sys_get_temp_dir(), 'ct600-accounts-');
    $computationsPath = tempnam(sys_get_temp_dir(), 'ct600-computations-');
    if (!is_string($accountsPath) || !is_string($computationsPath)) {
        throw new RuntimeException('Unable to create CT600 orchestration fixtures.');
    }
    file_put_contents($accountsPath, '<html>synthetic AP79 accounts</html>');
    file_put_contents($computationsPath, '<html>synthetic CT6 computations</html>');
    $accountsHash = (string)hash_file('sha256', $accountsPath);
    $computationsHash = (string)hash_file('sha256', $computationsPath);
    $clock = new HmrcCtOrchestratorClock(new DateTimeImmutable('2026-07-17 09:00:00', new DateTimeZone('UTC')));
    $readiness = new HmrcCtOrchestratorReadiness($accountsHash, $computationsHash);
    $repository = new HmrcCtOrchestratorRepository();
    $storage = new HmrcCtOrchestratorStorage();
    $taxonomy = new HmrcCtOrchestratorTaxonomy();
    $statutory = new ArrayObject();
    $gateway ??= new FakeHmrcCtGatewayClient();
    $orchestrator = new HmrcCtSubmissionOrchestrator(
        gateway: $gateway,
        readiness: $readiness,
        returnFactory: new HmrcCtOrchestratorReturnFactory($accountsPath, $computationsPath),
        xmlBuilder: new HmrcCtOrchestratorXmlBuilder(),
        envelopeBuilder: new HmrcCtOrchestratorEnvelopeBuilder(),
        validator: new HmrcCtOrchestratorValidator(),
        repository: $repository,
        storage: $storage,
        configuration: new HmrcCtOrchestratorConfiguration($environment),
        taxonomy: $taxonomy,
        clock: $clock,
        statutoryAcceptanceRecorder: static function (array $row) use ($statutory): void {
            $statutory[(string)$row['id']] = $row['business_outcome'];
        },
    );

    return compact(
        'orchestrator', 'gateway', 'clock', 'readiness', 'repository', 'storage',
        'statutory', 'taxonomy', 'accountsPath', 'computationsPath'
    );
}

/** @param array<string, mixed> $fixture */
function hmrc_ct_orchestrator_cleanup(array $fixture): void
{
    foreach (['accountsPath', 'computationsPath'] as $key) {
        $path = (string)($fixture[$key] ?? '');
        if ($path !== '' && is_file($path)) {
            unlink($path);
        }
    }
}

function hmrc_ct_orchestrator_approve(HmrcCtSubmissionOrchestrator $orchestrator, int $id): array
{
    return $orchestrator->approve($id, [
        'name' => 'Test Director',
        'status' => 'proper_officer',
        'confirmed' => true,
        'scope_confirmed' => true,
        'original_unfiled_confirmed' => true,
    ], 'user:42');
}

$harness = new GeneratedServiceClassTestHarness();

$harness->check(HmrcCtSubmissionOrchestrator::class, 'freezes deterministically and treats an acknowledgement as non-final', static function () use ($harness): void {
    $fixture = hmrc_ct_orchestrator_fixture('TEST');
    try {
        /** @var HmrcCtSubmissionOrchestrator $orchestrator */
        $orchestrator = $fixture['orchestrator'];
        $first = $orchestrator->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $second = $orchestrator->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $harness->assertSame($first['submission_id'], $second['submission_id']);
        $harness->assertSame(
            $first['submission']['transaction_id'],
            $second['submission']['transaction_id']
        );
        $harness->assertTrue($fixture['taxonomy']->calls >= 4);
        $harness->assertSame(
            '2026-07-17',
            $first['submission']['validation']['taxonomy']['catalog_checked_at']
        );

        hmrc_ct_orchestrator_approve($orchestrator, (int)$first['submission_id']);
        $ack = $orchestrator->submit((int)$first['submission_id'], 'user:42');
        $harness->assertSame(true, $ack['success']);
        $harness->assertSame('awaiting_poll', $ack['protocol_state']);
        $harness->assertSame('none', $ack['business_outcome']);

        $early = false;
        try {
            $orchestrator->poll((int)$first['submission_id'], 'user:42');
        } catch (DomainException $exception) {
            $early = str_contains($exception->getMessage(), 'not due until');
        }
        $harness->assertTrue($early);

        $fixture['clock']->advance('+1 second');
        $final = $orchestrator->poll((int)$first['submission_id'], 'user:42');
        $harness->assertSame('sandbox_passed', $final['business_outcome']);
        $harness->assertSame('delete_pending', $final['protocol_state']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
        $closed = $orchestrator->deleteResponse((int)$first['submission_id'], 'user:42');
        $harness->assertSame('closed', $closed['protocol_state']);
        $persisted = json_encode($fixture['storage']->files, JSON_THROW_ON_ERROR);
        $harness->assertFalse(str_contains($persisted, 'LOCAL-ONLY-DO-NOT-PERSIST'));
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'keeps Test-in-Live acceptance non-statutory', static function () use ($harness): void {
    $fixture = hmrc_ct_orchestrator_fixture('TIL');
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['orchestrator']->submit($id, 'user:42');
        $fixture['clock']->advance('+1 second');
        $final = $fixture['orchestrator']->poll($id, 'user:42');
        $harness->assertSame('til_validated', $final['business_outcome']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'invalidates an unsubmitted package when a source hash changes', static function () use ($harness): void {
    $fixture = hmrc_ct_orchestrator_fixture('TIL');
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        $fixture['readiness']->accountsHash = hash('sha256', 'changed locked source');
        $invalidated = false;
        try {
            hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        } catch (DomainException $exception) {
            $invalidated = str_contains($exception->getMessage(), 'must be prepared again');
        }
        $harness->assertTrue($invalidated);
        $harness->assertSame('invalidated', $fixture['repository']->fetchById($id)['protocol_state']);
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'keeps LIVE sequence blockers reversible and records only final LIVE acceptance', static function () use ($harness): void {
    $fixture = hmrc_ct_orchestrator_fixture('LIVE');
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['readiness']->submitReady = false;
        $fixture['readiness']->submitBlockers = ['CT6 must receive final LIVE acceptance before CT7.'];
        $blocked = false;
        try {
            $fixture['orchestrator']->submit($id, 'user:42');
        } catch (DomainException $exception) {
            $blocked = str_contains($exception->getMessage(), 'CT6 must receive');
        }
        $harness->assertTrue($blocked);
        $harness->assertSame('ready', $fixture['repository']->fetchById($id)['protocol_state']);
        $harness->assertCount(0, $fixture['gateway']->calls());

        $fixture['readiness']->submitReady = true;
        $fixture['readiness']->submitBlockers = [];
        $fixture['orchestrator']->submit($id, 'user:42');
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
        $fixture['clock']->advance('+1 second');
        $accepted = $fixture['orchestrator']->poll($id, 'user:42');
        $harness->assertSame('live_accepted', $accepted['business_outcome']);
        $harness->assertSame('live_accepted', $fixture['statutory'][(string)$id]);
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'recovers an ambiguous submit without resubmission and retries failed deletion', static function () use ($harness): void {
    $gateway = new HmrcCtOrchestratorRecoveryGateway();
    $fixture = hmrc_ct_orchestrator_fixture('TEST', $gateway);
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $uncertain = $fixture['orchestrator']->submit($id, 'user:42');
        $harness->assertSame(false, $uncertain['success']);
        $harness->assertSame('transport_uncertain', $uncertain['protocol_state']);

        $resubmitBlocked = false;
        try {
            $fixture['orchestrator']->submit($id, 'user:42');
        } catch (DomainException $exception) {
            $resubmitBlocked = str_contains($exception->getMessage(), 'approved, ready');
        }
        $harness->assertTrue($resubmitBlocked);
        $harness->assertSame(['submit'], $gateway->calls);

        $fixture['clock']->advance('+60 seconds');
        $recovered = $fixture['orchestrator']->deleteResponse($id, 'user:42');
        $harness->assertSame('awaiting_poll', $recovered['protocol_state']);
        $fixture['orchestrator']->poll($id, 'user:42');
        $failedDelete = $fixture['orchestrator']->deleteResponse($id, 'user:42');
        $harness->assertSame(false, $failedDelete['success']);
        $harness->assertSame('delete_pending', $failedDelete['protocol_state']);
        $closed = $fixture['orchestrator']->deleteResponse($id, 'user:42');
        $harness->assertSame('closed', $closed['protocol_state']);
        $harness->assertSame(['submit', 'data_request', 'poll', 'delete', 'delete'], $gateway->calls);
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'never accepts a final response with mismatched persisted identifiers', static function () use ($harness): void {
    $gateway = new FakeHmrcCtGatewayClient([
        'poll' => [[
            'success' => true,
            'protocol_state' => 'final_response',
            'business_outcome' => 'accepted',
            'transaction_id' => 'DEADBEEF',
            'correlation_id' => str_repeat('A', 32),
            'response_endpoint' => 'https://transaction-engine.tax.service.gov.uk/poll',
            'cleanup_required' => true,
        ]],
    ]);
    $fixture = hmrc_ct_orchestrator_fixture('LIVE', $gateway);
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['orchestrator']->submit($id, 'user:42');
        $fixture['clock']->advance('+1 second');
        $result = $fixture['orchestrator']->poll($id, 'user:42');
        $harness->assertSame(false, $result['success']);
        $harness->assertSame('transport_uncertain', $result['protocol_state']);
        $harness->assertSame('none', $result['business_outcome']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'continues polling after a Transaction Engine submission error', static function () use ($harness): void {
    $gateway = new FakeHmrcCtGatewayClient([
        'poll' => [[
            'success' => false,
            'protocol_state' => 'submission_error',
            'business_outcome' => null,
            'errors' => [[
                'raised_by' => 'Gateway',
                'number' => '1000',
                'type' => 'fatal',
                'texts' => ['Temporary Transaction Engine failure.'],
                'locations' => [],
            ]],
            'error' => '1000: Temporary Transaction Engine failure.',
        ]],
    ]);
    $fixture = hmrc_ct_orchestrator_fixture('LIVE', $gateway);
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['orchestrator']->submit($id, 'user:42');
        $fixture['clock']->advance('+1 second');
        $result = $fixture['orchestrator']->poll($id, 'user:42');

        $harness->assertSame(false, $result['success']);
        $harness->assertSame('awaiting_poll', $result['protocol_state']);
        $harness->assertSame('none', $result['business_outcome']);
        $harness->assertSame(1, (int)$fixture['repository']->fetchById($id)['poll_attempts']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'never accepts accepted-shaped XML without a successful 2xx result', static function () use ($harness): void {
    $gateway = new FakeHmrcCtGatewayClient([
        'poll' => [[
            'success' => false,
            'status_code' => 500,
            'protocol_state' => 'final_response',
            'business_outcome' => 'accepted',
            'cleanup_required' => true,
            'error' => 'HTTP 500',
        ]],
    ]);
    $fixture = hmrc_ct_orchestrator_fixture('LIVE', $gateway);
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['orchestrator']->submit($id, 'user:42');
        $fixture['clock']->advance('+1 second');
        $result = $fixture['orchestrator']->poll($id, 'user:42');

        $harness->assertSame(false, $result['success']);
        $harness->assertSame('awaiting_poll', $result['protocol_state']);
        $harness->assertSame('none', $result['business_outcome']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});

$harness->check(HmrcCtSubmissionOrchestrator::class, 'requires a returned TransactionID on a final response', static function () use ($harness): void {
    $gateway = new FakeHmrcCtGatewayClient([
        'poll' => [[
            'success' => true,
            'protocol_state' => 'final_response',
            'business_outcome' => 'accepted',
            'transaction_id' => '',
            'cleanup_required' => true,
        ]],
    ]);
    $fixture = hmrc_ct_orchestrator_fixture('LIVE', $gateway);
    try {
        $prepared = $fixture['orchestrator']->prepare(49, 79, 6, 'user:42', [
            'name' => 'Test Director',
            'status' => 'proper_officer',
            'confirmed' => true,
        ]);
        $id = (int)$prepared['submission_id'];
        hmrc_ct_orchestrator_approve($fixture['orchestrator'], $id);
        $fixture['orchestrator']->submit($id, 'user:42');
        $fixture['clock']->advance('+1 second');
        $result = $fixture['orchestrator']->poll($id, 'user:42');

        $harness->assertSame(false, $result['success']);
        $harness->assertSame('transport_uncertain', $result['protocol_state']);
        $harness->assertSame('none', $result['business_outcome']);
        $harness->assertCount(0, $fixture['statutory']->getArrayCopy());
    } finally {
        hmrc_ct_orchestrator_cleanup($fixture);
    }
});
