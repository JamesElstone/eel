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
use eel_accounts\Support\Utf8;

final class GovTalkProtocolConversationService
{
    private const EXCHANGES = 'govtalk_protocol_exchanges';

    public function __construct(
        private readonly ?TransmissionArchiveService $archiveService = null
    ) {
    }

    public function schemaReady(): bool
    {
        return \InterfaceDB::tableExists(self::EXCHANGES)
            && \InterfaceDB::columnExists(self::EXCHANGES, 'authority')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'transmission_archive_id')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'hmrc_submission_id')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'response_headers_sha256');
    }

    /**
     * @param array<string,mixed> $identity
     */
    public function context(array $identity): GovTalkConversationContext
    {
        $identity = $this->identity($identity);

        return new GovTalkConversationContext(
            $identity['authority'],
            $identity['company_id'],
            $identity['accounting_period_id'],
            $identity['environment'],
            $identity['archive_reference'],
            fn(array $request): array => $this->captureRequest($identity, $request),
            function (array $request) use ($identity): void {
                $this->markSendStarted(
                    $identity['authority'],
                    $identity['environment'],
                    (string)($request['transaction_id'] ?? '')
                );
            },
            fn(array $response): array => $this->captureResponse($identity, $response),
            function (string $state, string $error, array $result) use ($identity): void {
                $structuredErrors = $result['govtalk_errors'] ?? null;
                if ($structuredErrors !== null && !is_array($structuredErrors)) {
                    throw new \InvalidArgumentException(
                        'The final GovTalk structured errors must be an array.'
                    );
                }
                $this->completeExchange(
                    $identity['authority'],
                    $identity['environment'],
                    (string)($result['transaction_id'] ?? ''),
                    $state,
                    $this->outcomeCode($result),
                    $this->outcomeSummary($result),
                    $error,
                    '',
                    $structuredErrors
                );
            },
            function (string $transactionId, string $error) use ($identity): void {
                $this->markEvidenceIncomplete(
                    $identity['authority'],
                    $identity['environment'],
                    $transactionId,
                    $error
                );
            }
        );
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function captureRequest(array $identity, array $request): array
    {
        $this->requireSchema();
        $identity = $this->identity($identity);
        $transactionId = strtoupper(trim((string)($request['transaction_id'] ?? '')));
        $xml = (string)($request['raw_request_xml'] ?? $request['request_xml'] ?? '');
        if ($transactionId === '' || $xml === '') {
            throw new \InvalidArgumentException(
                'The GovTalk request transaction and exact XML are required.'
            );
        }
        $requestSha256 = strtolower(trim((string)($request['request_sha256'] ?? '')));
        if ($requestSha256 !== '' && !hash_equals(hash('sha256', $xml), $requestSha256)) {
            throw new \RuntimeException('The GovTalk request checksum did not match its exact XML.');
        }
        $filename = $this->filename(
            (string)($identity['request_filename'] ?? ''),
            $identity['operation'],
            $transactionId,
            'request'
        );
        $stored = $this->archives()->store(
            $identity['company_id'],
            $identity['accounting_period_id'],
            $identity['authority'],
            $identity['environment'],
            $identity['archive_reference'],
            $identity['lifecycle'],
            $filename,
            $xml
        );
        $details = (new GovTalkProtocolMetadataService())
            ->requestMessageDetails($xml, $identity['operation']);
        if ($details['transaction_id'] !== ''
            && strcasecmp($details['transaction_id'], $transactionId) !== 0) {
            throw new \RuntimeException(
                'The GovTalk envelope transaction ID did not match the conversation.'
            );
        }
        $this->upsertExchange(
            $identity,
            $transactionId,
            'prepared',
            [
                'message_class' => $details['class'],
                'qualifier' => $details['qualifier'],
                'function' => $details['function'],
                'correlation_id' => $details['correlation_id']
                    ?: trim((string)($request['correlation_id'] ?? '')),
                'endpoint' => trim((string)($request['endpoint'] ?? $identity['endpoint'] ?? '')),
                'path' => $stored['path'],
                'sha256' => $stored['sha256'],
                'bytes' => $stored['bytes'],
                'archive_id' => $stored['archive_id'],
            ],
            null,
            null,
            '',
            '',
            ''
        );
        $this->refreshManifest($identity, 'prepared');

        return $stored + [
            'transaction_id' => $transactionId,
            'request_sha256' => $stored['sha256'],
            'request_bytes' => $stored['bytes'],
        ];
    }

    public function markSendStarted(
        string $authority,
        string $environment,
        string $transactionId
    ): void {
        $this->requireSchema();
        $authority = $this->authority($authority);
        $environment = $this->environment($environment);
        $transactionId = strtoupper(trim($transactionId));
        $now = gmdate('Y-m-d H:i:s');
        $statement = \InterfaceDB::prepareExecute(
            'UPDATE ' . self::EXCHANGES . '
             SET exchange_state = :state,
                 sent_at = COALESCE(sent_at, :sent_at),
                 updated_at = :updated_at
             WHERE authority = :authority
               AND environment = :environment
               AND transaction_id = :transaction_id
               AND request_path IS NOT NULL
               AND exchange_state = :prepared',
            [
                'state' => 'sent',
                'sent_at' => $now,
                'updated_at' => $now,
                'authority' => $authority,
                'environment' => $environment,
                'transaction_id' => $transactionId,
                'prepared' => 'prepared',
            ]
        );
        if ($statement->rowCount() !== 1) {
            $existing = $this->exchange($authority, $environment, $transactionId);
            if (!is_array($existing) || (string)$existing['exchange_state'] !== 'sent') {
                throw new \RuntimeException(
                    'The GovTalk request was not durably prepared before transport.'
                );
            }
        }
        try {
            $this->refreshManifestForExchange(
                $authority,
                $environment,
                $transactionId,
                'sending'
            );
        } catch (\Throwable $exception) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::EXCHANGES . '
                 SET exchange_state = :prepared, sent_at = NULL, updated_at = :updated_at
                 WHERE authority = :authority
                   AND environment = :environment
                   AND transaction_id = :transaction_id
                   AND exchange_state = :sent',
                [
                    'prepared' => 'prepared',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'authority' => $authority,
                    'environment' => $environment,
                    'transaction_id' => $transactionId,
                    'sent' => 'sent',
                ]
            );
            throw $exception;
        }
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public function captureResponse(array $identity, array $response): array
    {
        $this->requireSchema();
        $identity = $this->identity($identity);
        $transactionId = strtoupper(trim((string)($response['transaction_id'] ?? '')));
        if ($transactionId === '') {
            throw new \InvalidArgumentException('The GovTalk response transaction ID is required.');
        }
        $existing = $this->exchange(
            $identity['authority'],
            $identity['environment'],
            $transactionId
        );
        if (!is_array($existing)) {
            throw new \RuntimeException(
                'The GovTalk request evidence was not recorded before its response.'
            );
        }
        $xml = (string)($response['response_xml'] ?? '');
        $stored = null;
        if ($xml !== '') {
            $filename = $this->filename(
                (string)($identity['response_filename'] ?? ''),
                $identity['operation'],
                $transactionId,
                'response'
            );
            $stored = $this->archives()->store(
                $identity['company_id'],
                $identity['accounting_period_id'],
                $identity['authority'],
                $identity['environment'],
                $identity['archive_reference'],
                'received',
                $filename,
                $xml
            );
        }
        $metadata = new GovTalkProtocolMetadataService();
        $headers = $metadata->sanitizeResponseHeaders(
            is_array($response['response_headers'] ?? null)
                ? $response['response_headers']
                : []
        );
        $headersJson = $metadata->responseHeadersJson($headers);
        $headersSha256 = hash('sha256', $headersJson);
        $suppliedHeaderSha = strtolower(trim((string)(
            $response['response_headers_sha256'] ?? ''
        )));
        if ($suppliedHeaderSha === '' || !hash_equals($headersSha256, $suppliedHeaderSha)) {
            throw new \RuntimeException(
                'The GovTalk response-header evidence checksum did not match.'
            );
        }
        $responseEvidence = [
            'path' => $stored['path'] ?? null,
            'sha256' => $stored['sha256'] ?? null,
            'bytes' => $stored['bytes'] ?? 0,
            'headers_json' => $headersJson,
            'headers_sha256' => $headersSha256,
            'govtalk_errors_json' => Utf8::json(
                $metadata->govTalkErrors($xml),
                JSON_THROW_ON_ERROR
            ),
        ];
        $identity['transmission_archive_id'] = (int)$existing['transmission_archive_id'];
        $this->upsertExchange(
            $identity,
            $transactionId,
            'received',
            null,
            $responseEvidence,
            (int)($response['status_code'] ?? 0),
            '',
            '',
            ''
        );
        $this->refreshManifest($identity, 'received');

        return ($stored ?? [
            'path' => null,
            'sha256' => null,
            'bytes' => 0,
            'archive_id' => (int)$existing['transmission_archive_id'],
            'archive_path' => null,
            'manifest_path' => null,
        ]) + [
            'transaction_id' => $transactionId,
            'response_sha256' => $xml !== '' ? hash('sha256', $xml) : null,
            'response_bytes' => strlen($xml),
            'response_headers_sha256' => $headersSha256,
        ];
    }

    /**
     * @param list<array<string,mixed>>|null $structuredErrors
     */
    public function completeExchange(
        string $authority,
        string $environment,
        string $transactionId,
        string $state,
        string $outcomeCode = '',
        string $outcomeSummary = '',
        string $error = '',
        string $correlationId = '',
        ?array $structuredErrors = null
    ): void {
        if (!$this->schemaReady() || trim($transactionId) === '') {
            return;
        }
        $correlationId = strtoupper(trim($correlationId));
        if ($correlationId !== '' && preg_match('/^[0-9A-F]{1,32}$/D', $correlationId) !== 1) {
            throw new \InvalidArgumentException('The GovTalk correlation ID is invalid.');
        }
        $allowed = [
            'prepared', 'sent', 'received', 'succeeded', 'rejected',
            'transport_unknown', 'evidence_incomplete', 'failed',
        ];
        if (!in_array($state, $allowed, true)) {
            throw new \InvalidArgumentException('The GovTalk exchange state is invalid.');
        }
        $structuredErrorsJson = $structuredErrors !== null
            ? $this->structuredErrorsJson($structuredErrors)
            : null;
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::EXCHANGES . '
             SET exchange_state = :state,
                 correlation_id = COALESCE(:correlation_id, correlation_id),
                 govtalk_errors_json = COALESCE(:structured_errors_json, govtalk_errors_json),
                 outcome_code = :outcome_code,
                 outcome_summary = :outcome_summary,
                 error_summary = :error,
                 updated_at = :updated_at
             WHERE authority = :authority
               AND environment = :environment
               AND transaction_id = :transaction_id',
            [
                'state' => $state,
                'correlation_id' => $correlationId !== '' ? $correlationId : null,
                'structured_errors_json' => $structuredErrorsJson,
                'outcome_code' => trim($outcomeCode) !== '' ? trim($outcomeCode) : null,
                'outcome_summary' => trim($outcomeSummary) !== ''
                    ? trim($outcomeSummary)
                    : null,
                'error' => trim($error) !== '' ? trim($error) : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'authority' => $this->authority($authority),
                'environment' => $this->environment($environment),
                'transaction_id' => strtoupper(trim($transactionId)),
            ]
        );
        $this->refreshManifestForExchange(
            $authority,
            $environment,
            $transactionId,
            $state
        );
    }

    /**
     * @param list<array<string,mixed>> $structuredErrors
     */
    private function structuredErrorsJson(array $structuredErrors): string
    {
        if (!array_is_list($structuredErrors)) {
            throw new \InvalidArgumentException(
                'The final GovTalk structured errors must be a list.'
            );
        }
        $scalarFields = ['raised_by', 'number', 'type', 'source', 'scope'];
        $listFields = ['texts', 'locations'];
        foreach ($structuredErrors as $error) {
            if (!is_array($error) || array_is_list($error)) {
                throw new \InvalidArgumentException(
                    'Each final GovTalk structured error must be an object.'
                );
            }
            foreach ($scalarFields as $field) {
                if (array_key_exists($field, $error) && !is_string($error[$field])) {
                    throw new \InvalidArgumentException(
                        'The final GovTalk structured error field ' . $field
                            . ' must be a string.'
                    );
                }
            }
            foreach ($listFields as $field) {
                if (!array_key_exists($field, $error)) {
                    continue;
                }
                if (!is_array($error[$field]) || !array_is_list($error[$field])) {
                    throw new \InvalidArgumentException(
                        'The final GovTalk structured error field ' . $field
                            . ' must be a list.'
                    );
                }
                foreach ($error[$field] as $value) {
                    if (!is_string($value)) {
                        throw new \InvalidArgumentException(
                            'The final GovTalk structured error field ' . $field
                                . ' must contain only strings.'
                        );
                    }
                }
            }
        }

        return Utf8::json($structuredErrors, JSON_THROW_ON_ERROR);
    }

    public function markEvidenceIncomplete(
        string $authority,
        string $environment,
        string $transactionId,
        string $error
    ): void {
        $this->completeExchange(
            $authority,
            $environment,
            $transactionId,
            'evidence_incomplete',
            'evidence_incomplete',
            'Evidence incomplete',
            trim($error) ?: 'The exact GovTalk response evidence could not be archived.'
        );
    }

    /** @return array<string,mixed> */
    public function evidenceFileForCompany(
        int $companyId,
        int $exchangeId,
        string $direction
    ): array {
        $direction = strtolower(trim($direction));
        if ($companyId <= 0
            || $exchangeId <= 0
            || !in_array($direction, ['request', 'response'], true)) {
            throw new \InvalidArgumentException('Select valid GovTalk exchange evidence.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT e.*, a.company_id, a.archive_path
             FROM ' . self::EXCHANGES . ' e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.id = :exchange_id
               AND a.company_id = :company_id
             LIMIT 1',
            ['exchange_id' => $exchangeId, 'company_id' => $companyId]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The GovTalk evidence is not available for this company.');
        }
        $path = trim((string)($row[$direction . '_path'] ?? ''));
        $sha256 = strtolower(trim((string)($row[$direction . '_sha256'] ?? '')));
        $archivePath = trim((string)($row['archive_path'] ?? ''));
        if ($path === '' || $sha256 === '' || !is_file($path)
            || !$this->pathWithin($path, $archivePath)) {
            throw new \RuntimeException('The GovTalk evidence file is unavailable.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha256, strtolower($actual))) {
            throw new \RuntimeException('The GovTalk evidence file failed its integrity check.');
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'sha256' => $sha256,
            'authority' => (string)$row['authority'],
            'exchange_id' => (int)$row['id'],
            'direction' => $direction,
        ];
    }

    /** @return array<string,mixed>|null */
    private function exchange(
        string $authority,
        string $environment,
        string $transactionId
    ): ?array {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ' . self::EXCHANGES . '
             WHERE authority = :authority
               AND environment = :environment
               AND transaction_id = :transaction_id
             LIMIT 1',
            [
                'authority' => $this->authority($authority),
                'environment' => $this->environment($environment),
                'transaction_id' => strtoupper(trim($transactionId)),
            ]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed>|null $request
     * @param array<string,mixed>|null $response
     */
    private function upsertExchange(
        array $identity,
        string $transactionId,
        string $state,
        ?array $request,
        ?array $response,
        ?int $statusCode,
        string $outcomeCode,
        string $outcomeSummary,
        string $error
    ): void {
        $existing = $this->exchange(
            $identity['authority'],
            $identity['environment'],
            $transactionId
        );
        $now = gmdate('Y-m-d H:i:s');
        if (is_array($existing)) {
            foreach ([
                'authority',
                'environment',
                'operation',
                'submission_id',
                'preflight_id',
                'status_cycle_id',
                'hmrc_submission_id',
            ] as $field) {
                $expected = $identity[$field] ?? null;
                $actual = $existing[$field] ?? null;
                if ((string)($expected ?? '') !== (string)($actual ?? '')) {
                    throw new \RuntimeException(
                        'The GovTalk transaction ID is already bound to a different conversation.'
                    );
                }
            }
            $expectedArchiveId = (int)(
                $identity['transmission_archive_id']
                    ?? $request['archive_id']
                    ?? $existing['transmission_archive_id']
            );
            if ($expectedArchiveId !== (int)$existing['transmission_archive_id']) {
                throw new \RuntimeException(
                    'The GovTalk transaction ID is already bound to a different archive.'
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::EXCHANGES . '
                 SET exchange_state = :state,
                     endpoint = COALESCE(:endpoint, endpoint),
                     correlation_id = COALESCE(:correlation_id, correlation_id),
                     response_path = COALESCE(:response_path, response_path),
                     response_sha256 = COALESCE(:response_sha256, response_sha256),
                     response_bytes = COALESCE(:response_bytes, response_bytes),
                     response_status_code = COALESCE(:status_code, response_status_code),
                     response_headers_json = COALESCE(:headers_json, response_headers_json),
                     response_headers_sha256 = COALESCE(:headers_sha256, response_headers_sha256),
                     govtalk_errors_json = COALESCE(:govtalk_errors_json, govtalk_errors_json),
                     outcome_code = COALESCE(:outcome_code, outcome_code),
                     outcome_summary = COALESCE(:outcome_summary, outcome_summary),
                     error_summary = COALESCE(:error, error_summary),
                     received_at = COALESCE(:received_at, received_at),
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'state' => $state,
                    'endpoint' => trim((string)($request['endpoint'] ?? '')) ?: null,
                    'correlation_id' => trim((string)($request['correlation_id'] ?? '')) ?: null,
                    'response_path' => $response['path'] ?? null,
                    'response_sha256' => $response['sha256'] ?? null,
                    'response_bytes' => $response['bytes'] ?? null,
                    'status_code' => $statusCode,
                    'headers_json' => $response['headers_json'] ?? null,
                    'headers_sha256' => $response['headers_sha256'] ?? null,
                    'govtalk_errors_json' => $response['govtalk_errors_json'] ?? null,
                    'outcome_code' => trim($outcomeCode) ?: null,
                    'outcome_summary' => trim($outcomeSummary) ?: null,
                    'error' => trim($error) ?: null,
                    'received_at' => $response !== null ? $now : null,
                    'updated_at' => $now,
                    'id' => (int)$existing['id'],
                ]
            );
            return;
        }
        $archiveId = (int)($request['archive_id']
            ?? $identity['transmission_archive_id']
            ?? 0);
        if ($archiveId <= 0) {
            throw new \RuntimeException('The GovTalk exchange archive could not be identified.');
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::EXCHANGES . ' (
                authority, transmission_archive_id,
                submission_id, preflight_id, status_cycle_id, hmrc_submission_id,
                operation, request_message_class, request_qualifier, request_function,
                environment, endpoint, transaction_id, correlation_id, exchange_state,
                request_path, request_sha256, request_bytes,
                response_path, response_sha256, response_bytes,
                response_status_code, response_headers_json, response_headers_sha256,
                govtalk_errors_json, outcome_code, outcome_summary, error_summary,
                sent_at, received_at, created_at, updated_at
             ) VALUES (
                :authority, :archive_id,
                :submission_id, :preflight_id, :status_cycle_id, :hmrc_submission_id,
                :operation, :message_class, :qualifier, :request_function,
                :environment, :endpoint, :transaction_id, :correlation_id, :state,
                :request_path, :request_sha256, :request_bytes,
                :response_path, :response_sha256, :response_bytes,
                :status_code, :headers_json, :headers_sha256,
                :govtalk_errors_json, :outcome_code, :outcome_summary, :error,
                :sent_at, :received_at, :created_at, :updated_at
             )',
            [
                'authority' => $identity['authority'],
                'archive_id' => $archiveId,
                'submission_id' => $identity['submission_id'],
                'preflight_id' => $identity['preflight_id'],
                'status_cycle_id' => $identity['status_cycle_id'],
                'hmrc_submission_id' => $identity['hmrc_submission_id'],
                'operation' => $identity['operation'],
                'message_class' => trim((string)($request['message_class'] ?? '')) ?: null,
                'qualifier' => trim((string)($request['qualifier'] ?? '')) ?: null,
                'request_function' => trim((string)($request['function'] ?? '')) ?: null,
                'environment' => $identity['environment'],
                'endpoint' => trim((string)($request['endpoint'] ?? '')) ?: null,
                'transaction_id' => $transactionId,
                'correlation_id' => trim((string)($request['correlation_id'] ?? '')) ?: null,
                'state' => $state,
                'request_path' => $request['path'] ?? null,
                'request_sha256' => $request['sha256'] ?? null,
                'request_bytes' => $request['bytes'] ?? null,
                'response_path' => $response['path'] ?? null,
                'response_sha256' => $response['sha256'] ?? null,
                'response_bytes' => $response['bytes'] ?? null,
                'status_code' => $statusCode,
                'headers_json' => $response['headers_json'] ?? null,
                'headers_sha256' => $response['headers_sha256'] ?? null,
                'govtalk_errors_json' => $response['govtalk_errors_json'] ?? null,
                'outcome_code' => trim($outcomeCode) ?: null,
                'outcome_summary' => trim($outcomeSummary) ?: null,
                'error' => trim($error) ?: null,
                'sent_at' => null,
                'received_at' => $response !== null ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /** @param array<string,mixed> $identity */
    private function refreshManifest(array $identity, string $fallbackLifecycle): void
    {
        $this->archives()->refreshManifest(
            $identity['company_id'],
            $identity['accounting_period_id'],
            $identity['authority'],
            $identity['environment'],
            $identity['archive_reference'],
            trim($identity['lifecycle']) ?: $fallbackLifecycle
        );
    }

    private function refreshManifestForExchange(
        string $authority,
        string $environment,
        string $transactionId,
        string $fallbackLifecycle
    ): void {
        $row = \InterfaceDB::fetchOne(
            'SELECT a.company_id, a.accounting_period_id, a.authority,
                    a.environment, a.submission_reference, a.lifecycle
             FROM ' . self::EXCHANGES . ' e
             INNER JOIN transmission_archives a ON a.id = e.transmission_archive_id
             WHERE e.authority = :authority
               AND e.environment = :environment
               AND e.transaction_id = :transaction_id
             LIMIT 1',
            [
                'authority' => $this->authority($authority),
                'environment' => $this->environment($environment),
                'transaction_id' => strtoupper(trim($transactionId)),
            ]
        );
        if (!is_array($row)) {
            return;
        }
        $this->archives()->refreshManifest(
            (int)$row['company_id'],
            (int)$row['accounting_period_id'],
            (string)$row['authority'],
            (string)$row['environment'],
            (string)$row['submission_reference'],
            trim((string)$row['lifecycle']) ?: $fallbackLifecycle
        );
    }

    /** @param array<string,mixed> $identity */
    private function identity(array $identity): array
    {
        $authority = $this->authority((string)($identity['authority'] ?? ''));
        $environment = $this->environment((string)($identity['environment'] ?? ''));
        $companyId = (int)($identity['company_id'] ?? 0);
        $accountingPeriodId = (int)($identity['accounting_period_id'] ?? 0);
        $archiveReference = trim((string)($identity['archive_reference'] ?? ''));
        $operation = strtolower(trim((string)($identity['operation'] ?? '')));
        if ($companyId <= 0 || $accountingPeriodId <= 0
            || $archiveReference === '' || $operation === '') {
            throw new \InvalidArgumentException('The GovTalk conversation identity is incomplete.');
        }
        $submissionId = (int)($identity['submission_id'] ?? 0) ?: null;
        $preflightId = (int)($identity['preflight_id'] ?? 0) ?: null;
        $statusCycleId = (int)($identity['status_cycle_id'] ?? 0) ?: null;
        $hmrcSubmissionId = (int)($identity['hmrc_submission_id'] ?? 0) ?: null;
        if ($authority === 'companies_house'
            && $submissionId === null && $preflightId === null) {
            throw new \InvalidArgumentException(
                'A Companies House GovTalk conversation requires a submission or authentication check.'
            );
        }
        if ($authority === 'hmrc' && $hmrcSubmissionId === null) {
            throw new \InvalidArgumentException(
                'An HMRC GovTalk conversation requires an HMRC submission.'
            );
        }
        if ($authority === 'companies_house'
            && ($hmrcSubmissionId !== null || $environment === 'TIL')) {
            throw new \InvalidArgumentException(
                'The Companies House GovTalk conversation has incompatible authority metadata.'
            );
        }
        if ($authority === 'hmrc'
            && ($submissionId !== null || $preflightId !== null || $statusCycleId !== null)) {
            throw new \InvalidArgumentException(
                'The HMRC GovTalk conversation has incompatible Companies House metadata.'
            );
        }
        if ($statusCycleId !== null && $submissionId === null) {
            throw new \InvalidArgumentException(
                'A Companies House status cycle requires its submission.'
            );
        }

        return array_replace($identity, [
            'authority' => $authority,
            'environment' => $environment,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'archive_reference' => $archiveReference,
            'operation' => $operation,
            'lifecycle' => trim((string)($identity['lifecycle'] ?? '')) ?: 'unknown',
            'submission_id' => $submissionId,
            'preflight_id' => $preflightId,
            'status_cycle_id' => $statusCycleId,
            'hmrc_submission_id' => $hmrcSubmissionId,
        ]);
    }

    private function filename(
        string $configured,
        string $operation,
        string $transactionId,
        string $direction
    ): string {
        $configured = trim($configured);
        if ($configured !== '') {
            return $configured;
        }
        $operation = preg_replace('/[^a-z0-9-]+/', '-', strtolower($operation))
            ?: 'exchange';
        $transactionId = preg_replace('/[^a-z0-9]+/', '', strtolower($transactionId))
            ?: 'unknown';

        return $operation . '-' . $transactionId . '-' . $direction . '.xml';
    }

    private function authority(string $authority): string
    {
        $authority = strtolower(trim($authority));
        if (!in_array($authority, ['companies_house', 'hmrc'], true)) {
            throw new \InvalidArgumentException('The GovTalk authority is invalid.');
        }

        return $authority;
    }

    private function environment(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, ['TEST', 'TIL', 'LIVE'], true)) {
            throw new \InvalidArgumentException('The GovTalk environment is invalid.');
        }

        return $environment;
    }

    /** @param array<string,mixed> $result */
    private function outcomeCode(array $result): string
    {
        return strtolower(trim((string)(
            $result['outcome_code']
                ?? $result['business_outcome']
                ?? $result['normalized_status']
                ?? $result['outcome']
                ?? (!empty($result['success']) ? 'succeeded' : 'failed')
        )));
    }

    /** @param array<string,mixed> $result */
    private function outcomeSummary(array $result): string
    {
        return trim((string)(
            $result['outcome_summary']
                ?? $result['message']
                ?? $result['error']
                ?? ''
        ));
    }

    private function requireSchema(): void
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException(
                'Run the shared GovTalk exchange-ledger migration before transmitting.'
            );
        }
    }

    private function archives(): TransmissionArchiveService
    {
        return $this->archiveService ?? new TransmissionArchiveService();
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
