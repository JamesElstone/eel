<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Client\HmrcCtTransactionEngineClient;
use eel_accounts\Client\HmrcCtTransactionEngineTransportInterface;

/** Durable CT600 GovTalk submission workflow, one conversation per CT period. */
final class HmrcCorporationTaxSubmissionService
{
    private const SUBMISSIONS = 'hmrc_ct600_submissions';
    private const EVENTS = 'hmrc_submission_events';
    private const REQUIRED_COLUMNS = [
        'source_manifest_json',
        'source_manifest_sha256',
        'test_submission_id',
        'authority_confirmed',
        'authority_confirmed_at',
        'authority_confirmed_by',
        'cleanup_attempts',
    ];

    private HmrcCtTransactionEngineTransportInterface $transport;
    private HmrcSubmissionPackageService $packages;

    /** @var null|\Closure(): mixed */
    private ?\Closure $clock;

    /** @var null|\Closure(int,int,string,array): array */
    private ?\Closure $packagePreparer;

    /** @var null|\Closure(int,int): array */
    private ?\Closure $manifestResolver;

    private string $artifactRoot;
    private TransmissionArchiveService $archives;

    public function __construct(
        ?HmrcCtTransactionEngineTransportInterface $transport = null,
        ?HmrcSubmissionPackageService $packages = null,
        ?callable $clock = null,
        ?string $artifactRoot = null,
        ?callable $packagePreparer = null,
        ?callable $manifestResolver = null,
        ?TransmissionArchiveService $archiveService = null
    ) {
        $this->transport = $transport ?? new HmrcCtTransactionEngineClient();
        $this->packages = $packages ?? new HmrcSubmissionPackageService();
        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
        $this->packagePreparer = $packagePreparer === null
            ? null
            : \Closure::fromCallable($packagePreparer);
        $this->manifestResolver = $manifestResolver === null
            ? null
            : \Closure::fromCallable($manifestResolver);
        $this->artifactRoot = $this->resolveArtifactRoot($artifactRoot);
        $this->archives = $archiveService ?? new TransmissionArchiveService($this->artifactRoot);
    }

    /** @return array<string, mixed> */
    public function status(int $companyId, int $accountingPeriodId): array
    {
        $environments = [
            'TEST' => $this->transport->configurationStatus('TEST'),
            'TIL' => $this->transport->configurationStatus('TIL'),
            'LIVE' => $this->transport->configurationStatus('LIVE'),
        ];
        $base = [
            'success' => false,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'test_environment' => 'TIL',
            'live_environment' => 'LIVE',
            'environments' => $environments,
            'periods' => [],
            'errors' => [],
            'warnings' => [],
        ];
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            $base['errors'][] = 'Select a company and accounting period.';
            return $base;
        }
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            $base['errors'][] = $schemaError;
            return $base;
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT id, sequence_no, period_start, period_end, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status <> :superseded
             ORDER BY sequence_no, id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'superseded' => 'superseded',
            ]
        );
        foreach ($periods as $period) {
            $ctPeriodId = (int)$period['id'];
            $submissions = $this->fetchForCtPeriod($companyId, $ctPeriodId);
            $latestTest = $this->firstMode($submissions, 'TIL');
            $latestLive = $this->firstMode($submissions, 'LIVE');
            $pending = $this->firstPending($submissions);
            $manifest = $this->safeCurrentManifest($companyId, $ctPeriodId);
            $manifestHash = (string)($manifest['source_manifest_sha256'] ?? '');
            $bodyHash = (string)($manifest['body_sha256'] ?? '');

            $testBlockers = array_values(array_map(
                'strval',
                (array)($environments['TIL']['blockers'] ?? [])
            ));
            $liveBlockers = array_values(array_map(
                'strval',
                (array)($environments['LIVE']['blockers'] ?? [])
            ));
            if (empty($manifest['ok'])) {
                $errors = (array)($manifest['errors'] ?? ['The current filing source manifest is not ready.']);
                $testBlockers = array_merge($testBlockers, array_map('strval', $errors));
                $liveBlockers = array_merge($liveBlockers, array_map('strval', $errors));
            }
            if (is_array($pending)) {
                $message = (string)$pending['protocol_state'] === 'transport_uncertain'
                    ? 'The last transmission has an uncertain outcome and must not be resubmitted.'
                    : 'An HMRC conversation is already in progress for this CT period.';
                $testBlockers[] = $message;
                $liveBlockers[] = $message;
            }
            if ($this->matchesSuccessfulTest($latestTest, $manifestHash, $bodyHash)) {
                $testBlockers[] = 'This exact filing body has already passed HMRC Test in Live.';
            }
            $testGate = $this->successfulTestForHashes($companyId, $ctPeriodId, $manifestHash, $bodyHash);
            if (!is_array($testGate)) {
                $liveBlockers[] = 'The exact current filing body must pass HMRC Test in Live before LIVE filing.';
            }
            if (is_array($latestLive) && (string)$latestLive['business_outcome'] === 'live_accepted') {
                $liveBlockers[] = 'HMRC has already accepted a LIVE return for this CT period.';
            }

            $row = [
                'ct_period_id' => $ctPeriodId,
                'sequence_no' => (int)$period['sequence_no'],
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
                'ct_period_status' => (string)$period['status'],
                'test_environment' => 'TIL',
                'live_environment' => 'LIVE',
                'current_manifest_sha256' => $manifestHash,
                'current_body_sha256' => $bodyHash,
                'latest_test' => $latestTest,
                'latest_live' => $latestLive,
                'latest_submission' => $submissions[0] ?? null,
                'pending_submission' => $pending,
                'declaration' => [
                    'declaration_name' => (string)($latestTest['declarant_name'] ?? ''),
                    'declaration_status' => (string)($latestTest['declarant_status'] ?? ''),
                    'declaration_confirmed' => false,
                    'supplementary_scope_confirmed' => false,
                    'original_unfiled_confirmed' => false,
                    'authority_confirmed' => false,
                ],
                'test_ready' => $testBlockers === [],
                'live_ready' => $liveBlockers === [],
                'test_blockers' => array_values(array_unique($testBlockers)),
                'live_blockers' => array_values(array_unique($liveBlockers)),
                'blockers' => array_values(array_unique(array_merge($testBlockers, $liveBlockers))),
            ];
            $base['periods'][] = $row;
        }

        $base['success'] = true;
        return $base;
    }

    /** Test means HMRC Test in Live (TIL), never ETS, for this user workflow. */
    public function submitTest(
        int $companyId,
        int $ctPeriodId,
        int|string|null $actor = null,
        array $declaration = []
    ): array {
        return $this->submitMode($companyId, $ctPeriodId, 'TIL', $actor, $declaration);
    }

    public function submitLive(
        int $companyId,
        int $ctPeriodId,
        int|string|null $actor = null,
        array $declaration = []
    ): array {
        return $this->submitMode($companyId, $ctPeriodId, 'LIVE', $actor, $declaration);
    }

    public function poll(int $submissionId, int|string|null $actor = null): array
    {
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            return $this->failure($schemaError);
        }
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            return $this->failure('The HMRC submission does not exist.');
        }
        $state = (string)$submission['protocol_state'];
        if ($state === 'transport_uncertain') {
            return $this->failure(
                'The transmission outcome is uncertain. Do not resubmit or poll it as a normal acknowledgement.',
                $submissionId,
                $submission
            );
        }
        if ($state === 'delete_pending') {
            return $this->cleanup($submission, $actor);
        }
        if ($state !== 'awaiting_poll') {
            return $this->failure('This HMRC submission is not awaiting a poll.', $submissionId, $submission);
        }

        $now = $this->now();
        $nextPoll = trim((string)($submission['next_poll_at'] ?? ''));
        if ($nextPoll !== '') {
            $due = new \DateTimeImmutable($nextPoll, new \DateTimeZone('UTC'));
            if ($now < $due) {
                $seconds = max(1, $due->getTimestamp() - $now->getTimestamp());
                $result = $this->failure(
                    'HMRC requested that polling wait for ' . $seconds . ' more seconds.',
                    $submissionId,
                    $submission
                );
                $result['needs_poll'] = true;
                $result['poll_after_seconds'] = $seconds;
                return $result;
            }
        }

        $previousAttempt = (int)$submission['poll_attempts'];
        $attempt = $previousAttempt + 1;
        $capturedResponse = null;
        $result = $this->transport->poll(
            (string)$submission['hmrc_correlation_id'],
            (string)$submission['response_endpoint'],
            (string)$submission['environment'],
            null,
            function (array $request) use ($submissionId, $previousAttempt, $attempt, $actor): void {
                $statement = \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET poll_attempts = :attempt,
                         transaction_id = :transaction_id,
                         submitted_by = :actor,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND protocol_state = :state
                       AND poll_attempts = :previous_attempt',
                    [
                        'attempt' => $attempt,
                        'transaction_id' => (string)$request['transaction_id'],
                        'actor' => $this->actor($actor),
                        'updated_at' => $this->sqlNow(),
                        'id' => $submissionId,
                        'state' => 'awaiting_poll',
                        'previous_attempt' => $previousAttempt,
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The HMRC conversation changed before polling; no poll request was sent.'
                    );
                }
                $artifact = $this->storeArtifact(
                    $submissionId,
                    sprintf('poll-%04d-request.xml', $attempt),
                    (string)$request['raw_request_xml']
                );
                $this->event($submissionId, 'info', 'HMRC poll request persisted before transmission.', [
                    'attempt' => $attempt,
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                    'request_bytes' => (int)$request['request_bytes'],
                ]);
            },
            function (array $response) use ($submissionId, $attempt, &$capturedResponse): void {
                $capturedResponse = $this->storeArtifact(
                    $submissionId,
                    sprintf('poll-%04d-response.xml', $attempt),
                    (string)$response['response_xml']
                );
            }
        );
        $result['archived_response'] = $capturedResponse;

        return $this->applyConversationResult($submissionId, $result, $actor, true);
    }

    private function submitMode(
        int $companyId,
        int $ctPeriodId,
        string $mode,
        int|string|null $actor,
        array $declaration
    ): array {
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            return $this->failure($schemaError);
        }
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company and CT period.');
        }
        $ctPeriod = \InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id
             FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id
             LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($ctPeriod)) {
            return $this->failure('The selected CT period does not belong to this company.');
        }
        $mode = strtoupper(trim($mode));
        $configuration = $this->transport->configurationStatus($mode);
        if (empty($configuration['ready'])) {
            return $this->failure((string)(($configuration['blockers'] ?? [])[0]
                ?? 'HMRC Transaction Engine credentials are not configured.'));
        }

        $declaration = $this->normaliseDeclaration($declaration);
        $declarationErrors = $this->declarationErrors($declaration);
        if ($declarationErrors !== []) {
            return $this->failure($declarationErrors);
        }
        $pending = $this->firstPendingSubmissionForPeriod($companyId, $ctPeriodId);
        if (is_array($pending)) {
            $message = (string)$pending['protocol_state'] === 'transport_uncertain'
                ? 'A prior transmission has an uncertain outcome. Do not submit another return for this CT period.'
                : 'An HMRC conversation is already in progress for this CT period.';
            return $this->failure($message, (int)$pending['id'], $pending);
        }
        if ($mode === 'LIVE') {
            $acceptedLive = $this->acceptedLiveSubmissionForPeriod($companyId, $ctPeriodId);
            if (is_array($acceptedLive)) {
                return $this->failure(
                    'HMRC has already accepted the original LIVE return for this CT period.',
                    (int)$acceptedLive['id'],
                    $acceptedLive
                );
            }
        }

        try {
            $package = $this->packagePreparer instanceof \Closure
                ? ($this->packagePreparer)($companyId, $ctPeriodId, $mode, $declaration)
                : $this->packages->prepareForSubmission(
                    $companyId,
                    $ctPeriodId,
                    $mode,
                    $declaration
                );
        } catch (\Throwable $exception) {
            return $this->failure('The CT600 package could not be prepared: ' . $exception->getMessage());
        }
        $package = $this->normalisePackage($package, $companyId, $ctPeriodId);
        if (empty($package['ok'])) {
            return $this->failure((array)($package['errors'] ?? ['The CT600 package is not ready.']));
        }
        if (
            (int)$package['company_id'] !== $companyId
            || (int)$package['ct_period_id'] !== $ctPeriodId
            || (int)$package['accounting_period_id'] !== (int)$ctPeriod['accounting_period_id']
        ) {
            return $this->failure('The prepared CT600 package identity does not match the selected CT period.');
        }
        try {
            $evidenceBundle = (new FilingEvidenceService())->ensureCurrentBundle(
                $companyId,
                (int)$package['accounting_period_id'],
                $this->actor($actor)
            );
            $package['evidence_bundle_id'] = (int)$evidenceBundle['id'];
            $package['evidence_id'] = (string)$evidenceBundle['evidence_id'];
            $package['source_manifest']['filing_evidence_id'] = (string)$evidenceBundle['evidence_id'];
            $manifestJson = $this->canonicalJson((array)$package['source_manifest']);
            $package['source_manifest_sha256'] = hash('sha256', $manifestJson);
            $package['package_hash'] = hash('sha256', implode('|', [
                HmrcSubmissionPackageService::PACKAGE_VERSION,
                $mode,
                (string)$package['source_manifest_sha256'],
                (string)$package['body_sha256'],
            ]));
        } catch (\Throwable $exception) {
            return $this->failure('Current filing evidence is required: ' . $exception->getMessage());
        }

        $manifestHash = (string)$package['source_manifest_sha256'];
        $bodyHash = (string)$package['body_sha256'];
        $testSubmission = null;
        if ($mode === 'LIVE') {
            $testSubmission = $this->successfulTestForHashes(
                $companyId,
                $ctPeriodId,
                $manifestHash,
                $bodyHash
            );
            if (!is_array($testSubmission)) {
                return $this->failure(
                    'The exact current filing body must pass HMRC Test in Live before LIVE filing.'
                );
            }
        }

        $idempotencyKey = hash('sha256', implode('|', [
            'ct600-govtalk-v1',
            $mode,
            (string)$companyId,
            (string)$ctPeriodId,
            $manifestHash,
            $bodyHash,
        ]));
        $existing = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE idempotency_key = :key LIMIT 1',
            ['key' => $idempotencyKey]
        );
        if (is_array($existing)) {
            return $this->existingResult($this->normaliseSubmission($existing));
        }

        try {
            $submissionId = $this->createPreparedSubmission(
                $package,
                $mode,
                $idempotencyKey,
                $actor,
                $declaration,
                is_array($testSubmission) ? (int)$testSubmission['id'] : null
            );
        } catch (\Throwable) {
            // A concurrent worker may have committed the same immutable basis
            // while this worker was freezing its artifacts.
            $existing = \InterfaceDB::fetchOne(
                'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE idempotency_key = :key LIMIT 1',
                ['key' => $idempotencyKey]
            );
            if (is_array($existing)) {
                return $this->existingResult($this->normaliseSubmission($existing));
            }

            $pending = $this->firstPendingSubmissionForPeriod($companyId, $ctPeriodId);
            if (is_array($pending)) {
                $message = (string)$pending['protocol_state'] === 'transport_uncertain'
                    ? 'A prior transmission has an uncertain outcome. Do not submit another return for this CT period.'
                    : 'An HMRC conversation is already in progress for this CT period.';
                return $this->failure($message, (int)$pending['id'], $pending);
            }
            if ($mode === 'LIVE') {
                $acceptedLive = $this->acceptedLiveSubmissionForPeriod($companyId, $ctPeriodId);
                if (is_array($acceptedLive)) {
                    return $this->failure(
                        'HMRC has already accepted the original LIVE return for this CT period.',
                        (int)$acceptedLive['id'],
                        $acceptedLive
                    );
                }
            }

            return $this->failure(
                'The immutable CT600 submission package could not be persisted; no HMRC request was sent.'
            );
        }

        $govtalkEvidence = (new FilingEvidenceService())->reserveArtifact(
            $companyId,
            (int)$package['accounting_period_id'],
            'hmrc_govtalk_submit_request',
            $ctPeriodId,
            ['submission_id' => $submissionId]
        );
        $capturedResponse = null;
        $result = $this->transport->submit(
            (string)$package['filing_body_xml'],
            (string)$package['utr'],
            $mode,
            (string)$govtalkEvidence['transaction_hex'],
            function (array $request) use (
                $submissionId,
                $companyId,
                $ctPeriodId,
                $manifestHash,
                $bodyHash,
                $actor,
                $govtalkEvidence
            ): void {
                // Close the small prepare/send race: the approved source basis
                // must still be byte-identical at the pre-send boundary.
                $current = $this->safeCurrentManifest($companyId, $ctPeriodId);
                if (
                    empty($current['ok'])
                    || !hash_equals($manifestHash, (string)($current['source_manifest_sha256'] ?? ''))
                    || !hash_equals($bodyHash, (string)($current['body_sha256'] ?? ''))
                ) {
                    throw new \RuntimeException(
                        'The filing source changed after preparation; no HMRC request was sent.'
                    );
                }
                $artifact = $this->storeArtifact(
                    $submissionId,
                    'submission-request.xml',
                    (string)$request['raw_request_xml']
                );
                $statement = \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET status = :status,
                         protocol_state = :protocol_state,
                         transaction_id = :transaction_id,
                         request_body_path = :request_path,
                         request_headers_json = :request_metadata,
                         submitted_by = :submitted_by,
                         submitted_by_user_id = :submitted_by_user_id,
                         submitted_at = :submitted_at,
                         updated_at = :updated_at
                     WHERE id = :id AND protocol_state = :expected_state',
                    [
                        'status' => 'submitting',
                        'protocol_state' => 'submitting',
                        'transaction_id' => (string)$request['transaction_id'],
                        'request_path' => $artifact['path'],
                        'request_metadata' => $this->json([
                            'content_type' => 'text/xml; charset=UTF-8',
                            'request_sha256' => (string)$request['request_sha256'],
                            'request_bytes' => (int)$request['request_bytes'],
                            'persisted_exact_sha256' => $artifact['sha256'],
                        ]),
                        'submitted_by' => $this->actor($actor),
                        'submitted_by_user_id' => $this->actorUserId($actor),
                        'submitted_at' => $this->sqlNow(),
                        'updated_at' => $this->sqlNow(),
                        'id' => $submissionId,
                        'expected_state' => 'ready',
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The HMRC submission changed before transmission; no request was sent.'
                    );
                }
                $this->event($submissionId, 'info', 'GovTalk request persisted before transmission.', [
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                    'request_bytes' => (int)$request['request_bytes'],
                ]);
                (new FilingEvidenceService())->completeArtifact((int)$govtalkEvidence['id'], [
                    'status' => 'generated',
                    'filename' => 'submission-request.xml',
                    'path' => $artifact['path'],
                    'sha256' => (string)$request['request_sha256'],
                    'schema_identity' => 'GovTalk Document Submission Protocol 2.0 / CT/5',
                    'validation_status' => 'passed',
                    'identifier_embedded' => true,
                    'metadata' => ['submission_id' => $submissionId, 'persisted_exact_sha256' => $artifact['sha256']],
                ]);
            },
            function (array $response) use ($submissionId, &$capturedResponse): void {
                $capturedResponse = $this->storeArtifact(
                    $submissionId,
                    'submission-response.xml',
                    (string)$response['response_xml']
                );
            }
        );
        $result['archived_response'] = $capturedResponse;

        return $this->applyConversationResult($submissionId, $result, $actor, false);
    }

    private function createPreparedSubmission(
        array $package,
        string $mode,
        string $idempotencyKey,
        int|string|null $actor,
        array $declaration,
        ?int $testSubmissionId
    ): int {
        return \InterfaceDB::transaction(function () use (
            $package,
            $mode,
            $idempotencyKey,
            $actor,
            $declaration,
            $testSubmissionId
        ): int {
            $lockSuffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
            $ctPeriod = \InterfaceDB::fetchOne(
                'SELECT id FROM corporation_tax_periods
                 WHERE id = :ct_period_id AND company_id = :company_id' . $lockSuffix,
                [
                    'ct_period_id' => (int)$package['ct_period_id'],
                    'company_id' => (int)$package['company_id'],
                ]
            );
            if (!is_array($ctPeriod)) {
                throw new \RuntimeException('The selected CT period is no longer available.');
            }

            // The CT-period row is the per-period conversation mutex. Recheck
            // while it is locked so two distinct declaration bodies cannot
            // reserve and transmit concurrent original returns.
            $pending = $this->firstPendingSubmissionForPeriod(
                (int)$package['company_id'],
                (int)$package['ct_period_id']
            );
            if (is_array($pending)) {
                throw new \RuntimeException('An HMRC conversation is already in progress for this CT period.');
            }
            if ($mode === 'LIVE' && is_array($this->acceptedLiveSubmissionForPeriod(
                (int)$package['company_id'],
                (int)$package['ct_period_id']
            ))) {
                throw new \RuntimeException('HMRC has already accepted the original LIVE return for this CT period.');
            }

            $manifestJson = $this->canonicalJson((array)$package['source_manifest']);
            $now = $this->sqlNow();
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::SUBMISSIONS . ' (
                    evidence_bundle_id, company_id, accounting_period_id, ct_period_id, mode, environment,
                    status, protocol_state, business_outcome, submission_type,
                    accounts_ixbrl_path, accounts_run_id, accounts_sha256,
                    computations_ixbrl_path, computation_run_id, computations_sha256,
                    year_end_locked_at, package_hash, idempotency_key, irmark,
                    schema_version, body_sha256, ct600_sha256, validation_json,
                    source_manifest_json, source_manifest_sha256, test_submission_id,
                    declarant_name, declarant_status, declaration_confirmed,
                    authority_confirmed, authority_confirmed_at, authority_confirmed_by,
                    supplementary_scope_confirmed, original_unfiled_confirmed,
                    declaration_approved_at, declaration_approved_by,
                    approved_package_hash, prepared_by, created_at, updated_at
                 ) VALUES (
                    :evidence_bundle_id, :company_id, :accounting_period_id, :ct_period_id, :mode, :environment,
                    :status, :protocol_state, :business_outcome, :submission_type,
                    :accounts_path, :accounts_run_id, :accounts_sha256,
                    :computations_path, :computation_run_id, :computations_sha256,
                    :year_end_locked_at, :package_hash, :idempotency_key, :irmark,
                    :schema_version, :body_sha256, :ct600_sha256, :validation_json,
                    :source_manifest_json, :source_manifest_sha256, :test_submission_id,
                    :declarant_name, :declarant_status, :declaration_confirmed,
                    :authority_confirmed, :authority_confirmed_at, :authority_confirmed_by,
                    :supplementary_scope_confirmed, :original_unfiled_confirmed,
                    :declaration_approved_at, :declaration_approved_by,
                    :approved_package_hash, :prepared_by, :created_at, :updated_at
                 )',
                [
                    'evidence_bundle_id' => (int)$package['evidence_bundle_id'],
                    'company_id' => (int)$package['company_id'],
                    'accounting_period_id' => (int)$package['accounting_period_id'],
                    'ct_period_id' => (int)$package['ct_period_id'],
                    'mode' => $mode,
                    'environment' => $mode,
                    'status' => 'ready',
                    'protocol_state' => 'ready',
                    'business_outcome' => 'none',
                    'submission_type' => 'original',
                    'accounts_path' => $package['accounts_ixbrl_path'],
                    'accounts_run_id' => $package['accounts_run_id'],
                    'accounts_sha256' => $package['accounts_sha256'],
                    'computations_path' => $package['computations_ixbrl_path'],
                    'computation_run_id' => $package['computation_run_id'],
                    'computations_sha256' => $package['computations_sha256'],
                    'year_end_locked_at' => $package['year_end_locked_at'],
                    'package_hash' => $package['package_hash'],
                    'idempotency_key' => $idempotencyKey,
                    'irmark' => $package['irmark'],
                    'schema_version' => $package['schema_version'],
                    'body_sha256' => $package['body_sha256'],
                    'ct600_sha256' => $package['body_sha256'],
                    'validation_json' => $this->json($package['validation']),
                    'source_manifest_json' => $manifestJson,
                    'source_manifest_sha256' => $package['source_manifest_sha256'],
                    'test_submission_id' => $testSubmissionId,
                    'declarant_name' => $declaration['declaration_name'],
                    'declarant_status' => $declaration['declaration_status'],
                    'declaration_confirmed' => $declaration['declaration_confirmed'] ? 1 : 0,
                    'authority_confirmed' => $declaration['authority_confirmed'] ? 1 : 0,
                    'authority_confirmed_at' => $now,
                    'authority_confirmed_by' => $this->actor($actor),
                    'supplementary_scope_confirmed' => $declaration['supplementary_scope_confirmed'] ? 1 : 0,
                    'original_unfiled_confirmed' => $declaration['original_unfiled_confirmed'] ? 1 : 0,
                    'declaration_approved_at' => $mode === 'LIVE' ? $now : null,
                    'declaration_approved_by' => $mode === 'LIVE' ? $this->actor($actor) : null,
                    'approved_package_hash' => $mode === 'LIVE' ? $package['package_hash'] : null,
                    'prepared_by' => $this->actor($actor),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $submissionId = (int)\InterfaceDB::fetchColumn(
                strtolower((string)\InterfaceDB::driverName()) === 'sqlite'
                    ? 'SELECT last_insert_rowid()'
                    : 'SELECT LAST_INSERT_ID()'
            );
            $bodyArtifact = $this->storeArtifact(
                $submissionId,
                'filing-body.xml',
                (string)$package['filing_body_xml']
            );
            $manifestArtifact = $this->storeArtifact(
                $submissionId,
                'source-manifest.json',
                $manifestJson . "\n"
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS . '
                 SET ct600_xml_path = :body_path, manifest_path = :manifest_path, updated_at = :updated_at
                 WHERE id = :id',
                [
                    'body_path' => $bodyArtifact['path'],
                    'manifest_path' => $manifestArtifact['path'],
                    'updated_at' => $this->sqlNow(),
                    'id' => $submissionId,
                ]
            );
            $this->event($submissionId, 'info', 'CT600 package prepared and frozen for HMRC transmission.', [
                'environment' => $mode,
                'source_manifest_sha256' => (string)$package['source_manifest_sha256'],
                'body_sha256' => (string)$package['body_sha256'],
            ]);
            $evidence = new FilingEvidenceService();
            foreach ([
                ['hmrc_ct600_body', $bodyArtifact, (string)$package['body_sha256']],
                ['hmrc_source_manifest', $manifestArtifact, hash('sha256', $manifestJson . "\n")],
            ] as [$role, $storedArtifact, $sha]) {
                $reserved = $evidence->reserveArtifact(
                    (int)$package['company_id'],
                    (int)$package['accounting_period_id'],
                    (string)$role,
                    (int)$package['ct_period_id'],
                    ['submission_id' => $submissionId]
                );
                $evidence->completeArtifact((int)$reserved['id'], [
                    'status' => 'generated',
                    'filename' => basename((string)$storedArtifact['path']),
                    'path' => (string)$storedArtifact['path'],
                    'sha256' => (string)$sha,
                    'schema_identity' => $role === 'hmrc_ct600_body' ? (string)$package['schema_version'] : 'EEL canonical source manifest',
                    'validation_status' => 'passed',
                    'identifier_embedded' => false,
                    'metadata' => ['submission_id' => $submissionId, 'evidence_id' => (string)($package['evidence_id'] ?? '')],
                ]);
            }
            $evidence->recordEvent((int)$package['evidence_bundle_id'], 'hmrc_prepared', 'success', $this->actor($actor),
                'An immutable HMRC Corporation Tax package was prepared.', ['submission_id' => $submissionId, 'environment' => $mode]);

            return $submissionId;
        });
    }

    private function applyConversationResult(
        int $submissionId,
        array $result,
        int|string|null $actor,
        bool $wasPoll
    ): array {
        $responseArtifact = is_array($result['archived_response'] ?? null)
            ? $result['archived_response']
            : null;
        if ($responseArtifact === null && trim((string)($result['response_xml'] ?? '')) !== '') {
            $current = $this->fetchById($submissionId);
            $name = $wasPoll
                ? sprintf('poll-%04d-response-redacted.xml', max(1, (int)($current['poll_attempts'] ?? 1)))
                : 'submission-response-redacted.xml';
            $responseArtifact = $this->storeArtifact($submissionId, $name, (string)$result['response_xml']);
        }

        if (!empty($result['pre_send_failure'])) {
            $message = trim((string)($result['error'] ?? 'Pre-send persistence failed.'));
            $current = $this->fetchById($submissionId);
            if ($wasPoll) {
                // A local poll-persistence failure cannot terminate the
                // already-open HMRC conversation. Leave it available to retry.
                if ((string)($current['protocol_state'] ?? '') === 'awaiting_poll') {
                    $this->event(
                        $submissionId,
                        'warning',
                        'HMRC poll was not transmitted because its request evidence could not be persisted.',
                        ['error' => $message]
                    );
                }
                $interval = max(1, (int)($current['poll_interval_seconds'] ?? 10));
                return $this->commandResult(
                    $submissionId,
                    in_array((string)($current['business_outcome'] ?? ''), [
                        'sandbox_passed', 'til_validated', 'live_accepted',
                    ], true),
                    [$message],
                    false,
                    $interval
                );
            }
            if (in_array((string)($current['protocol_state'] ?? ''), ['ready', 'submitting'], true)) {
                $this->updateFailure($submissionId, 'validation_failed', $message);
            }
            return $this->commandResult($submissionId, false, [$message]);
        }
        if (!empty($result['transport_unknown'])) {
            $this->updateSubmission($submissionId, [
                'status' => 'failed',
                'protocol_state' => 'transport_uncertain',
                'business_outcome' => 'error',
                'hmrc_response_code' => (int)($result['status_code'] ?? 0) ?: null,
                'hmrc_response_summary' => (string)($result['error'] ?? 'Transport outcome is uncertain.'),
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
            ]);
            $this->event($submissionId, 'error', 'HMRC submission transport outcome is uncertain; automatic retry is blocked.', [
                'error' => (string)($result['error'] ?? ''),
            ]);
            $this->recordEvidenceOutcome($submissionId, 'hmrc_transport_uncertain', 'error', $actor, [
                'error' => (string)($result['error'] ?? ''),
            ]);
            return $this->commandResult(
                $submissionId,
                false,
                ['HMRC may have received the return, but no definitive response was received. Do not resubmit blindly.']
            );
        }

        $protocol = (string)($result['protocol_state'] ?? 'failed');
        if ($protocol === 'acknowledged') {
            $interval = max(1, (int)($result['poll_interval'] ?? 10));
            $nextPoll = $this->now()->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s');
            $this->updateSubmission($submissionId, [
                'status' => 'submitting',
                'protocol_state' => 'awaiting_poll',
                'hmrc_correlation_id' => (string)$result['correlation_id'],
                'response_endpoint' => (string)$result['response_endpoint'],
                'poll_interval_seconds' => $interval,
                'next_poll_at' => $nextPoll,
                'hmrc_response_code' => (int)($result['status_code'] ?? 0) ?: null,
                'hmrc_response_summary' => 'HMRC acknowledged the submission; polling is required.',
                'response_headers_json' => $this->json((array)($result['headers'] ?? [])),
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
            ]);
            $this->event($submissionId, 'info', 'HMRC acknowledged the CT600 submission.', [
                'correlation_id' => (string)$result['correlation_id'],
                'poll_interval_seconds' => $interval,
            ]);
            $this->recordEvidenceOutcome($submissionId, 'hmrc_acknowledged', 'info', $actor, [
                'correlation_id' => (string)$result['correlation_id'],
            ]);
            return $this->commandResult($submissionId, true, [], true, $interval);
        }

        $business = (string)($result['business_outcome'] ?? '');
        if ($protocol === 'final_response' || $business === 'rejected') {
            $accepted = $business === 'accepted';
            $submission = $this->fetchById($submissionId);
            $environment = (string)($submission['environment'] ?? '');
            $outcome = $accepted
                ? match ($environment) {
                    'TEST' => 'sandbox_passed',
                    'TIL' => 'til_validated',
                    'LIVE' => 'live_accepted',
                    default => 'error',
                }
                : 'rejected';
            $this->updateSubmission($submissionId, [
                'status' => $accepted ? 'accepted' : 'rejected',
                'protocol_state' => !empty($result['cleanup_required']) ? 'delete_pending' : 'closed',
                'business_outcome' => $outcome,
                'hmrc_correlation_id' => (string)($result['correlation_id'] ?? ''),
                'response_endpoint' => (string)($result['response_endpoint'] ?? ''),
                'hmrc_submission_reference' => $this->submissionReference((string)($result['body_xml'] ?? '')),
                'hmrc_response_code' => (int)($result['status_code'] ?? 0) ?: null,
                'hmrc_response_summary' => $accepted
                    ? 'HMRC accepted the CT600 filing body.'
                    : (string)($result['error'] ?? 'HMRC rejected the CT600 filing body.'),
                'response_headers_json' => $this->json((array)($result['headers'] ?? [])),
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
                'final_response_at' => $this->sqlNow(),
                'next_poll_at' => null,
            ]);
            $this->event(
                $submissionId,
                $accepted ? 'success' : 'error',
                $accepted ? 'HMRC returned a final acceptance.' : 'HMRC returned a final rejection.',
                ['errors' => (array)($result['errors'] ?? [])]
            );
            $this->recordEvidenceOutcome(
                $submissionId,
                $accepted ? 'hmrc_accepted' : 'hmrc_rejected',
                $accepted ? 'success' : 'error',
                $actor,
                ['environment' => $environment, 'errors' => (array)($result['errors'] ?? [])]
            );
            $updated = $this->fetchById($submissionId);
            if (!empty($result['cleanup_required']) && is_array($updated)) {
                $cleanup = $this->cleanup($updated, $actor);
                // Acceptance/rejection remains the business result even if
                // protocol cleanup needs a later retry.
                $cleanup['success'] = $accepted;
                if ($accepted && (array)($cleanup['errors'] ?? []) !== []) {
                    $cleanup['warnings'] = array_values(array_unique(array_merge(
                        (array)($cleanup['warnings'] ?? []),
                        (array)$cleanup['errors']
                    )));
                    $cleanup['errors'] = [];
                } elseif (!$accepted) {
                    $cleanup['errors'] = $this->transportErrors($result);
                }
                return $cleanup;
            }

            return $this->commandResult(
                $submissionId,
                $accepted,
                $accepted ? [] : $this->transportErrors($result)
            );
        }

        $message = trim((string)($result['error'] ?? 'HMRC Transaction Engine rejected the request.'));
        if ($wasPoll) {
            $interval = max(1, (int)($this->fetchById($submissionId)['poll_interval_seconds'] ?? 10));
            $this->updateSubmission($submissionId, [
                'protocol_state' => 'awaiting_poll',
                'next_poll_at' => $this->now()->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s'),
                'hmrc_response_summary' => $message,
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
            ]);
            $this->event($submissionId, 'warning', 'HMRC poll did not yield a final response; the conversation remains open.', [
                'error' => $message,
            ]);
            return $this->commandResult($submissionId, false, [$message], true, $interval);
        }

        $this->updateFailure($submissionId, 'validation_failed', $message);
        return $this->commandResult($submissionId, false, [$message]);
    }

    private function cleanup(array $submission, int|string|null $actor): array
    {
        $submissionId = (int)$submission['id'];
        $correlationId = trim((string)($submission['hmrc_correlation_id'] ?? ''));
        if ($correlationId === '') {
            $this->updateSubmission($submissionId, [
                'protocol_state' => 'closed',
                'cleanup_completed_at' => $this->sqlNow(),
            ]);
            return $this->commandResult($submissionId, true);
        }

        $previousAttempt = (int)($submission['cleanup_attempts'] ?? 0);
        $attempt = $previousAttempt + 1;
        $capturedResponse = null;
        $result = $this->transport->delete(
            $correlationId,
            (string)($submission['response_endpoint'] ?? ''),
            (string)$submission['environment'],
            null,
            function (array $request) use ($submissionId, $previousAttempt, $attempt, $actor): void {
                $statement = \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET cleanup_attempts = :attempt,
                         transaction_id = :transaction_id,
                         submitted_by = :submitted_by,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND protocol_state = :protocol_state
                       AND cleanup_attempts = :previous_attempt',
                    [
                        'attempt' => $attempt,
                        'transaction_id' => (string)$request['transaction_id'],
                        'submitted_by' => $this->actor($actor),
                        'updated_at' => $this->sqlNow(),
                        'id' => $submissionId,
                        'protocol_state' => 'delete_pending',
                        'previous_attempt' => $previousAttempt,
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The HMRC conversation changed before cleanup; no delete request was sent.'
                    );
                }
                $artifact = $this->storeArtifact(
                    $submissionId,
                    sprintf('delete-%04d-request.xml', $attempt),
                    (string)$request['raw_request_xml']
                );
                $this->event($submissionId, 'info', 'HMRC delete request persisted before transmission.', [
                    'attempt' => $attempt,
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                ]);
            },
            function (array $response) use ($submissionId, $attempt, &$capturedResponse): void {
                $capturedResponse = $this->storeArtifact(
                    $submissionId,
                    sprintf('delete-%04d-response.xml', $attempt),
                    (string)$response['response_xml']
                );
            }
        );

        if (!empty($result['pre_send_failure'])) {
            $message = trim((string)($result['error'] ?? 'HMRC cleanup request evidence could not be persisted.'));
            $current = $this->fetchById($submissionId);
            if ((string)($current['protocol_state'] ?? '') === 'delete_pending') {
                $this->updateSubmission($submissionId, ['cleanup_error' => $message]);
                $this->event($submissionId, 'warning', 'HMRC cleanup was not transmitted.', [
                    'attempt' => $attempt,
                    'error' => $message,
                ]);
            }
            return $this->commandResult($submissionId, false, [$message]);
        }

        $responseArtifact = $capturedResponse;
        if ($responseArtifact === null && trim((string)($result['response_xml'] ?? '')) !== '') {
            $responseArtifact = $this->storeArtifact(
                $submissionId,
                sprintf('delete-%04d-response-redacted.xml', $attempt),
                (string)$result['response_xml']
            );
        }
        if (!empty($result['success']) && (string)($result['protocol_state'] ?? '') === 'deleted') {
            $this->updateSubmission($submissionId, [
                'protocol_state' => 'closed',
                'cleanup_completed_at' => $this->sqlNow(),
                'cleanup_response_path' => $responseArtifact['path'] ?? null,
                'cleanup_response_sha256' => $responseArtifact['sha256'] ?? null,
                'cleanup_error' => null,
            ]);
            $this->event($submissionId, 'success', 'HMRC Transaction Engine conversation was deleted.');
            return $this->commandResult($submissionId, true);
        }

        $message = trim((string)($result['error'] ?? 'HMRC conversation cleanup failed.'));
        $this->updateSubmission($submissionId, [
            'protocol_state' => 'delete_pending',
            'cleanup_response_path' => $responseArtifact['path'] ?? null,
            'cleanup_response_sha256' => $responseArtifact['sha256'] ?? null,
            'cleanup_error' => $message,
        ]);
        $this->event($submissionId, 'warning', 'HMRC final result is recorded, but conversation cleanup must be retried.', [
            'error' => $message,
        ]);
        return $this->commandResult($submissionId, false, [$message]);
    }

    private function normalisePackage(array $package, int $companyId, int $ctPeriodId): array
    {
        if (empty($package['ok'])) {
            return $package + ['ok' => false, 'errors' => ['The CT600 package is not ready.']];
        }
        $body = (string)($package['filing_body_xml'] ?? $package['body'] ?? $package['xml'] ?? '');
        $manifest = $package['source_manifest'] ?? $package['manifest'] ?? [];
        if (is_string($manifest)) {
            try {
                $manifest = json_decode($manifest, true, 64, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return ['ok' => false, 'errors' => ['The filing source manifest is invalid JSON.']];
            }
        }
        if ($body === '' || !is_array($manifest)) {
            return ['ok' => false, 'errors' => ['The CT600 package omitted its body or source manifest.']];
        }
        if ($this->containsSecretField($manifest)) {
            return ['ok' => false, 'errors' => ['The filing source manifest contains prohibited credential material.']];
        }
        $bodyHash = hash('sha256', $body);
        $manifestHash = hash('sha256', $this->canonicalJson($manifest));
        $providedBodyHash = strtolower(trim((string)($package['body_sha256'] ?? '')));
        $providedManifestHash = strtolower(trim((string)($package['source_manifest_sha256'] ?? '')));
        if ($providedBodyHash !== '' && !hash_equals($bodyHash, $providedBodyHash)) {
            return ['ok' => false, 'errors' => ['The CT600 body hash does not match its bytes.']];
        }
        if ($providedManifestHash !== '' && !hash_equals($manifestHash, $providedManifestHash)) {
            return ['ok' => false, 'errors' => ['The source-manifest hash does not match its canonical contents.']];
        }
        $packageHash = strtolower(trim((string)($package['package_hash'] ?? '')))
            ?: hash('sha256', $manifestHash . '|' . $bodyHash);
        if (!preg_match('/^[a-f0-9]{64}$/D', $packageHash)) {
            return ['ok' => false, 'errors' => ['The CT600 package hash is invalid.']];
        }

        return array_replace($package, [
            'ok' => true,
            'company_id' => (int)($package['company_id'] ?? $companyId),
            'accounting_period_id' => (int)($package['accounting_period_id'] ?? 0),
            'ct_period_id' => (int)($package['ct_period_id'] ?? $ctPeriodId),
            'utr' => preg_replace('/\s+/', '', (string)($package['utr'] ?? '')) ?? '',
            'filing_body_xml' => $body,
            'source_manifest' => $manifest,
            'source_manifest_sha256' => $manifestHash,
            'body_sha256' => $bodyHash,
            'package_hash' => $packageHash,
            'accounts_ixbrl_path' => $package['accounts_ixbrl_path'] ?? $package['accounts_path'] ?? null,
            'accounts_run_id' => isset($package['accounts_run_id']) ? (int)$package['accounts_run_id'] : null,
            'accounts_sha256' => $package['accounts_sha256'] ?? null,
            'computations_ixbrl_path' => $package['computations_ixbrl_path'] ?? $package['computations_path'] ?? null,
            'computation_run_id' => isset($package['computation_run_id']) ? (int)$package['computation_run_id'] : null,
            'computations_sha256' => $package['computations_sha256'] ?? null,
            'year_end_locked_at' => $package['year_end_locked_at'] ?? null,
            'irmark' => (string)($package['irmark'] ?? ''),
            'schema_version' => (string)($package['schema_version'] ?? ''),
            'validation' => (array)($package['validation'] ?? []),
            'errors' => [],
            'warnings' => (array)($package['warnings'] ?? []),
        ]);
    }

    private function safeCurrentManifest(int $companyId, int $ctPeriodId): array
    {
        if (!$this->manifestResolver instanceof \Closure
            && !method_exists($this->packages, 'currentSourceManifest')) {
            return ['ok' => false, 'errors' => ['The CT600 source-manifest service is unavailable.']];
        }
        try {
            $current = $this->manifestResolver instanceof \Closure
                ? ($this->manifestResolver)($companyId, $ctPeriodId)
                : $this->packages->currentSourceManifest($companyId, $ctPeriodId);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'errors' => [$exception->getMessage()]];
        }
        if (empty($current['ok'])) {
            return $current + ['ok' => false, 'errors' => ['The current filing source manifest is not ready.']];
        }
        $manifest = $current['source_manifest'] ?? [];
        if (is_string($manifest)) {
            $manifest = json_decode($manifest, true);
        }
        if (!is_array($manifest)) {
            return ['ok' => false, 'errors' => ['The current filing source manifest is invalid.']];
        }
        $manifestHash = hash('sha256', $this->canonicalJson($manifest));
        $provided = strtolower(trim((string)($current['source_manifest_sha256'] ?? '')));
        if ($provided !== '' && !hash_equals($manifestHash, $provided)) {
            return ['ok' => false, 'errors' => ['The current source-manifest hash is inconsistent.']];
        }
        $ctPeriod = \InterfaceDB::fetchOne(
            'SELECT accounting_period_id FROM corporation_tax_periods
             WHERE id = :ct_period_id AND company_id = :company_id LIMIT 1',
            ['ct_period_id' => $ctPeriodId, 'company_id' => $companyId]
        );
        if (is_array($ctPeriod)) {
            try {
                $bundle = (new FilingEvidenceService())->currentBundle(
                    $companyId,
                    (int)$ctPeriod['accounting_period_id'],
                    true
                );
                $manifest['filing_evidence_id'] = (string)$bundle['evidence_id'];
                $manifestHash = hash('sha256', $this->canonicalJson($manifest));
            } catch (\Throwable) {
                // Status remains available before a legacy locked period has
                // lazily received its first evidence bundle.
            }
        }
        $bodyHash = strtolower(trim((string)($current['body_sha256'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/D', $bodyHash)) {
            return ['ok' => false, 'errors' => ['The current CT600 body hash is missing.']];
        }

        return array_replace($current, [
            'ok' => true,
            'source_manifest' => $manifest,
            'source_manifest_sha256' => $manifestHash,
            'body_sha256' => $bodyHash,
            'errors' => [],
        ]);
    }

    private function successfulTestForHashes(
        int $companyId,
        int $ctPeriodId,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D', $manifestHash) || !preg_match('/^[a-f0-9]{64}$/D', $bodyHash)) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id
               AND ct_period_id = :ct_period_id
               AND environment = :environment
               AND business_outcome = :outcome
               AND protocol_state = :protocol_state
               AND source_manifest_sha256 = :manifest_hash
               AND body_sha256 = :body_hash
             ORDER BY id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'ct_period_id' => $ctPeriodId,
                'environment' => 'TIL',
                'outcome' => 'til_validated',
                'protocol_state' => 'closed',
                'manifest_hash' => $manifestHash,
                'body_hash' => $bodyHash,
            ]
        );

        return is_array($row) ? $this->normaliseSubmission($row) : null;
    }

    private function matchesSuccessfulTest(?array $row, string $manifestHash, string $bodyHash): bool
    {
        return is_array($row)
            && (string)($row['business_outcome'] ?? '') === 'til_validated'
            && (string)($row['protocol_state'] ?? '') === 'closed'
            && $manifestHash !== ''
            && $bodyHash !== ''
            && hash_equals($manifestHash, (string)($row['source_manifest_sha256'] ?? ''))
            && hash_equals($bodyHash, (string)($row['body_sha256'] ?? ''));
    }

    private function normaliseDeclaration(array $declaration): array
    {
        return [
            'declaration_name' => trim((string)(
                $declaration['declaration_name'] ?? $declaration['declarant_name'] ?? ''
            )),
            'declaration_status' => trim((string)(
                $declaration['declaration_status'] ?? $declaration['declarant_status'] ?? ''
            )),
            'declaration_confirmed' => $this->truthy($declaration['declaration_confirmed'] ?? false),
            'authority_confirmed' => $this->truthy($declaration['authority_confirmed'] ?? false),
            'supplementary_scope_confirmed' => $this->truthy(
                $declaration['supplementary_scope_confirmed'] ?? false
            ),
            'original_unfiled_confirmed' => $this->truthy(
                $declaration['original_unfiled_confirmed'] ?? false
            ),
        ];
    }

    private function declarationErrors(array $declaration): array
    {
        $errors = [];
        if ($declaration['declaration_name'] === '') {
            $errors[] = 'Enter the declarant name.';
        } elseif (mb_strlen($declaration['declaration_name']) > 255) {
            $errors[] = 'The declarant name must be 255 characters or fewer.';
        }
        if ($declaration['declaration_status'] === '') {
            $errors[] = 'Enter the declarant capacity or status.';
        } elseif (mb_strlen($declaration['declaration_status']) > 255) {
            $errors[] = 'The declarant capacity or status must be 255 characters or fewer.';
        }
        if (!$declaration['authority_confirmed']) {
            $errors[] = 'Confirm authority to file this Company Tax Return.';
        }
        if (!$declaration['declaration_confirmed']) {
            $errors[] = 'Confirm the Company Tax Return declaration.';
        }
        if (!$declaration['supplementary_scope_confirmed']) {
            $errors[] = 'Confirm the assessed supplementary-page scope.';
        }
        if (!$declaration['original_unfiled_confirmed']) {
            $errors[] = 'Confirm that this is the original unfiled return for the CT period.';
        }

        return $errors;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || in_array(strtolower(trim((string)$value)), ['1', 'yes', 'on', 'true'], true);
    }

    private function fetchForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND ct_period_id = :ct_period_id
             ORDER BY id DESC',
            ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
        );

        return array_map(fn(array $row): array => $this->normaliseSubmission($row), $rows);
    }

    private function firstMode(array $rows, string $mode): ?array
    {
        foreach ($rows as $row) {
            if ((string)$row['environment'] === $mode) {
                return $row;
            }
        }

        return null;
    }

    private function firstPending(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (in_array((string)$row['protocol_state'], [
                'ready', 'submitting', 'awaiting_poll', 'delete_pending', 'transport_uncertain',
            ], true)) {
                return $row;
            }
        }

        return null;
    }

    private function fetchById(int $submissionId): ?array
    {
        if ($submissionId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE id = :id LIMIT 1',
            ['id' => $submissionId]
        );

        return is_array($row) ? $this->normaliseSubmission($row) : null;
    }

    private function normaliseSubmission(array $row): array
    {
        foreach ([
            'validation_json' => 'validation',
            'source_manifest_json' => 'source_manifest',
            'request_headers_json' => 'request_headers',
            'response_headers_json' => 'response_headers',
        ] as $column => $key) {
            $decoded = json_decode((string)($row[$column] ?? ''), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
        }
        foreach ([
            'id', 'company_id', 'accounting_period_id', 'ct_period_id', 'test_submission_id',
            'poll_interval_seconds', 'poll_attempts', 'hmrc_response_code',
        ] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        $row['needs_poll'] = (string)($row['protocol_state'] ?? '') === 'awaiting_poll';
        $row['cleanup_pending'] = (string)($row['protocol_state'] ?? '') === 'delete_pending';
        $row['uncertain'] = (string)($row['protocol_state'] ?? '') === 'transport_uncertain';
        try {
            $row['transmission_archive'] = $this->archives->find(
                (int)($row['company_id'] ?? 0),
                'hmrc',
                (string)($row['environment'] ?? ''),
                $this->archiveReference((int)($row['id'] ?? 0))
            );
        } catch (\Throwable) {
            $row['transmission_archive'] = null;
        }

        return $row;
    }

    private function updateFailure(int $submissionId, string $protocolState, string $message): void
    {
        $this->updateSubmission($submissionId, [
            'status' => 'failed',
            'protocol_state' => $protocolState,
            'business_outcome' => 'error',
            'hmrc_response_summary' => $message,
        ]);
        $this->event($submissionId, 'error', $message);
    }

    private function updateSubmission(int $submissionId, array $changes): void
    {
        $allowed = [
            'status', 'protocol_state', 'business_outcome', 'transaction_id',
            'hmrc_submission_reference', 'hmrc_correlation_id', 'response_endpoint',
            'poll_interval_seconds', 'next_poll_at', 'poll_attempts',
            'hmrc_response_code', 'hmrc_response_summary', 'response_headers_json',
            'response_body_path', 'response_sha256', 'submitted_by', 'final_response_at',
            'cleanup_completed_at', 'cleanup_response_path', 'cleanup_response_sha256',
            'cleanup_error',
        ];
        $sets = [];
        $params = ['id' => $submissionId, 'updated_at' => $this->sqlNow()];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $sets[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = :updated_at';
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS . ' SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
        $this->syncArchiveLifecycle($submissionId);
    }

    private function archiveReference(int $submissionId): string
    {
        return 'submission-' . sprintf('%06d', $submissionId);
    }

    private function syncArchiveLifecycle(int $submissionId): void
    {
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            return;
        }
        try {
            $this->archives->updateLifecycle(
                (int)$submission['company_id'],
                (int)$submission['accounting_period_id'],
                'hmrc',
                (string)$submission['environment'],
                $this->archiveReference($submissionId),
                (string)$submission['protocol_state']
            );
        } catch (\Throwable) {
        }
    }

    private function commandResult(
        int $submissionId,
        bool $success,
        array $errors = [],
        bool $needsPoll = false,
        ?int $pollAfter = null
    ): array {
        $submission = $this->fetchById($submissionId);

        return [
            'success' => $success,
            'submission_id' => $submissionId,
            'mode' => (string)($submission['environment'] ?? ''),
            'status' => (string)($submission['status'] ?? ''),
            'protocol_state' => (string)($submission['protocol_state'] ?? ''),
            'business_outcome' => (string)($submission['business_outcome'] ?? ''),
            'needs_poll' => $needsPoll || !empty($submission['needs_poll']),
            'poll_after_seconds' => $pollAfter,
            'errors' => array_values(array_filter(array_map('strval', $errors))),
            'warnings' => [],
            'submission' => $submission,
        ];
    }

    private function existingResult(array $existing): array
    {
        $state = (string)$existing['protocol_state'];
        if ($state === 'transport_uncertain') {
            return $this->failure(
                'An identical transmission has an uncertain outcome. Do not resubmit it blindly.',
                (int)$existing['id'],
                $existing
            );
        }
        if (in_array($state, ['submitting', 'awaiting_poll', 'delete_pending'], true)) {
            return $this->failure(
                'An identical HMRC conversation is already in progress.',
                (int)$existing['id'],
                $existing
            );
        }
        if (in_array((string)$existing['business_outcome'], ['til_validated', 'live_accepted'], true)) {
            return $this->commandResult((int)$existing['id'], true);
        }

        return $this->failure(
            'HMRC already processed this exact filing basis. Change the filing source before another submission.',
            (int)$existing['id'],
            $existing
        );
    }

    private function failure(string|array $errors, int $submissionId = 0, ?array $submission = null): array
    {
        $errors = is_array($errors) ? $errors : [$errors];

        return [
            'success' => false,
            'submission_id' => $submissionId,
            'mode' => (string)($submission['environment'] ?? ''),
            'status' => (string)($submission['status'] ?? ''),
            'protocol_state' => (string)($submission['protocol_state'] ?? ''),
            'business_outcome' => (string)($submission['business_outcome'] ?? ''),
            'needs_poll' => !empty($submission['needs_poll']),
            'poll_after_seconds' => null,
            'errors' => array_values(array_filter(array_map('strval', $errors))),
            'warnings' => [],
            'submission' => $submission,
        ];
    }

    private function transportErrors(array $result): array
    {
        $message = trim((string)($result['error'] ?? ''));
        return [$message !== '' ? $message : 'HMRC rejected the CT600 filing body.'];
    }

    private function submissionReference(string $bodyXml): ?string
    {
        if ($bodyXml === '') {
            return null;
        }
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($bodyXml, LIBXML_NONET)) {
                return null;
            }
            $xpath = new \DOMXPath($document);
            foreach (['SubmissionReference', 'HMRCReference', 'ReceiptReference', 'IRmarkReceipt'] as $name) {
                $nodes = $xpath->query('//*[local-name()="' . $name . '"]');
                $value = trim((string)($nodes === false ? '' : $nodes->item(0)?->textContent));
                if ($value !== '') {
                    return mb_substr($value, 0, 255);
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function storeArtifact(int $submissionId, string $filename, string $contents): array
    {
        if ($submissionId <= 0 || $contents === '') {
            throw new \RuntimeException('A non-empty HMRC artifact and submission ID are required.');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $filename)) {
            throw new \InvalidArgumentException('The HMRC artifact filename is invalid.');
        }
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            throw new \RuntimeException('The HMRC submission archive identity could not be resolved.');
        }

        return $this->archives->store(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'hmrc',
            (string)$submission['environment'],
            $this->archiveReference($submissionId),
            (string)$submission['protocol_state'],
            $filename,
            $contents
        );
    }

    private function resolveArtifactRoot(?string $artifactRoot): string
    {
        $artifactRoot = trim((string)$artifactRoot);
        if ($artifactRoot === '') {
            $configured = \AppConfigurationStore::get('hmrc.ct600_xml.artifact_root', '');
            $artifactRoot = trim((string)$configured);
        }
        if ($artifactRoot === '') {
            $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
            $uploadRoot = trim((string)($uploads['upload_base_dir'] ?? ''));
            if ($uploadRoot === '') {
                $uploadRoot = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'files';
            }
            $artifactRoot = rtrim($uploadRoot, '\\/');
        }
        if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/D', $artifactRoot)) {
            throw new \RuntimeException('HMRC artifact storage must use an absolute path.');
        }
        if (!is_dir($artifactRoot) && !@mkdir($artifactRoot, 0700, true) && !is_dir($artifactRoot)) {
            throw new \RuntimeException('Unable to create protected HMRC artifact storage.');
        }
        $resolved = realpath($artifactRoot);
        if (!is_string($resolved) || $resolved === '') {
            throw new \RuntimeException('Unable to resolve protected HMRC artifact storage.');
        }
        $publicRoot = realpath((string)APP_ROOT);
        if (is_string($publicRoot) && $this->pathWithin($resolved, $publicRoot)) {
            throw new \RuntimeException('HMRC filing artifacts must not be stored beneath the public web root.');
        }

        return rtrim($resolved, '\\/');
    }

    private function schemaError(): ?string
    {
        if (!\InterfaceDB::tableExists(self::SUBMISSIONS) || !\InterfaceDB::tableExists(self::EVENTS)) {
            return 'Run the downstream HMRC CT600 database migration before filing.';
        }
        foreach (self::REQUIRED_COLUMNS as $column) {
            if (!\InterfaceDB::columnExists(self::SUBMISSIONS, $column)) {
                return 'Run the downstream HMRC CT600 source-manifest migration before filing.';
            }
        }

        return null;
    }

    public function event(int $submissionId, string $level, string $message, array $context = []): void
    {
        if ($submissionId <= 0 || !\InterfaceDB::tableExists(self::EVENTS)) {
            return;
        }
        $level = strtolower(trim($level));
        if (!in_array($level, ['debug', 'info', 'warning', 'error', 'success'], true)) {
            $level = 'info';
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::EVENTS . ' (
                submission_id, event_level, event_message, event_context_json, created_at
             ) VALUES (:submission_id, :level, :message, :context, :created_at)',
            [
                'submission_id' => $submissionId,
                'level' => $level,
                'message' => trim($message),
                'context' => $context === [] ? null : $this->json($context),
                'created_at' => $this->sqlNow(),
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function getSubmissionHistory(int $companyId, ?int $accountingPeriodId = null): array
    {
        if ($companyId <= 0 || $this->schemaError() !== null) {
            return [];
        }
        $params = ['company_id' => $companyId];
        $where = 'company_id = :company_id';
        if ($accountingPeriodId !== null && $accountingPeriodId > 0) {
            $where .= ' AND accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT 200',
            $params
        );

        return array_map(fn(array $row): array => $this->normaliseSubmission($row), $rows);
    }

    public function getLatestSubmission(int $companyId, int $accountingPeriodId): ?array
    {
        return $this->getSubmissionHistory($companyId, $accountingPeriodId)[0] ?? null;
    }

    public function getLatestSubmissionForCtPeriod(int $companyId, int $ctPeriodId): ?array
    {
        return $this->fetchForCtPeriod($companyId, $ctPeriodId)[0] ?? null;
    }

    /** Compatibility validator; it never creates a submission row. */
    public function validatePackage(int $companyId, int $ctPeriodId, string $mode): array
    {
        try {
            $package = $this->packagePreparer instanceof \Closure
                ? ($this->packagePreparer)($companyId, $ctPeriodId, strtoupper(trim($mode)), [])
                : $this->packages->prepareForSubmission(
                    $companyId,
                    $ctPeriodId,
                    strtoupper(trim($mode)),
                    []
                );
            $package = $this->normalisePackage($package, $companyId, $ctPeriodId);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        return [
            'success' => !empty($package['ok']),
            'submission_id' => 0,
            'errors' => (array)($package['errors'] ?? []),
            'warnings' => (array)($package['warnings'] ?? []),
            'validation' => (array)($package['validation'] ?? []),
        ];
    }

    /** Draft-only persistence is deliberately not part of the filing workflow. */
    public function createSubmissionDraft(int $companyId, int $ctPeriodId, string $mode): array
    {
        unset($companyId, $ctPeriodId, $mode);
        return $this->failure('Use Test or Submit Tax Return to prepare and transmit one immutable package.');
    }

    /** Compatibility entrypoint: an existing acknowledgement can only be polled. */
    public function submit(int $submissionId, callable $logger): array
    {
        $result = $this->poll($submissionId, null);
        foreach ((array)$result['errors'] as $error) {
            $logger('error', (string)$error);
        }

        return $result;
    }

    /** Migration guard only; runtime DDL is forbidden. */
    public function ensureSchema(): void
    {
        $error = $this->schemaError();
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
    }

    private function firstPendingSubmissionForPeriod(int $companyId, int $ctPeriodId): ?array
    {
        return $this->firstPending($this->fetchForCtPeriod($companyId, $ctPeriodId));
    }

    private function acceptedLiveSubmissionForPeriod(int $companyId, int $ctPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id
               AND ct_period_id = :ct_period_id
               AND environment = :environment
               AND business_outcome = :outcome
             ORDER BY id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'ct_period_id' => $ctPeriodId,
                'environment' => 'LIVE',
                'outcome' => 'live_accepted',
            ]
        );

        return is_array($row) ? $this->normaliseSubmission($row) : null;
    }

    private function actor(int|string|null $actor): string
    {
        if (is_int($actor) && $actor > 0) {
            return 'user:' . $actor;
        }
        $value = trim((string)$actor);
        return $value !== '' ? mb_substr($value, 0, 100) : 'system';
    }

    private function recordEvidenceOutcome(
        int $submissionId,
        string $eventType,
        string $status,
        int|string|null $actor,
        array $context = []
    ): void {
        $submission = $this->fetchById($submissionId);
        $bundleId = (int)($submission['evidence_bundle_id'] ?? 0);
        if ($bundleId <= 0) {
            return;
        }
        (new FilingEvidenceService())->recordEvent(
            $bundleId,
            $eventType,
            $status,
            $this->actor($actor),
            match ($eventType) {
                'hmrc_accepted' => 'HMRC accepted the frozen filing package.',
                'hmrc_rejected' => 'HMRC rejected the frozen filing package.',
                'hmrc_acknowledged' => 'HMRC acknowledged the frozen filing package.',
                default => 'The HMRC transmission outcome is uncertain.',
            },
            ['submission_id' => $submissionId] + $context
        );
    }

    private function actorUserId(int|string|null $actor): ?int
    {
        if (is_int($actor) && $actor > 0) {
            return $actor;
        }
        $value = trim((string)$actor);
        return ctype_digit($value) && (int)$value > 0 ? (int)$value : null;
    }

    private function now(): \DateTimeImmutable
    {
        $value = $this->clock instanceof \Closure ? ($this->clock)() : null;
        if ($value instanceof \DateTimeInterface) {
            return new \DateTimeImmutable($value->format('c'));
        }
        if (is_int($value)) {
            return (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_string($value) && trim($value) !== '') {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function sqlNow(): string
    {
        return $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };

        return json_encode(
            $normalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    private function containsSecretField(array $value): bool
    {
        $prohibited = [
            'password', 'sender_password', 'api_key', 'access_token', 'client_secret',
            'authorization', 'credential_secret',
        ];
        foreach ($value as $key => $item) {
            $normalised = strtolower(str_replace('-', '_', trim((string)$key)));
            if (in_array($normalised, $prohibited, true)) {
                return true;
            }
            if (is_array($item) && $this->containsSecretField($item)) {
                return true;
            }
        }

        return false;
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent . '/');
    }
}
