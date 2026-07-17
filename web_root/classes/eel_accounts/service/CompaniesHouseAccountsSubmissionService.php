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
use eel_accounts\Store\AccountingConfigurationStore;

final class CompaniesHouseAccountsSubmissionService
{
    private const ELIGIBILITY_TABLE = 'companies_house_accounts_eligibility';
    private const SUBMISSIONS_TABLE = 'companies_house_accounts_submissions';
    private const EVENTS_TABLE = 'companies_house_accounts_submission_events';

    public function __construct(
        private readonly ?IxbrlReadinessService $readinessService = null,
        private readonly ?IxbrlRevisedAccountsArtifactService $artifactService = null,
        private readonly ?CompaniesHouseAccountsGatewayClient $gatewayClient = null,
        private readonly ?YearEndLockService $lockService = null,
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
        if ($mode === 'LIVE' && !$liveApproved) {
            $submissionBlockers[] = 'LIVE revised-accounts filing has not been explicitly approved in server configuration.';
        }
        if ($mode === 'LIVE' && !$testAccepted) {
            $submissionBlockers[] = 'A Companies House TEST revised-accounts submission must be accepted before LIVE filing.';
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
                'live_approved' => $liveApproved,
                'test_accepted' => $testAccepted,
            ],
            'eligibility' => $eligibility,
            'readiness' => $readiness,
            'submission' => $submission,
            'prepared_artifact' => $preparedArtifact,
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
        $artifact = ($this->artifactService ?? new IxbrlRevisedAccountsArtifactService())
            ->prepare($companyId, $accountingPeriodId, $input);
        if (empty($artifact['success'])) {
            return [
                'success' => false,
                'errors' => (array)($artifact['errors'] ?? ['The revised accounts could not be prepared.']),
                'warnings' => (array)($artifact['warnings'] ?? []),
                'messages' => [],
            ];
        }

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

        $submissionNumber = $this->newSubmissionNumber($environment);
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::transaction(function () use (
            $eligibility,
            $context,
            $artifact,
            $idempotencyKey,
            $submissionNumber,
            $environment,
            $actor,
            $now,
            $companyId,
            $accountingPeriodId
        ): void {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::SUBMISSIONS_TABLE . ' (
                    eligibility_id, company_id, accounting_period_id, original_document_id,
                    original_transaction_id, original_document_external_id,
                    ixbrl_generation_run_id, environment, filing_type, lifecycle,
                    submission_number, revised_artifact_path, revised_artifact_sha256,
                    basis_hash, idempotency_key, revision_declarations_json,
                    prepared_by, prepared_at, status_updated_at, created_at, updated_at
                 ) VALUES (
                    :eligibility_id, :company_id, :accounting_period_id, :original_document_id,
                    :transaction_id, :external_id, :run_id, :environment, :filing_type,
                    :lifecycle, :submission_number, :artifact_path, :artifact_sha256,
                    :basis_hash, :idempotency_key, :declarations, :prepared_by,
                    :prepared_at, :status_updated_at, :created_at, :updated_at
                 )',
                [
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
                    'submission_number' => $submissionNumber,
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

    public function submitRevision(int $submissionId, string $companyAuthCode, string $actor): array
    {
        $submission = $this->submission($submissionId);
        if ($submission === null) {
            return $this->failure('The revised-accounts submission was not found.');
        }
        if ((string)$submission['lifecycle'] !== 'prepared') {
            return $this->failure('Only a prepared revised-accounts artifact can be submitted.');
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
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle, submitted_by = :actor, submitted_at = :submitted_at,
                 status_updated_at = :status_updated_at, updated_at = :updated_at
             WHERE id = :id AND lifecycle = :expected_lifecycle',
            [
                'lifecycle' => 'submitting',
                'actor' => $actor,
                'submitted_at' => $now,
                'status_updated_at' => $now,
                'updated_at' => $now,
                'id' => $submissionId,
                'expected_lifecycle' => 'prepared',
            ]
        );
        $current = $this->submission($submissionId);
        if ($current === null || (string)$current['lifecycle'] !== 'submitting') {
            return $this->failure('The submission state changed before it could be sent.');
        }

        $declarations = json_decode((string)$submission['revision_declarations_json'], true);
        $dateSigned = is_array($declarations)
            ? trim((string)($declarations['revision_approval_date'] ?? ''))
            : '';
        $result = ($this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient())->submitAccounts([
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
        ], $mode);

        $success = !empty($result['success']);
        $transportUnknown = !empty($result['transport_unknown']);
        $lifecycle = $success ? 'pending' : ($transportUnknown ? 'transport_unknown' : 'failed');
        $gatewayErrors = (array)($result['gateway_errors'] ?? []);
        $firstError = is_array($gatewayErrors[0] ?? null) ? $gatewayErrors[0] : [];
        $summary = $success
            ? 'Companies House acknowledged the revised-accounts submission.'
            : trim((string)($result['error'] ?? 'Companies House did not acknowledge the submission.'));
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

        return [
            'success' => $success,
            'errors' => $success ? [] : [$summary],
            'warnings' => $transportUnknown ? ['Do not resubmit; refresh the same submission number first.'] : [],
            'messages' => $success ? [$summary] : [],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'changed' => true,
        ];
    }

    public function refreshStatus(int $submissionId, string $actor): array
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
        $actor = $this->actor($actor);
        $result = ($this->gatewayClient ?? new CompaniesHouseAccountsGatewayClient())
            ->getSubmissionStatus((string)$submission['submission_number'], $mode);
        $now = gmdate('Y-m-d H:i:s');
        if (empty($result['success'])) {
            $summary = trim((string)($result['error'] ?? 'Companies House status could not be refreshed.'));
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS_TABLE . '
                 SET last_polled_at = :polled_at, gateway_status_summary = :summary,
                     updated_at = :updated_at WHERE id = :id',
                ['polled_at' => $now, 'summary' => $summary, 'updated_at' => $now, 'id' => $submissionId]
            );
            $this->recordEvent($submissionId, 'status_refresh_failed', 'warning', (string)$submission['lifecycle'], null, $summary, $actor, $this->safeGatewayContext($result));
            return [
                'success' => false,
                'errors' => [$summary],
                'warnings' => [],
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
        $rejections = (array)($result['rejections'] ?? []);
        $firstRejection = is_array($rejections[0] ?? null) ? $rejections[0] : [];
        $examiner = (array)($result['examiner'] ?? []);
        $summary = 'Companies House status: ' . ($rawStatus !== '' ? $rawStatus : strtoupper($lifecycle)) . '.';
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::SUBMISSIONS_TABLE . '
             SET lifecycle = :lifecycle, raw_gateway_status = :raw_status,
                 gateway_submission_reference = :gateway_reference,
                 gateway_status_summary = :summary, rejection_code = :rejection_code,
                 rejection_description = :rejection_description,
                 examiner_comments = :examiner_comments, last_polled_at = :polled_at,
                 status_updated_at = :status_updated_at, accepted_at = :accepted_at,
                 rejected_at = :rejected_at, updated_at = :updated_at
             WHERE id = :id',
            [
                'lifecycle' => $lifecycle,
                'raw_status' => $rawStatus !== '' ? $rawStatus : null,
                'gateway_reference' => trim((string)($result['customer_reference'] ?? '')) ?: ($submission['gateway_submission_reference'] ?? null),
                'summary' => $summary,
                'rejection_code' => trim((string)($firstRejection['code'] ?? '')) ?: null,
                'rejection_description' => trim((string)($firstRejection['description'] ?? '')) ?: null,
                'examiner_comments' => trim((string)($examiner['comment'] ?? '')) ?: null,
                'polled_at' => $now,
                'status_updated_at' => $now,
                'accepted_at' => $lifecycle === 'accepted' ? $now : ($submission['accepted_at'] ?? null),
                'rejected_at' => $lifecycle === 'rejected' ? $now : ($submission['rejected_at'] ?? null),
                'updated_at' => $now,
                'id' => $submissionId,
            ]
        );
        $this->recordEvent(
            $submissionId,
            'status_updated',
            in_array($lifecycle, ['rejected', 'internal_failure'], true) ? 'error' : ($lifecycle === 'accepted' ? 'success' : 'info'),
            $lifecycle,
            $rawStatus,
            $summary,
            $actor,
            $this->safeGatewayContext($result),
            (string)($firstRejection['code'] ?? ''),
            (string)($firstRejection['description'] ?? ''),
            (string)($examiner['comment'] ?? '')
        );

        return [
            'success' => true,
            'errors' => [],
            'warnings' => [],
            'messages' => [$summary],
            'submission' => $this->normaliseSubmission((array)$this->submission($submissionId)),
            'changed' => true,
        ];
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
        try {
            $prefix = 'companieshouse.accounts_filing.' . strtolower($environment) . '.';
            return trim((string)\SecurityStore::loadFact($prefix . 'presenter_id')) !== ''
                && trim((string)\SecurityStore::loadFact($prefix . 'presenter_code')) !== ''
                && trim((string)\SecurityStore::loadFact($prefix . 'package_reference')) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function newSubmissionNumber(string $environment): string
    {
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = '';
            for ($i = 0; $i < 6; $i++) {
                $number .= $characters[random_int(0, strlen($characters) - 1)];
            }
            $exists = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM ' . self::SUBMISSIONS_TABLE . '
                 WHERE environment = :environment AND submission_number = :submission_number',
                ['environment' => $environment, 'submission_number' => $number]
            );
            if ($exists === 0) {
                return $number;
            }
        }
        throw new \RuntimeException('Could not allocate a unique Companies House submission number.');
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
            'feature' => ['mode' => 'DISABLED', 'enabled' => false, 'credentials_configured' => false, 'live_approved' => false, 'test_accepted' => false],
            'eligibility' => ['decision' => 'pending', 'detected_channel' => 'unknown', 'original_document_id' => 0, 'evidence' => []],
            'readiness' => ['ready_for_filing' => false, 'filing_errors' => [$message]],
            'submission' => null,
            'prepared_artifact' => null,
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
