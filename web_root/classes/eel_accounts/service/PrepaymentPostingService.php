<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentPostingService
{
    private const APPROVAL_CHECK_CODE = 'prepayment_approvals';

    public function __construct(
        private readonly ?PrepaymentScheduleService $scheduleService = null,
        private readonly ?ManualJournalService $journalService = null,
        private readonly ?YearEndLockService $lockService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function validateForYearEndLock(int $companyId, int $accountingPeriodId): array
    {
        return $this->validateState($companyId, $accountingPeriodId, true, false, false);
    }

    /**
     * Final-state validation used by the low-level period lock. This never
     * creates journals or synchronises schedules: the normal Year End close
     * must already have posted every required adjustment.
     *
     * @return array<string, mixed>
     */
    public function validateForFinalLock(int $companyId, int $accountingPeriodId): array
    {
        return $this->validateState($companyId, $accountingPeriodId, false, true, true);
    }

    /** @return array{success: bool, errors: list<string>} */
    public function verifyJournalEvidence(int $companyId, int $accountingPeriodId, int $journalId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $journalId <= 0) {
            return ['success' => false, 'errors' => ['The prepayment journal evidence context is invalid.']];
        }

        if (!($this->scheduleService ?? new PrepaymentScheduleService())->hasSchema()
            || !\InterfaceDB::tableExists('journal_entry_metadata')) {
            return ['success' => false, 'errors' => ['The automated prepayment posting schema is unavailable.']];
        }

        $posting = \InterfaceDB::fetchOne(
            'SELECT ps.review_id
             FROM prepayment_schedule_postings psp
             INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id
             WHERE ps.company_id = :company_id
               AND psp.accounting_period_id = :accounting_period_id
               AND psp.journal_id = :journal_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'journal_id' => $journalId,
            ]
        );
        if (!is_array($posting)) {
            return ['success' => false, 'errors' => ['The posted journal is not linked to an automated prepayment schedule.']];
        }

        $errors = $this->postingIntegrityErrors((int)($posting['review_id'] ?? 0), $journalId);

        return [
            'success' => $errors === [],
            'errors' => array_values(array_unique(array_filter(array_map('strval', $errors)))),
        ];
    }

    /** @return array<string, mixed> */
    private function validateState(
        int $companyId,
        int $accountingPeriodId,
        bool $synchronise,
        bool $lockRows,
        bool $requireZeroAdjustments
    ): array {
        $schedules = $this->scheduleService ?? new PrepaymentScheduleService();
        if (!$schedules->hasSchema()) {
            return ['success' => false, 'errors' => ['Run the automated prepayment schedules migration before closing Year End.'], 'schedules' => [], 'adjustments' => []];
        }

        $errors = [];
        if ($synchronise) {
            $sync = $schedules->syncForAccountingPeriod($companyId, $accountingPeriodId, 'year_end_validation');
            $errors = (array)($sync['errors'] ?? []);
        }
        $approvalContext = (new PrepaymentApprovalContextService())->fetchContext($companyId, $accountingPeriodId);
        $review = (array)($approvalContext['review'] ?? []);
        $approval = $approvalContext['approval'] ?? null;
        if (empty($review['available'])) {
            $errors[] = (string)(($review['errors'] ?? [])[0] ?? 'Prepayment review is not available.');
        } elseif ((int)($review['pending_count'] ?? 0) > 0) {
            $errors[] = 'Complete every prepayment candidate decision before closing Year End.';
        }
        $needsApproval = (int)($review['total_count'] ?? 0) > 0
            || (int)($review['carried_schedule_count'] ?? 0) > 0;
        if ($needsApproval && (!is_array($approval) || empty($approval['current']))) {
            $errors[] = 'Approve the current prepayment calculation on the Prepayments Year End Confirmation tab before closing Year End.';
        }

        $integrity = $schedules->validatePeriodIntegrity($companyId, $accountingPeriodId, $lockRows);
        $errors = array_merge($errors, (array)($integrity['errors'] ?? []));
        $context = $schedules->fetchPeriodContext($companyId, $accountingPeriodId);
        $errors = array_merge($errors, (array)($context['errors'] ?? []));
        $validatedPostingReviews = [];
        foreach ((array)($context['schedules'] ?? []) as $schedule) {
            if (!empty($schedule['preview_only'])) {
                $errors[] = 'Prepayment review #' . (int)($schedule['review_id'] ?? 0)
                    . ' is still a preview-only calculation and must be synchronised before posting.';
                continue;
            }
            if (empty($schedule['source_valid'])) {
                $errors = array_merge($errors, (array)($schedule['source_errors'] ?? ['A prepayment source is no longer valid.']));
            }
            if (!$this->continuousThroughSelectedPeriod($schedule)) {
                $errors[] = 'Prepayment schedule #' . (int)$schedule['id'] . ' has a missing accounting-period allocation before or within the selected period.';
            }
            $reviewId = (int)$schedule['review_id'];
            if (!isset($validatedPostingReviews[$reviewId])) {
                $errors = array_merge($errors, $this->postingIntegrityErrors($reviewId));
                $validatedPostingReviews[$reviewId] = true;
            }
        }

        $adjustments = $schedules->fetchPreviewAdjustments($companyId, $accountingPeriodId);
        if ($requireZeroAdjustments && $adjustments !== []) {
            $errors[] = 'Prepayment journals are not at their exact calculated targets; use the normal Year End close to post them before locking this period.';
        }
        $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));
        return [
            'success' => $errors === [],
            'errors' => $errors,
            'schedules' => (array)($context['schedules'] ?? []),
            'adjustments' => $adjustments,
        ];
    }

    /** @return array<string, mixed> */
    public function postForAccountingPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $changedBy = 'year_end_close'
    ): array {
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        $journalIds = [];

        try {
            ($this->lockService ?? new YearEndLockService())->assertUnlockedForUpdate(
                $companyId,
                $accountingPeriodId,
                'post prepayment journals'
            );

            // First synchronise and validate inside the transaction. Then lock
            // the resulting graph and rebuild it again before deriving deltas.
            $initialValidation = $this->validateState($companyId, $accountingPeriodId, true, false, false);
            if (empty($initialValidation['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return array_merge($initialValidation, ['posted_count' => 0, 'journal_ids' => []]);
            }
            $validation = $this->validateState($companyId, $accountingPeriodId, false, true, false);
            if (empty($validation['success'])) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
                return array_merge($validation, ['posted_count' => 0, 'journal_ids' => []]);
            }
            $adjustments = (array)$validation['adjustments'];
            if ($adjustments === []) {
                if ($ownsTransaction) {
                    \InterfaceDB::commit();
                }
                \eel_accounts\Support\RequestCache::clear();
                return [
                    'success' => true,
                    'errors' => [],
                    'posted_count' => 0,
                    'journal_ids' => [],
                    'adjustments' => [],
                    'no_op' => true,
                ];
            }

            foreach ($adjustments as $adjustment) {
                if (!empty($adjustment['preview_only'])
                    || (int)($adjustment['schedule_id'] ?? 0) <= 0
                    || (int)($adjustment['schedule_period_id'] ?? 0) <= 0) {
                    throw new \RuntimeException('A preview-only prepayment adjustment cannot be posted until its schedule has been synchronised.');
                }
                $this->lockSchedulePeriod((int)$adjustment['schedule_period_id']);
                $role = (string)$adjustment['posting_role'];
                $currentNet = ($this->scheduleService ?? new PrepaymentScheduleService())->netPostedForReviewPeriod(
                    (int)$adjustment['review_id'],
                    $accountingPeriodId,
                    $role
                );
                $delta = (int)$adjustment['target_pence'] - $currentNet;
                if ($delta === 0) {
                    continue;
                }
                $result = $this->postEffect(
                    $companyId,
                    $accountingPeriodId,
                    $adjustment,
                    $delta,
                    $currentNet === 0 ? $role : 'correction',
                    $changedBy
                );
                $journalIds[] = (int)$result['journal_id'];
                // The final target check must rebuild every ledger-derived read
                // model after this journal and its schedule evidence are saved.
                // In the atomic Year End close the request cache is active, so
                // leaving the pre-posting preview cached makes a successful post
                // appear to have the same outstanding delta and rolls it back.
                \eel_accounts\Support\RequestCache::clear();
            }

            $finalValidation = $this->validateState($companyId, $accountingPeriodId, false, true, true);
            if (empty($finalValidation['success'])) {
                throw new \RuntimeException((string)(($finalValidation['errors'] ?? [])[0]
                    ?? 'Prepayment postings did not reach their final calculated targets.'));
            }
            foreach (array_values(array_unique(array_map(
                static fn(array $row): int => (int)$row['schedule_id'],
                $adjustments
            ))) as $scheduleId) {
                $this->refreshCompletionStatus($scheduleId);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
            \eel_accounts\Support\RequestCache::clear();
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()], 'posted_count' => 0, 'journal_ids' => []];
        }

        return [
            'success' => true,
            'errors' => [],
            'posted_count' => count($journalIds),
            'journal_ids' => $journalIds,
            'adjustments' => $adjustments ?? [],
            'no_op' => $journalIds === [],
        ];
    }

    /** @return array<string, mixed> */
    public function reopenSchedule(int $companyId, int $reviewId, string $changedBy = 'web_app'): array
    {
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        $journalIds = [];
        try {
            $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
            $review = \InterfaceDB::fetchOne(
                'SELECT id, company_id, accounting_period_id, current_schedule_id
                 FROM prepayment_reviews
                 WHERE id = :id AND company_id = :company_id
                 LIMIT 1' . $suffix,
                ['id' => $reviewId, 'company_id' => $companyId]
            );
            if (!is_array($review) || (int)($review['current_schedule_id'] ?? 0) <= 0) {
                throw new \RuntimeException('The active prepayment schedule could not be found.');
            }
            $schedules = $this->scheduleService ?? new PrepaymentScheduleService();
            $snapshot = $schedules->validateStoredSnapshotIntegrity((int)$review['current_schedule_id'], true);
            if (empty($snapshot['success']) || !is_array($snapshot['schedule'] ?? null)) {
                throw new \RuntimeException((string)(($snapshot['errors'] ?? [])[0] ?? 'The active prepayment schedule could not be loaded.'));
            }
            $schedule = (array)$snapshot['schedule'];
            $postingErrors = $this->postingIntegrityErrors($reviewId);
            if ($postingErrors !== []) {
                throw new \RuntimeException($postingErrors[0]);
            }

            // Every current allocation and every period ever touched by an
            // append-only posting is affected, even when historical effects
            // currently net to zero.
            $affectedPeriods = \InterfaceDB::fetchAll(
                'SELECT DISTINCT affected.accounting_period_id
                 FROM (
                    SELECT psp.accounting_period_id
                    FROM prepayment_schedule_periods psp
                    WHERE psp.schedule_id = :schedule_id
                    UNION
                    SELECT posting.accounting_period_id
                    FROM prepayment_schedule_postings posting
                    INNER JOIN prepayment_schedules ps ON ps.id = posting.schedule_id
                    WHERE ps.review_id = :review_id
                 ) affected
                 ORDER BY affected.accounting_period_id',
                ['schedule_id' => (int)$schedule['id'], 'review_id' => $reviewId]
            );
            foreach ($affectedPeriods as $period) {
                ($this->lockService ?? new YearEndLockService())->assertUnlockedForUpdate(
                    $companyId,
                    (int)$period['accounting_period_id'],
                    'reopen this prepayment schedule'
                );
            }

            $nets = $schedules->fetchPostingNetsForReview($reviewId);
            foreach ($nets as $net) {
                $accountingPeriodId = (int)$net['accounting_period_id'];
                $role = (string)$net['posting_role'];
                $allocation = $this->allocationForPeriod($schedule, $accountingPeriodId);
                if (!is_array($allocation)) {
                    throw new \RuntimeException('The active schedule is missing an allocation needed to compensate posted history.');
                }
                $effect = -((int)$net['net_effect_pence']);
                if ($effect === 0) {
                    continue;
                }
                $adjustment = [
                    'review_id' => $reviewId,
                    'schedule_id' => (int)$schedule['id'],
                    'schedule_period_id' => (int)$allocation['id'],
                    'accounting_period_id' => $accountingPeriodId,
                    'posting_role' => $role,
                    'target_pence' => 0,
                    'asset_nominal_id' => (int)$schedule['asset_nominal_id'],
                    'original_expense_nominal_id' => (int)$schedule['original_expense_nominal_id'],
                    'journal_date' => $role === 'deferral' ? (string)$allocation['period_end'] : (string)$allocation['overlap_start'],
                    'calculation_hash' => (string)$schedule['calculation_hash'],
                ];
                $result = $this->postEffect(
                    $companyId,
                    $accountingPeriodId,
                    $adjustment,
                    $effect,
                    'reopen_compensation',
                    $changedBy
                );
                $journalIds[] = (int)$result['journal_id'];
            }

            if ($schedules->fetchPostingNetsForReview($reviewId) !== []) {
                throw new \RuntimeException('The compensating journals did not return the prepayment posting history to zero.');
            }
            $postingErrors = $this->postingIntegrityErrors($reviewId);
            if ($postingErrors !== []) {
                throw new \RuntimeException($postingErrors[0]);
            }
            \InterfaceDB::execute(
                'UPDATE prepayment_schedules SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['status' => 'needs_review', 'id' => (int)$schedule['id']]
            );
            \InterfaceDB::execute(
                'UPDATE prepayment_reviews SET current_schedule_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['id' => $reviewId]
            );
            (new YearEndAcknowledgementService())->revoke(
                $companyId,
                (int)$review['accounting_period_id'],
                self::APPROVAL_CHECK_CODE,
                true
            );
            ($this->lockService ?? new YearEndLockService())->writeAuditLog(
                $companyId,
                (int)$review['accounting_period_id'],
                'prepayment_schedule_reopened',
                $changedBy,
                ['schedule_id' => (int)$schedule['id']],
                ['review_id' => $reviewId, 'compensating_journal_ids' => $journalIds]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
            \eel_accounts\Support\RequestCache::clear();
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()], 'journal_ids' => []];
        }

        return ['success' => true, 'errors' => [], 'journal_ids' => $journalIds, 'reopened' => true];
    }

    /** @return array{journal_id: int} */
    private function postEffect(
        int $companyId,
        int $accountingPeriodId,
        array $adjustment,
        int $effectPence,
        string $postingType,
        string $changedBy
    ): array {
        $role = (string)$adjustment['posting_role'];
        $assetNominalId = (int)($adjustment['asset_nominal_id'] ?? 0);
        $expenseNominalId = (int)($adjustment['original_expense_nominal_id'] ?? 0);
        if ($assetNominalId <= 0 || $expenseNominalId <= 0) {
            $schedule = ($this->scheduleService ?? new PrepaymentScheduleService())->fetchSchedule((int)$adjustment['schedule_id']);
            if (!is_array($schedule)) {
                throw new \RuntimeException('The prepayment nominals could not be resolved for posting.');
            }
            $assetNominalId = (int)$schedule['asset_nominal_id'];
            $expenseNominalId = (int)$schedule['original_expense_nominal_id'];
        }
        $normalDebit = $role === 'deferral' ? $assetNominalId : $expenseNominalId;
        $normalCredit = $role === 'deferral' ? $expenseNominalId : $assetNominalId;
        $debitNominalId = $effectPence > 0 ? $normalDebit : $normalCredit;
        $creditNominalId = $effectPence > 0 ? $normalCredit : $normalDebit;
        $amount = abs($effectPence) / 100;
        $tag = in_array($postingType, ['deferral', 'release'], true)
            ? 'prepayment_' . $postingType
            : 'prepayment_correction';
        $journalKey = 'review:' . (int)$adjustment['review_id']
            . ':period:' . $accountingPeriodId . ':role:' . $role;
        $description = match ($postingType) {
            'deferral' => 'Prepayment deferral',
            'release' => 'Prepayment release',
            'reopen_compensation' => 'Prepayment reopen compensation',
            default => 'Prepayment correction',
        } . ' (review ' . (int)$adjustment['review_id'] . ')';

        $result = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            $tag,
            $journalKey,
            (string)$adjustment['journal_date'],
            $description,
            [
                ['nominal_account_id' => $debitNominalId, 'debit' => $amount, 'credit' => 0, 'line_description' => $description],
                ['nominal_account_id' => $creditNominalId, 'debit' => 0, 'credit' => $amount, 'line_description' => $description],
            ],
            'system_generated',
            null,
            null,
            'Append-only prepayment schedule posting; effect ' . $effectPence . ' pence.',
            $changedBy
        );
        if (empty($result['success']) || !is_array($result['journal'] ?? null)) {
            throw new \RuntimeException((string)(($result['errors'] ?? [])[0] ?? 'The prepayment journal could not be posted.'));
        }
        $journalId = (int)$result['journal']['id'];
        \InterfaceDB::execute(
            'INSERT INTO prepayment_schedule_postings (
                schedule_id, schedule_period_id, accounting_period_id, journal_id,
                posting_role, posting_type, effect_pence, target_pence,
                calculation_hash, created_by, created_at
             ) VALUES (
                :schedule_id, :schedule_period_id, :accounting_period_id, :journal_id,
                :posting_role, :posting_type, :effect_pence, :target_pence,
                :calculation_hash, :created_by, CURRENT_TIMESTAMP
             )',
            [
                'schedule_id' => (int)$adjustment['schedule_id'],
                'schedule_period_id' => (int)$adjustment['schedule_period_id'],
                'accounting_period_id' => $accountingPeriodId,
                'journal_id' => $journalId,
                'posting_role' => $role,
                'posting_type' => $postingType,
                'effect_pence' => $effectPence,
                'target_pence' => max(0, (int)$adjustment['target_pence']),
                'calculation_hash' => (string)$adjustment['calculation_hash'],
                'created_by' => trim($changedBy) !== '' ? trim($changedBy) : 'web_app',
            ]
        );
        if ($postingType === 'deferral') {
            \InterfaceDB::execute(
                'UPDATE prepayment_reviews SET generated_journal_id = COALESCE(generated_journal_id, :journal_id) WHERE id = :id',
                ['journal_id' => $journalId, 'id' => (int)$adjustment['review_id']]
            );
        } elseif ($postingType === 'release') {
            \InterfaceDB::execute(
                'UPDATE prepayment_reviews SET reversal_journal_id = COALESCE(reversal_journal_id, :journal_id) WHERE id = :id',
                ['journal_id' => $journalId, 'id' => (int)$adjustment['review_id']]
            );
        }

        return ['journal_id' => $journalId];
    }

    /** @return list<string> */
    private function postingIntegrityErrors(int $reviewId, ?int $journalId = null): array
    {
        if (!\InterfaceDB::tableExists('journal_entry_metadata')) {
            return ['Journal metadata is required for automated prepayment postings.'];
        }
        $journalCondition = $journalId !== null && $journalId > 0
            ? ' AND psp.journal_id = :journal_id'
            : '';
        $params = ['review_id' => $reviewId];
        if ($journalCondition !== '') {
            $params['journal_id'] = $journalId;
        }
        $rows = \InterfaceDB::fetchAll(
            'SELECT psp.id AS posting_id, psp.schedule_id, psp.schedule_period_id,
                    psp.accounting_period_id, psp.journal_id, psp.posting_role,
                    psp.posting_type, psp.effect_pence, psp.target_pence,
                    psp.calculation_hash AS posting_calculation_hash,
                    ps.company_id, ps.calculation_hash AS schedule_calculation_hash,
                    ps.asset_nominal_id, ps.original_expense_nominal_id,
                    allocation.schedule_id AS allocation_schedule_id,
                    allocation.accounting_period_id AS allocation_accounting_period_id,
                    allocation.is_source_period,
                    allocation.period_end, allocation.overlap_start,
                    allocation.expense_pence, allocation.closing_deferred_pence,
                    j.company_id AS journal_company_id,
                    j.accounting_period_id AS journal_accounting_period_id,
                    j.journal_date, j.is_posted, j.source_type,
                    COALESCE(jem.entry_mode, \'\') AS entry_mode,
                    COALESCE(jem.journal_tag, \'\') AS journal_tag
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings psp ON psp.schedule_id = ps.id
             LEFT JOIN prepayment_schedule_periods allocation ON allocation.id = psp.schedule_period_id
             LEFT JOIN journals j ON j.id = psp.journal_id
             LEFT JOIN journal_entry_metadata jem ON jem.journal_id = j.id
             WHERE ps.review_id = :review_id
             ' . $journalCondition . '
             ORDER BY psp.id',
            $params
        );
        $errors = [];
        $scheduleIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int)$row['schedule_id'],
            $rows
        )));
        foreach ($scheduleIds as $scheduleId) {
            $snapshot = ($this->scheduleService ?? new PrepaymentScheduleService())
                ->validateStoredSnapshotIntegrity($scheduleId);
            $errors = array_merge($errors, (array)($snapshot['errors'] ?? []));
        }
        foreach ($rows as $row) {
            $postingId = (int)$row['posting_id'];
            $journalId = (int)$row['journal_id'];
            $role = (string)$row['posting_role'];
            $type = (string)$row['posting_type'];
            if (!in_array($role, ['deferral', 'release'], true)) {
                $errors[] = 'Prepayment posting #' . $postingId . ' has an invalid posting role.';
                continue;
            }
            if ((int)$row['allocation_schedule_id'] !== (int)$row['schedule_id']
                || (int)$row['allocation_accounting_period_id'] !== (int)$row['accounting_period_id']) {
                $errors[] = 'Prepayment posting #' . $postingId . ' is linked to an allocation from the wrong schedule or accounting period.';
            }
            $expectedRole = !empty($row['is_source_period']) ? 'deferral' : 'release';
            if ($role !== $expectedRole) {
                $errors[] = 'Prepayment posting #' . $postingId . ' has a role which does not match its source-period allocation flag.';
            }
            if (!hash_equals((string)$row['schedule_calculation_hash'], (string)$row['posting_calculation_hash'])) {
                $errors[] = 'Prepayment posting #' . $postingId . ' does not carry its linked schedule calculation hash.';
            }
            $expectedTag = in_array($type, ['deferral', 'release'], true)
                ? 'prepayment_' . $type
                : 'prepayment_correction';
            if (($type === 'deferral' && $role !== 'deferral')
                || ($type === 'release' && $role !== 'release')
                || !in_array($type, ['deferral', 'release', 'correction', 'reopen_compensation'], true)) {
                $errors[] = 'Prepayment posting #' . $postingId . ' has an invalid posting type/role combination.';
            }
            $expectedDate = $role === 'deferral'
                ? (string)$row['period_end']
                : (string)$row['overlap_start'];
            if ((int)$row['journal_company_id'] !== (int)$row['company_id']
                || (int)$row['journal_accounting_period_id'] !== (int)$row['accounting_period_id']
                || (string)$row['journal_date'] !== $expectedDate) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' is recorded against the wrong company, accounting period or journal date.';
            }
            if (empty($row['is_posted'])) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' is not posted.';
            }
            $expectedAmount = abs((int)$row['effect_pence']) / 100;
            if ((string)$row['source_type'] !== 'manual'
                || (string)$row['entry_mode'] !== 'system_generated'
                || (string)$row['journal_tag'] !== $expectedTag) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' is missing its exact manual/system-generated prepayment metadata.';
            }
            $allocationTarget = $role === 'deferral'
                ? (int)$row['closing_deferred_pence']
                : (int)$row['expense_pence'];
            if (in_array($type, ['deferral', 'release'], true) && (int)$row['target_pence'] !== $allocationTarget) {
                $errors[] = 'Prepayment posting #' . $postingId . ' does not record the allocation target used by its schedule version.';
            }
            if ($type === 'reopen_compensation' && (int)$row['target_pence'] !== 0) {
                $errors[] = 'Prepayment reopen posting #' . $postingId . ' must record a zero target.';
            }

            $normalDebit = $role === 'deferral'
                ? (int)$row['asset_nominal_id']
                : (int)$row['original_expense_nominal_id'];
            $normalCredit = $role === 'deferral'
                ? (int)$row['original_expense_nominal_id']
                : (int)$row['asset_nominal_id'];
            $expectedDebit = (int)$row['effect_pence'] > 0 ? $normalDebit : $normalCredit;
            $expectedCredit = (int)$row['effect_pence'] > 0 ? $normalCredit : $normalDebit;
            $lines = \InterfaceDB::fetchAll(
                'SELECT nominal_account_id, debit, credit FROM journal_lines WHERE journal_id = :journal_id ORDER BY id',
                ['journal_id' => (int)$row['journal_id']]
            );
            $totalDebit = array_sum(array_map(static fn(array $line): float => (float)$line['debit'], $lines));
            $totalCredit = array_sum(array_map(static fn(array $line): float => (float)$line['credit'], $lines));
            if (abs(round($totalDebit - $totalCredit, 2)) >= 0.005) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' is not balanced.';
            }
            if (abs(round($totalDebit - $expectedAmount, 2)) >= 0.005
                || abs(round($totalCredit - $expectedAmount, 2)) >= 0.005) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' does not match its recorded schedule effect.';
            }
            $debitMatches = array_filter($lines, static fn(array $line): bool =>
                (int)$line['nominal_account_id'] === $expectedDebit
                && abs(round((float)$line['debit'] - $expectedAmount, 2)) < 0.005
                && (float)$line['credit'] === 0.0
            );
            $creditMatches = array_filter($lines, static fn(array $line): bool =>
                (int)$line['nominal_account_id'] === $expectedCredit
                && abs(round((float)$line['credit'] - $expectedAmount, 2)) < 0.005
                && (float)$line['debit'] === 0.0
            );
            if (count($lines) !== 2 || count($debitMatches) !== 1 || count($creditMatches) !== 1) {
                $errors[] = 'Linked prepayment journal #' . $journalId . ' does not use the expected expense and Prepayments nominals.';
            }
        }
        return $errors;
    }

    private function continuousThroughSelectedPeriod(array $schedule): bool
    {
        $selected = (array)($schedule['selected_allocation'] ?? []);
        if ($selected === []) {
            return false;
        }
        $expected = new \DateTimeImmutable((string)$schedule['service_start_date']);
        foreach ((array)$schedule['allocations'] as $allocation) {
            if (!empty($allocation['is_source_period'])
                && (int)($allocation['overlap_days'] ?? 0) === 0
                && ($allocation['overlap_start'] ?? null) === null
                && ($allocation['overlap_end'] ?? null) === null) {
                if ((int)$allocation['accounting_period_id'] === (int)$selected['accounting_period_id']) {
                    return true;
                }
                continue;
            }
            $start = new \DateTimeImmutable((string)$allocation['overlap_start']);
            if ($start != $expected) {
                return false;
            }
            $end = new \DateTimeImmutable((string)$allocation['overlap_end']);
            if ((int)$allocation['accounting_period_id'] === (int)$selected['accounting_period_id']) {
                return true;
            }
            $expected = $end->modify('+1 day');
        }
        return false;
    }

    private function lockSchedulePeriod(int $schedulePeriodId): void
    {
        $sql = 'SELECT id FROM prepayment_schedule_periods WHERE id = :id';
        if (\InterfaceDB::driverName() !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        if ((int)\InterfaceDB::fetchColumn($sql, ['id' => $schedulePeriodId]) <= 0) {
            throw new \RuntimeException('The prepayment allocation disappeared before posting.');
        }
    }

    private function refreshCompletionStatus(int $scheduleId): void
    {
        $schedules = $this->scheduleService ?? new PrepaymentScheduleService();
        $schedule = $schedules->fetchSchedule($scheduleId);
        if (!is_array($schedule) || (int)($schedule['unallocated_pence'] ?? 0) !== 0) {
            return;
        }
        $complete = true;
        foreach ((array)$schedule['allocations'] as $allocation) {
            $role = !empty($allocation['is_source_period']) ? 'deferral' : 'release';
            $target = $role === 'deferral'
                ? (int)$allocation['closing_deferred_pence']
                : (int)$allocation['expense_pence'];
            if ($schedules->netPostedForReviewPeriod(
                (int)$schedule['review_id'],
                (int)$allocation['accounting_period_id'],
                $role
            ) !== $target) {
                $complete = false;
                break;
            }
        }
        if ($complete) {
            \InterfaceDB::execute(
                'UPDATE prepayment_schedules SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status = :active',
                ['status' => 'complete', 'id' => $scheduleId, 'active' => 'active']
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function allocationForPeriod(array $schedule, int $accountingPeriodId): ?array
    {
        foreach ((array)($schedule['allocations'] ?? []) as $allocation) {
            if ((int)$allocation['accounting_period_id'] === $accountingPeriodId) {
                return $allocation;
            }
        }
        return null;
    }
}
