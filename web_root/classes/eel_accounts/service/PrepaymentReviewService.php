<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class PrepaymentReviewService
{
    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing prepayments.'],
            ];
        }

        foreach (['nominal_accounts', 'prepayment_reviews'] as $table) {
            if (!$this->tableExists($table)) {
                return [
                    'available' => false,
                    'errors' => ['Run the prepayments migration before reviewing prepayments.'],
                ];
            }
        }

        if (!$this->columnExists('nominal_accounts', 'prepayment_candidate')) {
            return [
                'available' => false,
                'errors' => ['Run the nominal prepayment candidate migration before reviewing prepayments.'],
            ];
        }

        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $items = array_merge(
            $this->fetchTransactionItems($companyId, $accountingPeriodId),
            $this->fetchExpenseLineItems($companyId, $accountingPeriodId)
        );
        $reviews = $this->fetchReviews($companyId, $accountingPeriodId);
        foreach ($items as $index => $item) {
            $review = $reviews[$this->reviewKey((string)$item['source_type'], (int)$item['source_id'])] ?? null;
            $items[$index]['review'] = is_array($review) ? $review : [
                'status' => 'pending',
                'service_start_date' => '',
                'service_end_date' => '',
                'notes' => '',
                'reviewed_at' => '',
                'reviewed_by' => '',
                'persisted' => false,
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $dateComparison = strcmp((string)($right['source_date'] ?? ''), (string)($left['source_date'] ?? ''));
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            return strcmp((string)($left['nominal_name'] ?? ''), (string)($right['nominal_name'] ?? ''));
        });

        $reviewedCount = count(array_filter($items, fn(array $item): bool => $this->isCompletedDecision((array)($item['review'] ?? []))));
        $pendingCount = count($items) - $reviewedCount;

        return [
            'available' => true,
            'accounting_period' => $accountingPeriod,
            'items' => $items,
            'total_count' => count($items),
            'pending_count' => $pendingCount,
            'prepaid_count' => count(array_filter($items, static function (array $item): bool {
                $review = (array)($item['review'] ?? []);
                return !empty($review['persisted']) && (string)($review['status'] ?? '') === 'prepaid';
            })),
            'reviewed_count' => $reviewedCount,
            'unreviewed_count' => $pendingCount,
        ];
    }

    public function saveReview(int $companyId, int $accountingPeriodId, array $payload, string $changedBy = 'web_app'): array
    {
        (($this->lockService ?? new \eel_accounts\Service\YearEndLockService()))->assertUnlocked($companyId, $accountingPeriodId, 'save prepayment review decisions');

        if (!$this->tableExists('prepayment_reviews')) {
            return [
                'success' => false,
                'errors' => ['Run the prepayments migration before saving prepayment reviews.'],
            ];
        }

        $sourceType = trim((string)($payload['source_type'] ?? ''));
        $sourceId = max(0, (int)($payload['source_id'] ?? 0));
        $status = trim((string)($payload['status'] ?? 'not_prepaid'));
        $serviceStart = trim((string)($payload['service_start_date'] ?? ''));
        $serviceEnd = trim((string)($payload['service_end_date'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));

        if (!in_array($sourceType, ['transaction', 'expense_claim_line'], true) || $sourceId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a valid prepayment source item.'],
            ];
        }

        if (!in_array($status, ['not_prepaid', 'prepaid'], true)) {
            return [
                'success' => false,
                'errors' => ['Select a valid prepayment review status.'],
            ];
        }

        if ($status === 'prepaid' && ($serviceStart === '' || $serviceEnd === '')) {
            return [
                'success' => false,
                'errors' => ['Enter the service period start and end dates before marking an item as prepaid.'],
            ];
        }

        foreach ([$serviceStart, $serviceEnd] as $date) {
            if ($date !== '' && !$this->validDate($date)) {
                return [
                    'success' => false,
                    'errors' => ['Service period dates must be valid dates.'],
                ];
            }
        }

        if ($serviceStart !== '' && $serviceEnd !== '' && $serviceStart > $serviceEnd) {
            return [
                'success' => false,
                'errors' => ['Service period start date must be on or before the end date.'],
            ];
        }

        if (!$this->sourceBelongsToCandidateNominal($companyId, $accountingPeriodId, $sourceType, $sourceId)) {
            return [
                'success' => false,
                'errors' => ['The selected source item is not available for prepayment review.'],
            ];
        }

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        \InterfaceDB::execute(
            'INSERT INTO prepayment_reviews (
                company_id,
                accounting_period_id,
                source_type,
                source_id,
                status,
                service_start_date,
                service_end_date,
                notes,
                reviewed_at,
                reviewed_by,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :source_type,
                :source_id,
                :status,
                :service_start_date,
                :service_end_date,
                :notes,
                :reviewed_at,
                :reviewed_by,
                :created_at,
                :updated_at
             )
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                service_start_date = VALUES(service_start_date),
                service_end_date = VALUES(service_end_date),
                notes = VALUES(notes),
                reviewed_at = VALUES(reviewed_at),
                reviewed_by = VALUES(reviewed_by),
                updated_at = VALUES(updated_at)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => $status,
                'service_start_date' => $status === 'prepaid' && $serviceStart !== '' ? $serviceStart : null,
                'service_end_date' => $status === 'prepaid' && $serviceEnd !== '' ? $serviceEnd : null,
                'notes' => $notes !== '' ? $notes : null,
                'reviewed_at' => $now,
                'reviewed_by' => $this->actorValue($changedBy),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->writeAuditLog(
            $companyId,
            $accountingPeriodId,
            'prepayment_review_saved',
            $changedBy,
            null,
            [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'status' => $status,
            ]
        );

        return [
            'success' => true,
        ];
    }

    private function fetchTransactionItems(int $companyId, int $accountingPeriodId): array
    {
        if (!$this->tableExists('transactions')) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT \'transaction\' AS source_type,
                    t.id AS source_id,
                    t.txn_date AS source_date,
                    COALESCE(t.description, t.counterparty_name, \'\') AS description,
                    t.amount,
                    na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM transactions t
             INNER JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.company_id = :company_id
               AND t.accounting_period_id = :accounting_period_id
               AND COALESCE(na.prepayment_candidate, 0) = 1
             ORDER BY t.txn_date DESC, t.id DESC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function fetchExpenseLineItems(int $companyId, int $accountingPeriodId): array
    {
        foreach (['expense_claims', 'expense_claim_lines'] as $table) {
            if (!$this->tableExists($table)) {
                return [];
            }
        }

        return \InterfaceDB::fetchAll(
            'SELECT \'expense_claim_line\' AS source_type,
                    ecl.id AS source_id,
                    ecl.expense_date AS source_date,
                    ecl.description,
                    ecl.amount,
                    na.id AS nominal_account_id,
                    na.code AS nominal_code,
                    na.name AS nominal_name
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             INNER JOIN nominal_accounts na ON na.id = ecl.nominal_account_id
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
               AND COALESCE(na.prepayment_candidate, 0) = 1
             ORDER BY ecl.expense_date DESC, ecl.id DESC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
    }

    private function fetchReviews(int $companyId, int $accountingPeriodId): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT source_type,
                    source_id,
                    status,
                    COALESCE(service_start_date, \'\') AS service_start_date,
                    COALESCE(service_end_date, \'\') AS service_end_date,
                    COALESCE(notes, \'\') AS notes,
                    COALESCE(reviewed_at, \'\') AS reviewed_at,
                    COALESCE(reviewed_by, \'\') AS reviewed_by,
                    generated_journal_id,
                    reversal_journal_id
             FROM prepayment_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        $reviews = [];
        foreach ((array)$rows as $row) {
            if (is_array($row)) {
                $row['status'] = trim((string)($row['status'] ?? '')) !== '' ? (string)$row['status'] : 'pending';
                $row['persisted'] = true;
                $reviews[$this->reviewKey((string)$row['source_type'], (int)$row['source_id'])] = $row;
            }
        }

        return $reviews;
    }

    private function isCompletedDecision(array $review): bool
    {
        if (empty($review['persisted'])) {
            return false;
        }

        $status = (string)($review['status'] ?? 'pending');
        if ($status === 'not_prepaid') {
            return true;
        }
        if ($status !== 'prepaid') {
            return false;
        }

        return trim((string)($review['service_start_date'] ?? '')) !== ''
            && trim((string)($review['service_end_date'] ?? '')) !== '';
    }

    private function sourceBelongsToCandidateNominal(int $companyId, int $accountingPeriodId, string $sourceType, int $sourceId): bool
    {
        if ($sourceType === 'transaction') {
            return (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM transactions t
                 INNER JOIN nominal_accounts na ON na.id = t.nominal_account_id
                 WHERE t.company_id = :company_id
                   AND t.accounting_period_id = :accounting_period_id
                   AND t.id = :source_id
                   AND COALESCE(na.prepayment_candidate, 0) = 1',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_id' => $sourceId,
                ]
            ) > 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM expense_claim_lines ecl
             INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
             INNER JOIN nominal_accounts na ON na.id = ecl.nominal_account_id
             WHERE ec.company_id = :company_id
               AND ec.accounting_period_id = :accounting_period_id
               AND ecl.id = :source_id
               AND COALESCE(na.prepayment_candidate, 0) = 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'source_id' => $sourceId,
            ]
        ) > 0;
    }

    private function reviewKey(string $sourceType, int $sourceId): string
    {
        return $sourceType . ':' . $sourceId;
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }

    private function actorValue(string $value): string
    {
        $value = trim($value);
        return $value !== '' ? $value : 'web_app';
    }

    private function tableExists(string $table): bool
    {
        try {
            return \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return \InterfaceDB::columnExists($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
