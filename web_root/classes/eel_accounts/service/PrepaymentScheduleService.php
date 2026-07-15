<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class PrepaymentScheduleService
{
    private const LEGACY_CALCULATION_VERSION = 1;
    private const CURRENT_CALCULATION_VERSION = 2;

    public function __construct(
        private readonly ?PrepaymentAllocationService $allocationService = null,
        private readonly ?PrepaymentSourceService $sourceService = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function syncReviewSchedule(int $reviewId, string $changedBy = 'web_app'): array
    {
        if (!$this->schemaAvailable()) {
            return ['success' => false, 'errors' => ['Run the automated prepayment schedules migration first.']];
        }

        $review = $this->fetchReview($reviewId);
        if (!is_array($review) || (string)$review['status'] !== 'prepaid') {
            return ['success' => false, 'errors' => ['A saved prepaid review is required before calculating a schedule.']];
        }
        $built = $this->buildBasis($review);
        if (empty($built['success']) || !is_array($built['basis'] ?? null)) {
            return ['success' => false, 'errors' => (array)($built['errors'] ?? ['The prepayment schedule basis could not be calculated.'])];
        }
        $basis = (array)$built['basis'];
        $calculationHash = $this->hash($basis);
        $current = (int)($review['current_schedule_id'] ?? 0) > 0
            ? $this->fetchSchedule((int)$review['current_schedule_id'])
            : null;
        $reopenedPredecessor = !is_array($current)
            ? \InterfaceDB::fetchOne(
                'SELECT id FROM prepayment_schedules
                 WHERE review_id = :review_id AND status = \'needs_review\'
                 ORDER BY version_no DESC LIMIT 1',
                ['review_id' => $reviewId]
            )
            : null;
        if (is_array($current)) {
            $snapshotErrors = $this->storedSnapshotErrors($current);
            if ($snapshotErrors !== []) {
                return [
                    'success' => false,
                    'requires_reopen' => $this->postingCountForReview($reviewId) > 0,
                    'errors' => $snapshotErrors,
                ];
            }
        }
        if (is_array($current)) {
            $currentVersion = $this->calculationVersion($current);
            $currentBasis = $this->basisForVersion($basis, $currentVersion);
            if (hash_equals((string)$current['calculation_hash'], $this->hash($currentBasis))) {
                if ($currentVersion >= self::CURRENT_CALCULATION_VERSION || $this->postingCountForReview($reviewId) > 0) {
                    return ['success' => true, 'created' => false, 'schedule' => $current, 'errors' => []];
                }
                // An unposted legacy schedule is superseded below so all new
                // approvals and postings use the current calculation contract.
            }
        }

        if (is_array($current) && !$this->changeIsOnlyNewPeriods($current, $basis)) {
            if ($this->postingCountForReview($reviewId) > 0) {
                return ['success' => false, 'requires_reopen' => true, 'errors' => ['This prepayment already has posted journals. Reopen its schedule before changing source details, service dates, nominals or accounting-period boundaries.']];
            }
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $version = (int)\InterfaceDB::fetchColumn(
                'SELECT COALESCE(MAX(version_no), 0) + 1 FROM prepayment_schedules WHERE review_id = :review_id',
                ['review_id' => $reviewId]
            );
            \InterfaceDB::execute(
                'INSERT INTO prepayment_schedules (
                    review_id, version_no, company_id, source_accounting_period_id,
                    source_type, source_id, source_journal_id, source_journal_line_id,
                    source_date, source_amount_pence, original_expense_nominal_id,
                    asset_nominal_id, service_start_date, service_end_date, total_days,
                    calculation_version, calculation_hash, status, created_by, created_at, updated_at
                 ) VALUES (
                    :review_id, :version_no, :company_id, :source_accounting_period_id,
                    :source_type, :source_id, :source_journal_id, :source_journal_line_id,
                    :source_date, :source_amount_pence, :original_expense_nominal_id,
                    :asset_nominal_id, :service_start_date, :service_end_date, :total_days,
                    :calculation_version, :calculation_hash, :status, :created_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )',
                array_merge(array_diff_key($basis, ['allocations' => true]), [
                    'version_no' => $version,
                    'calculation_hash' => $calculationHash,
                    'status' => 'active',
                    'created_by' => $this->actor($changedBy),
                ])
            );
            $scheduleId = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM prepayment_schedules WHERE review_id = :review_id AND version_no = :version_no',
                ['review_id' => $reviewId, 'version_no' => $version]
            );
            if ($scheduleId <= 0) {
                throw new \RuntimeException('The prepayment schedule could not be reloaded after creation.');
            }

            foreach ($basis['allocations'] as $allocation) {
                \InterfaceDB::execute(
                    'INSERT INTO prepayment_schedule_periods (
                        schedule_id, accounting_period_id, period_start, period_end,
                        overlap_start, overlap_end, overlap_days, expense_pence,
                        opening_deferred_pence, closing_deferred_pence, is_source_period,
                        allocation_hash, created_at
                     ) VALUES (
                        :schedule_id, :accounting_period_id, :period_start, :period_end,
                        :overlap_start, :overlap_end, :overlap_days, :expense_pence,
                        :opening_deferred_pence, :closing_deferred_pence, :is_source_period,
                        :allocation_hash, CURRENT_TIMESTAMP
                     )',
                    array_merge($allocation, [
                        'schedule_id' => $scheduleId,
                        'is_source_period' => !empty($allocation['is_source_period']) ? 1 : 0,
                    ])
                );
            }

            if (is_array($current)) {
                \InterfaceDB::execute(
                    'UPDATE prepayment_schedules
                     SET status = :status, superseded_by_schedule_id = :superseded_by, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id',
                    ['status' => 'superseded', 'superseded_by' => $scheduleId, 'id' => (int)$current['id']]
                );
            } elseif (is_array($reopenedPredecessor)) {
                \InterfaceDB::execute(
                    'UPDATE prepayment_schedules
                     SET status = :status, superseded_by_schedule_id = :superseded_by, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id',
                    ['status' => 'superseded', 'superseded_by' => $scheduleId, 'id' => (int)$reopenedPredecessor['id']]
                );
            }
            \InterfaceDB::execute(
                'UPDATE prepayment_reviews SET current_schedule_id = :schedule_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['schedule_id' => $scheduleId, 'id' => $reviewId]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        return [
            'success' => true,
            'created' => true,
            'schedule' => $this->fetchSchedule($scheduleId),
            'errors' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function syncForAccountingPeriod(int $companyId, int $accountingPeriodId, string $changedBy = 'system'): array
    {
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($period) || !$this->schemaAvailable()) {
            return ['success' => false, 'errors' => ['The accounting period or prepayment schedule schema is unavailable.']];
        }

        $reviewIds = \InterfaceDB::fetchAll(
            'SELECT DISTINCT pr.id
             FROM prepayment_reviews pr
             LEFT JOIN prepayment_schedules ps ON ps.id = pr.current_schedule_id
             LEFT JOIN prepayment_schedule_periods psp
                    ON psp.schedule_id = ps.id
                   AND psp.accounting_period_id = :accounting_period_id
             WHERE pr.company_id = :company_id
               AND pr.status = \'prepaid\'
               AND (
                    (pr.service_start_date <= :period_end AND pr.service_end_date >= :period_start)
                    OR psp.id IS NOT NULL
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
            ]
        );
        $errors = [];
        $scheduleIds = [];
        foreach ($reviewIds as $row) {
            $result = $this->syncReviewSchedule((int)$row['id'], $changedBy);
            if (empty($result['success'])) {
                $errors = array_merge($errors, (array)($result['errors'] ?? []));
                continue;
            }
            $scheduleIds[] = (int)($result['schedule']['id'] ?? 0);
        }

        return [
            'success' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'schedule_ids' => array_values(array_filter(array_unique($scheduleIds))),
        ];
    }

    /**
     * Read-only preview of saved prepaid reviews which pre-date automated
     * schedules. No schedule or journal is created by this method.
     *
     * @return array<string, mixed>
     */
    public function fetchRepairContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->schemaAvailable()) {
            return [
                'available' => false,
                'errors' => ['Run the automated prepayment schedule repair migration first.'],
                'missing_reviews' => [],
                'missing_count' => 0,
            ];
        }
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($period)) {
            return ['available' => false, 'errors' => ['The selected accounting period could not be found.'], 'missing_reviews' => [], 'missing_count' => 0];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT id
             FROM prepayment_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND status = :status
               AND current_schedule_id IS NULL
             ORDER BY id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'status' => 'prepaid']
        );
        $missing = [];
        $errors = [];
        foreach ($rows as $row) {
            $review = $this->fetchReview((int)$row['id']);
            if (!is_array($review)) {
                $errors[] = 'Prepayment review #' . (int)$row['id'] . ' could not be loaded.';
                continue;
            }
            $built = $this->buildBasis($review);
            if (empty($built['success']) || !is_array($built['basis'] ?? null)) {
                foreach ((array)($built['errors'] ?? ['The schedule preview could not be calculated.']) as $error) {
                    $errors[] = 'Prepayment review #' . (int)$review['id'] . ': ' . (string)$error;
                }
                continue;
            }
            $basis = (array)$built['basis'];
            $selected = null;
            foreach ((array)$basis['allocations'] as $allocation) {
                if ((int)$allocation['accounting_period_id'] === $accountingPeriodId) {
                    $selected = $allocation;
                    break;
                }
            }
            $missing[] = [
                'review_id' => (int)$review['id'],
                'source_type' => (string)$review['source_type'],
                'source_id' => (int)$review['source_id'],
                'source_date' => (string)$basis['source_date'],
                'source_amount_pence' => (int)$basis['source_amount_pence'],
                'service_start_date' => (string)$basis['service_start_date'],
                'service_end_date' => (string)$basis['service_end_date'],
                'total_days' => (int)$basis['total_days'],
                'selected_allocation' => $selected,
                'allocations' => (array)$basis['allocations'],
                'calculation_hash' => $this->hash($basis),
            ];
        }

        return [
            'available' => true,
            'errors' => array_values(array_unique($errors)),
            'accounting_period' => $period,
            'missing_reviews' => $missing,
            'missing_count' => count($missing),
        ];
    }

    /**
     * Explicitly creates schedule snapshots for legacy prepaid reviews in the
     * selected source period. Journals are deliberately left to Year End.
     *
     * @return array<string, mixed>
     */
    public function syncMissingSchedulesForPeriod(
        int $companyId,
        int $accountingPeriodId,
        string $changedBy = 'web_app'
    ): array {
        $preview = $this->fetchRepairContext($companyId, $accountingPeriodId);
        if (empty($preview['available'])) {
            return ['success' => false, 'errors' => (array)($preview['errors'] ?? []), 'created_count' => 0, 'schedule_ids' => []];
        }
        if ((array)$preview['missing_reviews'] === []) {
            return ['success' => true, 'errors' => [], 'created_count' => 0, 'schedule_ids' => [], 'no_op' => true];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }
        try {
            (new YearEndLockService())->assertUnlockedForUpdate($companyId, $accountingPeriodId, 'recalculate legacy prepayment schedules');
            $scheduleIds = [];
            foreach ((array)$preview['missing_reviews'] as $missing) {
                $result = $this->syncReviewSchedule((int)$missing['review_id'], $changedBy);
                if (empty($result['success'])) {
                    throw new \RuntimeException((string)(($result['errors'] ?? [])[0] ?? 'The legacy prepayment schedule could not be created.'));
                }
                $scheduleIds[] = (int)($result['schedule']['id'] ?? 0);
            }
            (new YearEndAcknowledgementService())->revoke($companyId, $accountingPeriodId, 'prepayment_approvals', true);
            (new YearEndLockService())->writeAuditLog(
                $companyId,
                $accountingPeriodId,
                'prepayment_missing_schedules_recalculated',
                $changedBy,
                null,
                ['schedule_ids' => array_values(array_filter($scheduleIds))]
            );
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            return ['success' => false, 'errors' => [$exception->getMessage()], 'created_count' => 0, 'schedule_ids' => []];
        }

        return [
            'success' => true,
            'errors' => [],
            'created_count' => count($scheduleIds),
            'schedule_ids' => array_values(array_filter($scheduleIds)),
            'no_op' => $scheduleIds === [],
        ];
    }

    /** @return array<string, mixed>|null */
    public function fetchSchedule(int $scheduleId): ?array
    {
        if ($scheduleId <= 0 || !$this->schemaAvailable()) {
            return null;
        }
        $schedule = \InterfaceDB::fetchOne(
            'SELECT ps.*, na.code AS expense_nominal_code, na.name AS expense_nominal_name,
                    asset_na.code AS asset_nominal_code, asset_na.name AS asset_nominal_name
             FROM prepayment_schedules ps
             INNER JOIN nominal_accounts na ON na.id = ps.original_expense_nominal_id
             INNER JOIN nominal_accounts asset_na ON asset_na.id = ps.asset_nominal_id
             WHERE ps.id = :id LIMIT 1',
            ['id' => $scheduleId]
        );
        if (!is_array($schedule)) {
            return null;
        }

        $schedule['allocations'] = \InterfaceDB::fetchAll(
            'SELECT p.*,
                    COALESCE((
                        SELECT SUM(sp.effect_pence)
                        FROM prepayment_schedules history_schedule
                        INNER JOIN prepayment_schedule_postings sp ON sp.schedule_id = history_schedule.id
                        WHERE history_schedule.review_id = owner_schedule.review_id
                          AND sp.accounting_period_id = p.accounting_period_id
                          AND sp.posting_role = CASE WHEN p.is_source_period = 1 THEN \'deferral\' ELSE \'release\' END
                    ), 0) AS posted_effect_pence,
                    (
                        SELECT COUNT(*)
                        FROM prepayment_schedules history_schedule
                        INNER JOIN prepayment_schedule_postings sp ON sp.schedule_id = history_schedule.id
                        WHERE history_schedule.review_id = owner_schedule.review_id
                          AND sp.accounting_period_id = p.accounting_period_id
                          AND sp.posting_role = CASE WHEN p.is_source_period = 1 THEN \'deferral\' ELSE \'release\' END
                    ) AS posting_count
             FROM prepayment_schedule_periods p
             INNER JOIN prepayment_schedules owner_schedule ON owner_schedule.id = p.schedule_id
             WHERE p.schedule_id = :schedule_id
             ORDER BY p.period_start, p.accounting_period_id',
            ['schedule_id' => $scheduleId]
        );
        $schedule['allocated_pence'] = array_sum(array_map(
            static fn(array $row): int => (int)$row['expense_pence'],
            (array)$schedule['allocations']
        ));
        $schedule['unallocated_pence'] = max(0, (int)$schedule['source_amount_pence'] - (int)$schedule['allocated_pence']);

        return $schedule;
    }

    /** @return list<array<string, mixed>> */
    public function fetchCurrentSchedulesForPeriod(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->schemaAvailable()) {
            return [];
        }
        $ids = \InterfaceDB::fetchAll(
            'SELECT DISTINCT ps.id
             FROM prepayment_reviews pr
             INNER JOIN prepayment_schedules ps ON ps.id = pr.current_schedule_id
             INNER JOIN prepayment_schedule_periods psp ON psp.schedule_id = ps.id
             WHERE pr.company_id = :company_id AND psp.accounting_period_id = :accounting_period_id
               AND pr.status = \'prepaid\' AND ps.status IN (\'active\', \'complete\')
             ORDER BY ps.id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        $result = [];
        foreach ($ids as $row) {
            $schedule = $this->fetchSchedule((int)$row['id']);
            if (is_array($schedule)) {
                $result[] = $schedule;
            }
        }
        return $result;
    }

    /**
     * Stable read model shared by the Prepayments workflow and Tax cards.
     *
     * @return array<string, mixed>
     */
    public function fetchPeriodContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->schemaAvailable()) {
            return [
                'available' => false,
                'errors' => ['Run the automated prepayment schedules migration and select an accounting period.'],
                'schedules' => [],
            ];
        }
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($period)) {
            return ['available' => false, 'errors' => ['The selected accounting period could not be found.'], 'schedules' => []];
        }

        $schedules = [];
        $errors = [];
        $totalExpense = 0;
        $totalClosing = 0;
        foreach ($this->fetchCurrentSchedulesForPeriod($companyId, $accountingPeriodId) as $schedule) {
            $selected = null;
            foreach ((array)$schedule['allocations'] as $allocation) {
                if ((int)$allocation['accounting_period_id'] === $accountingPeriodId) {
                    $selected = $allocation;
                    break;
                }
            }
            if (!is_array($selected)) {
                continue;
            }

            $verification = ($this->sourceService ?? new PrepaymentSourceService())->verify(
                $companyId,
                (int)$schedule['source_accounting_period_id'],
                (string)$schedule['source_type'],
                (int)$schedule['source_id']
            );
            $source = is_array($verification['source'] ?? null) ? (array)$verification['source'] : [];
            if (empty($verification['success'])) {
                $errors = array_merge($errors, (array)($verification['errors'] ?? []));
            }

            $role = !empty($selected['is_source_period']) ? 'deferral' : 'release';
            $targetPence = $role === 'deferral'
                ? (int)$selected['closing_deferred_pence']
                : (int)$selected['expense_pence'];
            $postedPence = $this->netPostedForReviewPeriod(
                (int)$schedule['review_id'],
                $accountingPeriodId,
                $role
            );
            $selected['posting_role'] = $role;
            $selected['posting_target_pence'] = $targetPence;
            $selected['posted_effect_pence'] = $postedPence;
            $selected['posting_delta_pence'] = $targetPence - $postedPence;
            $selected['journal_state'] = $targetPence === $postedPence
                ? 'posted'
                : ($postedPence === 0 ? 'not_posted' : 'correction_required');

            $knownFuture = array_values(array_filter(
                (array)$schedule['allocations'],
                static fn(array $row): bool => (string)$row['period_start'] > (string)$selected['period_end']
            ));
            $schedule['source_description'] = (string)($source['description'] ?? '');
            $schedule['selected_allocation'] = $selected;
            $schedule['known_future_allocations'] = $knownFuture;
            $schedule['journal_state'] = (string)$selected['journal_state'];
            $schedule['source_valid'] = !empty($verification['success']);
            $schedule['source_errors'] = (array)($verification['errors'] ?? []);
            $schedules[] = $schedule;
            $totalExpense += (int)$selected['expense_pence'];
            $totalClosing += (int)$selected['closing_deferred_pence'];
        }

        return [
            'available' => true,
            'errors' => array_values(array_unique($errors)),
            'accounting_period' => $period,
            'schedules' => $schedules,
            'total_expense_pence' => $totalExpense,
            'total_closing_deferred_pence' => $totalClosing,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function fetchPreviewAdjustments(int $companyId, int $accountingPeriodId): array
    {
        $context = $this->fetchPeriodContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return [];
        }
        $adjustments = [];
        foreach ((array)$context['schedules'] as $schedule) {
            $allocation = (array)($schedule['selected_allocation'] ?? []);
            $delta = (int)($allocation['posting_delta_pence'] ?? 0);
            if ($delta === 0) {
                continue;
            }
            $role = (string)($allocation['posting_role'] ?? 'release');
            $assetNominalId = (int)$schedule['asset_nominal_id'];
            $expenseNominalId = (int)$schedule['original_expense_nominal_id'];
            $normalDebit = $role === 'deferral' ? $assetNominalId : $expenseNominalId;
            $normalCredit = $role === 'deferral' ? $expenseNominalId : $assetNominalId;
            $adjustments[] = [
                'review_id' => (int)$schedule['review_id'],
                'schedule_id' => (int)$schedule['id'],
                'schedule_period_id' => (int)$allocation['id'],
                'accounting_period_id' => $accountingPeriodId,
                'posting_role' => $role,
                'target_pence' => (int)$allocation['posting_target_pence'],
                'posted_pence' => (int)$allocation['posted_effect_pence'],
                'delta_pence' => $delta,
                'amount_pence' => abs($delta),
                'debit_nominal_id' => $delta > 0 ? $normalDebit : $normalCredit,
                'credit_nominal_id' => $delta > 0 ? $normalCredit : $normalDebit,
                'journal_date' => $role === 'deferral'
                    ? (string)$allocation['period_end']
                    : (string)$allocation['overlap_start'],
                'calculation_hash' => (string)$schedule['calculation_hash'],
            ];
        }
        return $adjustments;
    }

    public function netPostedForReview(int $reviewId): int
    {
        if ($reviewId <= 0 || !$this->schemaAvailable()) {
            return 0;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(psp.effect_pence), 0)
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings psp ON psp.schedule_id = ps.id
             WHERE ps.review_id = :review_id',
            ['review_id' => $reviewId]
        );
    }

    public function postingCountForReview(int $reviewId): int
    {
        if ($reviewId <= 0 || !$this->schemaAvailable()) {
            return 0;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings psp ON psp.schedule_id = ps.id
             WHERE ps.review_id = :review_id',
            ['review_id' => $reviewId]
        );
    }

    public function netPostedForReviewPeriod(int $reviewId, int $accountingPeriodId, string $postingRole): int
    {
        if ($reviewId <= 0 || $accountingPeriodId <= 0 || !in_array($postingRole, ['deferral', 'release'], true) || !$this->schemaAvailable()) {
            return 0;
        }
        return (int)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(psp.effect_pence), 0)
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings psp ON psp.schedule_id = ps.id
             WHERE ps.review_id = :review_id
               AND psp.accounting_period_id = :accounting_period_id
               AND psp.posting_role = :posting_role',
            [
                'review_id' => $reviewId,
                'accounting_period_id' => $accountingPeriodId,
                'posting_role' => $postingRole,
            ]
        );
    }

    /** @return list<array<string, mixed>> */
    public function fetchPostingNetsForReview(int $reviewId): array
    {
        if ($reviewId <= 0 || !$this->schemaAvailable()) {
            return [];
        }
        return \InterfaceDB::fetchAll(
            'SELECT psp.accounting_period_id, psp.posting_role, SUM(psp.effect_pence) AS net_effect_pence
             FROM prepayment_schedules ps
             INNER JOIN prepayment_schedule_postings psp ON psp.schedule_id = ps.id
             WHERE ps.review_id = :review_id
             GROUP BY psp.accounting_period_id, psp.posting_role
             HAVING SUM(psp.effect_pence) <> 0
             ORDER BY psp.accounting_period_id, psp.posting_role',
            ['review_id' => $reviewId]
        );
    }

    /**
     * Rebuild and compare every current schedule which can affect a selected
     * accounting period. When requested, all schedule/source evidence is
     * locked first and the caller must own the surrounding transaction.
     *
     * @return array{success: bool, errors: list<string>, schedules: list<array<string, mixed>>}
     */
    public function validatePeriodIntegrity(int $companyId, int $accountingPeriodId, bool $lockRows = false): array
    {
        if (!$this->schemaAvailable()) {
            return ['success' => false, 'errors' => ['Run the automated prepayment schedules migration before validating Year End.'], 'schedules' => []];
        }
        if ($lockRows && !\InterfaceDB::inTransaction()) {
            return ['success' => false, 'errors' => ['Prepayment schedule rows can only be locked inside a transaction.'], 'schedules' => []];
        }
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($period)) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.'], 'schedules' => []];
        }

        $reviews = \InterfaceDB::fetchAll(
            'SELECT pr.id, pr.current_schedule_id
             FROM prepayment_reviews pr
             WHERE pr.company_id = :company_id
               AND pr.status = \'prepaid\'
               AND (
                    pr.accounting_period_id = :accounting_period_id
                    OR (pr.service_start_date <= :period_end AND pr.service_end_date >= :period_start)
                    OR EXISTS (
                        SELECT 1
                        FROM prepayment_schedule_periods existing_period
                        WHERE existing_period.schedule_id = pr.current_schedule_id
                          AND existing_period.accounting_period_id = :accounting_period_id
                    )
               )
             ORDER BY pr.id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => (string)$period['period_start'],
                'period_end' => (string)$period['period_end'],
            ]
        );
        $errors = [];
        $schedules = [];
        foreach ($reviews as $review) {
            $scheduleId = (int)($review['current_schedule_id'] ?? 0);
            if ($scheduleId <= 0) {
                $errors[] = 'Prepayment review #' . (int)$review['id'] . ' has no current calculated schedule.';
                continue;
            }
            $validation = $this->validateScheduleIntegrity($scheduleId, $lockRows);
            $errors = array_merge($errors, (array)($validation['errors'] ?? []));
            if (is_array($validation['schedule'] ?? null)) {
                $schedules[] = (array)$validation['schedule'];
            }
        }

        $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));
        return ['success' => $errors === [], 'errors' => $errors, 'schedules' => $schedules];
    }

    /** @return array{success: bool, errors: list<string>, schedule?: array<string, mixed>} */
    public function validateScheduleIntegrity(int $scheduleId, bool $lockRows = false): array
    {
        if ($scheduleId <= 0 || !$this->schemaAvailable()) {
            return ['success' => false, 'errors' => ['The prepayment schedule could not be validated.']];
        }
        if ($lockRows && !\InterfaceDB::inTransaction()) {
            return ['success' => false, 'errors' => ['Prepayment schedule rows can only be locked inside a transaction.']];
        }
        try {
            if ($lockRows) {
                $this->lockScheduleGraph($scheduleId);
            }
            $schedule = $this->fetchSchedule($scheduleId);
            if (!is_array($schedule)) {
                return ['success' => false, 'errors' => ['The current prepayment schedule disappeared during validation.']];
            }
            $review = $this->fetchReview((int)$schedule['review_id']);
            if (!is_array($review)) {
                return ['success' => false, 'errors' => ['The prepayment review linked to schedule #' . $scheduleId . ' no longer exists.']];
            }

            $errors = [];
            if ((int)($review['current_schedule_id'] ?? 0) !== $scheduleId) {
                $errors[] = 'Prepayment schedule #' . $scheduleId . ' is not the review\'s current schedule.';
            }
            if ((int)$schedule['company_id'] !== (int)$review['company_id']) {
                $errors[] = 'Prepayment schedule #' . $scheduleId . ' is linked to the wrong company.';
            }
            if (!in_array((string)$schedule['status'], ['active', 'complete'], true)) {
                $errors[] = 'Prepayment schedule #' . $scheduleId . ' is not active.';
            }
            $errors = array_merge($errors, $this->storedSnapshotErrors($schedule));

            $rebuilt = $this->buildBasis($review, $lockRows);
            if (empty($rebuilt['success']) || !is_array($rebuilt['basis'] ?? null)) {
                $errors = array_merge($errors, (array)($rebuilt['errors'] ?? ['The live prepayment basis could not be rebuilt.']));
            } else {
                $errors = array_merge($errors, $this->basisComparisonErrors($schedule, (array)$rebuilt['basis']));
            }
            $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));
            return ['success' => $errors === [], 'errors' => $errors, 'schedule' => $schedule];
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * Validate the immutable snapshot itself without comparing it with today's
     * set of accounting periods. This is used for historical schedule versions
     * which retain posting evidence after lazy future-period supersession.
     *
     * @return array{success: bool, errors: list<string>, schedule?: array<string, mixed>}
     */
    public function validateStoredSnapshotIntegrity(int $scheduleId, bool $lockRows = false): array
    {
        if ($lockRows && !\InterfaceDB::inTransaction()) {
            return ['success' => false, 'errors' => ['Prepayment schedule rows can only be locked inside a transaction.']];
        }
        if ($lockRows) {
            $this->lockScheduleGraph($scheduleId);
        }
        $schedule = $this->fetchSchedule($scheduleId);
        if (!is_array($schedule)) {
            return ['success' => false, 'errors' => ['Prepayment schedule #' . $scheduleId . ' could not be loaded.']];
        }
        $errors = $this->storedSnapshotErrors($schedule);
        return ['success' => $errors === [], 'errors' => $errors, 'schedule' => $schedule];
    }

    public function hasSchema(): bool
    {
        return $this->schemaAvailable();
    }

    /** @return array<string, mixed>|null */
    private function fetchReview(int $reviewId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id, source_type, source_id, status,
                    service_start_date, service_end_date, current_schedule_id
             FROM prepayment_reviews WHERE id = :id LIMIT 1',
            ['id' => $reviewId]
        );
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function accountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, period_start, period_end
             FROM accounting_periods WHERE id = :id AND company_id = :company_id LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    private function overlappingAccountingPeriods(int $companyId, string $start, string $end): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT id, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id AND period_start <= :service_end AND period_end >= :service_start
             ORDER BY period_start, id',
            ['company_id' => $companyId, 'service_start' => $start, 'service_end' => $end]
        );
    }

    /** @return array<string, mixed>|null */
    private function assetNominal(int $companyId): ?array
    {
        return (new PrepaymentAssetNominalService())->resolveForCompany($companyId);
    }

    /** @return array{success: bool, errors: list<string>, basis?: array<string, mixed>} */
    private function buildBasis(array $review, bool $lockRows = false): array
    {
        $companyId = (int)($review['company_id'] ?? 0);
        $accountingPeriodId = (int)($review['accounting_period_id'] ?? 0);
        $sourceType = (string)($review['source_type'] ?? '');
        $sourceId = (int)($review['source_id'] ?? 0);
        $sources = $this->sourceService ?? new PrepaymentSourceService();
        if ($lockRows) {
            $sources->lockEvidence($companyId, $accountingPeriodId, $sourceType, $sourceId);
        }
        $verification = $sources->verify($companyId, $accountingPeriodId, $sourceType, $sourceId);
        if (empty($verification['success']) || !is_array($verification['source'] ?? null)) {
            return ['success' => false, 'errors' => (array)($verification['errors'] ?? ['The source could not be verified.'])];
        }
        $source = (array)$verification['source'];
        $sourcePeriod = $this->accountingPeriod($companyId, $accountingPeriodId);
        if (!is_array($sourcePeriod)) {
            return ['success' => false, 'errors' => ['The source accounting period could not be found.']];
        }

        $serviceStart = (string)($review['service_start_date'] ?? '');
        $serviceEnd = (string)($review['service_end_date'] ?? '');
        if ($serviceStart < (string)$sourcePeriod['period_start']) {
            return ['success' => false, 'errors' => ['The service start cannot precede the source accounting period; treat that case as an accrual or prior-period issue.']];
        }
        if ($serviceEnd <= (string)$sourcePeriod['period_end']) {
            return ['success' => false, 'errors' => ['A prepayment must extend beyond the source accounting period end.']];
        }
        $assetNominal = $this->assetNominal($companyId);
        if (!is_array($assetNominal)) {
            return ['success' => false, 'errors' => ['Assign an active Prepayments current-asset nominal before calculating the schedule.']];
        }
        $periods = $this->overlappingAccountingPeriods($companyId, $serviceStart, $serviceEnd);
        foreach ($periods as &$period) {
            $period['is_source_period'] = (int)($period['id'] ?? 0) === $accountingPeriodId;
        }
        unset($period);
        if ($serviceStart > (string)$sourcePeriod['period_end']) {
            $periods[] = [
                'id' => (int)$sourcePeriod['id'],
                'period_start' => (string)$sourcePeriod['period_start'],
                'period_end' => (string)$sourcePeriod['period_end'],
                'force_source_deferral' => true,
                'is_source_period' => true,
            ];
        }
        $calculation = ($this->allocationService ?? new PrepaymentAllocationService())->calculateSchedule(
            (int)$source['amount_pence'],
            $serviceStart,
            $serviceEnd,
            $periods
        );
        return ['success' => true, 'errors' => [], 'basis' => [
            'calculation_version' => self::CURRENT_CALCULATION_VERSION,
            'review_id' => (int)$review['id'],
            'company_id' => $companyId,
            'source_accounting_period_id' => $accountingPeriodId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_journal_id' => (int)$source['source_journal_id'],
            'source_journal_line_id' => (int)$source['source_journal_line_id'],
            'source_date' => (string)$source['source_date'],
            'source_amount_pence' => (int)$source['amount_pence'],
            'original_expense_nominal_id' => (int)$source['nominal_account_id'],
            'asset_nominal_id' => (int)$assetNominal['id'],
            'service_start_date' => $serviceStart,
            'service_end_date' => $serviceEnd,
            'total_days' => (int)$calculation['total_days'],
            'allocations' => array_map(fn(array $row): array => [
                'accounting_period_id' => (int)$row['accounting_period_id'],
                'period_start' => (string)$row['period_start'],
                'period_end' => (string)$row['period_end'],
                'overlap_start' => $row['overlap_start'] !== null ? (string)$row['overlap_start'] : null,
                'overlap_end' => $row['overlap_end'] !== null ? (string)$row['overlap_end'] : null,
                'overlap_days' => (int)$row['overlap_days'],
                'expense_pence' => (int)$row['expense_pence'],
                'opening_deferred_pence' => (int)$row['opening_deferred_pence'],
                'closing_deferred_pence' => (int)$row['closing_deferred_pence'],
                'is_source_period' => !empty($row['is_source_period']),
                'allocation_hash' => $this->allocationHash($row, self::CURRENT_CALCULATION_VERSION),
            ], (array)$calculation['allocations']),
        ]];
    }

    /** @return list<string> */
    private function storedSnapshotErrors(array $schedule): array
    {
        $version = $this->calculationVersion($schedule);
        $errors = $this->sourcePeriodStructureErrors($schedule);
        foreach ((array)($schedule['allocations'] ?? []) as $allocation) {
            $expectedHash = $this->allocationHash($allocation, $version);
            if (!hash_equals($expectedHash, (string)($allocation['allocation_hash'] ?? ''))) {
                $errors[] = 'Prepayment allocation #' . (int)($allocation['id'] ?? 0) . ' has a stale or altered allocation hash.';
            }
        }
        $expectedParentHash = $this->hash($this->basisFromSchedule($schedule));
        if (!hash_equals($expectedParentHash, (string)($schedule['calculation_hash'] ?? ''))) {
            $errors[] = 'Prepayment schedule #' . (int)($schedule['id'] ?? 0) . ' has a stale or altered calculation hash.';
        }
        return $errors;
    }

    /** @return list<string> */
    private function basisComparisonErrors(array $schedule, array $expectedBasis): array
    {
        $errors = [];
        $version = $this->calculationVersion($schedule);
        $expectedBasis = $this->basisForVersion($expectedBasis, $version);
        $expectedHash = $this->hash($expectedBasis);
        if (!hash_equals($expectedHash, (string)($schedule['calculation_hash'] ?? ''))) {
            $errors[] = 'Prepayment schedule #' . (int)$schedule['id'] . ' no longer matches its live source, nominal, dates or accounting-period boundaries.';
        }
        $actual = $this->basisFromSchedule($schedule);
        if ($actual !== $expectedBasis) {
            $actualByPeriod = [];
            foreach ((array)$actual['allocations'] as $allocation) {
                $actualByPeriod[(int)$allocation['accounting_period_id']] = $allocation;
            }
            $expectedByPeriod = [];
            foreach ((array)$expectedBasis['allocations'] as $allocation) {
                $expectedByPeriod[(int)$allocation['accounting_period_id']] = $allocation;
            }
            if (array_keys($actualByPeriod) !== array_keys($expectedByPeriod)) {
                $errors[] = 'Prepayment schedule #' . (int)$schedule['id'] . ' does not contain exactly the required accounting-period allocations.';
            }
            foreach ($expectedByPeriod as $periodId => $allocation) {
                if (!isset($actualByPeriod[$periodId]) || $actualByPeriod[$periodId] !== $allocation) {
                    $errors[] = 'Prepayment schedule #' . (int)$schedule['id'] . ' has an allocation which no longer matches accounting period #' . $periodId . '.';
                }
            }
        }
        return $errors;
    }

    /** @return array<string, mixed> */
    private function basisFromSchedule(array $schedule): array
    {
        $version = $this->calculationVersion($schedule);
        $basis = [
            'calculation_version' => $version,
            'review_id' => (int)$schedule['review_id'],
            'company_id' => (int)$schedule['company_id'],
            'source_accounting_period_id' => (int)$schedule['source_accounting_period_id'],
            'source_type' => (string)$schedule['source_type'],
            'source_id' => (int)$schedule['source_id'],
            'source_journal_id' => (int)$schedule['source_journal_id'],
            'source_journal_line_id' => (int)$schedule['source_journal_line_id'],
            'source_date' => (string)$schedule['source_date'],
            'source_amount_pence' => (int)$schedule['source_amount_pence'],
            'original_expense_nominal_id' => (int)$schedule['original_expense_nominal_id'],
            'asset_nominal_id' => (int)$schedule['asset_nominal_id'],
            'service_start_date' => (string)$schedule['service_start_date'],
            'service_end_date' => (string)$schedule['service_end_date'],
            'total_days' => (int)$schedule['total_days'],
            'allocations' => array_map(static fn(array $allocation): array => [
                'accounting_period_id' => (int)$allocation['accounting_period_id'],
                'period_start' => (string)$allocation['period_start'],
                'period_end' => (string)$allocation['period_end'],
                'overlap_start' => $allocation['overlap_start'] !== null ? (string)$allocation['overlap_start'] : null,
                'overlap_end' => $allocation['overlap_end'] !== null ? (string)$allocation['overlap_end'] : null,
                'overlap_days' => (int)$allocation['overlap_days'],
                'expense_pence' => (int)$allocation['expense_pence'],
                'opening_deferred_pence' => (int)$allocation['opening_deferred_pence'],
                'closing_deferred_pence' => (int)$allocation['closing_deferred_pence'],
                'is_source_period' => !empty($allocation['is_source_period']),
                'allocation_hash' => (string)$allocation['allocation_hash'],
            ], (array)($schedule['allocations'] ?? [])),
        ];

        return $this->basisForVersion($basis, $version, false);
    }

    private function allocationHash(array $allocation, int $version): string
    {
        $parts = [
            (string)(int)$allocation['accounting_period_id'],
            (string)$allocation['period_start'],
            (string)$allocation['period_end'],
            (string)$allocation['overlap_start'],
            (string)$allocation['overlap_end'],
            (string)(int)$allocation['expense_pence'],
            (string)(int)$allocation['closing_deferred_pence'],
        ];
        if ($version >= self::CURRENT_CALCULATION_VERSION) {
            $parts[] = !empty($allocation['is_source_period']) ? '1' : '0';
        }
        return hash('sha256', implode('|', $parts));
    }

    private function lockScheduleGraph(int $scheduleId): void
    {
        $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
        $schedule = \InterfaceDB::fetchOne(
            'SELECT id, review_id FROM prepayment_schedules WHERE id = :id' . $suffix,
            ['id' => $scheduleId]
        );
        if (!is_array($schedule)) {
            throw new \RuntimeException('The current prepayment schedule disappeared before its rows could be locked.');
        }
        $reviewId = (int)$schedule['review_id'];
        \InterfaceDB::fetchAll('SELECT id FROM prepayment_reviews WHERE id = :id' . $suffix, ['id' => $reviewId]);
        \InterfaceDB::fetchAll('SELECT id FROM prepayment_schedules WHERE review_id = :review_id ORDER BY id' . $suffix, ['review_id' => $reviewId]);
        \InterfaceDB::fetchAll(
            'SELECT psp.id FROM prepayment_schedule_periods psp
             INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id
             WHERE ps.review_id = :review_id ORDER BY psp.id' . $suffix,
            ['review_id' => $reviewId]
        );
        \InterfaceDB::fetchAll(
            'SELECT posting.id FROM prepayment_schedule_postings posting
             INNER JOIN prepayment_schedules ps ON ps.id = posting.schedule_id
             WHERE ps.review_id = :review_id ORDER BY posting.id' . $suffix,
            ['review_id' => $reviewId]
        );
        \InterfaceDB::fetchAll(
            'SELECT j.id FROM journals j
             INNER JOIN prepayment_schedule_postings posting ON posting.journal_id = j.id
             INNER JOIN prepayment_schedules ps ON ps.id = posting.schedule_id
             WHERE ps.review_id = :review_id ORDER BY j.id' . $suffix,
            ['review_id' => $reviewId]
        );
        \InterfaceDB::fetchAll(
            'SELECT jl.id FROM journal_lines jl
             INNER JOIN prepayment_schedule_postings posting ON posting.journal_id = jl.journal_id
             INNER JOIN prepayment_schedules ps ON ps.id = posting.schedule_id
             WHERE ps.review_id = :review_id ORDER BY jl.id' . $suffix,
            ['review_id' => $reviewId]
        );
        if (\InterfaceDB::tableExists('journal_entry_metadata')) {
            \InterfaceDB::fetchAll(
                'SELECT jem.journal_id FROM journal_entry_metadata jem
                 INNER JOIN prepayment_schedule_postings posting ON posting.journal_id = jem.journal_id
                 INNER JOIN prepayment_schedules ps ON ps.id = posting.schedule_id
                 WHERE ps.review_id = :review_id ORDER BY jem.journal_id' . $suffix,
                ['review_id' => $reviewId]
            );
        }
    }

    private function changeIsOnlyNewPeriods(array $current, array $basis): bool
    {
        $basis = $this->basisForVersion($basis, $this->calculationVersion($current));
        foreach ([
            'company_id', 'source_accounting_period_id', 'source_type', 'source_id',
            'source_journal_id', 'source_journal_line_id', 'source_date', 'source_amount_pence',
            'original_expense_nominal_id', 'asset_nominal_id', 'service_start_date',
            'service_end_date', 'total_days',
        ] as $key) {
            if ((string)($current[$key] ?? '') !== (string)($basis[$key] ?? '')) {
                return false;
            }
        }

        $newByPeriod = [];
        foreach ((array)$basis['allocations'] as $allocation) {
            $newByPeriod[(int)$allocation['accounting_period_id']] = (string)$allocation['allocation_hash'];
        }
        foreach ((array)($current['allocations'] ?? []) as $allocation) {
            $periodId = (int)$allocation['accounting_period_id'];
            if (!isset($newByPeriod[$periodId]) || !hash_equals((string)$allocation['allocation_hash'], $newByPeriod[$periodId])) {
                return false;
            }
        }
        return true;
    }

    private function hash(array $basis): string
    {
        $json = json_encode($basis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($json)) {
            throw new \RuntimeException('The prepayment calculation basis could not be encoded.');
        }
        return hash('sha256', $json);
    }

    /** @return list<string> */
    private function sourcePeriodStructureErrors(array $schedule): array
    {
        $sourcePeriodId = (int)($schedule['source_accounting_period_id'] ?? 0);
        $sourceAllocations = array_values(array_filter(
            (array)($schedule['allocations'] ?? []),
            static fn(array $allocation): bool => !empty($allocation['is_source_period'])
        ));
        if (count($sourceAllocations) !== 1) {
            return ['Prepayment schedule #' . (int)($schedule['id'] ?? 0) . ' must contain exactly one source-period allocation.'];
        }
        if ((int)$sourceAllocations[0]['accounting_period_id'] !== $sourcePeriodId) {
            return ['Prepayment schedule #' . (int)($schedule['id'] ?? 0) . ' marks the wrong accounting period as its source period.'];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function basisForVersion(array $basis, int $version, bool $recalculateHashes = true): array
    {
        if ($version >= self::CURRENT_CALCULATION_VERSION) {
            $basis['calculation_version'] = self::CURRENT_CALCULATION_VERSION;
        } else {
            unset($basis['calculation_version']);
        }
        foreach ((array)($basis['allocations'] ?? []) as $index => $allocation) {
            if ($version >= self::CURRENT_CALCULATION_VERSION) {
                $allocation['is_source_period'] = !empty($allocation['is_source_period']);
            } else {
                unset($allocation['is_source_period']);
            }
            if ($recalculateHashes) {
                $allocation['allocation_hash'] = $this->allocationHash($allocation, $version);
            }
            $basis['allocations'][$index] = $allocation;
        }

        return $basis;
    }

    private function calculationVersion(array $schedule): int
    {
        $version = (int)($schedule['calculation_version'] ?? self::LEGACY_CALCULATION_VERSION);
        return $version >= self::CURRENT_CALCULATION_VERSION
            ? self::CURRENT_CALCULATION_VERSION
            : self::LEGACY_CALCULATION_VERSION;
    }

    private function actor(string $changedBy): string
    {
        return trim($changedBy) !== '' ? trim($changedBy) : 'web_app';
    }

    private function schemaAvailable(): bool
    {
        try {
            return \InterfaceDB::tableExists('prepayment_schedules')
                && \InterfaceDB::tableExists('prepayment_schedule_periods')
                && \InterfaceDB::tableExists('prepayment_schedule_postings')
                && \InterfaceDB::columnExists('prepayment_reviews', 'current_schedule_id')
                && \InterfaceDB::columnExists('prepayment_schedules', 'calculation_version');
        } catch (\Throwable) {
            return false;
        }
    }
}
