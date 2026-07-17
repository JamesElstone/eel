<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Migration-backed persistence for immutable CT600 supplementary assessments. */
final class Ct600SupplementaryAssessmentRepository
{
    private const ASSESSMENTS = 'ct600_supplement_assessments';
    private const ROWS = 'ct600_supplement_assessment_rows';

    public function requireSchema(): void
    {
        if (
            !\InterfaceDB::tableExists(self::ASSESSMENTS)
            || !\InterfaceDB::columnsExists(self::ASSESSMENTS, [
                'id', 'company_id', 'accounting_period_id', 'ct_period_id', 'computation_run_id',
                'year_end_locked_at', 'assessment_hash', 'approved_by', 'approved_at', 'created_at',
            ])
            || !\InterfaceDB::tableExists(self::ROWS)
            || !\InterfaceDB::columnsExists(self::ROWS, [
                'id', 'assessment_id', 'row_order', 'contract_key', 'page', 'label', 'status',
                'evidence_source', 'evidence_ref', 'detail', 'created_at',
            ])
        ) {
            throw new \RuntimeException(
                'The CT600 supplementary-assessment migration has not been applied. Run the downstream database migrations first.'
            );
        }
    }

    /** @return array<string, mixed> */
    public function currentBinding(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        $this->requireSchema();
        return $this->currentBindingInternal($companyId, $accountingPeriodId, $ctPeriodId, false);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function create(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $computationRunId,
        string $yearEndLockedAt,
        array $rows,
        string $approvedBy,
        ?\DateTimeImmutable $approvedAt = null
    ): array {
        $this->requireSchema();
        $rows = Ct600SupplementaryAssessmentContract::normaliseRows($rows);
        $approvedBy = $this->actor($approvedBy);
        $approvedAtValue = ($approvedAt ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $yearEndLockedAt = $this->dateTime($yearEndLockedAt, 'Year End lock timestamp');

        return \InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $ctPeriodId,
            $computationRunId,
            $yearEndLockedAt,
            $rows,
            $approvedBy,
            $approvedAtValue
        ): array {
            $binding = $this->currentBindingInternal(
                $companyId,
                $accountingPeriodId,
                $ctPeriodId,
                true
            );
            if (
                (int)$binding['computation_run_id'] !== $computationRunId
                || !hash_equals((string)$binding['year_end_locked_at'], $yearEndLockedAt)
            ) {
                throw new \DomainException(
                    'The supplementary assessment is not bound to the current locked computation. Reload the filing assessment.'
                );
            }

            $hash = Ct600SupplementaryAssessmentContract::hash(
                $binding,
                $rows,
                $approvedBy,
                $approvedAtValue
            );
            $existing = $this->fetchByHashInternal($hash, true);
            if (is_array($existing)) {
                if (!$this->verify($existing)) {
                    throw new \RuntimeException('The stored supplementary assessment failed its immutable hash check.');
                }
                return $existing;
            }

            \InterfaceDB::prepareExecute(
                'INSERT INTO ' . self::ASSESSMENTS . ' (
                    company_id, accounting_period_id, ct_period_id, computation_run_id,
                    year_end_locked_at, assessment_hash, approved_by, approved_at
                 ) VALUES (
                    :company_id, :accounting_period_id, :ct_period_id, :computation_run_id,
                    :year_end_locked_at, :assessment_hash, :approved_by, :approved_at
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'ct_period_id' => $ctPeriodId,
                    'computation_run_id' => $computationRunId,
                    'year_end_locked_at' => $yearEndLockedAt,
                    'assessment_hash' => $hash,
                    'approved_by' => $approvedBy,
                    'approved_at' => $approvedAtValue,
                ]
            );
            $header = $this->fetchHeaderByHashInternal($hash, true);
            if (!is_array($header)) {
                throw new \RuntimeException('The supplementary assessment could not be reloaded after insertion.');
            }
            foreach ($rows as $row) {
                \InterfaceDB::prepareExecute(
                    'INSERT INTO ' . self::ROWS . ' (
                        assessment_id, row_order, contract_key, page, label, status,
                        evidence_source, evidence_ref, detail
                     ) VALUES (
                        :assessment_id, :row_order, :contract_key, :page, :label, :status,
                        :evidence_source, :evidence_ref, :detail
                     )',
                    [
                        'assessment_id' => (int)$header['id'],
                        'row_order' => (int)$row['row_order'],
                        'contract_key' => (string)$row['contract_key'],
                        'page' => $row['page'],
                        'label' => (string)$row['label'],
                        'status' => (string)$row['status'],
                        'evidence_source' => (string)$row['evidence_source'],
                        'evidence_ref' => (string)$row['evidence_ref'],
                        'detail' => (string)$row['detail'],
                    ]
                );
            }

            $created = $this->fetchByIdInternal((int)$header['id'], true);
            if (!is_array($created) || !$this->verify($created)) {
                throw new \RuntimeException('The stored supplementary assessment failed its immutable hash check.');
            }

            return $created;
        });
    }

    /** @return array<string, mixed>|null */
    public function fetchCurrent(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        int $computationRunId,
        string $yearEndLockedAt
    ): ?array {
        $this->requireSchema();
        $yearEndLockedAt = $this->dateTime($yearEndLockedAt, 'Year End lock timestamp');
        $header = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::ASSESSMENTS . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND ct_period_id = :ct_period_id
               AND computation_run_id = :computation_run_id
               AND year_end_locked_at = :year_end_locked_at
             ORDER BY approved_at DESC, id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
                'computation_run_id' => $computationRunId,
                'year_end_locked_at' => $yearEndLockedAt,
            ]
        );

        return is_array($header) ? $this->hydrate($header) : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchById(int $assessmentId): ?array
    {
        $this->requireSchema();
        return $assessmentId > 0 ? $this->fetchByIdInternal($assessmentId, false) : null;
    }

    /** @param array<string, mixed> $assessment */
    public function verify(array $assessment): bool
    {
        try {
            $rows = Ct600SupplementaryAssessmentContract::normaliseRows((array)($assessment['rows'] ?? []));
            $calculated = Ct600SupplementaryAssessmentContract::hash(
                $assessment,
                $rows,
                (string)($assessment['approved_by'] ?? ''),
                (string)($assessment['approved_at'] ?? '')
            );

            return preg_match('/^[a-f0-9]{64}$/D', (string)($assessment['assessment_hash'] ?? '')) === 1
                && hash_equals((string)$assessment['assessment_hash'], $calculated);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function currentBindingInternal(
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId,
        bool $forUpdate
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            throw new \InvalidArgumentException('A company, accounting period, and CT period are required.');
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT ctp.company_id,
                    ctp.accounting_period_id,
                    ctp.id AS ct_period_id,
                    ctp.latest_computation_run_id AS computation_run_id,
                    yer.is_locked,
                    yer.locked_at AS year_end_locked_at
             FROM corporation_tax_periods ctp
             INNER JOIN accounting_periods ap
               ON ap.id = ctp.accounting_period_id
              AND ap.company_id = ctp.company_id
             LEFT JOIN year_end_reviews yer
               ON yer.company_id = ctp.company_id
              AND yer.accounting_period_id = ctp.accounting_period_id
             LEFT JOIN corporation_tax_computation_runs ctr
               ON ctr.id = ctp.latest_computation_run_id
              AND ctr.company_id = ctp.company_id
              AND ctr.accounting_period_id = ctp.accounting_period_id
              AND ctr.ct_period_id = ctp.id
             WHERE ctp.company_id = :company_id
               AND ctp.accounting_period_id = :accounting_period_id
               AND ctp.id = :ct_period_id
               AND ctr.id IS NOT NULL
             LIMIT 1' . ($forUpdate ? $this->forUpdateSuffix() : ''),
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'ct_period_id' => $ctPeriodId,
            ]
        );
        if (!is_array($row)) {
            throw new \DomainException('The selected CT period has no current computation run.');
        }
        if (empty($row['is_locked']) || trim((string)($row['year_end_locked_at'] ?? '')) === '') {
            throw new \DomainException('Year End must be locked before supplementary scope can be assessed.');
        }

        return [
            'company_id' => (int)$row['company_id'],
            'accounting_period_id' => (int)$row['accounting_period_id'],
            'ct_period_id' => (int)$row['ct_period_id'],
            'computation_run_id' => (int)$row['computation_run_id'],
            'year_end_locked_at' => $this->dateTime(
                (string)$row['year_end_locked_at'],
                'Year End lock timestamp'
            ),
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchByHashInternal(string $hash, bool $forUpdate): ?array
    {
        $header = $this->fetchHeaderByHashInternal($hash, $forUpdate);
        return is_array($header) ? $this->hydrate($header) : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchHeaderByHashInternal(string $hash, bool $forUpdate): ?array
    {
        $header = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::ASSESSMENTS . '
             WHERE assessment_hash = :assessment_hash
             LIMIT 1' . ($forUpdate ? $this->forUpdateSuffix() : ''),
            ['assessment_hash' => $hash]
        );

        return is_array($header) ? $header : null;
    }

    /** @return array<string, mixed>|null */
    private function fetchByIdInternal(int $assessmentId, bool $forUpdate): ?array
    {
        $header = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . self::ASSESSMENTS . '
             WHERE id = :id
             LIMIT 1' . ($forUpdate ? $this->forUpdateSuffix() : ''),
            ['id' => $assessmentId]
        );

        return is_array($header) ? $this->hydrate($header) : null;
    }

    /** @param array<string, mixed> $header @return array<string, mixed> */
    private function hydrate(array $header): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT row_order, contract_key, page, label, status, evidence_source, evidence_ref, detail
             FROM ' . self::ROWS . '
             WHERE assessment_id = :assessment_id
             ORDER BY row_order ASC, id ASC',
            ['assessment_id' => (int)$header['id']]
        );
        $assessment = $header;
        foreach (['id', 'company_id', 'accounting_period_id', 'ct_period_id', 'computation_run_id'] as $field) {
            $assessment[$field] = (int)$assessment[$field];
        }
        $assessment['rows'] = array_map(
            static function (array $row): array {
                $row['row_order'] = (int)$row['row_order'];
                $row['page'] = $row['page'] === null || $row['page'] === '' ? null : (string)$row['page'];
                return $row;
            },
            $rows
        );
        $assessment['hash_valid'] = $this->verify($assessment);

        return $assessment;
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 255 || preg_match('/[\x00-\x1F\x7F]/', $actor)) {
            throw new \InvalidArgumentException('A valid authenticated supplementary-assessment approver is required.');
        }
        return $actor;
    }

    private function dateTime(string $value, string $label): string
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof \DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d H:i:s') !== $value
        ) {
            throw new \InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function forUpdateSuffix(): string
    {
        return \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
    }
}
