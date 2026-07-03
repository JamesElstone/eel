<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class YearEndLockService
{
    public function fetchReview(int $companyId, int $accountingPeriodId): ?array {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->hasReviewTable()) {
            return null;
        }

        $acknowledgementColumns = [];
        if ($this->hasReviewColumn('director_loan_closing_acknowledged_at') && $this->hasReviewColumn('director_loan_closing_acknowledged_by')) {
            $acknowledgementColumns[] = 'director_loan_closing_acknowledged_at';
            $acknowledgementColumns[] = 'director_loan_closing_acknowledged_by';
        } else {
            $acknowledgementColumns[] = 'NULL AS director_loan_closing_acknowledged_at';
            $acknowledgementColumns[] = 'NULL AS director_loan_closing_acknowledged_by';
        }

        if ($this->hasReviewColumn('tax_readiness_acknowledged_at') && $this->hasReviewColumn('tax_readiness_acknowledged_by')) {
            $acknowledgementColumns[] = 'tax_readiness_acknowledged_at';
            $acknowledgementColumns[] = 'tax_readiness_acknowledged_by';
        } else {
            $acknowledgementColumns[] = 'NULL AS tax_readiness_acknowledged_at';
            $acknowledgementColumns[] = 'NULL AS tax_readiness_acknowledged_by';
        }

        if ($this->hasReviewColumn('expense_position_acknowledged_at') && $this->hasReviewColumn('expense_position_acknowledged_by')) {
            $acknowledgementColumns[] = 'expense_position_acknowledged_at';
            $acknowledgementColumns[] = 'expense_position_acknowledged_by';
        } else {
            $acknowledgementColumns[] = 'NULL AS expense_position_acknowledged_at';
            $acknowledgementColumns[] = 'NULL AS expense_position_acknowledged_by';
        }

        $row = \InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    accounting_period_id,
                    status,
                    is_locked,
                    locked_at,
                    locked_by,
                    review_notes,
                    ' . implode(",\n                    ", $acknowledgementColumns) . ',
                    last_recalculated_at,
                    created_at,
                    updated_at
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);
        return is_array($row) ? $row : null;
    }

    public function isLocked(int $companyId, int $accountingPeriodId): bool {
        $review = $this->fetchReview($companyId, $accountingPeriodId);

        return is_array($review) && (int)($review['is_locked'] ?? 0) === 1;
    }

    public function assertUnlocked(int $companyId, int $accountingPeriodId, string $actionLabel = 'change this period'): void {
        if ($this->isLocked($companyId, $accountingPeriodId)) {
            throw new \RuntimeException('This accounting period is locked, so you cannot ' . trim($actionLabel) . '.');
        }
    }

    public function saveRecalculationSnapshot(
        int $companyId,
        int $accountingPeriodId,
        string $status,
        array $checkRows
    ): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before recalculating this checklist.'],
            ];
        }

        $status = $this->normaliseStatus($status);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $existing = $this->fetchReview($companyId, $accountingPeriodId);

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($existing === null) {
                \InterfaceDB::execute( 'INSERT INTO year_end_reviews (
                        company_id,
                        accounting_period_id,
                        status,
                        is_locked,
                        review_notes,
                        last_recalculated_at,
                        created_at,
                        updated_at
                     ) VALUES (
                        :company_id,
                        :accounting_period_id,
                        :status,
                        0,
                        NULL,
                        :last_recalculated_at,
                        :created_at,
                        :updated_at
                     )', [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'status' => $status,
                    'last_recalculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                \InterfaceDB::execute( 'UPDATE year_end_reviews
                     SET status = :status,
                         last_recalculated_at = :last_recalculated_at,
                         updated_at = :updated_at
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id'
                , [
                    'status' => (int)($existing['is_locked'] ?? 0) === 1 ? 'locked' : $status,
                    'last_recalculated_at' => $now,
                    'updated_at' => $now,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]);
            }

            \InterfaceDB::execute( 'DELETE FROM year_end_check_results
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id'
            , [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]);

            $insertCheckSql = 'INSERT INTO year_end_check_results (
                    company_id,
                    accounting_period_id,
                    check_code,
                    severity,
                    status,
                    title,
                    detail_text,
                    metric_value,
                    action_url,
                    calculated_at
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :check_code,
                    :severity,
                    :status,
                    :title,
                    :detail_text,
                    :metric_value,
                    :action_url,
                    :calculated_at
                 )';

            foreach ($checkRows as $row) {
                \InterfaceDB::execute( $insertCheckSql, [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'check_code' => (string)($row['check_code'] ?? ''),
                    'severity' => $this->normaliseSeverity((string)($row['severity'] ?? 'info')),
                    'status' => $this->normaliseCheckStatus((string)($row['status'] ?? 'pass')),
                    'title' => (string)($row['title'] ?? ''),
                    'detail_text' => trim((string)($row['detail_text'] ?? '')) !== '' ? (string)$row['detail_text'] : null,
                    'metric_value' => trim((string)($row['metric_value'] ?? '')) !== '' ? (string)$row['metric_value'] : null,
                    'action_url' => trim((string)($row['action_url'] ?? '')) !== '' ? (string)$row['action_url'] : null,
                    'calculated_at' => $now,
                ]);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return [
            'success' => true,
            'review' => $this->fetchReview($companyId, $accountingPeriodId),
        ];
    }

    public function saveNotes(int $companyId, int $accountingPeriodId, string $notes, string $changedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before saving notes.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $notes = trim($notes);

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET review_notes = :review_notes,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'review_notes' => $notes !== '' ? $notes : null,
            'updated_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $this->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'notes_changed',
            $changedBy,
            ['review_notes' => $existing['review_notes'] ?? null],
            ['review_notes' => $notes !== '' ? $notes : null]
        );

        return [
            'success' => true,
            'review' => $this->fetchReview($companyId, $accountingPeriodId),
        ];
    }

    public function saveDirectorLoanClosingAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before saving director loan acknowledgements.'],
            ];
        }

        if (!$this->hasReviewColumn('director_loan_closing_acknowledged_at') || !$this->hasReviewColumn('director_loan_closing_acknowledged_by')) {
            return [
                'success' => false,
                'errors' => ['Run the Director Loan year-end acknowledgement migration before saving this acknowledgement.'],
            ];
        }

        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the director loan offset acknowledgement before saving.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET director_loan_closing_acknowledged_at = :acknowledged_at,
                 director_loan_closing_acknowledged_by = :acknowledged_by,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'acknowledged_at' => $now,
            'acknowledged_by' => $this->actorValue($changedBy),
            'updated_at' => $now,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'director_loan_closing_acknowledged', $changedBy, $existing, $review);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function saveTaxReadinessAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before saving tax readiness acknowledgements.'],
            ];
        }

        if (!$this->hasReviewColumn('tax_readiness_acknowledged_at') || !$this->hasReviewColumn('tax_readiness_acknowledged_by')) {
            return [
                'success' => false,
                'errors' => ['Run the Tax Readiness year-end acknowledgement migration before saving this acknowledgement.'],
            ];
        }

        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the tax readiness acknowledgement before saving.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET tax_readiness_acknowledged_at = :acknowledged_at,
                 tax_readiness_acknowledged_by = :acknowledged_by,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'acknowledged_at' => $now,
            'acknowledged_by' => $this->actorValue($changedBy),
            'updated_at' => $now,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'tax_readiness_acknowledged', $changedBy, $existing, $review);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function saveExpensePositionAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before saving expense position acknowledgements.'],
            ];
        }

        if (!$this->hasReviewColumn('expense_position_acknowledged_at') || !$this->hasReviewColumn('expense_position_acknowledged_by')) {
            return [
                'success' => false,
                'errors' => ['Run the Expense Position year-end acknowledgement migration before saving this acknowledgement.'],
            ];
        }

        if (!$acknowledged) {
            return [
                'success' => false,
                'errors' => ['Tick the expense position acknowledgement before saving.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET expense_position_acknowledged_at = :acknowledged_at,
                 expense_position_acknowledged_by = :acknowledged_by,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'acknowledged_at' => $now,
            'acknowledged_by' => $this->actorValue($changedBy),
            'updated_at' => $now,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'expense_position_acknowledged', $changedBy, $existing, $review);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function lockPeriod(int $companyId, int $accountingPeriodId, string $lockedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before locking periods.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET status = :status,
                 is_locked = 1,
                 locked_at = :locked_at,
                 locked_by = :locked_by,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'status' => 'locked',
            'locked_at' => $now,
            'locked_by' => $this->actorValue($lockedBy),
            'updated_at' => $now,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'lock', $lockedBy, $existing, $review);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function unlockPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app', ?string $notes = null): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before unlocking periods.'],
            ];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute( 'UPDATE year_end_reviews
             SET is_locked = 0,
                 locked_at = NULL,
                 locked_by = NULL,
                 status = CASE
                    WHEN status = :locked_status THEN :fallback_status
                    ELSE status
                 END,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id'
        , [
            'locked_status' => 'locked',
            'fallback_status' => 'in_progress',
            'updated_at' => $now,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]);

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'unlock', $changedBy, $existing, $review, $notes);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function writeAuditLog(
        int $companyId,
        int $accountingPeriodId,
        string $action,
        string $actionBy,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $notes = null
    ): void {
        if (!$this->hasAuditLogTable()) {
            return;
        }

        \InterfaceDB::execute( 'INSERT INTO year_end_audit_log (
                company_id,
                accounting_period_id,
                action,
                action_by,
                action_at,
                old_value_json,
                new_value_json,
                notes
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :action,
                :action_by,
                :action_at,
                :old_value_json,
                :new_value_json,
                :notes
             )', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'action' => trim($action) !== '' ? trim($action) : 'unknown',
            'action_by' => $this->actorValue($actionBy),
            'action_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'old_value_json' => $oldValue !== null ? json_encode($oldValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : null,
            'new_value_json' => $newValue !== null ? json_encode($newValue, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);
    }

    private function ensureReviewRow(int $companyId, int $accountingPeriodId): void {
        if ($this->fetchReview($companyId, $accountingPeriodId) !== null) {
            return;
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        \InterfaceDB::execute( 'INSERT INTO year_end_reviews (
                company_id,
                accounting_period_id,
                status,
                is_locked,
                locked_at,
                locked_by,
                review_notes,
                last_recalculated_at,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :status,
                0,
                NULL,
                NULL,
                NULL,
                NULL,
                :created_at,
                :updated_at
             )', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'status' => 'not_started',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function actorValue(string $value): string {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function normaliseStatus(string $status): string {
        $status = trim($status);
        return in_array($status, ['not_started', 'in_progress', 'needs_attention', 'ready_for_review', 'locked'], true)
            ? $status
            : 'not_started';
    }

    private function normaliseSeverity(string $severity): string {
        $severity = trim($severity);
        return in_array($severity, ['info', 'warning', 'fail'], true) ? $severity : 'info';
    }

    private function normaliseCheckStatus(string $status): string {
        $status = trim($status);
        return in_array($status, ['pass', 'warning', 'fail', 'not_applicable'], true) ? $status : 'pass';
    }

    private function hasReviewTable(): bool {
        return $this->tableExists('year_end_reviews');
    }

    private function hasReviewColumn(string $column): bool {
        return $this->hasReviewTable() && \InterfaceDB::columnExists('year_end_reviews', $column);
    }

    private function hasAuditLogTable(): bool {
        return $this->tableExists('year_end_audit_log');
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $cache[$table] = \InterfaceDB::tableExists( $table);

        return $cache[$table];
    }
}


