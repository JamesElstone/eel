<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Client\GovTalkConversationContext;
use eel_accounts\Client\HmrcCtTransactionEngineClient;
use eel_accounts\Client\HmrcCtTransactionEngineEnvironment;
use eel_accounts\Client\HmrcCtTransactionEngineTransportInterface;

/** Durable CT600 GovTalk submission workflow, one conversation per CT period. */
final class HmrcCorporationTaxSubmissionService
{
    private const SUBMISSIONS = 'hmrc_ct600_submissions';
    private const EVENTS = 'hmrc_submission_events';
    private const REJECTED_CLEANUP_BLOCKER = 'HMRC rejected this submission, but GovTalk cleanup is still pending. '
        . 'In the History tab, select Check Submission Status before transmitting the revised return.';
    private const REQUIRED_COLUMNS = [
        'source_manifest_json',
        'source_manifest_sha256',
        'test_submission_id',
        'authority_confirmed',
        'authority_confirmed_at',
        'authority_confirmed_by',
        'cleanup_attempts',
    ];
    private const DEVELOPER_REQUEST_ROLES = [
        'TEST' => 'hmrc_govtalk_developer_request_test',
        'TIL' => 'hmrc_govtalk_developer_request_til',
        'LIVE' => 'hmrc_govtalk_developer_request_live',
    ];

    private HmrcCtTransactionEngineTransportInterface $transport;
    private HmrcSubmissionPackageService $packages;

    /** @var null|\Closure(): mixed */
    private ?\Closure $clock;

    /** @var null|\Closure(int,int,string): array */
    private ?\Closure $packagePreparer;

    /** @var null|\Closure(int,int): array */
    private ?\Closure $manifestResolver;

    /** @var null|\Closure(): string */
    private ?\Closure $xmlEnvironmentResolver;

    /** @var null|\Closure(int,int,int): list<array{label:string,ready:bool,message:string,detail?:string}> */
    private ?\Closure $filingReadinessResolver;

    private string $artifactRoot;
    private TransmissionArchiveService $archives;
    private GovTalkProtocolConversationService $govTalk;
    private HmrcReceiptReferenceService $receiptReferences;

    public function __construct(
        ?HmrcCtTransactionEngineTransportInterface $transport = null,
        ?HmrcSubmissionPackageService $packages = null,
        ?callable $clock = null,
        ?string $artifactRoot = null,
        ?callable $packagePreparer = null,
        ?callable $manifestResolver = null,
        ?TransmissionArchiveService $archiveService = null,
        ?callable $xmlEnvironmentResolver = null,
        ?callable $filingReadinessResolver = null,
        ?GovTalkProtocolConversationService $govTalkConversation = null,
        ?HmrcReceiptReferenceService $receiptReferences = null
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
        $this->xmlEnvironmentResolver = $xmlEnvironmentResolver === null
            ? null
            : \Closure::fromCallable($xmlEnvironmentResolver);
        $this->filingReadinessResolver = $filingReadinessResolver === null
            ? null
            : \Closure::fromCallable($filingReadinessResolver);
        $this->artifactRoot = $this->resolveArtifactRoot($artifactRoot);
        $this->archives = $archiveService ?? new TransmissionArchiveService($this->artifactRoot);
        $this->govTalk = $govTalkConversation
            ?? new GovTalkProtocolConversationService($this->archives);
        $this->receiptReferences = $receiptReferences ?? new HmrcReceiptReferenceService();
    }

    /** @return array<string, mixed> */
    public function status(int $companyId, int $accountingPeriodId): array
    {
        $xmlEnvironment = $this->xmlEnvironment();
        $testEnvironment = match ($xmlEnvironment) {
            'TEST' => 'TEST',
            'LIVE' => 'TIL',
            default => 'DISABLED',
        };
        $liveEnvironment = $xmlEnvironment === 'LIVE' ? 'LIVE' : 'DISABLED';
        $environments = ['DISABLED' => $this->disabledConfiguration()];
        if ($xmlEnvironment === 'TEST') {
            $environments['TEST'] = $this->transport->configurationStatus('TEST');
        } elseif ($xmlEnvironment === 'LIVE') {
            $environments['TIL'] = $this->transport->configurationStatus('TIL');
            $environments['LIVE'] = $this->transport->configurationStatus('LIVE');
        }
        $base = [
            'success' => false,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'xml_environment' => $xmlEnvironment,
            'test_environment' => $testEnvironment,
            'live_environment' => $liveEnvironment,
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
        $submissionsByPeriod = $this->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        $requestArtifactCandidates = $this->requestArtifactCandidatesForAccountingPeriod(
            $companyId,
            $accountingPeriodId
        );
        $ctPeriodService = new CorporationTaxPeriodService();
        foreach ($periods as $period) {
            $ctPeriodId = (int)$period['id'];
            $submissions = (array)($submissionsByPeriod[$ctPeriodId] ?? []);
            $latestTest = $testEnvironment === 'DISABLED'
                ? null
                : $this->firstMode($submissions, $testEnvironment);
            $latestLive = $this->firstMode($submissions, 'LIVE');
            $pending = $this->firstPending($submissions);
            $manifest = $this->safeCurrentManifestForStatus(
                $companyId,
                $ctPeriodId
            );
            $filingSnapshot = $this->filingSnapshot(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                $manifest
            );
            $filingDependencies = (array)($filingSnapshot['dependencies'] ?? []);
            $manifestHash = (string)($manifest['source_manifest_sha256'] ?? '');
            $bodyHash = (string)($manifest['body_sha256'] ?? '');
            $requestArtifacts = [];
            $requestModes = $xmlEnvironment === 'TEST'
                ? ['TEST']
                : ($xmlEnvironment === 'LIVE' ? ['TEST', 'TIL', 'LIVE'] : []);
            foreach ($requestModes as $requestMode) {
                $artifact = $this->requestArtifactFromCandidates(
                    (array)($requestArtifactCandidates[$ctPeriodId] ?? []),
                    $ctPeriodId,
                    $requestMode,
                    $manifestHash,
                    $bodyHash
                );
                $requestArtifacts[$requestMode] = is_array($artifact)
                    ? $this->publicRequestArtifactDescriptor($artifact)
                    : $this->unavailableRequestArtifactDescriptor($requestMode);
            }
            $latestTestAttempt = $bodyHash === ''
                ? $this->firstMode($submissions, 'TEST')
                : $this->firstModeForBody($submissions, 'TEST', $bodyHash);
            $latestTilAttempt = $bodyHash === ''
                ? $this->firstMode($submissions, 'TIL')
                : $this->firstModeForBody($submissions, 'TIL', $bodyHash);
            $latestLiveAttempt = $bodyHash === ''
                ? $this->firstMode($submissions, 'LIVE')
                : $this->firstModeForBody($submissions, 'LIVE', $bodyHash);
            $readinessBlockers = [];
            foreach ($filingDependencies as $dependency) {
                if (!empty($dependency['ready'])) {
                    continue;
                }
                $message = trim((string)($dependency['message'] ?? ''));
                if ($message !== '') {
                    $readinessBlockers[] = $message;
                }
            }

            $testBlockers = array_values(array_map(
                'strval',
                (array)($environments[$testEnvironment]['blockers'] ?? [])
            ));
            $liveBlockers = $xmlEnvironment === 'LIVE'
                ? array_values(array_map(
                    'strval',
                    (array)($environments[$liveEnvironment]['blockers'] ?? [])
                ))
                : [];
            if (empty($manifest['ok'])) {
                $errors = (array)($manifest['errors'] ?? ['The current filing source manifest is not ready.']);
                $testBlockers = array_merge($testBlockers, array_map('strval', $errors));
                $liveBlockers = array_merge($liveBlockers, array_map('strval', $errors));
            }
            $testBlockers = array_merge($testBlockers, $readinessBlockers);
            $liveBlockers = array_merge($liveBlockers, $readinessBlockers);
            if (is_array($pending)) {
                $fallback = (string)$pending['protocol_state'] === 'transport_uncertain'
                    ? 'The last transmission has an uncertain outcome and must not be resubmitted.'
                    : 'An HMRC conversation is already in progress for this CT period.';
                $message = $this->pendingConversationBlocker($pending, $fallback);
                $testBlockers[] = $message;
                $liveBlockers[] = $message;
            }
            if ($this->matchesSuccessfulTest($latestTest, $manifestHash, $bodyHash, $testEnvironment)) {
                $testBlockers[] = $testEnvironment === 'TEST'
                    ? 'This exact filing body has already passed the HMRC TEST service.'
                    : 'This exact filing body has already passed HMRC Test in Live.';
            }
            if ($xmlEnvironment === 'LIVE') {
                $testGate = $this->successfulTestForHashesInRows($submissions, $manifestHash, $bodyHash);
                if (!is_array($testGate)) {
                    $liveBlockers[] = 'The exact current filing body must pass HMRC Test in Live before LIVE filing.';
                }
            } else {
                if ($xmlEnvironment === 'DISABLED') {
                    $liveBlockers[] = 'HMRC XML transmission is disabled in Application API Credentials.';
                }
            }
            if (is_array($latestLive) && (string)$latestLive['business_outcome'] === 'live_accepted') {
                $liveBlockers[] = 'HMRC has already accepted a LIVE return for this CT period.';
            }

            $testGatewayRejection = $testEnvironment === 'DISABLED' || $manifestHash === '' || $bodyHash === ''
                ? null
                : $this->gatewayRejectionForHashesInRows(
                    $submissions,
                    $testEnvironment,
                    $manifestHash,
                    $bodyHash
                );
            $liveGatewayRejection = $liveEnvironment === 'DISABLED' || $manifestHash === '' || $bodyHash === ''
                ? null
                : $this->gatewayRejectionForHashesInRows(
                    $submissions,
                    $liveEnvironment,
                    $manifestHash,
                    $bodyHash
                );
            $testGatewayRetryReady = is_array($testGatewayRejection) && $testBlockers === [];
            $liveGatewayRetryReady = is_array($liveGatewayRejection) && $liveBlockers === [];
            if (is_array($testGatewayRejection)) {
                $testBlockers[] = 'HMRC definitively rejected this exact filing body before opening a conversation. Ordinary resubmission is blocked.';
            }
            if (is_array($liveGatewayRejection)) {
                $liveBlockers[] = 'HMRC definitively rejected this exact filing body before opening a conversation. Ordinary resubmission is blocked.';
            }

            $row = [
                'ct_period_id' => $ctPeriodId,
                'sequence_no' => (int)$period['sequence_no'],
                'display_sequence_no' => $ctPeriodService->displaySequenceNo(
                    $companyId,
                    $accountingPeriodId,
                    (int)$period['sequence_no']
                ),
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
                'ct_period_status' => (string)$period['status'],
                'xml_environment' => $xmlEnvironment,
                'test_environment' => $testEnvironment,
                'live_environment' => $liveEnvironment,
                'current_manifest_sha256' => $manifestHash,
                'current_body_sha256' => $bodyHash,
                'request_artifacts' => $requestArtifacts,
                'latest_test' => $latestTest,
                'latest_live' => $latestLive,
                'latest_test_attempt' => $latestTestAttempt,
                'latest_til_attempt' => $latestTilAttempt,
                'latest_live_attempt' => $latestLiveAttempt,
                'test_gateway_rejection' => $testGatewayRejection,
                'live_gateway_rejection' => $liveGatewayRejection,
                'test_gateway_retry_ready' => $testGatewayRetryReady,
                'live_gateway_retry_ready' => $liveGatewayRetryReady,
                'filing_dependencies' => $filingDependencies,
                'latest_submission' => $submissions[0] ?? null,
                'pending_submission' => $pending,
                'test_ready' => $testBlockers === [],
                'live_ready' => $xmlEnvironment === 'LIVE' && $liveBlockers === [],
                'test_blockers' => array_values(array_unique($testBlockers)),
                'live_blockers' => array_values(array_unique($liveBlockers)),
                'blockers' => array_values(array_unique(array_merge($testBlockers, $liveBlockers))),
            ];
            $base['periods'][] = $row;
        }

        $base['success'] = true;
        return $base;
    }

    /** TEST mode uses ETS; LIVE mode requires Test in Live before statutory filing. */
    public function submitTest(
        int $companyId,
        int $ctPeriodId,
        int|string|null $actor = null,
        ?callable $progress = null,
        bool $retryGatewayRejection = false
    ): array {
        $xmlEnvironment = $this->xmlEnvironment();
        if ($xmlEnvironment === 'DISABLED') {
            return $this->failure('HMRC XML transmission is disabled in Application API Credentials.');
        }

        return $this->submitMode(
            $companyId,
            $ctPeriodId,
            $xmlEnvironment === 'TEST' ? 'TEST' : 'TIL',
            $actor,
            $progress,
            $retryGatewayRejection
        );
    }

    public function submitLive(
        int $companyId,
        int $ctPeriodId,
        int|string|null $actor = null,
        ?callable $progress = null,
        bool $retryGatewayRejection = false
    ): array {
        return $this->submitMode(
            $companyId,
            $ctPeriodId,
            'LIVE',
            $actor,
            $progress,
            $retryGatewayRejection
        );
    }

    /**
     * Developer-only preparation of the exact submit envelope. This performs
     * no transport and deliberately creates no submission/conversation row.
     *
     * @return array<string,mixed>
     */
    public function generateRequestFile(
        int $companyId,
        int $ctPeriodId,
        int|string|null $actor = null,
        ?callable $progress = null,
        ?string $requestedEnvironment = null
    ): array {
        unset($actor);
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Verifying the configured HMRC XML environment…', 12);
        $xmlEnvironment = $this->xmlEnvironment();
        if ($xmlEnvironment === 'DISABLED') {
            return $this->failure('HMRC XML transmission is disabled in Application API Credentials.');
        }
        $defaultMode = $xmlEnvironment === 'TEST' ? 'TEST' : 'LIVE';
        $requestedEnvironment = strtoupper(trim((string)$requestedEnvironment));
        try {
            $mode = $requestedEnvironment === ''
                ? $defaultMode
                : HmrcCtTransactionEngineEnvironment::normalise($requestedEnvironment);
        } catch (\InvalidArgumentException $exception) {
            return $this->failure($exception->getMessage());
        }
        $allowedModes = $xmlEnvironment === 'TEST'
            ? ['TEST']
            : ['TEST', 'TIL', 'LIVE'];
        if (!in_array($mode, $allowedModes, true)) {
            return $this->failure(
                'The requested HMRC request-file environment is not permitted by the selected HMRC XML environment.'
            );
        }
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
        $report('Loading and verifying the prepared CT600 XML artifact…', 35);
        try {
            $package = $this->packagePreparer instanceof \Closure
                ? ($this->packagePreparer)($companyId, $ctPeriodId, $mode)
                : $this->packages->prepareForSubmission($companyId, $ctPeriodId, $mode);
            $package = $this->normalisePackage($package, $companyId, $ctPeriodId);
        } catch (\Throwable $exception) {
            return $this->failure('The CT600 package could not be prepared: ' . $exception->getMessage());
        }
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

        $existing = $this->requestArtifactRecordForHashes(
            $companyId,
            (int)$ctPeriod['accounting_period_id'],
            $ctPeriodId,
            $mode,
            (string)$package['source_manifest_sha256'],
            (string)$package['body_sha256']
        );
        if (is_array($existing)) {
            try {
                $file = $this->requestArtifactFile($existing);
            } catch (\Throwable $exception) {
                return $this->failure(
                    'An immutable GovTalk request artefact already exists but failed verification; '
                        . 'no replacement file was generated: ' . $exception->getMessage()
                );
            }
            $report('The existing immutable GovTalk request artefact is ready.', 100);
            return $this->existingRequestArtifactResult(
                $existing,
                $file,
                (array)($package['warnings'] ?? [])
            );
        }

        $profile = HmrcCtTransactionEngineEnvironment::profile($mode);
        $evidence = new FilingEvidenceService();
        try {
            $reservation = $evidence->reserveArtifact(
                $companyId,
                (int)$ctPeriod['accounting_period_id'],
                self::DEVELOPER_REQUEST_ROLES[$mode],
                $ctPeriodId,
                [
                    'artifact_source' => 'generated',
                    'environment' => $mode,
                    'body_sha256' => (string)$package['body_sha256'],
                    'source_manifest_sha256' => (string)$package['source_manifest_sha256'],
                    'endpoint' => (string)$profile['submission_url'],
                    'credential_environment' => (string)$profile['credential_environment'],
                    'transmitted' => false,
                ]
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The immutable GovTalk request artefact could not be reserved: ' . $exception->getMessage()
            );
        }

        $report('Building the exact environment-specific GovTalk request…', 65);
        $prepared = $this->transport->prepareSubmissionRequest(
            (string)$package['filing_body_xml'],
            (string)$package['utr'],
            $mode,
            (string)$reservation['transaction_hex']
        );
        if (empty($prepared['success'])) {
            $this->failRequestArtifactQuietly(
                $evidence,
                (int)$reservation['id'],
                'The environment-specific GovTalk request could not be prepared.',
                ['environment' => $mode, 'transmitted' => false]
            );
            return $this->failure($this->transportErrors($prepared));
        }

        try {
            $artifact = $this->storeDeveloperRequestFile(
                $package,
                $mode,
                (string)$prepared['transaction_id'],
                (string)($prepared['raw_request_xml'] ?? $prepared['request_xml'] ?? '')
            );
            $credentialsPlaceholder = !empty($prepared['credentials_placeholder']);
            $evidence->completeArtifact((int)$reservation['id'], [
                'status' => 'generated',
                'filename' => $artifact['filename'],
                'path' => $artifact['path'],
                'sha256' => $artifact['sha256'],
                'schema_identity' => 'GovTalk Document Submission Protocol 2.0 / CT/5',
                'validation_status' => 'passed',
                'identifier_embedded' => true,
                'metadata' => [
                    'artifact_source' => 'generated',
                    'environment' => $mode,
                    'body_sha256' => (string)$package['body_sha256'],
                    'source_manifest_sha256' => (string)$package['source_manifest_sha256'],
                    'endpoint' => (string)($prepared['endpoint'] ?? $profile['submission_url']),
                    'credential_environment' => (string)$profile['credential_environment'],
                    'credentials_placeholder' => $credentialsPlaceholder,
                    'transmitted' => false,
                ],
            ]);
        } catch (\Throwable $exception) {
            $this->failRequestArtifactQuietly(
                $evidence,
                (int)$reservation['id'],
                'The GovTalk request file could not be stored.',
                ['environment' => $mode, 'transmitted' => false]
            );
            return $this->failure('The GovTalk request file could not be stored: ' . $exception->getMessage());
        }
        $report('The GovTalk request file was generated without transmission.', 100);
        $credentialWarning = $credentialsPlaceholder
            ? 'The generated GovTalk request uses developer placeholder sender credentials and cannot be transmitted.'
            : 'The generated GovTalk request contains configured HMRC sender credentials; keep it private.';

        return [
            'success' => true,
            'submission_id' => 0,
            'mode' => $mode,
            'status' => 'generated',
            'protocol_state' => 'not_sent',
            'business_outcome' => '',
            'needs_poll' => false,
            'poll_after_seconds' => null,
            'errors' => [],
            'warnings' => array_values(array_unique(array_merge(
                (array)($package['warnings'] ?? []),
                [$credentialWarning]
            ))),
            'path' => $artifact['path'],
            'filename' => $artifact['filename'],
            'sha256' => $artifact['sha256'],
            'bytes' => $artifact['bytes'],
            'endpoint' => (string)($prepared['endpoint'] ?? ''),
            'transaction_id' => $artifact['transaction_id'],
            'credentials_placeholder' => $credentialsPlaceholder,
            'artifact_source' => 'generated',
            'artifact_id' => (string)$reservation['artifact_id'],
        ];
    }

    /** @return array{path:string,filename:string,sha256:string,environment:string,source:string,artifact_id:string,artifact_row_id:int,bundle_id:int,submission_id:int,exchange_id:int} */
    public function requestArtifactForDownload(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $requestedEnvironment
    ): array {
        try {
            $mode = HmrcCtTransactionEngineEnvironment::normalise($requestedEnvironment);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }
        $xmlEnvironment = $this->xmlEnvironment();
        $allowedModes = $xmlEnvironment === 'TEST'
            ? ['TEST']
            : ($xmlEnvironment === 'LIVE' ? ['TEST', 'TIL', 'LIVE'] : []);
        if (!in_array($mode, $allowedModes, true)) {
            throw new \RuntimeException(
                'The requested HMRC request artefact is not permitted by the selected HMRC XML environment.'
            );
        }

        $status = $this->status($companyId, $accountingPeriodId);
        if (empty($status['success'])) {
            throw new \RuntimeException(
                (string)(((array)($status['errors'] ?? []))[0] ?? 'HMRC request artefact status is unavailable.')
            );
        }
        $selected = null;
        foreach ((array)($status['periods'] ?? []) as $period) {
            if ((int)($period['ct_period_id'] ?? 0) === $ctPeriodId) {
                $selected = (array)$period;
                break;
            }
        }
        if (!is_array($selected)) {
            throw new \RuntimeException(
                'The selected CT period does not belong to the authenticated accounting period.'
            );
        }
        $record = $this->requestArtifactRecordForHashes(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $mode,
            (string)($selected['current_manifest_sha256'] ?? ''),
            (string)($selected['current_body_sha256'] ?? '')
        );
        if (!is_array($record)) {
            throw new \RuntimeException(
                'No immutable ' . $mode . ' GovTalk request artefact exists for the exact current filing basis.'
            );
        }

        return $this->requestArtifactFile($record);
    }

    public function poll(
        int $submissionId,
        int|string|null $actor = null,
        ?callable $progress = null
    ): array
    {
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Verifying the pending HMRC conversation…', 15);
        $xmlEnvironment = $this->xmlEnvironment();
        if ($xmlEnvironment === 'DISABLED') {
            return $this->failure('HMRC XML transmission is disabled in Application API Credentials.');
        }
        $schemaError = $this->schemaError();
        if ($schemaError !== null) {
            return $this->failure($schemaError);
        }
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            return $this->failure('The HMRC submission does not exist.');
        }
        if (!$this->environmentPermitted((string)$submission['environment'], $xmlEnvironment)) {
            return $this->failure(
                'The pending HMRC conversation does not belong to the selected HMRC XML environment.',
                $submissionId,
                $submission
            );
        }
        $state = (string)$submission['protocol_state'];
        if ($state === 'transport_uncertain') {
            return $this->failure(
                'The transmission outcome is uncertain. Do not resubmit or poll it as a normal acknowledgement.',
                $submissionId,
                $submission
            );
        }
        if (!in_array($state, ['awaiting_poll', 'delete_pending'], true)) {
            return $this->failure('This HMRC submission is not awaiting a poll.', $submissionId, $submission);
        }

        $now = $this->now();
        $nextPoll = trim((string)($submission['next_poll_at'] ?? ''));
        if ($nextPoll !== '') {
            $due = new \DateTimeImmutable($nextPoll, new \DateTimeZone('UTC'));
            if ($now < $due) {
                $seconds = max(1, $due->getTimestamp() - $now->getTimestamp());
                $result = $this->failure(
                    'HMRC requested a wait of ' . $seconds
                        . ' more seconds before the next protocol action.',
                    $submissionId,
                    $submission
                );
                $result['needs_poll'] = true;
                $result['poll_after_seconds'] = $seconds;
                return $result;
            }
        }
        if ($state === 'delete_pending') {
            $report('Completing HMRC conversation cleanup…', 55);
            return $this->cleanup($submission, $actor);
        }

        try {
            $originalSubmissionTransactionId = $this->originalSubmissionTransactionId(
                $submissionId,
                $submission
            );
            $boundConversationTransactionIds = $this->boundConversationTransactionIds(
                $submissionId,
                $submission
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The original HMRC submission exchange could not be verified: '
                    . $exception->getMessage(),
                $submissionId,
                $submission
            );
        }

        $previousAttempt = (int)$submission['poll_attempts'];
        $attempt = $previousAttempt + 1;
        $capturedResponse = null;
        $report('Building the HMRC GovTalk poll request…', 45);
        $report('Sending the HMRC GovTalk poll request…', 60);
        $result = $this->transport->poll(
            (string)$submission['hmrc_correlation_id'],
            (string)$submission['response_endpoint'],
            (string)$submission['environment'],
            GovTalkConversationContext::fromCallbacks(
                'hmrc',
                (string)$submission['environment'],
                function (array $request) use (
                    $submissionId,
                    $previousAttempt,
                    $attempt,
                    $actor,
                    $report
                ): array {
                $report('Archiving the exact GovTalk poll request before sending…', 68);
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
                $artifact = $this->govTalk->captureRequest(
                    $this->hmrcGovTalkIdentity(
                        $submissionId,
                        'poll'
                    ),
                    $request
                );
                $this->govTalk->markSendStarted(
                    'hmrc',
                    (string)$request['environment'],
                    (string)$request['transaction_id']
                );
                $this->event($submissionId, 'info', 'HMRC poll request persisted before transmission.', [
                    'attempt' => $attempt,
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                    'request_bytes' => (int)$request['request_bytes'],
                ]);
                return $artifact;
                },
                function (array $response) use (
                    $submissionId,
                    $attempt,
                    &$capturedResponse,
                    $report
                ): array {
                    $report('Capturing and verifying the HMRC poll response…', 85);
                    $capturedResponse = $this->govTalk->captureResponse(
                        $this->hmrcGovTalkIdentity(
                            $submissionId,
                            'poll'
                        ),
                        $response
                    );
                    return $capturedResponse;
                }
            ),
            $originalSubmissionTransactionId,
            null,
            $boundConversationTransactionIds
        );
        $report('Recording the latest HMRC conversation state and evidence…', 94);
        $this->completeHmrcGovTalkResult((string)$submission['environment'], $result);
        $result['archived_response'] = $capturedResponse;

        return $this->applyConversationResult($submissionId, $result, $actor, true);
    }

    public function reprocessArchivedResponse(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $submissionId,
        int $exchangeId,
        int|string|null $actor = null,
        ?callable $progress = null
    ): array {
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Verifying the HMRC submission and selected archived exchange…', 15);
        if ($exchangeId <= 0) {
            return $this->failure('Select a valid archived HMRC response to reprocess.');
        }
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            return $this->failure('The HMRC submission does not exist.');
        }
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || $ctPeriodId <= 0
            || (int)$submission['company_id'] !== $companyId
            || (int)$submission['accounting_period_id'] !== $accountingPeriodId
            || (int)$submission['ct_period_id'] !== $ctPeriodId) {
            return $this->failure(
                'The selected HMRC submission does not belong to the authorised accounting context.',
                $submissionId,
                $submission
            );
        }
        $expectedState = (string)$submission['protocol_state'];
        $successfulOutcome = in_array((string)$submission['business_outcome'], [
            'sandbox_passed', 'til_validated', 'live_accepted', 'accepted',
        ], true);
        $metadataRepair = $successfulOutcome
            && in_array($expectedState, ['delete_pending', 'closed'], true)
            && $this->receiptReferences->normalise(
                $submission['hmrc_submission_reference_raw'] ?? null
            ) === null;
        $operation = $metadataRepair ? 'poll' : match ($expectedState) {
            'transport_uncertain' => 'submit',
            'awaiting_poll' => 'poll',
            'delete_pending' => 'delete',
            default => '',
        };
        if ($operation === '') {
            return $this->failure(
                'This HMRC submission no longer has a current response that can be reprocessed.',
                $submissionId,
                $submission
            );
        }
        $exchange = \InterfaceDB::fetchOne(
            'SELECT e.*, a.authority AS archive_authority,
                    a.company_id AS archive_company_id,
                    a.accounting_period_id AS archive_accounting_period_id,
                    a.environment AS archive_environment,
                    a.submission_reference AS archive_submission_reference
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.id = :exchange_id
               AND e.authority = :authority
               AND e.hmrc_submission_id = :submission_id
               AND e.operation = :operation
             LIMIT 1',
            [
                'exchange_id' => $exchangeId,
                'authority' => 'hmrc',
                'submission_id' => $submissionId,
                'operation' => $operation,
            ]
        );
        if (!is_array($exchange)) {
            return $this->failure(
                'The selected HMRC exchange does not belong to this submission.',
                $submissionId,
                $submission
            );
        }
        $transactionId = strtoupper(trim((string)($exchange['transaction_id'] ?? '')));
        if ($transactionId === ''
            || (!$metadataRepair
                && $transactionId !== strtoupper(trim((string)($submission['transaction_id'] ?? ''))))
            || strtoupper(trim((string)($exchange['environment'] ?? '')))
                !== strtoupper(trim((string)$submission['environment'])
            )
            || strtolower(trim((string)($exchange['archive_authority'] ?? ''))) !== 'hmrc'
            || (int)($exchange['archive_company_id'] ?? 0) !== (int)$submission['company_id']
            || (int)($exchange['archive_accounting_period_id'] ?? 0) !== (int)$submission['accounting_period_id']
            || strtoupper(trim((string)($exchange['archive_environment'] ?? '')))
                !== strtoupper(trim((string)$submission['environment']))
            || trim((string)($exchange['archive_submission_reference'] ?? ''))
                !== $this->archiveReference($submissionId)) {
            return $this->failure(
                'The archived HMRC response is not the current exchange for this submission.',
                $submissionId,
                $submission
            );
        }
        $exchangeState = (string)($exchange['exchange_state'] ?? '');
        $exchangeOutcome = (string)($exchange['outcome_code'] ?? '');
        if ($metadataRepair
            ? ($exchangeState !== 'succeeded' || $exchangeOutcome !== 'accepted')
            : !in_array($exchangeState, ['failed', 'transport_unknown', 'received'], true)) {
            return $this->failure(
                'This HMRC exchange has already been processed or is not eligible for reprocessing.',
                $submissionId,
                $submission
            );
        }
        $statusCode = (int)($exchange['response_status_code'] ?? 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            return $this->failure(
                'Only an archived HTTP 2xx GovTalk response can be reprocessed.',
                $submissionId,
                $submission
            );
        }

        $report('Checking the archived HMRC response integrity…', 35);
        try {
            $requestEvidence = $this->govTalk->evidenceFileForCompany(
                (int)$submission['company_id'],
                (int)$exchange['id'],
                'request'
            );
            $evidence = $this->govTalk->evidenceFileForCompany(
                (int)$submission['company_id'],
                (int)$exchange['id'],
                'response'
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The archived HMRC response could not be verified: ' . $exception->getMessage(),
                $submissionId,
                $submission
            );
        }
        $exchangeRequestPath = realpath((string)($exchange['request_path'] ?? ''));
        $requestEvidencePath = realpath((string)($requestEvidence['path'] ?? ''));
        $exchangeRequestHash = strtolower(trim((string)($exchange['request_sha256'] ?? '')));
        if (!is_string($exchangeRequestPath)
            || !is_string($requestEvidencePath)
            || strcasecmp($exchangeRequestPath, $requestEvidencePath) !== 0
            || $exchangeRequestHash === ''
            || !hash_equals(
                $exchangeRequestHash,
                strtolower(trim((string)($requestEvidence['sha256'] ?? '')))
            )
            || strtolower(trim((string)($requestEvidence['authority'] ?? ''))) !== 'hmrc') {
            return $this->failure(
                'The archived HMRC request evidence does not match the selected exchange.',
                $submissionId,
                $submission
            );
        }
        $pathColumn = $operation === 'delete' && !$metadataRepair
            ? 'cleanup_response_path'
            : 'response_body_path';
        $hashColumn = $operation === 'delete' && !$metadataRepair
            ? 'cleanup_response_sha256'
            : 'response_sha256';
        $submissionResponseHash = strtolower(trim((string)($submission[$hashColumn] ?? '')));
        $submissionResponsePath = realpath((string)($submission[$pathColumn] ?? ''));
        $evidenceResponsePath = realpath((string)$evidence['path']);
        if ($submissionResponseHash === ''
            || !hash_equals($submissionResponseHash, (string)$evidence['sha256'])
            || strtolower(trim((string)($evidence['authority'] ?? ''))) !== 'hmrc'
            || (int)($evidence['exchange_id'] ?? 0) !== (int)$exchange['id']
            || !is_string($submissionResponsePath)
            || !is_string($evidenceResponsePath)
            || strcasecmp($submissionResponsePath, $evidenceResponsePath) !== 0) {
            return $this->failure(
                'The submission and exchange do not reference the same archived HMRC response.',
                $submissionId,
                $submission
            );
        }
        $responseXml = file_get_contents((string)$evidence['path']);
        if (!is_string($responseXml) || $responseXml === '') {
            return $this->failure(
                'The archived HMRC response is empty.',
                $submissionId,
                $submission
            );
        }

        try {
            $originalSubmissionTransactionId = $this->originalSubmissionTransactionId(
                $submissionId,
                $submission
            );
            $boundConversationTransactionIds = $this->boundConversationTransactionIds(
                $submissionId,
                $submission
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The original HMRC submission exchange could not be verified: '
                    . $exception->getMessage(),
                $submissionId,
                $submission
            );
        }
        $expectedCorrelationId = $operation === 'submit'
            ? ''
            : strtoupper(trim((string)($submission['hmrc_correlation_id'] ?? '')));
        if ($operation !== 'submit' && $expectedCorrelationId === '') {
            return $this->failure(
                'The HMRC conversation has no correlation ID for this archived response.',
                $submissionId,
                $submission
            );
        }
        $exchangeCorrelationId = strtoupper(trim((string)($exchange['correlation_id'] ?? '')));
        if (($operation !== 'submit'
                && ($exchangeCorrelationId === ''
                    || !hash_equals($expectedCorrelationId, $exchangeCorrelationId)))
            || ($operation === 'submit'
                && $expectedCorrelationId !== ''
                && $exchangeCorrelationId !== ''
                && !hash_equals($expectedCorrelationId, $exchangeCorrelationId))) {
            return $this->failure(
                'The selected HMRC exchange correlation ID does not match the conversation.',
                $submissionId,
                $submission
            );
        }

        $report('Reprocessing the archived response without contacting HMRC…', 55);
        try {
            $parsed = $this->transport->parseArchivedResponse(
                $responseXml,
                $operation,
                (string)$submission['environment'],
                $expectedCorrelationId,
                $originalSubmissionTransactionId,
                $transactionId,
                $boundConversationTransactionIds
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The archived HMRC response could not be parsed safely: ' . $exception->getMessage(),
                $submissionId,
                $submission
            );
        }
        $parsedProtocol = (string)($parsed['protocol_state'] ?? 'failed');
        $parsedBusiness = (string)($parsed['business_outcome'] ?? '');
        $allowedProtocols = $metadataRepair ? ['final_response'] : match ($expectedState) {
            'transport_uncertain' => ['acknowledged', 'gateway_rejected', 'final_response'],
            'awaiting_poll' => ['acknowledged', 'final_response', 'submission_error'],
            'delete_pending' => ['deleted', 'submission_error'],
            default => [],
        };
        if (!in_array($parsedProtocol, $allowedProtocols, true)
            || ($parsedProtocol === 'final_response'
                && !in_array($parsedBusiness, ['accepted', 'rejected'], true))
            || ($metadataRepair
                && ($parsedProtocol !== 'final_response' || $parsedBusiness !== 'accepted'))
            || ($parsedProtocol === 'submission_error' && $expectedCorrelationId === '')) {
            return $this->failure(
                'The archived HMRC response did not resolve to a valid current protocol result: '
                    . trim((string)($parsed['error'] ?? 'protocol validation failed.')),
                $submissionId,
                $submission
            );
        }

        $receivedAtValue = trim((string)($exchange['received_at'] ?? ''));
        try {
            if ($receivedAtValue === '') {
                throw new \RuntimeException('Missing response receipt timestamp.');
            }
            $receivedAt = new \DateTimeImmutable(
                $receivedAtValue,
                new \DateTimeZone('UTC')
            );
        } catch (\Throwable) {
            return $this->failure(
                'The archived HMRC response has no verifiable receipt timestamp.',
                $submissionId,
                $submission
            );
        }

        if ($metadataRepair) {
            $reference = $this->receiptReferences->extract(
                (string)($parsed['body_xml'] ?? $responseXml)
            );
            if ($reference === null) {
                return $this->failure(
                    'The verified HMRC success response does not contain one unambiguous document reference.',
                    $submissionId,
                    $submission
                );
            }

            return $this->repairReceiptMetadata(
                $submission,
                $exchange,
                $reference,
                $submissionResponseHash,
                $actor,
                $report
            );
        }

        $changes = $this->reprocessedSubmissionChanges(
            $submission,
            $parsed,
            $statusCode,
            $receivedAt
        );
        if (isset($changes['error'])) {
            return $this->failure(
                (string)$changes['error'],
                $submissionId,
                $submission
            );
        }
        $expectedExchangeState = (string)($exchange['exchange_state'] ?? '');
        $exchangeResponseHash = strtolower(trim((string)(
            $exchange['response_sha256'] ?? ''
        )));
        $report('Applying the verified HMRC response and audit trail…', 80);
        try {
            \InterfaceDB::transaction(function () use (
                $submissionId,
                $exchangeId,
                $expectedExchangeState,
                $exchangeResponseHash,
                $submissionResponseHash,
                $transactionId,
                $expectedState,
                $hashColumn,
                $operation,
                $submission,
                $actor,
                $parsed,
                $changes
            ): void {
                $exchangeClaim = \InterfaceDB::prepareExecute(
                    'UPDATE govtalk_protocol_exchanges
                     SET exchange_state = :claim_state,
                         updated_at = :claim_at
                     WHERE id = :exchange_id
                       AND authority = :authority
                       AND environment = :environment
                       AND hmrc_submission_id = :submission_id
                       AND operation = :operation
                       AND transaction_id = :transaction_id
                       AND exchange_state = :expected_exchange_state
                       AND response_sha256 = :exchange_response_sha256',
                    [
                        'claim_state' => 'prepared',
                        'claim_at' => $this->sqlNow(),
                        'exchange_id' => $exchangeId,
                        'authority' => 'hmrc',
                        'environment' => (string)$submission['environment'],
                        'submission_id' => $submissionId,
                        'operation' => $operation,
                        'transaction_id' => $transactionId,
                        'expected_exchange_state' => $expectedExchangeState,
                        'exchange_response_sha256' => $exchangeResponseHash,
                    ]
                );
                if ($exchangeClaim->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The selected HMRC exchange changed before its response could be reprocessed.'
                    );
                }
                $statement = \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET status = :status,
                         protocol_state = :protocol_state,
                         business_outcome = :business_outcome,
                         idempotency_key = :idempotency_key,
                         hmrc_submission_reference = :submission_reference,
                         hmrc_correlation_id = :correlation_id,
                         response_endpoint = :response_endpoint,
                         poll_interval_seconds = :poll_interval,
                         next_poll_at = :next_poll_at,
                         hmrc_response_code = :response_code,
                         hmrc_response_summary = :response_summary,
                         final_response_at = :final_response_at,
                         cleanup_completed_at = :cleanup_completed_at,
                         cleanup_error = :cleanup_error,
                         recovery_attempts = recovery_attempts + 1,
                         last_recovery_at = :last_recovery_at,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND protocol_state = :expected_state
                       AND transaction_id = :transaction_id
                       AND ' . $hashColumn . ' = :response_sha256',
                    [
                        'status' => $changes['status'],
                        'protocol_state' => $changes['protocol_state'],
                        'business_outcome' => $changes['business_outcome'],
                        'idempotency_key' => $changes['idempotency_key'],
                        'submission_reference' => $changes['hmrc_submission_reference'],
                        'correlation_id' => $changes['hmrc_correlation_id'],
                        'response_endpoint' => $changes['response_endpoint'],
                        'poll_interval' => $changes['poll_interval_seconds'],
                        'next_poll_at' => $changes['next_poll_at'],
                        'response_code' => $changes['hmrc_response_code'],
                        'response_summary' => $changes['hmrc_response_summary'],
                        'final_response_at' => $changes['final_response_at'],
                        'cleanup_completed_at' => $changes['cleanup_completed_at'],
                        'cleanup_error' => $changes['cleanup_error'],
                        'last_recovery_at' => $this->sqlNow(),
                        'updated_at' => $this->sqlNow(),
                        'id' => $submissionId,
                        'expected_state' => $expectedState,
                        'transaction_id' => $transactionId,
                        'response_sha256' => $submissionResponseHash,
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The HMRC submission changed before its response could be reprocessed.'
                    );
                }
                $this->govTalk->completeExchange(
                    'hmrc',
                    (string)$submission['environment'],
                    $transactionId,
                    (string)$changes['exchange_state'],
                    (string)$changes['outcome_code'],
                    (string)$changes['hmrc_response_summary'],
                    (string)$changes['exchange_error'],
                    (string)($changes['hmrc_correlation_id'] ?? ''),
                    (array)($parsed['errors'] ?? [])
                );
                $this->event(
                    $submissionId,
                    (string)$changes['event_level'],
                    (string)$changes['event_message'],
                    [
                        'exchange_id' => $exchangeId,
                        'operation' => $operation,
                        'transaction_id' => $transactionId,
                        'response_transaction_id' => (string)($parsed['response_transaction_id'] ?? ''),
                        'correlation_id' => (string)($changes['hmrc_correlation_id'] ?? ''),
                        'response_endpoint' => (string)($changes['response_endpoint'] ?? ''),
                        'poll_interval_seconds' => $changes['poll_interval_seconds'],
                        'errors' => (array)($parsed['errors'] ?? []),
                    ]
                );
                $this->recordEvidenceOutcome(
                    $submissionId,
                    'hmrc_response_reprocessed',
                    'success',
                    $actor,
                    [
                        'exchange_id' => $exchangeId,
                        'operation' => $operation,
                        'transaction_id' => $transactionId,
                        'response_transaction_id' => (string)($parsed['response_transaction_id'] ?? ''),
                        'protocol_state' => (string)$changes['protocol_state'],
                        'business_outcome' => (string)$changes['business_outcome'],
                    ]
                );
            });
        } catch (\Throwable $exception) {
            return $this->failure(
                'The archived HMRC response could not be reprocessed: ' . $exception->getMessage(),
                $submissionId,
                $this->fetchById($submissionId)
            );
        }
        $this->syncArchiveLifecycle($submissionId);
        $nextActionAt = trim((string)($changes['next_poll_at'] ?? ''));
        $remaining = null;
        if ($nextActionAt !== '') {
            $remaining = max(
                0,
                (new \DateTimeImmutable($nextActionAt, new \DateTimeZone('UTC')))->getTimestamp()
                    - $this->now()->getTimestamp()
            );
        }
        $result = $this->commandResult(
            $submissionId,
            true,
            [],
            in_array((string)$changes['protocol_state'], ['awaiting_poll', 'delete_pending'], true),
            $remaining
        );
        if (in_array((string)$changes['business_outcome'], ['rejected', 'error'], true)
            || (string)$changes['outcome_code'] === 'submission_error') {
            $result['warnings'][] = 'Recorded result from the archived HMRC response '
                . '(no request was sent): ' . (string)$changes['hmrc_response_summary'];
        }

        return $result;
    }

    private function repairReceiptMetadata(
        array $submission,
        array $exchange,
        string $reference,
        string $responseHash,
        int|string|null $actor,
        callable $report
    ): array {
        $submissionId = (int)$submission['id'];
        $exchangeId = (int)$exchange['id'];
        $rawReference = trim((string)($submission['hmrc_submission_reference_raw'] ?? ''));
        $report('Repairing the HMRC receipt metadata and audit trail…', 80);
        try {
            \InterfaceDB::transaction(function () use (
                $submission,
                $exchange,
                $submissionId,
                $exchangeId,
                $reference,
                $responseHash,
                $rawReference,
                $actor
            ): void {
                $statement = \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET hmrc_submission_reference = :reference
                     WHERE id = :id
                       AND company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                       AND ct_period_id = :ct_period_id
                       AND protocol_state = :protocol_state
                       AND business_outcome = :business_outcome
                       AND response_sha256 = :response_sha256
                       AND COALESCE(hmrc_submission_reference, :empty_reference) = :expected_reference',
                    [
                        'reference' => $reference,
                        'id' => $submissionId,
                        'company_id' => (int)$submission['company_id'],
                        'accounting_period_id' => (int)$submission['accounting_period_id'],
                        'ct_period_id' => (int)$submission['ct_period_id'],
                        'protocol_state' => (string)$submission['protocol_state'],
                        'business_outcome' => (string)$submission['business_outcome'],
                        'response_sha256' => $responseHash,
                        'empty_reference' => '',
                        'expected_reference' => $rawReference,
                    ]
                );
                if ($statement->rowCount() !== 1) {
                    throw new \RuntimeException(
                        'The HMRC submission metadata changed before the receipt could be repaired.'
                    );
                }

                $context = [
                    'exchange_id' => $exchangeId,
                    'operation' => 'poll',
                    'transaction_id' => (string)$exchange['transaction_id'],
                    'response_sha256' => $responseHash,
                    'previous_reference_valid' => false,
                    'document_reference' => $reference,
                    'network_request' => false,
                ];
                $this->event(
                    $submissionId,
                    'success',
                    'The HMRC receipt document reference was repaired from verified archived evidence.',
                    $context
                );
                $this->recordEvidenceOutcome(
                    $submissionId,
                    'hmrc_receipt_metadata_repaired',
                    'success',
                    $actor,
                    $context
                );
                $this->recordReceiptMetadataAudit($submission, $actor, $context);
            });
        } catch (\Throwable $exception) {
            return $this->failure(
                'The HMRC receipt metadata could not be repaired: ' . $exception->getMessage(),
                $submissionId,
                $this->fetchById($submissionId)
            );
        }

        $report('The HMRC receipt metadata was repaired without contacting HMRC.', 100);
        return $this->commandResult($submissionId, true);
    }

    private function recordReceiptMetadataAudit(
        array $submission,
        int|string|null $actor,
        array $context
    ): void {
        if (!\InterfaceDB::tableExists('year_end_audit_log')) {
            return;
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO year_end_audit_log (
                company_id, accounting_period_id, action, action_by,
                action_at, new_value_json, notes
             ) VALUES (
                :company_id, :accounting_period_id, :action, :actor,
                :action_at, :details, :notes
             )',
            [
                'company_id' => (int)$submission['company_id'],
                'accounting_period_id' => (int)$submission['accounting_period_id'],
                'action' => 'hmrc_receipt_metadata_repaired',
                'actor' => $this->actor($actor),
                'action_at' => $this->sqlNow(),
                'details' => $this->json($context),
                'notes' => 'A verified archived HMRC success response repaired local receipt metadata; no network request was made.',
            ]
        );
    }

    /** @return array<string,mixed> */
    private function reprocessedSubmissionChanges(
        array $submission,
        array $parsed,
        int $statusCode,
        \DateTimeImmutable $receivedAt
    ): array {
        $protocol = (string)($parsed['protocol_state'] ?? 'failed');
        $business = (string)($parsed['business_outcome'] ?? '');
        $interval = ($parsed['poll_interval'] ?? null) === null
            ? null
            : max(1, (int)$parsed['poll_interval']);
        $endpoint = trim((string)($parsed['response_endpoint'] ?? ''));
        $correlationId = strtoupper(trim((string)($parsed['correlation_id'] ?? '')));
        $summary = trim((string)($parsed['error'] ?? ''));
        $receivedSql = $receivedAt->format('Y-m-d H:i:s');
        $changes = [
            'status' => (string)$submission['status'],
            'protocol_state' => (string)$submission['protocol_state'],
            'business_outcome' => (string)$submission['business_outcome'],
            'idempotency_key' => $submission['idempotency_key'] ?? null,
            'hmrc_submission_reference' => $submission['hmrc_submission_reference'] ?? null,
            'hmrc_correlation_id' => $submission['hmrc_correlation_id'] ?? null,
            'response_endpoint' => $submission['response_endpoint'] ?? null,
            'poll_interval_seconds' => $submission['poll_interval_seconds'] ?? null,
            'next_poll_at' => $submission['next_poll_at'] ?? null,
            'hmrc_response_code' => $statusCode,
            'hmrc_response_summary' => $summary,
            'final_response_at' => $submission['final_response_at'] ?? null,
            'cleanup_completed_at' => $submission['cleanup_completed_at'] ?? null,
            'cleanup_error' => $submission['cleanup_error'] ?? null,
            'exchange_state' => 'failed',
            'outcome_code' => $protocol,
            'exchange_error' => $summary,
            'event_level' => 'warning',
            'event_message' => 'An archived HMRC response was reprocessed.',
        ];

        if ($protocol === 'acknowledged') {
            if ($correlationId === '' || $endpoint === '' || $interval === null) {
                return ['error' => 'The archived HMRC acknowledgement has incomplete polling instructions.'];
            }
            $changes = array_replace($changes, [
                'status' => 'submitting',
                'protocol_state' => 'awaiting_poll',
                'business_outcome' => 'none',
                'hmrc_correlation_id' => $correlationId,
                'response_endpoint' => $endpoint,
                'poll_interval_seconds' => $interval,
                'next_poll_at' => $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s'),
                'hmrc_response_summary' => 'HMRC acknowledgement reprocessed from verified archived evidence; polling is required.',
                'exchange_state' => 'succeeded',
                'outcome_code' => 'acknowledged',
                'exchange_error' => '',
                'event_level' => 'info',
                'event_message' => 'The archived HMRC acknowledgement was verified and the polling conversation was restored.',
            ]);
            return $changes;
        }

        if ($protocol === 'gateway_rejected') {
            $summary = $summary !== '' ? $summary : 'HMRC Gateway rejected the submission.';
            $changes = array_replace($changes, [
                'status' => 'failed',
                'protocol_state' => 'gateway_rejected',
                'business_outcome' => 'error',
                'idempotency_key' => null,
                'hmrc_correlation_id' => null,
                'response_endpoint' => null,
                'next_poll_at' => null,
                'hmrc_response_summary' => $summary,
                'final_response_at' => $receivedSql,
                'exchange_state' => 'rejected',
                'outcome_code' => 'gateway_rejected',
                'exchange_error' => $summary,
                'event_level' => 'error',
                'event_message' => 'An archived response confirmed that HMRC Gateway rejected the submission.',
            ]);
            return $changes;
        }

        if ($protocol === 'final_response') {
            if ($correlationId === '' || $endpoint === '' || $interval === null) {
                return ['error' => 'The archived HMRC final response has incomplete cleanup instructions.'];
            }
            $accepted = $business === 'accepted';
            $outcome = $accepted
                ? match ((string)$submission['environment']) {
                    'TEST' => 'sandbox_passed',
                    'TIL' => 'til_validated',
                    'LIVE' => 'live_accepted',
                    default => 'error',
                }
                : 'rejected';
            $cleanupRequired = !empty($parsed['cleanup_required']);
            $summary = $accepted
                ? 'HMRC accepted the CT600 filing body.'
                : $this->rejectionSummary($summary);
            $reference = $this->receiptReferences->extract((string)($parsed['body_xml'] ?? ''));
            $changes = array_replace($changes, [
                'status' => $accepted ? 'accepted' : 'rejected',
                'protocol_state' => $cleanupRequired ? 'delete_pending' : 'closed',
                'business_outcome' => $outcome,
                'hmrc_submission_reference' => $reference
                    ?? ($submission['hmrc_submission_reference'] ?? null),
                'hmrc_correlation_id' => $correlationId,
                'response_endpoint' => $endpoint,
                'poll_interval_seconds' => $interval,
                'next_poll_at' => $cleanupRequired
                    ? $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s')
                    : null,
                'hmrc_response_summary' => $summary,
                'final_response_at' => $receivedSql,
                'exchange_state' => $accepted ? 'succeeded' : 'rejected',
                'outcome_code' => $accepted ? 'accepted' : 'rejected',
                'exchange_error' => $accepted ? '' : $summary,
                'event_level' => $accepted ? 'success' : 'error',
                'event_message' => $accepted
                    ? 'An archived response confirmed HMRC final acceptance.'
                    : 'An archived response confirmed HMRC final rejection.',
            ]);
            return $changes;
        }

        if ($protocol === 'submission_error') {
            if ($correlationId === '' || $endpoint === '' || $interval === null) {
                return ['error' => 'The archived HMRC protocol error has incomplete follow-on instructions.'];
            }
            $summary = $summary !== '' ? $summary : 'HMRC rejected the latest GovTalk follow-on request.';
            $changes = array_replace($changes, [
                'hmrc_correlation_id' => $correlationId,
                'response_endpoint' => $endpoint,
                'poll_interval_seconds' => $interval,
                'next_poll_at' => $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s'),
                'hmrc_response_summary' => $summary,
                'exchange_state' => 'rejected',
                'outcome_code' => 'submission_error',
                'exchange_error' => $summary,
                'event_level' => 'warning',
                'event_message' => 'An archived HMRC protocol error was verified; the conversation remains open.',
            ]);
            return $changes;
        }

        if ($protocol === 'deleted') {
            $changes = array_replace($changes, [
                'protocol_state' => 'closed',
                'next_poll_at' => null,
                'cleanup_completed_at' => $receivedSql,
                'cleanup_error' => null,
                'hmrc_response_summary' => trim((string)($submission['hmrc_response_summary'] ?? '')),
                'exchange_state' => 'succeeded',
                'outcome_code' => 'deleted',
                'exchange_error' => '',
                'event_level' => 'success',
                'event_message' => 'An archived response confirmed that the HMRC conversation was deleted.',
            ]);
            return $changes;
        }

        return ['error' => 'The archived HMRC response did not contain a supported protocol result.'];
    }

    private function submitMode(
        int $companyId,
        int $ctPeriodId,
        string $mode,
        int|string|null $actor,
        ?callable $progress = null,
        bool $retryGatewayRejection = false
    ): array {
        $report = static function (string $message, int $percent) use ($progress): void {
            if ($progress !== null) {
                $progress($message, $percent);
            }
        };
        $report('Verifying the HMRC environment, credentials and schema…', 12);
        $xmlEnvironment = $this->xmlEnvironment();
        if (!$this->environmentPermitted($mode, $xmlEnvironment)) {
            return $this->failure($xmlEnvironment === 'DISABLED'
                ? 'HMRC XML transmission is disabled in Application API Credentials.'
                : 'The requested HMRC transmission is not permitted by the selected HMRC XML environment.');
        }
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

        $report('Checking the CT Period transport lock and submission history…', 20);
        $pending = $this->firstPendingSubmissionForPeriod($companyId, $ctPeriodId);
        if (is_array($pending)) {
            $fallback = (string)$pending['protocol_state'] === 'transport_uncertain'
                ? 'A prior transmission has an uncertain outcome. Do not submit another return for this CT period.'
                : 'An HMRC conversation is already in progress for this CT period.';
            $message = $this->pendingConversationBlocker($pending, $fallback);
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

        $report('Loading and deeply verifying the prepared CT600 XML artifact…', 30);
        try {
            $package = $this->packagePreparer instanceof \Closure
                ? ($this->packagePreparer)($companyId, $ctPeriodId, $mode)
                : $this->packages->prepareForSubmission(
                    $companyId,
                    $ctPeriodId,
                    $mode
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
        $declaration = (array)($package['approval_declaration'] ?? []);
        $declarationErrors = $this->approvalDeclarationErrors($declaration);
        if ($declarationErrors !== []) {
            return $this->failure(array_merge(
                ['The prepared CT600 artifact has no valid frozen return authorisation.'],
                $declarationErrors
            ));
        }
        $report('Verifying the frozen approval and filing-evidence bundle…', 44);
        try {
            $evidenceBundle = (new FilingEvidenceService())->ensureCurrentBundle(
                $companyId,
                (int)$package['accounting_period_id'],
                $this->actor($actor)
            );
            $preparedEvidenceId = trim((string)(
                $package['source_manifest']['filing_evidence_id'] ?? ''
            ));
            $preparedEvidenceHash = strtolower(trim((string)(
                $package['source_manifest']['filing_evidence_bundle_hash'] ?? ''
            )));
            if ($preparedEvidenceId === ''
                || !hash_equals($preparedEvidenceId, (string)$evidenceBundle['evidence_id'])
                || $preparedEvidenceHash === ''
                || !hash_equals(
                    $preparedEvidenceHash,
                    strtolower((string)($evidenceBundle['bundle_hash'] ?? ''))
                )) {
                return $this->failure(
                    'The prepared CT600 XML belongs to an earlier filing-evidence bundle. '
                    . 'Regenerate it from iXBRL Generation before transmission.'
                );
            }
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
        $gatewayRejection = $this->gatewayRejectionForHashes(
            $companyId,
            $ctPeriodId,
            $mode,
            $manifestHash,
            $bodyHash
        );
        if (is_array($gatewayRejection)) {
            if (!$retryGatewayRejection) {
                return $this->failure(
                    'HMRC definitively rejected this exact filing body before opening a conversation. '
                    . 'Ordinary resubmission is blocked.',
                    (int)$gatewayRejection['id'],
                    $gatewayRejection
                );
            }
            if (!(bool)\AppConfigurationStore::get('developer_options', false)) {
                return $this->failure(
                    'Developer Options must be enabled to retry a definitive HMRC Gateway rejection.',
                    (int)$gatewayRejection['id'],
                    $gatewayRejection
                );
            }
        } elseif ($retryGatewayRejection) {
            return $this->failure(
                'There is no definitive HMRC Gateway rejection for this exact filing body to retry.'
            );
        }
        $testSubmission = null;
        if ($mode === 'LIVE') {
            $report('Verifying Test in Live acceptance for this exact CT600 body…', 52);
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

        $report('Reserving the immutable HMRC submission and transport lock…', 60);
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
                $fallback = (string)$pending['protocol_state'] === 'transport_uncertain'
                    ? 'A prior transmission has an uncertain outcome. Do not submit another return for this CT period.'
                    : 'An HMRC conversation is already in progress for this CT period.';
                $message = $this->pendingConversationBlocker($pending, $fallback);
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
        $report('Building and sending the environment-specific GovTalk wrapper…', 70);
        $result = $this->transport->submit(
            (string)$package['filing_body_xml'],
            (string)$package['utr'],
            $mode,
            GovTalkConversationContext::fromCallbacks(
                'hmrc',
                $mode,
                function (array $request) use (
                    $submissionId,
                    $companyId,
                    $ctPeriodId,
                    $manifestHash,
                    $bodyHash,
                    $package,
                    $actor,
                    $govtalkEvidence,
                    $report
                ): array {
                $report('Rechecking the prepared CT600 body at the pre-send boundary…', 74);
                // Close the small prepare/send race: the approved source basis
                // must still be byte-identical at the pre-send boundary.
                $current = $this->safeCurrentManifest($companyId, $ctPeriodId, [
                    'filing_evidence_id' => (string)($package['evidence_id'] ?? ''),
                ]);
                if (
                    empty($current['ok'])
                    || !hash_equals($manifestHash, (string)($current['source_manifest_sha256'] ?? ''))
                    || !hash_equals($bodyHash, (string)($current['body_sha256'] ?? ''))
                ) {
                    throw new \RuntimeException(
                        'The filing source changed after preparation; no HMRC request was sent.'
                    );
                }
                $report('Archiving the exact GovTalk submission request before sending…', 78);
                $artifact = $this->govTalk->captureRequest(
                    $this->hmrcGovTalkIdentity(
                        $submissionId,
                        'submit'
                    ),
                    $request
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
                $this->govTalk->markSendStarted(
                    'hmrc',
                    (string)$request['environment'],
                    (string)$request['transaction_id']
                );
                $this->event($submissionId, 'info', 'GovTalk request persisted before transmission.', [
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                    'request_bytes' => (int)$request['request_bytes'],
                ]);
                (new FilingEvidenceService())->completeArtifact((int)$govtalkEvidence['id'], [
                    'status' => 'generated',
                    'filename' => basename((string)$artifact['path']),
                    'path' => $artifact['path'],
                    'sha256' => (string)$request['request_sha256'],
                    'schema_identity' => 'GovTalk Document Submission Protocol 2.0 / CT/5',
                    'validation_status' => 'passed',
                    'identifier_embedded' => true,
                    'metadata' => ['submission_id' => $submissionId, 'persisted_exact_sha256' => $artifact['sha256']],
                ]);
                return $artifact;
                },
                function (array $response) use ($submissionId, &$capturedResponse, $report): array {
                    $report('Capturing and verifying the HMRC submission response…', 88);
                    $capturedResponse = $this->govTalk->captureResponse(
                        $this->hmrcGovTalkIdentity(
                            $submissionId,
                            'submit'
                        ),
                        $response
                    );
                    return $capturedResponse;
                }
            ),
            (string)$govtalkEvidence['transaction_hex']
        );
        $report('Recording the HMRC outcome and submission evidence…', 95);
        $this->completeHmrcGovTalkResult($mode, $result);
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
            // while it is locked so two callers cannot reserve and transmit
            // concurrent original returns for the same CT period.
            $pending = $this->firstPendingSubmissionForPeriod(
                (int)$package['company_id'],
                (int)$package['ct_period_id']
            );
            if (is_array($pending)) {
                throw new \RuntimeException($this->pendingConversationBlocker(
                    $pending,
                    'An HMRC conversation is already in progress for this CT period.'
                ));
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
                    evidence_bundle_id, company_id, accounting_period_id, ct_period_id,
                    hmrc_ct_filing_approval_id, hmrc_ct_filing_approval_hash, mode, environment,
                    status, protocol_state, business_outcome, submission_type,
                    accounts_ixbrl_path, accounts_run_id, accounts_artifact_id,
                    accounts_validation_run_id, accounts_sha256,
                    computations_ixbrl_path, computation_run_id,
                    computation_validation_run_id, computations_sha256,
                    year_end_locked_at, package_hash, idempotency_key, irmark,
                    schema_version, body_sha256, ct600_sha256, validation_json,
                    source_manifest_json, source_manifest_sha256, test_submission_id,
                    declarant_name, declarant_status, declaration_confirmed,
                    authority_confirmed, authority_confirmed_at, authority_confirmed_by,
                    supplementary_scope_confirmed, original_unfiled_confirmed,
                    declaration_approved_at, declaration_approved_by,
                    approved_package_hash, prepared_by, created_at, updated_at
                 ) VALUES (
                    :evidence_bundle_id, :company_id, :accounting_period_id, :ct_period_id,
                    :hmrc_ct_filing_approval_id, :hmrc_ct_filing_approval_hash, :mode, :environment,
                    :status, :protocol_state, :business_outcome, :submission_type,
                    :accounts_path, :accounts_run_id, :accounts_artifact_id,
                    :accounts_validation_run_id, :accounts_sha256,
                    :computations_path, :computation_run_id,
                    :computation_validation_run_id, :computations_sha256,
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
                    'hmrc_ct_filing_approval_id' => $package['hmrc_ct_filing_approval_id'],
                    'hmrc_ct_filing_approval_hash' => $package['hmrc_ct_filing_approval_hash'],
                    'mode' => $mode,
                    'environment' => $mode,
                    'status' => 'ready',
                    'protocol_state' => 'ready',
                    'business_outcome' => 'none',
                    'submission_type' => 'original',
                    'accounts_path' => $package['accounts_ixbrl_path'],
                    'accounts_run_id' => $package['accounts_run_id'],
                    'accounts_artifact_id' => $package['accounts_artifact_id'],
                    'accounts_validation_run_id' => $package['accounts_validation_run_id'],
                    'accounts_sha256' => $package['accounts_sha256'],
                    'computations_path' => $package['computations_ixbrl_path'],
                    'computation_run_id' => $package['computation_run_id'],
                    'computation_validation_run_id' => $package['computation_validation_run_id'],
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
                    'declarant_name' => (string)$declaration['declarant_name'],
                    'declarant_status' => (string)$declaration['declarant_status'],
                    'declaration_confirmed' => !empty($declaration['declaration_confirmed']) ? 1 : 0,
                    'authority_confirmed' => !empty($declaration['authority_confirmed']) ? 1 : 0,
                    'authority_confirmed_at' => (string)$declaration['declaration_at'],
                    'authority_confirmed_by' => (string)($declaration['approved_by'] ?? ''),
                    'supplementary_scope_confirmed' => 1,
                    'original_unfiled_confirmed' => !empty($declaration['original_unfiled_confirmed']) ? 1 : 0,
                    'declaration_approved_at' => (string)($declaration['approved_at'] ?? $declaration['declaration_at']),
                    'declaration_approved_by' => (string)($declaration['approved_by'] ?? ''),
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
            $name = $this->transactionEvidenceFilename(
                $wasPoll ? 'poll' : 'submit',
                (string)($result['transaction_id'] ?? $current['transaction_id'] ?? ''),
                'response'
            );
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
        if ($protocol === 'gateway_rejected') {
            $messages = $this->gatewayRejectionMessages((array)($result['errors'] ?? []));
            $summary = $messages !== []
                ? implode(' ', $messages)
                : (string)($result['error'] ?? 'HMRC Gateway rejected the submission.');
            $this->updateSubmission($submissionId, [
                'status' => 'failed',
                'protocol_state' => 'gateway_rejected',
                'business_outcome' => 'error',
                'hmrc_correlation_id' => null,
                'response_endpoint' => null,
                'next_poll_at' => null,
                'hmrc_response_code' => (int)($result['status_code'] ?? 0) ?: null,
                'hmrc_response_summary' => $summary,
                'response_headers_json' => $this->json((array)($result['headers'] ?? [])),
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
                'final_response_at' => $this->sqlNow(),
                'idempotency_key' => null,
            ]);
            $this->event(
                $submissionId,
                'error',
                'HMRC Gateway rejected the submission before opening a filing conversation.',
                ['errors' => (array)($result['errors'] ?? []), 'remediation' => $messages]
            );
            $this->recordEvidenceOutcome(
                $submissionId,
                'hmrc_gateway_rejected',
                'error',
                $actor,
                ['errors' => (array)($result['errors'] ?? []), 'remediation' => $messages]
            );
            return $this->commandResult($submissionId, false, $messages);
        }
        if ($protocol === 'acknowledged') {
            $interval = max(1, (int)($result['poll_interval'] ?? 10));
            $receivedAt = $this->exchangeReceivedAt(
                (string)($result['transaction_id'] ?? ''),
                $this->now()
            );
            $nextPoll = $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s');
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
            $interval = max(1, (int)($result['poll_interval'] ?? 10));
            $receivedAt = $this->exchangeReceivedAt(
                (string)($result['transaction_id'] ?? ''),
                $this->now()
            );
            $cleanupRequired = !empty($result['cleanup_required']);
            $rejectionSummary = $accepted
                ? ''
                : $this->rejectionSummary((string)($result['error'] ?? ''));
            $nextAction = $cleanupRequired
                ? $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s')
                : null;
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
                'protocol_state' => $cleanupRequired ? 'delete_pending' : 'closed',
                'business_outcome' => $outcome,
                'hmrc_correlation_id' => (string)($result['correlation_id'] ?? ''),
                'response_endpoint' => (string)($result['response_endpoint'] ?? ''),
                'poll_interval_seconds' => $interval,
                'hmrc_submission_reference' => $this->receiptReferences->extract(
                    (string)($result['body_xml'] ?? '')
                ),
                'hmrc_response_code' => (int)($result['status_code'] ?? 0) ?: null,
                'hmrc_response_summary' => $accepted
                    ? 'HMRC accepted the CT600 filing body.'
                    : $rejectionSummary,
                'response_headers_json' => $this->json((array)($result['headers'] ?? [])),
                'response_body_path' => $responseArtifact['path'] ?? null,
                'response_sha256' => $responseArtifact['sha256'] ?? null,
                'final_response_at' => $receivedAt->format('Y-m-d H:i:s'),
                'next_poll_at' => $nextAction,
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
            return $this->commandResult(
                $submissionId,
                $accepted,
                $accepted ? [] : [$rejectionSummary],
                $cleanupRequired,
                $cleanupRequired
                    ? max(0, $receivedAt->getTimestamp() + $interval - $this->now()->getTimestamp())
                    : null
            );
        }

        $message = trim((string)($result['error'] ?? 'HMRC Transaction Engine rejected the request.'));
        if ($wasPoll) {
            $current = $this->fetchById($submissionId);
            $interval = max(1, (int)(
                $result['poll_interval']
                    ?? $current['poll_interval_seconds']
                    ?? 10
            ));
            $receivedAt = $this->exchangeReceivedAt(
                (string)($result['transaction_id'] ?? ''),
                $this->now()
            );
            $responseEndpoint = trim((string)($result['response_endpoint'] ?? ''));
            $this->updateSubmission($submissionId, [
                'protocol_state' => 'awaiting_poll',
                'response_endpoint' => $responseEndpoint !== ''
                    ? $responseEndpoint
                    : ($current['response_endpoint'] ?? null),
                'poll_interval_seconds' => $interval,
                'next_poll_at' => $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s'),
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
                'next_poll_at' => null,
                'cleanup_completed_at' => $this->sqlNow(),
            ]);
            return $this->commandResult($submissionId, true);
        }
        try {
            $originalSubmissionTransactionId = $this->originalSubmissionTransactionId(
                $submissionId,
                $submission
            );
            $boundConversationTransactionIds = $this->boundConversationTransactionIds(
                $submissionId,
                $submission
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'The original HMRC submission exchange could not be verified: '
                    . $exception->getMessage(),
                $submissionId,
                $submission
            );
        }

        $previousAttempt = (int)($submission['cleanup_attempts'] ?? 0);
        $attempt = $previousAttempt + 1;
        $capturedResponse = null;
        $result = $this->transport->delete(
            $correlationId,
            (string)($submission['response_endpoint'] ?? ''),
            (string)$submission['environment'],
            GovTalkConversationContext::fromCallbacks(
                'hmrc',
                (string)$submission['environment'],
                function (array $request) use ($submissionId, $previousAttempt, $attempt, $actor): array {
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
                $artifact = $this->govTalk->captureRequest(
                    $this->hmrcGovTalkIdentity(
                        $submissionId,
                        'delete'
                    ),
                    $request
                );
                $this->govTalk->markSendStarted(
                    'hmrc',
                    (string)$request['environment'],
                    (string)$request['transaction_id']
                );
                $this->event($submissionId, 'info', 'HMRC delete request persisted before transmission.', [
                    'attempt' => $attempt,
                    'request_path' => $artifact['path'],
                    'request_sha256' => (string)$request['request_sha256'],
                ]);
                return $artifact;
                },
                function (array $response) use ($submissionId, $attempt, &$capturedResponse): array {
                    $capturedResponse = $this->govTalk->captureResponse(
                        $this->hmrcGovTalkIdentity(
                            $submissionId,
                            'delete'
                        ),
                        $response
                    );
                    return $capturedResponse;
                }
            ),
            $originalSubmissionTransactionId,
            null,
            $boundConversationTransactionIds
        );
        $this->completeHmrcGovTalkResult((string)$submission['environment'], $result);

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
            $current = $this->fetchById($submissionId);
            $responseArtifact = $this->storeArtifact(
                $submissionId,
                $this->transactionEvidenceFilename(
                    'delete',
                    (string)($result['transaction_id'] ?? $current['transaction_id'] ?? ''),
                    'response'
                ),
                (string)$result['response_xml']
            );
        }
        if (!empty($result['success']) && (string)($result['protocol_state'] ?? '') === 'deleted') {
            $this->updateSubmission($submissionId, [
                'protocol_state' => 'closed',
                'next_poll_at' => null,
                'cleanup_completed_at' => $this->sqlNow(),
                'cleanup_response_path' => $responseArtifact['path'] ?? null,
                'cleanup_response_sha256' => $responseArtifact['sha256'] ?? null,
                'cleanup_error' => null,
            ]);
            $this->event($submissionId, 'success', 'HMRC Transaction Engine conversation was deleted.');
            return $this->commandResult($submissionId, true);
        }

        $message = trim((string)($result['error'] ?? 'HMRC conversation cleanup failed.'));
        $interval = max(1, (int)(
            $result['poll_interval']
                ?? $submission['poll_interval_seconds']
                ?? 10
        ));
        $receivedAt = $this->exchangeReceivedAt(
            (string)($result['transaction_id'] ?? ''),
            $this->now()
        );
        $responseEndpoint = trim((string)($result['response_endpoint'] ?? ''));
        $this->updateSubmission($submissionId, [
            'protocol_state' => 'delete_pending',
            'response_endpoint' => $responseEndpoint !== ''
                ? $responseEndpoint
                : ($submission['response_endpoint'] ?? null),
            'poll_interval_seconds' => $interval,
            'next_poll_at' => $receivedAt->modify('+' . $interval . ' seconds')->format('Y-m-d H:i:s'),
            'cleanup_response_path' => $responseArtifact['path'] ?? null,
            'cleanup_response_sha256' => $responseArtifact['sha256'] ?? null,
            'cleanup_error' => $message,
        ]);
        $this->event($submissionId, 'warning', 'HMRC final result is recorded, but conversation cleanup must be retried.', [
            'error' => $message,
        ]);
        return $this->commandResult($submissionId, false, [$message], true, $interval);
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
        foreach ([
            'accounts_artifact_id' => 'accounts artifact',
            'accounts_validation_run_id' => 'accounts validation',
            'computation_validation_run_id' => 'computation validation',
        ] as $field => $label) {
            if ((int)($package[$field] ?? 0) <= 0) {
                return ['ok' => false, 'errors' => [
                    'The CT600 package has no immutable ' . $label . ' identity.',
                ]];
            }
        }
        $hmrcApprovalHash = strtolower(trim((string)($package['hmrc_ct_filing_approval_hash'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $hmrcApprovalHash) !== 1) {
            return ['ok' => false, 'errors' => [
                'The CT600 package has no valid HMRC Corporation Tax approval fingerprint.',
            ]];
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
            'accounts_artifact_id' => isset($package['accounts_artifact_id']) ? (int)$package['accounts_artifact_id'] : null,
            'accounts_validation_run_id' => isset($package['accounts_validation_run_id']) ? (int)$package['accounts_validation_run_id'] : null,
            'accounts_run_id' => isset($package['accounts_run_id']) ? (int)$package['accounts_run_id'] : null,
            'accounts_sha256' => $package['accounts_sha256'] ?? null,
            'computations_ixbrl_path' => $package['computations_ixbrl_path'] ?? $package['computations_path'] ?? null,
            'computation_run_id' => isset($package['computation_run_id']) ? (int)$package['computation_run_id'] : null,
            'computation_validation_run_id' => isset($package['computation_validation_run_id']) ? (int)$package['computation_validation_run_id'] : null,
            'computations_sha256' => $package['computations_sha256'] ?? null,
            'year_end_locked_at' => $package['year_end_locked_at'] ?? null,
            'irmark' => (string)($package['irmark'] ?? ''),
            'schema_version' => (string)($package['schema_version'] ?? ''),
            'validation' => (array)($package['validation'] ?? []),
            'approval_declaration' => (array)($package['approval_declaration'] ?? []),
            'filing_approval_id' => (int)($package['filing_approval_id'] ?? 0),
            'filing_approval_hash' => (string)($package['filing_approval_hash'] ?? ''),
            'hmrc_ct_filing_approval_id' => isset($package['hmrc_ct_filing_approval_id'])
                ? (int)$package['hmrc_ct_filing_approval_id'] ?: null
                : null,
            'hmrc_ct_filing_approval_hash' => $hmrcApprovalHash,
            'errors' => [],
            'warnings' => (array)($package['warnings'] ?? []),
        ]);
    }

    private function safeCurrentManifest(int $companyId, int $ctPeriodId, array $filingSnapshot = []): array
    {
        return $this->resolveCurrentManifest($companyId, $ctPeriodId, $filingSnapshot, true);
    }

    private function safeCurrentManifestForStatus(
        int $companyId,
        int $ctPeriodId,
        array $filingSnapshot = []
    ): array {
        return $this->resolveCurrentManifest($companyId, $ctPeriodId, $filingSnapshot, false);
    }

    private function resolveCurrentManifest(
        int $companyId,
        int $ctPeriodId,
        array $filingSnapshot,
        bool $deep
    ): array
    {
        try {
            if ($this->manifestResolver instanceof \Closure) {
                $current = ($this->manifestResolver)($companyId, $ctPeriodId);
            } else {
                $artifacts = new Ct600GenerationService($this->packages);
                $current = $deep
                    ? $artifacts->currentManifest($companyId, $ctPeriodId)
                    : $artifacts->currentManifestForStatus($companyId, $ctPeriodId);
            }
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
        $filingEvidenceId = trim((string)($filingSnapshot['filing_evidence_id'] ?? ''));
        if ($filingEvidenceId !== '') {
            $manifest['filing_evidence_id'] = $filingEvidenceId;
        }
        $manifestHash = hash('sha256', $this->canonicalJson($manifest));
        $provided = strtolower(trim((string)($current['source_manifest_sha256'] ?? '')));
        if ($provided !== '' && !hash_equals($manifestHash, $provided)) {
            return ['ok' => false, 'errors' => ['The current source-manifest hash is inconsistent.']];
        }
        // Building a FilingEvidence bundle re-runs the complete accounts
        // evidence pipeline. That belongs to submission preparation, not the
        // read-only status card: the package itself is still evidence-bound
        // immediately before transmission.
        $bodyHash = strtolower(trim((string)($current['body_sha256'] ?? '')));
        if ($bodyHash !== '' && !preg_match('/^[a-f0-9]{64}$/D', $bodyHash)) {
            return ['ok' => false, 'errors' => ['The current CT600 body hash is invalid.']];
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

    private function matchesSuccessfulTest(
        ?array $row,
        string $manifestHash,
        string $bodyHash,
        string $environment
    ): bool
    {
        $expectedOutcome = $environment === 'TEST' ? 'sandbox_passed' : 'til_validated';

        return is_array($row)
            && (string)($row['business_outcome'] ?? '') === $expectedOutcome
            && (string)($row['protocol_state'] ?? '') === 'closed'
            && $manifestHash !== ''
            && $bodyHash !== ''
            && hash_equals($manifestHash, (string)($row['source_manifest_sha256'] ?? ''))
            && hash_equals($bodyHash, (string)($row['body_sha256'] ?? ''));
    }

    private function xmlEnvironment(): string
    {
        $mode = $this->xmlEnvironmentResolver instanceof \Closure
            ? ($this->xmlEnvironmentResolver)()
            : \eel_accounts\Store\AccountingConfigurationStore::hmrcXmlMode();
        $mode = strtoupper(trim((string)$mode));

        return in_array($mode, ['TEST', 'LIVE'], true) ? $mode : 'DISABLED';
    }

    private function environmentPermitted(string $environment, string $xmlEnvironment): bool
    {
        $environment = strtoupper(trim($environment));

        return ($xmlEnvironment === 'TEST' && $environment === 'TEST')
            || ($xmlEnvironment === 'LIVE' && in_array($environment, ['TIL', 'LIVE'], true));
    }

    /** @return array<string,mixed> */
    private function disabledConfiguration(): array
    {
        return [
            'ready' => false,
            'credentials_configured' => false,
            'environment' => 'DISABLED',
            'credential_environment' => 'DISABLED',
            'class' => '',
            'endpoint' => '',
            'poll_endpoint' => '',
            'statutory' => false,
            'blockers' => ['HMRC XML transmission is disabled in Application API Credentials.'],
        ];
    }

    private function approvalDeclarationErrors(array $declaration): array
    {
        $errors = [];
        $name = trim((string)($declaration['declarant_name'] ?? ''));
        $status = trim((string)($declaration['declarant_status'] ?? ''));
        if ($name === '') {
            $errors[] = 'The frozen declarant name is missing.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'The frozen declarant name is too long.';
        }
        if ($status === '') {
            $errors[] = 'The frozen declarant capacity or status is missing.';
        } elseif (mb_strlen($status) > 64) {
            $errors[] = 'The frozen declarant capacity or status is too long.';
        }
        if (trim((string)($declaration['declaration_at'] ?? '')) === '') {
            $errors[] = 'The frozen Corporation Tax return authorisation date is missing.';
        }
        if (empty($declaration['authority_confirmed'])) {
            $errors[] = 'The frozen authority-to-file confirmation is missing.';
        }
        if (empty($declaration['declaration_confirmed'])) {
            $errors[] = 'The frozen Corporation Tax return declaration is missing.';
        }
        if (empty($declaration['original_unfiled_confirmed'])) {
            $errors[] = 'The frozen original-return confirmation is missing.';
        }

        return $errors;
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

    /** @return array<int,list<array<string,mixed>>> */
    private function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY id DESC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        $indexed = [];
        foreach ($rows as $row) {
            $normalised = $this->normaliseSubmission($row);
            $indexed[(int)$normalised['ct_period_id']][] = $normalised;
        }
        return $indexed;
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

    private function firstModeForBody(array $rows, string $mode, string $bodyHash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $bodyHash)) {
            return null;
        }
        foreach ($rows as $row) {
            if ((string)$row['environment'] === $mode
                && hash_equals($bodyHash, (string)($row['body_sha256'] ?? ''))) {
                return $row;
            }
        }

        return null;
    }

    private function gatewayRejectionForHashes(
        int $companyId,
        int $ctPeriodId,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id
               AND ct_period_id = :ct_period_id
               AND environment = :environment
               AND source_manifest_sha256 = :manifest_hash
               AND body_sha256 = :body_hash
               AND protocol_state = :protocol_state
             ORDER BY id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'ct_period_id' => $ctPeriodId,
                'environment' => strtoupper(trim($mode)),
                'manifest_hash' => $manifestHash,
                'body_hash' => $bodyHash,
                'protocol_state' => 'gateway_rejected',
            ]
        );

        return is_array($row) ? $this->normaliseSubmission($row) : null;
    }

    private function successfulTestForHashesInRows(
        array $rows,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        foreach ($rows as $row) {
            if ((string)($row['environment'] ?? '') === 'TIL'
                && (string)($row['business_outcome'] ?? '') === 'til_validated'
                && (string)($row['protocol_state'] ?? '') === 'closed'
                && hash_equals($manifestHash, (string)($row['source_manifest_sha256'] ?? ''))
                && hash_equals($bodyHash, (string)($row['body_sha256'] ?? ''))) {
                return $row;
            }
        }
        return null;
    }

    private function gatewayRejectionForHashesInRows(
        array $rows,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        foreach ($rows as $row) {
            if ((string)($row['environment'] ?? '') === strtoupper(trim($mode))
                && (string)($row['protocol_state'] ?? '') === 'gateway_rejected'
                && hash_equals($manifestHash, (string)($row['source_manifest_sha256'] ?? ''))
                && hash_equals($bodyHash, (string)($row['body_sha256'] ?? ''))) {
                return $row;
            }
        }
        return null;
    }

    /** @return array{dependencies:list<array{label:string,ready:bool,message:string,detail?:string}>,return?:array<string,mixed>,accounts?:array<string,mixed>,computations?:array<string,mixed>} */
    private function filingSnapshot(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        array $manifest = []
    ): array
    {
        if ($this->filingReadinessResolver instanceof \Closure) {
            return ['dependencies' => (array)($this->filingReadinessResolver)($companyId, $accountingPeriodId, $ctPeriodId)];
        }

        $readiness = (array)($manifest['readiness'] ?? []);
        if (!empty($readiness['ready'])) {
            $approval = [
                'state' => 'current',
                'approval' => (array)($readiness['approval'] ?? []),
                'errors' => [],
            ];
            $basis = (array)(($readiness['return'] ?? [])['filing_model'] ?? []);
            $accounts = (array)($readiness['accounts'] ?? []);
            $computations = (array)($readiness['computation'] ?? []);
        } else {
            $approval = $this->safeReadinessCheck(
                fn(): array => (new IxbrlAccountsFilingApprovalService())->statusForReadModel(
                    $companyId,
                    $accountingPeriodId
                ),
                'The current disclosures and filing basis could not be verified.'
            );
            $basis = $this->safeReadinessCheck(
                fn(): array => (new CtPeriodFilingModelService())->buildForStatus(
                    $companyId,
                    $accountingPeriodId,
                    $ctPeriodId
                ),
                'The current CT-period filing basis could not be verified.'
            );
            $accounts = $this->safeReadinessCheck(
                fn(): array => $this->packages->locateAccountsIxbrlForStatus($companyId, $accountingPeriodId),
                'The accounts iXBRL artifact could not be verified.'
            );
            $computations = $this->safeReadinessCheck(
                fn(): array => $this->packages->locateComputationsIxbrlForStatus($companyId, $ctPeriodId),
                'The computations iXBRL artifact could not be verified.'
            );
        }
        $return = $manifest !== []
            ? $manifest
            : $this->safeReadinessCheck(
                fn(): array => (new Ct600GenerationService($this->packages))->currentManifestForStatus(
                    $companyId,
                    $ctPeriodId
                ),
                'The current CT600 source model could not be verified.'
            );
        $approvalReady = (string)($approval['state'] ?? '') === 'current';
        $basisReady = !empty($basis['available']);
        $returnReady = !empty($return['ok']);
        $artifactsReady = !empty($accounts['ok']) && !empty($computations['ok']);
        $artifactErrors = array_values(array_unique(array_filter(array_merge(
            array_map('strval', (array)($accounts['errors'] ?? [])),
            array_map('strval', (array)($computations['errors'] ?? []))
        ), static fn(string $error): bool => trim($error) !== '')));

        return [
            'return' => $return,
            'accounts' => $accounts,
            'computations' => $computations,
            'dependencies' => [[
                'label' => 'Disclosures and filing basis',
                'ready' => $approvalReady,
                'message' => $approvalReady ? '' : $this->firstDiagnostic(
                    $approval,
                    'Approve the current disclosures and filing basis before preparing CT filing output.'
                ),
            ], [
                'label' => 'CT-period filing basis',
                'ready' => $basisReady,
                'message' => $basisReady ? '' : $this->firstDiagnostic(
                    $basis,
                    'A current approved CT-period filing basis is required.'
                ),
            ], [
                'label' => 'CT600 source model',
                'ready' => $returnReady,
                'message' => $returnReady ? '' : $this->firstDiagnostic(
                    $return,
                    'The current CT600 source model is not ready.'
                ),
            ], [
                'label' => 'Filing iXBRL artifacts',
                'ready' => $artifactsReady,
                'message' => $artifactsReady ? '' : 'The current filing iXBRL artifacts are not ready.',
                'detail' => $artifactsReady ? '' : (string)($artifactErrors[0] ?? 'The filing iXBRL artifacts could not be verified.'),
            ]],
        ];
    }

    private function firstDiagnostic(array $result, string $fallback): string
    {
        foreach ((array)($result['errors'] ?? []) as $error) {
            $error = trim((string)$error);
            if ($error !== '') {
                return $error;
            }
        }

        return $fallback;
    }

    /** @param callable(): array<string,mixed> $check */
    private function safeReadinessCheck(callable $check, string $fallback): array
    {
        try {
            return (array)$check();
        } catch (\Throwable $exception) {
            return ['ok' => false, 'available' => false, 'state' => 'error', 'errors' => [$fallback, $exception->getMessage()]];
        }
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

    private function originalSubmissionTransactionId(
        int $submissionId,
        array $submission
    ): string {
        if (!$this->govTalk->schemaReady()) {
            throw new \RuntimeException('The GovTalk exchange ledger is unavailable.');
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT e.id, e.transaction_id, e.correlation_id, e.environment,
                    e.request_path, e.request_sha256,
                    a.authority AS archive_authority,
                    a.company_id AS archive_company_id,
                    a.accounting_period_id AS archive_accounting_period_id,
                    a.environment AS archive_environment,
                    a.submission_reference AS archive_submission_reference
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.authority = :authority
               AND e.hmrc_submission_id = :submission_id
               AND e.operation = :operation
               AND e.request_path IS NOT NULL
             ORDER BY e.id',
            [
                'authority' => 'hmrc',
                'submission_id' => $submissionId,
                'operation' => 'submit',
            ]
        );
        if (count($rows) !== 1) {
            throw new \RuntimeException(
                'Exactly one original submit exchange is required for this conversation.'
            );
        }
        $row = $rows[0];
        if (strtolower(trim((string)($row['archive_authority'] ?? ''))) !== 'hmrc'
            || (int)($row['archive_company_id'] ?? 0) !== (int)$submission['company_id']
            || (int)($row['archive_accounting_period_id'] ?? 0)
                !== (int)$submission['accounting_period_id']
            || strtoupper(trim((string)($row['archive_environment'] ?? '')))
                !== strtoupper(trim((string)($submission['environment'] ?? '')))
            || trim((string)($row['archive_submission_reference'] ?? ''))
                !== $this->archiveReference($submissionId)) {
            throw new \RuntimeException(
                'The original submit exchange archive identity does not match the submission.'
            );
        }
        if (strtoupper(trim((string)($row['environment'] ?? '')))
            !== strtoupper(trim((string)($submission['environment'] ?? '')))) {
            throw new \RuntimeException(
                'The original submit exchange belongs to a different HMRC environment.'
            );
        }
        $transactionId = strtoupper(trim((string)($row['transaction_id'] ?? '')));
        if (preg_match('/^[0-9A-F]{1,32}$/D', $transactionId) !== 1) {
            throw new \RuntimeException('The original submit transaction ID is invalid.');
        }
        $requestEvidence = $this->govTalk->evidenceFileForCompany(
            (int)$submission['company_id'],
            (int)$row['id'],
            'request'
        );
        $exchangeRequestPath = realpath((string)($row['request_path'] ?? ''));
        $evidenceRequestPath = realpath((string)($requestEvidence['path'] ?? ''));
        $submissionRequestPath = realpath((string)($submission['request_body_path'] ?? ''));
        $exchangeRequestHash = strtolower(trim((string)($row['request_sha256'] ?? '')));
        if (!is_string($exchangeRequestPath)
            || !is_string($evidenceRequestPath)
            || !is_string($submissionRequestPath)
            || strcasecmp($exchangeRequestPath, $evidenceRequestPath) !== 0
            || strcasecmp($submissionRequestPath, $evidenceRequestPath) !== 0
            || $exchangeRequestHash === ''
            || !hash_equals(
                $exchangeRequestHash,
                strtolower(trim((string)($requestEvidence['sha256'] ?? '')))
            )
            || strtolower(trim((string)($requestEvidence['authority'] ?? ''))) !== 'hmrc') {
            throw new \RuntimeException(
                'The original submit request evidence does not match the immutable exchange.'
            );
        }
        $submissionCorrelation = strtoupper(trim((string)(
            $submission['hmrc_correlation_id'] ?? ''
        )));
        $exchangeCorrelation = strtoupper(trim((string)($row['correlation_id'] ?? '')));
        if ($submissionCorrelation !== ''
            && $exchangeCorrelation !== ''
            && !hash_equals($submissionCorrelation, $exchangeCorrelation)) {
            throw new \RuntimeException(
                'The original submit exchange correlation ID does not match the conversation.'
            );
        }

        return $transactionId;
    }

    /** @return list<string> */
    private function boundConversationTransactionIds(
        int $submissionId,
        array $submission
    ): array {
        if (!$this->govTalk->schemaReady()) {
            throw new \RuntimeException('The GovTalk exchange ledger is unavailable.');
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT e.id, e.operation, e.environment, e.transaction_id, e.correlation_id,
                    e.request_path, e.request_sha256,
                    a.authority AS archive_authority,
                    a.company_id AS archive_company_id,
                    a.accounting_period_id AS archive_accounting_period_id,
                    a.environment AS archive_environment,
                    a.submission_reference AS archive_submission_reference
             FROM govtalk_protocol_exchanges e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.authority = :authority
               AND e.hmrc_submission_id = :submission_id
             ORDER BY e.id',
            ['authority' => 'hmrc', 'submission_id' => $submissionId]
        );
        if ($rows === []) {
            throw new \RuntimeException('The HMRC conversation has no bound exchange ledger.');
        }
        $environment = strtoupper(trim((string)($submission['environment'] ?? '')));
        $correlationId = strtoupper(trim((string)(
            $submission['hmrc_correlation_id'] ?? ''
        )));
        $transactionIds = [];
        foreach ($rows as $row) {
            if (!in_array((string)($row['operation'] ?? ''), ['submit', 'poll', 'delete'], true)
                || strtoupper(trim((string)($row['environment'] ?? ''))) !== $environment
                || strtolower(trim((string)($row['archive_authority'] ?? ''))) !== 'hmrc'
                || (int)($row['archive_company_id'] ?? 0) !== (int)$submission['company_id']
                || (int)($row['archive_accounting_period_id'] ?? 0)
                    !== (int)$submission['accounting_period_id']
                || strtoupper(trim((string)($row['archive_environment'] ?? ''))) !== $environment
                || trim((string)($row['archive_submission_reference'] ?? ''))
                    !== $this->archiveReference($submissionId)) {
                throw new \RuntimeException(
                    'An HMRC exchange is not bound to the selected submission archive.'
                );
            }
            $transactionId = strtoupper(trim((string)($row['transaction_id'] ?? '')));
            if (preg_match('/^[0-9A-F]{1,32}$/D', $transactionId) !== 1) {
                throw new \RuntimeException('A bound HMRC exchange transaction ID is invalid.');
            }
            $exchangeCorrelationId = strtoupper(trim((string)(
                $row['correlation_id'] ?? ''
            )));
            if ($correlationId !== ''
                && $exchangeCorrelationId !== ''
                && !hash_equals($correlationId, $exchangeCorrelationId)) {
                throw new \RuntimeException(
                    'A bound HMRC exchange correlation ID does not match the conversation.'
                );
            }
            $requestEvidence = $this->govTalk->evidenceFileForCompany(
                (int)$submission['company_id'],
                (int)$row['id'],
                'request'
            );
            $exchangeRequestPath = realpath((string)($row['request_path'] ?? ''));
            $evidenceRequestPath = realpath((string)($requestEvidence['path'] ?? ''));
            $exchangeRequestHash = strtolower(trim((string)($row['request_sha256'] ?? '')));
            if (!is_string($exchangeRequestPath)
                || !is_string($evidenceRequestPath)
                || strcasecmp($exchangeRequestPath, $evidenceRequestPath) !== 0
                || $exchangeRequestHash === ''
                || !hash_equals(
                    $exchangeRequestHash,
                    strtolower(trim((string)($requestEvidence['sha256'] ?? '')))
                )
                || strtolower(trim((string)($requestEvidence['authority'] ?? ''))) !== 'hmrc') {
                throw new \RuntimeException(
                    'A bound HMRC request does not match its immutable exchange evidence.'
                );
            }
            $transactionIds[$transactionId] = true;
        }

        return array_keys($transactionIds);
    }

    private function exchangeReceivedAt(
        string $transactionId,
        \DateTimeImmutable $fallback
    ): \DateTimeImmutable {
        $transactionId = strtoupper(trim($transactionId));
        if ($transactionId === '' || !$this->govTalk->schemaReady()) {
            return $fallback;
        }
        $value = trim((string)(\InterfaceDB::fetchColumn(
            'SELECT received_at
             FROM govtalk_protocol_exchanges
             WHERE authority = :authority
               AND transaction_id = :transaction_id
             LIMIT 1',
            [
                'authority' => 'hmrc',
                'transaction_id' => $transactionId,
            ]
        ) ?? ''));
        if ($value === '') {
            return $fallback;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function normaliseSubmission(array $row): array
    {
        $row['hmrc_submission_reference_raw'] = $row['hmrc_submission_reference'] ?? null;
        $row['hmrc_submission_reference'] = $this->receiptReferences->normalise(
            $row['hmrc_submission_reference'] ?? null
        );
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
            'idempotency_key',
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
                $this->pendingConversationBlocker(
                    $existing,
                    'An identical HMRC conversation is already in progress.'
                ),
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

    /**
     * Load all request-artifact candidates for a card in two queries.  Exact
     * body/manifest matching remains in PHP so each CT period/environment does
     * not repeat the same archive and evidence-table joins.
     *
     * @return array<int,list<array<string,mixed>>>
     */
    private function requestArtifactCandidatesForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId
    ): array {
        $indexed = [];
        if (\InterfaceDB::tableExists('govtalk_protocol_exchanges')
            && \InterfaceDB::tableExists('transmission_archives')) {
            $rows = \InterfaceDB::fetchAll(
                'SELECT s.id AS submission_id, s.ct_period_id, s.environment,
                        s.source_manifest_sha256, s.body_sha256,
                        e.id AS exchange_id, e.transaction_id, e.request_path, e.request_sha256,
                        a.submission_reference AS archive_submission_reference
                 FROM ' . self::SUBMISSIONS . ' s
                 INNER JOIN govtalk_protocol_exchanges e
                   ON e.hmrc_submission_id = s.id
                  AND e.authority = :exchange_authority
                  AND e.operation = :operation
                  AND e.environment = s.environment
                  AND e.request_path IS NOT NULL
                  AND e.request_sha256 IS NOT NULL
                 INNER JOIN transmission_archives a
                   ON a.id = e.transmission_archive_id
                  AND a.authority = :archive_authority
                  AND a.company_id = s.company_id
                  AND a.accounting_period_id = s.accounting_period_id
                  AND a.environment = s.environment
                 WHERE s.company_id = :company_id
                   AND s.accounting_period_id = :accounting_period_id
                 ORDER BY s.id DESC, e.id DESC',
                [
                    'exchange_authority' => 'hmrc',
                    'archive_authority' => 'hmrc',
                    'operation' => 'submit',
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            ) ?: [];
            foreach ($rows as $row) {
                $submissionId = (int)($row['submission_id'] ?? 0);
                $ctPeriodId = (int)($row['ct_period_id'] ?? 0);
                $mode = strtoupper(trim((string)($row['environment'] ?? '')));
                if ($submissionId <= 0 || $ctPeriodId <= 0 || !isset(self::DEVELOPER_REQUEST_ROLES[$mode])
                    || trim((string)($row['archive_submission_reference'] ?? ''))
                        !== $this->archiveReference($submissionId)) {
                    continue;
                }
                $indexed[$ctPeriodId][] = [
                    'source' => 'submitted',
                    'company_id' => $companyId,
                    'environment' => $mode,
                    'artifact_id' => '',
                    'artifact_row_id' => 0,
                    'bundle_id' => 0,
                    'submission_id' => $submissionId,
                    'exchange_id' => (int)($row['exchange_id'] ?? 0),
                    'transaction_id' => strtoupper(trim((string)($row['transaction_id'] ?? ''))),
                    'filename' => basename((string)($row['request_path'] ?? '')),
                    'storage_path' => (string)($row['request_path'] ?? ''),
                    'sha256' => strtolower(trim((string)($row['request_sha256'] ?? ''))),
                    'metadata' => [
                        'environment' => $mode,
                        'body_sha256' => strtolower(trim((string)($row['body_sha256'] ?? ''))),
                        'source_manifest_sha256' => strtolower(trim((string)($row['source_manifest_sha256'] ?? ''))),
                        'transmitted' => true,
                    ],
                ];
            }
        }

        if (\InterfaceDB::tableExists('filing_evidence_artifacts')
            && \InterfaceDB::tableExists('filing_evidence_bundles')) {
            $rows = \InterfaceDB::fetchAll(
                "SELECT artifact.id, artifact.artifact_id, artifact.transaction_hex,
                        artifact.bundle_id, artifact.ct_period_id, artifact.artifact_role,
                        artifact.filename, artifact.storage_path, artifact.sha256,
                        artifact.metadata_json
                 FROM filing_evidence_artifacts artifact
                 INNER JOIN filing_evidence_bundles bundle ON bundle.id = artifact.bundle_id
                 WHERE bundle.company_id = :company_id
                   AND bundle.accounting_period_id = :accounting_period_id
                   AND artifact.artifact_status IN ('generated', 'validated', 'historical')
                 ORDER BY artifact.id DESC",
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            ) ?: [];
            $modesByRole = array_flip(self::DEVELOPER_REQUEST_ROLES);
            foreach ($rows as $row) {
                $mode = (string)($modesByRole[(string)($row['artifact_role'] ?? '')] ?? '');
                $ctPeriodId = (int)($row['ct_period_id'] ?? 0);
                $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                if ($mode === '' || $ctPeriodId <= 0
                    || strtoupper(trim((string)($metadata['environment'] ?? ''))) !== $mode
                    || !array_key_exists('transmitted', $metadata)
                    || !empty($metadata['transmitted'])) {
                    continue;
                }
                $indexed[$ctPeriodId][] = [
                    'source' => 'generated',
                    'company_id' => $companyId,
                    'environment' => $mode,
                    'artifact_id' => (string)($row['artifact_id'] ?? ''),
                    'artifact_row_id' => (int)($row['id'] ?? 0),
                    'bundle_id' => (int)($row['bundle_id'] ?? 0),
                    'submission_id' => 0,
                    'exchange_id' => 0,
                    'transaction_id' => strtoupper(trim((string)($row['transaction_hex'] ?? ''))),
                    'filename' => (string)($row['filename'] ?? ''),
                    'storage_path' => (string)($row['storage_path'] ?? ''),
                    'sha256' => strtolower(trim((string)($row['sha256'] ?? ''))),
                    'metadata' => $metadata,
                ];
            }
        }
        return $indexed;
    }

    /** @return array<string,mixed>|null */
    private function requestArtifactFromCandidates(
        array $candidates,
        int $ctPeriodId,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        $mode = strtoupper(trim($mode));
        $manifestHash = strtolower(trim($manifestHash));
        $bodyHash = strtolower(trim($bodyHash));
        if ($ctPeriodId <= 0 || !isset(self::DEVELOPER_REQUEST_ROLES[$mode])
            || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1) {
            return null;
        }
        foreach (['submitted', 'generated'] as $source) {
            foreach ($candidates as $candidate) {
                $metadata = (array)($candidate['metadata'] ?? []);
                if ((string)($candidate['source'] ?? '') === $source
                    && (string)($candidate['environment'] ?? '') === $mode
                    && hash_equals($manifestHash, strtolower(trim((string)($metadata['source_manifest_sha256'] ?? ''))))
                    && hash_equals($bodyHash, strtolower(trim((string)($metadata['body_sha256'] ?? ''))))) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function requestArtifactRecordForHashes(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        $mode = strtoupper(trim($mode));
        $manifestHash = strtolower(trim($manifestHash));
        $bodyHash = strtolower(trim($bodyHash));
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || $ctPeriodId <= 0
            || !isset(self::DEVELOPER_REQUEST_ROLES[$mode])
            || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1) {
            return null;
        }

        $submitted = $this->submittedRequestArtifactRecord(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $mode,
            $manifestHash,
            $bodyHash
        );
        if (is_array($submitted)) {
            return $submitted;
        }

        return $this->generatedRequestArtifactRecord(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $mode,
            $manifestHash,
            $bodyHash
        );
    }

    /** @return array<string,mixed>|null */
    private function submittedRequestArtifactRecord(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        if (!\InterfaceDB::tableExists('govtalk_protocol_exchanges')
            || !\InterfaceDB::tableExists('transmission_archives')) {
            return null;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT s.id AS submission_id, e.id AS exchange_id,
                    e.transaction_id, e.request_path, e.request_sha256,
                    a.submission_reference AS archive_submission_reference
             FROM ' . self::SUBMISSIONS . ' s
             INNER JOIN govtalk_protocol_exchanges e
               ON e.hmrc_submission_id = s.id
              AND e.authority = :exchange_authority
              AND e.operation = :operation
             INNER JOIN transmission_archives a
               ON a.id = e.transmission_archive_id
              AND a.authority = :archive_authority
              AND a.company_id = s.company_id
              AND a.accounting_period_id = s.accounting_period_id
              AND a.environment = s.environment
             WHERE s.company_id = :company_id
               AND s.accounting_period_id = :accounting_period_id
               AND s.ct_period_id = :ct_period_id
               AND s.environment = :environment
               AND s.source_manifest_sha256 = :manifest_hash
               AND s.body_sha256 = :body_hash
               AND e.environment = s.environment
               AND e.request_path IS NOT NULL
               AND e.request_sha256 IS NOT NULL
             ORDER BY s.id DESC, e.id DESC',
            [
                'exchange_authority' => 'hmrc',
                'archive_authority' => 'hmrc',
                'operation' => 'submit',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'environment' => $mode,
                'manifest_hash' => $manifestHash,
                'body_hash' => $bodyHash,
            ]
        ) ?: [];
        foreach ($rows as $row) {
            $submissionId = (int)($row['submission_id'] ?? 0);
            if ($submissionId <= 0
                || trim((string)($row['archive_submission_reference'] ?? ''))
                    !== $this->archiveReference($submissionId)) {
                continue;
            }
            return [
                'source' => 'submitted',
                'company_id' => $companyId,
                'environment' => $mode,
                'artifact_id' => '',
                'artifact_row_id' => 0,
                'bundle_id' => 0,
                'submission_id' => $submissionId,
                'exchange_id' => (int)($row['exchange_id'] ?? 0),
                'transaction_id' => strtoupper(trim((string)($row['transaction_id'] ?? ''))),
                'filename' => basename((string)($row['request_path'] ?? '')),
                'storage_path' => (string)($row['request_path'] ?? ''),
                'sha256' => strtolower(trim((string)($row['request_sha256'] ?? ''))),
                'metadata' => [
                    'environment' => $mode,
                    'body_sha256' => $bodyHash,
                    'source_manifest_sha256' => $manifestHash,
                    'transmitted' => true,
                ],
            ];
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function generatedRequestArtifactRecord(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $mode,
        string $manifestHash,
        string $bodyHash
    ): ?array {
        if (!\InterfaceDB::tableExists('filing_evidence_artifacts')
            || !\InterfaceDB::tableExists('filing_evidence_bundles')) {
            return null;
        }
        $rows = \InterfaceDB::fetchAll(
            "SELECT artifact.id, artifact.artifact_id, artifact.transaction_hex,
                    artifact.bundle_id, artifact.filename, artifact.storage_path,
                    artifact.sha256, artifact.metadata_json
             FROM filing_evidence_artifacts artifact
             INNER JOIN filing_evidence_bundles bundle ON bundle.id = artifact.bundle_id
             WHERE bundle.company_id = :company_id
               AND bundle.accounting_period_id = :accounting_period_id
               AND artifact.ct_period_id = :ct_period_id
               AND artifact.artifact_role = :artifact_role
               AND artifact.artifact_status IN ('generated', 'validated', 'historical')
             ORDER BY artifact.id DESC",
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'artifact_role' => self::DEVELOPER_REQUEST_ROLES[$mode],
            ]
        ) ?: [];
        foreach ($rows as $row) {
            $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            if (strtoupper(trim((string)($metadata['environment'] ?? ''))) !== $mode
                || !hash_equals($manifestHash, strtolower(trim((string)(
                    $metadata['source_manifest_sha256'] ?? ''
                ))))
                || !hash_equals($bodyHash, strtolower(trim((string)($metadata['body_sha256'] ?? ''))))
                || !array_key_exists('transmitted', $metadata)
                || !empty($metadata['transmitted'])) {
                continue;
            }
            return [
                'source' => 'generated',
                'company_id' => $companyId,
                'environment' => $mode,
                'artifact_id' => (string)($row['artifact_id'] ?? ''),
                'artifact_row_id' => (int)($row['id'] ?? 0),
                'bundle_id' => (int)($row['bundle_id'] ?? 0),
                'submission_id' => 0,
                'exchange_id' => 0,
                'transaction_id' => strtoupper(trim((string)($row['transaction_hex'] ?? ''))),
                'filename' => (string)($row['filename'] ?? ''),
                'storage_path' => (string)($row['storage_path'] ?? ''),
                'sha256' => strtolower(trim((string)($row['sha256'] ?? ''))),
                'metadata' => $metadata,
            ];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function publicRequestArtifactDescriptor(array $record): array
    {
        return [
            'available' => true,
            'environment' => (string)$record['environment'],
            'source' => (string)$record['source'],
            'filename' => (string)$record['filename'],
            'artifact_id' => (string)($record['artifact_id'] ?? ''),
            'submission_id' => (int)($record['submission_id'] ?? 0),
            'exchange_id' => (int)($record['exchange_id'] ?? 0),
            'sha256' => (string)$record['sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private function unavailableRequestArtifactDescriptor(string $mode): array
    {
        return [
            'available' => false,
            'environment' => strtoupper(trim($mode)),
            'source' => '',
            'filename' => '',
            'artifact_id' => '',
            'submission_id' => 0,
            'exchange_id' => 0,
            'sha256' => '',
        ];
    }

    /** @return array{path:string,filename:string,sha256:string,environment:string,source:string,artifact_id:string,artifact_row_id:int,bundle_id:int,submission_id:int,exchange_id:int} */
    private function requestArtifactFile(array $record): array
    {
        $source = strtolower(trim((string)($record['source'] ?? '')));
        $path = '';
        $filename = '';
        $sha256 = strtolower(trim((string)($record['sha256'] ?? '')));
        if ($source === 'submitted') {
            $evidence = $this->govTalk->evidenceFileForCompany(
                (int)($record['company_id'] ?? 0),
                (int)($record['exchange_id'] ?? 0),
                'request'
            );
            $path = (string)$evidence['path'];
            $filename = (string)$evidence['filename'];
            $evidenceHash = strtolower(trim((string)$evidence['sha256']));
            $recordPath = realpath((string)($record['storage_path'] ?? ''));
            $evidencePath = realpath($path);
            if (!is_string($recordPath)
                || !is_string($evidencePath)
                || strcasecmp($recordPath, $evidencePath) !== 0
                || $sha256 === ''
                || !hash_equals($sha256, $evidenceHash)) {
                throw new \RuntimeException(
                    'The submitted GovTalk request does not match its immutable exchange evidence.'
                );
            }
        } elseif ($source === 'generated') {
            $resolved = realpath((string)($record['storage_path'] ?? ''));
            if (!is_string($resolved)
                || !is_file($resolved)
                || !$this->pathWithin($resolved, $this->artifactRoot)) {
                throw new \RuntimeException('The generated GovTalk request artefact is unavailable.');
            }
            if (preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
                throw new \RuntimeException('The generated GovTalk request artefact has no valid integrity hash.');
            }
            $actual = hash_file('sha256', $resolved);
            if (!is_string($actual) || !hash_equals($sha256, strtolower($actual))) {
                throw new \RuntimeException('The generated GovTalk request artefact failed its integrity check.');
            }
            $path = $resolved;
            $filename = basename($resolved);
            $recordedFilename = trim((string)($record['filename'] ?? ''));
            if ($recordedFilename === '' || $recordedFilename !== $filename) {
                throw new \RuntimeException('The generated GovTalk request filename does not match its evidence record.');
            }
        } else {
            throw new \RuntimeException('The GovTalk request artefact source is invalid.');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
            'environment' => strtoupper(trim((string)($record['environment'] ?? ''))),
            'source' => $source,
            'artifact_id' => (string)($record['artifact_id'] ?? ''),
            'artifact_row_id' => (int)($record['artifact_row_id'] ?? 0),
            'bundle_id' => (int)($record['bundle_id'] ?? 0),
            'submission_id' => (int)($record['submission_id'] ?? 0),
            'exchange_id' => (int)($record['exchange_id'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function existingRequestArtifactResult(array $record, array $file, array $warnings): array
    {
        $metadata = (array)($record['metadata'] ?? []);
        $credentialsPlaceholder = !empty($metadata['credentials_placeholder']);
        $warning = $credentialsPlaceholder
            ? 'The existing GovTalk request uses developer placeholder sender credentials and cannot be transmitted.'
            : 'The existing GovTalk request contains HMRC sender credentials; keep it private.';
        $bytes = filesize((string)$file['path']);

        return [
            'success' => true,
            'submission_id' => (int)($record['submission_id'] ?? 0),
            'mode' => (string)$record['environment'],
            'status' => 'existing',
            'protocol_state' => (string)$record['source'] === 'submitted' ? 'archived' : 'not_sent',
            'business_outcome' => '',
            'needs_poll' => false,
            'poll_after_seconds' => null,
            'errors' => [],
            'warnings' => array_values(array_unique(array_merge($warnings, [
                'An exact immutable GovTalk request artefact already exists; no new file was generated.',
                $warning,
            ]))),
            'path' => $file['path'],
            'filename' => $file['filename'],
            'sha256' => $file['sha256'],
            'bytes' => is_int($bytes) ? $bytes : 0,
            'endpoint' => (string)($metadata['endpoint']
                ?? HmrcCtTransactionEngineEnvironment::profile((string)$record['environment'])['submission_url']),
            'transaction_id' => (string)($record['transaction_id'] ?? ''),
            'credentials_placeholder' => $credentialsPlaceholder,
            'artifact_source' => (string)$record['source'],
            'artifact_id' => (string)($record['artifact_id'] ?? ''),
            'exchange_id' => (int)($record['exchange_id'] ?? 0),
        ];
    }

    private function failRequestArtifactQuietly(
        FilingEvidenceService $evidence,
        int $artifactRowId,
        string $message,
        array $metadata
    ): void {
        try {
            $evidence->failArtifact($artifactRowId, $message, $metadata);
        } catch (\Throwable) {
            // Preserve the preparation/storage failure that caused this cleanup attempt.
        }
    }

    /**
     * @return array{path:string,filename:string,sha256:string,bytes:int,transaction_id:string}
     */
    private function storeDeveloperRequestFile(
        array $package,
        string $mode,
        string $transactionId,
        string $xml
    ): array {
        if ($xml === '') {
            throw new \RuntimeException('The prepared GovTalk request is empty.');
        }
        $sourcePath = trim((string)($package['ct600_xml_path'] ?? ''));
        $resolvedSource = $sourcePath !== '' ? realpath($sourcePath) : false;
        if (!is_string($resolvedSource) || !is_file($resolvedSource)) {
            throw new \RuntimeException('The prepared CT600 XML path is unavailable.');
        }
        $directory = realpath(dirname($resolvedSource));
        if (!is_string($directory)
            || !$this->pathWithin($resolvedSource, $this->artifactRoot)
            || !$this->pathWithin($directory, $this->artifactRoot)) {
            throw new \RuntimeException('The prepared CT600 XML is outside protected artifact storage.');
        }
        $mode = strtolower(trim($mode));
        $transactionId = strtolower(trim($transactionId));
        if (!in_array($mode, ['test', 'til', 'live'], true)
            || preg_match('/^[a-f0-9]{1,32}$/D', $transactionId) !== 1) {
            throw new \RuntimeException('The GovTalk request identity is invalid.');
        }
        $filename = 'govtalk_ctperiod-' . (int)$package['ct_period_id']
            . '_' . $mode . '_' . $transactionId . '.xml';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        $sha256 = hash('sha256', $xml);
        $bytes = strlen($xml);
        if (is_file($path)) {
            $existing = hash_file('sha256', $path);
            if (!is_string($existing) || !hash_equals($sha256, strtolower($existing))) {
                throw new \RuntimeException('A generated GovTalk request already exists with different bytes.');
            }
        } else {
            $temporary = tempnam($directory, '.govtalk-');
            if (!is_string($temporary) || $temporary === '') {
                throw new \RuntimeException('Unable to stage the GovTalk request file.');
            }
            try {
                if (file_put_contents($temporary, $xml, LOCK_EX) !== $bytes) {
                    throw new \RuntimeException('The GovTalk request file was not written completely.');
                }
                @chmod($temporary, 0600);
                if (!@rename($temporary, $path)) {
                    throw new \RuntimeException('Unable to publish the GovTalk request file.');
                }
                $temporary = '';
            } finally {
                if ($temporary !== '' && is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        }
        clearstatcache(true, $path);
        $storedHash = hash_file('sha256', $path);
        $storedBytes = filesize($path);
        if (!is_string($storedHash)
            || !hash_equals($sha256, strtolower($storedHash))
            || !is_int($storedBytes)
            || $storedBytes !== $bytes) {
            throw new \RuntimeException('The generated GovTalk request failed its read-back check.');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
            'bytes' => $bytes,
            'transaction_id' => strtoupper($transactionId),
        ];
    }

    private function transportErrors(array $result): array
    {
        $message = trim((string)($result['error'] ?? ''));
        return [$message !== '' ? $message : 'HMRC rejected the CT600 filing body.'];
    }

    private function rejectionSummary(string $summary): string
    {
        $summary = trim($summary);
        if ($summary === '') {
            $summary = 'HMRC rejected the CT600 filing body.';
        }
        if (stripos($summary, 'view the GovTalk conversation') === false) {
            $summary .= ' View the GovTalk conversation for full details.';
        }

        return $summary;
    }

    /** @return list<string> */
    private function gatewayRejectionMessages(array $errors): array
    {
        $messages = [];
        $authenticationFailure = false;
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }
            $number = trim((string)($error['number'] ?? ''));
            $raisedBy = trim((string)($error['raised_by'] ?? ''));
            $texts = array_values(array_filter(array_map(
                static fn(mixed $text): string => trim((string)$text),
                (array)($error['texts'] ?? [])
            )));
            if ($texts === []) {
                $fallback = trim(implode(' ', array_filter([$raisedBy, $number])));
                if ($fallback !== '') {
                    $messages[] = $fallback;
                }
            } else {
                foreach ($texts as $text) {
                    $messages[] = ($number === '' ? '' : $number . ': ') . $text;
                }
            }
            $authenticationFailure = $authenticationFailure || $number === '1046';
        }
        if ($authenticationFailure) {
            $messages[] = 'Check the Sender ID and password stored under '
                . 'HMRC / XML / CT600_XML / TEST|LIVE for the selected environment.';
        }

        return array_values(array_unique($messages));
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

    private function transactionEvidenceFilename(
        string $operation,
        string $transactionId,
        string $direction
    ): string {
        $operation = preg_replace('/[^a-z0-9-]+/', '-', strtolower($operation)) ?: 'exchange';
        $transactionId = preg_replace('/[^a-z0-9]+/', '', strtolower($transactionId)) ?: 'unknown';
        $direction = $direction === 'request' ? 'request' : 'response';

        return $operation . '-' . $transactionId . '-' . $direction . '.xml';
    }

    /**
     * @return array<string,mixed>
     */
    private function hmrcGovTalkIdentity(
        int $submissionId,
        string $operation
    ): array {
        $submission = $this->fetchById($submissionId);
        if (!is_array($submission)) {
            throw new \RuntimeException(
                'The HMRC GovTalk conversation identity could not be resolved.'
            );
        }

        return [
            'authority' => 'hmrc',
            'company_id' => (int)$submission['company_id'],
            'accounting_period_id' => (int)$submission['accounting_period_id'],
            'environment' => (string)$submission['environment'],
            'archive_reference' => $this->archiveReference($submissionId),
            'lifecycle' => (string)$submission['protocol_state'],
            'operation' => strtolower(trim($operation)),
            'submission_id' => null,
            'preflight_id' => null,
            'status_cycle_id' => null,
            'hmrc_submission_id' => $submissionId,
        ];
    }

    /** @param array<string,mixed> $result */
    private function completeHmrcGovTalkResult(string $environment, array $result): void
    {
        $transactionId = trim((string)($result['transaction_id'] ?? ''));
        if ($transactionId === '' || !$this->govTalk->schemaReady()) {
            return;
        }
        $businessOutcome = strtolower(trim((string)($result['business_outcome'] ?? '')));
        $state = !empty($result['evidence_incomplete'])
            ? 'evidence_incomplete'
            : (!empty($result['transport_unknown'])
                ? 'transport_unknown'
                : (!empty($result['success'])
                    ? 'succeeded'
                    : ($businessOutcome === 'rejected' ? 'rejected' : 'failed')));
        $outcomeCode = $businessOutcome !== ''
            ? $businessOutcome
            : strtolower(trim((string)($result['protocol_state'] ?? $state)));
        $summary = trim((string)($result['error'] ?? ''));
        $this->govTalk->completeExchange(
            'hmrc',
            $environment,
            $transactionId,
            $state,
            $outcomeCode,
            $summary,
            $summary,
            (string)($result['correlation_id'] ?? ''),
            (array)($result['errors'] ?? [])
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
        if (is_string($publicRoot)
            && $this->pathWithin($resolved, $publicRoot)
            && !\eel_accounts\Store\AccountingConfigurationStore::isConfiguredTestUploadPath($resolved)) {
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
        if (!$this->govTalk->schemaReady()) {
            return 'Run the shared GovTalk exchange-ledger migration before HMRC filing.';
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
                ? ($this->packagePreparer)($companyId, $ctPeriodId, strtoupper(trim($mode)))
                : $this->packages->prepareForSubmission(
                    $companyId,
                    $ctPeriodId,
                    strtoupper(trim($mode))
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

    /** @param array<string,mixed> $submission */
    private function pendingConversationBlocker(array $submission, string $fallback): string
    {
        if (
            strtolower(trim((string)($submission['protocol_state'] ?? ''))) === 'delete_pending'
            && strtolower(trim((string)($submission['business_outcome'] ?? ''))) === 'rejected'
        ) {
            return self::REJECTED_CLEANUP_BLOCKER;
        }

        return $fallback;
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
                'hmrc_gateway_rejected' => 'HMRC Gateway rejected the request before opening a filing conversation.',
                'hmrc_acknowledged' => 'HMRC acknowledged the frozen filing package.',
                'hmrc_acknowledgement_recovered' => 'A verified archived HMRC acknowledgement restored the polling conversation.',
                'hmrc_receipt_metadata_repaired' => 'Verified archived HMRC evidence repaired the receipt document reference.',
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
        return \eel_accounts\Support\Utf8::json(
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

        return \eel_accounts\Support\Utf8::json(
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
