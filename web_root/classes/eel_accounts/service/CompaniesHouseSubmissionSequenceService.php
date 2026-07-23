<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseSubmissionSequenceService
{
    private const TABLE = 'companies_house_submission_sequences';
    private const SUBMISSIONS = 'companies_house_accounts_submissions';
    private const MAX_VALUE = 999999;

    /** @return array{configured:bool,next_number:string,last_issued_number:?string,in_flight_submission_id:?int,status_in_flight_submission_id:?int,status_in_flight_cycle_id:?int,presenter_fingerprint:string} */
    public function status(string $environment, string $presenterId): array
    {
        $environment = $this->environment($environment);
        $fingerprint = $this->fingerprint($presenterId);
        $row = $this->row($environment, $fingerprint);
        $next = (int)($row['next_value'] ?? 1);

        return [
            'configured' => $this->schemaReady(),
            'next_number' => $next <= self::MAX_VALUE ? sprintf('%06d', $next) : 'EXHAUSTED',
            'last_issued_number' => isset($row['last_issued_value'])
                ? sprintf('%06d', (int)$row['last_issued_value'])
                : null,
            'in_flight_submission_id' => isset($row['in_flight_submission_id'])
                ? (int)$row['in_flight_submission_id']
                : null,
            'status_in_flight_submission_id' => isset($row['status_in_flight_submission_id'])
                ? (int)$row['status_in_flight_submission_id']
                : null,
            'status_in_flight_cycle_id' => isset($row['status_in_flight_cycle_id'])
                ? (int)$row['status_in_flight_cycle_id']
                : null,
            'presenter_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Permanently consumes the next number and attaches it to the prepared
     * submission in the same database transaction.
     *
     * @return array{submission_number:string,presenter_fingerprint:string}
     */
    public function allocate(int $submissionId, string $environment, string $presenterId): array
    {
        if ($submissionId <= 0) {
            throw new \InvalidArgumentException('A Companies House submission ID is required.');
        }
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Run the Companies House submission-sequence migration before filing.');
        }
        $environment = $this->environment($environment);
        $fingerprint = $this->fingerprint($presenterId);

        return \InterfaceDB::transaction(function () use ($submissionId, $environment, $fingerprint): array {
            $lock = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
            $submission = \InterfaceDB::fetchOne(
                'SELECT id, environment, lifecycle, submission_number, presenter_fingerprint
                 FROM ' . self::SUBMISSIONS . '
                 WHERE id = :id' . $lock,
                ['id' => $submissionId]
            );
            if (!is_array($submission)
                || (string)$submission['environment'] !== $environment
                || (string)$submission['lifecycle'] !== 'prepared') {
                throw new \RuntimeException('Only the selected prepared Companies House submission can receive a number.');
            }
            if (trim((string)($submission['submission_number'] ?? '')) !== '') {
                throw new \RuntimeException('This Companies House submission number has already been consumed.');
            }

            $this->ensureRow($environment, $fingerprint);
            $sequence = \InterfaceDB::fetchOne(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE environment = :environment
                   AND presenter_fingerprint = :fingerprint' . $lock,
                ['environment' => $environment, 'fingerprint' => $fingerprint]
            );
            if (!is_array($sequence)) {
                throw new \RuntimeException('The Companies House submission sequence could not be locked.');
            }

            $inFlight = (int)($sequence['in_flight_submission_id'] ?? 0);
            if ($inFlight > 0 && $inFlight !== $submissionId) {
                throw new \RuntimeException(
                    'Another Companies House request for this presenter has an unresolved transport state.'
                );
            }

            $value = (int)$sequence['next_value'];
            if ($value < 1 || $value > self::MAX_VALUE) {
                throw new \RuntimeException(
                    'The six-digit Companies House submission-number sequence is exhausted.'
                );
            }
            $number = sprintf('%06d', $value);
            $now = gmdate('Y-m-d H:i:s');
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::SUBMISSIONS . '
                 SET submission_number = :number,
                     presenter_fingerprint = :fingerprint,
                     updated_at = :updated_at
                 WHERE id = :id AND submission_number IS NULL',
                [
                    'number' => $number,
                    'fingerprint' => $fingerprint,
                    'updated_at' => $now,
                    'id' => $submissionId,
                ]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::TABLE . '
                 SET next_value = :next_value,
                     last_issued_value = :last_value,
                     in_flight_submission_id = :submission_id,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'next_value' => $value + 1,
                    'last_value' => $value,
                    'submission_id' => $submissionId,
                    'updated_at' => $now,
                    'id' => (int)$sequence['id'],
                ]
            );

            return ['submission_number' => $number, 'presenter_fingerprint' => $fingerprint];
        });
    }

    public function releaseResolved(int $submissionId, string $environment, string $presenterFingerprint): void
    {
        if ($submissionId <= 0 || !$this->schemaReady()) {
            return;
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::TABLE . '
             SET in_flight_submission_id = NULL, updated_at = :updated_at
             WHERE environment = :environment
               AND presenter_fingerprint = :fingerprint
               AND in_flight_submission_id = :submission_id',
            [
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'environment' => $this->environment($environment),
                'fingerprint' => strtolower(trim($presenterFingerprint)),
                'submission_id' => $submissionId,
            ]
        );
    }

    public function acquireStatusLock(
        int $submissionId,
        int $statusCycleId,
        string $environment,
        string $presenterFingerprint
    ): void {
        if ($submissionId <= 0 || $statusCycleId <= 0 || !$this->statusLockSchemaReady()) {
            throw new \RuntimeException(
                'Run the Companies House protocol-conversation migration before requesting submission status.'
            );
        }
        $environment = $this->environment($environment);
        $presenterFingerprint = strtolower(trim($presenterFingerprint));
        \InterfaceDB::transaction(function () use (
            $submissionId,
            $statusCycleId,
            $environment,
            $presenterFingerprint
        ): void {
            $lock = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
            $row = \InterfaceDB::fetchOne(
                'SELECT * FROM ' . self::TABLE . '
                 WHERE environment = :environment AND presenter_fingerprint = :fingerprint' . $lock,
                ['environment' => $environment, 'fingerprint' => $presenterFingerprint]
            );
            if (!is_array($row)) {
                throw new \RuntimeException('The Companies House presenter sequence is not initialised.');
            }
            $currentSubmission = (int)($row['status_in_flight_submission_id'] ?? 0);
            $currentCycle = (int)($row['status_in_flight_cycle_id'] ?? 0);
            if (($currentSubmission > 0 && $currentSubmission !== $submissionId)
                || ($currentCycle > 0 && $currentCycle !== $statusCycleId)) {
                throw new \RuntimeException(
                    'Another Companies House status/acknowledgement exchange is in progress for this presenter.'
                );
            }
            \InterfaceDB::prepareExecute(
                'UPDATE ' . self::TABLE . '
                 SET status_in_flight_submission_id = :submission_id,
                     status_in_flight_cycle_id = :cycle_id,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'submission_id' => $submissionId,
                    'cycle_id' => $statusCycleId,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int)$row['id'],
                ]
            );
        });
    }

    public function releaseStatusLock(
        int $submissionId,
        int $statusCycleId,
        string $environment,
        string $presenterFingerprint
    ): void {
        if (!$this->statusLockSchemaReady()) {
            return;
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ' . self::TABLE . '
             SET status_in_flight_submission_id = NULL,
                 status_in_flight_cycle_id = NULL,
                 updated_at = :updated_at
             WHERE environment = :environment
               AND presenter_fingerprint = :fingerprint
               AND status_in_flight_submission_id = :submission_id
               AND status_in_flight_cycle_id = :cycle_id',
            [
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'environment' => $this->environment($environment),
                'fingerprint' => strtolower(trim($presenterFingerprint)),
                'submission_id' => $submissionId,
                'cycle_id' => $statusCycleId,
            ]
        );
    }

    private function ensureRow(string $environment, string $fingerprint): void
    {
        if (is_array($this->row($environment, $fingerprint))) {
            return;
        }
        try {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::TABLE . ' (
                    environment, presenter_fingerprint, next_value,
                    last_issued_value, in_flight_submission_id, created_at, updated_at
                 ) VALUES (
                    :environment, :fingerprint, 1, NULL, NULL, :created_at, :updated_at
                 )',
                [
                    'environment' => $environment,
                    'fingerprint' => $fingerprint,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable) {
            // A concurrent allocator may have inserted the same unique row.
            if (!is_array($this->row($environment, $fingerprint))) {
                throw new \RuntimeException('The Companies House submission sequence could not be initialised.');
            }
        }
    }

    private function row(string $environment, string $fingerprint): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::TABLE . '
             WHERE environment = :environment AND presenter_fingerprint = :fingerprint',
            ['environment' => $environment, 'fingerprint' => $fingerprint]
        );

        return is_array($row) ? $row : null;
    }

    private function schemaReady(): bool
    {
        return \InterfaceDB::tableExists(self::TABLE)
            && \InterfaceDB::columnExists(self::SUBMISSIONS, 'presenter_fingerprint');
    }

    private function statusLockSchemaReady(): bool
    {
        return $this->schemaReady()
            && \InterfaceDB::columnExists(self::TABLE, 'status_in_flight_submission_id')
            && \InterfaceDB::columnExists(self::TABLE, 'status_in_flight_cycle_id')
            && \InterfaceDB::tableExists('companies_house_accounts_status_cycles');
    }

    private function fingerprint(string $presenterId): string
    {
        $presenterId = strtoupper(trim($presenterId));
        if ($presenterId === '') {
            throw new \InvalidArgumentException('A Companies House presenter ID is required.');
        }

        return hash('sha256', $presenterId);
    }

    private function environment(string $environment): string
    {
        $environment = strtoupper(trim($environment));
        if (!in_array($environment, ['TEST', 'LIVE'], true)) {
            throw new \InvalidArgumentException('Companies House sequence environment must be TEST or LIVE.');
        }

        return $environment;
    }
}
