<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Client\CompaniesHouseAccountsGatewayClient;
use eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface;
use eel_accounts\Store\AccountingConfigurationStore;

final class CompaniesHouseAccountsSubmissionService
{
    private const ELIGIBILITY_TABLE = 'companies_house_accounts_eligibility';
    private const SUBMISSIONS_TABLE = 'companies_house_accounts_submissions';
    private const EVENTS_TABLE = 'companies_house_accounts_submission_events';

    public function __construct(
        private readonly ?IxbrlReadinessService $readinessService = null,
        private readonly ?IxbrlRevisedAccountsArtifactService $artifactService = null,
        private readonly ?CompaniesHouseAccountsGatewayTransportInterface $gatewayClient = null,
        private readonly ?YearEndLockService $lockService = null,
        private readonly ?CompaniesHouseSchemaCurrentnessInterface $schemaService = null,
        private readonly ?CompaniesHouseAccountsCredentialService $credentialService = null,
        private readonly ?CompaniesHouseSubmissionSequenceService $sequenceService = null,
        private readonly ?TransmissionArchiveService $archiveService = null,
        private readonly ?CompaniesHouseCompanyDataCredentialService $companyDataCredentialService = null,
        private readonly ?CompaniesHouseProtocolConversationService $conversationService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $selection = $this->selection($companyId, $accountingPeriodId);
        if ($selection === null) {
            return $this->emptyContext('Select a valid company and accounting period.');
        }

        $locked = ($this->lockService ?? new YearEndLockService())
            ->isLocked($companyId, $accountingPeriodId);
        $original = $this->exactOriginalDocument($selection);
        $eligibility = $this->eligibility($selection, $original);
        $readiness = $this->readiness($companyId, $accountingPeriodId);
        $submission = $this->latestSubmission($companyId, $accountingPeriodId);
        $mode = AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        $featureEnabled = in_array($mode, ['TEST', 'LIVE'], true);
        $credentialsConfigured = $featureEnabled && $this->credentialsConfigured($mode);
        $companyDataCredentialsConfigured = $featureEnabled
            && $this->companyDataCredentials()->configured($mode);
        $protocolReady = $this->conversation()->schemaReady();
        $sequence = [
            'configured' => false,
            'next_number' => '',
            'last_issued_number' => null,
            'in_flight_submission_id' => null,
            'status_in_flight_submission_id' => null,
            'status_in_flight_cycle_id' => null,
            'presenter_fingerprint' => '',
        ];
        if ($credentialsConfigured) {
            try {
                $credentials = $this->credentials()->load($mode);
                $sequence = $this->sequences()->status($mode, $credentials['presenter_id']);
            } catch (\Throwable) {
                $sequence['configured'] = false;
            }
        }
        $liveApproved = AccountingConfigurationStore::companiesHouseAccountsLiveApproved();
        $testAccepted = $this->testAccepted((int)($eligibility['id'] ?? 0));

        $preparationBlockers = [];
        if (!$locked) {
            $preparationBlockers[] = 'Lock Year End before preparing revised accounts.';
        }
        if ($original === null) {
            $preparationBlockers[] = 'An exact-period original Companies House accounts filing is required.';
        }
        if ((string)($eligibility['decision'] ?? 'pending') === 'pending') {
            $preparationBlockers[] = 'Record Companies House written confirmation that this original filing is eligible for electronic revision.';
        } elseif ((string)($eligibility['decision'] ?? '') === 'ineligible') {
            $preparationBlockers[] = 'Companies House has marked this original filing as ineligible for software amendment; use the paper route.';
        }
        foreach ((array)($readiness['filing_errors'] ?? []) as $error) {
            $error = trim((string)$error);
            if ($error !== '') {
                $preparationBlockers[] = $error;
            }
        }

        $lifecycle = (string)($submission['lifecycle'] ?? '');
        if (in_array($lifecycle, ['prepared', 'submitting', 'transport_unknown', 'pending', 'parked', 'accepted'], true)) {
            $preparationBlockers[] = match ($lifecycle) {
                'prepared' => 'A revised-accounts artifact is already prepared for this filing basis.',
                'accepted' => 'Companies House has already accepted revised accounts for this filing basis.',
                default => 'A revised-accounts submission is already active and must be resolved before preparing another.',
            };
        }

        $submissionBlockers = [];
        if ($lifecycle !== 'prepared') {
            $submissionBlockers[] = 'Prepare and validate revised accounts before submission.';
        }
        if (!$featureEnabled) {
            $submissionBlockers[] = 'Companies House accounts filing is disabled until TEST credentials are issued.';
        } elseif (!$credentialsConfigured) {
            $submissionBlockers[] = 'Companies House accounts filing credentials are not configured for ' . $mode . '.';
        }
        if ($featureEnabled && !$companyDataCredentialsConfigured) {
            $submissionBlockers[] = 'Companies House CompanyData XML Output credentials are not configured for ' . $mode . '.';
        }
        if (!$protocolReady) {
            $submissionBlockers[] = 'Run the Companies House protocol-conversation migration before filing.';
        }
        if ($mode === 'LIVE' && !$liveApproved) {
            $submissionBlockers[] = 'LIVE revised-accounts filing has not been explicitly approved in server configuration.';
        }
        if ($mode === 'LIVE' && !$testAccepted) {
            $submissionBlockers[] = 'A Companies House TEST revised-accounts submission must be accepted before LIVE filing.';
        }
        $inFlightSubmissionId = (int)($sequence['in_flight_submission_id'] ?? 0);
        if ($inFlightSubmissionId > 0 && $inFlightSubmissionId !== (int)($submission['id'] ?? 0)) {
            $submissionBlockers[] = 'Another request for this Companies House presenter has an unresolved transport state.';
        }
        if ($featureEnabled && $credentialsConfigured && empty($sequence['configured'])) {
            $submissionBlockers[] = 'Run the Companies House submission-sequence migration before filing.';
        }
        if ($submission !== null
            && $featureEnabled
            && (string)($submission['environment'] ?? '') !== $mode) {
            $submissionBlockers[] = 'The prepared artifact belongs to ' . (string)$submission['environment'] . '; prepare a new artifact for ' . $mode . '.';
        }
        $submissionBlockers = array_merge($preparationBlockersForSubmit = array_values(array_filter(
            $preparationBlockers,
            static fn(string $blocker): bool => !str_contains($blocker, 'already prepared')
        )), $submissionBlockers);

        $preparedArtifact = $submission === null ? null : [
            'path' => (string)($submission['revised_artifact_path'] ?? ''),
            'filename' => basename((string)($submission['revised_artifact_path'] ?? '')),
            'sha256' => (string)($submission['revised_artifact_sha256'] ?? ''),
            'basis_hash' => (string)($submission['basis_hash'] ?? ''),
        ];

        return [
            'company' => $selection['company'],
            'accounting_period' => $selection['accounting_period'],
            'locked' => $locked,
            'feature' => [
                'mode' => $mode,
                'enabled' => $featureEnabled,
                'credentials_configured' => $credentialsConfigured,
                'company_data_credentials_configured' => $companyDataCredentialsConfigured,
                'protocol_ready' => $protocolReady,
                'developer_binding_configured' => $featureEnabled
                    && $this->conversation()->bindingConfigured($mode),
                'live_approved' => $liveApproved,
                'test_accepted' => $testAccepted,
            ],
            'eligibility' => $eligibility,
            'readiness' => $readiness,
            'submission' => $submission,
            'preflight' => $submission === null
                ? null
                : $this->conversation()->latestPreflight((int)$submission['id']),
            'status_cycle' => $submission === null
                ? null
                : $this->conversation()->latestStatusCycle((int)$submission['id']),
            'exchanges' => $submission === null
                ? []
                : $this->conversation()->exchanges((int)$submission['id']),
            'prepared_artifact' => $preparedArtifact,
            'sequence' => $sequence,
            'can_prepare' => $preparationBlockers === [],
            'can_submit' => $submissionBlockers === [],
            'preparation_blockers' => array_values(array_unique($preparationBlockers)),
            'submission_blockers' => array_values(array_unique($submissionBlockers)),
            'blockers' => array_values(array_unique(array_merge($preparationBlockers, $submissionBlockers))),
        ];
    }

    public function recordEligibility(
        int $companyId,
        int $accountingPeriodId,
        int $originalDocumentId,
        string $decision,
        string $evidence,
        string $actor
    ): array {
        $selection = $this->selection($companyId, $accountingPeriodId);
        if ($selection === null) {
            return $this->failure('Select a valid company and accounting period.');
        }
        if (!(($this->lockService ?? new YearEndLockService())->isLocked($companyId, $accountingPeriodId))) {
            return $this->failure('Lock Year End before recording revised-accounts eligibility.');
        }
        if (!\InterfaceDB::tableExists(self::ELIGIBILITY_TABLE)) {
            return $this->failure('The Companies House accounts-filing migration has not been applied.');
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['eligible', 'ineligible'], true)) {
            return $this->failure('Choose whether Companies House confirmed the original filing as eligible or ineligible.');
        }
        $evidence = trim($evidence);
        if ($evidence === '' || mb_strlen($evidence) > 16000) {
            return $this->failure('Enter the Companies House written response, up to 16,000 characters.');
        }
        $actor = $this->actor($actor);
        $original = $this->exactOriginalDocument($selection);
        if ($original === null || (int)$original['id'] !== $originalDocumentId) {
            return $this->failure('The selected document is not the newest exact-period original Companies House filing.');
        }

        $existing = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::ELIGIBILITY_TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND original_transaction_id = :transaction_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'transaction_id' => (string)$original['transaction_id'],
            ]
        );
        $now = gmdate('Y-m-d H:i:s');
        if (is_array($existing)) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::ELIGIBILITY_TABLE . '
                 SET original_document_id = :original_document_id,
                     original_document_external_id = :external_id,
                     original_filing_channel = :channel,
                     decision = :decision,
                     evidence_text = :evidence,
                     evidence_reference = :reference,
                     evidence_received_at = :received_at,
                     decided_by = :actor,
                     decided_at = :decided_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'original_document_id' => $originalDocumentId,
                    'external_id' => (string)$original['document_id'],
                    'channel' => (string)$original['detected_channel'],
                    'decision' => $decision,
                    'evidence' => $evidence,
                    'reference' => 'Companies House XML Team written response',
                    'received_at' => $now,
                    'actor' => $actor,
                    'decided_at' => $now,
                    'updated_at' => $now,
                    'id' => (int)$existing['id'],
                ]
            );
        } else {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::ELIGIBILITY_TABLE . ' (
                    company_id, accounting_period_id, original_document_id,
                    original_transaction_id, original_document_external_id,
                    original_filing_channel, decision, evidence_text,
                    evidence_reference, evidence_received_at, decided_by,
                    decided_at, created_at, updated_at
                 ) VALUES (
                    :company_id, :accounting_period_id, :original_document_id,
                    :transaction_id, :external_id, :channel, :decision, :evidence,
                    :reference, :received_at, :actor, :decided_at, :created_at, :updated_at
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'original_document_id' => $originalDocumentId,
                    'transaction_id' => (string)$original['transaction_id'],
                    'external_id' => (string)$original['document_id'],
                    'channel' => (string)$original['detected_channel'],
                    'decision' => $decision,
                    'evidence' => $evidence,
                    'reference' => 'Companies House XML Team written response',
                    'received_at' => $now,
                    'actor' => $actor,
                    'decided_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'messages' => [
                $decision === 'eligible'
                    ? 'Companies House electronic revised-accounts eligibility recorded.'
                    : 'Companies House marked this filing as ineligible for software amendment.',
            ],
            'changed' => true,
        ];
    }

    public function preflightRevision(
        int $submissionId,
        string $companyAuthCode,
        string $actor,
        mixed $progress = null
    ): array {
        $submission = $this->submission($submissionId);
        if ($submission === null || (string)$submission['lifecycle'] !== 'prepared') {
            return $this->failure('Only a prepared revised-accounts artifact can be preflighted.');
        }
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return $this->failure(
                'The company authentication code must contain exactly 6 letters or numbers.'
            );
        }
        $mode = AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        if (!in_array($mode, ['TEST', 'LIVE'], true)
            || $mode !== (string)$submission['environment']) {
            return $this->failure('The Companies House filing environment is unavailable or mismatched.');
        }
        $actor = $this->actor($actor);
        try {
            $schema = ($this->schemaService ?? new CompaniesHouseAccountsSchemaService())
                ->ensureCurrent($progress);
            $manifest = strtolower(trim((string)($schema['manifest_sha256'] ?? '')));
            $snapshotId = (int)($schema['snapshot_id'] ?? 0);
            if ($snapshotId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $manifest)) {
                throw new \RuntimeException('Companies House did not produce a verified schema snapshot.');
            }
            $result = $this->performCompanyDataPreflight(
                $submission,
                $companyAuthCode,
                $actor,
                $mode,
                $snapshotId,
                $manifest,
                true
            );
        } catch (\Throwable $exception) {
            return $this->failure(
                'Companies House CompanyData preflight failed; no submission number was consumed. '
                . $exception->getMessage()
            );
        }

        $success = !empty($result['success']);
        return [
            'success' => $success,
            'errors' => $success ? [] : [(string)$result['error']],
            'warnings' => !empty($result['transport_unknown'])
                ? ['The preflight transport outcome is uncertain. Accounts submission remains blocked.']
                : [],
            'messages' => $success
                ? ['CompanyData verified the company authentication code. The preflight is valid for 30 minutes.']
                : [],
            'preflight_id' => (int)($result['preflight_id'] ?? 0),
            'changed' => true,
        ];
    }

    public function prepareRevision(
        int $companyId,
        int $accountingPeriodId,
        array $input,
        string $actor
    ): array {
        if (!\InterfaceDB::tableExists(self::SUBMISSIONS_TABLE)) {
            return $this->failure('The Companies House accounts-filing migration has not been applied.');
        }
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['can_prepare'])) {
            return $this->failure((string)(($context['preparation_blockers'] ?? [])[0] ?? 'Revised accounts cannot be prepared yet.'));
        }
        $eligibility = (array)$context['eligibility'];
        $originalDocumentId = (int)($input['original_document_id'] ?? 0);
        if ($originalDocumentId <= 0 || $originalDocumentId !== (int)($eligibility['original_document_id'] ?? 0)) {
            return $this->failure('The revised accounts must use the exact original filing covered by the Companies House decision.');
        }

        $actor = $this->actor($actor);
        try {
            $evidenceService = new FilingEvidenceService();
            $evidenceBundle = $evidenceService->ensureCurrentBundle($companyId, $accountingPeriodId, $actor);
            $evidenceArtifact = $evidenceService->reserveArtifact(
                $companyId,
                $accountingPeriodId,
                'companies_house_revised_accounts_ixbrl',
                null,
                ['original_document_id' => $originalDocumentId]
            );
        } catch (\Throwable $exception) {
            return $this->failure('Current filing evidence is required: ' . $exception->getMessage());
        }
        $artifact = ($this->artifactService ?? new IxbrlRevisedAccountsArtifactService())
            ->prepare($companyId, $accountingPeriodId, $input, (string)$evidenceArtifact['display_id']);
        if (empty($artifact['success'])) {
            $evidenceService->failArtifact((int)$evidenceArtifact['id'], (string)(($artifact['errors'] ?? [])[0] ?? 'Revised accounts preparation failed.'));
            return [
                'success' => false,
                'errors' => (array)($artifact['errors'] ?? ['The revised accounts could not be prepared.']),
                'warnings' => (array)($artifact['warnings'] ?? []),
                'messages' => [],
            ];
        }
        $validation = (array)($artifact['validation'] ?? []);
        $evidenceService->completeArtifact((int)$evidenceArtifact['id'], [
            'status' => 'validated',
            'filename' => (string)$artifact['filename'],
            'path' => (string)$artifact['path'],
            'sha256' => (string)$artifact['sha256'],
            'schema_identity' => IxbrlTaxonomyProfileService::SCHEMA_REF,
            'validator_name' => 'arelle',
            'validator_version' => (string)($validation['version'] ?? ''),
            'validation_status' => (string)($validation['status'] ?? 'passed'),
            'identifier_embedded' => true,
            'metadata' => ['base_run_id' => (int)($artifact['base_run_id'] ?? 0)],
        ]);

        $mode = AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        $environment = $mode === 'LIVE' ? 'LIVE' : 'TEST';
        $idempotencyKey = hash('sha256', $this->canonicalJson([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'eligibility_id' => (int)$eligibility['id'],
            'original_document_id' => $originalDocumentId,
            'environment' => $environment,
            'basis_hash' => (string)$artifact['basis_hash'],
            'artifact_sha256' => (string)$artifact['sha256'],
        ]));
        $existing = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS_TABLE . '
             WHERE environment = :environment AND idempotency_key = :idempotency_key
             LIMIT 1',
            ['environment' => $environment, 'idempotency_key' => $idempotencyKey]
        );
        if (is_array($existing)) {
            return [
                'success' => true,
                'errors' => [],
                'warnings' => [],
                'messages' => ['The same revised-accounts artifact is already prepared.'],
                'submission' => $this->normaliseSubmission($existing),
                'changed' => false,
            ];
        }

        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::transaction(function () use (
            $eligibility,
            $context,
            $artifact,
            $idempotencyKey,
            $environment,
            $actor,
            $now,
            $companyId,
            $accountingPeriodId,
            $evidenceBundle
        ): void {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::SUBMISSIONS_TABLE . ' (
                    evidence_bundle_id, eligibility_id, company_id, accounting_period_id, original_document_id,
                    original_transaction_id, original_document_external_id,
                    ixbrl_generation_run_id, environment, filing_type, lifecycle,
                    submission_number, revised_artifact_path, revised_artifact_sha256,
                    basis_hash, idempotency_key, revision_declarations_json,
                    prepared_by, prepared_at, status_updated_at, created_at, updated_at
                 ) VALUES (
                    :evidence_bundle_id, :eligibility_id, :company_id, :accounting_period_id, :original_document_id,
                    :transaction_id, :external_id, :run_id, :environment, :filing_type,
                    :lifecycle, :submission_number, :artifact_path, :artifact_sha256,
                    :basis_hash, :idempotency_key, :declarations, :prepared_by,
                    :prepared_at, :status_updated_at, :created_at, :updated_at
                 )',
                [
                    'evidence_bundle_id' => (int)$evidenceBundle['id'],
                    'eligibility_id' => (int)$eligibility['id'],
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'original_document_id' => (int)$eligibility['original_document_id'],
                    'transaction_id' => (string)$eligibility['original_transaction_id'],
                    'external_id' => (string)$eligibility['original_document_external_id'],
                    'run_id' => (int)($artifact['base_run_id'] ?? 0) ?: null,
                    'environment' => $environment,
                    'filing_type' => 'revised',
                    'lifecycle' => 'prepared',
                    'submission_number' => null,
                    'artifact_path' => (string)$artifact['path'],
                    'artifact_sha256' => (string)$artifact['sha256'],
                    'basis_hash' => (string)$artifact['basis_hash'],
                    'idempotency_key' => $idempotencyKey,
                    'declarations' => json_encode((array)$artifact['declarations'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'prepared_by' => $actor,
                    'prepared_at' => $now,
                    'status_updated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            $row = \InterfaceDB::fetchOne(
                'SELECT id FROM ' . self::SUBMISSIONS_TABLE . '
                 WHERE environment = :environment AND idempotency_key = :idempotency_key LIMIT 1',
                ['environment' => $environment, 'idempotency_key' => $idempotencyKey]
            );
            if (!is_array($row)) {
                throw new \RuntimeException('The prepared submission could not be reloaded.');
            }
            $this->recordEvent(
                (int)$row['id'],
                'prepared',
                'success',
                'prepared',
                null,
                'A revised-accounts artifact was prepared and validated.',
                $actor,
                ['artifact_sha256' => (string)$artifact['sha256'], 'basis_hash' => (string)$artifact['basis_hash']]
            );
            (new FilingEvidenceService())->recordEvent(
                (int)$evidenceBundle['id'],
                'companies_house_prepared',
                'success',
                $actor,
                'A Companies House revised-accounts artifact was prepared.',
                ['submission_id' => (int)$row['id'], 'environment' => $environment]
            );
        });

        $submission = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS_TABLE . '
             WHERE environment = :environment AND idempotency_key = :idempotency_key LIMIT 1',
            ['environment' => $environment, 'idempotency_key' => $idempotencyKey]
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($artifact['warnings'] ?? []),
            'messages' => ['Revised accounts prepared and validated for ' . $environment . '.'],
            'submission' => is_array($submission) ? $this->normaliseSubmission($submission) : null,
            'changed' => true,
        ];
    }

    public function submitRevision(
        int $submissionId,
        string $companyAuthCode,
        string $actor,
        mixed $progress = null,
        ?int $verifiedPreflightId = null
    ): array
    {
        $submission = $this->submission($submissionId);
        if ($submission === null) {
            return $this->failure('The revised-accounts submission was not found.');
        }
        if ((string)$submission['lifecycle'] !== 'prepared') {
            return $this->failure('Only a prepared revised-accounts artifact can be submitted.');
        }
        if (preg_match('/^[A-Za-z0-9]{6}$/D', $companyAuthCode) !== 1) {
            return $this->failure(
                'The company authentication code must contain exactly 6 letters or numbers.'
            );
        }
        $mode = AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        if (!in_array($mode, ['TEST', 'LIVE'], true)) {
            return $this->failure('Companies House accounts filing is disabled.');
        }
        if ($mode !== (string)$submission['environment']) {
            return $this->failure('The prepared submission environment does not match the server filing mode.');
        }
        if ($mode === 'LIVE' && !AccountingConfigurationStore::companiesHouseAccountsLiveApproved()) {
            return $this->failure('LIVE Companies House accounts filing has not been explicitly approved.');
        }
        if ($mode === 'LIVE' && !$this->testAccepted((int)$submission['eligibility_id'])) {
            return $this->failure('An accepted TEST revised-accounts submission is required before LIVE filing.');
        }
        if ((string)($submission['eligibility_decision'] ?? '') !== 'eligible') {
            return $this->failure('The original filing is not recorded as eligible for electronic revision.');
        }
        if (!(($this->lockService ?? new YearEndLockService())->isLocked(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id']
        ))) {
            return $this->failure('Year End is no longer locked; the submission was not sent.');
        }

        $readiness = $this->readiness((int)$submission['company_id'], (int)$submission['accounting_period_id']);
        if (empty($readiness['ready_for_filing'])) {
            return $this->failure((string)(($readiness['filing_errors'] ?? [])[0] ?? 'The iXBRL filing basis is no longer current.'));
        }
        $artifactPath = (string)$submission['revised_artifact_path'];
        $artifactHash = is_file($artifactPath) ? hash_file('sha256', $artifactPath) : false;
        if (!is_string($artifactHash)
            || !hash_equals(strtolower((string)$submission['revised_artifact_sha256']), strtolower($artifactHash))) {
            return $this->failure('The revised-accounts artifact has changed or is missing; it was not sent.');
        }
        $accountsXml = file_get_contents($artifactPath);
        if (!is_string($accountsXml) || $accountsXml === '') {
            return $this->failure('The revised-accounts artifact could not be read.');
        }

        $actor = $this->actor($actor);
        $allocated = false;
        $credentials = [];
        $manifest = '';
        $snapshotId = 0;
        try {
            $credentials = $this->credentials()->load($mode);
            $this->reportProgress($progress, 'Refreshing Companies House filing schemas before submission.', 5);
            $schema = ($this->schemaService ?? new CompaniesHouseAccountsSchemaService())->ensureCurrent($progress);
            $manifest = strtolower(trim((string)($schema['manifest_sha256'] ?? '')));
            $snapshotId = (int)($schema['snapshot_id'] ?? 0);
            if ($snapshotId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $manifest)) {
                throw new \RuntimeException('Companies House did not produce a verified accounts schema snapshot.');
            }
            if (!$this->conversation()->schemaReady()) {
                throw new \RuntimeException(
                    'Run the Companies House protocol-conversation migration before filing.'
                );
            }
            if ($verifiedPreflightId !== null && $verifiedPreflightId > 0) {
                $this->conversation()->consumePreflight(
                    $verifiedPreflightId,
                    $submission,
                    $companyAuthCode,
                    $actor,
                    true
                );
            } else {
                $this->reportProgress(
                    $progress,
                    'Checking the company authentication code with Companies House CompanyData.',
                    45
                );
                $preflight = $this->performCompanyDataPreflight(
                    $submission,
                    $companyAuthCode,
                    $actor,
                    $mode,
                    $snapshotId,
                    $manifest,
                    false
                );
                if (empty($preflight['success'])) {
                    throw new \RuntimeException(
                        trim((string)($preflight['error'] ?? 'Companies House CompanyData preflight failed.'))
                    );
                }
                $this->conversation()->consumePreflight(
                    (int)$preflight['preflight_id'],
                    $submission,
                    $companyAuthCode,
                    $actor,
                    false
                );
            }
            $allocation = $this->sequences()->allocate(
                $submissionId,
                $mode,
                (string)$credentials['presenter_id']
            );
            $allocated = true;
            $submission = $this->submission($submissionId);
            if ($submission === null
                || !hash_equals(
                    (string)$allocation['presenter_fingerprint'],
                    (string)($submission['presenter_fingerprint'] ?? '')
                )) {
                throw new \RuntimeException('The allocated Companies House submission could not be reloaded.');
            }
        } catch (\Throwable $exception) {
            $message = 'Companies House pre-submission preparation failed; nothing was sent. ' . $exception->getMessage();
            if ($allocated) {
                $this->failConsumedSubmission($submissionId, $mode, (string)($allocation['presenter_fingerprint'] ?? ''), $message, $actor);
            } else {
                $this->recordEvent($submissionId, 'preparation_failed', 'error', 'prepared', null, $message, $actor);
            }
            return $this->failure($message);
        }

        $declarations = json_decode((string)$submission['revision_declarations_json'], true);
        $dateSigned = is_array($declarations)
            ? trim((string)($declarations['revision_approval_date'] ?? ''))
            : '';
        $payload = [
            'company_number' => trim((string)$submission['company_number']),
            'company_name' => trim((string)$submission['company_name']),
            'company_authentication_code' => $companyAuthCode,
            'submission_number' => (string)$submission['submission_number'],
            'date_signed' => $dateSigned,
            'accounts_xml' => $accountsXml,
            'filename' => 'Accounts-' . (string)$submission['submission_number'] . '.xhtml',
            'customer_reference' => 'EEL' . (int)$submission['company_id'] . 'AP' . (int)$submission['accounting_period_id'],
            'language' => 'EN',
            'company_type' => 'EW',
        ];
        try {
            $this->reportProgress($progress, 'Building and validating the exact Companies House request.', 75);
            $gateway = $this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient();
            $preparedRequest = $gateway->prepareAccounts($payload, $mode, $manifest);
            if ($preparedRequest->schemaSnapshotId() !== $snapshotId
                || !hash_equals($manifest, $preparedRequest->schemaManifestSha256())) {
                throw new \RuntimeException('The prepared request was validated against a different Companies House schema snapshot.');
            }
            $archive = $this->archives();
            $accountsArchive = $archive->store(
                (int)$submission['company_id'],
                (int)$submission['accounting_period_id'],
                'companies_house',
                $mode,
                (string)$submission['submission_number'],
                'prepared',
                'accounts.xhtml',
                $accountsXml
            );
            $requestArchive = $archive->store(
                (int)$submission['company_id'],
                (int)$submission['accounting_period_id'],
                'companies_house',
                $mode,
                (string)$submission['submission_number'],
                'prepared',
                'submission-request.xml',
                $preparedRequest->requestXml()
            );
            $this->conversation()->captureRequest(
                $submission,
                $mode,
                (string)$submission['submission_number'],
                'accounts',
                [
                    'transaction_id' => $preparedRequest->transactionId(),
                    'request_xml' => $preparedRequest->requestXml(),
                ]
            );
            $evidenceService = new FilingEvidenceService();
            $requestEvidence = $evidenceService->reserveArtifact(
                (int)$submission['company_id'],
                (int)$submission['accounting_period_id'],
                'companies_house_govtalk_submit_request',
                null,
                ['submission_id' => $submissionId],
                $preparedRequest->transactionId()
            );
            $evidenceService->completeArtifact((int)$requestEvidence['id'], [
                'status' => 'validated',
                'filename' => 'submission-request.xml',
                'path' => $requestArchive['path'],
                'sha256' => hash('sha256', $preparedRequest->requestXml()),
                'schema_identity' => 'Companies House GovTalk accounts filing',
                'schema_manifest_sha256' => $manifest,
                'validation_status' => 'passed',
                'identifier_embedded' => true,
                'metadata' => [
                    'submission_id' => $submissionId,
                    'accounts_path' => $accountsArchive['path'],
                    'archive_manifest_path' => $requestArchive['manifest_path'],
                    'redacted_sha256' => hash('sha256', $preparedRequest->redactedRequestXml()),
                ],
            ]);
        } catch (\Throwable $exception) {
            $message = 'Companies House pre-submission validation failed; nothing was sent. ' . $exception->getMessage();
            $this->failConsumedSubmission(
                $submissionId,
                $mode,
                (string)$submission['presenter_fingerprint'],
                $message,
                $actor
            );
            return $this->failure($message);
        }

        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle, submitted_by = :actor, submitted_at = :submitted_at,
                 schema_snapshot_id = :schema_snapshot_id, schema_manifest_sha256 = :schema_manifest,
                 schema_validated_at = :schema_validated_at,
                 status_updated_at = :status_updated_at, updated_at = :updated_at
             WHERE id = :id AND lifecycle = :expected_lifecycle',
            [
                'lifecycle' => 'submitting',
                'actor' => $actor,
                'submitted_at' => $now,
                'schema_snapshot_id' => $snapshotId,
                'schema_manifest' => $manifest,
                'schema_validated_at' => $now,
                'status_updated_at' => $now,
                'updated_at' => $now,
                'id' => $submissionId,
                'expected_lifecycle' => 'prepared',
            ]
        );
        $current = $this->submission($submissionId);
        if ($current === null || (string)$current['lifecycle'] !== 'submitting') {
            $this->failConsumedSubmission(
                $submissionId,
                $mode,
                (string)$submission['presenter_fingerprint'],
                'The submission state changed before it could be sent.',
                $actor
            );
            return $this->failure('The submission state changed before it could be sent.');
        }

        $this->reportProgress($progress, 'Sending the already validated Companies House request.', 90);
        $result = $gateway->sendPreparedAccounts(
            $preparedRequest,
            function (array $response) use ($submission, $mode): void {
                $this->archives()->store(
                    (int)$submission['company_id'],
                    (int)$submission['accounting_period_id'],
                    'companies_house',
                    $mode,
                    (string)$submission['submission_number'],
                    'submitting',
                    'submission-response.xml',
                    (string)$response['response_xml']
                );
                $this->conversation()->captureResponse(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'accounts',
                    $response
                );
            }
        );

        $success = !empty($result['success']);
        $transportUnknown = !empty($result['transport_unknown']);
        $lifecycle = $success ? 'pending' : ($transportUnknown ? 'transport_unknown' : 'failed');
        $gatewayErrors = (array)($result['gateway_errors'] ?? []);
        $firstError = is_array($gatewayErrors[0] ?? null) ? $gatewayErrors[0] : [];
        $summary = $success
            ? 'Companies House acknowledged the revised-accounts submission.'
            : trim((string)($result['error'] ?? 'Companies House did not acknowledge the submission.'));
        $this->conversation()->completeExchange(
            $mode,
            (string)($result['transaction_id'] ?? ''),
            $success ? 'succeeded' : ($transportUnknown ? 'transport_unknown' : 'rejected'),
            $success ? '' : $summary
        );
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle, raw_gateway_status = :raw_status,
                 gateway_submission_reference = :gateway_reference,
                 gateway_status_summary = :summary, rejection_code = :rejection_code,
                 rejection_description = :rejection_description,
                 status_updated_at = :status_updated_at, updated_at = :updated_at
             WHERE id = :id',
            [
                'lifecycle' => $lifecycle,
                'raw_status' => $success ? 'ACKNOWLEDGED' : null,
                'gateway_reference' => trim((string)($result['response_transaction_id'] ?? $result['transaction_id'] ?? '')) ?: null,
                'summary' => $summary,
                'rejection_code' => trim((string)($firstError['number'] ?? $firstError['code'] ?? '')) ?: null,
                'rejection_description' => trim((string)($firstError['text'] ?? $firstError['description'] ?? $summary)) ?: null,
                'status_updated_at' => $now,
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        if (!$transportUnknown) {
            $this->sequences()->releaseResolved(
                $submissionId,
                $mode,
                (string)$submission['presenter_fingerprint']
            );
        }
        $this->archives()->updateLifecycle(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'companies_house',
            $mode,
            (string)$submission['submission_number'],
            $lifecycle
        );
        $this->recordEvent(
            $submissionId,
            $success ? 'gateway_acknowledgement' : ($transportUnknown ? 'transport_unknown' : 'gateway_error'),
            $success ? 'success' : 'error',
            $lifecycle,
            $success ? 'ACKNOWLEDGED' : null,
            $summary,
            $actor,
            $this->safeGatewayContext($result),
            (string)($firstError['number'] ?? $firstError['code'] ?? ''),
            (string)($firstError['text'] ?? $firstError['description'] ?? '')
        );
        $this->recordFilingEvidenceEvent(
            $submission,
            $success ? 'companies_house_acknowledged' : ($transportUnknown ? 'companies_house_transport_uncertain' : 'companies_house_rejected'),
            $success ? 'success' : 'error',
            $summary,
            $actor,
            ['gateway_reference' => (string)($result['response_transaction_id'] ?? $result['transaction_id'] ?? '')]
        );

        return [
            'success' => $success,
            'errors' => $success ? [] : [$summary],
            'warnings' => array_values(array_filter([
                $transportUnknown ? 'Do not resubmit; refresh the same submission number first.' : '',
                (string)($result['evidence_error'] ?? ''),
            ])),
            'messages' => $success ? [$summary] : [],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'changed' => true,
        ];
    }

    public function refreshStatus(int $submissionId, string $actor): array
    {
        $current = $this->submission($submissionId);
        if (is_array($current)
            && (string)$current['lifecycle'] === 'accepted'
            && trim((string)($current['document_request_key'] ?? '')) !== ''
            && trim((string)($current['returned_document_sha256'] ?? '')) === '') {
            return $this->retrieveDocument($submissionId, $actor);
        }
        $cycle = $this->conversation()->latestStatusCycle($submissionId);
        if (is_array($cycle)
            && ((string)$cycle['acknowledgement_state'] === 'required'
                || ((string)$cycle['acknowledgement_state'] === 'failed'
                    && trim((string)($cycle['result_json'] ?? '')) !== ''))) {
            $acknowledgement = $this->acknowledgeStatus($submissionId, $actor);
            if (empty($acknowledgement['success'])) {
                return $acknowledgement;
            }
            return $this->retrieveAcceptedDocumentIfAvailable($submissionId, $actor, $acknowledgement);
        }
        if (is_array($cycle) && (string)$cycle['acknowledgement_state'] === 'transport_unknown') {
            return $this->failure(
                'The previous status or StatusAck transport outcome is uncertain. Reconcile it before polling again.'
            );
        }

        $poll = $this->pollStatus($submissionId, $actor);
        if (empty($poll['success'])) {
            return $poll;
        }
        $acknowledgement = $this->acknowledgeStatus($submissionId, $actor);
        if (empty($acknowledgement['success'])) {
            return $acknowledgement;
        }
        return $this->retrieveAcceptedDocumentIfAvailable($submissionId, $actor, $acknowledgement);
    }

    public function pollStatus(int $submissionId, string $actor): array
    {
        $submission = $this->submission($submissionId);
        if ($submission === null) {
            return $this->failure('The revised-accounts submission was not found.');
        }
        if (!in_array((string)$submission['lifecycle'], ['submitting', 'transport_unknown', 'pending', 'parked'], true)) {
            return $this->failure('This submission does not have a refreshable Companies House status.');
        }
        $mode = AccountingConfigurationStore::companiesHouseAccountsFilingMode();
        if ($mode !== (string)$submission['environment']) {
            return $this->failure('The server filing mode does not match this submission environment.');
        }
        if (!$this->conversation()->schemaReady()) {
            return $this->failure(
                'Run the Companies House protocol-conversation migration before requesting status.'
            );
        }
        $existingCycle = $this->conversation()->latestStatusCycle($submissionId);
        if (is_array($existingCycle)
            && (in_array(
                (string)$existingCycle['acknowledgement_state'],
                ['required', 'sending', 'transport_unknown'],
                true
            ) || ((string)$existingCycle['acknowledgement_state'] === 'failed'
                && trim((string)($existingCycle['result_json'] ?? '')) !== ''))) {
            return $this->failure(
                'The previous Companies House status response must be acknowledged or reconciled first.'
            );
        }
        $actor = $this->actor($actor);
        $cycleId = $this->conversation()->createStatusCycle($submissionId);
        try {
            $this->sequences()->acquireStatusLock(
                $submissionId,
                $cycleId,
                $mode,
                (string)$submission['presenter_fingerprint']
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS_TABLE . '
                 SET pending_status_cycle_id = :cycle_id, updated_at = :updated_at WHERE id = :id',
                [
                    'cycle_id' => $cycleId,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => $submissionId,
                ]
            );
        } catch (\Throwable $exception) {
            $this->conversation()->updateStatusCycle(
                $cycleId,
                ['acknowledgement_state' => 'failed']
            );
            return $this->failure($exception->getMessage());
        }

        $gateway = $this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient();
        $result = $gateway->getSubmissionStatus(
            (string)$submission['submission_number'],
            $mode,
            function (array $request) use ($submission, $mode, $cycleId): void {
                $this->conversation()->captureRequest(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'submission_status',
                    $request,
                    null,
                    $cycleId
                );
            },
            function (array $response) use ($submission, $mode, $cycleId): void {
                $this->conversation()->captureResponse(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'submission_status',
                    $response,
                    null,
                    $cycleId
                );
            },
            (string)$submission['schema_manifest_sha256']
        );
        $now = gmdate('Y-m-d H:i:s');
        if (empty($result['success'])) {
            $summary = trim((string)($result['error'] ?? 'Companies House status could not be refreshed.'));
            $uncertain = !empty($result['transport_unknown']);
            $this->conversation()->updateStatusCycle($cycleId, [
                'poll_transaction_id' => trim((string)($result['transaction_id'] ?? '')) ?: null,
                'acknowledgement_state' => $uncertain ? 'transport_unknown' : 'failed',
                'polled_at' => $now,
            ]);
            $this->conversation()->completeExchange(
                $mode,
                (string)($result['transaction_id'] ?? ''),
                $uncertain ? 'transport_unknown' : 'failed',
                $summary
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS_TABLE . '
                 SET last_polled_at = :polled_at, gateway_status_summary = :summary,
                     updated_at = :updated_at WHERE id = :id',
                ['polled_at' => $now, 'summary' => $summary, 'updated_at' => $now, 'id' => $submissionId]
            );
            if (!$uncertain) {
                $this->sequences()->releaseStatusLock(
                    $submissionId,
                    $cycleId,
                    $mode,
                    (string)$submission['presenter_fingerprint']
                );
                \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS_TABLE . '
                     SET pending_status_cycle_id = NULL, updated_at = :updated_at WHERE id = :id',
                    ['updated_at' => $now, 'id' => $submissionId]
                );
            }
            $this->recordEvent($submissionId, 'status_refresh_failed', 'warning', (string)$submission['lifecycle'], null, $summary, $actor, $this->safeGatewayContext($result));
            return [
                'success' => false,
                'errors' => [$summary],
                'warnings' => array_values(array_filter([(string)($result['evidence_error'] ?? '')])),
                'messages' => [],
                'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
                'changed' => true,
            ];
        }

        $lifecycle = (string)($result['normalized_status'] ?? 'pending');
        if (!in_array($lifecycle, ['pending', 'parked', 'accepted', 'rejected', 'internal_failure'], true)) {
            $lifecycle = 'internal_failure';
        }
        $rawStatus = strtoupper(trim((string)($result['submission_status'] ?? '')));
        $cycleResult = [
            'normalized_status' => $lifecycle,
            'submission_status' => $rawStatus,
            'company_number' => (string)($result['company_number'] ?? ''),
            'customer_reference' => (string)($result['customer_reference'] ?? ''),
            'document_request_key' => (string)($result['document_request_key'] ?? ''),
            'rejections' => (array)($result['rejections'] ?? []),
            'examiner' => (array)($result['examiner'] ?? []),
        ];
        $this->conversation()->updateStatusCycle($cycleId, [
            'poll_transaction_id' => trim((string)($result['transaction_id'] ?? '')) ?: null,
            'raw_status' => $rawStatus !== '' ? $rawStatus : null,
            'normalized_status' => $lifecycle,
            'result_json' => json_encode($cycleResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'acknowledgement_state' => 'required',
            'polled_at' => $now,
        ]);
        $this->conversation()->completeExchange(
            $mode,
            (string)($result['transaction_id'] ?? ''),
            'succeeded'
        );
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET last_polled_at = :polled_at,
                 gateway_status_summary = :summary,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'polled_at' => $now,
                'summary' => 'Companies House status received; StatusAck is required.',
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        $this->recordEvent(
            $submissionId,
            'status_ack_required',
            'info',
            (string)$submission['lifecycle'],
            $rawStatus,
            'Companies House status was received and must be acknowledged before it is committed.',
            $actor,
            $this->safeGatewayContext($result)
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => array_values(array_filter([(string)($result['evidence_error'] ?? '')])),
            'messages' => ['Companies House status received. StatusAck is now required.'],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'status_cycle_id' => $cycleId,
            'changed' => true,
        ];
    }

    public function acknowledgeStatus(int $submissionId, string $actor): array
    {
        $submission = $this->submission($submissionId);
        $cycle = $this->conversation()->latestStatusCycle($submissionId);
        if ($submission === null || !is_array($cycle)) {
            return $this->failure('No Companies House status response is awaiting acknowledgement.');
        }
        if (!in_array((string)$cycle['acknowledgement_state'], ['required', 'failed'], true)) {
            return $this->failure('This Companies House status response cannot be acknowledged.');
        }
        $mode = (string)$submission['environment'];
        $cycleId = (int)$cycle['id'];
        $actor = $this->actor($actor);
        $this->conversation()->updateStatusCycle(
            $cycleId,
            ['acknowledgement_state' => 'sending']
        );
        $gateway = $this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient();
        $result = $gateway->acknowledgeSubmissionStatus(
            $mode,
            (string)$submission['schema_manifest_sha256'],
            function (array $request) use ($submission, $mode, $cycleId): void {
                $this->conversation()->captureRequest(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'status_ack',
                    $request,
                    null,
                    $cycleId
                );
            },
            function (array $response) use ($submission, $mode, $cycleId): void {
                $this->conversation()->captureResponse(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'status_ack',
                    $response,
                    null,
                    $cycleId
                );
            }
        );
        if (empty($result['success'])) {
            $uncertain = !empty($result['transport_unknown']);
            $this->conversation()->updateStatusCycle($cycleId, [
                'acknowledgement_state' => $uncertain ? 'transport_unknown' : 'failed',
                'acknowledgement_transaction_id' => trim((string)($result['transaction_id'] ?? '')) ?: null,
            ]);
            $this->conversation()->completeExchange(
                $mode,
                (string)($result['transaction_id'] ?? ''),
                $uncertain ? 'transport_unknown' : 'failed',
                (string)($result['error'] ?? '')
            );
            return [
                'success' => false,
                'errors' => [trim((string)($result['error'] ?? 'Companies House StatusAck failed.'))],
                'warnings' => $uncertain
                    ? ['Do not poll or resend automatically; the StatusAck outcome requires reconciliation.']
                    : ['Retry the unsent or definitively failed StatusAck before polling again.'],
                'messages' => [],
                'changed' => true,
            ];
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->conversation()->updateStatusCycle($cycleId, [
            'acknowledgement_state' => 'acknowledged',
            'acknowledgement_transaction_id' => trim((string)($result['transaction_id'] ?? '')) ?: null,
            'acknowledged_at' => $now,
        ]);
        $this->conversation()->completeExchange(
            $mode,
            (string)($result['transaction_id'] ?? ''),
            'succeeded'
        );
        $cycle = (array)$this->conversation()->statusCycle($cycleId);
        $status = json_decode((string)($cycle['result_json'] ?? ''), true);
        if (!is_array($status)) {
            return $this->failure(
                'StatusAck succeeded, but the stored Companies House status could not be committed.'
            );
        }
        $this->commitAcknowledgedStatus($submission, $status, $now, $actor);
        $this->sequences()->releaseResolved(
            $submissionId,
            $mode,
            (string)$submission['presenter_fingerprint']
        );
        $this->sequences()->releaseStatusLock(
            $submissionId,
            $cycleId,
            $mode,
            (string)$submission['presenter_fingerprint']
        );
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET pending_status_cycle_id = NULL, updated_at = :updated_at WHERE id = :id',
            ['updated_at' => $now, 'id' => $submissionId]
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'messages' => [
                'StatusAck accepted. Companies House status: '
                . strtoupper((string)$status['normalized_status']) . '.',
            ],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'changed' => true,
        ];
    }

    public function retrieveDocument(int $submissionId, string $actor): array
    {
        $submission = $this->submission($submissionId);
        if ($submission === null || (string)$submission['lifecycle'] !== 'accepted') {
            return $this->failure('Only an accepted Companies House submission can retrieve its document.');
        }
        $requestKey = trim((string)($submission['document_request_key'] ?? ''));
        if ($requestKey === '') {
            return $this->failure('Companies House did not provide a document request key.');
        }
        if (trim((string)($submission['returned_document_sha256'] ?? '')) !== '') {
            return [
                'success' => true,
                'errors' => [],
                'warnings' => [],
                'messages' => ['The accepted Companies House document is already archived.'],
                'changed' => false,
            ];
        }
        $mode = (string)$submission['environment'];
        $gateway = $this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient();
        $result = $gateway->getDocument(
            $requestKey,
            $mode,
            (string)$submission['schema_manifest_sha256'],
            function (array $request) use ($submission, $mode): void {
                $this->conversation()->captureRequest(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'get_document',
                    $request
                );
            },
            function (array $response) use ($submission, $mode): void {
                $this->conversation()->captureResponse(
                    $submission,
                    $mode,
                    (string)$submission['submission_number'],
                    'get_document',
                    $response
                );
            }
        );
        if (empty($result['success'])) {
            $this->conversation()->completeExchange(
                $mode,
                (string)($result['transaction_id'] ?? ''),
                !empty($result['transport_unknown']) ? 'transport_unknown' : 'failed',
                (string)($result['error'] ?? '')
            );
            return $this->failure(
                trim((string)($result['error'] ?? 'Companies House document retrieval failed.'))
            );
        }
        if (!$this->sameCompanyNumber(
            (string)$submission['company_number'],
            (string)($result['company_number'] ?? '')
        )) {
            return $this->failure(
                'Companies House returned a document for a different company; the PDF was not archived.'
            );
        }
        $documentId = trim((string)($result['document_id'] ?? ''));
        $safeId = preg_replace('/[^A-Za-z0-9._-]+/', '-', $documentId) ?: 'accepted-accounts';
        $stored = $this->archives()->store(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'companies_house',
            $mode,
            (string)$submission['submission_number'],
            'accepted',
            'companies-house-document-' . substr($safeId, 0, 80) . '.pdf',
            (string)$result['document_data']
        );
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET returned_document_id = :document_id,
                 returned_document_path = :document_path,
                 returned_document_sha256 = :document_sha256,
                 document_retrieved_at = :retrieved_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'document_id' => $documentId !== '' ? $documentId : null,
                'document_path' => $stored['path'],
                'document_sha256' => $stored['sha256'],
                'retrieved_at' => $now,
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        $this->conversation()->completeExchange(
            $mode,
            (string)($result['transaction_id'] ?? ''),
            'succeeded'
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'messages' => ['The accepted Companies House document was retrieved and archived.'],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'changed' => true,
        ];
    }

    public function reconcileStatusExchange(
        int $submissionId,
        string $resolution,
        string $actor
    ): array {
        $submission = $this->submission($submissionId);
        $cycle = $this->conversation()->latestStatusCycle($submissionId);
        if ($submission === null
            || !is_array($cycle)
            || (string)$cycle['acknowledgement_state'] !== 'transport_unknown') {
            return $this->failure('No uncertain Companies House status exchange requires reconciliation.');
        }
        $resolution = strtolower(trim($resolution));
        $hasStatus = trim((string)($cycle['result_json'] ?? '')) !== '';
        if (($resolution === 'ack_confirmed' && !$hasStatus)
            || ($resolution === 'poll_not_received' && $hasStatus)
            || !in_array($resolution, ['ack_confirmed', 'poll_not_received'], true)) {
            return $this->failure('The reconciliation resolution does not match the uncertain exchange.');
        }
        $actor = $this->actor($actor);
        $now = gmdate('Y-m-d H:i:s');
        if ($resolution === 'ack_confirmed') {
            $status = json_decode((string)$cycle['result_json'], true);
            if (!is_array($status)) {
                return $this->failure('The stored Companies House status cannot be reconciled.');
            }
            $this->conversation()->updateStatusCycle((int)$cycle['id'], [
                'acknowledgement_state' => 'acknowledged',
                'acknowledged_at' => $now,
            ]);
            $this->commitAcknowledgedStatus($submission, $status, $now, $actor);
            $this->sequences()->releaseResolved(
                $submissionId,
                (string)$submission['environment'],
                (string)$submission['presenter_fingerprint']
            );
            $message = 'The StatusAck was manually reconciled as received by Companies House.';
        } else {
            $this->conversation()->updateStatusCycle((int)$cycle['id'], [
                'acknowledgement_state' => 'failed',
            ]);
            $message = 'The uncertain status request was manually reconciled as not received.';
        }
        $this->sequences()->releaseStatusLock(
            $submissionId,
            (int)$cycle['id'],
            (string)$submission['environment'],
            (string)$submission['presenter_fingerprint']
        );
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET pending_status_cycle_id = NULL,
                 gateway_status_summary = :summary,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'summary' => $message,
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        $this->recordEvent(
            $submissionId,
            'status_exchange_reconciled',
            'warning',
            (string)$submission['lifecycle'],
            null,
            $message,
            $actor,
            ['resolution' => $resolution]
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'messages' => [$message],
            'changed' => true,
        ];
    }

    private function retrieveAcceptedDocumentIfAvailable(
        int $submissionId,
        string $actor,
        array $acknowledgement
    ): array {
        $submission = $this->submission($submissionId);
        if ($submission === null
            || (string)$submission['lifecycle'] !== 'accepted'
            || trim((string)($submission['document_request_key'] ?? '')) === '') {
            return $acknowledgement;
        }
        $document = $this->retrieveDocument($submissionId, $actor);
        if (!empty($document['success'])) {
            $acknowledgement['messages'] = array_values(array_merge(
                (array)($acknowledgement['messages'] ?? []),
                (array)($document['messages'] ?? [])
            ));
        } else {
            $acknowledgement['warnings'] = array_values(array_merge(
                (array)($acknowledgement['warnings'] ?? []),
                (array)($document['errors'] ?? [])
            ));
        }
        return $acknowledgement;
    }

    private function commitAcknowledgedStatus(
        array $submission,
        array $status,
        string $now,
        string $actor
    ): void {
        $lifecycle = (string)($status['normalized_status'] ?? 'internal_failure');
        if (!in_array($lifecycle, ['pending', 'parked', 'accepted', 'rejected', 'internal_failure'], true)) {
            $lifecycle = 'internal_failure';
        }
        $rawStatus = strtoupper(trim((string)($status['submission_status'] ?? '')));
        $rejections = (array)($status['rejections'] ?? []);
        $firstRejection = is_array($rejections[0] ?? null) ? $rejections[0] : [];
        $examiner = (array)($status['examiner'] ?? []);
        $summary = 'Companies House status: ' . ($rawStatus !== '' ? $rawStatus : strtoupper($lifecycle)) . '.';
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle, raw_gateway_status = :raw_status,
                 gateway_submission_reference = :gateway_reference,
                 gateway_status_summary = :summary, rejection_code = :rejection_code,
                 rejection_description = :rejection_description,
                 examiner_comments = :examiner_comments,
                 document_request_key = :document_request_key,
                 status_updated_at = :status_updated_at, accepted_at = :accepted_at,
                 rejected_at = :rejected_at, updated_at = :updated_at
             WHERE id = :id',
            [
                'lifecycle' => $lifecycle,
                'raw_status' => $rawStatus !== '' ? $rawStatus : null,
                'gateway_reference' => trim((string)($status['customer_reference'] ?? ''))
                    ?: ($submission['gateway_submission_reference'] ?? null),
                'summary' => $summary,
                'rejection_code' => trim((string)($firstRejection['code'] ?? '')) ?: null,
                'rejection_description' => trim((string)($firstRejection['description'] ?? '')) ?: null,
                'examiner_comments' => trim((string)($examiner['comment'] ?? '')) ?: null,
                'document_request_key' => trim((string)($status['document_request_key'] ?? '')) ?: null,
                'status_updated_at' => $now,
                'accepted_at' => $lifecycle === 'accepted' ? $now : ($submission['accepted_at'] ?? null),
                'rejected_at' => $lifecycle === 'rejected' ? $now : ($submission['rejected_at'] ?? null),
                'updated_at' => $now,
                'id' => (int)$submission['id'],
            ]
        );
        $this->archives()->updateLifecycle(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'companies_house',
            (string)$submission['environment'],
            (string)$submission['submission_number'],
            $lifecycle
        );
        $this->recordEvent(
            (int)$submission['id'],
            'status_acknowledged',
            in_array($lifecycle, ['rejected', 'internal_failure'], true)
                ? 'error'
                : ($lifecycle === 'accepted' ? 'success' : 'info'),
            $lifecycle,
            $rawStatus,
            $summary,
            $actor,
            ['document_request_key_returned' => trim((string)($status['document_request_key'] ?? '')) !== ''],
            (string)($firstRejection['code'] ?? ''),
            (string)($firstRejection['description'] ?? ''),
            (string)($examiner['comment'] ?? '')
        );
        $this->recordFilingEvidenceEvent(
            $submission,
            'companies_house_' . $lifecycle,
            in_array($lifecycle, ['rejected', 'internal_failure'], true)
                ? 'error'
                : ($lifecycle === 'accepted' ? 'success' : 'info'),
            $summary,
            $actor,
            ['raw_status' => $rawStatus, 'status_acknowledged' => true]
        );
    }

    private function sameCompanyNumber(string $expected, string $actual): bool
    {
        $expected = strtoupper(ltrim(trim($expected), '0'));
        $actual = strtoupper(ltrim(trim($actual), '0'));
        return $expected !== '' && hash_equals($expected, $actual);
    }

    private function selection(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }
        $company = \InterfaceDB::fetchOne('SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId]);
        $period = \InterfaceDB::fetchOne(
            'SELECT * FROM accounting_periods WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($company) || !is_array($period)) {
            return null;
        }

        return ['company' => $company, 'accounting_period' => $period];
    }

    private function exactOriginalDocument(array $selection): ?array
    {
        foreach (['companies_house_documents', 'companies_house_document_facts', 'companies_house_taxonomy_concepts'] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                return null;
            }
        }
        $company = (array)$selection['company'];
        $period = (array)$selection['accounting_period'];
        $companyNumber = strtoupper(trim((string)($company['company_number'] ?? '')));
        if ($companyNumber === '') {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT d.*
             FROM companies_house_documents d
             WHERE (d.company_id = :company_id OR UPPER(d.company_number) = :company_number)
               AND EXISTS (
                   SELECT 1
                   FROM companies_house_document_facts period_fact
                   INNER JOIN companies_house_taxonomy_concepts period_concept
                     ON period_concept.id = period_fact.concept_fk
                   WHERE period_fact.document_fk = d.id
                     AND period_concept.short_name IN (:period_end_concept, :balance_sheet_concept)
                     AND period_fact.normalised_date = :period_end
               )
             ORDER BY d.filing_date DESC, d.id DESC
             LIMIT 1',
            [
                'company_id' => (int)$company['id'],
                'company_number' => $companyNumber,
                'period_end_concept' => 'EndDateForPeriodCoveredByReport',
                'balance_sheet_concept' => 'BalanceSheetDate',
                'period_end' => (string)$period['period_end'],
            ]
        );
        if (!is_array($row)) {
            return null;
        }
        $productionSoftware = (string)(\InterfaceDB::fetchColumn(
            'SELECT COALESCE(NULLIF(f.normalised_text, :empty), f.raw_value)
             FROM companies_house_document_facts f
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             WHERE f.document_fk = :document_id AND c.short_name = :concept
             ORDER BY f.id ASC LIMIT 1',
            ['empty' => '', 'document_id' => (int)$row['id'], 'concept' => 'NameProductionSoftware']
        ) ?: '');
        $metadata = json_decode((string)($row['raw_metadata_json'] ?? ''), true);
        $paperFiled = is_array($metadata) && !empty($metadata['paper_filed']);
        $detectedChannel = $paperFiled
            ? 'paper'
            : ($productionSoftware !== ''
                ? (stripos($productionSoftware, 'Companies House') !== false ? 'webfiling' : 'software')
                : 'unknown');
        $row['production_software'] = $productionSoftware;
        $row['detected_channel'] = $detectedChannel;

        return $row;
    }

    private function eligibility(array $selection, ?array $original): array
    {
        $default = [
            'id' => 0,
            'decision' => 'pending',
            'detected_channel' => (string)($original['detected_channel'] ?? 'unknown'),
            'original_document_id' => (int)($original['id'] ?? 0),
            'original_transaction_id' => (string)($original['transaction_id'] ?? ''),
            'original_document_external_id' => (string)($original['document_id'] ?? ''),
            'evidence' => [
                'production_software' => (string)($original['production_software'] ?? ''),
                'filing_date' => (string)($original['filing_date'] ?? ''),
                'text' => '',
                'reference' => '',
                'received_at' => '',
            ],
        ];
        if ($original === null || !\InterfaceDB::tableExists(self::ELIGIBILITY_TABLE)) {
            return $default;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::ELIGIBILITY_TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND original_transaction_id = :transaction_id
             LIMIT 1',
            [
                'company_id' => (int)$selection['company']['id'],
                'accounting_period_id' => (int)$selection['accounting_period']['id'],
                'transaction_id' => (string)$original['transaction_id'],
            ]
        );
        if (!is_array($row)) {
            return $default;
        }

        return [
            'id' => (int)$row['id'],
            'decision' => (string)$row['decision'],
            'detected_channel' => (string)$row['original_filing_channel'],
            'original_document_id' => (int)($row['original_document_id'] ?? $original['id']),
            'original_transaction_id' => (string)$row['original_transaction_id'],
            'original_document_external_id' => (string)$row['original_document_external_id'],
            'evidence' => [
                'production_software' => (string)($original['production_software'] ?? ''),
                'filing_date' => (string)($original['filing_date'] ?? ''),
                'text' => (string)$row['evidence_text'],
                'reference' => (string)($row['evidence_reference'] ?? ''),
                'received_at' => (string)($row['evidence_received_at'] ?? ''),
            ],
            'response_reference' => (string)($row['evidence_reference'] ?? ''),
            'decided_by' => (string)($row['decided_by'] ?? ''),
            'decided_at' => (string)($row['decided_at'] ?? ''),
        ];
    }

    private function readiness(int $companyId, int $accountingPeriodId): array
    {
        try {
            $readiness = ($this->readinessService ?? new IxbrlReadinessService())
                ->getReadiness($companyId, $accountingPeriodId);
            if (!isset($readiness['filing_errors'])) {
                $readiness['filing_errors'] = [];
            }
            return $readiness;
        } catch (\Throwable $exception) {
            return ['ready_for_filing' => false, 'filing_errors' => [$exception->getMessage()]];
        }
    }

    private function latestSubmission(int $companyId, int $accountingPeriodId): ?array
    {
        if (!\InterfaceDB::tableExists(self::SUBMISSIONS_TABLE)) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS_TABLE . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return is_array($row) ? $this->normaliseSubmission($row) : null;
    }

    private function submission(int $submissionId): ?array
    {
        if ($submissionId <= 0 || !\InterfaceDB::tableExists(self::SUBMISSIONS_TABLE)) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT s.*, e.decision AS eligibility_decision,
                    c.company_name, c.company_number
             FROM ' . self::SUBMISSIONS_TABLE . ' s
             INNER JOIN ' . self::ELIGIBILITY_TABLE . ' e ON e.id = s.eligibility_id
             INNER JOIN companies c ON c.id = s.company_id
             WHERE s.id = :id LIMIT 1',
            ['id' => $submissionId]
        );

        return is_array($row) ? $row : null;
    }

    private function normaliseSubmission(array $row): array
    {
        $declarations = json_decode((string)($row['revision_declarations_json'] ?? ''), true);
        $row['revision_declarations'] = is_array($declarations) ? $declarations : [];
        unset($row['revision_declarations_json']);
        $number = trim((string)($row['submission_number'] ?? ''));
        if ($number !== '' && isset($row['company_id'], $row['environment'])) {
            try {
                $row['transmission_archive'] = $this->archives()->find(
                    (int)$row['company_id'],
                    'companies_house',
                    (string)$row['environment'],
                    $number
                );
            } catch (\Throwable) {
                $row['transmission_archive'] = null;
            }
        } else {
            $row['transmission_archive'] = null;
        }

        return $row;
    }

    private function testAccepted(int $eligibilityId): bool
    {
        return $eligibilityId > 0
            && \InterfaceDB::tableExists(self::SUBMISSIONS_TABLE)
            && (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM ' . self::SUBMISSIONS_TABLE . '
                 WHERE eligibility_id = :eligibility_id
                   AND environment = :environment
                   AND lifecycle = :lifecycle',
                ['eligibility_id' => $eligibilityId, 'environment' => 'TEST', 'lifecycle' => 'accepted']
            ) > 0;
    }

    private function credentialsConfigured(string $environment): bool
    {
        return $this->credentials()->configured($environment);
    }

    /** @return list<array<string,mixed>> */
    public function submissionHistory(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists(self::SUBMISSIONS_TABLE)) {
            return [];
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS_TABLE . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY id DESC LIMIT 100',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return array_map(fn(array $row): array => $this->normaliseSubmission($row), $rows);
    }

    public function protocolEvidenceFile(
        int $submissionId,
        int $exchangeId,
        string $direction
    ): array {
        $submission = $this->submission($submissionId);
        if ($submission === null) {
            throw new \RuntimeException('The Companies House submission was not found.');
        }
        return $this->conversation()->evidenceFile($submissionId, $exchangeId, $direction);
    }

    private function failConsumedSubmission(
        int $submissionId,
        string $environment,
        string $presenterFingerprint,
        string $message,
        string $actor
    ): void {
        $submission = $this->submission($submissionId);
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle,
                 gateway_status_summary = :summary,
                 status_updated_at = :updated_at,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'lifecycle' => 'failed',
                'summary' => $message,
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        $this->sequences()->releaseResolved($submissionId, $environment, $presenterFingerprint);
        if (is_array($submission) && trim((string)($submission['submission_number'] ?? '')) !== '') {
            try {
                $this->archives()->updateLifecycle(
                    (int)$submission['company_id'],
                    (int)$submission['accounting_period_id'],
                    'companies_house',
                    $environment,
                    (string)$submission['submission_number'],
                    'failed'
                );
            } catch (\Throwable) {
            }
        }
        $this->recordEvent(
            $submissionId,
            'pre_send_failure',
            'error',
            'failed',
            null,
            $message,
            $actor
        );
    }

    private function credentials(): CompaniesHouseAccountsCredentialService
    {
        return $this->credentialService ?? new CompaniesHouseAccountsCredentialService();
    }

    private function companyDataCredentials(): CompaniesHouseCompanyDataCredentialService
    {
        return $this->companyDataCredentialService ?? new CompaniesHouseCompanyDataCredentialService();
    }

    private function conversation(): CompaniesHouseProtocolConversationService
    {
        return $this->conversationService ?? new CompaniesHouseProtocolConversationService($this->archiveService);
    }

    private function performCompanyDataPreflight(
        array $submission,
        string $companyAuthCode,
        string $actor,
        string $environment,
        int $schemaSnapshotId,
        string $schemaManifestSha256,
        bool $developerStep
    ): array {
        $outputCredentials = $this->companyDataCredentials()->load($environment);
        $preflight = $this->conversation()->beginPreflight(
            $submission,
            $environment,
            $schemaSnapshotId,
            $schemaManifestSha256,
            hash('sha256', strtoupper((string)$outputCredentials['presenter_id'])),
            $companyAuthCode,
            $actor,
            $developerStep
        );
        $preflightId = (int)$preflight['id'];
        $gateway = $this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient();
        $result = $gateway->checkCompanyAuthentication(
            (string)$submission['company_number'],
            $companyAuthCode,
            $environment,
            $schemaManifestSha256,
            function (array $request) use ($submission, $environment, $preflight, $preflightId): void {
                $this->conversation()->captureRequest(
                    $submission,
                    $environment,
                    (string)$preflight['archive_reference'],
                    'company_data',
                    $request,
                    $preflightId
                );
            },
            function (array $response) use ($submission, $environment, $preflight, $preflightId): void {
                $this->conversation()->captureResponse(
                    $submission,
                    $environment,
                    (string)$preflight['archive_reference'],
                    'company_data',
                    $response,
                    $preflightId
                );
            }
        );
        $this->conversation()->finishPreflight($preflightId, $result);
        $result['preflight_id'] = $preflightId;
        if (empty($result['success'])) {
            $result['error'] = trim((string)($result['error'] ?? ''))
                ?: 'Companies House CompanyData rejected the authentication preflight.';
        }

        return $result;
    }

    private function sequences(): CompaniesHouseSubmissionSequenceService
    {
        return $this->sequenceService ?? new CompaniesHouseSubmissionSequenceService();
    }

    private function archives(): TransmissionArchiveService
    {
        return $this->archiveService ?? new TransmissionArchiveService();
    }

    private function recordEvent(
        int $submissionId,
        string $eventType,
        string $level,
        ?string $lifecycle,
        ?string $rawStatus,
        string $message,
        string $actor,
        array $context = [],
        string $gatewayCode = '',
        string $gatewayDescription = '',
        string $examinerComments = ''
    ): void {
        if (!\InterfaceDB::tableExists(self::EVENTS_TABLE)) {
            return;
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::EVENTS_TABLE . ' (
                submission_id, event_type, event_level, lifecycle, raw_gateway_status,
                event_message, gateway_code, gateway_description, examiner_comments,
                redacted_context_json, actor, created_at
             ) VALUES (
                :submission_id, :event_type, :event_level, :lifecycle, :raw_status,
                :message, :gateway_code, :gateway_description, :examiner_comments,
                :context, :actor, :created_at
             )',
            [
                'submission_id' => $submissionId,
                'event_type' => $eventType,
                'event_level' => $level,
                'lifecycle' => $lifecycle,
                'raw_status' => $rawStatus,
                'message' => $message,
                'gateway_code' => $gatewayCode !== '' ? $gatewayCode : null,
                'gateway_description' => $gatewayDescription !== '' ? $gatewayDescription : null,
                'examiner_comments' => $examinerComments !== '' ? $examinerComments : null,
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'actor' => $actor,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]
        );
    }

    private function recordFilingEvidenceEvent(
        array $submission,
        string $eventType,
        string $status,
        string $message,
        string $actor,
        array $context = []
    ): void {
        $bundleId = (int)($submission['evidence_bundle_id'] ?? 0);
        if ($bundleId <= 0) {
            return;
        }
        (new FilingEvidenceService())->recordEvent(
            $bundleId,
            $eventType,
            $status,
            $actor,
            $message,
            ['submission_id' => (int)($submission['id'] ?? 0)] + $context
        );
    }

    private function safeGatewayContext(array $result): array
    {
        return [
            'status_code' => (int)($result['status_code'] ?? 0),
            'transaction_id' => (string)($result['transaction_id'] ?? ''),
            'response_transaction_id' => (string)($result['response_transaction_id'] ?? ''),
            'qualifier' => (string)($result['qualifier'] ?? ''),
            'transport_unknown' => !empty($result['transport_unknown']),
            'gateway_errors' => (array)($result['gateway_errors'] ?? []),
        ];
    }

    private function reportProgress(mixed $progress, string $message, int $percent): void
    {
        if ($progress instanceof \ActionProgressFramework) {
            $progress->report($message, $percent);
        } elseif (is_callable($progress)) {
            $progress($message, $percent);
        }
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new \InvalidArgumentException('An authenticated actor is required.');
        }

        return mb_substr($actor, 0, 100);
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'errors' => [$message], 'warnings' => [], 'messages' => [], 'changed' => false];
    }

    private function emptyContext(string $message): array
    {
        return [
            'company' => [],
            'accounting_period' => [],
            'locked' => false,
            'feature' => [
                'mode' => 'DISABLED',
                'enabled' => false,
                'credentials_configured' => false,
                'company_data_credentials_configured' => false,
                'protocol_ready' => false,
                'developer_binding_configured' => false,
                'live_approved' => false,
                'test_accepted' => false,
            ],
            'eligibility' => ['decision' => 'pending', 'detected_channel' => 'unknown', 'original_document_id' => 0, 'evidence' => []],
            'readiness' => ['ready_for_filing' => false, 'filing_errors' => [$message]],
            'submission' => null,
            'preflight' => null,
            'status_cycle' => null,
            'exchanges' => [],
            'prepared_artifact' => null,
            'sequence' => [
                'configured' => false,
                'next_number' => '',
                'last_issued_number' => null,
                'in_flight_submission_id' => null,
                'status_in_flight_submission_id' => null,
                'status_in_flight_cycle_id' => null,
                'presenter_fingerprint' => '',
            ],
            'can_prepare' => false,
            'can_submit' => false,
            'preparation_blockers' => [$message],
            'submission_blockers' => [$message],
            'blockers' => [$message],
        ];
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $value = array_map(fn(mixed $item): mixed => is_array($item) ? json_decode($this->canonicalJson($item), true) : $item, $value);
            } else {
                ksort($value);
                foreach ($value as $key => $item) {
                    if (is_array($item)) {
                        $value[$key] = json_decode($this->canonicalJson($item), true);
                    }
                }
            }
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not fingerprint the Companies House submission basis.');
        }

        return $json;
    }
}
