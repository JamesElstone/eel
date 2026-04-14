<?php
declare(strict_types=1);

final class DashboardRepository
{
    public function fetchStats(int $companyId, int $taxYearId): array
    {
        $stats = [
            'bank_accounts' => 0,
            'unreconciled_items' => 0,
            'draft_journals' => 0,
            'vat_returns_due' => 0,
        ];

        if ($companyId <= 0) {
            return $stats;
        }

        $stmt = InterfaceDB::prepareExecute("SELECT COUNT(*) FROM company_accounts WHERE company_id = ? AND is_active = 1 AND account_type = 'bank'", [$companyId]);
        $stats['bank_accounts'] = (int)$stmt->fetchColumn();

        if ($taxYearId > 0) {
            $stmt = InterfaceDB::prepareExecute("SELECT COUNT(*) FROM transactions WHERE company_id = ? AND tax_year_id = ? AND category_status = 'uncategorised'", [$companyId, $taxYearId]);
            $stats['unreconciled_items'] = (int)$stmt->fetchColumn();

            $stmt = InterfaceDB::prepareExecute("SELECT COUNT(*) FROM journals WHERE company_id = ? AND tax_year_id = ? AND source_type = 'manual'", [$companyId, $taxYearId]);
            $stats['draft_journals'] = (int)$stmt->fetchColumn();
        }

        return $stats;
    }

    public function fetchRecentTransactions(int $companyId, int $taxYearId, ?int $defaultBankNominalId = null, int $limit = 12): array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 100));
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
                WHERE t.company_id = ? AND t.tax_year_id = ?
                ORDER BY t.txn_date DESC, t.id DESC
                LIMIT {$limit}";

        $stmt = InterfaceDB::prepare($sql);
        $stmt->execute([
            $defaultBankNominalId,
            $companyId,
            $taxYearId,
        ]);
        return $stmt->fetchAll();
    }

    public function fetchUploadHistory(int $companyId, int $taxYearId, ?int $limit = null, int $offset = 0): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $offset = max(0, $offset);
        $sql = "SELECT su.id,
                       su.uploaded_at AS uploaded_at_sort,
                       DATE_FORMAT(su.uploaded_at, '%Y-%m-%d %H:%i') AS uploaded_at,
                       su.source_type,
                       su.workflow_status,
                       su.original_filename AS filename,
                       CASE
                           WHEN su.date_range_start IS NOT NULL AND su.date_range_end IS NOT NULL
                               THEN CONCAT(DATE_FORMAT(su.date_range_start, '%d/%m/%Y'), ' to ', DATE_FORMAT(su.date_range_end, '%d/%m/%Y'))
                           ELSE DATE_FORMAT(su.statement_month, '%b %Y')
                       END AS month,
                       su.rows_committed AS inserted,
                       su.rows_duplicate AS duplicates,
                       su.rows_valid,
                       su.rows_invalid,
                       su.rows_ready_to_import,
                       su.rows_parsed,
                       su.stored_filename AS stored_filename,
                       su.source_headers_json,
                       su.account_id,
                       COALESCE(ca.account_name, '') AS account_name,
                       COALESCE(ca.account_type, '') AS account_type,
                       COALESCE(sim.original_headers_json, '') AS mapping_headers_json
                FROM statement_uploads su
                LEFT JOIN company_accounts ca
                    ON ca.id = su.account_id
                   AND ca.company_id = su.company_id
                LEFT JOIN statement_import_mappings sim
                    ON sim.upload_id = su.id
                WHERE su.company_id = ?";

        $params = [$companyId];

        if ($taxYearId > 0) {
            $sql .= " AND su.tax_year_id = ?";
            $params[] = $taxYearId;
        }

        $sql .= "
                ORDER BY su.uploaded_at DESC, su.id DESC";

        if ($limit !== null) {
            $limit = max(1, min($limit, 500));
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limit, $offset);
        }

        $stmt = InterfaceDB::prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function buildMonthStatus(int $companyId, int $taxYearId): array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $taxYearRepository = new TaxYearRepository();
        $taxYear = $taxYearRepository->fetchTaxYear($companyId, $taxYearId);

        if ($taxYear === null || empty($taxYear['period_start']) || empty($taxYear['period_end'])) {
            return [];
        }

        $summaryStmt = InterfaceDB::prepare("SELECT DATE_FORMAT(txn_date, '%Y-%m-01') AS month_key,
                                             COUNT(*) AS txn_count,
                                             SUM(CASE WHEN category_status = 'uncategorised' THEN 1 ELSE 0 END) AS uncategorised_count,
                                             SUM(CASE WHEN is_auto_excluded = 1 THEN 1 ELSE 0 END) AS deferred_count,
                                             SUM(
                                                 CASE
                                                     WHEN category_status IN ('auto', 'manual')
                                                       AND nominal_account_id IS NOT NULL
                                                       AND NOT EXISTS (
                                                           SELECT 1
                                                           FROM journals j
                                                           WHERE j.source_type = 'bank_csv'
                                                             AND j.source_ref = CONCAT('transaction:', transactions.id)
                                                       )
                                                     THEN 1
                                                     ELSE 0
                                                 END
                                             ) AS ready_to_post_count
                                      FROM transactions
                                      WHERE company_id = ? AND tax_year_id = ?
                                      GROUP BY DATE_FORMAT(txn_date, '%Y-%m-01')
                                      ORDER BY month_key");
        $summaryStmt->execute([$companyId, $taxYearId]);
        $summaries = [];
        foreach ($summaryStmt->fetchAll() as $row) {
            $summaries[$row['month_key']] = $row;
        }

        $months = [];
        $cursor = new DateTime((string)$taxYear['period_start']);
        $end = new DateTime((string)$taxYear['period_end']);
        $cursor->modify('first day of this month');
        $end->modify('first day of this month');

        while ($cursor <= $end) {
            $monthKey = $cursor->format('Y-m-01');
            $txnCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['txn_count'] : 0;
            $uncatCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['uncategorised_count'] : 0;
            $deferredCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['deferred_count'] : 0;
            $readyToPostCount = isset($summaries[$monthKey]) ? (int)$summaries[$monthKey]['ready_to_post_count'] : 0;

            if ($txnCount === 0) {
                $status = 'red';
            } elseif ($uncatCount > 0 || $deferredCount > 0) {
                $status = 'amber';
            } else {
                $status = 'green';
            }

            $months[] = [
                'month' => $cursor->format('M'),
                'year' => $cursor->format('Y'),
                'month_key' => $monthKey,
                'label' => $cursor->format('M Y'),
                'status' => $status,
                'status_colour' => $status,
                'transactions' => $txnCount,
                'uncategorised' => $uncatCount,
                'deferred' => $deferredCount,
                'ready_to_post' => $readyToPostCount,
            ];

            $cursor->modify('+1 month');
        }

        return $months;
    }

    public function normaliseTransactionMonthFilter(?string $monthKey): string
    {
        $monthKey = trim((string)$monthKey);

        return preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1 ? $monthKey : '';
    }

    public function normaliseTransactionCategoryFilter(?string $filter): string
    {
        $filter = trim((string)$filter);

        return in_array($filter, ['all', 'uncategorised', 'auto', 'manual'], true) ? $filter : 'all';
    }

    public function defaultTransactionMonth(array $monthStatus): string
    {
        $currentMonthKey = (new DateTimeImmutable('first day of this month'))->format('Y-m-01');

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

    public function fetchTransactionsForMonth(
        int $companyId,
        int $taxYearId,
        string $monthKey,
        string $categoryFilter = 'all',
        int $limit = 500
    ): array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $monthKey = $this->normaliseTransactionMonthFilter($monthKey);
        $categoryFilter = $this->normaliseTransactionCategoryFilter($categoryFilter);
        $limit = max(1, min($limit, 1000));

        $where = [
            't.company_id = :company_id',
            't.tax_year_id = :tax_year_id',
        ];
        $params = [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ];

        if ($monthKey !== '') {
            $monthStart = new DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        if ($categoryFilter !== 'all') {
            $where[] = 't.category_status = :category_status';
            $params['category_status'] = $categoryFilter;
        }

        $sql = "SELECT t.id,
                       t.account_id,
                       t.txn_date,
                       COALESCE(t.txn_type, '') AS txn_type,
                       t.description,
                       t.amount,
                       COALESCE(t.currency, '') AS currency,
                       COALESCE(t.source_account_label, '') AS source_account,
                       COALESCE(t.source_category, '') AS source_category,
                       COALESCE(t.source_document_url, '') AS source_document_url,
                       COALESCE(t.local_document_path, '') AS local_document_path,
                       COALESCE(t.document_download_status, 'skipped') AS document_download_status,
                       COALESCE(t.document_error, '') AS document_error,
                       t.nominal_account_id,
                       t.transfer_account_id,
                       COALESCE(t.is_internal_transfer, 0) AS is_internal_transfer,
                       COALESCE(ca.internal_transfer_marker, '') AS internal_transfer_marker,
                       COALESCE(ca.account_name, '') AS owned_account_name,
                       COALESCE(ta.account_name, '') AS transfer_account_name,
                       COALESCE(na.name, '') AS assigned_nominal,
                       t.category_status,
                       COALESCE(t.auto_rule_id, 0) AS auto_rule_id,
                       COALESCE(cr.match_value, '') AS auto_rule_match_value,
                       COALESCE(t.is_auto_excluded, 0) AS is_auto_excluded,
                       EXISTS(
                           SELECT 1
                           FROM journals j
                           WHERE j.source_type = 'bank_csv'
                             AND j.source_ref = CONCAT('transaction:', t.id)
                       ) AS has_derived_journal
                FROM transactions t
                LEFT JOIN company_accounts ca ON ca.id = t.account_id
                LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
                LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
                LEFT JOIN categorisation_rules cr ON cr.id = t.auto_rule_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY t.txn_date DESC, t.id DESC
                LIMIT {$limit}";

        $stmt = InterfaceDB::prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchActionQueue(int $companyId, int $taxYearId): array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $items = [];

        $stmt = InterfaceDB::prepare("SELECT COUNT(*)
                               FROM transactions
                               WHERE company_id = ?
                                 AND tax_year_id = ?
                                 AND category_status = 'uncategorised'");
        $stmt->execute([$companyId, $taxYearId]);
        $uncategorisedCount = (int)$stmt->fetchColumn();
        if ($uncategorisedCount > 0) {
            $items[] = [
                'title' => 'Categorise uncategorised transactions',
                'detail' => $uncategorisedCount . ' transaction' . ($uncategorisedCount === 1 ? '' : 's') . ' still need a nominal account.',
            ];
        }

        $stmt = InterfaceDB::prepare("SELECT COUNT(*)
                               FROM statement_uploads
                               WHERE company_id = ?
                                 AND tax_year_id = ?
                                 AND rows_duplicate > 0");
        $stmt->execute([$companyId, $taxYearId]);
        $duplicateUploads = (int)$stmt->fetchColumn();
        if ($duplicateUploads > 0) {
            $items[] = [
                'title' => 'Review duplicate upload hits',
                'detail' => $duplicateUploads . ' upload' . ($duplicateUploads === 1 ? '' : 's') . ' reported duplicate rows.',
            ];
        }

        $stmt = InterfaceDB::prepare("SELECT COUNT(*)
                               FROM statement_uploads
                               WHERE company_id = ?
                                 AND tax_year_id = ?
                                 AND rows_inserted = 0");
        $stmt->execute([$companyId, $taxYearId]);
        $emptyUploads = (int)$stmt->fetchColumn();
        if ($emptyUploads > 0) {
            $items[] = [
                'title' => 'Check empty imports',
                'detail' => $emptyUploads . ' upload' . ($emptyUploads === 1 ? '' : 's') . ' inserted no rows and may need inspection.',
            ];
        }

        $stmt = InterfaceDB::prepare("SELECT COUNT(*)
                               FROM transactions
                               WHERE company_id = ?
                                 AND tax_year_id = ?
                                 AND statement_upload_id IS NULL");
        $stmt->execute([$companyId, $taxYearId]);
        $manualTransactions = (int)$stmt->fetchColumn();
        if ($manualTransactions > 0) {
            $items[] = [
                'title' => 'Review manually added transactions',
                'detail' => $manualTransactions . ' transaction' . ($manualTransactions === 1 ? '' : 's') . ' are not tied to an uploaded statement.',
            ];
        }

        if ($items === []) {
            $items[] = [
                'title' => 'No immediate actions',
                'detail' => 'This period looks tidy. The accounting gremlins appear to be off shift.',
            ];
        }

        return array_slice($items, 0, 6);
    }
}
