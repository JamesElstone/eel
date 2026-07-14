<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Repository;

final class DashboardRepository
{
    public function fetchDashboardData(
        int $companyId,
        int $accountingPeriodId,
        ?int $defaultBankNominalId = null,
        int $recentLimit = 12
    ): array
    {
        return [
            'stats' => $this->fetchDashboardStats($companyId, $accountingPeriodId),
            'activity' => $this->fetchDashboardActionQueue($companyId, $accountingPeriodId),
            'recent_transactions' => $this->fetchRecentTransactions($companyId, $accountingPeriodId, $defaultBankNominalId, $recentLimit),
        ];
    }

    public function fetchDashboardStats(int $companyId, int $accountingPeriodId): array
    {
        $stats = $this->emptyDashboardStats();

        if ($companyId <= 0) {
            return $stats;
        }

        $accountCounts = \InterfaceDB::fetchOne(
            'SELECT
                    COALESCE(SUM(CASE WHEN account_type = :bank_type THEN 1 ELSE 0 END), 0) AS bank_accounts,
                    COALESCE(SUM(CASE WHEN account_type = :trade_type THEN 1 ELSE 0 END), 0) AS trade_accounts
             FROM company_accounts
             WHERE company_id = :company_id
               AND is_active = 1',
            [
                'bank_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'trade_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                'company_id' => $companyId,
            ]
        ) ?: [];

        $stats['bank_accounts'] = (int)($accountCounts['bank_accounts'] ?? 0);
        $stats['trade_accounts'] = (int)($accountCounts['trade_accounts'] ?? 0);
        $stats['statement_uploads'] = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM statement_uploads
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );

        if ($accountingPeriodId <= 0) {
            return $stats;
        }

        $transactionCounts = $this->fetchTransactionDashboardCounts($companyId, $accountingPeriodId);
        $stats['unreconciled_items'] = (int)($transactionCounts['unreconciled_items'] ?? 0);
        $stats['draft_journals'] = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND source_type = :source_type',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'source_type' => 'manual',
            ]
        );
        $stats['staged_upload_rows'] = $this->countStagedUploadRows($companyId, $accountingPeriodId);

        return $stats;
    }

    public function fetchDashboardActionQueue(int $companyId, int $accountingPeriodId): array
    {
        $activity = [];
        $setupHealthActions = $this->fetchSetupHealthActions($companyId);

        if ($companyId <= 0) {
            return $this->finaliseActivity([
                [
                    'title' => 'Company required',
                    'detail' => 'A company must exist before dashboard activity can be calculated.',
                ],
            ], $setupHealthActions);
        }

        $bankAccountCount = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM company_accounts
             WHERE company_id = :company_id
               AND account_type = :account_type
               AND is_active = 1',
            [
                'company_id' => $companyId,
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            ]
        );
        $uploadCounts = $this->fetchActionQueueUploadCounts($companyId, $accountingPeriodId);
        $this->appendCompanySetupActions(
            $activity,
            $bankAccountCount,
            (int)($uploadCounts['statement_uploads'] ?? 0)
        );

        if ($accountingPeriodId <= 0) {
            return $this->finaliseActivity($activity, $setupHealthActions);
        }

        $transactionCounts = $this->fetchTransactionDashboardCounts($companyId, $accountingPeriodId);
        $this->appendMissingTransactionAction($activity, (int)($transactionCounts['transaction_count'] ?? 0));

        $uncategorisedCount = (int)($transactionCounts['unreconciled_items'] ?? 0);
        if ($uncategorisedCount > 0) {
            $activity[] = [
                'title' => 'Categorise uncategorised transactions',
                'detail' => $uncategorisedCount . ' transaction' . ($uncategorisedCount === 1 ? '' : 's') . ' still need to be categorised against a nominal account.',
            ];
        }

        $stagedUploadRows = $this->countStagedUploadRows($companyId, $accountingPeriodId);
        if ($stagedUploadRows > 0) {
            $activity[] = [
                'title' => 'Process staged upload rows',
                'detail' => $stagedUploadRows . ' staged upload row' . ($stagedUploadRows === 1 ? '' : 's') . ' still need processing.',
            ];
        }

        $duplicateUploads = (int)($uploadCounts['duplicate_uploads'] ?? 0);
        if ($duplicateUploads > 0) {
            $activity[] = [
                'title' => 'Review duplicate upload hits',
                'detail' => $duplicateUploads . ' upload' . ($duplicateUploads === 1 ? '' : 's') . ' reported duplicate rows.',
            ];
        }

        $emptyUploads = (int)($uploadCounts['empty_uploads'] ?? 0);
        if ($emptyUploads > 0) {
            $activity[] = [
                'title' => 'Check empty imports',
                'detail' => $emptyUploads . ' upload' . ($emptyUploads === 1 ? '' : 's') . ' inserted no rows and may need inspection.',
            ];
        }

        return $this->finaliseActivity($activity, $setupHealthActions);
    }

    private function fetchActionQueueUploadCounts(int $companyId, int $accountingPeriodId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS statement_uploads,
                    COALESCE(SUM(CASE
                        WHEN accounting_period_id = :duplicate_period_id AND rows_duplicate > 0 THEN 1
                        ELSE 0
                    END), 0) AS duplicate_uploads,
                    COALESCE(SUM(CASE
                        WHEN accounting_period_id = :empty_period_id AND rows_inserted = 0 THEN 1
                        ELSE 0
                    END), 0) AS empty_uploads
             FROM statement_uploads
             WHERE company_id = :company_id',
            [
                'duplicate_period_id' => $accountingPeriodId,
                'empty_period_id' => $accountingPeriodId,
                'company_id' => $companyId,
            ]
        ) ?: [];

        return [
            'statement_uploads' => (int)($row['statement_uploads'] ?? 0),
            'duplicate_uploads' => (int)($row['duplicate_uploads'] ?? 0),
            'empty_uploads' => (int)($row['empty_uploads'] ?? 0),
        ];
    }

    public function fetchRecentTransactions(
        int $companyId,
        int $accountingPeriodId,
        ?int $defaultBankNominalId = null,
        int $recentLimit = 100
    ): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        if ($defaultBankNominalId === null) {
            $defaultBankNominalId = (int)((new \eel_accounts\Store\CompanySettingsStore($companyId))->all()['default_bank_nominal_id'] ?? 0);
        }

        $recentLimit = max(1, min($recentLimit, 100));
        $sql = "SELECT t.txn_date AS date,
                       COALESCE(NULLIF(t.source_account_label, ''), bank_na.name, 'Bank') AS account,
                       t.description,
                       COALESCE(cat_na.name, 'Uncategorised') AS category,
                       COALESCE(t.currency, '') AS currency,
                       t.amount,
                       CASE
                           WHEN t.category_status = 'uncategorised' THEN 'Needs review'
                           WHEN t.category_status = 'manual' THEN 'Posted'
                           ELSE 'Matched'
                       END AS status,
                       COALESCE(t.source_category, '') AS source_category,
                       COALESCE(t.document_download_status, 'skipped') AS document_status,
                       t.local_document_path,
                       t.source_document_url
                FROM transactions t
                LEFT JOIN nominal_accounts bank_na ON bank_na.id = ?
                LEFT JOIN nominal_accounts cat_na ON cat_na.id = t.nominal_account_id
                WHERE t.company_id = ? AND t.accounting_period_id = ?
                ORDER BY t.txn_date DESC, t.id DESC
                LIMIT {$recentLimit}";

        $stmt = \InterfaceDB::prepare($sql);
        $stmt->execute([
            $defaultBankNominalId,
            $companyId,
            $accountingPeriodId,
        ]);

        return $stmt->fetchAll();
    }

    private function emptyDashboardStats(): array
    {
        return [
            'bank_accounts' => 0,
            'trade_accounts' => 0,
            'statement_uploads' => 0,
            'unreconciled_items' => 0,
            'draft_journals' => 0,
            'staged_upload_rows' => 0,
        ];
    }

    private function fetchTransactionDashboardCounts(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'transaction_count' => 0,
                'unreconciled_items' => 0,
            ];
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT COUNT(*) AS transaction_count,
                    COALESCE(SUM(CASE WHEN category_status = :category_status THEN 1 ELSE 0 END), 0) AS unreconciled_items
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            [
                'category_status' => 'uncategorised',
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        ) ?: [];

        return [
            'transaction_count' => (int)($row['transaction_count'] ?? 0),
            'unreconciled_items' => (int)($row['unreconciled_items'] ?? 0),
        ];
    }

    private function finaliseActivity(array $activity, array $setupHealthActions): array
    {
        if ($activity === [] && $setupHealthActions === []) {
            return [
                [
                    'title' => 'No setup or transaction actions',
                    'detail' => 'No immediate setup, import, categorisation, or draft-journal actions were found. Review the separate Year End readiness card for close and filing issues.',
                    'state' => 'ok',
                ],
            ];
        }

        return array_merge(array_slice($activity, 0, 6), $setupHealthActions);
    }

    private function appendCompanySetupActions(array &$activity, int $bankAccountCount, int $statementUploadCount): void
    {
        if ($bankAccountCount < 1) {
            $activity[] = [
                'title' => 'Create a bank account',
                'detail' => 'No bank accounts have been created for this company.',
            ];
        }

        if ($statementUploadCount < 1) {
            $activity[] = [
                'title' => 'Upload bank statement files',
                'detail' => 'No bank statement files have been uploaded for this company yet.',
            ];
        }
    }

    private function appendMissingTransactionAction(array &$activity, int $transactionCount): void
    {
        if ($transactionCount > 0) {
            return;
        }

        $activity[] = [
            'title' => 'Import transactions for this year',
            'detail' => 'The selected accounting period is missing any transaction records.',
        ];
    }

    private function countStagedUploadRows(int $companyId, int $accountingPeriodId): int
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return 0;
        }

        $stmt = \InterfaceDB::prepare(
            "SELECT COUNT(*)
             FROM statement_import_rows sir
             INNER JOIN statement_uploads su ON su.id = sir.upload_id
             WHERE su.company_id = ?
               AND sir.accounting_period_id = ?
               AND su.workflow_status IN ('mapped', 'staged')
               AND sir.committed_transaction_id IS NULL"
        );
        $stmt->execute([$companyId, $accountingPeriodId]);

        return (int)$stmt->fetchColumn();
    }

    private function fetchSetupHealthActions(int $companyId): array
    {
        return $this->setupHealthContextToActionItems((new \eel_accounts\Service\SetupHealthService())->buildContext($companyId));
    }

    private function setupHealthContextToActionItems(array $healthContext): array
    {
        $items = array_merge(
            (array)($healthContext['installation_setup_health_items'] ?? []),
            (array)($healthContext['company_setup_health_items'] ?? [])
        );
        $actions = [];

        foreach ($items as $item) {
            if (!is_array($item) || $this->setupHealthItemState($item) !== 'bad') {
                continue;
            }

            $title = trim((string)($item['title'] ?? ''));
            $actions[] = [
                'title' => $title !== '' ? 'Company Health: ' . $title : 'Company Health Issue',
                'detail' => (string)($item['detail'] ?? 'This setup check needs attention.'),
            ];
        }

        return $actions;
    }

    private function setupHealthItemState(array $item): string
    {
        $state = strtolower(trim((string)($item['state'] ?? '')));

        if (in_array($state, ['ok', 'warn', 'bad'], true)) {
            return $state;
        }

        return !empty($item['ok']) ? 'ok' : 'bad';
    }

    public function normaliseTransactionMonthFilter(?string $monthKey): string
    {
        $monthKey = trim((string)$monthKey);

        return preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1 ? $monthKey : '';
    }

    public function normaliseTransactionCategoryFilter(?string $filter): string
    {
        $filter = strtolower(trim((string)$filter));

        return in_array($filter, ['all', 'not_posted', 'uncategorised', 'manual', 'auto_assigned', 'auto_unreviewed', 'auto_unposted', 'auto_confirmed'], true) ? $filter : 'all';
    }

    public function normaliseTransactionAccountFilter(mixed $filter): int
    {
        return max(0, (int)$filter);
    }

    public function normaliseSearchCategoryStatus(?string $filter): string
    {
        $filter = trim((string)$filter);

        return in_array($filter, ['uncategorised', 'auto', 'manual'], true) ? $filter : '';
    }

    public function normaliseAutoApprovalFilter(?string $filter): string
    {
        $filter = strtolower(trim((string)$filter));
        $aliases = [
            'unconfirmed' => 'pending',
            'correct' => 'confirmed',
            'awaiting_post_confirmation' => 'post_pending',
            'awaiting post confirmation' => 'post_pending',
        ];
        $filter = $aliases[$filter] ?? $filter;

        return in_array($filter, ['pending', 'confirmed', 'post_pending'], true) ? $filter : '';
    }

    public function defaultTransactionMonth(array $monthStatus): string
    {
        $currentMonthKey = (new \DateTimeImmutable('first day of this month'))->format('Y-m-01');

        foreach ($monthStatus as $month) {
            if ((string)($month['month_key'] ?? '') === $currentMonthKey) {
                return $currentMonthKey;
            }
        }

        foreach ($monthStatus as $month) {
            if ((int)($month['uncategorised'] ?? 0) > 0 && !empty($month['month_key'])) {
                return (string)$month['month_key'];
            }
        }

        foreach ($monthStatus as $month) {
            if ((int)($month['transactions'] ?? 0) > 0 && !empty($month['month_key'])) {
                return (string)$month['month_key'];
            }
        }

        return isset($monthStatus[0]['month_key']) ? (string)$monthStatus[0]['month_key'] : '';
    }

    public function defaultTransactionMonthForPeriod(int $companyId, int $accountingPeriodId): string
    {
        $companyId = \HelperFramework::sanitiseId($companyId);
        $accountingPeriodId = \HelperFramework::sanitiseId($accountingPeriodId);
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return '';
        }

        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null || empty($accountingPeriod['period_start']) || empty($accountingPeriod['period_end'])) {
            return '';
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $currentMonthKey = (new \DateTimeImmutable('first day of this month'))->format('Y-m-01');
        if ($this->monthStartInPeriod($currentMonthKey, $periodStart, $periodEnd)) {
            return $currentMonthKey;
        }

        $uncategorisedMonth = $this->firstUncategorisedTransactionMonth($companyId, $periodStart, $periodEnd);
        if ($uncategorisedMonth !== '') {
            return $uncategorisedMonth;
        }

        $transactionMonth = $this->firstTransactionMonth($companyId, $periodStart, $periodEnd);
        if ($transactionMonth !== '') {
            return $transactionMonth;
        }

        return $this->normalisedMonthStart($periodStart);
    }

    private function firstUncategorisedTransactionMonth(int $companyId, string $periodStart, string $periodEnd): string
    {
        $monthExpression = $this->monthKeyExpression('t.txn_date');
        $splitStatusJoin = '';
        $readySplitPredicate = '0 = 1';
        if (\InterfaceDB::tableExists('transaction_splits') && \InterfaceDB::tableExists('transaction_split_lines')) {
            $splitStatusJoin = "LEFT JOIN (
                                    SELECT ts.transaction_id,
                                           CASE
                                               WHEN SUM(CASE WHEN COALESCE(tsl.is_deferred, 0) = 0 THEN 1 ELSE 0 END) >= 2
                                                AND SUM(CASE WHEN COALESCE(tsl.is_deferred, 0) = 1 THEN 1 ELSE 0 END) = 0
                                                AND SUM(
                                                    CASE
                                                        WHEN COALESCE(tsl.is_deferred, 0) = 0
                                                         AND (tsl.amount IS NULL OR tsl.amount <= 0 OR tsl.nominal_account_id IS NULL)
                                                        THEN 1
                                                        ELSE 0
                                                    END
                                                ) = 0
                                                AND ABS(ROUND(ABS(st.amount) - COALESCE(SUM(CASE WHEN COALESCE(tsl.is_deferred, 0) = 0 THEN tsl.amount ELSE 0 END), 0), 2)) < 0.005
                                               THEN 1
                                               ELSE 0
                                           END AS is_ready
                                    FROM transaction_splits ts
                                    INNER JOIN transactions st
                                       ON st.id = ts.transaction_id
                                    INNER JOIN transaction_split_lines tsl
                                       ON tsl.split_id = ts.id
                                    GROUP BY ts.id, ts.transaction_id, st.amount
                                ) split_status
                                   ON split_status.transaction_id = t.id";
            $readySplitPredicate = 'COALESCE(split_status.is_ready, 0) = 1';
        }

        $validTransferPredicate = "(COALESCE(t.is_internal_transfer, 0) = 1 AND COALESCE(t.transfer_account_id, 0) > 0 AND t.category_status = 'manual')";
        $interAccountNoPostPredicate = \InterfaceDB::tableExists('transaction_inter_ac_marker')
            ? "EXISTS (
                  SELECT 1
                  FROM transaction_inter_ac_marker tiam
                  WHERE tiam.matched_transaction_id = t.id
              )"
            : '0 = 1';
        $uncategorisedPredicate = "(
            NOT ({$interAccountNoPostPredicate})
            AND (
                t.category_status = 'uncategorised'
                OR (
                    t.nominal_account_id IS NULL
                    AND NOT ({$readySplitPredicate})
                    AND NOT ({$validTransferPredicate})
                )
            )
        )";

        return (string)(\InterfaceDB::fetchColumn(
            "SELECT {$monthExpression} AS month_key
             FROM transactions t
             {$splitStatusJoin}
             WHERE t.company_id = :company_id
               AND t.txn_date BETWEEN :period_start AND :period_end
               AND {$uncategorisedPredicate}
             GROUP BY {$monthExpression}
             ORDER BY month_key ASC
             LIMIT 1",
            [
                'company_id' => $companyId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) ?: '');
    }

    private function firstTransactionMonth(int $companyId, string $periodStart, string $periodEnd): string
    {
        $monthExpression = $this->monthKeyExpression('txn_date');

        return (string)(\InterfaceDB::fetchColumn(
            "SELECT {$monthExpression} AS month_key
             FROM transactions
             WHERE company_id = :company_id
               AND txn_date BETWEEN :period_start AND :period_end
             GROUP BY {$monthExpression}
             ORDER BY month_key ASC
             LIMIT 1",
            [
                'company_id' => $companyId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) ?: '');
    }

    private function monthStartInPeriod(string $monthStart, string $periodStart, string $periodEnd): bool
    {
        $monthStart = $this->normalisedMonthStart($monthStart);
        $periodStart = $this->normalisedMonthStart($periodStart);
        $periodEnd = $this->normalisedMonthStart($periodEnd);

        return $monthStart !== ''
            && $periodStart !== ''
            && $periodEnd !== ''
            && $monthStart >= $periodStart
            && $monthStart <= $periodEnd;
    }

    private function normalisedMonthStart(string $date): string
    {
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return '';
        }

        return (new \DateTimeImmutable($date))->modify('first day of this month')->format('Y-m-01');
    }

    private function monthKeyExpression(string $column): string
    {
        return \InterfaceDB::driverName() === 'sqlite'
            ? "strftime('%Y-%m-01', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m-01')";
    }

    public function fetchTransactionsForMonth(
        int $companyId,
        int $accountingPeriodId,
        string $monthKey,
        string $categoryFilter = 'all',
        int $limit = 500,
        int $accountFilter = 0
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null || empty($accountingPeriod['period_start']) || empty($accountingPeriod['period_end'])) {
            return [];
        }

        $monthKey = $this->normaliseTransactionMonthFilter($monthKey);
        $categoryFilter = $this->normaliseTransactionCategoryFilter($categoryFilter);
        $accountFilter = $this->normaliseTransactionAccountFilter($accountFilter);
        $limit = max(1, min($limit, 1000));

        $where = [
            't.company_id = :company_id',
            't.txn_date BETWEEN :period_start AND :period_end',
        ];
        $params = [
            'company_id' => $companyId,
            'period_start' => (string)$accountingPeriod['period_start'],
            'period_end' => (string)$accountingPeriod['period_end'],
        ];

        if ($monthKey !== '') {
            $monthStart = new \DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        if ($accountFilter > 0) {
            $where[] = 't.account_id = :account_filter';
            $params['account_filter'] = $accountFilter;
        }

        $hasInterAccountMarkers = \InterfaceDB::tableExists('transaction_inter_ac_marker');
        if ($categoryFilter === 'not_posted') {
            $where[] = "NOT EXISTS(
                SELECT 1
                FROM journals j
                WHERE j.source_type = 'bank_csv'
                  AND j.source_ref = CONCAT('transaction:', t.id)
            )";
            if ($hasInterAccountMarkers) {
                $where[] = 'NOT EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker filter_tiam
                    WHERE filter_tiam.matched_transaction_id = t.id
                )';
            }
        } elseif (in_array($categoryFilter, ['auto_assigned', 'auto_unreviewed', 'auto_unposted', 'auto_confirmed'], true)) {
            $where[] = 't.category_status = :category_status';
            $where[] = 't.auto_rule_id IS NOT NULL';
            $where[] = 't.auto_rule_id > 0';
            $params['category_status'] = 'auto';
            if ($categoryFilter === 'auto_unreviewed') {
                $where[] = 'NOT (' . \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't') . ')';
            } elseif ($categoryFilter === 'auto_unposted') {
                $where[] = "NOT EXISTS(
                    SELECT 1
                    FROM journals auto_filter_j
                    WHERE auto_filter_j.source_type = 'bank_csv'
                      AND auto_filter_j.source_ref = CONCAT('transaction:', t.id)
                )";
            } elseif ($categoryFilter === 'auto_confirmed') {
                $where[] = \eel_accounts\Service\TransactionAutoApprovalService::currentApprovalSql('taa', 't');
            }
            if ($hasInterAccountMarkers) {
                $where[] = 'NOT EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker filter_tiam
                    WHERE filter_tiam.transaction_id = t.id
                       OR filter_tiam.matched_transaction_id = t.id
                )';
            }
        } elseif ($categoryFilter !== 'all') {
            $where[] = 't.category_status = :category_status';
            $params['category_status'] = $categoryFilter;
            if ($categoryFilter === 'uncategorised' && $hasInterAccountMarkers) {
                $where[] = 'NOT EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker filter_tiam
                    WHERE filter_tiam.matched_transaction_id = t.id
                )';
            }
        }

        $interAccountSelect = $hasInterAccountMarkers
            ? ",
                       COALESCE(tiam_source.id, tiam_matched.id, 0) AS inter_ac_marker_id,
                       CASE
                           WHEN tiam_source.id IS NOT NULL THEN 'source'
                           WHEN tiam_matched.id IS NOT NULL THEN 'matched'
                           ELSE ''
                       END AS inter_ac_marker_role,
                       COALESCE(tiam_source.transaction_id, tiam_matched.transaction_id, 0) AS inter_ac_source_transaction_id,
                       COALESCE(tiam_source.matched_transaction_id, tiam_matched.matched_transaction_id, 0) AS inter_ac_matched_transaction_id,
                       COALESCE(tiam_source.created_by, tiam_matched.created_by, '') AS inter_ac_created_by,
                       COALESCE(peer_t.id, 0) AS inter_ac_peer_transaction_id,
                       COALESCE(peer_t.txn_date, '') AS inter_ac_peer_txn_date,
                       COALESCE(peer_t.description, '') AS inter_ac_peer_description,
                       COALESCE(peer_t.amount, 0.00) AS inter_ac_peer_amount,
                       COALESCE(peer_ca.account_name, '') AS inter_ac_peer_account_name"
            : ",
                       0 AS inter_ac_marker_id,
                       '' AS inter_ac_marker_role,
                       0 AS inter_ac_source_transaction_id,
                       0 AS inter_ac_matched_transaction_id,
                       '' AS inter_ac_created_by,
                       0 AS inter_ac_peer_transaction_id,
                       '' AS inter_ac_peer_txn_date,
                       '' AS inter_ac_peer_description,
                       0.00 AS inter_ac_peer_amount,
                       '' AS inter_ac_peer_account_name";
        $interAccountJoin = $hasInterAccountMarkers
            ? "LEFT JOIN transaction_inter_ac_marker tiam_source ON tiam_source.transaction_id = t.id
                LEFT JOIN transaction_inter_ac_marker tiam_matched ON tiam_matched.matched_transaction_id = t.id
                LEFT JOIN transactions peer_t
                  ON peer_t.id = CASE
                      WHEN tiam_source.id IS NOT NULL THEN tiam_source.matched_transaction_id
                      WHEN tiam_matched.id IS NOT NULL THEN tiam_matched.transaction_id
                      ELSE 0
                  END
                LEFT JOIN company_accounts peer_ca ON peer_ca.id = peer_t.account_id"
            : '';
        $hasDerivedJournalSql = $hasInterAccountMarkers
            ? "CASE
                           WHEN tiam_matched.id IS NOT NULL THEN 0
                           ELSE EXISTS(
                               SELECT 1
                               FROM journals j
                               WHERE j.source_type = 'bank_csv'
                                 AND j.source_ref = CONCAT('transaction:', t.id)
                           )
                       END"
            : "EXISTS(
                           SELECT 1
                           FROM journals j
                           WHERE j.source_type = 'bank_csv'
                             AND j.source_ref = CONCAT('transaction:', t.id)
                       )";
        $internalTransferMarkerSql = \InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')
            ? "COALESCE(ca.internal_transfer_marker, '')"
            : "''";

        $sql = "SELECT t.id,
                       t.account_id,
                       t.txn_date,
                       COALESCE(t.txn_type, '') AS txn_type,
                       t.description,
                       COALESCE(t.reference, '') AS reference,
                       t.amount,
                       COALESCE(t.currency, '') AS currency,
                       COALESCE(t.source_account_label, '') AS source_account,
                       COALESCE(t.source_category, '') AS source_category,
                       COALESCE(t.source_document_url, '') AS source_document_url,
                       COALESCE(t.local_document_path, '') AS local_document_path,
                       COALESCE(t.document_download_status, 'skipped') AS document_download_status,
                       COALESCE(t.document_error, '') AS document_error,
                       COALESCE(t.notes, '') AS notes,
                       t.nominal_account_id,
                       t.transfer_account_id,
                       COALESCE(t.is_internal_transfer, 0) AS is_internal_transfer,
                       {$internalTransferMarkerSql} AS internal_transfer_marker,
                       COALESCE(ca.account_name, '') AS owned_account_name,
                       COALESCE(ta.account_name, '') AS transfer_account_name,
                       COALESCE(na.code, '') AS nominal_code,
                       COALESCE(na.name, '') AS assigned_nominal,
                       t.category_status,
                       COALESCE(t.auto_rule_id, 0) AS auto_rule_id,
                       COALESCE(cr.desc_match_value, '') AS auto_rule_match_value,
                       COALESCE(t.is_auto_excluded, 0) AS is_auto_excluded,
                       COALESCE(taa.state, '') AS auto_approval_state,
                       taa.state_change_at AS auto_approval_state_change_at,
                       taa.state_change_transaction_updated_at AS auto_approval_state_change_transaction_updated_at,
                       taa.confirmed_at AS auto_approval_confirmed_at,
                       taa.confirmed_transaction_updated_at AS auto_approval_confirmed_transaction_updated_at,
                       " . \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't') . " AS auto_approval_checked_current,
                       " . \eel_accounts\Service\TransactionAutoApprovalService::currentApprovalSql('taa', 't') . " AS auto_approval_confirmed_current,
                       {$hasDerivedJournalSql} AS has_derived_journal,
                       EXISTS(
                           SELECT 1
                           FROM journals dividend_j
                           WHERE dividend_j.company_id = t.company_id
                             AND dividend_j.source_type = 'manual'
                             AND dividend_j.source_ref = CONCAT('dividend:transaction:', t.id)
                       ) AS has_dividend_declaration
                       {$interAccountSelect}
                FROM transactions t
                LEFT JOIN company_accounts ca ON ca.id = t.account_id
                LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
                LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
                LEFT JOIN categorisation_rules cr ON cr.id = t.auto_rule_id
                LEFT JOIN transaction_auto_approvals taa ON taa.transaction_id = t.id
                {$interAccountJoin}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.txn_date DESC, t.id DESC
                LIMIT {$limit}";

        $stmt = \InterfaceDB::prepare($sql);
        $stmt->execute($params);

        return (new \eel_accounts\Service\TransactionSplitService())->attachSplitsToTransactions($stmt->fetchAll());
    }

    public function searchTransactions(
        int $companyId,
        int $accountingPeriodId,
        string $keyword,
        int $sourceAccountId = 0,
        array|string $nominalAccountIds = [],
        int $limit = 5000,
        ?string $amount = '',
        ?string $flow = 'any',
        ?string $categoryStatus = '',
        ?string $autoApprovalFilter = ''
    ): array {
        $keyword = trim($keyword);
        $flow = $this->normaliseTransactionFlowFilter($flow);
        $amount = $this->normaliseTransactionAmountFilter($amount, $flow);
        $categoryStatus = $this->normaliseSearchCategoryStatus($categoryStatus);
        $autoApprovalFilter = $this->normaliseAutoApprovalFilter($autoApprovalFilter);
        $nominalAccountIds = $this->normalisePositiveIntList($nominalAccountIds);
        $sourceAccountId = max(0, $sourceAccountId);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || ($keyword === '' && $amount === '' && $flow === 'any' && $sourceAccountId <= 0 && $nominalAccountIds === [] && $categoryStatus === '' && $autoApprovalFilter === '')) {
            return [];
        }

        $limit = max(1, min($limit, 5000));
        $where = [
            't.company_id = :company_id',
            't.accounting_period_id = :accounting_period_id',
        ];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];

        if ($keyword !== '') {
            $where[] = "(t.description LIKE :keyword ESCAPE '\\\\' OR COALESCE(t.reference, '') LIKE :keyword ESCAPE '\\\\')";
            $params['keyword'] = '%' . $this->escapeLike($keyword) . '%';
        }

        if ($sourceAccountId > 0) {
            $where[] = 't.account_id = :source_account_id';
            $params['source_account_id'] = $sourceAccountId;
        }

        if ($amount !== '') {
            $where[] = 't.amount = :amount';
            $params['amount'] = $amount;
        } elseif ($flow === 'in') {
            $where[] = 't.amount > 0';
        } elseif ($flow === 'out') {
            $where[] = 't.amount < 0';
        }

        if ($nominalAccountIds !== []) {
            $placeholders = [];
            foreach ($nominalAccountIds as $index => $nominalAccountId) {
                $key = 'nominal_account_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $nominalAccountId;
            }

            if ((new \eel_accounts\Service\TransactionSplitService())->hasSchema()) {
                $where[] = '(t.nominal_account_id IN (' . implode(', ', $placeholders) . ')
                    OR EXISTS (
                        SELECT 1
                        FROM transaction_splits filter_ts
                        INNER JOIN transaction_split_lines filter_tsl ON filter_tsl.split_id = filter_ts.id
                        WHERE filter_ts.transaction_id = t.id
                          AND filter_tsl.nominal_account_id IN (' . implode(', ', $placeholders) . ')
                    ))';
            } else {
                $where[] = 't.nominal_account_id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        if ($categoryStatus !== '') {
            $where[] = 't.category_status = :search_category_status';
            $params['search_category_status'] = $categoryStatus;
        }

        if ($autoApprovalFilter === 'pending') {
            $where[] = 't.category_status = :auto_approval_category_status';
            $where[] = 't.auto_rule_id IS NOT NULL';
            $where[] = 't.auto_rule_id > 0';
            $where[] = 'NOT (' . \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't') . ')';
            $params['auto_approval_category_status'] = 'auto';
        } elseif ($autoApprovalFilter === 'post_pending') {
            $where[] = 't.category_status = :auto_approval_category_status';
            $where[] = 't.auto_rule_id IS NOT NULL';
            $where[] = 't.auto_rule_id > 0';
            $where[] = \eel_accounts\Service\TransactionAutoApprovalService::currentPostConfirmationPendingSql('taa', 't');
            $params['auto_approval_category_status'] = 'auto';
        } elseif ($autoApprovalFilter === 'confirmed') {
            $where[] = \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't');
        }

        $sql = "SELECT t.id,
                       t.statement_upload_id,
                       t.account_id,
                       t.txn_date,
                       " . $this->transactionMonthKeySql('t.txn_date') . " AS month_key,
                       COALESCE(t.txn_type, '') AS txn_type,
                       t.description,
                       COALESCE(t.reference, '') AS reference,
                       t.amount,
                       COALESCE(t.currency, '') AS currency,
                       t.balance,
                       COALESCE(t.source_account_label, '') AS source_account_label,
                       COALESCE(t.source_category, '') AS source_category,
                       COALESCE(t.document_download_status, 'skipped') AS document_download_status,
                       t.nominal_account_id,
                       t.transfer_account_id,
                       COALESCE(t.is_internal_transfer, 0) AS is_internal_transfer,
                       COALESCE(ca.account_name, '') AS owned_account_name,
                       COALESCE(ca.institution_name, '') AS owned_institution_name,
                       COALESCE(ta.account_name, '') AS transfer_account_name,
                       COALESCE(na.code, '') AS nominal_code,
                       COALESCE(na.name, '') AS assigned_nominal,
                       t.category_status,
                       COALESCE(t.auto_rule_id, 0) AS auto_rule_id,
                       COALESCE(cr.desc_match_value, '') AS auto_rule_match_value,
                       COALESCE(cr.ref_match_value, '') AS auto_rule_reference_match_value,
                       COALESCE(t.is_auto_excluded, 0) AS is_auto_excluded,
                       COALESCE(t.notes, '') AS notes,
                       t.created_at,
                       t.updated_at,
                       COALESCE(taa.state, '') AS auto_approval_state,
                       taa.state_change_at AS auto_approval_state_change_at,
                       taa.state_change_transaction_updated_at AS auto_approval_state_change_transaction_updated_at,
                       taa.confirmed_at AS auto_approval_confirmed_at,
                       taa.confirmed_transaction_updated_at AS auto_approval_confirmed_transaction_updated_at,
                       " . \eel_accounts\Service\TransactionAutoApprovalService::currentCheckedSql('taa', 't') . " AS auto_approval_checked_current,
                       " . \eel_accounts\Service\TransactionAutoApprovalService::currentApprovalSql('taa', 't') . " AS auto_approval_confirmed_current,
                       EXISTS(
                           SELECT 1
                           FROM journals j
                           WHERE j.source_type = 'bank_csv'
                             AND j.source_ref = CONCAT('transaction:', t.id)
                       ) AS has_derived_journal,
                       EXISTS(
                           SELECT 1
                           FROM journals dividend_j
                           WHERE dividend_j.company_id = t.company_id
                             AND dividend_j.source_type = 'manual'
                             AND dividend_j.source_ref = CONCAT('dividend:transaction:', t.id)
                       ) AS has_dividend_declaration
                FROM transactions t
                LEFT JOIN company_accounts ca ON ca.id = t.account_id
                LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
                LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
                LEFT JOIN categorisation_rules cr ON cr.id = t.auto_rule_id
                LEFT JOIN transaction_auto_approvals taa ON taa.transaction_id = t.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.txn_date DESC, t.id DESC
                LIMIT {$limit}";

        $stmt = \InterfaceDB::prepare($sql);
        $stmt->execute($params);

        return (new \eel_accounts\Service\TransactionSplitService())->attachSplitsToTransactions($stmt->fetchAll());
    }

    private function transactionMonthKeySql(string $dateExpression): string
    {
        if (\InterfaceDB::driverName() === 'sqlite') {
            return "SUBSTR(" . $dateExpression . ", 1, 7) || '-01'";
        }

        return "DATE_FORMAT(" . $dateExpression . ", '%Y-%m-01')";
    }

    public function normaliseTransactionAmountFilter(?string $value, ?string $flow = 'any'): string
    {
        $value = trim(str_replace("\xC2\xA3", '', (string)$value));
        $flow = $this->normaliseTransactionFlowFilter($flow);

        if ($value === '' || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = round((float)$value, 2);
        if ($flow === 'in') {
            $amount = abs($amount);
        } elseif ($flow === 'out') {
            $amount = 0 - abs($amount);
        }

        if (abs($amount) < 0.005) {
            $amount = 0.0;
        }

        return number_format($amount, 2, '.', '');
    }

    public function normaliseTransactionFlowFilter(?string $value): string
    {
        $value = strtolower(trim((string)$value));

        return in_array($value, ['in', 'out'], true) ? $value : 'any';
    }

    private function normalisePositiveIntList(array|string $values): array
    {
        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        }

        $normalised = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $normalised[$id] = $id;
            }
        }

        return array_values($normalised);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

}
