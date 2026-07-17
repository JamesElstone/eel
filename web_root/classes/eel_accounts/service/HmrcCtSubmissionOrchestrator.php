<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Client\HmrcCtGatewayClient;
use eel_accounts\Client\HmrcCtGatewayClientInterface;

/**
 * Coordinates one frozen CT600 through the asynchronous GovTalk lifecycle.
 *
 * The browser supplies only an owned submission ID.  Environment, company,
 * periods, UTR, transaction identifiers and endpoints are always recovered
 * from server configuration or persisted state.
 */
final class HmrcCtSubmissionOrchestrator
{
    private ?HmrcCtGatewayClientInterface $gateway;
    private ?object $readiness;
    private ?object $returnFactory;
    private ?object $xmlBuilder;
    private ?object $envelopeBuilder;
    private ?object $validator;
    private ?object $repository;
    private ?object $storage;
    private ?object $configuration;
    private ?object $taxonomy;
    private ?object $statutoryAcceptance;
    private ?\Closure $clock;
    private ?\Closure $statutoryAcceptanceRecorder;

    /**
     * Concrete services are deliberately lazy: the read model calls only
     * environment(), and a page GET must not initialise storage or touch the
     * submission schema.  Object-shaped injections keep lifecycle tests free
     * of the live database while the production defaults remain strongly
     * bounded at the public gateway interface.
     */
    public function __construct(
        ?HmrcCtGatewayClientInterface $gateway = null,
        ?object $readiness = null,
        ?object $returnFactory = null,
        ?object $xmlBuilder = null,
        ?object $envelopeBuilder = null,
        ?object $validator = null,
        ?object $repository = null,
        ?object $storage = null,
        ?object $configuration = null,
        ?object $taxonomy = null,
        ?callable $clock = null,
        ?callable $statutoryAcceptanceRecorder = null,
        ?object $statutoryAcceptance = null,
    ) {
        $this->gateway = $gateway;
        $this->readiness = $readiness;
        $this->returnFactory = $returnFactory;
        $this->xmlBuilder = $xmlBuilder;
        $this->envelopeBuilder = $envelopeBuilder;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->storage = $storage;
        $this->configuration = $configuration;
        $this->taxonomy = $taxonomy;
        $this->statutoryAcceptance = $statutoryAcceptance;
        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
        $this->statutoryAcceptanceRecorder = $statutoryAcceptanceRecorder === null
            ? null
            : \Closure::fromCallable($statutoryAcceptanceRecorder);
    }

    public function environment(): string
    {
        $environment = strtoupper(trim((string)$this->configuration()->environment()));
        if (!in_array($environment, [
            HmrcCtConfigurationService::TEST,
            HmrcCtConfigurationService::TIL,
            HmrcCtConfigurationService::LIVE,
        ], true)) {
            throw new \RuntimeException('The server-controlled HMRC CT environment is invalid.');
        }

        return $environment;
    }

    /** @return array<string, mixed> */
    public function prepare(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        string $actor,
        array $declaration = [],
    ): array {
        $this->positiveIds($companyId, $accountingPeriodId, $ctPeriodId);
        $actor = $this->actor($actor);
        $environment = $this->environment();
        $this->repository()->requireSchema();
        $this->assertTestDataScope($companyId, $environment);

        $readiness = $this->readiness()->assess(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $environment
        );
        $this->assertReadiness($readiness, 'prepare');

        $inputs = $this->returnFactory()->build($readiness, $declaration);
        $return = $inputs['return'] ?? null;
        $accounts = $inputs['accounts'] ?? null;
        $computation = $inputs['computation'] ?? null;
        if (!$return instanceof Ct600ReturnData
            || !$accounts instanceof Ct600IxbrlArtifact
            || !$computation instanceof Ct600IxbrlArtifact) {
            throw new \RuntimeException('The CT600 return-data factory returned an invalid filing contract.');
        }
        if ($return->companyId !== $companyId
            || $return->accountingPeriodId !== $accountingPeriodId
            || $return->ctPeriodId !== $ctPeriodId) {
            throw new \DomainException('The CT600 return-data factory changed the selected filing scope.');
        }

        $taxonomyDecision = $this->taxonomy()->validate($return, $accounts, $computation);
        if (empty($taxonomyDecision['accepted'])) {
            throw new \DomainException(
                'The parsed iXBRL taxonomy metadata is not accepted by HMRC: '
                . $this->messages(
                    (array)($taxonomyDecision['errors'] ?? []),
                    'The pinned HMRC taxonomy acceptance decision failed.'
                )
            );
        }

        $profile = $this->profile($environment);
        $ct600 = $this->xmlBuilder()->build(
            $return,
            $accounts,
            $computation,
            $this->requiredProfilePath($profile, 'schema_path', 'CT600 RIM XSD'),
            fn(
                Ct600ReturnData $hookReturn,
                Ct600IxbrlArtifact $hookAccounts,
                Ct600IxbrlArtifact $hookComputation,
            ): array => $this->taxonomy()->validate($hookReturn, $hookAccounts, $hookComputation)
        );
        $ct600Xml = (string)($ct600['xml'] ?? $ct600['body_xml'] ?? '');
        if ($ct600Xml === '') {
            throw new \RuntimeException('The CT600 XML builder returned an empty CT/5 body.');
        }

        $transactionId = $this->transactionId(
            $environment,
            $return,
            $accounts,
            $computation,
            (string)($ct600['body_sha256'] ?? hash('sha256', $ct600Xml))
        );
        $finalized = $this->validationEnvelope(
            $ct600Xml,
            $return->utr,
            $environment,
            $transactionId,
            $profile
        );
        $localValidation = $this->validator()->validateFinalPackage((string)$finalized['xml']);
        if (empty($localValidation['ok'])) {
            throw new \DomainException(
                'Local HMRC CT600 validation failed: ' . $this->validationSummary($localValidation)
            );
        }

        $lockedAt = $this->lockedAt($readiness);
        $manifest = Ct600PackageManifest::fromFinalizedPackage(
            $return,
            $accounts,
            $computation,
            $finalized,
            $lockedAt
        );
        $packageHash = $manifest->sha256();
        $idempotencyKey = hash(
            'sha256',
            implode('|', [
                'HMRC-CT600-ORIGINAL-V1',
                $environment,
                (string)$companyId,
                (string)$accountingPeriodId,
                (string)$ctPeriodId,
                $packageHash,
            ])
        );

        $accountsBytes = $this->verifiedSourceBytes($accounts);
        $computationBytes = $this->verifiedSourceBytes($computation);
        $stored = $this->storage()->storePreparedPackage(
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $environment,
            $packageHash,
            (string)$finalized['ir_envelope_xml'],
            $accountsBytes,
            $computationBytes,
            $manifest->toJson()
        );

        $validationAudit = [
            'status' => 'passed',
            'rim_version' => Ct600XmlBuilder::RIM_VERSION,
            'schema' => (array)($ct600['schema_validation'] ?? []),
            'local' => $this->safeValidationAudit($localValidation),
            'factory' => $this->safeValidationAudit((array)($inputs['validation'] ?? [])),
            'taxonomy' => $taxonomyDecision,
            'mapping' => (array)($inputs['mapping'] ?? []),
        ];
        $prepared = $this->repository()->createPrepared([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'environment' => $environment,
            'submission_type' => 'original',
            'ct600_xml_path' => (string)$stored['ct600_path'],
            'accounts_ixbrl_path' => (string)$stored['accounts_ixbrl_path'],
            'accounts_run_id' => $accounts->runId,
            'accounts_sha256' => (string)$stored['accounts_sha256'],
            'computations_ixbrl_path' => (string)$stored['computations_ixbrl_path'],
            'computation_run_id' => $computation->runId,
            'computations_sha256' => (string)$stored['computations_sha256'],
            'year_end_locked_at' => $lockedAt->format('Y-m-d H:i:s'),
            'package_hash' => $packageHash,
            'idempotency_key' => $idempotencyKey,
            'transaction_id' => $transactionId,
            'irmark' => (string)$finalized['irmark'],
            'schema_version' => $return->schemaVersion,
            'body_sha256' => (string)$finalized['canonical_body_sha256'],
            'ct600_sha256' => (string)$stored['ct600_sha256'],
            'manifest_path' => (string)$stored['manifest_path'],
            'validation' => $validationAudit,
            'declarant_name' => $return->declarationName,
            'declarant_status' => $return->declarationStatus,
        ], $actor);

        return $this->success(
            $prepared,
            'The exact CT600 package was frozen, locally validated and is ready for approval.',
            array_merge(
                (array)($readiness['warnings'] ?? []),
                (array)($taxonomyDecision['warnings'] ?? [])
            )
        );
    }

    /** @return array<string, mixed> */
    public function approve(int $submissionId, array $declaration, string $actor): array
    {
        $row = $this->submission($submissionId);
        $this->assertServerEnvironment($row);
        $this->revalidateOrInvalidate($row, 'prepare', $actor);

        $approved = $this->repository()->approve(
            $submissionId,
            (int)$row['company_id'],
            [
                'name' => trim((string)($declaration['name'] ?? '')),
                'status' => $this->declarantStatus((string)($declaration['status'] ?? '')),
                'confirmed' => !empty($declaration['confirmed']),
                'scope_confirmed' => !empty($declaration['scope_confirmed']),
                'original_unfiled_confirmed' => !empty($declaration['original_unfiled_confirmed']),
            ],
            $this->actor($actor)
        );

        return $this->success(
            $approved,
            'The declaration is bound to the exact frozen package. It is ready to send.'
        );
    }

    /** @return array<string, mixed> */
    public function submit(int $submissionId, string $actor): array
    {
        $row = $this->submission($submissionId);
        $environment = $this->assertServerEnvironment($row);
        if ((string)($row['protocol_state'] ?? '') !== 'ready') {
            throw new \DomainException('Only an approved, ready CT600 package can be submitted.');
        }
        $this->assertTestDataScope((int)$row['company_id'], $environment);
        $this->revalidateOrInvalidate($row, 'submit', $actor);

        $gatewayStatus = $this->gateway()->configurationStatus($environment);
        if (empty($gatewayStatus['ready'])) {
            throw new \DomainException(
                'The HMRC CT XML gateway is not configured: '
                . $this->messages((array)($gatewayStatus['blockers'] ?? []), 'Dedicated CT XML credentials are required.')
            );
        }

        $body = $this->storage()->readVerified(
            (string)$row['ct600_xml_path'],
            (string)$row['ct600_sha256']
        );
        $submitting = $this->repository()->markSubmitting(
            $submissionId,
            (int)$row['company_id'],
            $this->actor($actor),
            $this->actorUserId($actor),
            null,
            [
                'environment' => $environment,
                'class' => (string)($gatewayStatus['class'] ?? ''),
                'gateway_test' => (string)($gatewayStatus['gateway_test'] ?? ''),
            ]
        );

        try {
            $result = $this->gateway()->submit(
                $body,
                (string)$this->currentUtr($submitting),
                $environment,
                (string)$submitting['transaction_id']
            );
        } catch (\Throwable $exception) {
            $uncertain = $this->repository()->markTransportUncertain(
                $submissionId,
                (int)$row['company_id'],
                'The submit call ended without a conclusive Transaction Engine response.',
                $this->dbDate($this->now()->modify('+60 seconds'))
            );
            $this->repository()->recordEvent(
                $submissionId,
                (int)$row['company_id'],
                'warning',
                'The initial submit result is ambiguous; DATA_REQUEST recovery is required.',
                ['exception_type' => $exception::class]
            );

            return $this->failure(
                $uncertain,
                'HMRC may have received the package. It will not be resubmitted; use recovery when due.'
            );
        }

        $submitting = $this->persistRedactedRequest($submitting, $result);
        $gatewayIrmark = trim((string)($result['irmark'] ?? ''));
        if ($gatewayIrmark !== '' && !hash_equals((string)$submitting['irmark'], $gatewayIrmark)) {
            $uncertain = $this->repository()->markTransportUncertain(
                $submissionId,
                (int)$row['company_id'],
                'The transmitted request IRmark did not match the approved frozen package.',
                $this->dbDate($this->now()->modify('+60 seconds'))
            );

            return $this->failure(
                $uncertain,
                'The gateway IRmark could not be reconciled. Recover the existing transaction; do not resubmit.'
            );
        }

        return $this->handleGatewayResult($submitting, $result, 'submit');
    }

    /** @return array<string, mixed> */
    public function poll(int $submissionId, string $actor): array
    {
        $row = $this->submission($submissionId);
        $environment = $this->assertServerEnvironment($row);
        if ((string)($row['protocol_state'] ?? '') !== 'awaiting_poll') {
            throw new \DomainException('Only an acknowledged CT600 transaction can be polled.');
        }
        $this->assertDue($row, 'HMRC status check');

        $result = $this->gateway()->poll(
            (string)$row['hmrc_correlation_id'],
            (string)$row['response_endpoint'],
            $environment,
            (string)$row['transaction_id']
        );

        return $this->handleGatewayResult($row, $result, 'poll');
    }

    /**
     * Completes DELETE_REQUEST after a final response, or performs DATA_REQUEST
     * recovery when the initial submit result was ambiguous.
     *
     * @return array<string, mixed>
     */
    public function deleteResponse(int $submissionId, string $actor): array
    {
        $row = $this->submission($submissionId);
        $environment = $this->assertServerEnvironment($row);
        $state = (string)($row['protocol_state'] ?? '');
        if ($state === 'transport_uncertain') {
            return $this->recover($row, $actor);
        }
        if ($state === 'closed') {
            return $this->success($row, 'The HMRC Transaction Engine conversation is already closed.');
        }
        if (!in_array($state, ['final_received', 'delete_pending'], true)) {
            throw new \DomainException('There is no final HMRC response ready for deletion or recovery.');
        }

        $correlationId = trim((string)($row['hmrc_correlation_id'] ?? ''));
        $endpoint = trim((string)($row['response_endpoint'] ?? ''));
        if ($correlationId === '' || $endpoint === '') {
            $pending = $this->repository()->markCleanupFailed(
                $submissionId,
                (int)$row['company_id'],
                'HMRC did not provide a correlation ID and response endpoint for DELETE_REQUEST.'
            );

            return $this->failure($pending, 'The final response is preserved, but HMRC cleanup cannot yet be completed.');
        }

        $result = $this->gateway()->delete(
            $correlationId,
            $endpoint,
            $environment,
            (string)$row['transaction_id']
        );
        $artifact = $this->persistGatewayResponse($row, $result, 'delete');
        $bindingError = $this->gatewayBindingError($row, $result, 'delete');
        if ($bindingError !== null) {
            $pending = $this->repository()->markCleanupFailed(
                $submissionId,
                (int)$row['company_id'],
                $bindingError
            );
            return $this->failure(
                $pending,
                'HMRC cleanup identifiers did not match the persisted transaction. The receipt remains preserved.'
            );
        }
        if (!empty($result['success']) && (string)($result['protocol_state'] ?? '') === 'deleted') {
            $closed = $this->repository()->markCleanupComplete(
                $submissionId,
                (int)$row['company_id'],
                (string)$artifact['path'],
                (string)$artifact['sha256']
            );

            return $this->success($closed, 'The HMRC response was deleted and the protocol conversation is closed.');
        }

        $pending = $this->repository()->markCleanupFailed(
            $submissionId,
            (int)$row['company_id'],
            $this->gatewaySummary($result)
        );

        return $this->failure($pending, 'The receipt is safe, but HMRC response deletion must be retried.');
    }

    /**
     * Reconciles only the local statutory state for an already-preserved LIVE
     * acceptance. No GovTalk request is made by this operation.
     *
     * @return array<string, mixed>
     */
    public function retryStatutorySync(int $submissionId, string $actor): array
    {
        $this->actor($actor);
        $row = $this->submission($submissionId);
        if ((string)($row['environment'] ?? '') !== HmrcCtConfigurationService::LIVE
            || (string)($row['business_outcome'] ?? '') !== 'live_accepted') {
            throw new \DomainException('Only a preserved final LIVE acceptance can be reconciled locally.');
        }

        $state = (string)($row['statutory_sync_state'] ?? '');
        if ($state === 'applied') {
            return $this->success($row, 'The LIVE acceptance is already reflected in the local statutory filing state.');
        }
        if (!in_array($state, ['pending', 'failed'], true)) {
            throw new \DomainException('This LIVE acceptance has no local statutory reconciliation pending.');
        }

        try {
            $updated = $state === 'failed'
                ? $this->statutoryAcceptance()->retry($submissionId)
                : $this->statutoryAcceptance()->apply($submissionId);
        } catch (\Throwable) {
            $latest = $this->repository()->fetchById($submissionId) ?? $row;
            return $this->failure(
                is_array($latest) ? $latest : $row,
                'HMRC LIVE acceptance and its receipt remain preserved, but the local statutory filing state could not be reconciled. Review the sanitised submission event and retry after correcting the local error.'
            );
        }

        return $this->success(
            is_array($updated) ? $updated : $row,
            'The preserved HMRC LIVE acceptance is now reflected in the local statutory filing state.'
        );
    }

    /** @return array<string, mixed> */
    private function handleGatewayResult(array $row, array $result, string $operation): array
    {
        $submissionId = (int)$row['id'];
        $companyId = (int)$row['company_id'];
        $protocolState = (string)($result['protocol_state'] ?? 'failed');

        $bindingError = $this->gatewayBindingError($row, $result, $operation);
        if ($bindingError !== null) {
            $this->persistGatewayResponse($row, $result, 'error');
            $uncertain = $this->repository()->markTransportUncertain(
                $submissionId,
                $companyId,
                $bindingError,
                $this->dbDate($this->now()->modify('+60 seconds'))
            );
            $this->repository()->recordEvent(
                $submissionId,
                $companyId,
                'error',
                'HMRC response identifiers did not bind to the persisted transaction; no business outcome was recorded.',
                [
                    'operation' => $operation,
                    'protocol_state' => $protocolState,
                ]
            );

            return $this->failure(
                $uncertain,
                $bindingError . ' Recover the persisted transaction; do not resubmit it.'
            );
        }

        if (!empty($result['transport_unknown'])) {
            $this->persistGatewayResponse($row, $result, 'error');
            $uncertain = $this->repository()->markTransportUncertain(
                $submissionId,
                $companyId,
                $this->gatewaySummary($result),
                $this->dbDate($this->now()->modify('+60 seconds'))
            );

            return $this->failure(
                $uncertain,
                'The transport result is uncertain. Recover the existing transaction when due; it will not be resubmitted.'
            );
        }

        if ($protocolState === 'submission_error') {
            $this->persistGatewayResponse($row, $result, 'error');
            if ($operation === 'poll') {
                $interval = max(1, (int)($row['poll_interval_seconds'] ?? 60));
                $pending = $this->repository()->markPollAttempt(
                    $submissionId,
                    $companyId,
                    $this->dbDate($this->now()->modify('+' . $interval . ' seconds')),
                    (array)($result['headers'] ?? [])
                );
                $this->repository()->recordEvent(
                    $submissionId,
                    $companyId,
                    'warning',
                    'The Transaction Engine rejected this poll request; the acknowledged correlation sequence remains active.',
                    ['errors' => (array)($result['errors'] ?? [])]
                );
                return $this->failure(
                    $pending,
                    'The Transaction Engine could not process this poll. The existing transaction remains active and will be polled again; it was not recorded as rejected.'
                );
            }

            $invalidated = $this->repository()->transition(
                $submissionId,
                $companyId,
                ['submitting'],
                [
                    'status' => 'failed',
                    'protocol_state' => 'invalidated',
                    'business_outcome' => 'error',
                    'invalidated_at' => $this->dbDate($this->now()),
                    'invalidation_reason' => $this->gatewaySummary($result),
                ],
                'error',
                'The Transaction Engine rejected the initial request before creating a filing conversation.',
                ['errors' => (array)($result['errors'] ?? [])]
            );
            return $this->failure(
                $invalidated,
                'The Transaction Engine did not create a submission sequence. Correct the reported gateway error and prepare a new package.'
            );
        }

        if ($protocolState === 'acknowledged') {
            $this->persistGatewayResponse($row, $result, $operation === 'submit' ? 'acknowledgement' : 'poll');
            $interval = max(1, (int)($result['poll_interval'] ?? 1));
            if ($operation === 'submit') {
                $acknowledged = $this->repository()->markAcknowledged($submissionId, $companyId, [
                    'submission_reference' => (string)($result['transaction_id'] ?? ''),
                    'correlation_id' => (string)($result['correlation_id'] ?? ''),
                    'response_endpoint' => (string)($result['response_endpoint'] ?? ''),
                    'poll_interval_seconds' => $interval,
                    'next_poll_at' => $this->dbDate($this->now()->modify('+' . $interval . ' seconds')),
                    'response_code' => $result['status_code'] ?? null,
                    'summary' => 'HMRC acknowledged the transaction; this is not a filing acceptance.',
                    'headers' => (array)($result['headers'] ?? []),
                ]);
            } else {
                $acknowledged = $this->repository()->markPollAttempt(
                    $submissionId,
                    $companyId,
                    $this->dbDate($this->now()->modify('+' . $interval . ' seconds')),
                    (array)($result['headers'] ?? [])
                );
            }

            return $this->success(
                $acknowledged,
                'HMRC acknowledged the transaction. Check again after the minimum poll interval.'
            );
        }

        if ($protocolState === 'final_response') {
            if ($operation === 'submit' && trim((string)($row['hmrc_correlation_id'] ?? '')) === '') {
                $row = $this->repository()->transition(
                    $submissionId,
                    $companyId,
                    ['submitting'],
                    [
                        'hmrc_correlation_id' => (string)$result['correlation_id'],
                        'response_endpoint' => (string)$result['response_endpoint'],
                        'poll_interval_seconds' => max(1, (int)($result['poll_interval'] ?? 1)),
                    ],
                    'info',
                    'The direct final response was bound to the submitted transaction identifiers.',
                    []
                );
            }
            $statusCode = (int)($result['status_code'] ?? 0);
            $outcome = (string)($result['business_outcome'] ?? '');
            $accepted = !empty($result['success'])
                && $statusCode >= 200
                && $statusCode < 300
                && $outcome === 'accepted';
            $rejected = $statusCode >= 200 && $statusCode < 300 && $outcome === 'rejected';
            if (!$accepted && !$rejected) {
                $this->persistGatewayResponse($row, $result, 'error');
                if ($operation === 'poll') {
                    $interval = max(1, (int)($row['poll_interval_seconds'] ?? 60));
                    $pending = $this->repository()->markPollAttempt(
                        $submissionId,
                        $companyId,
                        $this->dbDate($this->now()->modify('+' . $interval . ' seconds')),
                        (array)($result['headers'] ?? [])
                    );
                    return $this->failure(
                        $pending,
                        'The purported final response did not have a successful 2xx transport result. The existing correlation ID will be polled again.'
                    );
                }
                $uncertain = $this->repository()->markTransportUncertain(
                    $submissionId,
                    $companyId,
                    'The direct final response did not have a successful 2xx transport result.',
                    $this->dbDate($this->now()->modify('+60 seconds'))
                );
                return $this->failure(
                    $uncertain,
                    'The submit transport result cannot prove a final HMRC outcome. Recover the existing transaction; do not resubmit it.'
                );
            }

            $artifact = $this->persistGatewayResponse($row, $result, 'final');
            $final = $this->repository()->markFinal($submissionId, $companyId, [
                'accepted' => $accepted,
                'error' => false,
                'response_code' => $result['status_code'] ?? null,
                'summary' => $this->gatewaySummary($result),
                'headers' => (array)($result['headers'] ?? []),
                'response_body_path' => (string)$artifact['path'],
                'response_sha256' => (string)$artifact['sha256'],
            ]);
            $statutorySyncError = null;
            if ((string)$final['business_outcome'] === 'live_accepted') {
                try {
                    $this->recordStatutoryAcceptance($final);
                } catch (\Throwable $exception) {
                    $statutorySyncError = $exception->getMessage();
                    $this->recordStatutorySyncFailure($final, $exception);
                }
            }
            if (!empty($result['cleanup_required']) || trim((string)($result['correlation_id'] ?? '')) !== '') {
                $final = $this->repository()->markCleanupPending($submissionId, $companyId);
            }
            if ($accepted) {
                if ($statutorySyncError !== null) {
                    return $this->failure(
                        $final,
                        'HMRC LIVE acceptance and its receipt are preserved, but the local CT-period/obligation filing state is pending reconciliation: '
                        . $statutorySyncError
                    );
                }
                return $this->success(
                    $final,
                    match ((string)$final['business_outcome']) {
                        'sandbox_passed' => 'HMRC ETS returned a final synthetic-data acceptance.',
                        'til_validated' => 'HMRC Test-in-Live returned a final validation acceptance; no statutory filing was recorded.',
                        'live_accepted' => 'HMRC returned a final LIVE business acceptance.',
                        default => 'HMRC returned a final business acceptance.',
                    }
                );
            }

            return $this->failure($final, $this->gatewaySummary($result));
        }

        $this->persistGatewayResponse($row, $result, 'error');
        if ($operation === 'poll') {
            $interval = max(1, (int)($row['poll_interval_seconds'] ?? 60));
            $pending = $this->repository()->markPollAttempt(
                $submissionId,
                $companyId,
                $this->dbDate($this->now()->modify('+' . $interval . ' seconds')),
                (array)($result['headers'] ?? [])
            );
            $this->repository()->recordEvent(
                $submissionId,
                $companyId,
                'warning',
                'The HMRC poll did not return a final response and will be retried.',
                ['errors' => (array)($result['errors'] ?? [])]
            );

            return $this->failure($pending, $this->gatewaySummary($result));
        }

        $artifact = $this->persistGatewayResponse($row, $result, 'error');
        $failed = $this->repository()->markFinal($submissionId, $companyId, [
            'accepted' => false,
            'error' => true,
            'response_code' => $result['status_code'] ?? null,
            'summary' => $this->gatewaySummary($result),
            'headers' => (array)($result['headers'] ?? []),
            'response_body_path' => (string)$artifact['path'],
            'response_sha256' => (string)$artifact['sha256'],
        ]);

        return $this->failure($failed, $this->gatewaySummary($result));
    }

    /**
     * A direct acknowledgement may omit TransactionID under the Transaction
     * Engine protocol. Final responses and all follow-on operations must echo
     * the ID because this client supplies it. Any returned ID must match.
     */
    private function gatewayBindingError(array $row, array $result, string $operation): ?string
    {
        $protocolState = (string)($result['protocol_state'] ?? 'failed');
        $expectedTransaction = strtoupper(trim((string)($row['transaction_id'] ?? '')));
        $returnedTransaction = strtoupper(trim((string)($result['transaction_id'] ?? '')));
        $transactionRequired = $operation !== 'submit' || $protocolState === 'final_response';
        if ($returnedTransaction === '' && $transactionRequired) {
            return 'HMRC response omitted the persisted TransactionID.';
        }
        if ($returnedTransaction !== '' && !hash_equals($expectedTransaction, $returnedTransaction)) {
            return 'HMRC response TransactionID did not match the persisted submission.';
        }

        $expectedCorrelation = strtoupper(trim((string)($row['hmrc_correlation_id'] ?? '')));
        $returnedCorrelation = strtoupper(trim((string)($result['correlation_id'] ?? '')));
        $correlationRequired = $operation !== 'submit'
            || in_array($protocolState, ['acknowledged', 'final_response'], true);
        if (!$correlationRequired) {
            return null;
        }
        if ($returnedCorrelation === '') {
            return 'HMRC response omitted the correlation ID required to bind this conversation.';
        }
        if ($expectedCorrelation !== '' && !hash_equals($expectedCorrelation, $returnedCorrelation)) {
            return 'HMRC response correlation ID did not match the persisted conversation.';
        }
        if ($protocolState === 'final_response' || $protocolState === 'acknowledged') {
            $endpoint = trim((string)($result['response_endpoint'] ?? ''));
            if ($endpoint === '') {
                return 'HMRC response omitted the response endpoint required for the bound conversation.';
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function recover(array $row, string $actor): array
    {
        $this->assertDue($row, 'Transaction Engine recovery');
        $now = $this->now();
        $submittedAt = $this->parseDbDate((string)($row['submitted_at'] ?? ''))
            ?? $now->modify('-24 hours');
        $result = $this->gateway()->requestData([
            'start_at' => $submittedAt->modify('-5 minutes'),
            'end_at' => $now->modify('+5 minutes'),
            'include_identifiers' => true,
        ], (string)$row['environment'], (string)$row['transaction_id']);
        $this->persistGatewayResponse($row, $result, 'recovery');

        if (empty($result['success']) || (string)($result['protocol_state'] ?? '') !== 'data_response') {
            $pending = $this->recordRecoveryMiss($row, $this->gatewaySummary($result), $actor);
            return $this->failure($pending, 'HMRC recovery did not yet locate the transaction. Retry after the displayed time.');
        }

        $match = $this->matchingStatusRecord(
            (array)($result['status_records'] ?? []),
            (string)$row['transaction_id']
        );
        if ($match === null) {
            $pending = $this->recordRecoveryMiss(
                $row,
                'HMRC DATA_RESPONSE did not contain the persisted transaction ID.',
                $actor
            );
            return $this->failure($pending, 'HMRC has not yet listed the existing transaction. It has not been resubmitted.');
        }

        $correlationId = trim((string)($match['correlation_id'] ?? $result['correlation_id'] ?? ''));
        $endpoint = trim((string)($result['response_endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = (string)($this->profile((string)$row['environment'])['poll_endpoint'] ?? '');
        }
        if ($correlationId === '' || $endpoint === '') {
            $pending = $this->recordRecoveryMiss(
                $row,
                'HMRC listed the transaction without a usable correlation ID or poll endpoint.',
                $actor
            );
            return $this->failure($pending, 'The existing transaction was found, but HMRC did not provide enough data to poll it.');
        }

        $normalised = (string)($match['normalised_status'] ?? '');
        $interval = max(1, (int)($result['poll_interval'] ?? 1));
        $next = in_array($normalised, ['final_response', 'rejected'], true)
            ? $now
            : $now->modify('+' . $interval . ' seconds');
        $recovered = $this->repository()->markRecovered((int)$row['id'], (int)$row['company_id'], [
            'correlation_id' => $correlationId,
            'response_endpoint' => $endpoint,
            'poll_interval_seconds' => $interval,
            'next_poll_at' => $this->dbDate($next),
        ]);
        $this->repository()->recordEvent(
            (int)$row['id'],
            (int)$row['company_id'],
            'info',
            'DATA_REQUEST matched the persisted transaction; continuation will use POLL_REQUEST.',
            ['actor' => $this->actor($actor), 'normalised_status' => $normalised]
        );

        return $this->success(
            $recovered,
            'The existing HMRC transaction was recovered. Poll it when the displayed time is reached.'
        );
    }

    /** @return array<string, mixed> */
    private function recordRecoveryMiss(array $row, string $summary, string $actor): array
    {
        $next = $this->now()->modify('+60 seconds');
        return $this->repository()->transition(
            (int)$row['id'],
            (int)$row['company_id'],
            ['transport_uncertain'],
            [
                'recovery_attempts' => (int)($row['recovery_attempts'] ?? 0) + 1,
                'last_recovery_at' => $this->dbDate($this->now()),
                'next_poll_at' => $this->dbDate($next),
                'hmrc_response_summary' => $summary,
            ],
            'warning',
            'DATA_REQUEST did not yet reconcile the uncertain transaction.',
            ['actor' => $this->actor($actor)]
        );
    }

    /** @return array<string, mixed>|null */
    private function matchingStatusRecord(array $records, string $transactionId): ?array
    {
        $transactionId = strtoupper(trim($transactionId));
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (hash_equals($transactionId, strtoupper(trim((string)($record['transaction_id'] ?? ''))))) {
                return $record;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function revalidateOrInvalidate(array $row, string $stage, string $actor): array
    {
        try {
            // Preparation gates describe immutable/source validity.  Submit-
            // only gates (credentials and CT-period sequence) are deliberately
            // checked afterwards: a temporarily blocked CT7 package remains
            // approved while CT6 is awaiting LIVE acceptance.
            $readiness = $this->revalidateFrozen($row, 'prepare');
        } catch (\Throwable $exception) {
            if (in_array((string)($row['protocol_state'] ?? ''), ['prepared', 'ready'], true)) {
                try {
                    $this->repository()->invalidatePrepared(
                        (int)$row['id'],
                        (int)$row['company_id'],
                        $exception->getMessage(),
                        $this->actor($actor)
                    );
                } catch (\Throwable) {
                    // Preserve the original staleness error if invalidation itself fails.
                }
            }
            throw new \DomainException(
                'The frozen CT600 package is no longer current and must be prepared again: '
                . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($stage === 'submit') {
            $this->assertReadiness($readiness, 'submit');
        }

        return $readiness;
    }

    /** @return array<string, mixed> */
    private function revalidateFrozen(array $row, string $stage): array
    {
        $environment = $this->assertServerEnvironment($row);
        $this->assertTestDataScope((int)$row['company_id'], $environment);
        $readiness = $this->readiness()->assess(
            (int)$row['company_id'],
            (int)$row['accounting_period_id'],
            (int)$row['ct_period_id'],
            $environment
        );
        $this->assertReadiness($readiness, $stage);

        $comparisons = [
            'Year End lock timestamp' => [
                (string)$row['year_end_locked_at'],
                $this->lockedAt($readiness)->format('Y-m-d H:i:s'),
            ],
            'accounts iXBRL run' => [
                (string)$row['accounts_run_id'],
                (string)($readiness['accounts']['run_id'] ?? ''),
            ],
            'accounts iXBRL hash' => [
                strtolower((string)$row['accounts_sha256']),
                strtolower((string)($readiness['accounts']['hash'] ?? '')),
            ],
            'computations iXBRL run' => [
                (string)$row['computation_run_id'],
                (string)($readiness['computations']['run_id'] ?? ''),
            ],
            'computations iXBRL hash' => [
                strtolower((string)$row['computations_sha256']),
                strtolower((string)($readiness['computations']['hash'] ?? '')),
            ],
        ];
        foreach ($comparisons as $label => [$frozen, $current]) {
            if ($current === '' || !hash_equals($frozen, $current)) {
                throw new \RuntimeException($label . ' differs from the frozen package manifest.');
            }
        }

        foreach ([
            [(string)$row['ct600_xml_path'], (string)$row['ct600_sha256'], 'CT600'],
            [(string)$row['accounts_ixbrl_path'], (string)$row['accounts_sha256'], 'accounts iXBRL'],
            [(string)$row['computations_ixbrl_path'], (string)$row['computations_sha256'], 'computations iXBRL'],
            [(string)$row['manifest_path'], (string)$row['package_hash'], 'package manifest'],
        ] as [$path, $hash, $label]) {
            if (!$this->storage()->verify($path, $hash)) {
                throw new \RuntimeException($label . ' failed immutable SHA-256 verification.');
            }
        }

        $body = $this->storage()->readVerified(
            (string)$row['ct600_xml_path'],
            (string)$row['ct600_sha256']
        );
        $profile = $this->profile($environment);
        $finalized = $this->validationEnvelope(
            $body,
            (string)($readiness['utr'] ?? ''),
            $environment,
            (string)$row['transaction_id'],
            $profile
        );
        if (!hash_equals((string)$row['irmark'], (string)$finalized['irmark'])) {
            throw new \RuntimeException('The generic IRmark differs from the approved frozen package.');
        }
        if (!hash_equals((string)$row['body_sha256'], (string)$finalized['canonical_body_sha256'])) {
            throw new \RuntimeException('The canonical GovTalk Body differs from the frozen package manifest.');
        }
        $validation = $this->validator()->validateFinalPackage((string)$finalized['xml']);
        if (empty($validation['ok'])) {
            throw new \RuntimeException('Local HMRC validation no longer passes: ' . $this->validationSummary($validation));
        }

        return $readiness;
    }

    /** @return array<string, mixed> */
    private function validationEnvelope(
        string $ct600Xml,
        string $utr,
        string $environment,
        string $transactionId,
        array $profile,
    ): array {
        $vendorId = trim((string)($profile['vendor_id'] ?? ''));
        if (preg_match('/^[0-9]{4}$/D', $vendorId) !== 1) {
            // Vendor ID is outside the IRmark digest and is a submit-stage
            // prerequisite.  0000 permits credential-free local preparation.
            $vendorId = '0000';
        }

        return $this->envelopeBuilder()->buildSubmission(
            $ct600Xml,
            $environment,
            $transactionId,
            'LOCAL-VALIDATION',
            'LOCAL-ONLY-DO-NOT-PERSIST',
            $utr,
            $vendorId,
            trim((string)($profile['product'] ?? '')) ?: 'EEL Accounts',
            trim((string)($profile['version'] ?? '')) ?: '1.0',
            $this->requiredProfilePath($profile, 'envelope_schema_path', 'GovTalk envelope XSD')
        );
    }

    /** @return array<string, mixed> */
    private function persistRedactedRequest(array $row, array $result): array
    {
        $xml = trim((string)($result['request_xml'] ?? ''));
        if ($xml === '') {
            return $row;
        }
        $artifact = $this->storage()->storeRedactedRequest($this->packageDirectory($row), $xml);

        return $this->repository()->transition(
            (int)$row['id'],
            (int)$row['company_id'],
            ['submitting'],
            [
                'request_body_path' => (string)$artifact['path'],
                'request_headers_json' => [
                    'environment' => (string)$row['environment'],
                    'class' => (string)($result['class'] ?? ''),
                    'gateway_test' => (string)($result['gateway_test'] ?? ''),
                ],
            ],
            'info',
            'A credential-free audit copy of the GovTalk submission request was preserved.',
            []
        );
    }

    /** @return array{path:string,sha256:string,bytes:int} */
    private function persistGatewayResponse(array $row, array $result, string $kind): array
    {
        $xml = trim((string)($result['response_xml'] ?? ''));
        if ($xml === '') {
            $xml = $this->summaryXml($result);
        }
        $identifier = strtolower(substr(hash('sha256', $xml), 0, 20));

        return $this->storage()->storeResponse(
            $this->packageDirectory($row),
            $kind,
            $xml,
            $identifier
        );
    }

    private function summaryXml(array $result): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElement('HmrcCtGatewayAudit');
        $document->appendChild($root);
        foreach ([
            'operation' => (string)($result['operation'] ?? ''),
            'protocol_state' => (string)($result['protocol_state'] ?? ''),
            'business_outcome' => (string)($result['business_outcome'] ?? ''),
            'status_code' => (string)($result['status_code'] ?? ''),
            'summary' => $this->gatewaySummary($result),
        ] as $name => $value) {
            $element = $document->createElement($name);
            $element->appendChild($document->createTextNode($value));
            $root->appendChild($element);
        }
        $xml = $document->saveXML();
        if (!is_string($xml) || $xml === '') {
            throw new \RuntimeException('Unable to preserve the sanitised HMRC gateway result.');
        }

        return $xml;
    }

    private function currentUtr(array $row): string
    {
        $readiness = $this->readiness()->assess(
            (int)$row['company_id'],
            (int)$row['accounting_period_id'],
            (int)$row['ct_period_id'],
            (string)$row['environment']
        );
        $utr = trim((string)($readiness['utr'] ?? ''));
        if (preg_match('/^[0-9]{10}$/D', $utr) !== 1) {
            throw new \DomainException('The current Corporation Tax UTR is invalid.');
        }

        return $utr;
    }

    private function recordStatutoryAcceptance(array $row): void
    {
        if ((string)($row['environment'] ?? '') !== HmrcCtConfigurationService::LIVE
            || (string)($row['business_outcome'] ?? '') !== 'live_accepted') {
            return;
        }
        if ($this->statutoryAcceptanceRecorder instanceof \Closure) {
            ($this->statutoryAcceptanceRecorder)($row);
            return;
        }

        $companyId = (int)$row['company_id'];
        $accountingPeriodId = (int)$row['accounting_period_id'];
        $ctPeriodId = (int)$row['ct_period_id'];
        $submissionId = (int)$row['id'];
        \InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $submissionId
        ): void {
            $period = \InterfaceDB::fetchOne(
                'SELECT id FROM corporation_tax_periods
                 WHERE id = :ct_period_id AND company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 LIMIT 1 FOR UPDATE',
                [
                    'ct_period_id' => $ctPeriodId,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            if (!is_array($period)) {
                throw new \DomainException('The accepted Corporation Tax period no longer belongs to the filing scope.');
            }
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_periods
                 SET status = :status, latest_submission_id = :submission_id
                 WHERE id = :ct_period_id AND company_id = :company_id',
                [
                    'status' => 'accepted',
                    'submission_id' => $submissionId,
                    'ct_period_id' => $ctPeriodId,
                    'company_id' => $companyId,
                ]
            );

            $periodCount = (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM corporation_tax_periods
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
            $accepted = \InterfaceDB::fetchAll(
                'SELECT s.id, s.ct_period_id
                 FROM hmrc_ct600_submissions s
                 INNER JOIN (
                    SELECT ct_period_id, MAX(id) AS submission_id
                    FROM hmrc_ct600_submissions
                    WHERE company_id = :company_id
                      AND accounting_period_id = :accounting_period_id
                      AND environment = :environment
                      AND submission_type = :submission_type
                      AND business_outcome = :business_outcome
                    GROUP BY ct_period_id
                 ) accepted_ids ON accepted_ids.submission_id = s.id
                 ORDER BY s.ct_period_id ASC',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'environment' => 'LIVE',
                    'submission_type' => 'original',
                    'business_outcome' => 'live_accepted',
                ]
            );
            if ($periodCount <= 0 || count($accepted) !== $periodCount) {
                return;
            }

            $obligation = \InterfaceDB::fetchOne(
                'SELECT id FROM hmrc_obligations
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                   AND obligation_type = :obligation_type
                 ORDER BY id ASC LIMIT 1 FOR UPDATE',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'obligation_type' => 'ct600_filing',
                ]
            );
            if (!is_array($obligation)) {
                throw new \RuntimeException('The accounting-period CT600 filing obligation is missing.');
            }
            $obligationId = (int)$obligation['id'];
            $receiptIds = array_map(static fn(array $item): string => (string)(int)$item['id'], $accepted);
            \InterfaceDB::prepareExecute(
                'UPDATE hmrc_obligations
                 SET status = :status, checked_at = :checked_at, source_reference = :source_reference
                 WHERE id = :id AND company_id = :company_id',
                [
                    'status' => 'filed',
                    'checked_at' => $this->dbDate($this->now()),
                    'source_reference' => 'hmrc_ct600_live:' . implode(',', $receiptIds),
                    'id' => $obligationId,
                    'company_id' => $companyId,
                ]
            );
            foreach ($accepted as $receipt) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO hmrc_obligation_submission_links (hmrc_obligation_id, submission_id)
                     SELECT :hmrc_obligation_id, :submission_id
                     WHERE NOT EXISTS (
                        SELECT 1 FROM hmrc_obligation_submission_links
                        WHERE hmrc_obligation_id = :hmrc_obligation_id
                          AND submission_id = :submission_id
                     )',
                    [
                        'hmrc_obligation_id' => $obligationId,
                        'submission_id' => (int)$receipt['id'],
                    ]
                );
            }
        });
    }

    private function recordStatutorySyncFailure(array $row, \Throwable $exception): void
    {
        $this->repository()->recordEvent(
            (int)$row['id'],
            (int)$row['company_id'],
            'error',
            'HMRC LIVE acceptance is preserved, but local statutory filing-state synchronisation failed and must be retried.',
            [
                'exception_type' => $exception::class,
                'error' => $exception->getMessage(),
            ]
        );
    }

    private function assertReadiness(array $readiness, string $stage): void
    {
        $key = $stage === 'submit' ? 'can_submit' : 'can_prepare';
        if (!empty($readiness[$key])) {
            return;
        }
        $errors = (array)($readiness[$stage === 'submit' ? 'submit_blockers' : 'blockers'] ?? []);
        throw new \DomainException($this->messages($errors, 'The CT600 filing-readiness checks did not pass.'));
    }

    private function assertTestDataScope(int $companyId, string $environment): void
    {
        if ($environment === HmrcCtConfigurationService::TEST
            && !$this->configuration()->isSyntheticTestCompany($companyId)) {
            throw new \DomainException(
                'HMRC ETS/TEST accepts deterministic synthetic data only. This company is not on the server-controlled test allowlist.'
            );
        }
    }

    private function assertDue(array $row, string $label): void
    {
        $due = $this->parseDbDate((string)($row['next_poll_at'] ?? ''));
        if ($due instanceof \DateTimeImmutable && $due > $this->now()) {
            throw new \DomainException($label . ' is not due until ' . $due->format('Y-m-d H:i:s') . ' UTC.');
        }
    }

    private function assertServerEnvironment(array $row): string
    {
        $environment = $this->environment();
        if (!hash_equals($environment, strtoupper(trim((string)($row['environment'] ?? ''))))) {
            throw new \DomainException(
                'The frozen package belongs to a different server HMRC environment and must not be sent.'
            );
        }

        return $environment;
    }

    /** @return array<string, mixed> */
    private function submission(int $submissionId): array
    {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('A valid CT600 submission ID is required.');
        }
        $row = $this->repository()->fetchById($submissionId);
        if (!is_array($row)) {
            throw new \DomainException('The CT600 submission was not found.');
        }

        return $row;
    }

    private function transactionId(
        string $environment,
        Ct600ReturnData $return,
        Ct600IxbrlArtifact $accounts,
        Ct600IxbrlArtifact $computation,
        string $bodyHash,
    ): string {
        return strtoupper(substr(hash('sha256', implode('|', [
            'HMRC-CT600-TRANSACTION-V1',
            $environment,
            (string)$return->companyId,
            (string)$return->accountingPeriodId,
            (string)$return->ctPeriodId,
            (string)$accounts->runId,
            $accounts->outputSha256,
            (string)$computation->runId,
            $computation->outputSha256,
            strtolower($bodyHash),
        ])), 0, 32));
    }

    private function verifiedSourceBytes(Ct600IxbrlArtifact $artifact): string
    {
        $errors = $artifact->verificationErrors();
        if ($errors !== []) {
            throw new \DomainException(implode(' ', $errors));
        }
        $bytes = file_get_contents($artifact->path);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('The validated ' . $artifact->documentType . ' iXBRL could not be read.');
        }

        return $bytes;
    }

    private function lockedAt(array $readiness): \DateTimeImmutable
    {
        $lockedAt = trim((string)($readiness['lock']['locked_at'] ?? ''));
        $date = $this->parseDbDate($lockedAt);
        if (!$date instanceof \DateTimeImmutable) {
            throw new \DomainException('The locked Year End has no valid immutable UTC lock timestamp.');
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function profile(string $environment): array
    {
        $profile = $this->configuration()->profile($environment);
        if (!is_array($profile) || strtoupper((string)($profile['environment'] ?? '')) !== $environment) {
            throw new \RuntimeException('The server HMRC CT environment profile is invalid.');
        }

        return $profile;
    }

    private function requiredProfilePath(array $profile, string $key, string $label): string
    {
        $path = trim((string)($profile[$key] ?? ''));
        if ($path === '') {
            throw new \RuntimeException('The configured ' . $label . ' path is missing.');
        }

        return $path;
    }

    private function packageDirectory(array $row): string
    {
        $path = trim(str_replace('\\', '/', (string)($row['ct600_xml_path'] ?? '')));
        $directory = trim(str_replace('\\', '/', dirname($path)), '/.');
        if ($directory === '') {
            throw new \RuntimeException('The protected CT600 package directory is invalid.');
        }

        return $directory;
    }

    private function gatewaySummary(array $result): string
    {
        $summary = trim((string)($result['error'] ?? ''));
        if ($summary !== '') {
            return $summary;
        }
        $messages = [];
        foreach ((array)($result['errors'] ?? []) as $error) {
            if (is_string($error)) {
                $messages[] = trim($error);
                continue;
            }
            if (!is_array($error)) {
                continue;
            }
            foreach ((array)($error['texts'] ?? []) as $text) {
                $text = trim((string)$text);
                if ($text !== '') {
                    $messages[] = $text;
                }
            }
        }

        return $this->messages($messages, 'HMRC Transaction Engine returned an unsuccessful response.');
    }

    private function validationSummary(array $validation): string
    {
        $messages = [];
        foreach ((array)($validation['errors'] ?? []) as $error) {
            if (is_string($error)) {
                $messages[] = $error;
            } elseif (is_array($error)) {
                $messages[] = (string)($error['message'] ?? $error['detail'] ?? $error['code'] ?? '');
            }
        }

        return $this->messages($messages, 'The pinned XSD, Schematron or IRmark checks failed.');
    }

    /** @return array<string, mixed> */
    private function safeValidationAudit(array $value): array
    {
        $allowed = [
            'ok', 'status', 'validator', 'rim_version', 'errors', 'warnings', 'checks',
            'artifact_hashes', 'scope_blockers', 'hashes_reverified', 'accounts_identity',
            'computation_identity', 'accounts_schema_ref', 'computation_schema_ref',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $value)) {
                $safe[$key] = $value[$key];
            }
        }

        return $safe;
    }

    private function messages(array $messages, string $fallback): string
    {
        $messages = array_values(array_unique(array_filter(array_map(
            static fn(mixed $message): string => trim((string)$message),
            $messages
        ))));

        return $messages === [] ? $fallback : implode(' ', $messages);
    }

    private function declarantStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'proper_officer', 'proper officer' => 'Proper officer',
            'authorised_person', 'authorised person' => 'Authorised person',
            default => throw new \DomainException(
                'Declaration status must be proper_officer or authorised_person.'
            ),
        };
    }

    private function positiveIds(int ...$ids): void
    {
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Valid company, accounting-period and CT-period IDs are required.');
            }
        }
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 255 || preg_match('/[\x00-\x1F\x7F]/', $actor)) {
            throw new \InvalidArgumentException('A valid authenticated filing actor is required.');
        }

        return $actor;
    }

    private function actorUserId(string $actor): ?int
    {
        return preg_match('/^user:(\d+)$/D', trim($actor), $matches) === 1 && (int)$matches[1] > 0
            ? (int)$matches[1]
            : null;
    }

    private function now(): \DateTimeImmutable
    {
        $value = $this->clock instanceof \Closure
            ? ($this->clock)()
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
        }
        if (is_string($value) && trim($value) !== '') {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        }
        throw new \RuntimeException('The CT600 orchestration clock returned an invalid value.');
    }

    private function parseDbDate(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            trim($value),
            new \DateTimeZone('UTC')
        );

        return $date && $date->format('Y-m-d H:i:s') === trim($value) ? $date : null;
    }

    private function dbDate(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    private function success(array $submission, string $message, array $warnings = []): array
    {
        return [
            'success' => true,
            'messages' => [$message],
            'warnings' => array_values(array_unique(array_filter(array_map('strval', $warnings)))),
            'submission' => $submission,
            'submission_id' => (int)($submission['id'] ?? 0),
            'protocol_state' => (string)($submission['protocol_state'] ?? ''),
            'business_outcome' => (string)($submission['business_outcome'] ?? 'none'),
        ];
    }

    /** @return array<string, mixed> */
    private function failure(array $submission, string $message): array
    {
        return [
            'success' => false,
            'errors' => [$message],
            'submission' => $submission,
            'submission_id' => (int)($submission['id'] ?? 0),
            'protocol_state' => (string)($submission['protocol_state'] ?? ''),
            'business_outcome' => (string)($submission['business_outcome'] ?? 'none'),
        ];
    }

    private function gateway(): HmrcCtGatewayClientInterface
    {
        return $this->gateway ??= new HmrcCtGatewayClient();
    }

    private function readiness(): object
    {
        return $this->readiness ??= new Ct600FilingReadinessService();
    }

    private function returnFactory(): object
    {
        return $this->returnFactory ??= new Ct600ReturnDataFactory();
    }

    private function xmlBuilder(): object
    {
        return $this->xmlBuilder ??= new Ct600XmlBuilder();
    }

    private function envelopeBuilder(): object
    {
        return $this->envelopeBuilder ??= new GovTalkEnvelopeBuilder();
    }

    private function validator(): object
    {
        return $this->validator ??= new Ct600LocalValidationService();
    }

    private function repository(): object
    {
        return $this->repository ??= new HmrcCtSubmissionRepository();
    }

    private function storage(): object
    {
        return $this->storage ??= new HmrcCtArtifactStorageService(configuration: $this->configuration());
    }

    private function configuration(): object
    {
        return $this->configuration ??= new HmrcCtConfigurationService();
    }

    private function taxonomy(): object
    {
        return $this->taxonomy ??= new Ct600TaxonomyAcceptanceService();
    }
}
