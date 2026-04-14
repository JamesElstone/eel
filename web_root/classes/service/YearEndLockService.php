<?php
declare(strict_types=1);

final class YearEndLockService
{
    public function fetchReview(int $companyId, int $taxYearId): ?array {
        if ($companyId <= 0 || $taxYearId <= 0 || !$this->hasReviewTable()) {
            return null;
        }

        $row = InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    tax_year_id,
                    status,
                    is_locked,
                    locked_at,
                    locked_by,
                    review_notes,
                    last_recalculated_at,
                    created_at,
                    updated_at
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id
             LIMIT 1', [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);
        return is_array($row) ? $row : null;
    }

    public function isLocked(int $companyId, int $taxYearId): bool {
        $review = $this->fetchReview($companyId, $taxYearId);

        return is_array($review) && (int)($review['is_locked'] ?? 0) === 1;
    }

    public function assertUnlocked(int $companyId, int $taxYearId, string $actionLabel = 'change this period'): void {
        if ($this->isLocked($companyId, $taxYearId)) {
            throw new RuntimeException('This accounting period is locked, so you cannot ' . trim($actionLabel) . '.');
        }
    }

    public function saveRecalculationSnapshot(
        int $companyId,
        int $taxYearId,
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
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $existing = $this->fetchReview($companyId, $taxYearId);

        $ownsTransaction = !InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            if ($existing === null) {
                InterfaceDB::execute( 'INSERT INTO year_end_reviews (
                        company_id,
                        tax_year_id,
                        status,
                        is_locked,
                        review_notes,
                        last_recalculated_at,
                        created_at,
                        updated_at
                     ) VALUES (
                        :company_id,
                        :tax_year_id,
                        :status,
                        0,
                        NULL,
                        :last_recalculated_at,
                        :created_at,
                        :updated_at
                     )', [
                    'company_id' => $companyId,
                    'tax_year_id' => $taxYearId,
                    'status' => $status,
                    'last_recalculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                InterfaceDB::execute( 'UPDATE year_end_reviews
                     SET status = :status,
                         last_recalculated_at = :last_recalculated_at,
                         updated_at = :updated_at
                     WHERE company_id = :company_id
                       AND tax_year_id = :tax_year_id'
                , [
                    'status' => (int)($existing['is_locked'] ?? 0) === 1 ? 'locked' : $status,
                    'last_recalculated_at' => $now,
                    'updated_at' => $now,
                    'company_id' => $companyId,
                    'tax_year_id' => $taxYearId,
                ]);
            }

            InterfaceDB::execute( 'DELETE FROM year_end_check_results
                 WHERE company_id = :company_id
                   AND tax_year_id = :tax_year_id'
            , [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ]);

            $insertCheckSql = 'INSERT INTO year_end_check_results (
                    company_id,
                    tax_year_id,
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
                    :tax_year_id,
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
                InterfaceDB::execute( $insertCheckSql, [
                    'company_id' => $companyId,
                    'tax_year_id' => $taxYearId,
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
                InterfaceDB::commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return [
            'success' => true,
            'review' => $this->fetchReview($companyId, $taxYearId),
        ];
    }

    public function saveNotes(int $companyId, int $taxYearId, string $notes, string $changedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before saving notes.'],
            ];
        }

        $this->ensureReviewRow($companyId, $taxYearId);
        $existing = $this->fetchReview($companyId, $taxYearId);
        $notes = trim($notes);

        InterfaceDB::execute( 'UPDATE year_end_reviews
             SET review_notes = :review_notes,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id'
        , [
            'review_notes' => $notes !== '' ? $notes : null,
            'updated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $this->writeAuditLog(
            $companyId,
            $taxYearId,
            'notes_changed',
            $changedBy,
            ['review_notes' => $existing['review_notes'] ?? null],
            ['review_notes' => $notes !== '' ? $notes : null]
        );

        return [
            'success' => true,
            'review' => $this->fetchReview($companyId, $taxYearId),
        ];
    }

    public function lockPeriod(int $companyId, int $taxYearId, string $lockedBy = 'web_app'): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before locking periods.'],
            ];
        }

        $this->ensureReviewRow($companyId, $taxYearId);
        $existing = $this->fetchReview($companyId, $taxYearId);
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        InterfaceDB::execute( 'UPDATE year_end_reviews
             SET status = :status,
                 is_locked = 1,
                 locked_at = :locked_at,
                 locked_by = :locked_by,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id'
        , [
            'status' => 'locked',
            'locked_at' => $now,
            'locked_by' => $this->actorValue($lockedBy),
            'updated_at' => $now,
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $review = $this->fetchReview($companyId, $taxYearId);
        $this->writeAuditLog($companyId, $taxYearId, 'lock', $lockedBy, $existing, $review);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function unlockPeriod(int $companyId, int $taxYearId, string $changedBy = 'web_app', ?string $notes = null): array {
        if (!$this->hasReviewTable()) {
            return [
                'success' => false,
                'errors' => ['Run the Year End review migration before unlocking periods.'],
            ];
        }

        $this->ensureReviewRow($companyId, $taxYearId);
        $existing = $this->fetchReview($companyId, $taxYearId);
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        InterfaceDB::execute( 'UPDATE year_end_reviews
             SET is_locked = 0,
                 locked_at = NULL,
                 locked_by = NULL,
                 status = CASE
                    WHEN status = :locked_status THEN :fallback_status
                    ELSE status
                 END,
                 updated_at = :updated_at
             WHERE company_id = :company_id
               AND tax_year_id = :tax_year_id'
        , [
            'locked_status' => 'locked',
            'fallback_status' => 'in_progress',
            'updated_at' => $now,
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $review = $this->fetchReview($companyId, $taxYearId);
        $this->writeAuditLog($companyId, $taxYearId, 'unlock', $changedBy, $existing, $review, $notes);

        return [
            'success' => true,
            'review' => $review,
        ];
    }

    public function writeAuditLog(
        int $companyId,
        int $taxYearId,
        string $action,
        string $actionBy,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $notes = null
    ): void {
        if (!$this->hasAuditLogTable()) {
            return;
        }

        InterfaceDB::execute( 'INSERT INTO year_end_audit_log (
                company_id,
                tax_year_id,
                action,
                action_by,
                action_at,
                old_value_json,
                new_value_json,
                notes
             ) VALUES (
                :company_id,
                :tax_year_id,
                :action,
                :action_by,
                :action_at,
                :old_value_json,
                :new_value_json,
                :notes
             )', [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'action' => trim($action) !== '' ? trim($action) : 'unknown',
            'action_by' => $this->actorValue($actionBy),
            'action_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'old_value_json' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'new_value_json' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ]);
    }

    private function ensureReviewRow(int $companyId, int $taxYearId): void {
        if ($this->fetchReview($companyId, $taxYearId) !== null) {
            return;
        }

        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        InterfaceDB::execute( 'INSERT INTO year_end_reviews (
                company_id,
                tax_year_id,
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
                :tax_year_id,
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
            'tax_year_id' => $taxYearId,
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

    private function hasAuditLogTable(): bool {
        return $this->tableExists('year_end_audit_log');
    }

    private function tableExists(string $table): bool {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $cache[$table] = InterfaceDB::tableExists( $table);

        return $cache[$table];
    }
}


