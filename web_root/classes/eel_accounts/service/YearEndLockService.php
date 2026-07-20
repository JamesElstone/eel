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
    public function fetchReview(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->hasReviewTable()) {
            return null;
        }

        $row = \eel_accounts\Support\RequestCache::remember(
            'year-end-lock.review',
            $companyId . ':' . $accountingPeriodId,
            static fn(): array|false => \InterfaceDB::fetchOne(
                'SELECT id, company_id, accounting_period_id,
                        is_locked, locked_at, locked_by, review_notes,
                        created_at, updated_at
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 LIMIT 1',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            )
        );

        return is_array($row) ? $row : null;
    }

    public function isLocked(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->hasReviewTable()) {
            return false;
        }

        return (bool)\eel_accounts\Support\RequestCache::remember(
            'year-end-lock.is-locked',
            $companyId . ':' . $accountingPeriodId,
            static function () use ($companyId, $accountingPeriodId): bool {
                try {
                    return (int)\InterfaceDB::fetchColumn(
                        'SELECT COALESCE(is_locked, 0)
                         FROM year_end_reviews
                         WHERE company_id = :company_id
                           AND accounting_period_id = :accounting_period_id
                         LIMIT 1',
                        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                    ) === 1;
                } catch (\Throwable) {
                    return false;
                }
            }
        );
    }

    public function assertUnlocked(int $companyId, int $accountingPeriodId, string $actionLabel = 'change this period'): void
    {
        if ($this->isLocked($companyId, $accountingPeriodId)) {
            throw new \RuntimeException('This accounting period is locked, so you cannot ' . trim($actionLabel) . '.');
        }
    }

    public function assertLocked(int $companyId, int $accountingPeriodId, string $actionLabel = 'continue'): void
    {
        if (!$this->isLocked($companyId, $accountingPeriodId)) {
            throw new \RuntimeException(
                'Complete and lock Year End before you can ' . trim($actionLabel) . '.'
            );
        }
    }

    public function assertUnlockedForUpdate(int $companyId, int $accountingPeriodId, string $actionLabel = 'change this period'): void
    {
        if (!\InterfaceDB::inTransaction()) {
            throw new \RuntimeException('The accounting-period lock can only be secured inside a transaction.');
        }
        if (!$this->hasReviewTable()) {
            throw new \RuntimeException('Run the Year End review migration before changing accounting periods.');
        }
        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $sql = 'SELECT COALESCE(is_locked, 0)
                FROM year_end_reviews
                WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                LIMIT 1';
        if (\InterfaceDB::driverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $locked = (int)\InterfaceDB::fetchColumn($sql, [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ]) === 1;
        if ($locked) {
            throw new \RuntimeException('This accounting period is locked, so you cannot ' . trim($actionLabel) . '.');
        }
    }

    public function saveNotes(int $companyId, int $accountingPeriodId, string $notes, string $changedBy = 'web_app'): array
    {
        $this->assertYearEndSupported($companyId, 'save Year End notes');
        $this->assertUnlocked($companyId, $accountingPeriodId, 'change the year-end notes for this period');
        if (!$this->hasReviewTable()) {
            return ['success' => false, 'errors' => ['Run the Year End review migration before saving notes.']];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $notes = trim($notes);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute(
            'UPDATE year_end_reviews
             SET review_notes = :review_notes, updated_at = :updated_at
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            [
                'review_notes' => $notes !== '' ? $notes : null,
                'updated_at' => $now,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        $this->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'notes_changed',
            $changedBy,
            ['review_notes' => $existing['review_notes'] ?? null],
            ['review_notes' => $notes !== '' ? $notes : null]
        );

        return ['success' => true, 'review' => $this->fetchReview($companyId, $accountingPeriodId)];
    }

    public function lockPeriod(int $companyId, int $accountingPeriodId, string $lockedBy = 'web_app'): array
    {
        $this->assertYearEndSupported($companyId, 'lock an accounting period');
        if (!$this->hasReviewTable()) {
            return ['success' => false, 'errors' => ['Run the Year End review migration before locking periods.']];
        }
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            $this->ensureReviewRow($companyId, $accountingPeriodId);
            $existing = $this->fetchReview($companyId, $accountingPeriodId);
            if (!empty($existing['is_locked'])) {
                if ($ownsTransaction) {
                    \InterfaceDB::commit();
                }
                return ['success' => true, 'review' => $existing, 'no_op' => true];
            }
            $this->assertUnlockedForUpdate($companyId, $accountingPeriodId, 'lock this accounting period');

            $prepayments = (new PrepaymentPostingService())->validateForFinalLock($companyId, $accountingPeriodId);
            if (empty($prepayments['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return [
                    'success' => false,
                    'errors' => (array)($prepayments['errors'] ?? ['Prepayment postings failed final validation.']),
                    'prepayments' => $prepayments,
                ];
            }

            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            \InterfaceDB::execute(
                'UPDATE year_end_reviews
                 SET is_locked = 1, locked_at = :locked_at, locked_by = :locked_by, updated_at = :updated_at
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                [
                    'locked_at' => $now,
                    'locked_by' => $this->actorValue($lockedBy),
                    'updated_at' => $now,
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ]
            );
            \eel_accounts\Support\RequestCache::clear();
            $review = $this->fetchReview($companyId, $accountingPeriodId);
            $this->writeAuditLog($companyId, $accountingPeriodId, 'lock', $lockedBy, $existing, $review);
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
            return ['success' => true, 'review' => $review, 'prepayments' => $prepayments];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function unlockPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app', ?string $notes = null): array
    {
        $this->assertYearEndSupported($companyId, 'unlock an accounting period');
        if (!$this->hasReviewTable()) {
            return ['success' => false, 'errors' => ['Run the Year End review migration before unlocking periods.']];
        }

        $this->ensureReviewRow($companyId, $accountingPeriodId);
        $existing = $this->fetchReview($companyId, $accountingPeriodId);
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        \InterfaceDB::execute(
            'UPDATE year_end_reviews
             SET is_locked = 0, locked_at = NULL, locked_by = NULL, updated_at = :updated_at
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['updated_at' => $now, 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        \eel_accounts\Support\RequestCache::clear();
        if (\InterfaceDB::tableExists('corporation_tax_computation_runs')) {
            try {
                \InterfaceDB::execute(
                    'UPDATE corporation_tax_computation_runs SET ixbrl_status = :status
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                       AND generated_path IS NOT NULL',
                    ['status' => 'stale', 'company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                );
            } catch (\Throwable) {
                // Deployments apply the CT iXBRL migration independently; runtime freshness still fails closed.
            }
        }

        $review = $this->fetchReview($companyId, $accountingPeriodId);
        $this->writeAuditLog($companyId, $accountingPeriodId, 'unlock', $changedBy, $existing, $review, $notes);

        return [
            'success' => true,
            'review' => $review,
            'retained_earnings_close' => ['success' => true, 'deleted' => false, 'skipped' => true],
        ];
    }

    public function writeAuditLog(
        int $companyId,
        int $accountingPeriodId,
        string $action,
        string $actionBy,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $notes = null,
        bool $supportScopeVerified = false
    ): void {
        $normalisedAction = trim($action) !== '' ? trim($action) : 'unknown';
        // Manual journals are ordinary bookkeeping and remain supported for a
        // LIVE-confirmed VAT company. Their audit rows share this table, while
        // every genuine Year End caller is read-only in unsupported VAT scope.
        if (!$supportScopeVerified && !in_array($normalisedAction, ['journal_created', 'journal_appended'], true)) {
            $this->assertYearEndSupported($companyId, 'write a Year End audit entry');
        }

        if (!$this->tableExists('year_end_audit_log')) {
            return;
        }

        \InterfaceDB::execute(
            'INSERT INTO year_end_audit_log (
                company_id, accounting_period_id, action, action_by, action_at,
                old_value_json, new_value_json, notes
             ) VALUES (
                :company_id, :accounting_period_id, :action, :action_by, :action_at,
                :old_value_json, :new_value_json, :notes
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'action' => $normalisedAction,
                'action_by' => $this->actorValue($actionBy),
                'action_at' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                'old_value_json' => $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'new_value_json' => $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            ]
        );
    }

    private function assertYearEndSupported(int $companyId, string $actionLabel): void
    {
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, $actionLabel);
    }

    private function ensureReviewRow(int $companyId, int $accountingPeriodId): void
    {
        if ($this->fetchReview($companyId, $accountingPeriodId) !== null) {
            return;
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        \InterfaceDB::execute(
            'INSERT INTO year_end_reviews (
                company_id, accounting_period_id, is_locked,
                locked_at, locked_by, review_notes, created_at, updated_at
             ) VALUES (
                :company_id, :accounting_period_id, 0,
                NULL, NULL, NULL, :created_at, :updated_at
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function actorValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function hasReviewTable(): bool
    {
        return $this->tableExists('year_end_reviews');
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
