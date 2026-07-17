<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Persistence boundary for the asynchronous HMRC CT600 GovTalk lifecycle.
 *
 * This class is deliberately migration-dependent. It never creates or alters
 * tables at runtime and every state change locks and re-checks ownership.
 */
final class HmrcCtSubmissionRepository
{
    private const SUBMISSIONS = 'hmrc_ct600_submissions';
    private const EVENTS = 'hmrc_submission_events';
    private const ENVIRONMENTS = ['TEST', 'TIL', 'LIVE'];
    private const PROTOCOL_STATES = [
        'prepared',
        'validation_failed',
        'ready',
        'submitting',
        'awaiting_poll',
        'final_received',
        'delete_pending',
        'closed',
        'transport_uncertain',
        'invalidated',
    ];
    private const BUSINESS_OUTCOMES = [
        'none',
        'sandbox_passed',
        'til_validated',
        'live_accepted',
        'rejected',
        'error',
    ];
    private const STATUTORY_SYNC_STATES = ['not_applicable', 'pending', 'applied', 'failed'];
    private const STATUSES = [
        'draft',
        'validating',
        'validation_failed',
        'ready',
        'submitting',
        'accepted',
        'rejected',
        'failed',
    ];
    private const EVENT_LEVELS = ['debug', 'info', 'warning', 'error', 'success'];
    private const MAX_EVENT_JSON_BYTES = 16_384;

    private const REQUIRED_SUBMISSION_COLUMNS = [
        'id', 'company_id', 'accounting_period_id', 'ct_period_id', 'mode', 'environment',
        'status', 'protocol_state', 'business_outcome', 'statutory_sync_state',
        'statutory_sync_error', 'statutory_synced_at', 'submission_type', 'ct600_xml_path',
        'accounts_ixbrl_path', 'accounts_run_id', 'accounts_sha256', 'computations_ixbrl_path',
        'computation_run_id', 'computations_sha256', 'year_end_locked_at', 'package_hash',
        'idempotency_key', 'transaction_id', 'hmrc_submission_reference', 'hmrc_correlation_id',
        'response_endpoint', 'poll_interval_seconds', 'next_poll_at', 'poll_attempts', 'irmark',
        'schema_version', 'body_sha256', 'ct600_sha256', 'hmrc_response_code',
        'hmrc_response_summary', 'request_headers_json', 'response_headers_json',
        'request_body_path', 'manifest_path', 'response_body_path', 'response_sha256',
        'validation_json', 'declarant_name', 'declarant_status', 'declaration_confirmed',
        'supplementary_scope_confirmed', 'original_unfiled_confirmed', 'declaration_approved_at',
        'declaration_approved_by', 'approved_package_hash', 'prepared_by', 'submitted_by',
        'submitted_by_user_id', 'submitted_at', 'final_response_at', 'cleanup_completed_at',
        'cleanup_response_path', 'cleanup_response_sha256', 'cleanup_error', 'recovery_attempts',
        'last_recovery_at', 'invalidated_at', 'invalidation_reason', 'created_at', 'updated_at',
    ];

    /** Read-only migration guard. */
    public function requireSchema(): void
    {
        if (
            !\InterfaceDB::tableExists(self::SUBMISSIONS)
            || !\InterfaceDB::columnsExists(self::SUBMISSIONS, self::REQUIRED_SUBMISSION_COLUMNS)
            || !\InterfaceDB::tableExists(self::EVENTS)
            || !\InterfaceDB::columnsExists(
                self::EVENTS,
                ['id', 'submission_id', 'event_level', 'event_message', 'event_context_json', 'created_at']
            )
        ) {
            throw new \RuntimeException(
                'The HMRC CT600 GovTalk migration has not been applied. Run the downstream database migrations first.'
            );
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function createPrepared(array $source, string $actor): array
    {
        $this->requireSchema();
        $row = $this->normalisePreparedSource($source, $actor);

        return \InterfaceDB::transaction(function () use ($row): array {
            $existing = $this->fetchByIdempotencyKeyInternal(
                (int)$row['company_id'],
                (string)$row['environment'],
                (string)$row['idempotency_key'],
                true
            );
            if (is_array($existing)) {
                $this->assertSamePreparedSource($existing, $row);
                return $existing;
            }

            $this->assertSourceOwnership($row);
            try {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO ' . self::SUBMISSIONS . ' (
                        company_id, accounting_period_id, ct_period_id, mode, environment,
                        status, protocol_state, business_outcome, statutory_sync_state, submission_type,
                        ct600_xml_path, accounts_ixbrl_path, accounts_run_id, accounts_sha256,
                        computations_ixbrl_path, computation_run_id, computations_sha256,
                        year_end_locked_at, package_hash, idempotency_key, transaction_id,
                        irmark, schema_version, body_sha256, ct600_sha256,
                        request_body_path, manifest_path, validation_json,
                        declarant_name, declarant_status, prepared_by
                     ) VALUES (
                        :company_id, :accounting_period_id, :ct_period_id, :mode, :environment,
                        :status, :protocol_state, :business_outcome, :statutory_sync_state, :submission_type,
                        :ct600_xml_path, :accounts_ixbrl_path, :accounts_run_id, :accounts_sha256,
                        :computations_ixbrl_path, :computation_run_id, :computations_sha256,
                        :year_end_locked_at, :package_hash, :idempotency_key, :transaction_id,
                        :irmark, :schema_version, :body_sha256, :ct600_sha256,
                        :request_body_path, :manifest_path, :validation_json,
                        :declarant_name, :declarant_status, :prepared_by
                     )',
                    $row
                );
            } catch (\Throwable $exception) {
                $existing = $this->fetchByIdempotencyKeyInternal(
                    (int)$row['company_id'],
                    (string)$row['environment'],
                    (string)$row['idempotency_key'],
                    true
                );
                if (!is_array($existing)) {
                    throw $exception;
                }
                $this->assertSamePreparedSource($existing, $row);
                return $existing;
            }

            $created = $this->fetchByIdempotencyKeyInternal(
                (int)$row['company_id'],
                (string)$row['environment'],
                (string)$row['idempotency_key'],
                true
            );
            if (!is_array($created)) {
                throw new \RuntimeException('The prepared CT600 submission could not be reloaded.');
            }
            $this->insertEvent(
                (int)$created['id'],
                'success',
                'The frozen CT600 package was prepared and validated.',
                [
                    'actor' => (string)$row['prepared_by'],
                    'environment' => (string)$row['environment'],
                    'package_hash' => (string)$row['package_hash'],
                    'accounts_run_id' => (int)$row['accounts_run_id'],
                    'computation_run_id' => (int)$row['computation_run_id'],
                ]
            );

            return $created;
        });
    }

    /** @return array<string, mixed>|null */
    public function fetchById(int $submissionId): ?array
    {
        $this->requireSchema();
        if ($submissionId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE id = :id LIMIT 1',
            ['id' => $submissionId]
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchOwned(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId = 0,
        int $ctPeriodId = 0,
    ): ?array {
        $this->requireSchema();
        if ($submissionId <= 0 || $companyId <= 0) {
            return null;
        }

        [$sql, $params] = $this->ownedSql(
            $submissionId,
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            false
        );
        $row = \InterfaceDB::fetchOne($sql, $params);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchByIdempotencyKey(int $companyId, string $environment, string $idempotencyKey): ?array
    {
        $this->requireSchema();
        return $this->fetchByIdempotencyKeyInternal(
            $companyId,
            $this->environment($environment),
            $this->hash($idempotencyKey, 'idempotency key'),
            false
        );
    }

    /** @return list<array<string, mixed>> */
    public function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId, int $limit = 100): array
    {
        $this->requireSchema();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }
        $limit = max(1, min(250, $limit));

        return \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
             ORDER BY created_at DESC, id DESC LIMIT ' . $limit,
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    /** @return array<string, mixed>|null */
    public function fetchLatestForCtPeriod(int $companyId, int $ctPeriodId, ?string $environment = null): ?array
    {
        $this->requireSchema();
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return null;
        }
        $params = ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId];
        $where = '';
        if ($environment !== null) {
            $params['environment'] = $this->environment($environment);
            $where = ' AND environment = :environment';
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND ct_period_id = :ct_period_id' . $where . '
             ORDER BY created_at DESC, id DESC LIMIT 1',
            $params
        );

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchLiveAcceptedOriginal(int $companyId, int $ctPeriodId): ?array
    {
        $this->requireSchema();
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND ct_period_id = :ct_period_id
               AND environment = :environment AND submission_type = :submission_type
               AND business_outcome = :business_outcome
             ORDER BY final_response_at DESC, id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'ct_period_id' => $ctPeriodId,
                'environment' => 'LIVE',
                'submission_type' => 'original',
                'business_outcome' => 'live_accepted',
            ]
        );

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function fetchDuePolls(?string $environment = null, int $limit = 50): array
    {
        $this->requireSchema();
        $limit = max(1, min(250, $limit));
        $params = ['due_at' => gmdate('Y-m-d H:i:s')];
        $environmentSql = '';
        if ($environment !== null) {
            $params['environment'] = $this->environment($environment);
            $environmentSql = ' AND environment = :environment';
        }

        return \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE protocol_state IN (\'awaiting_poll\', \'transport_uncertain\')
               AND next_poll_at IS NOT NULL AND next_poll_at <= :due_at' . $environmentSql . '
             ORDER BY next_poll_at ASC, id ASC LIMIT ' . $limit,
            $params
        );
    }

    /**
     * @param array<string, mixed> $declaration
     * @return array<string, mixed>
     */
    public function approve(int $submissionId, int $companyId, array $declaration, string $actor): array
    {
        $name = $this->boundedText((string)($declaration['name'] ?? ''), 255, 'Declarant name');
        $status = $this->declarantStatus((string)($declaration['status'] ?? ''));
        $actor = $this->boundedText($actor, 255, 'Approver');
        foreach (['confirmed', 'scope_confirmed', 'original_unfiled_confirmed'] as $field) {
            if (empty($declaration[$field])) {
                throw new \DomainException('Every declaration confirmation must be explicitly accepted.');
            }
        }
        $now = gmdate('Y-m-d H:i:s');

        $this->requireSchema();
        return $this->mutateLocked(
            $submissionId,
            $companyId,
            ['prepared'],
            function (array $row) use ($name, $status, $now, $actor): array {
                if (
                    !hash_equals((string)($row['declarant_name'] ?? ''), $name)
                    || !hash_equals((string)($row['declarant_status'] ?? ''), $status)
                ) {
                    throw new \DomainException(
                        'The approval declaration does not match the declarant identity frozen into this CT600 package.'
                    );
                }

                return [
                    'status' => 'ready',
                    'protocol_state' => 'ready',
                    'declaration_confirmed' => 1,
                    'supplementary_scope_confirmed' => 1,
                    'original_unfiled_confirmed' => 1,
                    'declaration_approved_at' => $now,
                    'declaration_approved_by' => $actor,
                    'approved_package_hash' => '__CURRENT_PACKAGE_HASH__',
                ];
            },
            'success',
            'The exact frozen CT600 package was approved for submission.',
            ['actor' => $actor, 'declarant_status' => $status]
        );
    }

    /** @return array<string, mixed> */
    public function markSubmitting(
        int $submissionId,
        int $companyId,
        string $actor,
        ?int $authenticatedUserId = null,
        ?string $redactedRequestPath = null,
        array $requestHeaders = [],
    ): array {
        $changes = [
            'status' => 'submitting',
            'protocol_state' => 'submitting',
            'submitted_by' => $this->boundedText($actor, 100, 'Submitter'),
            'submitted_by_user_id' => $authenticatedUserId !== null && $authenticatedUserId > 0
                ? $authenticatedUserId
                : null,
            'submitted_at' => gmdate('Y-m-d H:i:s'),
            'request_headers_json' => $this->safeJson($requestHeaders),
        ];
        if ($redactedRequestPath !== null) {
            $changes['request_body_path'] = $this->storageKey($redactedRequestPath, 'request body');
        }

        $this->requireSchema();
        return \InterfaceDB::transaction(function () use ($submissionId, $companyId, $changes, $actor): array {
            // Read the ownership key first, then serialise all attempts for the
            // CT period before taking the submission-row lock. This lock order
            // avoids two prepared packages racing each other into LIVE.
            $candidate = $this->fetchOwned($submissionId, $companyId);
            if (!is_array($candidate)) {
                throw new \DomainException('The CT600 submission does not belong to the selected company.');
            }
            // Serialize against YearEndLockService::unlockPeriod(). A
            // readiness check immediately before this transaction is not
            // sufficient: the lock must still be the same lock-time snapshot
            // at the exact point the package enters the submission lifecycle.
            $review = \InterfaceDB::fetchOne(
                'SELECT is_locked, locked_at FROM year_end_reviews
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                 LIMIT 1' . $this->forUpdateSuffix(),
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => (int)$candidate['accounting_period_id'],
                ]
            );
            if (!is_array($review)
                || empty($review['is_locked'])
                || !hash_equals(
                    (string)($candidate['year_end_locked_at'] ?? ''),
                    (string)($review['locked_at'] ?? '')
                )) {
                throw new \DomainException(
                    'Year End is no longer locked at the frozen package timestamp; prepare and approve a new CT600 package.'
                );
            }
            $ctPeriod = \InterfaceDB::fetchOne(
                'SELECT id FROM corporation_tax_periods
                 WHERE id = :ct_period_id AND company_id = :company_id
                 LIMIT 1' . $this->forUpdateSuffix(),
                ['ct_period_id' => (int)$candidate['ct_period_id'], 'company_id' => $companyId]
            );
            if (!is_array($ctPeriod)) {
                throw new \DomainException('The Corporation Tax period does not belong to the selected company.');
            }

            $locked = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($locked) || (string)$locked['protocol_state'] !== 'ready') {
                throw new \DomainException('Only an approved, ready CT600 package can be submitted.');
            }
            if (
                empty($locked['declaration_confirmed'])
                || empty($locked['supplementary_scope_confirmed'])
                || empty($locked['original_unfiled_confirmed'])
                || !hash_equals(
                    (string)($locked['package_hash'] ?? ''),
                    (string)($locked['approved_package_hash'] ?? '')
                )
            ) {
                throw new \DomainException('The declaration is not bound to the exact frozen CT600 package.');
            }

            if (
                (string)$locked['environment'] === 'LIVE'
                && (string)$locked['submission_type'] === 'original'
            ) {
                $conflict = \InterfaceDB::fetchOne(
                    'SELECT id FROM ' . self::SUBMISSIONS . '
                     WHERE company_id = :company_id AND ct_period_id = :ct_period_id
                       AND id <> :id AND environment = :environment
                       AND submission_type = :submission_type
                       AND (
                            protocol_state IN (
                                \'submitting\', \'awaiting_poll\', \'transport_uncertain\',
                                \'final_received\', \'delete_pending\'
                            )
                            OR business_outcome = \'live_accepted\'
                       )
                     ORDER BY id ASC LIMIT 1' . $this->forUpdateSuffix(),
                    [
                        'company_id' => $companyId,
                        'ct_period_id' => (int)$locked['ct_period_id'],
                        'id' => $submissionId,
                        'environment' => 'LIVE',
                        'submission_type' => 'original',
                    ]
                );
                if (is_array($conflict)) {
                    throw new \DomainException(
                        'Another LIVE original for this Corporation Tax period is already active or accepted.'
                    );
                }
            }

            return $this->mutateLocked(
                $submissionId,
                $companyId,
                ['ready'],
                static fn(array $row): array => $changes,
                'info',
                'The frozen CT600 package is being sent to the HMRC Transaction Engine.',
                ['actor' => $actor]
            );
        });
    }

    /** @param array<string, mixed> $acknowledgement @return array<string, mixed> */
    public function markAcknowledged(
        int $submissionId,
        int $companyId,
        array $acknowledgement,
    ): array {
        $pollInterval = max(1, min(86_400, (int)($acknowledgement['poll_interval_seconds'] ?? 0)));
        $nextPollAt = (string)($acknowledgement['next_poll_at'] ?? '');
        if ($nextPollAt === '') {
            $nextPollAt = gmdate('Y-m-d H:i:s', time() + $pollInterval);
        }
        $changes = [
            'status' => 'submitting',
            'protocol_state' => 'awaiting_poll',
            'hmrc_submission_reference' => $this->optionalText($acknowledgement['submission_reference'] ?? null, 255),
            'hmrc_correlation_id' => $this->boundedText(
                (string)($acknowledgement['correlation_id'] ?? ''),
                255,
                'HMRC correlation ID'
            ),
            'response_endpoint' => $this->httpsUrl((string)($acknowledgement['response_endpoint'] ?? '')),
            'poll_interval_seconds' => $pollInterval,
            'next_poll_at' => $this->dateTime($nextPollAt, 'next poll time'),
            'hmrc_response_code' => $this->responseCode($acknowledgement['response_code'] ?? null),
            'hmrc_response_summary' => $this->optionalSanitisedText($acknowledgement['summary'] ?? null, 4_000),
            'response_headers_json' => $this->safeJson((array)($acknowledgement['headers'] ?? [])),
        ];

        return $this->transition(
            $submissionId,
            $companyId,
            ['submitting', 'transport_uncertain'],
            $changes,
            'info',
            'HMRC acknowledged the transaction; this is not yet a business acceptance.',
            [
                'correlation_id' => $changes['hmrc_correlation_id'],
                'next_poll_at' => $changes['next_poll_at'],
            ]
        );
    }

    /** @return array<string, mixed> */
    public function markPollAttempt(
        int $submissionId,
        int $companyId,
        ?string $nextPollAt = null,
        array $responseHeaders = [],
    ): array {
        $this->requireSchema();
        return $this->mutateLocked(
            $submissionId,
            $companyId,
            ['awaiting_poll', 'transport_uncertain'],
            function (array $row) use ($nextPollAt, $responseHeaders): array {
                $interval = max(1, (int)($row['poll_interval_seconds'] ?? 1));
                return [
                    'protocol_state' => 'awaiting_poll',
                    'poll_attempts' => (int)($row['poll_attempts'] ?? 0) + 1,
                    'next_poll_at' => $this->dateTime(
                        $nextPollAt ?? gmdate('Y-m-d H:i:s', time() + $interval),
                        'next poll time'
                    ),
                    'response_headers_json' => $this->safeJson($responseHeaders),
                ];
            },
            'info',
            'HMRC status was checked; another poll is required.',
            []
        );
    }

    /**
     * @param array<string, mixed> $final
     * @return array<string, mixed>
     */
    public function markFinal(int $submissionId, int $companyId, array $final): array
    {
        $accepted = !empty($final['accepted']);
        $responsePath = $this->storageKey((string)($final['response_body_path'] ?? ''), 'response body');
        $responseHash = $this->hash((string)($final['response_sha256'] ?? ''), 'response');

        $this->requireSchema();
        return $this->mutateLocked(
            $submissionId,
            $companyId,
            ['submitting', 'awaiting_poll', 'transport_uncertain'],
            function (array $row) use ($accepted, $final, $responsePath, $responseHash): array {
                $environment = (string)$row['environment'];
                $outcome = $accepted
                    ? match ($environment) {
                        'TEST' => 'sandbox_passed',
                        'TIL' => 'til_validated',
                        'LIVE' => 'live_accepted',
                        default => throw new \DomainException('The persisted HMRC CT environment is invalid.'),
                    }
                    : (!empty($final['error']) ? 'error' : 'rejected');

                return [
                    'status' => $accepted ? 'accepted' : ($outcome === 'rejected' ? 'rejected' : 'failed'),
                    'protocol_state' => 'final_received',
                    'business_outcome' => $outcome,
                    'statutory_sync_state' => $accepted && $environment === 'LIVE'
                        ? 'pending'
                        : 'not_applicable',
                    'statutory_sync_error' => null,
                    'statutory_synced_at' => null,
                    'hmrc_response_code' => $this->responseCode($final['response_code'] ?? null),
                    'hmrc_response_summary' => $this->optionalSanitisedText($final['summary'] ?? null, 8_000),
                    'response_headers_json' => $this->safeJson((array)($final['headers'] ?? [])),
                    'response_body_path' => $responsePath,
                    'response_sha256' => $responseHash,
                    'next_poll_at' => null,
                    'final_response_at' => gmdate('Y-m-d H:i:s'),
                ];
            },
            $accepted ? 'success' : 'error',
            $accepted
                ? 'HMRC returned a final business acceptance.'
                : 'HMRC returned a final business rejection or error.',
            ['accepted' => $accepted]
        );
    }

    /**
     * Records a projection failure without changing the final HMRC business
     * outcome or the preserved receipt. Calling this after another worker has
     * already applied the projection is an idempotent no-op.
     *
     * @return array<string, mixed>
     */
    public function markStatutorySyncFailed(
        int $submissionId,
        int $companyId,
        string $error,
    ): array {
        $this->requireSchema();
        $error = $this->sanitisedText($error, 8_000);
        if ($error === '') {
            $error = 'The local statutory filing-state projection failed.';
        }

        return \InterfaceDB::transaction(function () use ($submissionId, $companyId, $error): array {
            $row = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($row)) {
                throw new \DomainException('The CT600 submission does not belong to the selected company.');
            }
            if ((string)($row['statutory_sync_state'] ?? '') === 'applied') {
                return $row;
            }
            $this->assertStatutorySyncEligible($row);

            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS . '
                 SET statutory_sync_state = :statutory_sync_state,
                     statutory_sync_error = :statutory_sync_error,
                     statutory_synced_at = NULL
                 WHERE id = :id AND company_id = :company_id',
                [
                    'statutory_sync_state' => 'failed',
                    'statutory_sync_error' => $error,
                    'id' => $submissionId,
                    'company_id' => $companyId,
                ]
            );
            $this->insertEvent(
                $submissionId,
                'error',
                'HMRC LIVE acceptance is preserved, but the local statutory filing-state projection failed.',
                ['error' => $error]
            );

            $updated = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($updated)) {
                throw new \RuntimeException('The failed statutory projection state could not be reloaded.');
            }
            return $updated;
        });
    }

    /**
     * Marks the local projection applied. This method deliberately changes
     * only projection metadata and can participate in a caller-owned database
     * transaction with the CT-period and obligation updates.
     *
     * @return array<string, mixed>
     */
    public function markStatutorySyncApplied(
        int $submissionId,
        int $companyId,
        string $syncedAt,
    ): array {
        $this->requireSchema();
        $syncedAt = $this->dateTime($syncedAt, 'statutory synchronisation time');

        return \InterfaceDB::transaction(function () use ($submissionId, $companyId, $syncedAt): array {
            $row = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($row)) {
                throw new \DomainException('The CT600 submission does not belong to the selected company.');
            }
            if ((string)($row['statutory_sync_state'] ?? '') === 'applied') {
                return $row;
            }
            $this->assertStatutorySyncEligible($row);

            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS . '
                 SET statutory_sync_state = :statutory_sync_state,
                     statutory_sync_error = NULL,
                     statutory_synced_at = :statutory_synced_at
                 WHERE id = :id AND company_id = :company_id',
                [
                    'statutory_sync_state' => 'applied',
                    'statutory_synced_at' => $syncedAt,
                    'id' => $submissionId,
                    'company_id' => $companyId,
                ]
            );
            $this->insertEvent(
                $submissionId,
                'success',
                'The HMRC LIVE acceptance was projected into the local statutory filing state.',
                ['statutory_synced_at' => $syncedAt]
            );

            $updated = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($updated)) {
                throw new \RuntimeException('The applied statutory projection state could not be reloaded.');
            }
            return $updated;
        });
    }

    /** @return array<string, mixed> */
    public function markCleanupPending(int $submissionId, int $companyId): array
    {
        return $this->transition(
            $submissionId,
            $companyId,
            ['final_received', 'delete_pending'],
            ['protocol_state' => 'delete_pending'],
            'info',
            'The final HMRC response is awaiting Transaction Engine deletion.',
            []
        );
    }

    /** @return array<string, mixed> */
    public function markCleanupComplete(
        int $submissionId,
        int $companyId,
        string $responsePath,
        string $responseSha256,
    ): array {
        return $this->transition(
            $submissionId,
            $companyId,
            ['final_received', 'delete_pending'],
            [
                'protocol_state' => 'closed',
                'cleanup_completed_at' => gmdate('Y-m-d H:i:s'),
                'cleanup_response_path' => $this->storageKey($responsePath, 'cleanup response'),
                'cleanup_response_sha256' => $this->hash($responseSha256, 'cleanup response'),
                'cleanup_error' => null,
            ],
            'success',
            'The Transaction Engine response was deleted and the protocol conversation was closed.',
            []
        );
    }

    /** @return array<string, mixed> */
    public function markCleanupFailed(int $submissionId, int $companyId, string $error): array
    {
        return $this->transition(
            $submissionId,
            $companyId,
            ['final_received', 'delete_pending'],
            [
                'protocol_state' => 'delete_pending',
                'cleanup_error' => $this->sanitisedText($error, 8_000),
            ],
            'warning',
            'The HMRC response is preserved, but Transaction Engine deletion must be retried.',
            []
        );
    }

    /** @return array<string, mixed> */
    public function markTransportUncertain(
        int $submissionId,
        int $companyId,
        string $summary,
        ?string $nextRecoveryAt = null,
    ): array {
        return $this->transition(
            $submissionId,
            $companyId,
            ['submitting', 'awaiting_poll', 'transport_uncertain'],
            [
                'protocol_state' => 'transport_uncertain',
                'hmrc_response_summary' => $this->sanitisedText($summary, 8_000),
                'next_poll_at' => $this->dateTime(
                    $nextRecoveryAt ?? gmdate('Y-m-d H:i:s', time() + 60),
                    'next recovery time'
                ),
            ],
            'warning',
            'The HMRC transport result is uncertain; the existing transaction must be recovered, not resubmitted.',
            []
        );
    }

    /** @param array<string, mixed> $recovery @return array<string, mixed> */
    public function markRecovered(int $submissionId, int $companyId, array $recovery): array
    {
        $this->requireSchema();
        return $this->mutateLocked(
            $submissionId,
            $companyId,
            ['transport_uncertain'],
            function (array $row) use ($recovery): array {
                $interval = max(1, min(86_400, (int)($recovery['poll_interval_seconds'] ?? 60)));
                return [
                    'protocol_state' => 'awaiting_poll',
                    'hmrc_correlation_id' => $this->boundedText(
                        (string)($recovery['correlation_id'] ?? $row['hmrc_correlation_id'] ?? ''),
                        255,
                        'HMRC correlation ID'
                    ),
                    'response_endpoint' => $this->httpsUrl(
                        (string)($recovery['response_endpoint'] ?? $row['response_endpoint'] ?? '')
                    ),
                    'poll_interval_seconds' => $interval,
                    'next_poll_at' => $this->dateTime(
                        (string)($recovery['next_poll_at'] ?? gmdate('Y-m-d H:i:s', time() + $interval)),
                        'next poll time'
                    ),
                    'recovery_attempts' => (int)($row['recovery_attempts'] ?? 0) + 1,
                    'last_recovery_at' => gmdate('Y-m-d H:i:s'),
                ];
            },
            'info',
            'The existing HMRC transaction was recovered and will continue by polling.',
            []
        );
    }

    /** @return array<string, mixed> */
    public function invalidatePrepared(int $submissionId, int $companyId, string $reason, string $actor): array
    {
        $reason = $this->sanitisedText($reason, 8_000);
        return $this->transition(
            $submissionId,
            $companyId,
            ['prepared', 'ready'],
            [
                'protocol_state' => 'invalidated',
                'status' => 'failed',
                'business_outcome' => 'error',
                'invalidated_at' => gmdate('Y-m-d H:i:s'),
                'invalidation_reason' => $reason,
            ],
            'warning',
            'The unsubmitted CT600 package was invalidated and must be prepared again.',
            ['actor' => $actor, 'reason' => $reason]
        );
    }

    /**
     * Invalidates every unsubmitted package in the selected locked-Year-End
     * scope. Call this after an unlock or any source-run/hash mismatch.
     */
    public function invalidateUnsubmittedForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $reason,
        string $actor,
        int $ctPeriodId = 0,
    ): int {
        $this->requireSchema();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \InvalidArgumentException('A valid company and accounting period are required.');
        }
        $reason = $this->sanitisedText($reason, 8_000);
        $actor = $this->boundedText($actor, 255, 'Invalidation actor');
        if ($reason === '') {
            throw new \InvalidArgumentException('An invalidation reason is required.');
        }

        return \InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $reason,
            $actor
        ): int {
            $period = \InterfaceDB::fetchOne(
                'SELECT id FROM accounting_periods
                 WHERE id = :accounting_period_id AND company_id = :company_id
                 LIMIT 1' . $this->forUpdateSuffix(),
                ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
            );
            if (!is_array($period)) {
                throw new \DomainException('The accounting period does not belong to the selected company.');
            }

            $where = [
                'company_id = :company_id',
                'accounting_period_id = :accounting_period_id',
                'protocol_state IN (\'prepared\', \'ready\')',
            ];
            $params = ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId];
            if ($ctPeriodId > 0) {
                $where[] = 'ct_period_id = :ct_period_id';
                $params['ct_period_id'] = $ctPeriodId;
            }
            $rows = \InterfaceDB::prepareExecute(
                'SELECT id FROM ' . self::SUBMISSIONS . '
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY id ASC' . $this->forUpdateSuffix(),
                $params
            )->fetchAll();
            $now = gmdate('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $submissionId = (int)($row['id'] ?? 0);
                if ($submissionId <= 0) {
                    continue;
                }
                \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . '
                     SET protocol_state = :protocol_state, status = :status,
                         business_outcome = :business_outcome, invalidated_at = :invalidated_at,
                         invalidation_reason = :invalidation_reason
                     WHERE id = :id AND company_id = :company_id
                       AND protocol_state IN (\'prepared\', \'ready\')',
                    [
                        'protocol_state' => 'invalidated',
                        'status' => 'failed',
                        'business_outcome' => 'error',
                        'invalidated_at' => $now,
                        'invalidation_reason' => $reason,
                        'id' => $submissionId,
                        'company_id' => $companyId,
                    ]
                );
                $this->insertEvent(
                    $submissionId,
                    'warning',
                    'The unsubmitted CT600 package was invalidated and must be prepared again.',
                    ['actor' => $actor, 'reason' => $reason]
                );
            }

            return count($rows);
        });
    }

    /**
     * Prevents Year End being unlocked while a GovTalk transaction can still
     * produce a remote business result.  The caller must first lock the
     * year_end_reviews row so this check serialises with markSubmitting().
     */
    public function assertNoActiveRemoteTransactionsForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
    ): void {
        $this->requireSchema();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \InvalidArgumentException('A valid company and accounting period are required.');
        }
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('The active HMRC transaction guard requires a database transaction.');
        }

        $active = \InterfaceDB::fetchOne(
            'SELECT id, environment, protocol_state FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
               AND protocol_state IN (\'submitting\', \'awaiting_poll\', \'transport_uncertain\')
             ORDER BY id ASC LIMIT 1' . $this->forUpdateSuffix(),
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (is_array($active)) {
            throw new \DomainException(
                'Year End cannot be unlocked while HMRC CT600 submission #'
                . (int)$active['id'] . ' (' . (string)$active['environment'] . ') is '
                . str_replace('_', ' ', (string)$active['protocol_state'])
                . '. Recover or complete that remote transaction first.'
            );
        }
    }

    /**
     * A guarded generic transition for orchestration branches not covered by
     * the convenience methods. Immutable package/source fields are excluded.
     *
     * @param list<string> $expectedProtocolStates
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $eventContext
     * @return array<string, mixed>
     */
    public function transition(
        int $submissionId,
        int $companyId,
        array $expectedProtocolStates,
        array $changes,
        string $eventLevel,
        string $eventMessage,
        array $eventContext = [],
    ): array {
        $this->requireSchema();
        return $this->mutateLocked(
            $submissionId,
            $companyId,
            $expectedProtocolStates,
            static fn(array $row): array => $changes,
            $eventLevel,
            $eventMessage,
            $eventContext
        );
    }

    public function recordEvent(
        int $submissionId,
        int $companyId,
        string $level,
        string $message,
        array $context = [],
    ): void {
        $this->requireSchema();
        \InterfaceDB::transaction(function () use ($submissionId, $companyId, $level, $message, $context): void {
            $row = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($row)) {
                throw new \DomainException('The CT600 submission does not belong to the selected company.');
            }
            $this->insertEvent($submissionId, $level, $message, $context);
        });
    }

    /** @return list<array<string, mixed>> */
    public function fetchEvents(int $submissionId, int $companyId, int $limit = 250): array
    {
        $this->requireSchema();
        if ($this->fetchOwned($submissionId, $companyId) === null) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        return \InterfaceDB::fetchAll(
            'SELECT id, submission_id, event_level, event_message, event_context_json, created_at
             FROM ' . self::EVENTS . '
             WHERE submission_id = :submission_id ORDER BY id ASC LIMIT ' . $limit,
            ['submission_id' => $submissionId]
        );
    }

    /**
     * @param list<string> $expectedProtocolStates
     * @param callable(array<string, mixed>): array<string, mixed> $changesResolver
     * @param array<string, mixed> $eventContext
     * @return array<string, mixed>
     */
    private function mutateLocked(
        int $submissionId,
        int $companyId,
        array $expectedProtocolStates,
        callable $changesResolver,
        string $eventLevel,
        string $eventMessage,
        array $eventContext,
    ): array {
        $expectedProtocolStates = $this->protocolStates($expectedProtocolStates);
        $eventLevel = $this->eventLevel($eventLevel);
        $eventMessage = $this->sanitisedText($eventMessage, 1_000);

        return \InterfaceDB::transaction(function () use (
            $submissionId,
            $companyId,
            $expectedProtocolStates,
            $changesResolver,
            $eventLevel,
            $eventMessage,
            $eventContext
        ): array {
            $row = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($row)) {
                throw new \DomainException('The CT600 submission does not belong to the selected company.');
            }
            $currentState = (string)($row['protocol_state'] ?? '');
            if (!in_array($currentState, $expectedProtocolStates, true)) {
                throw new \DomainException(
                    'The CT600 submission cannot change from its current protocol state (' . $currentState . ').'
                );
            }

            $changes = $this->normaliseMutableChanges($changesResolver($row), $row);
            if ($changes !== []) {
                $assignments = [];
                $params = ['id' => $submissionId, 'company_id' => $companyId];
                foreach ($changes as $column => $value) {
                    $assignments[] = $column . ' = :' . $column;
                    $params[$column] = $value;
                }
                \InterfaceDB::prepareExecute(
                    'UPDATE ' . self::SUBMISSIONS . ' SET ' . implode(', ', $assignments) . '
                     WHERE id = :id AND company_id = :company_id',
                    $params
                );
            }
            $this->insertEvent($submissionId, $eventLevel, $eventMessage, $eventContext);

            $updated = $this->fetchOwnedForUpdate($submissionId, $companyId);
            if (!is_array($updated)) {
                throw new \RuntimeException('The updated CT600 submission could not be reloaded.');
            }
            return $updated;
        });
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function normalisePreparedSource(array $source, string $actor): array
    {
        $companyId = (int)($source['company_id'] ?? 0);
        $accountingPeriodId = (int)($source['accounting_period_id'] ?? 0);
        $ctPeriodId = (int)($source['ct_period_id'] ?? 0);
        $accountsRunId = (int)($source['accounts_run_id'] ?? 0);
        $computationRunId = (int)($source['computation_run_id'] ?? 0);
        foreach (
            [
                'company' => $companyId,
                'accounting period' => $accountingPeriodId,
                'Corporation Tax period' => $ctPeriodId,
                'accounts iXBRL run' => $accountsRunId,
                'computation iXBRL run' => $computationRunId,
            ] as $label => $id
        ) {
            if ($id <= 0) {
                throw new \InvalidArgumentException('A valid ' . $label . ' ID is required.');
            }
        }

        $environment = $this->environment((string)($source['environment'] ?? $source['mode'] ?? ''));
        $submissionType = strtolower(trim((string)($source['submission_type'] ?? 'original')));
        if ($submissionType !== 'original') {
            throw new \DomainException('Phase one supports original CT600 returns only.');
        }

        return [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'mode' => $environment,
            'environment' => $environment,
            'status' => 'ready',
            'protocol_state' => 'prepared',
            'business_outcome' => 'none',
            'statutory_sync_state' => 'not_applicable',
            'submission_type' => $submissionType,
            'ct600_xml_path' => $this->storageKey((string)($source['ct600_xml_path'] ?? ''), 'CT600'),
            'accounts_ixbrl_path' => $this->storageKey((string)($source['accounts_ixbrl_path'] ?? ''), 'accounts iXBRL'),
            'accounts_run_id' => $accountsRunId,
            'accounts_sha256' => $this->hash((string)($source['accounts_sha256'] ?? ''), 'accounts iXBRL'),
            'computations_ixbrl_path' => $this->storageKey(
                (string)($source['computations_ixbrl_path'] ?? ''),
                'computations iXBRL'
            ),
            'computation_run_id' => $computationRunId,
            'computations_sha256' => $this->hash(
                (string)($source['computations_sha256'] ?? ''),
                'computations iXBRL'
            ),
            'year_end_locked_at' => $this->dateTime(
                (string)($source['year_end_locked_at'] ?? ''),
                'Year End lock time'
            ),
            'package_hash' => $this->hash((string)($source['package_hash'] ?? ''), 'package'),
            'idempotency_key' => $this->hash((string)($source['idempotency_key'] ?? ''), 'idempotency key'),
            'transaction_id' => $this->boundedIdentifier((string)($source['transaction_id'] ?? ''), 64, 'transaction ID'),
            'irmark' => $this->irmark((string)($source['irmark'] ?? '')),
            'schema_version' => $this->boundedIdentifier((string)($source['schema_version'] ?? ''), 50, 'schema version'),
            'body_sha256' => $this->hash((string)($source['body_sha256'] ?? ''), 'GovTalk body'),
            'ct600_sha256' => $this->hash((string)($source['ct600_sha256'] ?? ''), 'CT600'),
            'request_body_path' => isset($source['request_body_path']) && trim((string)$source['request_body_path']) !== ''
                ? $this->storageKey((string)$source['request_body_path'], 'request body')
                : null,
            'manifest_path' => $this->storageKey((string)($source['manifest_path'] ?? ''), 'manifest'),
            'validation_json' => $this->safeJson((array)($source['validation'] ?? [])),
            'declarant_name' => $this->boundedText(
                (string)($source['declarant_name'] ?? ''),
                255,
                'Frozen declarant name'
            ),
            'declarant_status' => $this->declarantStatus((string)($source['declarant_status'] ?? '')),
            'prepared_by' => $this->boundedText($actor, 255, 'Preparer'),
        ];
    }

    /** @param array<string, mixed> $row */
    private function assertSourceOwnership(array $row): void
    {
        $period = \InterfaceDB::fetchOne(
            'SELECT ctp.id
             FROM corporation_tax_periods ctp
             INNER JOIN accounting_periods ap ON ap.id = ctp.accounting_period_id
             WHERE ctp.id = :ct_period_id
               AND ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
               AND ap.company_id = :company_id
             LIMIT 1' . $this->forUpdateSuffix(),
            [
                'ct_period_id' => (int)$row['ct_period_id'],
                'company_id' => (int)$row['company_id'],
                'accounting_period_id' => (int)$row['accounting_period_id'],
            ]
        );
        if (!is_array($period)) {
            throw new \DomainException('The Corporation Tax period does not belong to the selected locked Year End.');
        }

        $accountsRun = \InterfaceDB::fetchOne(
            'SELECT id FROM ixbrl_generation_runs
             WHERE id = :run_id AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id LIMIT 1' . $this->forUpdateSuffix(),
            [
                'run_id' => (int)$row['accounts_run_id'],
                'company_id' => (int)$row['company_id'],
                'accounting_period_id' => (int)$row['accounting_period_id'],
            ]
        );
        if (!is_array($accountsRun)) {
            throw new \DomainException('The accounts iXBRL run does not belong to the selected locked Year End.');
        }

        $computationRun = \InterfaceDB::fetchOne(
            'SELECT id FROM corporation_tax_computation_runs
             WHERE id = :run_id AND company_id = :company_id
               AND accounting_period_id = :accounting_period_id AND ct_period_id = :ct_period_id
             LIMIT 1' . $this->forUpdateSuffix(),
            [
                'run_id' => (int)$row['computation_run_id'],
                'company_id' => (int)$row['company_id'],
                'accounting_period_id' => (int)$row['accounting_period_id'],
                'ct_period_id' => (int)$row['ct_period_id'],
            ]
        );
        if (!is_array($computationRun)) {
            throw new \DomainException('The computations iXBRL run does not belong to the selected Corporation Tax period.');
        }
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $candidate */
    private function assertSamePreparedSource(array $existing, array $candidate): void
    {
        foreach (
            [
                'company_id', 'accounting_period_id', 'ct_period_id', 'environment', 'submission_type',
                'accounts_run_id', 'accounts_sha256', 'computation_run_id', 'computations_sha256',
                'year_end_locked_at', 'package_hash', 'body_sha256', 'ct600_sha256', 'manifest_path',
                'declarant_name', 'declarant_status',
            ] as $field
        ) {
            if ((string)($existing[$field] ?? '') !== (string)($candidate[$field] ?? '')) {
                throw new \DomainException('The CT600 idempotency key is already bound to different frozen source data.');
            }
        }
    }

    /** @param array<string, mixed> $changes @param array<string, mixed> $row @return array<string, mixed> */
    private function normaliseMutableChanges(array $changes, array $row): array
    {
        $allowed = [
            'status', 'protocol_state', 'business_outcome', 'statutory_sync_state',
            'statutory_sync_error', 'statutory_synced_at', 'hmrc_submission_reference',
            'hmrc_correlation_id', 'response_endpoint', 'poll_interval_seconds', 'next_poll_at',
            'poll_attempts', 'hmrc_response_code', 'hmrc_response_summary', 'request_headers_json',
            'response_headers_json', 'request_body_path', 'response_body_path', 'response_sha256',
            'declaration_confirmed', 'supplementary_scope_confirmed', 'original_unfiled_confirmed',
            'declaration_approved_at',
            'declaration_approved_by', 'approved_package_hash', 'submitted_by', 'submitted_by_user_id',
            'submitted_at', 'final_response_at', 'cleanup_completed_at', 'cleanup_response_path',
            'cleanup_response_sha256', 'cleanup_error', 'recovery_attempts', 'last_recovery_at',
            'invalidated_at', 'invalidation_reason',
        ];
        foreach (array_keys($changes) as $column) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException('The immutable or unsupported submission field cannot be changed: ' . $column);
            }
        }

        if (($changes['approved_package_hash'] ?? null) === '__CURRENT_PACKAGE_HASH__') {
            $changes['approved_package_hash'] = (string)($row['package_hash'] ?? '');
        }
        if (isset($changes['status']) && !in_array((string)$changes['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid CT600 status.');
        }
        if (isset($changes['protocol_state']) && !in_array((string)$changes['protocol_state'], self::PROTOCOL_STATES, true)) {
            throw new \InvalidArgumentException('Invalid CT600 protocol state.');
        }
        if (isset($changes['business_outcome']) && !in_array((string)$changes['business_outcome'], self::BUSINESS_OUTCOMES, true)) {
            throw new \InvalidArgumentException('Invalid CT600 business outcome.');
        }
        if (isset($changes['statutory_sync_state'])
            && !in_array((string)$changes['statutory_sync_state'], self::STATUTORY_SYNC_STATES, true)) {
            throw new \InvalidArgumentException('Invalid CT600 statutory synchronisation state.');
        }

        foreach (['request_body_path', 'response_body_path', 'cleanup_response_path'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = $this->storageKey((string)$changes[$field], $field);
            }
        }
        foreach (['response_sha256', 'cleanup_response_sha256', 'approved_package_hash'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = $this->hash((string)$changes[$field], $field);
            }
        }
        foreach (['request_headers_json', 'response_headers_json'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $decoded = is_array($changes[$field])
                    ? $changes[$field]
                    : json_decode((string)$changes[$field], true, 32, JSON_THROW_ON_ERROR);
                $changes[$field] = $this->safeJson(is_array($decoded) ? $decoded : []);
            }
        }
        foreach (['declaration_confirmed', 'supplementary_scope_confirmed', 'original_unfiled_confirmed'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = !empty($changes[$field]) ? 1 : 0;
            }
        }
        foreach (['poll_interval_seconds', 'poll_attempts', 'recovery_attempts'] as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = max(0, (int)$changes[$field]);
            }
        }
        if (isset($changes['poll_interval_seconds'])) {
            $changes['poll_interval_seconds'] = min(86_400, (int)$changes['poll_interval_seconds']);
        }
        if (array_key_exists('submitted_by_user_id', $changes)) {
            $changes['submitted_by_user_id'] = (int)$changes['submitted_by_user_id'] > 0
                ? (int)$changes['submitted_by_user_id']
                : null;
        }
        if (array_key_exists('hmrc_response_code', $changes)) {
            $changes['hmrc_response_code'] = $this->responseCode($changes['hmrc_response_code']);
        }
        foreach (
            [
                'hmrc_submission_reference' => 255,
                'hmrc_correlation_id' => 255,
                'declaration_approved_by' => 255,
                'submitted_by' => 100,
            ] as $field => $limit
        ) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = $this->optionalText($changes[$field], $limit);
            }
        }
        if (array_key_exists('response_endpoint', $changes) && $changes['response_endpoint'] !== null) {
            $changes['response_endpoint'] = $this->httpsUrl((string)$changes['response_endpoint']);
        }
        foreach (
            [
                'next_poll_at', 'declaration_approved_at', 'submitted_at', 'final_response_at',
                'cleanup_completed_at', 'last_recovery_at', 'invalidated_at',
                'statutory_synced_at',
            ] as $field
        ) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = $this->dateTime((string)$changes[$field], $field);
            }
        }
        foreach (
            [
                'hmrc_response_summary' => 8_000,
                'cleanup_error' => 8_000,
                'invalidation_reason' => 8_000,
                'statutory_sync_error' => 8_000,
            ] as $field => $limit
        ) {
            if (array_key_exists($field, $changes) && $changes[$field] !== null) {
                $changes[$field] = $this->sanitisedText((string)$changes[$field], $limit);
            }
        }

        return $changes;
    }

    /** @param array<string, mixed> $row */
    private function assertStatutorySyncEligible(array $row): void
    {
        if ((string)($row['environment'] ?? '') !== 'LIVE'
            || (string)($row['submission_type'] ?? '') !== 'original'
            || (string)($row['business_outcome'] ?? '') !== 'live_accepted'
            || (string)($row['status'] ?? '') !== 'accepted'
            || !in_array(
                (string)($row['protocol_state'] ?? ''),
                ['final_received', 'delete_pending', 'closed'],
                true
            )
            || trim((string)($row['final_response_at'] ?? '')) === ''
            || trim((string)($row['response_body_path'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/D', strtolower(trim((string)($row['response_sha256'] ?? '')))) !== 1
        ) {
            throw new \DomainException(
                'Only a final LIVE accepted original with a preserved HMRC receipt can update statutory filing state.'
            );
        }
        if (!in_array((string)($row['statutory_sync_state'] ?? ''), ['pending', 'failed', 'applied'], true)) {
            throw new \DomainException('This submission has no statutory filing-state projection pending.');
        }
    }

    /** @return array<string, mixed>|null */
    private function fetchOwnedForUpdate(int $submissionId, int $companyId): ?array
    {
        [$sql, $params] = $this->ownedSql($submissionId, $companyId, 0, 0, true);
        $row = \InterfaceDB::fetchOne($sql, $params);
        return is_array($row) ? $row : null;
    }

    /** @return array{0: string, 1: array<string, int>} */
    private function ownedSql(
        int $submissionId,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $forUpdate,
    ): array {
        $where = ['id = :id', 'company_id = :company_id'];
        $params = ['id' => $submissionId, 'company_id' => $companyId];
        if ($accountingPeriodId > 0) {
            $where[] = 'accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }
        if ($ctPeriodId > 0) {
            $where[] = 'ct_period_id = :ct_period_id';
            $params['ct_period_id'] = $ctPeriodId;
        }

        return [
            'SELECT * FROM ' . self::SUBMISSIONS . ' WHERE ' . implode(' AND ', $where)
                . ' LIMIT 1' . ($forUpdate ? $this->forUpdateSuffix() : ''),
            $params,
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchByIdempotencyKeyInternal(
        int $companyId,
        string $environment,
        string $idempotencyKey,
        bool $forUpdate,
    ): ?array {
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::SUBMISSIONS . '
             WHERE company_id = :company_id AND environment = :environment
               AND idempotency_key = :idempotency_key LIMIT 1'
                . ($forUpdate ? $this->forUpdateSuffix() : ''),
            [
                'company_id' => $companyId,
                'environment' => $environment,
                'idempotency_key' => $idempotencyKey,
            ]
        );
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $context */
    private function insertEvent(int $submissionId, string $level, string $message, array $context): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::EVENTS . ' (
                submission_id, event_level, event_message, event_context_json
             ) VALUES (:submission_id, :event_level, :event_message, :event_context_json)',
            [
                'submission_id' => $submissionId,
                'event_level' => $this->eventLevel($level),
                'event_message' => $this->sanitisedText($message, 1_000),
                'event_context_json' => $this->safeJson($context),
            ]
        );
    }

    /** @param array<string, mixed> $value */
    private function safeJson(array $value): string
    {
        $sanitised = $this->sanitiseValue($value, 0);
        $json = json_encode($sanitised, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_EVENT_JSON_BYTES) {
            $json = json_encode(
                ['truncated' => true, 'reason' => 'sanitised context exceeded the persistence limit'],
                JSON_THROW_ON_ERROR
            );
        }
        return $json;
    }

    private function sanitiseValue(mixed $value, int $depth): mixed
    {
        if ($depth >= 6) {
            return '[TRUNCATED]';
        }
        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item) {
                if (++$count > 50) {
                    $result['_truncated'] = true;
                    break;
                }
                $key = is_int($key) ? $key : substr($this->sanitisedText((string)$key, 120), 0, 120);
                if (is_string($key) && $this->sensitiveKey($key)) {
                    $result[$key] = '[REDACTED]';
                    continue;
                }
                $result[$key] = $this->sanitiseValue($item, $depth + 1);
            }
            return $result;
        }
        if (is_object($value)) {
            return '[OBJECT REDACTED]';
        }
        if (is_resource($value)) {
            return '[RESOURCE REDACTED]';
        }
        if (is_string($value)) {
            return $this->sanitisedText($value, 2_000);
        }
        return $value;
    }

    private function sensitiveKey(string $key): bool
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key) ?? '');
        foreach (
            [
                'password', 'secret', 'token', 'authorization', 'cookie', 'credential', 'apikey',
                'senderid', 'idauthentication', 'utr',
            ] as $fragment
        ) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function sanitisedText(string $value, int $maxBytes): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $patterns = [
            '/(Authorization\s*:\s*(?:Basic|Bearer)\s+)[^\s<]+/i',
            '/(<\s*(?:[A-Za-z0-9_-]+:)?Password\b[^>]*>).*?(<\s*\/\s*(?:[A-Za-z0-9_-]+:)?Password\s*>)/is',
            '/("(?:password|secret|token|authorization|credential)"\s*:\s*")[^"]*(")/i',
        ];
        $replacements = ['$1[REDACTED]', '$1[REDACTED]$2', '$1[REDACTED]$2'];
        $value = preg_replace($patterns, $replacements, $value) ?? '';
        if (strlen($value) > $maxBytes) {
            $value = substr($value, 0, max(0, $maxBytes - 14)) . '[TRUNCATED]';
        }
        return trim($value);
    }

    private function optionalSanitisedText(mixed $value, int $maxBytes): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->sanitisedText((string)$value, $maxBytes);
    }

    private function boundedText(string $value, int $maxBytes, string $label): string
    {
        $value = $this->sanitisedText($value, $maxBytes);
        if ($value === '') {
            throw new \InvalidArgumentException($label . ' is required.');
        }
        return $value;
    }

    private function optionalText(mixed $value, int $maxBytes): ?string
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        return $this->sanitisedText((string)$value, $maxBytes);
    }

    private function boundedIdentifier(string $value, int $maxBytes, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes || preg_match('/^[A-Za-z0-9._:-]+$/D', $value) !== 1) {
            throw new \InvalidArgumentException('A valid ' . $label . ' is required.');
        }
        return $value;
    }

    private function declarantStatus(string $value): string
    {
        $value = trim($value);
        if (!in_array($value, ['Proper officer', 'Authorised person'], true)) {
            throw new \InvalidArgumentException(
                'The frozen declarant status must be Proper officer or Authorised person.'
            );
        }

        return $value;
    }

    private function storageKey(string $value, string $label): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if (
            $value === ''
            || str_starts_with($value, '/')
            || preg_match('/^[A-Za-z]:\//D', $value) === 1
            || str_contains($value, "\0")
            || strlen($value) > 1000
        ) {
            throw new \InvalidArgumentException('A protected relative ' . $label . ' storage key is required.');
        }
        foreach (explode('/', trim($value, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('The ' . $label . ' storage key is invalid.');
            }
        }
        return trim($value, '/');
    }

    private function hash(string $value, string $label): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('The ' . $label . ' SHA-256 fingerprint is invalid.');
        }
        return $value;
    }

    private function irmark(string $value): string
    {
        $value = trim($value);
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || strlen($decoded) !== 20 || strlen($value) > 64) {
            throw new \InvalidArgumentException('The generic IRmark is invalid.');
        }
        return $value;
    }

    private function dateTime(string $value, string $label): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', trim($value), new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== trim($value)) {
            throw new \InvalidArgumentException('A valid UTC ' . $label . ' is required.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function httpsUrl(string $value): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 1000
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($value, PHP_URL_SCHEME)) !== 'https'
        ) {
            throw new \InvalidArgumentException('A valid HTTPS HMRC response endpoint is required.');
        }
        return $value;
    }

    private function responseCode(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $code = (int)$value;
        return $code >= 100 && $code <= 599 ? $code : null;
    }

    /** @param list<string> $states @return list<string> */
    private function protocolStates(array $states): array
    {
        $states = array_values(array_unique(array_map(static fn(mixed $state): string => trim((string)$state), $states)));
        if ($states === []) {
            throw new \InvalidArgumentException('At least one expected CT600 protocol state is required.');
        }
        foreach ($states as $state) {
            if (!in_array($state, self::PROTOCOL_STATES, true)) {
                throw new \InvalidArgumentException('Invalid expected CT600 protocol state.');
            }
        }
        return $states;
    }

    private function environment(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, self::ENVIRONMENTS, true)) {
            throw new \InvalidArgumentException('The HMRC CT environment must be TEST, TIL or LIVE.');
        }
        return $environment;
    }

    private function eventLevel(string $level): string
    {
        $level = strtolower(trim($level));
        if (!in_array($level, self::EVENT_LEVELS, true)) {
            throw new \InvalidArgumentException('Invalid CT600 submission event level.');
        }
        return $level;
    }

    private function forUpdateSuffix(): string
    {
        return \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
