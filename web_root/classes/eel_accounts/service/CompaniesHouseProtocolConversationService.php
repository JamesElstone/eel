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
    private const EXCHANGES = 'govtalk_protocol_exchanges';
    private const STATUS_CYCLES = 'companies_house_accounts_status_cycles';
    private const SUBMISSIONS = 'companies_house_accounts_submissions';
    private const BINDING_FACT_PREFIX = 'companies_house_preflight_binding_hmac_';
    private const BINDING_SECONDS = 1800;

    public function __construct(
        private readonly ?TransmissionArchiveService $archiveService = null,
        private readonly ?string $bindingKey = null,
        private readonly ?GovTalkProtocolConversationService $govTalkConversation = null
    ) {
    }

    public function schemaReady(): bool
    {
        return \InterfaceDB::tableExists(self::PREFLIGHTS)
            && \InterfaceDB::tableExists(self::EXCHANGES)
            && \InterfaceDB::tableExists(self::STATUS_CYCLES)
            && \InterfaceDB::columnExists(self::EXCHANGES, 'request_message_class')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'response_headers_json')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'response_headers_sha256')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'govtalk_errors_json')
            && \InterfaceDB::columnExists(self::EXCHANGES, 'transmission_archive_id')
            && \InterfaceDB::columnExists(self::SUBMISSIONS, 'preflight_id')
            && \InterfaceDB::columnExists(self::SUBMISSIONS, 'pending_status_cycle_id');
    }

    public function bindingConfigured(string $environment): bool
    {
        return ($this->bindingKey !== null && strlen($this->bindingKey) >= 32)
            || in_array(strtoupper(trim($environment)), ['TEST', 'LIVE'], true);
    }

    public function beginPreflight(
        array $context,
        string $environment,
        string $outputPresenterFingerprint,
        string $companyAuthenticationCode,
        string $actor,
        bool $developerStep
    ): array {
        return $this->beginAuthenticationCheck(
            $context,
            $environment,
            $outputPresenterFingerprint,
            $companyAuthenticationCode,
            $actor,
            $developerStep
        );
    }

    public function beginAuthenticationCheck(
        array $context,
        string $environment,
        string $outputPresenterFingerprint,
        string $companyAuthenticationCode,
        string $actor,
        bool $reusable
    ): array {
        if (!$this->schemaReady()) {
            throw new \RuntimeException(
                'Run the Companies House protocol-conversation migration before filing.'
            );
        }
        $companyId = (int)($context['company_id'] ?? 0);
        $accountingPeriodId = (int)($context['accounting_period_id'] ?? 0);
        $companyNumber = strtoupper(trim((string)($context['company_number'] ?? '')));
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $companyNumber === '') {
            throw new \InvalidArgumentException(
                'A company context is required for the authentication check.'
            );
        }
        $archiveReference = TransmissionArchiveService::companiesHouseAuthenticationCheckReference();
        $now = gmdate('Y-m-d H:i:s');
        $expiresAt = $reusable ? gmdate('Y-m-d H:i:s', time() + self::BINDING_SECONDS) : null;
        $bindingHmac = $reusable
            ? $this->bindingHmac(
                $companyId,
                $environment,
                $companyNumber,
                $companyAuthenticationCode,
                $this->hmacKey($environment)
            )
            : null;
        \InterfaceDB::prepareExecute(
            'INSERT INTO ' . self::PREFLIGHTS . ' (
                submission_id, company_id, accounting_period_id, environment,
                output_presenter_fingerprint,
                outcome, binding_hmac, binding_actor, binding_expires_at,
                archive_reference, created_at, updated_at
             ) VALUES (
                :submission_id, :company_id, :accounting_period_id, :environment,
                :fingerprint,
                :outcome, :binding_hmac, :binding_actor, :binding_expires_at,
                :archive_reference, :created_at, :updated_at
             )',
            [
                'submission_id' => null,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'environment' => strtoupper($environment),
                'fingerprint' => strtolower($outputPresenterFingerprint),
                'outcome' => 'sending',
                'binding_hmac' => $bindingHmac,
                'binding_actor' => $reusable ? $actor : null,
                'binding_expires_at' => $expiresAt,
                'archive_reference' => $archiveReference,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::PREFLIGHTS . '
             WHERE archive_reference = :archive_reference
             ORDER BY id DESC LIMIT 1',
            ['archive_reference' => $archiveReference]
        );
        if (!is_array($row)) {
            throw new \RuntimeException(
                'The Companies House authentication-check record could not be created.'
            );
        }

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
        $submissionId = (int)($submission['id'] ?? 0);
        $stored = $this->govTalk()->captureRequest(
            $this->govTalkIdentity(
                $submission,
                $environment,
                $archiveReference,
                $operation,
                $submissionId > 0 ? $submissionId : null,
                $preflightId,
                $statusCycleId,
                'sending'
            ),
            $request
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

    public function markSendStarted(string $environment, string $transactionId): void
    {
        $this->govTalk()->markSendStarted('companies_house', $environment, $transactionId);
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
        $submissionId = (int)($submission['id'] ?? 0);
        $stored = $this->govTalk()->captureResponse(
            $this->govTalkIdentity(
                $submission,
                $environment,
                $archiveReference,
                $operation,
                $submissionId > 0 ? $submissionId : null,
                $preflightId,
                $statusCycleId,
                'received'
            ),
            $response
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
        $presenterAuthorisationFailed = $this->hasGovTalkError($result, '502');
        if ($success) {
            $outcome = 'verified';
        } elseif ($presenterAuthorisationFailed) {
            $outcome = 'presenter_authorisation_failed';
        } elseif (!empty($result['transport_unknown']) || !empty($result['evidence_incomplete'])) {
            $outcome = 'transport_unknown';
        } else {
            $outcome = 'rejected';
        }
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
        if (!empty($result['evidence_incomplete'])) {
            $this->markEvidenceIncomplete(
                (string)($result['environment'] ?? ''),
                (string)($result['transaction_id'] ?? ''),
                (string)($result['error'] ?? '')
            );
        } else {
            $this->completeExchange(
                (string)($result['environment'] ?? ''),
                (string)($result['transaction_id'] ?? ''),
                $success
                    ? 'succeeded'
                    : ($outcome === 'transport_unknown'
                        ? 'transport_unknown'
                        : ($presenterAuthorisationFailed ? 'failed' : 'rejected')),
                (string)($result['error'] ?? '')
            );
        }
    }

    public function companyDataCapability(string $environment, string $presenterFingerprint): string
    {
        if (!$this->schemaReady()) {
            return 'unknown';
        }
        $environment = strtoupper(trim($environment));
        $presenterFingerprint = strtolower(trim($presenterFingerprint));
        if (!in_array($environment, ['TEST', 'LIVE'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $presenterFingerprint) !== 1) {
            return 'unknown';
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT id FROM ' . self::PREFLIGHTS . '
             WHERE environment = :environment
               AND output_presenter_fingerprint = :fingerprint
               AND outcome = :outcome
             ORDER BY checked_at DESC, id DESC
             LIMIT 1',
            [
                'environment' => $environment,
                'fingerprint' => $presenterFingerprint,
                'outcome' => 'verified',
            ]
        );

        return is_array($row) ? 'available' : 'unknown';
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
                || (int)$row['company_id'] !== (int)$submission['company_id']
                || (int)$row['accounting_period_id'] !== (int)$submission['accounting_period_id']
                || strtoupper((string)$row['environment']) !== strtoupper((string)$submission['environment'])
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
                    (int)$submission['company_id'],
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

    public function latestAuthenticationCheck(
        int $companyId,
        int $accountingPeriodId,
        string $environment
    ): ?array {
        if (!$this->schemaReady() || $companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::PREFLIGHTS . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND environment = :environment
             ORDER BY id DESC LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'environment' => strtoupper(trim($environment)),
            ]
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
             WHERE authority = :authority
               AND submission_id = :submission_id ORDER BY id ASC',
            ['authority' => 'companies_house', 'submission_id' => $submissionId]
        );
    }

    public function hasUnresolvedExchange(int $submissionId, string $operation): bool
    {
        if (!$this->schemaReady() || $submissionId <= 0) {
            return false;
        }
        $operation = str_replace('-', '_', strtolower(trim($operation)));

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM ' . self::EXCHANGES . '
             WHERE authority = :authority
               AND submission_id = :submission_id
               AND operation = :operation
               AND exchange_state IN (:transport_unknown, :evidence_incomplete)',
            [
                'authority' => 'companies_house',
                'submission_id' => $submissionId,
                'operation' => $operation,
                'transport_unknown' => 'transport_unknown',
                'evidence_incomplete' => 'evidence_incomplete',
            ]
        ) > 0;
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
             WHERE id = :id
               AND authority = :authority
               AND submission_id = :submission_id LIMIT 1',
            [
                'id' => $exchangeId,
                'authority' => 'companies_house',
                'submission_id' => $submissionId,
            ]
        );
        return $this->evidenceFromRow($row, $direction);
    }

    public function evidenceFileForContext(
        int $companyId,
        int $accountingPeriodId,
        int $exchangeId,
        string $direction
    ): array {
        if (!$this->schemaReady()
            || $companyId <= 0
            || $accountingPeriodId <= 0
            || $exchangeId <= 0) {
            throw new \RuntimeException('The Companies House protocol evidence is unavailable.');
        }
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['request', 'response'], true)) {
            throw new \InvalidArgumentException('Choose request or response evidence.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT e.operation,
                    e.' . $direction . '_path AS artifact_path,
                    e.' . $direction . '_sha256 AS artifact_sha256
             FROM ' . self::EXCHANGES . ' e
             LEFT JOIN ' . self::SUBMISSIONS . ' s ON s.id = e.submission_id
             LEFT JOIN ' . self::PREFLIGHTS . ' p ON p.id = e.preflight_id
             WHERE e.id = :exchange_id
               AND e.authority = :authority
               AND (
                   (s.company_id = :submission_company_id
                    AND s.accounting_period_id = :submission_accounting_period_id)
                   OR
                   (p.company_id = :preflight_company_id
                    AND p.accounting_period_id = :preflight_accounting_period_id)
               )
             LIMIT 1',
            [
                'exchange_id' => $exchangeId,
                'authority' => 'companies_house',
                'submission_company_id' => $companyId,
                'submission_accounting_period_id' => $accountingPeriodId,
                'preflight_company_id' => $companyId,
                'preflight_accounting_period_id' => $accountingPeriodId,
            ]
        );

        return $this->evidenceFromRow($row, $direction);
    }

    private function evidenceFromRow(mixed $row, string $direction): array
    {
        $path = is_array($row) ? (string)($row['artifact_path'] ?? '') : '';
        $sha256 = is_array($row) ? strtolower((string)($row['artifact_sha256'] ?? '')) : '';
        if ($path === '' || !is_file($path) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \RuntimeException('The Companies House protocol evidence file is missing.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($sha256, strtolower($actual))) {
            throw new \RuntimeException('The Companies House protocol evidence hash does not match.');
        }
        $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
        $uploadRoot = realpath(trim((string)($uploads['upload_base_dir'] ?? '')));
        $resolvedPath = realpath($path);
        if (!is_string($uploadRoot)
            || !is_string($resolvedPath)
            || !$this->pathWithin($resolvedPath, $uploadRoot)) {
            throw new \RuntimeException('The Companies House protocol evidence path is outside private storage.');
        }

        return [
            'path' => $resolvedPath,
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
        $this->govTalk()->completeExchange(
            'companies_house',
            $environment,
            $transactionId,
            $state,
            $state,
            '',
            $error
        );
    }

    public function markEvidenceIncomplete(
        string $environment,
        string $transactionId,
        string $error
    ): void {
        $this->govTalk()->markEvidenceIncomplete(
            'companies_house',
            $environment,
            $transactionId,
            trim($error) !== ''
                ? trim($error)
                : 'The exact Companies House response could not be archived.'
        );
    }

    private function hasGovTalkError(array $result, string $number): bool
    {
        foreach ((array)($result['gateway_errors'] ?? []) as $error) {
            if (is_array($error) && trim((string)($error['number'] ?? '')) === $number) {
                return true;
            }
        }

        return false;
    }

    private function bindingHmac(
        int $companyId,
        string $environment,
        string $companyNumber,
        string $code,
        string $key
    ): string {
        return hash_hmac(
            'sha256',
            implode('|', [
                $companyId,
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
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            throw new \InvalidArgumentException('Companies House preflight environment must be TEST or LIVE.');
        }
        $key = \SecurityStore::ensureFact(self::BINDING_FACT_PREFIX . strtolower($environment));
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

    private function govTalk(): GovTalkProtocolConversationService
    {
        return $this->govTalkConversation
            ?? new GovTalkProtocolConversationService($this->archives());
    }

    /**
     * @return array<string,mixed>
     */
    private function govTalkIdentity(
        array $submission,
        string $environment,
        string $archiveReference,
        string $operation,
        ?int $submissionId,
        ?int $preflightId,
        ?int $statusCycleId,
        string $lifecycle
    ): array {
        return [
            'authority' => 'companies_house',
            'company_id' => (int)($submission['company_id'] ?? 0),
            'accounting_period_id' => (int)($submission['accounting_period_id'] ?? 0),
            'environment' => strtoupper(trim($environment)),
            'archive_reference' => trim($archiveReference),
            'lifecycle' => trim($lifecycle) ?: 'unknown',
            'operation' => str_replace('-', '_', strtolower(trim($operation))),
            'submission_id' => $submissionId,
            'preflight_id' => $preflightId,
            'status_cycle_id' => $statusCycleId,
            'hmrc_submission_id' => null,
        ];
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

    private function utcTimestamp(string $value): int
    {
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
