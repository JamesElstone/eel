<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CorporationTaxPeriodService
{
    private const LOCKED_STATUSES = ['submitted', 'accepted'];

    public function ensureSchema(): void
    {
        if (!\InterfaceDB::tableExists('corporation_tax_periods')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS corporation_tax_periods (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    accounting_period_id INT NOT NULL,
                    sequence_no INT NOT NULL,
                    period_start DATE NOT NULL,
                    period_end DATE NOT NULL,
                    status ENUM('pending','computed','ready','submitted','accepted','rejected','superseded') NOT NULL DEFAULT 'pending',
                    latest_computation_run_id INT NULL,
                    latest_submission_id BIGINT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ct_period_sequence (accounting_period_id, sequence_no),
                    KEY idx_ct_period_company_period (company_id, accounting_period_id),
                    KEY idx_ct_period_status (company_id, accounting_period_id, status),
                    CONSTRAINT fk_ct_period_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_ct_period_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!\InterfaceDB::tableExists('corporation_tax_computation_runs')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS corporation_tax_computation_runs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    accounting_period_id INT NOT NULL,
                    ct_period_id INT NOT NULL,
                    period_start DATE NOT NULL,
                    period_end DATE NOT NULL,
                    status ENUM('draft','generated','failed') NOT NULL DEFAULT 'draft',
                    computation_hash CHAR(64) NOT NULL,
                    summary_json LONGTEXT NOT NULL,
                    generated_path VARCHAR(1000) NULL,
                    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_ct_computation_period (ct_period_id, generated_at),
                    KEY idx_ct_computation_company_period (company_id, accounting_period_id, generated_at),
                    CONSTRAINT fk_ct_computation_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_ct_computation_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_ct_computation_ct_period FOREIGN KEY (ct_period_id) REFERENCES corporation_tax_periods(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    public function syncForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $this->ensureSchema();
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The accounting period could not be found.'], 'periods' => []];
        }

        $existing = $this->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        foreach ($existing as $period) {
            if (in_array((string)($period['status'] ?? ''), self::LOCKED_STATUSES, true)) {
                return ['success' => false, 'errors' => ['Submitted CT periods cannot be regenerated automatically.'], 'periods' => $existing];
            }
        }

        $derived = (new \eel_accounts\Service\TaxPeriodService())->derive(
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end'],
            $companyId
        );

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $seen = [];
            foreach (array_values($derived) as $index => $period) {
                $sequence = $index + 1;
                $seen[] = $sequence;
                $upsertSql = 'INSERT INTO corporation_tax_periods (
                        company_id, accounting_period_id, sequence_no, period_start, period_end, status
                     ) VALUES (
                        :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status
                     )';

                if (\InterfaceDB::driverName() === 'sqlite') {
                    $upsertSql .= '
                     ON CONFLICT(accounting_period_id, sequence_no) DO UPDATE SET
                        period_start = excluded.period_start,
                        period_end = excluded.period_end,
                        status = CASE WHEN status = \'superseded\' THEN \'pending\' ELSE status END,
                        updated_at = CURRENT_TIMESTAMP';
                } else {
                    $upsertSql .= '
                     ON DUPLICATE KEY UPDATE
                        period_start = VALUES(period_start),
                        period_end = VALUES(period_end),
                        status = CASE WHEN status = \'superseded\' THEN \'pending\' ELSE status END,
                        updated_at = CURRENT_TIMESTAMP';
                }

                \InterfaceDB::prepareExecute(
                    $upsertSql,
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'sequence_no' => $sequence,
                        'period_start' => (string)$period['start'],
                        'period_end' => (string)$period['end'],
                        'status' => 'pending',
                    ]
                );
            }

            if ($seen !== []) {
                $placeholders = implode(',', array_fill(0, count($seen), '?'));
                \InterfaceDB::prepareExecute(
                    'UPDATE corporation_tax_periods
                     SET status = \'superseded\', updated_at = CURRENT_TIMESTAMP
                     WHERE company_id = ?
                       AND accounting_period_id = ?
                       AND sequence_no NOT IN (' . $placeholders . ')
                       AND status NOT IN (\'submitted\', \'accepted\')',
                    array_merge([$companyId, $accountingPeriodId], $seen)
                );
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()], 'periods' => $existing];
        }

        return ['success' => true, 'errors' => [], 'periods' => $this->fetchForAccountingPeriod($companyId, $accountingPeriodId)];
    }

    public function fetchForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        $this->ensureSchema();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $periods = \InterfaceDB::fetchAll(
            'SELECT *
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY sequence_no ASC, id ASC',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return $this->withDisplaySequences($companyId, $periods);
    }

    public function fetch(int $companyId, int $ctPeriodId): ?array
    {
        $this->ensureSchema();
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1',
            ['company_id' => $companyId, 'id' => $ctPeriodId]
        );

        if (!is_array($row)) {
            return null;
        }

        $periods = $this->withDisplaySequences($companyId, [$row]);
        return $periods[0] ?? $row;
    }

    public function defaultCtPeriodId(int $companyId, int $accountingPeriodId): int
    {
        $periods = $this->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        foreach ($periods as $period) {
            if (!in_array((string)($period['status'] ?? ''), ['accepted', 'superseded'], true)) {
                return (int)($period['id'] ?? 0);
            }
        }

        return (int)($periods[0]['id'] ?? 0);
    }

    public function displaySequenceNo(int $companyId, int $accountingPeriodId, int $sequenceNo): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $sequenceNo <= 0) {
            return $sequenceNo;
        }

        $displaySequences = $this->displaySequenceMap($companyId);
        return (int)($displaySequences[$this->displaySequenceKey($accountingPeriodId, $sequenceNo)] ?? $sequenceNo);
    }

    public function canSubmit(int $companyId, int $ctPeriodId): array
    {
        $period = $this->fetch($companyId, $ctPeriodId);
        if ($period === null) {
            return ['ok' => false, 'errors' => ['Select a valid CT period.']];
        }

        $blocking = \InterfaceDB::fetchOne(
            'SELECT sequence_no, period_start, period_end, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND sequence_no < :sequence_no
               AND status NOT IN (\'accepted\')
             ORDER BY sequence_no ASC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => (int)$period['accounting_period_id'],
                'sequence_no' => (int)$period['sequence_no'],
            ]
        );

        if (is_array($blocking)) {
            return [
                'ok' => false,
                'errors' => [
                    'CT Period ' . (int)$blocking['sequence_no'] . ' must be accepted or marked filed before this later CT period can be submitted.',
                ],
            ];
        }

        return ['ok' => true, 'errors' => []];
    }

    public function markLatestComputation(int $ctPeriodId, int $runId): void
    {
        if ($ctPeriodId <= 0 || $runId <= 0) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_periods
             SET latest_computation_run_id = :run_id,
                 status = CASE WHEN status = "pending" THEN "computed" ELSE status END
             WHERE id = :id',
            ['run_id' => $runId, 'id' => $ctPeriodId]
        );
    }

    public function markLatestSubmission(int $ctPeriodId, int $submissionId, string $status): void
    {
        if ($ctPeriodId <= 0 || $submissionId <= 0) {
            return;
        }

        $periodStatus = match ($status) {
            'accepted' => 'accepted',
            'rejected', 'failed' => 'rejected',
            'ready' => 'ready',
            'submitting' => 'submitted',
            default => null,
        };

        $sql = 'UPDATE corporation_tax_periods SET latest_submission_id = :submission_id';
        $params = ['submission_id' => $submissionId, 'id' => $ctPeriodId];

        if ($periodStatus !== null) {
            $sql .= ', status = :status';
            $params['status'] = $periodStatus;
        }

        $sql .= ' WHERE id = :id';
        \InterfaceDB::prepareExecute($sql, $params);
    }

    private function withDisplaySequences(int $companyId, array $periods): array
    {
        if ($companyId <= 0 || $periods === []) {
            return $periods;
        }

        $displaySequences = $this->displaySequenceMap($companyId);
        foreach ($periods as &$period) {
            $key = $this->displaySequenceKey(
                (int)($period['accounting_period_id'] ?? 0),
                (int)($period['sequence_no'] ?? 0)
            );
            $displaySequence = (int)($displaySequences[$key] ?? ($period['sequence_no'] ?? 0));
            $period['display_sequence_no'] = $displaySequence;
            $period['display_label'] = 'CT Period ' . $displaySequence;
        }
        unset($period);

        return $periods;
    }

    private function displaySequenceMap(int $companyId): array
    {
        $accountingPeriods = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId);
        usort($accountingPeriods, static function (array $a, array $b): int {
            return [
                (string)($a['period_start'] ?? ''),
                (int)($a['id'] ?? 0),
            ] <=> [
                (string)($b['period_start'] ?? ''),
                (int)($b['id'] ?? 0),
            ];
        });

        $storedPeriods = \InterfaceDB::fetchAll(
            'SELECT accounting_period_id, sequence_no, status
             FROM corporation_tax_periods
             WHERE company_id = :company_id
             ORDER BY accounting_period_id ASC, sequence_no ASC, id ASC',
            ['company_id' => $companyId]
        );
        $storedByAccountingPeriod = [];
        foreach ($storedPeriods as $period) {
            if ((string)($period['status'] ?? '') === 'superseded') {
                continue;
            }
            $storedByAccountingPeriod[(int)($period['accounting_period_id'] ?? 0)][] = $period;
        }

        $displaySequences = [];
        $displaySequence = 1;
        $taxPeriodService = new \eel_accounts\Service\TaxPeriodService();
        foreach ($accountingPeriods as $accountingPeriod) {
            $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
            if ($accountingPeriodId <= 0) {
                continue;
            }

            $periods = (array)($storedByAccountingPeriod[$accountingPeriodId] ?? []);
            if ($periods === []) {
                $periods = array_map(
                    static fn(int $index): array => ['sequence_no' => $index + 1],
                    array_keys(array_values($taxPeriodService->derive(
                        (string)($accountingPeriod['period_start'] ?? ''),
                        (string)($accountingPeriod['period_end'] ?? ''),
                        $companyId
                    )))
                );
            }

            usort($periods, static function (array $a, array $b): int {
                return (int)($a['sequence_no'] ?? 0) <=> (int)($b['sequence_no'] ?? 0);
            });

            foreach ($periods as $period) {
                $sequenceNo = (int)($period['sequence_no'] ?? 0);
                if ($sequenceNo <= 0) {
                    continue;
                }
                $displaySequences[$this->displaySequenceKey($accountingPeriodId, $sequenceNo)] = $displaySequence;
                $displaySequence++;
            }
        }

        return $displaySequences;
    }

    private function displaySequenceKey(int $accountingPeriodId, int $sequenceNo): string
    {
        return $accountingPeriodId . ':' . $sequenceNo;
    }
}
