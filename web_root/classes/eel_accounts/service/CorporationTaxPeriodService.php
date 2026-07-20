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

    public function __construct(private readonly ?\Closure $vatSupportScopeFetcher = null)
    {
    }

    /**
     * Stable read-only reference used while a derived CT period has not yet
     * been persisted. An accounting period can contain at most two statutory
     * CT periods, so one decimal digit is sufficient for the local sequence.
     */
    public static function transientReferenceId(int $accountingPeriodId, int $sequenceNo): int
    {
        if ($accountingPeriodId <= 0 || $sequenceNo <= 0 || $sequenceNo > 9) {
            return 0;
        }

        return 0 - (($accountingPeriodId * 10) + $sequenceNo);
    }

    /** @return array{accounting_period_id: int, sequence_no: int}|null */
    public static function decodeTransientReferenceId(int $ctPeriodId): ?array
    {
        if ($ctPeriodId >= 0) {
            return null;
        }

        $encoded = abs($ctPeriodId);
        $sequenceNo = $encoded % 10;
        $accountingPeriodId = intdiv($encoded, 10);
        if (self::transientReferenceId($accountingPeriodId, $sequenceNo) !== $ctPeriodId) {
            return null;
        }

        return [
            'accounting_period_id' => $accountingPeriodId,
            'sequence_no' => $sequenceNo,
        ];
    }

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
        $scopeError = $this->vatSupportScopeError($companyId);
        if ($scopeError !== null) {
            return [
                'success' => false,
                'errors' => [$scopeError],
                'periods' => [],
            ];
        }

        $this->ensureSchema();
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The accounting period could not be found.'], 'periods' => []];
        }

        $existing = $this->fetchForAccountingPeriod($companyId, $accountingPeriodId);
        $derived = array_values((new \eel_accounts\Service\TaxPeriodService())->derive(
            (string)$accountingPeriod['period_start'],
            (string)$accountingPeriod['period_end'],
            $companyId
        ));
        $derivedBySequence = [];
        foreach ($derived as $index => $period) {
            $derivedBySequence[$index + 1] = $period;
        }
        $existingBySequence = [];
        foreach ($existing as $period) {
            $sequenceNo = (int)($period['sequence_no'] ?? 0);
            if ($sequenceNo > 0) {
                $existingBySequence[$sequenceNo] = $period;
            }
            if (!in_array((string)($period['status'] ?? ''), self::LOCKED_STATUSES, true)) {
                continue;
            }

            $expected = $derivedBySequence[$sequenceNo] ?? null;
            if (!is_array($expected)
                || (string)($period['period_start'] ?? '') !== (string)($expected['start'] ?? '')
                || (string)($period['period_end'] ?? '') !== (string)($expected['end'] ?? '')) {
                return [
                    'success' => false,
                    'errors' => [
                        'Submitted or accepted CT period metadata does not match the statutory accounting-period calendar and cannot be regenerated automatically.',
                    ],
                    'periods' => $existing,
                ];
            }
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $seen = [];
            foreach ($derived as $index => $period) {
                $sequence = $index + 1;
                $seen[] = $sequence;
                $stored = $existingBySequence[$sequence] ?? null;
                if (is_array($stored)
                    && in_array((string)($stored['status'] ?? ''), self::LOCKED_STATUSES, true)) {
                    continue;
                }
                $upsertSql = 'INSERT INTO corporation_tax_periods (
                        company_id, accounting_period_id, sequence_no, period_start, period_end, status
                     ) VALUES (
                        :company_id, :accounting_period_id, :sequence_no, :period_start, :period_end, :status
                     )';

                if (\InterfaceDB::driverName() === 'sqlite') {
                    $upsertSql .= '
                     ON CONFLICT(accounting_period_id, sequence_no) DO UPDATE SET
                        updated_at = CASE
                            WHEN period_start <> excluded.period_start
                              OR period_end <> excluded.period_end
                              OR status = \'superseded\'
                            THEN CURRENT_TIMESTAMP
                            ELSE updated_at
                        END,
                        latest_computation_run_id = CASE
                            WHEN period_start <> excluded.period_start
                              OR period_end <> excluded.period_end
                            THEN NULL
                            ELSE latest_computation_run_id
                        END,
                        latest_submission_id = CASE
                            WHEN period_start <> excluded.period_start
                              OR period_end <> excluded.period_end
                            THEN NULL
                            ELSE latest_submission_id
                        END,
                        status = CASE
                            WHEN period_start <> excluded.period_start
                              OR period_end <> excluded.period_end
                              OR status = \'superseded\'
                            THEN \'pending\'
                            ELSE status
                        END,
                        period_start = excluded.period_start,
                        period_end = excluded.period_end';
                } else {
                    $upsertSql .= '
                     ON DUPLICATE KEY UPDATE
                        updated_at = CASE
                            WHEN period_start <> VALUES(period_start)
                              OR period_end <> VALUES(period_end)
                              OR status = \'superseded\'
                            THEN CURRENT_TIMESTAMP
                            ELSE updated_at
                        END,
                        latest_computation_run_id = CASE
                            WHEN period_start <> VALUES(period_start)
                              OR period_end <> VALUES(period_end)
                            THEN NULL
                            ELSE latest_computation_run_id
                        END,
                        latest_submission_id = CASE
                            WHEN period_start <> VALUES(period_start)
                              OR period_end <> VALUES(period_end)
                            THEN NULL
                            ELSE latest_submission_id
                        END,
                        status = CASE
                            WHEN period_start <> VALUES(period_start)
                              OR period_end <> VALUES(period_end)
                              OR status = \'superseded\'
                            THEN \'pending\'
                            ELSE status
                        END,
                        period_start = VALUES(period_start),
                        period_end = VALUES(period_end)';
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
                       AND status NOT IN (\'submitted\', \'accepted\', \'superseded\')',
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
        return $this->fetchExistingForAccountingPeriod($companyId, $accountingPeriodId);
    }

    /**
     * Read already-initialised CT periods without creating schema or
     * synchronising period rows. Reporting services must use this path so a
     * page refresh cannot alter CT metadata.
     */
    public function fetchExistingForAccountingPeriod(int $companyId, int $accountingPeriodId): array
    {
        if (!\InterfaceDB::tableExists('corporation_tax_periods')) {
            return [];
        }
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $periods = \eel_accounts\Support\RequestCache::remember(
            'corporation-tax-period.rows',
            $companyId . ':' . $accountingPeriodId,
            static fn(): array => \InterfaceDB::fetchAll(
                'SELECT *
                 FROM corporation_tax_periods
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 ORDER BY sequence_no ASC, id ASC',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            )
        );

        return $this->withDisplaySequences($companyId, (array)$periods);
    }

    /**
     * Build the statutory CT-period calendar without changing stored metadata.
     *
     * Existing rows are reused only when their sequence and dates match the
     * accounting-period-derived calendar. Missing or stale open rows are
     * represented by stable transient references so previews match the rows a
     * later synchronisation will create. Submitted or accepted rows cannot be
     * repaired automatically, so any locked inconsistency is returned as an
     * explicit error instead of producing a misleading preview.
     *
     * @param array<string, mixed>|null $accountingPeriod
     * @return array{success: bool, periods: list<array<string, mixed>>, errors: list<string>, requires_sync: bool}
     */
    public function projectForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        ?array $accountingPeriod = null
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'success' => false,
                'periods' => [],
                'errors' => ['A valid company and accounting period are required.'],
                'requires_sync' => false,
            ];
        }

        $accountingPeriod ??= (new \eel_accounts\Repository\AccountingPeriodRepository())
            ->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($accountingPeriod)) {
            return [
                'success' => false,
                'periods' => [],
                'errors' => ['The accounting period could not be found.'],
                'requires_sync' => false,
            ];
        }

        try {
            $derived = array_values((new \eel_accounts\Service\TaxPeriodService())->derive(
                (string)($accountingPeriod['period_start'] ?? ''),
                (string)($accountingPeriod['period_end'] ?? ''),
                $companyId
            ));
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'periods' => [],
                'errors' => [$exception->getMessage()],
                'requires_sync' => false,
            ];
        }

        $existing = array_values(array_filter(
            $this->fetchExistingForAccountingPeriod($companyId, $accountingPeriodId),
            static fn(array $period): bool => (string)($period['status'] ?? '') !== 'superseded'
        ));
        $existingBySequence = [];
        foreach ($existing as $period) {
            $sequenceNo = (int)($period['sequence_no'] ?? 0);
            if ($sequenceNo > 0 && !isset($existingBySequence[$sequenceNo])) {
                $existingBySequence[$sequenceNo] = $period;
            }
        }

        $periods = [];
        $requiresSync = false;
        $lockedInconsistency = false;
        foreach ($derived as $index => $derivedPeriod) {
            $sequenceNo = $index + 1;
            $periodStart = (string)($derivedPeriod['start'] ?? '');
            $periodEnd = (string)($derivedPeriod['end'] ?? '');
            $stored = $existingBySequence[$sequenceNo] ?? null;
            unset($existingBySequence[$sequenceNo]);

            if (is_array($stored)
                && (string)($stored['period_start'] ?? '') === $periodStart
                && (string)($stored['period_end'] ?? '') === $periodEnd) {
                $periods[] = $stored;
                continue;
            }

            $requiresSync = true;
            if (is_array($stored)
                && in_array((string)($stored['status'] ?? ''), self::LOCKED_STATUSES, true)) {
                $lockedInconsistency = true;
            }
            $displaySequenceNo = $this->displaySequenceNo(
                $companyId,
                $accountingPeriodId,
                $sequenceNo
            );
            $periods[] = [
                'id' => self::transientReferenceId($accountingPeriodId, $sequenceNo),
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'sequence_no' => $sequenceNo,
                'display_sequence_no' => $displaySequenceNo,
                'display_label' => 'CT Period ' . $displaySequenceNo,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => 'transient',
                'latest_computation_run_id' => null,
                'latest_submission_id' => null,
            ];
        }

        if ($existingBySequence !== []) {
            $requiresSync = true;
            foreach ($existingBySequence as $stored) {
                if (in_array((string)($stored['status'] ?? ''), self::LOCKED_STATUSES, true)) {
                    $lockedInconsistency = true;
                    break;
                }
            }
        }

        $errors = [];
        if ($lockedInconsistency) {
            $errors[] = 'Submitted or accepted CT period metadata does not match the statutory accounting-period calendar and cannot be repaired automatically.';
        }

        return [
            'success' => $errors === [],
            'periods' => $periods,
            'errors' => $errors,
            'requires_sync' => $requiresSync,
        ];
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

    /**
     * Validate the statutory calendar limit of twelve months. The inclusive
     * day count may therefore be 366 when the period spans 29 February.
     *
     * @return array{valid: bool, days: int, maximum_end: string, error: string}
     */
    public function validateMaximumPeriodLength(string $periodStart, string $periodEnd): array
    {
        return (new \eel_accounts\Service\TaxPeriodService())
            ->validateMaximumPeriodLength($periodStart, $periodEnd);
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
        $this->assertCtPeriodMutationSupported($ctPeriodId, 'record a Corporation Tax computation');

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
        $this->assertCtPeriodMutationSupported($ctPeriodId, 'record a Corporation Tax submission');

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

        $displaySequences = [];
        $displaySequence = 1;
        $taxPeriodService = new \eel_accounts\Service\TaxPeriodService();
        foreach ($accountingPeriods as $accountingPeriod) {
            $accountingPeriodId = (int)($accountingPeriod['id'] ?? 0);
            if ($accountingPeriodId <= 0) {
                continue;
            }

            $periods = array_values($taxPeriodService->derive(
                (string)($accountingPeriod['period_start'] ?? ''),
                (string)($accountingPeriod['period_end'] ?? ''),
                $companyId
            ));
            foreach (array_keys($periods) as $index) {
                $sequenceNo = $index + 1;
                $displaySequences[$this->displaySequenceKey($accountingPeriodId, $sequenceNo)] = $displaySequence;
                $displaySequence++;
            }
        }

        return $displaySequences;
    }

    private function assertCtPeriodMutationSupported(int $ctPeriodId, string $actionLabel): void
    {
        $companyId = (int)\InterfaceDB::fetchColumn(
            'SELECT company_id FROM corporation_tax_periods WHERE id = :id LIMIT 1',
            ['id' => $ctPeriodId]
        );
        $scopeError = $this->vatSupportScopeError($companyId);
        if ($scopeError !== null) {
            throw new \RuntimeException($scopeError . ' You cannot ' . $actionLabel . '.');
        }
    }

    private function vatSupportScopeError(int $companyId): ?string
    {
        if ($companyId <= 0) {
            return null;
        }

        try {
            if ($this->vatSupportScopeFetcher !== null) {
                $scope = ($this->vatSupportScopeFetcher)($companyId);
                if (!is_array($scope) || !array_key_exists('tax_year_end_read_only', $scope)) {
                    throw new \RuntimeException('VAT support scope resolver returned an invalid result.');
                }
            } else {
                $scope = (new \eel_accounts\Service\VatSupportScopeService())->fetchForCompany($companyId);
            }
        } catch (\Throwable) {
            return \eel_accounts\Service\VatSupportScopeService::SCOPE_EVALUATION_ERROR_MESSAGE;
        }

        return !empty($scope['tax_year_end_read_only'])
            ? (string)($scope['message'] ?? \eel_accounts\Service\VatSupportScopeService::UNSUPPORTED_MESSAGE)
            : null;
    }

    private function displaySequenceKey(int $accountingPeriodId, int $sequenceNo): string
    {
        return $accountingPeriodId . ':' . $sequenceNo;
    }
}
