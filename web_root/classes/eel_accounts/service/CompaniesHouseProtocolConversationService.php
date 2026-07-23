<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseProtocolConversationService
{
    private const PREFLIGHTS = 'companies_house_company_auth_preflights';
    private const EXCHANGES = 'companies_house_protocol_exchanges';
    private const STATUS_CYCLES = 'companies_house_accounts_status_cycles';
    private const SUBMISSIONS = 'companies_house_accounts_submissions';
    private const BINDING_TAG = 'PREFLIGHT_BINDING_HMAC_KEY';
    private const BINDING_SECONDS = 1800;

    public function __construct(
        private readonly ?TransmissionArchiveService $archiveService = null,
        private readonly ?string $bindingKey = null
    ) {
    }

    public function schemaReady(): bool
    {
        return \InterfaceDB::tableExists(self::PREFLIGHTS)
            && \InterfaceDB::tableExists(self::EXCHANGES)
            && \InterfaceDB::tableExists(self::STATUS_CYCLES)
            && \InterfaceDB::columnExists(self::SUBMISSIONS, 'preflight_id')
            && \InterfaceDB::columnExists(self::SUBMISSIONS, 'pending_status_cycle_id');
    }

    public function bindingConfigured(string $environment): bool
    {
        try {
            return $this->hmacKey($environment) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    public function beginPreflight(
        array $submission,
        string $environment,
        int $schemaSnapshotId,
        string $schemaManifestSha256,
        string $outputPresenterFingerprint,
        string $companyAuthenticationCode,
        string $actor,
        bool $developerStep
    ): array {
        if (!$this->schemaReady()) {
            throw new \RuntimeException(
                'Run the Companies House protocol-conversation migration before filing.'
            );
        }
        $submissionId = (int)($submission['id'] ?? 0);
        $companyId = (int)($submission['company_id'] ?? 0);
        $accountingPeriodId = (int)($submission['accounting_period_id'] ?? 0);
        $companyNumber = strtoupper(trim((string)($submission['company_number'] ?? '')));
        if ($submissionId <= 0 || $companyId <= 0 || $accountingPeriodId <= 0 || $companyNumber === '') {
            throw new \InvalidArgumentException('A complete Companies House submission is required for preflight.');
        }
        $token = 'pending-' . bin2hex(random_bytes(12));
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = $developerStep ? gmdate('Y-m-d H:i:s', time() + self::BINDING_SECONDS) : null;
        $bindingHmac = $developerStep
            ? $this->bindingHmac(
                $submissionId,
                $environment,
                $companyNumber,
                $companyAuthenticationCode,
                $this->hmacKey($environment)
            )
            : null;
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::PREFLIGHTS . ' (
                submission_id, company_id, accounting_period_id, environment,
                output_presenter_fingerprint, schema_snapshot_id, schema_manifest_sha256,
                outcome, binding_hmac, binding_actor, binding_expires_at,
                archive_reference, created_at, updated_at
             ) VALUES (
                :submission_id, :company_id, :accounting_period_id, :environment,
                :fingerprint, :snapshot_id, :manifest,
                :outcome, :binding_hmac, :binding_actor, :binding_expires_at,
                :archive_reference, :created_at, :updated_at
             )',
            [
                'submission_id' => $submissionId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'environment' => strtoupper($environment),
                'fingerprint' => strtolower($outputPresenterFingerprint),
                'snapshot_id' => $schemaSnapshotId,
                'manifest' => strtolower($schemaManifestSha256),
                'outcome' => 'sending',
                'binding_hmac' => $bindingHmac,
                'binding_actor' => $developerStep ? $actor : null,
                'binding_expires_at' => $expiresAt,
                'archive_reference' => $token,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::PREFLIGHTS . ' WHERE archive_reference = :reference LIMIT 1',
            ['reference' => $token]
        );
        if (!is_array($row)) {
            throw new \RuntimeException('The Companies House preflight record could not be created.');
        }
        $reference = 'preflight-' . (int)$row['id'];
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::PREFLIGHTS . ' SET archive_reference = :reference WHERE id = :id',
            ['reference' => $reference, 'id' => (int)$row['id']]
        );
        $row['archive_reference'] = $reference;

        return $row;
    }

    public function captureRequest(
        array $submission,
        string $environment,
        string $archiveReference,
        string $operation,
        array $request,
        ?int $preflightId = null,
        ?int $statusCycleId = null
    ): array {
        $transactionId = strtolower(trim((string)($request['transaction_id'] ?? '')));
        $filename = $this->filename($operation, $transactionId, 'request');
        $stored = $this->archives()->store(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'companies_house',
            $environment,
            $archiveReference,
            'sending',
            $filename,
            (string)$request['request_xml']
        );
        $this->upsertExchange(
            (int)$submission['id'],
            $preflightId,
            $statusCycleId,
            $operation,
            $environment,
            (string)$request['transaction_id'],
            'sent',
            $stored,
            null,
            null,
            ''
        );
        if ($preflightId !== null) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::PREFLIGHTS . '
                 SET transaction_id = :transaction_id, request_path = :path,
                     request_sha256 = :sha256, updated_at = :updated_at WHERE id = :id',
                [
                    'transaction_id' => (string)$request['transaction_id'],
                    'path' => $stored['path'],
                    'sha256' => $stored['sha256'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => $preflightId,
                ]
            );
        }

        return $stored;
    }

    public function captureResponse(
        array $submission,
        string $environment,
        string $archiveReference,
        string $operation,
        array $response,
        ?int $preflightId = null,
        ?int $statusCycleId = null
    ): array {
        $transactionId = strtolower(trim((string)($response['transaction_id'] ?? '')));
        $filename = $this->filename($operation, $transactionId, 'response');
        $stored = $this->archives()->store(
            (int)$submission['company_id'],
            (int)$submission['accounting_period_id'],
            'companies_house',
            $environment,
            $archiveReference,
            'received',
            $filename,
            (string)$response['response_xml']
        );
        $this->upsertExchange(
            (int)$submission['id'],
            $preflightId,
            $statusCycleId,
            $operation,
            $environment,
            (string)$response['transaction_id'],
            'received',
            null,
            $stored,
            (int)($response['status_code'] ?? 0),
            ''
        );
        if ($preflightId !== null) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::PREFLIGHTS . '
                 SET response_path = :path, response_sha256 = :sha256,
                     updated_at = :updated_at WHERE id = :id',
                [
                    'path' => $stored['path'],
                    'sha256' => $stored['sha256'],
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => $preflightId,
                ]
            );
        }

        return $stored;
    }

    public function finishPreflight(int $preflightId, array $result): void
    {
        $success = !empty($result['success']) && !empty($result['authenticated']);
        $outcome = $success
            ? 'verified'
            : (!empty($result['transport_unknown']) ? 'transport_unknown' : 'rejected');
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::PREFLIGHTS . '
             SET outcome = :outcome, matched_company_number = :company_number,
                 matched_company_name = :company_name, error_summary = :error,
                 checked_at = :checked_at, updated_at = :updated_at
             WHERE id = :id',
            [
                'outcome' => $outcome,
                'company_number' => trim((string)($result['company_number'] ?? '')) ?: null,
                'company_name' => mb_substr(trim((string)($result['company_name'] ?? '')), 0, 160) ?: null,
                'error' => trim((string)($result['error'] ?? '')) ?: null,
                'checked_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $preflightId,
            ]
        );
        $this->completeExchange(
            (string)($result['environment'] ?? ''),
            (string)($result['transaction_id'] ?? ''),
            $success ? 'succeeded' : ($outcome === 'transport_unknown' ? 'transport_unknown' : 'rejected'),
            (string)($result['error'] ?? '')
        );
    }

    public function consumePreflight(
        int $preflightId,
        array $submission,
        string $companyAuthenticationCode,
        string $actor,
        bool $developerStep
    ): void {
        \InterfaceDB::transaction(function () use (
            $preflightId,
            $submission,
            $companyAuthenticationCode,
            $actor,
            $developerStep
        ): void {
            $lock = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
            $row = \InterfaceDB::fetchOne(
                'SELECT * FROM ' . self::PREFLIGHTS . ' WHERE id = :id' . $lock,
                ['id' => $preflightId]
            );
            if (!is_array($row)
                || (int)$row['submission_id'] !== (int)$submission['id']
                || (string)$row['outcome'] !== 'verified'
                || $row['consumed_at'] !== null) {
                throw new \RuntimeException('A current successful CompanyData preflight is required.');
            }
            if ($developerStep) {
                if ((string)$row['binding_actor'] !== $actor
                    || $this->utcTimestamp((string)$row['binding_expires_at']) < time()) {
                    throw new \RuntimeException('The developer CompanyData preflight has expired.');
                }
                $expected = $this->bindingHmac(
                    (int)$submission['id'],
                    (string)$submission['environment'],
                    (string)$submission['company_number'],
                    $companyAuthenticationCode,
                    $this->hmacKey((string)$submission['environment'])
                );
                if (!hash_equals((string)$row['binding_hmac'], $expected)) {
                    throw new \RuntimeException(
                        'The company authentication code does not match the successful preflight.'
                    );
                }
            }
            $now = gmdate('Y-m-d H:i:s');
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::PREFLIGHTS . '
                 SET consumed_at = :consumed, binding_hmac = NULL,
                     binding_expires_at = NULL, updated_at = :updated WHERE id = :id',
                ['consumed' => $now, 'updated' => $now, 'id' => $preflightId]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS . ' SET preflight_id = :preflight_id, updated_at = :updated WHERE id = :id',
                ['preflight_id' => $preflightId, 'updated' => $now, 'id' => (int)$submission['id']]
            );
        });
    }

    public function latestPreflight(int $submissionId): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::PREFLIGHTS . '
             WHERE submission_id = :submission_id ORDER BY id DESC LIMIT 1',
            ['submission_id' => $submissionId]
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function exchanges(int $submissionId): array
    {
        if (!$this->schemaReady() || $submissionId <= 0) {
            return [];
        }
        return \InterfaceDB::fetchAll(
            'SELECT * FROM ' . self::EXCHANGES . '
             WHERE submission_id = :submission_id ORDER BY id ASC',
            ['submission_id' => $submissionId]
        );
    }

    public function evidenceFile(int $submissionId, int $exchangeId, string $direction): array
    {
        if (!$this->schemaReady() || $submissionId <= 0 || $exchangeId <= 0) {
            throw new \RuntimeException('The Companies House protocol evidence is unavailable.');
        }
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['request', 'response'], true)) {
            throw new \InvalidArgumentException('Choose request or response evidence.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT operation, ' . $direction . '_path AS artifact_path,
                    ' . $direction . '_sha256 AS artifact_sha256
             FROM ' . self::EXCHANGES . '
             WHERE id = :id AND submission_id = :submission_id LIMIT 1',
            ['id' => $exchangeId, 'submission_id' => $submissionId]
        );
        $path = is_array($row) ? (string)($row['artifact_path'] ?? '') : '';
        $sha256 = is_array($row) ? strtolower((string)($row['artifact_sha256'] ?? '')) : '';
        if ($path === '' || !is_file($path) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \RuntimeException('The Companies House protocol evidence file is missing.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha256, strtolower($actual))) {
            throw new \RuntimeException('The Companies House protocol evidence hash does not match.');
        }

        return [
            'path' => $path,
            'sha256' => $sha256,
            'filename' => basename($path),
            'operation' => (string)$row['operation'],
            'direction' => $direction,
        ];
    }

    public function createStatusCycle(int $submissionId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::STATUS_CYCLES . ' (
                submission_id, acknowledgement_state, created_at, updated_at
             ) VALUES (:submission_id, :ack_state, :created_at, :updated_at)',
            [
                'submission_id' => $submissionId,
                'ack_state' => 'not_requested',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $row = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::STATUS_CYCLES . '
             WHERE submission_id = :submission_id ORDER BY id DESC LIMIT 1',
            ['submission_id' => $submissionId]
        );
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('The Companies House status cycle could not be created.');
        }
        return $id;
    }

    public function statusCycle(int $cycleId): ?array
    {
        if (!$this->schemaReady() || $cycleId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::STATUS_CYCLES . ' WHERE id = :id LIMIT 1',
            ['id' => $cycleId]
        );
        return is_array($row) ? $row : null;
    }

    public function latestStatusCycle(int $submissionId): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::STATUS_CYCLES . '
             WHERE submission_id = :submission_id ORDER BY id DESC LIMIT 1',
            ['submission_id' => $submissionId]
        );
        return is_array($row) ? $row : null;
    }

    public function updateStatusCycle(int $cycleId, array $values): void
    {
        $allowed = [
            'poll_transaction_id', 'raw_status', 'normalized_status', 'result_json',
            'acknowledgement_state', 'acknowledgement_transaction_id',
            'polled_at', 'acknowledged_at',
        ];
        $sets = [];
        $params = ['id' => $cycleId, 'updated_at' => gmdate('Y-m-d H:i:s')];
        foreach ($allowed as $column) {
            if (array_key_exists($column, $values)) {
                $sets[] = $column . ' = :' . $column;
                $params[$column] = $values[$column];
            }
        }
        if ($sets === []) {
            return;
        }
        $sets[] = 'updated_at = :updated_at';
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::STATUS_CYCLES . ' SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }

    public function completeExchange(
        string $environment,
        string $transactionId,
        string $state,
        string $error = ''
    ): void {
        if (!$this->schemaReady() || trim($transactionId) === '') {
            return;
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::EXCHANGES . '
             SET exchange_state = :state, error_summary = :error, updated_at = :updated_at
             WHERE environment = :environment AND transaction_id = :transaction_id',
            [
                'state' => $state,
                'error' => trim($error) !== '' ? trim($error) : null,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'environment' => strtoupper($environment),
                'transaction_id' => strtoupper($transactionId),
            ]
        );
    }

    private function upsertExchange(
        int $submissionId,
        ?int $preflightId,
        ?int $statusCycleId,
        string $operation,
        string $environment,
        string $transactionId,
        string $state,
        ?array $request,
        ?array $response,
        ?int $statusCode,
        string $error
    ): void {
        $transactionId = strtoupper(trim($transactionId));
        $operation = str_replace('-', '_', strtolower(trim($operation)));
        $row = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::EXCHANGES . '
             WHERE environment = :environment AND transaction_id = :transaction_id',
            ['environment' => strtoupper($environment), 'transaction_id' => $transactionId]
        );
        $now = gmdate('Y-m-d H:i:s');
        if (is_array($row)) {
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::EXCHANGES . '
                 SET exchange_state = :state,
                     response_path = COALESCE(:response_path, response_path),
                     response_sha256 = COALESCE(:response_sha256, response_sha256),
                     response_status_code = COALESCE(:status_code, response_status_code),
                     received_at = COALESCE(:received_at, received_at),
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'state' => $state,
                    'response_path' => $response['path'] ?? null,
                    'response_sha256' => $response['sha256'] ?? null,
                    'status_code' => $statusCode,
                    'received_at' => $response !== null ? $now : null,
                    'updated_at' => $now,
                    'id' => (int)$row['id'],
                ]
            );
            return;
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::EXCHANGES . ' (
                submission_id, preflight_id, status_cycle_id, operation, environment,
                transaction_id, exchange_state, request_path, request_sha256,
                response_path, response_sha256, response_status_code, error_summary,
                sent_at, received_at, created_at, updated_at
             ) VALUES (
                :submission_id, :preflight_id, :status_cycle_id, :operation, :environment,
                :transaction_id, :state, :request_path, :request_sha256,
                :response_path, :response_sha256, :status_code, :error,
                :sent_at, :received_at, :created_at, :updated_at
             )',
            [
                'submission_id' => $submissionId,
                'preflight_id' => $preflightId,
                'status_cycle_id' => $statusCycleId,
                'operation' => $operation,
                'environment' => strtoupper($environment),
                'transaction_id' => $transactionId,
                'state' => $state,
                'request_path' => $request['path'] ?? null,
                'request_sha256' => $request['sha256'] ?? null,
                'response_path' => $response['path'] ?? null,
                'response_sha256' => $response['sha256'] ?? null,
                'status_code' => $statusCode,
                'error' => trim($error) !== '' ? trim($error) : null,
                'sent_at' => $request !== null ? $now : null,
                'received_at' => $response !== null ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function bindingHmac(
        int $submissionId,
        string $environment,
        string $companyNumber,
        string $code,
        string $key
    ): string {
        return hash_hmac(
            'sha256',
            implode('|', [
                $submissionId,
                strtoupper($environment),
                strtoupper(trim($companyNumber)),
                $code,
            ]),
            $key
        );
    }

    private function hmacKey(string $environment): string
    {
        if ($this->bindingKey !== null && strlen($this->bindingKey) >= 32) {
            return $this->bindingKey;
        }
        $credential = \SecurityStore::loadCredential(
            'COMPANIESHOUSE',
            'XML',
            self::BINDING_TAG,
            strtoupper($environment)
        );
        $key = trim((string)($credential['api_key'] ?? ''));
        if (strlen($key) < 32) {
            throw new \RuntimeException(
                'Configure a random Companies House preflight binding key of at least 32 characters.'
            );
        }
        return $key;
    }

    private function archives(): TransmissionArchiveService
    {
        return $this->archiveService ?? new TransmissionArchiveService();
    }

    private function filename(string $operation, string $transactionId, string $direction): string
    {
        $operation = preg_replace('/[^a-z0-9-]+/', '-', strtolower($operation)) ?: 'exchange';
        $transactionId = preg_replace('/[^a-z0-9]+/', '', strtolower($transactionId)) ?: 'unknown';
        return $operation . '-' . $transactionId . '-' . $direction . '.xml';
    }

    private function utcTimestamp(string $value): int
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
