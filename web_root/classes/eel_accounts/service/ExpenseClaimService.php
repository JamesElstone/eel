<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class ExpenseClaimService
{
    private \eel_accounts\Service\TransactionCategorisationService $categorisationService;
    private \eel_accounts\Service\TransactionJournalService $journalService;

    public function __construct(
        ?\eel_accounts\Service\TransactionCategorisationService $categorisationService = null,
        ?\eel_accounts\Service\TransactionJournalService $journalService = null
    ) {
        $this->categorisationService = $categorisationService ?? new \eel_accounts\Service\TransactionCategorisationService();
        $this->journalService = $journalService ?? new \eel_accounts\Service\TransactionJournalService();
    }

    public function fetchPageData(int $companyId, array $filters = [], int $accountingPeriodId = 0): array {
        $effectiveFilters = $filters;
        $selectedAccountingPeriodId = max(0, $accountingPeriodId);
        if ($selectedAccountingPeriodId > 0) {
            $effectiveFilters['accounting_period_id'] = $selectedAccountingPeriodId;
        } else {
            $selectedAccountingPeriodId = max(0, (int)($effectiveFilters['accounting_period_id'] ?? 0));
        }

        $selectedClaim = null;
        $heatmapClaimantId = max(0, (int)($effectiveFilters['heatmap_claimant_id'] ?? 0));
        $heatmapDate = trim((string)($effectiveFilters['heatmap_date'] ?? ''));

        if (isset($effectiveFilters['claim_reference_code']) && trim((string)$effectiveFilters['claim_reference_code']) !== '') {
            $selectedClaim = $this->fetchClaimByReferenceCode($companyId, (string)$effectiveFilters['claim_reference_code']);
        } elseif (isset($effectiveFilters['claim_id']) && (int)$effectiveFilters['claim_id'] > 0) {
            $selectedClaim = $this->fetchClaim($companyId, (int)$effectiveFilters['claim_id']);
        } elseif ($heatmapClaimantId > 0 && $this->isValidDate($heatmapDate)) {
            $selectedClaim = $this->fetchClaimForHeatmapLine($companyId, $heatmapClaimantId, $heatmapDate, $selectedAccountingPeriodId);
        }

        $paymentQuery = trim((string)($effectiveFilters['payment_query'] ?? ''));

        return [
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'accounting_periods' => (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriods($companyId),
            'nominal_accounts' => $this->fetchExpenseNominals(),
            'asset_categories' => \eel_accounts\Service\AssetService::assetCategoryOptions(),
            'payment_candidates' => $selectedClaim !== null
                ? $this->searchTransactions($companyId, [
                    'claim_id' => (int)$selectedClaim['id'],
                    'query' => $paymentQuery,
                    'current_month_only' => true,
                ])
                : [],
            'claims' => $this->listClaims($companyId, $effectiveFilters),
            'claim_heatmap_lines' => $this->listClaimLinesForHeatmap($companyId, $effectiveFilters),
            'selected_claim' => $selectedClaim,
            'filters' => [
                'query' => trim((string)($effectiveFilters['query'] ?? '')),
                'status' => $this->normaliseStatusFilter((string)($effectiveFilters['status'] ?? 'all')),
                'payment_query' => $paymentQuery,
                'heatmap_claimant_id' => $heatmapClaimantId,
                'heatmap_period_start' => trim((string)($effectiveFilters['heatmap_period_start'] ?? '')),
                'heatmap_date' => $heatmapDate,
                'accounting_period_id' => max(0, (int)($effectiveFilters['accounting_period_id'] ?? 0)),
                'accounting_period_start' => trim((string)($effectiveFilters['accounting_period_start'] ?? '')),
                'accounting_period_end' => trim((string)($effectiveFilters['accounting_period_end'] ?? '')),
            ],
        ];
    }

    public function fetchClaimants(int $companyId, bool $activeOnly = true): array {
        if ($companyId <= 0) {
            return [];
        }

        $sql = 'SELECT id,
                       company_id,
                       claimant_name,
                       is_active,
                       created_at,
                       updated_at,
                       (SELECT COUNT(*)
                          FROM expense_claims
                         WHERE expense_claims.company_id = expense_claimants.company_id
                           AND expense_claims.claimant_id = expense_claimants.id) AS claim_count
                FROM expense_claimants
                WHERE company_id = :company_id';

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY claimant_name ASC, id ASC';

        return array_map([$this, 'formatClaimant'], \InterfaceDB::fetchAll( $sql, ['company_id' => $companyId]));
    }

    public function createClaimant(int $companyId, string $claimantName): array {
        $claimantName = trim($claimantName);

        if ($companyId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company before adding a claimant.'],
            ];
        }

        if ($claimantName === '') {
            return [
                'success' => false,
                'errors' => ['Enter a claimant name.'],
            ];
        }

        $existing = $this->findClaimantByName($companyId, $claimantName);
        if ($existing !== null) {
            if ((int)($existing['is_active'] ?? 0) !== 1) {
                \InterfaceDB::prepare(
                    'UPDATE expense_claimants
                     SET is_active = 1,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                )->execute(['id' => (int)$existing['id']]);
                $existing = $this->fetchClaimantById($companyId, (int)$existing['id']);
            }

            return [
                'success' => true,
                'claimant' => $existing,
                'claimants' => $this->fetchClaimants($companyId, false),
                'messages' => ['That claimant already existed, so it has been selected.'],
            ];
        }

        \InterfaceDB::prepare(
            'INSERT INTO expense_claimants (
                company_id,
                claimant_name,
                is_active,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :claimant_name,
                1,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        )->execute([
            'company_id' => $companyId,
            'claimant_name' => $claimantName,
        ]);

        $claimant = $this->findClaimantByName($companyId, $claimantName);

        return [
            'success' => $claimant !== null,
            'claimant' => $claimant,
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'errors' => $claimant === null ? ['The claimant could not be saved.'] : [],
        ];
    }

    public function setClaimantActive(int $companyId, int $claimantId, bool $isActive): array {
        if ($companyId <= 0 || $claimantId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a valid claimant first.'],
            ];
        }

        $claimant = $this->fetchClaimantById($companyId, $claimantId);
        if ($claimant === null) {
            return [
                'success' => false,
                'errors' => ['The selected claimant could not be found.'],
            ];
        }

        \InterfaceDB::prepare(
            'UPDATE expense_claimants
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE company_id = :company_id
               AND id = :id'
        )->execute([
            'is_active' => $isActive ? 1 : 0,
            'company_id' => $companyId,
            'id' => $claimantId,
        ]);

        return [
            'success' => true,
            'claimant' => $this->fetchClaimantById($companyId, $claimantId),
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'messages' => [$isActive ? 'Claimant activated.' : 'Claimant deactivated.'],
        ];
    }

    public function deleteClaimant(int $companyId, int $claimantId): array {
        if ($companyId <= 0 || $claimantId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a valid claimant first.'],
            ];
        }

        $claimant = $this->fetchClaimantById($companyId, $claimantId);
        if ($claimant === null) {
            return [
                'success' => false,
                'errors' => ['The selected claimant could not be found.'],
            ];
        }

        if ((int)($claimant['claim_count'] ?? 0) > 0) {
            return [
                'success' => false,
                'errors' => ['Claimants with existing claims cannot be deleted.'],
            ];
        }

        $statement = \InterfaceDB::prepareExecute(
            'DELETE FROM expense_claimants
             WHERE company_id = :company_id
               AND id = :id
               AND NOT EXISTS (
                   SELECT 1
                   FROM expense_claims
                   WHERE expense_claims.company_id = expense_claimants.company_id
                     AND expense_claims.claimant_id = expense_claimants.id
                   LIMIT 1
               )',
            [
                'company_id' => $companyId,
                'id' => $claimantId,
            ]
        );

        if ($statement->rowCount() < 1) {
            return [
                'success' => false,
                'errors' => ['Claimants with existing claims cannot be deleted.'],
            ];
        }

        return [
            'success' => true,
            'claimants' => $this->fetchClaimants($companyId, false),
            'active_claimant_count' => count($this->fetchClaimants($companyId, true)),
            'deleted_claimant_id' => $claimantId,
            'messages' => ['Claimant deleted.'],
        ];
    }

    public function listClaims(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        $conditions = ['ec.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $query = trim((string)($filters['query'] ?? ''));
        $status = $this->normaliseStatusFilter((string)($filters['status'] ?? 'all'));
        $claimantId = max(0, (int)($filters['heatmap_claimant_id'] ?? 0));
        $accountingPeriodId = max(0, (int)($filters['accounting_period_id'] ?? 0));

        if ($claimantId <= 0) {
            return [];
        }

        $conditions[] = 'ec.claimant_id = :claimant_id';
        $params['claimant_id'] = $claimantId;

        if ($accountingPeriodId > 0) {
            $conditions[] = 'ec.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        } else {
            $periodStart = trim((string)($filters['accounting_period_start'] ?? ''));
            $periodEnd = trim((string)($filters['accounting_period_end'] ?? ''));
            if ($this->isValidDate($periodStart) && $this->isValidDate($periodEnd)) {
                $conditions[] = 'ec.period_start >= :accounting_period_start';
                $conditions[] = 'ec.period_end <= :accounting_period_end';
                $params['accounting_period_start'] = $periodStart;
                $params['accounting_period_end'] = $periodEnd;
            }
        }

        if ($query !== '') {
            $conditions[] = '(ec.claim_reference_code LIKE :query_reference OR ec.notes LIKE :query_notes OR c.claimant_name LIKE :query_claimant)';
            $params['query_reference'] = '%' . $query . '%';
            $params['query_notes'] = '%' . $query . '%';
            $params['query_claimant'] = '%' . $query . '%';
        }

        if ($status !== 'all') {
            $conditions[] = 'ec.status = :status';
            $params['status'] = $status;
        }

        return array_map([$this, 'formatClaimSummary'], \InterfaceDB::fetchAll( 'SELECT ec.id,
                    ec.company_id,
                    ec.accounting_period_id,
                    ec.claimant_id,
                    ec.claim_year,
                    ec.claim_month,
                    ec.period_start,
                    ec.period_end,
                    ec.claim_reference_code,
                    ec.brought_forward_amount,
                    ec.claimed_amount,
                    ec.payments_amount,
                    ec.carried_forward_amount,
                    ec.status,
                    ec.posted_journal_id,
                    ec.notes,
                    ec.created_at,
                    ec.updated_at,
                    c.claimant_name,
                    (SELECT COUNT(*)
                       FROM expense_claim_lines ecl
                      WHERE ecl.expense_claim_id = ec.id) AS line_count,
                    (SELECT COUNT(*)
                       FROM expense_claim_payment_links epl
                      WHERE epl.expense_claim_id = ec.id) AS payment_link_count
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY ec.claim_year DESC, ec.claim_month DESC, ec.updated_at DESC, ec.id DESC', $params));
    }

    public function searchExpenseLines(int $companyId, int $accountingPeriodId, array $filters = []): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $keyword = trim((string)($filters['keyword'] ?? ''));
        $amount = $this->normaliseSearchAmount((string)($filters['amount'] ?? ''));
        $claimantId = max(0, (int)($filters['claimant_id'] ?? 0));
        $claimYear = max(0, (int)($filters['claim_year'] ?? 0));
        $claimMonth = max(0, (int)($filters['claim_month'] ?? 0));
        $statuses = $this->normaliseSearchStatuses($filters['statuses'] ?? []);
        $nominalIds = $this->normaliseSearchIds($filters['nominal_account_ids'] ?? []);

        if (
            $keyword === ''
            && $amount === ''
            && $claimantId <= 0
            && ($claimYear <= 0 || $claimMonth <= 0)
            && $statuses === []
            && $nominalIds === []
        ) {
            return [];
        }

        $conditions = [
            'ec.company_id = :company_id',
            'ec.accounting_period_id = :accounting_period_id',
        ];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];

        if ($keyword !== '') {
            $conditions[] = '(ecl.description LIKE :keyword_description OR ecl.notes LIKE :keyword_notes)';
            $params['keyword_description'] = '%' . $keyword . '%';
            $params['keyword_notes'] = '%' . $keyword . '%';
        }

        if ($amount !== '') {
            $amountValue = (float)$amount;
            $conditions[] = 'ecl.amount >= :amount_min AND ecl.amount < :amount_max';
            $params['amount_min'] = $amountValue - 0.005;
            $params['amount_max'] = $amountValue + 0.005;
        }

        if ($claimantId > 0) {
            $conditions[] = 'ec.claimant_id = :claimant_id';
            $params['claimant_id'] = $claimantId;
        }

        if ($claimYear > 0 && $claimMonth >= 1 && $claimMonth <= 12) {
            $conditions[] = 'ec.claim_year = :claim_year';
            $conditions[] = 'ec.claim_month = :claim_month';
            $params['claim_year'] = $claimYear;
            $params['claim_month'] = $claimMonth;
        }

        if ($statuses !== []) {
            $statusPlaceholders = [];
            foreach ($statuses as $index => $status) {
                $placeholder = 'status_' . $index;
                $statusPlaceholders[] = ':' . $placeholder;
                $params[$placeholder] = $status;
            }
            $conditions[] = 'ec.status IN (' . implode(', ', $statusPlaceholders) . ')';
        }

        if ($nominalIds !== []) {
            $nominalPlaceholders = [];
            foreach ($nominalIds as $index => $nominalId) {
                $placeholder = 'nominal_id_' . $index;
                $nominalPlaceholders[] = ':' . $placeholder;
                $params[$placeholder] = $nominalId;
            }
            $conditions[] = 'ecl.nominal_account_id IN (' . implode(', ', $nominalPlaceholders) . ')';
        }

        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'expense_claim_id' => (int)$row['expense_claim_id'],
                    'claim_reference_code' => (string)$row['claim_reference_code'],
                    'claimant_id' => (int)$row['claimant_id'],
                    'claimant_name' => (string)$row['claimant_name'],
                    'claim_year' => (int)$row['claim_year'],
                    'claim_month' => (int)$row['claim_month'],
                    'claim_period' => sprintf('%04d-%02d', (int)$row['claim_year'], (int)$row['claim_month']),
                    'expense_date' => (string)$row['expense_date'],
                    'line_number' => (int)$row['line_number'],
                    'description' => (string)$row['description'],
                    'notes' => (string)($row['notes'] ?? ''),
                    'amount' => round((float)$row['amount'], 2),
                    'nominal_account_id' => isset($row['nominal_account_id']) ? (int)$row['nominal_account_id'] : null,
                    'nominal_code' => (string)($row['nominal_code'] ?? ''),
                    'nominal_name' => (string)($row['nominal_name'] ?? ''),
                    'status' => (string)$row['status'],
                    'updated_at' => (string)$row['updated_at'],
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT ecl.id,
                        ecl.expense_claim_id,
                        ecl.line_number,
                        ecl.expense_date,
                        ecl.description,
                        ecl.notes,
                        ecl.amount,
                        ecl.nominal_account_id,
                        ecl.updated_at,
                        ec.claim_reference_code,
                        ec.claimant_id,
                        ec.claim_year,
                        ec.claim_month,
                        ec.status,
                        c.claimant_name,
                        n.code AS nominal_code,
                        n.name AS nominal_name
                   FROM expense_claim_lines ecl
                   INNER JOIN expense_claims ec ON ec.id = ecl.expense_claim_id
                   INNER JOIN expense_claimants c ON c.id = ec.claimant_id
                   LEFT JOIN nominal_accounts n ON n.id = ecl.nominal_account_id
                  WHERE ' . implode(' AND ', $conditions) . '
                  ORDER BY ec.claim_year DESC, ec.claim_month DESC, ecl.expense_date DESC, ecl.line_number ASC, ecl.id DESC',
                $params
            )
        );
    }

    public function fetchStatistics(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return $this->emptyStatistics($filters);
        }

        $scope = $this->expenseStatisticsScope($companyId, $filters);

        return [
            'claimants' => $this->fetchStatisticsClaimants($scope),
            'unassigned_entries' => $this->fetchStatisticsUnassignedEntries($scope),
            'unconfirmed_no_line_claims' => $this->fetchStatisticsUnconfirmedNoLineClaims($scope),
            'nominals' => $this->fetchStatisticsNominals($scope),
            'claimant_breakdown' => $this->fetchStatisticsClaimantBreakdown($scope),
            'monthly_trend' => $this->fetchStatisticsMonthlyTrend($scope),
            'health_checks' => $this->fetchStatisticsHealthChecks($scope),
            'filters' => [
                'accounting_period_id' => max(0, (int)($filters['accounting_period_id'] ?? 0)),
                'accounting_period_start' => trim((string)($filters['accounting_period_start'] ?? '')),
                'accounting_period_end' => trim((string)($filters['accounting_period_end'] ?? '')),
            ],
        ];
    }

    public function fetchStatisticsClaimantBalances(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        return $this->fetchStatisticsClaimants($this->expenseStatisticsScope($companyId, $filters));
    }

    private function fetchStatisticsClaimants(array $scope): array {
        $firstClaimWhere = $this->expenseStatisticsScopedClaimWhere($scope, 'first_ec')
            . ' AND first_ec.claimant_id = ec.claimant_id';

        return array_map(
            static fn(array $row): array => [
                'claimant_id' => (int)$row['claimant_id'],
                'claimant_name' => (string)$row['claimant_name'],
                'claim_count' => (int)$row['claim_count'],
                'item_count' => (int)$row['item_count'],
                'unassigned_item_count' => (int)$row['unassigned_item_count'],
                'brought_forward' => round((float)$row['brought_forward'], 2),
                'claimed_total' => round((float)$row['claimed_total'], 2),
                'payments_made' => round((float)$row['payments_made'], 2),
                'carried_forward' => round((float)$row['carried_forward'], 2),
            ],
            \InterfaceDB::fetchAll(
                'SELECT ec.claimant_id,
                        c.claimant_name,
                        COUNT(ec.id) AS claim_count,
                        COALESCE(SUM(lc.line_count), 0) AS item_count,
                        COALESCE(SUM(lc.unassigned_line_count), 0) AS unassigned_item_count,
                        COALESCE((
                            SELECT first_ec.brought_forward_amount
                            FROM expense_claims first_ec
                            WHERE ' . $firstClaimWhere . '
                            ORDER BY first_ec.claim_year ASC, first_ec.claim_month ASC, first_ec.id ASC
                            LIMIT 1
                        ), 0) AS brought_forward,
                        COALESCE(SUM(lc.line_total), 0) AS claimed_total,
                        COALESCE(SUM(pc.payment_total), 0) AS payments_made,
                        COALESCE((
                            SELECT first_ec.brought_forward_amount
                            FROM expense_claims first_ec
                            WHERE ' . $firstClaimWhere . '
                            ORDER BY first_ec.claim_year ASC, first_ec.claim_month ASC, first_ec.id ASC
                            LIMIT 1
                        ), 0) + COALESCE(SUM(COALESCE(lc.line_total, 0) - COALESCE(pc.payment_total, 0)), 0) AS carried_forward
                 FROM expense_claims ec
                 INNER JOIN expense_claimants c ON c.id = ec.claimant_id
                 LEFT JOIN (
                    SELECT expense_claim_id,
                           COUNT(*) AS line_count,
                           COALESCE(SUM(CASE WHEN nominal_account_id IS NULL THEN 1 ELSE 0 END), 0) AS unassigned_line_count,
                           COALESCE(SUM(amount), 0) AS line_total
                    FROM expense_claim_lines
                    GROUP BY expense_claim_id
                 ) lc ON lc.expense_claim_id = ec.id
                 LEFT JOIN (
                    SELECT expense_claim_id, COALESCE(SUM(linked_amount), 0) AS payment_total
                    FROM expense_claim_payment_links
                    GROUP BY expense_claim_id
                 ) pc ON pc.expense_claim_id = ec.id
                 WHERE ' . $scope['where'] . '
                 GROUP BY ec.claimant_id, c.claimant_name
                 ORDER BY c.claimant_name ASC, ec.claimant_id ASC',
                $scope['params']
            )
        );
    }

    private function fetchStatisticsUnassignedEntries(array $scope): array {
        return array_map(
            function (array $row): array {
                $monthDate = \DateTimeImmutable::createFromFormat(
                    '!Y-n-j',
                    (string)(int)$row['claim_year'] . '-' . (string)(int)$row['claim_month'] . '-1'
                );

                return [
                    'claim_id' => (int)$row['claim_id'],
                    'claim_reference_code' => (string)$row['claim_reference_code'],
                    'claimant_name' => (string)$row['claimant_name'],
                    'month' => $monthDate !== false ? $monthDate->format('M Y') : '',
                    'expense_date' => (string)$row['expense_date'],
                    'amount' => round((float)$row['amount'], 2),
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT ec.id AS claim_id,
                        ec.claim_reference_code,
                        c.claimant_name,
                        ec.claim_year,
                        ec.claim_month,
                        l.expense_date,
                        l.amount
                 FROM expense_claim_lines l
                 INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
                 INNER JOIN expense_claimants c ON c.id = ec.claimant_id
                 WHERE ' . $scope['where'] . '
                   AND l.nominal_account_id IS NULL
                 ORDER BY ec.claim_year ASC, ec.claim_month ASC, ec.id ASC, l.expense_date ASC, l.line_number ASC, l.id ASC',
                $scope['params']
            )
        );
    }

    private function fetchStatisticsUnconfirmedNoLineClaims(array $scope): array {
        return array_map(
            function (array $row): array {
                $monthDate = \DateTimeImmutable::createFromFormat(
                    '!Y-n-j',
                    (string)(int)$row['claim_year'] . '-' . (string)(int)$row['claim_month'] . '-1'
                );

                return [
                    'claim_id' => (int)$row['claim_id'],
                    'claim_reference_code' => (string)$row['claim_reference_code'],
                    'claimant_name' => (string)$row['claimant_name'],
                    'month' => $monthDate !== false ? $monthDate->format('M Y') : '',
                    'status' => (string)$row['status'],
                ];
            },
            \InterfaceDB::fetchAll(
                'SELECT ec.id AS claim_id,
                        ec.claim_reference_code,
                        c.claimant_name,
                        ec.claim_year,
                        ec.claim_month,
                        ec.status
                 FROM expense_claims ec
                 INNER JOIN expense_claimants c ON c.id = ec.claimant_id
                 LEFT JOIN expense_claim_lines l ON l.expense_claim_id = ec.id
                 WHERE ' . $scope['where'] . '
                   AND ec.no_lines_confirmed_at IS NULL
                 GROUP BY ec.id, ec.claim_reference_code, c.claimant_name, ec.claim_year, ec.claim_month, ec.status
                 HAVING COUNT(l.id) = 0
                 ORDER BY ec.claim_year ASC, ec.claim_month ASC, ec.id ASC',
                $scope['params']
            )
        );
    }

    private function fetchStatisticsNominals(array $scope): array {
        return array_map(
            static fn(array $row): array => [
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'code' => (string)($row['code'] ?? ''),
                'name' => (string)($row['name'] ?? 'Unassigned'),
                'line_count' => (int)$row['line_count'],
                'claimed_total' => round((float)$row['claimed_total'], 2),
            ],
            \InterfaceDB::fetchAll(
                'SELECT COALESCE(l.nominal_account_id, 0) AS nominal_account_id,
                        COALESCE(na.code, \'\') AS code,
                        COALESCE(na.name, \'Unassigned\') AS name,
                        COUNT(l.id) AS line_count,
                        COALESCE(SUM(l.amount), 0) AS claimed_total
                 FROM expense_claim_lines l
                 INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
                 LEFT JOIN nominal_accounts na ON na.id = l.nominal_account_id
                 WHERE ' . $scope['where'] . '
                 GROUP BY COALESCE(l.nominal_account_id, 0), COALESCE(na.code, \'\'), COALESCE(na.name, \'Unassigned\')
                 ORDER BY claimed_total DESC, name ASC',
                $scope['params']
            )
        );
    }

    private function fetchStatisticsClaimantBreakdown(array $scope): array {
        return array_map(
            static fn(array $row): array => [
                'claimant_id' => (int)$row['claimant_id'],
                'claimant_name' => (string)$row['claimant_name'],
                'claimed_total' => round((float)$row['claimed_total'], 2),
            ],
            \InterfaceDB::fetchAll(
                'SELECT ec.claimant_id,
                        c.claimant_name,
                        COALESCE(SUM(ec.claimed_amount), 0) AS claimed_total
                 FROM expense_claims ec
                 INNER JOIN expense_claimants c ON c.id = ec.claimant_id
                 WHERE ' . $scope['where'] . '
                 GROUP BY ec.claimant_id, c.claimant_name
                 ORDER BY claimed_total DESC, c.claimant_name ASC',
                $scope['params']
            )
        );
    }

    private function fetchStatisticsMonthlyTrend(array $scope): array {
        $rows = \InterfaceDB::fetchAll(
            'SELECT ec.claim_year,
                    ec.claim_month,
                    COALESCE(SUM(ec.claimed_amount), 0) AS claimed_total
             FROM expense_claims ec
             WHERE ' . $scope['where'] . '
             GROUP BY ec.claim_year, ec.claim_month
             ORDER BY ec.claim_year ASC, ec.claim_month ASC',
            $scope['params']
        );
        $totals = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', (int)$row['claim_year'], (int)$row['claim_month']);
            $totals[$key] = round((float)$row['claimed_total'], 2);
        }

        $periodStart = (string)$scope['period_start'];
        $periodEnd = (string)$scope['period_end'];
        if (!$this->isValidDate($periodStart) || !$this->isValidDate($periodEnd)) {
            return array_map(
                static fn(string $key, float $value): array => [
                    'period' => $key,
                    'label' => \DateTimeImmutable::createFromFormat('!Y-m', $key)?->format('M y') ?? $key,
                    'claimed_total' => $value,
                ],
                array_keys($totals),
                array_values($totals)
            );
        }

        $points = [];
        $cursor = (new \DateTimeImmutable($periodStart))->modify('first day of this month');
        $end = (new \DateTimeImmutable($periodEnd))->modify('first day of this month');

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $points[] = [
                'period' => $key,
                'label' => $cursor->format('M y'),
                'claimed_total' => $totals[$key] ?? 0.0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $points;
    }

    private function fetchStatisticsHealthChecks(array $scope): array {
        $statusExpression = "CASE
                    WHEN ec.status = 'draft'
                     AND COALESCE(lc.line_count, 0) = 0
                     AND (COALESCE(pc.payment_link_count, 0) > 0 OR ABS(COALESCE(ec.payments_amount, 0)) > 0)
                    THEN 'repayment_only'
                    ELSE ec.status
                END";
        $statusRows = \InterfaceDB::fetchAll(
            'SELECT ' . $statusExpression . ' AS status,
                    COUNT(ec.id) AS claim_count,
                    COALESCE(SUM(ec.claimed_amount), 0) AS claimed_total
             FROM expense_claims ec
             LEFT JOIN (
                SELECT expense_claim_id, COUNT(*) AS line_count
                FROM expense_claim_lines
                GROUP BY expense_claim_id
             ) lc ON lc.expense_claim_id = ec.id
             LEFT JOIN (
                SELECT expense_claim_id, COUNT(*) AS payment_link_count
                FROM expense_claim_payment_links
                GROUP BY expense_claim_id
             ) pc ON pc.expense_claim_id = ec.id
             WHERE ' . $scope['where'] . '
             GROUP BY ' . $statusExpression,
            $scope['params']
        );
        $statusTotals = [
            'draft' => ['claim_count' => 0, 'claimed_total' => 0.0],
            'posted' => ['claim_count' => 0, 'claimed_total' => 0.0],
        ];

        foreach ($statusRows as $row) {
            $status = (string)$row['status'];
            if (!isset($statusTotals[$status])) {
                continue;
            }
            $statusTotals[$status] = [
                'claim_count' => (int)$row['claim_count'],
                'claimed_total' => round((float)$row['claimed_total'], 2),
            ];
        }

        $lineChecks = \InterfaceDB::fetchOne(
            'SELECT COALESCE(SUM(CASE WHEN l.receipt_reference IS NULL OR TRIM(l.receipt_reference) = \'\' THEN 1 ELSE 0 END), 0) AS missing_receipt_count,
                    COALESCE(SUM(CASE WHEN l.receipt_reference IS NULL OR TRIM(l.receipt_reference) = \'\' THEN l.amount ELSE 0 END), 0) AS missing_receipt_value,
                    COALESCE(SUM(CASE WHEN l.nominal_account_id IS NULL THEN 1 ELSE 0 END), 0) AS missing_nominal_count,
                    COALESCE(SUM(CASE WHEN l.nominal_account_id IS NULL THEN l.amount ELSE 0 END), 0) AS missing_nominal_value
             FROM expense_claim_lines l
             INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
             WHERE ' . $scope['where'],
            $scope['params']
        ) ?: [];

        $oldestOutstanding = \InterfaceDB::fetchOne(
            'SELECT ec.claim_reference_code,
                    ec.period_start,
                    (COALESCE(lc.line_total, 0) - COALESCE(pc.payment_total, 0)) AS period_balance,
                    c.claimant_name
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             LEFT JOIN (
                SELECT expense_claim_id, COALESCE(SUM(amount), 0) AS line_total
                FROM expense_claim_lines
                GROUP BY expense_claim_id
             ) lc ON lc.expense_claim_id = ec.id
             LEFT JOIN (
                SELECT expense_claim_id, COALESCE(SUM(linked_amount), 0) AS payment_total
                FROM expense_claim_payment_links
                GROUP BY expense_claim_id
             ) pc ON pc.expense_claim_id = ec.id
             WHERE ' . $scope['where'] . '
               AND (COALESCE(lc.line_total, 0) - COALESCE(pc.payment_total, 0)) > 0
             ORDER BY ec.period_start ASC, ec.id ASC
             LIMIT 1',
            $scope['params']
        ) ?: [];

        $largestOutstandingClaimant = \InterfaceDB::fetchOne(
            'SELECT ec.claimant_id,
                    c.claimant_name,
                    COALESCE(SUM(COALESCE(lc.line_total, 0) - COALESCE(pc.payment_total, 0)), 0) AS carried_forward
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             LEFT JOIN (
                SELECT expense_claim_id, COALESCE(SUM(amount), 0) AS line_total
                FROM expense_claim_lines
                GROUP BY expense_claim_id
             ) lc ON lc.expense_claim_id = ec.id
             LEFT JOIN (
                SELECT expense_claim_id, COALESCE(SUM(linked_amount), 0) AS payment_total
                FROM expense_claim_payment_links
                GROUP BY expense_claim_id
             ) pc ON pc.expense_claim_id = ec.id
             WHERE ' . $scope['where'] . '
             GROUP BY ec.claimant_id, c.claimant_name
             HAVING carried_forward > 0
             ORDER BY carried_forward DESC, c.claimant_name ASC
             LIMIT 1',
            $scope['params']
        ) ?: [];

        return [
            'draft' => $statusTotals['draft'],
            'posted' => $statusTotals['posted'],
            'missing_receipts' => [
                'count' => (int)($lineChecks['missing_receipt_count'] ?? 0),
                'value' => round((float)($lineChecks['missing_receipt_value'] ?? 0), 2),
            ],
            'missing_nominals' => [
                'count' => (int)($lineChecks['missing_nominal_count'] ?? 0),
                'value' => round((float)($lineChecks['missing_nominal_value'] ?? 0), 2),
            ],
            'oldest_outstanding_claim' => $oldestOutstanding === [] ? null : [
                'claim_reference_code' => (string)$oldestOutstanding['claim_reference_code'],
                'claimant_name' => (string)$oldestOutstanding['claimant_name'],
                'period_start' => (string)$oldestOutstanding['period_start'],
                'carried_forward' => round((float)$oldestOutstanding['period_balance'], 2),
            ],
            'largest_outstanding_claimant' => $largestOutstandingClaimant === [] ? null : [
                'claimant_id' => (int)$largestOutstandingClaimant['claimant_id'],
                'claimant_name' => (string)$largestOutstandingClaimant['claimant_name'],
                'carried_forward' => round((float)$largestOutstandingClaimant['carried_forward'], 2),
            ],
        ];
    }

    private function expenseStatisticsScope(int $companyId, array $filters): array {
        $params = ['company_id' => $companyId];
        $periodStart = trim((string)($filters['accounting_period_start'] ?? ''));
        $periodEnd = trim((string)($filters['accounting_period_end'] ?? ''));
        $accountingPeriodId = max(0, (int)($filters['accounting_period_id'] ?? 0));

        if ($accountingPeriodId > 0) {
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        if ($accountingPeriodId <= 0 && $this->isValidDate($periodStart) && $this->isValidDate($periodEnd)) {
            $params['accounting_period_start'] = $periodStart;
            $params['accounting_period_end'] = $periodEnd;
        }

        $scope = [
            'params' => $params,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'accounting_period_id' => $accountingPeriodId,
        ];
        $scope['where'] = $this->expenseStatisticsScopedClaimWhere($scope, 'ec');

        return $scope;
    }

    private function expenseStatisticsScopedClaimWhere(array $scope, string $claimAlias): string {
        $conditions = [$claimAlias . '.company_id = :company_id'];
        $accountingPeriodId = max(0, (int)($scope['accounting_period_id'] ?? 0));
        $periodStart = trim((string)($scope['period_start'] ?? ''));
        $periodEnd = trim((string)($scope['period_end'] ?? ''));

        if ($accountingPeriodId > 0) {
            $conditions[] = $claimAlias . '.accounting_period_id = :accounting_period_id';
        } elseif ($this->isValidDate($periodStart) && $this->isValidDate($periodEnd)) {
            $conditions[] = $claimAlias . '.period_start >= :accounting_period_start';
            $conditions[] = $claimAlias . '.period_end <= :accounting_period_end';
        }

        return implode(' AND ', $conditions);
    }

    private function emptyStatistics(array $filters): array {
        return [
            'claimants' => [],
            'unassigned_entries' => [],
            'unconfirmed_no_line_claims' => [],
            'nominals' => [],
            'claimant_breakdown' => [],
            'monthly_trend' => [],
            'health_checks' => [
                'draft' => ['claim_count' => 0, 'claimed_total' => 0.0],
                'posted' => ['claim_count' => 0, 'claimed_total' => 0.0],
                'missing_receipts' => ['count' => 0, 'value' => 0.0],
                'missing_nominals' => ['count' => 0, 'value' => 0.0],
                'oldest_outstanding_claim' => null,
                'largest_outstanding_claimant' => null,
            ],
            'filters' => [
                'accounting_period_id' => max(0, (int)($filters['accounting_period_id'] ?? 0)),
                'accounting_period_start' => trim((string)($filters['accounting_period_start'] ?? '')),
                'accounting_period_end' => trim((string)($filters['accounting_period_end'] ?? '')),
            ],
        ];
    }

    private function listClaimLinesForHeatmap(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        $conditions = ['ec.company_id = :company_id'];
        $params = ['company_id' => $companyId];
        $accountingPeriodId = max(0, (int)($filters['accounting_period_id'] ?? 0));

        if ($accountingPeriodId > 0) {
            $conditions[] = 'ec.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        } else {
            $periodStart = trim((string)($filters['accounting_period_start'] ?? ''));
            $periodEnd = trim((string)($filters['accounting_period_end'] ?? ''));
            if ($this->isValidDate($periodStart) && $this->isValidDate($periodEnd)) {
                $conditions[] = 'ec.period_start >= :accounting_period_start';
                $conditions[] = 'ec.period_end <= :accounting_period_end';
                $params['accounting_period_start'] = $periodStart;
                $params['accounting_period_end'] = $periodEnd;
            }
        }

        return array_map(
            static fn(array $row): array => [
                'id' => (int)$row['id'],
                'expense_claim_id' => (int)$row['expense_claim_id'],
                'claimant_id' => (int)$row['claimant_id'],
                'expense_date' => (string)$row['expense_date'],
                'claim_reference_code' => (string)$row['claim_reference_code'],
            ],
            \InterfaceDB::fetchAll( 'SELECT l.id,
                    l.expense_claim_id,
                    l.expense_date,
                    ec.claimant_id,
                    ec.claim_reference_code
             FROM expense_claim_lines l
             INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY l.expense_date ASC, l.line_number ASC, l.id ASC', $params)
        );
    }

    public function createClaim(int $companyId, array $payload): array {
        $claimantId = isset($payload['claimant_id']) ? (int)$payload['claimant_id'] : 0;
        $period = $this->normaliseClaimPeriodFromPayload($payload);

        if ($companyId <= 0) {
            return ['success' => false, 'errors' => ['Select a company before creating a claim.']];
        }

        if ($claimantId <= 0) {
            return ['success' => false, 'errors' => ['Choose a claimant before creating a claim.']];
        }

        if ($period === null) {
            return ['success' => false, 'errors' => ['Choose a valid claim month.']];
        }

        $claimPeriodValidation = $this->validateClaimPeriodSelection($companyId, $period['year'], $period['month']);
        if ($claimPeriodValidation['errors'] !== []) {
            return ['success' => false, 'errors' => $claimPeriodValidation['errors']];
        }

        $existing = $this->findClaimByUniqueMonth($companyId, $claimantId, $period['year'], $period['month']);
        if ($existing !== null) {
            return [
                'success' => true,
                'claim' => $this->fetchClaim($companyId, (int)$existing['id']),
                'claims' => $this->listClaims($companyId),
                'messages' => ['That claimant already has a claim for the selected month, so the existing claim was opened.'],
            ];
        }

        $claimant = $this->fetchClaimantById($companyId, $claimantId);
        if ($claimant === null) {
            return ['success' => false, 'errors' => ['The selected claimant could not be found.']];
        }

        $derivedPeriod = $claimPeriodValidation['period'];
        $accountingPeriodId = (int)$claimPeriodValidation['accounting_period_id'];
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'create expense claims in this period');
        if ((int)($claimant['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['Only active claimants can be used for new claims.']];
        }

        $referenceCode = $this->generateUniqueReferenceCode($companyId, $period['year'], $period['month']);

        \InterfaceDB::prepare(
            'INSERT INTO expense_claims (
                company_id,
                accounting_period_id,
                claimant_id,
                claim_year,
                claim_month,
                period_start,
                period_end,
                claim_reference_code,
                brought_forward_amount,
                claimed_amount,
                payments_amount,
                carried_forward_amount,
                status,
                posted_journal_id,
                notes,
                created_at,
                updated_at
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :claimant_id,
                :claim_year,
                :claim_month,
                :period_start,
                :period_end,
                :claim_reference_code,
                0,
                0,
                0,
                0,
                :status,
                NULL,
                :notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        )->execute([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'claimant_id' => $claimantId,
            'claim_year' => $period['year'],
            'claim_month' => $period['month'],
            'period_start' => $derivedPeriod['period_start'],
            'period_end' => $derivedPeriod['period_end'],
            'claim_reference_code' => $referenceCode,
            'status' => 'draft',
            'notes' => trim((string)($payload['notes'] ?? '')),
        ]);

        $claim = $this->findClaimByUniqueMonth($companyId, $claimantId, $period['year'], $period['month']);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The claim could not be created.']];
        }

        $this->recalculateClaimSeries($companyId, $claimantId);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, (int)$claim['id']),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function fetchClaim(int $companyId, int $claimId): ?array {
        if ($companyId <= 0 || $claimId <= 0) {
            return null;
        }

        $claim = \InterfaceDB::fetchOne( 'SELECT ec.*,
                    c.claimant_name
             FROM expense_claims ec
             INNER JOIN expense_claimants c ON c.id = ec.claimant_id
             WHERE ec.company_id = :company_id
               AND ec.id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $claimId,
        ]);

        if (!is_array($claim)) {
            return null;
        }

        $claim['lines'] = $this->fetchClaimLines($claimId);
        $claim['payment_links'] = $this->fetchPaymentLinks($claimId);
        $claim['line_count'] = count((array)$claim['lines']);
        $claim['payment_link_count'] = count((array)$claim['payment_links']);
        $claim['no_lines_confirmed_at'] = (string)($claim['no_lines_confirmed_at'] ?? '');
        $claim['no_lines_confirmed_by'] = (string)($claim['no_lines_confirmed_by'] ?? '');
        $claim['no_lines_confirmed'] = (string)$claim['status'] === 'draft'
            && (int)$claim['line_count'] === 0
            && $claim['no_lines_confirmed_at'] !== '';
        $claim['control_totals'] = [
            'A' => (float)$claim['brought_forward_amount'],
            'B' => (float)$claim['claimed_amount'],
            'C' => (float)$claim['payments_amount'],
            'D' => (float)$claim['carried_forward_amount'],
        ];
        $claim['claim_period'] = sprintf('%04d-%02d', (int)$claim['claim_year'], (int)$claim['claim_month']);
        $claim['is_posted'] = (string)$claim['status'] === 'posted';
        $claim['status_label'] = $this->claimStatusLabel($claim);

        return $claim;
    }

    public function fetchClaimByReferenceCode(int $companyId, string $referenceCode): ?array {
        $referenceCode = trim($referenceCode);
        if ($companyId <= 0 || $referenceCode === '') {
            return null;
        }

        $claimId = (int)\InterfaceDB::fetchColumn( 'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id
               AND claim_reference_code = :claim_reference_code
             LIMIT 1', [
            'company_id' => $companyId,
            'claim_reference_code' => $referenceCode,
        ]);
        return $claimId > 0 ? $this->fetchClaim($companyId, $claimId) : null;
    }

    public function confirmNoLines(int $companyId, int $claimId, string $changedBy = 'web_app'): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are already locked.']];
        }

        if ((int)($claim['line_count'] ?? 0) > 0) {
            return ['success' => false, 'errors' => ['This claim already has lines, so submit the claim instead.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)($claim['accounting_period_id'] ?? 0),
            'confirm no expense claim lines in this period'
        );

        \InterfaceDB::prepare(
            'UPDATE expense_claims
             SET no_lines_confirmed_at = CURRENT_TIMESTAMP,
                 no_lines_confirmed_by = :no_lines_confirmed_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id'
        )->execute([
            'no_lines_confirmed_by' => $this->actorValue($changedBy),
            'id' => $claimId,
            'company_id' => $companyId,
        ]);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => ['No-lines month confirmed.'],
        ];
    }

    private function fetchClaimForHeatmapLine(int $companyId, int $claimantId, string $expenseDate, int $accountingPeriodId = 0): ?array {
        if ($companyId <= 0 || $claimantId <= 0 || !$this->isValidDate($expenseDate)) {
            return null;
        }

        $conditions = [
            'ec.company_id = :company_id',
            'ec.claimant_id = :claimant_id',
            'l.expense_date = :expense_date',
        ];
        $params = [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
            'expense_date' => $expenseDate,
        ];
        $accountingPeriodId = max(0, $accountingPeriodId);
        if ($accountingPeriodId > 0) {
            $conditions[] = 'ec.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        $claimId = (int)\InterfaceDB::fetchColumn( 'SELECT ec.id
             FROM expense_claim_lines l
             INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY ec.period_start DESC, l.line_number ASC, l.id ASC
             LIMIT 1', $params);

        return $claimId > 0 ? $this->fetchClaim($companyId, $claimId) : null;
    }

    public function updateClaim(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $previousClaimantId = (int)$claim['claimant_id'];
        $nextClaimantId = array_key_exists('claimant_id', $payload)
            ? (int)$payload['claimant_id']
            : $previousClaimantId;
        $nextPeriod = (array_key_exists('claim_period', $payload) || array_key_exists('claim_year', $payload) || array_key_exists('claim_month', $payload))
            ? $this->normaliseClaimPeriodFromPayload($payload)
            : [
                'year' => (int)$claim['claim_year'],
                'month' => (int)$claim['claim_month'],
            ];
        $nextNotes = array_key_exists('notes', $payload)
            ? trim((string)$payload['notes'])
            : (string)($claim['notes'] ?? '');

        if ($nextClaimantId <= 0) {
            return ['success' => false, 'errors' => ['Choose a claimant.']];
        }

        if ($nextPeriod === null) {
            return ['success' => false, 'errors' => ['Choose a valid claim month.']];
        }

        $claimPeriodValidation = $this->validateClaimPeriodSelection($companyId, $nextPeriod['year'], $nextPeriod['month']);
        if ($claimPeriodValidation['errors'] !== []) {
            return ['success' => false, 'errors' => $claimPeriodValidation['errors']];
        }

        $nextClaimant = $this->fetchClaimantById($companyId, $nextClaimantId);
        if ($nextClaimant === null) {
            return ['success' => false, 'errors' => ['The selected claimant could not be found.']];
        }
        if ((int)($nextClaimant['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'errors' => ['Only active claimants can be used for claims.']];
        }

        $duplicate = $this->findClaimByUniqueMonth($companyId, $nextClaimantId, $nextPeriod['year'], $nextPeriod['month']);
        if ($duplicate !== null && (int)$duplicate['id'] !== $claimId) {
            return ['success' => false, 'errors' => ['That claimant already has a claim for the selected month.']];
        }

        $derivedPeriod = $claimPeriodValidation['period'];
        $accountingPeriodId = (int)$claimPeriodValidation['accounting_period_id'];

        \InterfaceDB::prepare(
            'UPDATE expense_claims
             SET claimant_id = :claimant_id,
                 accounting_period_id = :accounting_period_id,
                 claim_year = :claim_year,
                 claim_month = :claim_month,
                 period_start = :period_start,
                 period_end = :period_end,
                 notes = :notes,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id'
        )->execute([
            'claimant_id' => $nextClaimantId,
            'accounting_period_id' => $accountingPeriodId,
            'claim_year' => $nextPeriod['year'],
            'claim_month' => $nextPeriod['month'],
            'period_start' => $derivedPeriod['period_start'],
            'period_end' => $derivedPeriod['period_end'],
            'notes' => $nextNotes,
            'id' => $claimId,
            'company_id' => $companyId,
        ]);

        $this->recalculateClaimSeries($companyId, $previousClaimantId);
        if ($nextClaimantId !== $previousClaimantId) {
            $this->recalculateClaimSeries($companyId, $nextClaimantId);
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId, [
                'heatmap_claimant_id' => (int)$claim['claimant_id'],
            ]),
        ];
    }

    public function saveLine(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $lineId = isset($payload['id']) ? (int)$payload['id'] : 0;
        $errors = $this->validateLinePayload($payload);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        $expenseDate = trim((string)$payload['expense_date']);
        $description = trim((string)$payload['description']);
        $amount = round((float)$payload['amount'], 2);
        $nominalAccountId = isset($payload['nominal_account_id']) && (int)$payload['nominal_account_id'] > 0
            ? (int)$payload['nominal_account_id']
            : null;
        $receiptReference = trim((string)($payload['receipt_reference'] ?? ''));
        $notes = trim((string)($payload['notes'] ?? ''));

        if ($lineId > 0) {
            \InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET expense_date = :expense_date,
                     description = :description,
                     amount = :amount,
                     nominal_account_id = :nominal_account_id,
                     receipt_reference = :receipt_reference,
                     notes = :notes,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND expense_claim_id = :expense_claim_id'
            )->execute([
                'expense_date' => $expenseDate,
                'description' => $description,
                'amount' => $amount,
                'nominal_account_id' => $nominalAccountId,
                'receipt_reference' => $receiptReference !== '' ? $receiptReference : null,
                'notes' => $notes !== '' ? $notes : null,
                'id' => $lineId,
                'expense_claim_id' => $claimId,
            ]);
        } else {
            $lineNumber = $this->nextLineNumber($claimId);
            \InterfaceDB::prepare(
                'INSERT INTO expense_claim_lines (
                    expense_claim_id,
                    line_number,
                    expense_date,
                    description,
                    amount,
                    nominal_account_id,
                    receipt_reference,
                    notes,
                    created_at,
                    updated_at
                 ) VALUES (
                    :expense_claim_id,
                    :line_number,
                    :expense_date,
                    :description,
                    :amount,
                    :nominal_account_id,
                    :receipt_reference,
                    :notes,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            )->execute([
                'expense_claim_id' => $claimId,
                'line_number' => $lineNumber,
                'expense_date' => $expenseDate,
                'description' => $description,
                'amount' => $amount,
                'nominal_account_id' => $nominalAccountId,
                'receipt_reference' => $receiptReference !== '' ? $receiptReference : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);
            $this->clearNoLinesConfirmation($companyId, $claimId);
        }

        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function deleteLine(int $companyId, int $claimId, int $lineId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        \InterfaceDB::prepare(
            'DELETE FROM expense_claim_lines
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);

        $this->renumberLines($claimId);
        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function deleteClaim(int $companyId, int $claimId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        if ((array)($claim['payment_links'] ?? []) !== []) {
            return ['success' => false, 'errors' => ['Remove repayment links before deleting this claim.']];
        }

        $claimantId = (int)$claim['claimant_id'];
        \InterfaceDB::prepare(
            'DELETE FROM expense_claims
             WHERE company_id = :company_id
               AND id = :id
               AND status = :status'
        )->execute([
            'company_id' => $companyId,
            'id' => $claimId,
            'status' => 'draft',
        ]);

        $this->recalculateClaimSeries($companyId, $claimantId);

        return [
            'success' => true,
            'claims' => $this->listClaims($companyId, ['heatmap_claimant_id' => $claimantId]),
            'deleted_claim_id' => $claimId,
            'messages' => ['Expense claim deleted.'],
        ];
    }

    public function previewBulkLines(int $companyId, int $claimId, string $pastedText, string $displayDateFormat = 'd/m/Y'): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $parsed = $this->parseBulkLineText($pastedText, $displayDateFormat);
        $parsed['claim'] = $claim;
        $parsed['source_text'] = $pastedText;

        return $parsed;
    }

    public function bulkSaveLines(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $pastedText = (string)($payload['pasted_lines'] ?? '');
        $displayDateFormat = (string)($payload['date_format'] ?? 'd/m/Y');
        $parsed = $this->parseBulkLineText($pastedText, $displayDateFormat);
        if (empty($parsed['success'])) {
            return $parsed;
        }

        $existingLineKeys = $this->bulkLineDedupeKeys((array)($claim['lines'] ?? []));
        $rowsToImport = [];
        $duplicateCount = 0;

        foreach ((array)$parsed['rows'] as $row) {
            $dedupeKey = $this->bulkLineDedupeKey($row);
            if (isset($existingLineKeys[$dedupeKey])) {
                $duplicateCount++;
                continue;
            }

            $existingLineKeys[$dedupeKey] = true;
            $rowsToImport[] = $row;
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($rowsToImport !== [] && $ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($rowsToImport !== []) {
                $lineNumber = $this->nextLineNumber($claimId);
                foreach ($rowsToImport as $row) {
                    \InterfaceDB::prepare(
                        'INSERT INTO expense_claim_lines (
                            expense_claim_id,
                            line_number,
                            expense_date,
                            description,
                            amount,
                            nominal_account_id,
                            receipt_reference,
                            notes,
                            created_at,
                            updated_at
                         ) VALUES (
                            :expense_claim_id,
                            :line_number,
                            :expense_date,
                            :description,
                            :amount,
                            NULL,
                            NULL,
                            NULL,
                            CURRENT_TIMESTAMP,
                            CURRENT_TIMESTAMP
                         )'
                    )->execute([
                        'expense_claim_id' => $claimId,
                        'line_number' => $lineNumber,
                        'expense_date' => (string)$row['expense_date'],
                        'description' => trim((string)$row['description']),
                        'amount' => round((float)$row['amount'], 2),
                    ]);
                    $lineNumber++;
                }

                $this->clearNoLinesConfirmation($companyId, $claimId);
                $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);
            }

            if ($rowsToImport !== [] && $ownsTransaction) {
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
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => [$this->bulkImportMessage(count($rowsToImport), $duplicateCount)],
        ];
    }

    public function updateLineNominal(int $companyId, int $claimId, int $lineId, int $nominalAccountId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        if ($lineId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid expense line.']];
        }

        $line = $this->fetchClaimLine($claimId, $lineId);
        if ($line !== null && (string)($line['line_type'] ?? 'expense') === 'asset') {
            return ['success' => false, 'errors' => ['Switch the line back to Expense before choosing an expense charge.']];
        }

        \InterfaceDB::prepare(
            'UPDATE expense_claim_lines
             SET nominal_account_id = :nominal_account_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'nominal_account_id' => $nominalAccountId > 0 ? $nominalAccountId : null,
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);
        (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForExpenseClaimLine($lineId);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => ['Line charge saved.'],
        ];
    }

    public function updateLineType(int $companyId, int $claimId, int $lineId, string $lineType): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $line = $this->fetchClaimLine($claimId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['Select a valid expense line.']];
        }

        $lineType = strtolower(trim($lineType));
        if (!in_array($lineType, ['expense', 'asset'], true)) {
            return ['success' => false, 'errors' => ['Choose Expense or Asset for the line type.']];
        }

        if ($lineType === 'asset') {
            $normalised = $this->normaliseLineAssetPayload($line, [
                'category' => 'tools_equipment',
                'description' => (string)($line['description'] ?? ''),
                'useful_life_years' => 3,
                'depreciation_method' => 'straight_line',
                'residual_value' => 0,
            ], (int)$claim['accounting_period_id'], $companyId);
            if ($normalised['errors'] !== []) {
                return ['success' => false, 'errors' => $normalised['errors']];
            }

            $this->upsertLineAssetDetails($line, $normalised['values']);
            \InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET nominal_account_id = :nominal_account_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND expense_claim_id = :expense_claim_id'
            )->execute([
                'nominal_account_id' => (int)$normalised['values']['nominal_account_id'],
                'id' => $lineId,
                'expense_claim_id' => $claimId,
            ]);
            (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForExpenseClaimLine($lineId);
        } else {
            \InterfaceDB::prepare(
                'DELETE FROM expense_claim_line_assets
                 WHERE expense_claim_line_id = :expense_claim_line_id'
            )->execute(['expense_claim_line_id' => $lineId]);
            \InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET nominal_account_id = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND expense_claim_id = :expense_claim_id'
            )->execute([
                'id' => $lineId,
                'expense_claim_id' => $claimId,
            ]);
            (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForExpenseClaimLine($lineId);
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => ['Line type saved.'],
        ];
    }

    public function saveLineAssetDetails(int $companyId, int $claimId, int $lineId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['Posted claims are locked.']];
        }

        $line = $this->fetchClaimLine($claimId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['Select a valid expense line.']];
        }

        $normalised = $this->normaliseLineAssetPayload($line, $payload, (int)$claim['accounting_period_id'], $companyId);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $this->upsertLineAssetDetails($line, $normalised['values']);
        \InterfaceDB::prepare(
            'UPDATE expense_claim_lines
             SET nominal_account_id = :nominal_account_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'nominal_account_id' => (int)$normalised['values']['nominal_account_id'],
            'id' => $lineId,
            'expense_claim_id' => $claimId,
        ]);
        (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForExpenseClaimLine($lineId);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => ['Asset details saved.'],
        ];
    }

    public function convertPostedLineToAsset(int $companyId, int $lineId, array $payload): array
    {
        if (!\InterfaceDB::tableExists('expense_claim_line_assets') || !\InterfaceDB::tableExists('asset_register')) {
            return ['success' => false, 'errors' => ['Run the fixed asset expense claim migration before converting expense lines to assets.']];
        }

        $line = $this->fetchLineWithClaim($companyId, $lineId);
        if ($line === null) {
            return ['success' => false, 'errors' => ['The selected expense claim line could not be found for this company.']];
        }

        $claimId = (int)$line['expense_claim_id'];
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        if ((string)($claim['status'] ?? '') !== 'posted') {
            return ['success' => false, 'errors' => ['Use the expense claim editor to convert draft claim lines to assets.']];
        }

        $journalId = (int)($claim['posted_journal_id'] ?? 0);
        if ($journalId <= 0) {
            return ['success' => false, 'errors' => ['This posted claim does not have a linked journal to rebuild.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            $companyId,
            (int)($claim['accounting_period_id'] ?? 0),
            'convert posted expense claim lines to assets in this period'
        );

        if ($this->linkedExpenseClaimLineAssetExists($lineId)) {
            return ['success' => false, 'errors' => ['This expense claim line is already linked to an asset.']];
        }

        $normalised = $this->normaliseLineAssetPayload($line, [
            'category' => $payload['category'] ?? $payload['asset_category'] ?? 'tools_equipment',
            'useful_life_years' => $payload['useful_life_years'] ?? $payload['asset_useful_life_years'] ?? 3,
            'depreciation_method' => $payload['depreciation_method'] ?? $payload['asset_depreciation_method'] ?? 'straight_line',
            'residual_value' => $payload['residual_value'] ?? $payload['asset_residual_value'] ?? '0.00',
        ], (int)$claim['accounting_period_id'], $companyId);
        if ($normalised['errors'] !== []) {
            return ['success' => false, 'errors' => $normalised['errors']];
        }

        $payableNominalId = $this->payableNominalIdFromJournal($journalId);
        if ($payableNominalId <= 0) {
            return ['success' => false, 'errors' => ['The expense claim payable nominal could not be identified from the posted journal.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $this->upsertLineAssetDetails($line, $normalised['values']);
            \InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET nominal_account_id = :nominal_account_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND expense_claim_id = :expense_claim_id'
            )->execute([
                'nominal_account_id' => (int)$normalised['values']['nominal_account_id'],
                'id' => $lineId,
                'expense_claim_id' => $claimId,
            ]);
            (new \eel_accounts\Service\VehicleService())->cleanupVehicleDetailsForExpenseClaimLine($lineId);

            $this->rebuildPostedClaimJournal($companyId, $claim, $journalId, $payableNominalId);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The expense claim line could not be converted to an asset: ' . $exception->getMessage()]];
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
            'messages' => ['Expense claim line converted to an asset and the posted journal rebuilt.'],
        ];
    }

    public function linkPayment(int $companyId, int $claimId, array $payload): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, (int)($claim['accounting_period_id'] ?? 0), 'link expense repayments in this period');

        $transactionId = isset($payload['transaction_id']) ? (int)$payload['transaction_id'] : 0;
        $defaultExpenseNominalId = isset($payload['default_expense_nominal_id']) ? (int)$payload['default_expense_nominal_id'] : 0;
        $defaultBankNominalId = isset($payload['default_bank_nominal_id']) ? (int)$payload['default_bank_nominal_id'] : 0;

        if ($transactionId <= 0) {
            return ['success' => false, 'errors' => ['Select a repayment transaction.']];
        }

        if ($defaultExpenseNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the expense claims payable nominal before linking repayments.']];
        }

        $transaction = $this->categorisationService->fetchTransaction($transactionId);
        if ($transaction === null || (int)$transaction['company_id'] !== $companyId) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found.']];
        }

        $transactionAmount = round(abs((float)$transaction['amount']), 2);
        if ($transactionAmount <= 0) {
            return ['success' => false, 'errors' => ['Repayment transaction amount must be greater than zero.']];
        }
        $linkedAmount = $transactionAmount;

        $allocatedElsewhere = $this->sumLinkedAmountForTransaction($transactionId, $claimId);
        if ($allocatedElsewhere > 0.0001) {
            return ['success' => false, 'errors' => ['That transaction is already linked to another expense claim.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $existingLink = $this->findPaymentLink($claimId, $transactionId);
            if ($existingLink !== null) {
                \InterfaceDB::prepare(
                    'UPDATE expense_claim_payment_links
                     SET linked_amount = :linked_amount
                     WHERE id = :id'
                )->execute([
                    'linked_amount' => $linkedAmount,
                    'id' => (int)$existingLink['id'],
                ]);
            } else {
                \InterfaceDB::prepare(
                    'INSERT INTO expense_claim_payment_links (
                        expense_claim_id,
                        transaction_id,
                        linked_amount,
                        created_at
                     ) VALUES (
                        :expense_claim_id,
                        :transaction_id,
                        :linked_amount,
                        CURRENT_TIMESTAMP
                     )'
                )->execute([
                    'expense_claim_id' => $claimId,
                    'transaction_id' => $transactionId,
                    'linked_amount' => $linkedAmount,
                ]);
            }

            $saveResult = $this->categorisationService->saveManualCategorisation(
                $transactionId,
                $defaultExpenseNominalId,
                null,
                false,
                'expense_claim_payment_link',
                true
            );

            if (!empty($saveResult['errors'])) {
                throw new \RuntimeException(implode(' ', array_map('strval', $saveResult['errors'])));
            }

            if (!empty($saveResult['requires_journal_rebuild'])) {
                if ($defaultBankNominalId <= 0) {
                    throw new \RuntimeException('Set the default bank nominal before linking a posted repayment transaction.');
                }

                $journalResult = $this->journalService->syncJournalForTransaction(
                    $transactionId,
                    $defaultBankNominalId,
                    'expense_claim_payment_link',
                    true
                );

                if (!empty($journalResult['errors'])) {
                    throw new \RuntimeException(implode(' ', array_map('strval', $journalResult['errors'])));
                }
            }

            $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

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
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId, [
                'heatmap_claimant_id' => (int)$claim['claimant_id'],
            ]),
        ];
    }

    public function unlinkPayment(int $companyId, int $claimId, int $paymentLinkId): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, (int)($claim['accounting_period_id'] ?? 0), 'unlink expense repayments in this period');

        \InterfaceDB::prepare(
            'DELETE FROM expense_claim_payment_links
             WHERE id = :id
               AND expense_claim_id = :expense_claim_id'
        )->execute([
            'id' => $paymentLinkId,
            'expense_claim_id' => $claimId,
        ]);

        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function searchTransactions(int $companyId, array $filters = []): array {
        if ($companyId <= 0) {
            return [];
        }

        $claimId = isset($filters['claim_id']) ? (int)$filters['claim_id'] : 0;
        $query = trim((string)($filters['query'] ?? ''));
        $currentMonthOnly = !array_key_exists('current_month_only', $filters) || (bool)$filters['current_month_only'];
        $claim = $claimId > 0 ? $this->fetchClaim($companyId, $claimId) : null;

        $conditions = ['t.company_id = ?', 't.amount < 0'];
        $params = [$companyId];

        if ($query !== '') {
            $conditions[] = '(t.description LIKE ? OR COALESCE(t.reference, \'\') LIKE ? OR COALESCE(t.counterparty_name, \'\') LIKE ?)';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        }

        if ($currentMonthOnly && $claim !== null) {
            $conditions[] = 't.txn_date BETWEEN ? AND ?';
            $params[] = (string)$claim['period_start'];
            $params[] = (string)$claim['period_end'];
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount,
                    t.nominal_account_id,
                    t.category_status,
                    n.code AS nominal_code,
                    n.name AS nominal_name,
                    COALESCE(SUM(CASE WHEN l.expense_claim_id <> ? THEN l.linked_amount ELSE 0 END), 0) AS allocated_elsewhere,
                    MAX(CASE WHEN l.expense_claim_id = ? THEN l.id ELSE 0 END) AS current_link_id,
                    COALESCE(MAX(CASE WHEN l.expense_claim_id = ? THEN l.linked_amount ELSE 0 END), 0) AS current_link_amount
             FROM transactions t
             LEFT JOIN nominal_accounts n ON n.id = t.nominal_account_id
             LEFT JOIN expense_claim_payment_links l ON l.transaction_id = t.id
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY t.id, t.txn_date, t.description, t.reference, t.amount, t.nominal_account_id, t.category_status, n.code, n.name
             ORDER BY t.txn_date DESC, t.id DESC'
        );
        $stmt->execute(array_merge([$claimId, $claimId, $claimId], $params));

        return array_map(
            static function (array $row): array {
                $amount = round(abs((float)$row['amount']), 2);
                $allocatedElsewhere = round((float)$row['allocated_elsewhere'], 2);
                $availableAmount = max(0.0, round($amount - $allocatedElsewhere, 2));

                return [
                    'id' => (int)$row['id'],
                    'txn_date' => (string)$row['txn_date'],
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'amount' => $amount,
                    'allocated_elsewhere' => $allocatedElsewhere,
                    'available_amount' => $availableAmount,
                    'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                        ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                        : (string)($row['nominal_name'] ?? ''),
                    'category_status' => (string)$row['category_status'],
                    'current_link_id' => (int)$row['current_link_id'],
                    'current_link_amount' => round((float)$row['current_link_amount'], 2),
                ];
            },
            $stmt->fetchAll() ?: []
        );
    }

    public function postClaim(int $companyId, int $claimId, array $payload = []): array {
        $claim = $this->fetchClaim($companyId, $claimId);
        if ($claim === null) {
            return ['success' => false, 'errors' => ['The selected claim could not be found.']];
        }
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, (int)($claim['accounting_period_id'] ?? 0), 'post expense claims in this period');

        if ((string)$claim['status'] === 'posted') {
            return ['success' => false, 'errors' => ['This claim has already been posted.']];
        }

        if ((string)$claim['claim_reference_code'] === '') {
            return ['success' => false, 'errors' => ['Claim reference code is missing.']];
        }

        $expenseClaimsPayableNominalId = isset($payload['default_expense_nominal_id']) ? (int)$payload['default_expense_nominal_id'] : 0;
        if ($expenseClaimsPayableNominalId <= 0) {
            return ['success' => false, 'errors' => ['Set the expense claims payable nominal before submitting a claim.']];
        }

        $lines = $this->fetchClaimLines($claimId);
        if ($lines === []) {
            return ['success' => false, 'errors' => ['Add at least one expense line before posting a claim.']];
        }

        foreach ($lines as $line) {
            $lineErrors = $this->validateLineForPosting($companyId, (int)$claim['accounting_period_id'], $line);
            if ($lineErrors !== []) {
                return ['success' => false, 'errors' => $lineErrors];
            }
        }

        $totalClaimed = $this->sumClaimLines($claimId);
        if ($totalClaimed <= 0) {
            return ['success' => false, 'errors' => ['Claim total must be greater than zero before posting.']];
        }
        $this->recalculateClaimSeries($companyId, (int)$claim['claimant_id']);

        $existingJournal = $this->fetchExistingExpenseJournal($companyId, (string)$claim['claim_reference_code']);
        if ($existingJournal !== null) {
            return ['success' => false, 'errors' => ['An expense journal already exists for this claim reference.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            \InterfaceDB::prepare(
                'INSERT INTO journals (
                    company_id,
                    accounting_period_id,
                    source_type,
                    source_ref,
                    journal_date,
                    description,
                    is_posted,
                    created_at,
                    updated_at
                 ) VALUES (
                    :company_id,
                    :accounting_period_id,
                    :source_type,
                    :source_ref,
                    :journal_date,
                    :description,
                    1,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            )->execute([
                'company_id' => $companyId,
                'accounting_period_id' => (int)$claim['accounting_period_id'],
                'source_type' => 'expense_register',
                'source_ref' => (string)$claim['claim_reference_code'],
                'journal_date' => (string)$claim['period_end'],
                'description' => 'Expense claim ' . (string)$claim['claim_reference_code'],
            ]);

            $journal = $this->fetchExistingExpenseJournal($companyId, (string)$claim['claim_reference_code']);
            if ($journal === null) {
                throw new \RuntimeException('The expense journal could not be created.');
            }

            $assetService = new \eel_accounts\Service\AssetService();
            foreach ($lines as $line) {
                if ((string)($line['line_type'] ?? 'expense') === 'asset') {
                    $normalisedAsset = $this->normaliseAssetValuesForPosting($assetService, $companyId, (int)$claim['accounting_period_id'], $line);
                    if ($normalisedAsset['errors'] !== []) {
                        throw new \RuntimeException(implode(' ', $normalisedAsset['errors']));
                    }

                    $values = (array)$normalisedAsset['values'];
                    $this->insertJournalLine(
                        (int)$journal['id'],
                        (int)$values['nominal_account_id'],
                        round((float)$line['amount'], 2),
                        0.0,
                        (string)$line['description']
                    );
                    $asset = $assetService->createAssetRecordFromValues($values, [
                        'linked_journal_id' => (int)$journal['id'],
                        'linked_transaction_id' => null,
                        'linked_expense_claim_line_id' => (int)$line['id'],
                    ]);
                    if ($asset === null) {
                        throw new \RuntimeException('The asset could not be reloaded after save.');
                    }
                    \InterfaceDB::prepare(
                        'UPDATE expense_claim_line_assets
                         SET generated_asset_id = :generated_asset_id,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE expense_claim_line_id = :expense_claim_line_id'
                    )->execute([
                        'generated_asset_id' => (int)$asset['id'],
                        'expense_claim_line_id' => (int)$line['id'],
                    ]);
                    continue;
                }

                $this->insertJournalLine(
                    (int)$journal['id'],
                    (int)$line['nominal_account_id'],
                    round((float)$line['amount'], 2),
                    0.0,
                    (string)$line['description']
                );
            }

            $this->insertJournalLine(
                (int)$journal['id'],
                $expenseClaimsPayableNominalId,
                0.0,
                $totalClaimed,
                'Expense claim payable'
            );

            \InterfaceDB::prepare(
                'UPDATE expense_claims
                 SET status = :status,
                     posted_journal_id = :posted_journal_id,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND company_id = :company_id'
            )->execute([
                'status' => 'posted',
                'posted_journal_id' => (int)$journal['id'],
                'id' => $claimId,
                'company_id' => $companyId,
            ]);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => ['The claim could not be posted: ' . $exception->getMessage()]];
        }

        return [
            'success' => true,
            'claim' => $this->fetchClaim($companyId, $claimId),
            'claims' => $this->listClaims($companyId),
        ];
    }

    public function fetchExpenseNominals(): array {
        $stmt = \InterfaceDB::query(
            "SELECT id, code, name, account_type
             FROM nominal_accounts
             WHERE is_active = 1
               AND account_type IN ('expense', 'cost_of_sales')
             ORDER BY sort_order ASC, code ASC, id ASC"
        );

        return $stmt->fetchAll() ?: [];
    }

    private function fetchClaimLine(int $claimId, int $lineId): ?array {
        foreach ($this->fetchClaimLines($claimId) as $line) {
            if ((int)($line['id'] ?? 0) === $lineId) {
                return $line;
            }
        }

        return null;
    }

    private function fetchLineWithClaim(int $companyId, int $lineId): ?array
    {
        if ($companyId <= 0 || $lineId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT l.id,
                    l.expense_claim_id,
                    l.line_number,
                    l.expense_date,
                    l.description,
                    l.amount,
                    l.nominal_account_id,
                    l.receipt_reference,
                    l.notes,
                    la.category AS asset_category,
                    la.description AS asset_description,
                    la.useful_life_years AS asset_useful_life_years,
                    la.depreciation_method AS asset_depreciation_method,
                    la.residual_value AS asset_residual_value,
                    la.generated_asset_id
             FROM expense_claim_lines l
             INNER JOIN expense_claims ec ON ec.id = l.expense_claim_id
             LEFT JOIN expense_claim_line_assets la ON la.expense_claim_line_id = l.id
             WHERE l.id = :line_id
               AND ec.company_id = :company_id
             LIMIT 1',
            ['line_id' => $lineId, 'company_id' => $companyId]
        );

        if (!is_array($row)) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['expense_claim_id'] = (int)$row['expense_claim_id'];
        $row['line_number'] = (int)$row['line_number'];
        $row['amount'] = round((float)$row['amount'], 2);
        $row['nominal_account_id'] = isset($row['nominal_account_id']) ? (int)$row['nominal_account_id'] : null;
        $row['generated_asset_id'] = isset($row['generated_asset_id']) ? (int)$row['generated_asset_id'] : null;

        return $row;
    }

    public function recalculateClaim(int $claimId): void {
        $claimRow = $this->fetchClaimRow($claimId);
        if ($claimRow === null) {
            return;
        }

        $this->recalculateClaimSeries((int)$claimRow['company_id'], (int)$claimRow['claimant_id']);
    }

    public function recalculateClaimSeries(int $companyId, int $claimantId): void {
        if ($companyId <= 0 || $claimantId <= 0) {
            return;
        }

        $claims = \InterfaceDB::fetchAll( 'SELECT id,
                    brought_forward_amount,
                    claimed_amount,
                    payments_amount,
                    carried_forward_amount
             FROM expense_claims
             WHERE company_id = :company_id
               AND claimant_id = :claimant_id
             ORDER BY period_start ASC, id ASC', [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
        ]);
        if ($claims === []) {
            return;
        }

        $claimIds = array_values(array_map(static fn(array $claim): int => (int)$claim['id'], $claims));
        $lineTotals = $this->fetchClaimLineTotals($claimIds);
        $paymentTotals = $this->fetchClaimPaymentTotals($claimIds);
        $broughtForward = 0.0;

        foreach ($claims as $claim) {
            $seriesClaimId = (int)$claim['id'];
            $claimed = (float)($lineTotals[$seriesClaimId] ?? 0.0);
            $payments = (float)($paymentTotals[$seriesClaimId] ?? 0.0);
            $carriedForward = round($broughtForward + $claimed - $payments, 2);

            if (
                round((float)$claim['brought_forward_amount'], 2) === $broughtForward
                && round((float)$claim['claimed_amount'], 2) === $claimed
                && round((float)$claim['payments_amount'], 2) === $payments
                && round((float)$claim['carried_forward_amount'], 2) === $carriedForward
            ) {
                $broughtForward = $carriedForward;
                continue;
            }

            \InterfaceDB::prepare(
                'UPDATE expense_claims
                 SET brought_forward_amount = :brought_forward_amount,
                     claimed_amount = :claimed_amount,
                     payments_amount = :payments_amount,
                     carried_forward_amount = :carried_forward_amount,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'brought_forward_amount' => $broughtForward,
                'claimed_amount' => $claimed,
                'payments_amount' => $payments,
                'carried_forward_amount' => $carriedForward,
                'id' => $seriesClaimId,
            ]);

            $broughtForward = $carriedForward;
        }
    }

    public function recalculateCompanyClaimSeries(int $companyId): void {
        if ($companyId <= 0) {
            return;
        }

        $claimants = \InterfaceDB::fetchAll(
            'SELECT DISTINCT claimant_id
             FROM expense_claims
             WHERE company_id = :company_id
             ORDER BY claimant_id ASC',
            ['company_id' => $companyId]
        );

        foreach ($claimants as $claimant) {
            $this->recalculateClaimSeries($companyId, (int)($claimant['claimant_id'] ?? 0));
        }
    }

    /**
     * @param list<int> $claimIds
     * @return array<int, float>
     */
    private function fetchClaimLineTotals(array $claimIds): array {
        return $this->fetchClaimTotalsByTable($claimIds, 'expense_claim_lines', 'amount');
    }

    /**
     * @param list<int> $claimIds
     * @return array<int, float>
     */
    private function fetchClaimPaymentTotals(array $claimIds): array {
        return $this->fetchClaimTotalsByTable($claimIds, 'expense_claim_payment_links', 'linked_amount');
    }

    /**
     * @param list<int> $claimIds
     * @return array<int, float>
     */
    private function fetchClaimTotalsByTable(array $claimIds, string $table, string $amountColumn): array {
        $claimIds = array_values(array_filter(array_map('intval', $claimIds), static fn(int $claimId): bool => $claimId > 0));
        if ($claimIds === []) {
            return [];
        }

        if (!in_array($table, ['expense_claim_lines', 'expense_claim_payment_links'], true)) {
            return [];
        }
        if (!in_array($amountColumn, ['amount', 'linked_amount'], true)) {
            return [];
        }

        $totals = [];
        foreach (array_chunk($claimIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $rows = \InterfaceDB::fetchAll(
                'SELECT expense_claim_id, COALESCE(SUM(' . $amountColumn . '), 0) AS total
                 FROM ' . $table . '
                 WHERE expense_claim_id IN (' . $placeholders . ')
                 GROUP BY expense_claim_id',
                $chunk
            );

            foreach ($rows as $row) {
                $totals[(int)$row['expense_claim_id']] = round((float)$row['total'], 2);
            }
        }

        return $totals;
    }

    private function fetchClaimLines(int $claimId): array {
        $assetCategories = \eel_accounts\Service\AssetService::assetCategoryOptions();

        return array_map(
            static function (array $row) use ($assetCategories): array {
                $assetCategory = trim((string)($row['asset_category'] ?? ''));
                $assetCategoryLabel = $assetCategory !== ''
                    ? (string)($assetCategories[$assetCategory] ?? $assetCategory)
                    : '';

                return [
                    'id' => (int)$row['id'],
                    'expense_claim_id' => (int)$row['expense_claim_id'],
                    'line_number' => (int)$row['line_number'],
                    'expense_date' => (string)$row['expense_date'],
                    'description' => (string)$row['description'],
                    'amount' => round((float)$row['amount'], 2),
                    'nominal_account_id' => isset($row['nominal_account_id']) ? (int)$row['nominal_account_id'] : null,
                    'line_type' => $assetCategory !== '' ? 'asset' : 'expense',
                    'receipt_reference' => (string)($row['receipt_reference'] ?? ''),
                    'notes' => (string)($row['notes'] ?? ''),
                    'asset_category' => $assetCategory,
                    'asset_category_label' => $assetCategoryLabel,
                    'asset_description' => (string)($row['asset_description'] ?? ''),
                    'asset_useful_life_years' => isset($row['asset_useful_life_years']) ? (int)$row['asset_useful_life_years'] : 3,
                    'asset_depreciation_method' => (string)($row['asset_depreciation_method'] ?? 'straight_line'),
                    'asset_residual_value' => round((float)($row['asset_residual_value'] ?? 0), 2),
                    'generated_asset_id' => isset($row['generated_asset_id']) ? (int)$row['generated_asset_id'] : null,
                    'asset_code' => (string)($row['asset_code'] ?? ''),
                    'nominal_label' => trim((string)($row['nominal_code'] ?? '')) !== ''
                        ? (string)$row['nominal_code'] . ' - ' . (string)($row['nominal_name'] ?? '')
                        : (string)($row['nominal_name'] ?? ''),
                ];
            },
            \InterfaceDB::fetchAll( 'SELECT l.id,
                    l.expense_claim_id,
                    l.line_number,
                    l.expense_date,
                    l.description,
                    l.amount,
                    l.nominal_account_id,
                    l.receipt_reference,
                    l.notes,
                    l.created_at,
                    l.updated_at,
                    la.category AS asset_category,
                    la.description AS asset_description,
                    la.useful_life_years AS asset_useful_life_years,
                    la.depreciation_method AS asset_depreciation_method,
                    la.residual_value AS asset_residual_value,
                    la.generated_asset_id,
                    ar.asset_code,
                    n.code AS nominal_code,
                    n.name AS nominal_name
             FROM expense_claim_lines l
             LEFT JOIN nominal_accounts n ON n.id = l.nominal_account_id
             LEFT JOIN expense_claim_line_assets la ON la.expense_claim_line_id = l.id
             LEFT JOIN asset_register ar ON ar.id = la.generated_asset_id
             WHERE l.expense_claim_id = :expense_claim_id
             ORDER BY l.line_number ASC, l.id ASC', ['expense_claim_id' => $claimId])
        );
    }

    private function fetchPaymentLinks(int $claimId): array {
        return array_map(
            static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'expense_claim_id' => (int)$row['expense_claim_id'],
                    'transaction_id' => (int)$row['transaction_id'],
                    'linked_amount' => round((float)$row['linked_amount'], 2),
                    'txn_date' => (string)$row['txn_date'],
                    'description' => (string)$row['description'],
                    'reference' => (string)($row['reference'] ?? ''),
                    'transaction_amount' => round(abs((float)$row['amount']), 2),
                ];
            },
            \InterfaceDB::fetchAll( 'SELECT l.id,
                    l.expense_claim_id,
                    l.transaction_id,
                    l.linked_amount,
                    l.created_at,
                    t.txn_date,
                    t.description,
                    t.reference,
                    t.amount
             FROM expense_claim_payment_links l
             INNER JOIN transactions t ON t.id = l.transaction_id
             WHERE l.expense_claim_id = :expense_claim_id
             ORDER BY t.txn_date DESC, l.id DESC', ['expense_claim_id' => $claimId])
        );
    }

    private function fetchClaimantById(int $companyId, int $claimantId): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    claimant_name,
                    is_active,
                    created_at,
                    updated_at,
                    (SELECT COUNT(*)
                       FROM expense_claims
                      WHERE expense_claims.company_id = expense_claimants.company_id
                        AND expense_claims.claimant_id = expense_claimants.id) AS claim_count
             FROM expense_claimants
             WHERE company_id = :company_id
               AND id = :id
             LIMIT 1', [
            'company_id' => $companyId,
            'id' => $claimantId,
        ]);
        return is_array($row) ? $this->formatClaimant($row) : null;
    }

    private function findClaimantByName(int $companyId, string $claimantName): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT id,
                    company_id,
                    claimant_name,
                    is_active,
                    created_at,
                    updated_at,
                    (SELECT COUNT(*)
                       FROM expense_claims
                      WHERE expense_claims.company_id = expense_claimants.company_id
                        AND expense_claims.claimant_id = expense_claimants.id) AS claim_count
             FROM expense_claimants
             WHERE company_id = :company_id
               AND claimant_name = :claimant_name
             LIMIT 1', [
            'company_id' => $companyId,
            'claimant_name' => $claimantName,
        ]);
        return is_array($row) ? $this->formatClaimant($row) : null;
    }

    private function formatClaimant(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['company_id'] = (int)($row['company_id'] ?? 0);
        $row['is_active'] = (int)($row['is_active'] ?? 0);
        $row['claim_count'] = (int)($row['claim_count'] ?? 0);

        return $row;
    }

    private function findClaimByUniqueMonth(int $companyId, int $claimantId, int $claimYear, int $claimMonth): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT id
             FROM expense_claims
             WHERE company_id = :company_id
               AND claimant_id = :claimant_id
               AND claim_year = :claim_year
               AND claim_month = :claim_month
             LIMIT 1', [
            'company_id' => $companyId,
            'claimant_id' => $claimantId,
            'claim_year' => $claimYear,
            'claim_month' => $claimMonth,
        ]);
        return is_array($row) ? $row : null;
    }

    private function fetchClaimRow(int $claimId): ?array {
        $row = \InterfaceDB::fetchOne( 'SELECT id, company_id, claimant_id, period_start
             FROM expense_claims
             WHERE id = :id
             LIMIT 1', ['id' => $claimId]);
        return is_array($row) ? $row : null;
    }

    private function sumClaimLines(int $claimId): float {
        return round((float)\InterfaceDB::fetchColumn( 'SELECT COALESCE(SUM(amount), 0)
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id', ['expense_claim_id' => $claimId]), 2);
    }

    private function sumLinkedAmountForTransaction(int $transactionId, int $excludingClaimId = 0): float {
        $sql = 'SELECT COALESCE(SUM(linked_amount), 0)
                FROM expense_claim_payment_links
                WHERE transaction_id = :transaction_id';
        $params = ['transaction_id' => $transactionId];

        if ($excludingClaimId > 0) {
            $sql .= ' AND expense_claim_id <> :expense_claim_id';
            $params['expense_claim_id'] = $excludingClaimId;
        }

        $stmt = \InterfaceDB::prepare($sql);
        $stmt->execute($params);

        return round((float)$stmt->fetchColumn(), 2);
    }

    private function findPaymentLink(int $claimId, int $transactionId): ?array {
        $stmt = \InterfaceDB::prepare(
            'SELECT id, expense_claim_id, transaction_id, linked_amount
             FROM expense_claim_payment_links
             WHERE expense_claim_id = :expense_claim_id
               AND transaction_id = :transaction_id
             LIMIT 1'
        );
        $stmt->execute([
            'expense_claim_id' => $claimId,
            'transaction_id' => $transactionId,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function nextLineNumber(int $claimId): int {
        $stmt = \InterfaceDB::prepare(
            'SELECT COALESCE(MAX(line_number), 0)
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id'
        );
        $stmt->execute(['expense_claim_id' => $claimId]);

        return ((int)$stmt->fetchColumn()) + 1;
    }

    private function resolveAccountingPeriodIdForDate(int $companyId, string $date): int {
        $stmt = \InterfaceDB::prepare(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_start <= :date
               AND period_end >= :date
             ORDER BY period_start DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'date' => $date,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function renumberLines(int $claimId): void {
        $stmt = \InterfaceDB::prepare(
            'SELECT id
             FROM expense_claim_lines
             WHERE expense_claim_id = :expense_claim_id
             ORDER BY line_number ASC, id ASC'
        );
        $stmt->execute(['expense_claim_id' => $claimId]);

        $lineNumber = 1;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            \InterfaceDB::prepare(
                'UPDATE expense_claim_lines
                 SET line_number = :line_number,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'line_number' => $lineNumber,
                'id' => (int)$row['id'],
            ]);
            $lineNumber++;
        }
    }

    private function validateLinePayload(array $payload): array {
        $errors = [];
        $expenseDate = trim((string)($payload['expense_date'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;

        if ($expenseDate === '' || !$this->isValidDate($expenseDate)) {
            $errors[] = 'Expense date is required.';
        }

        if ($description === '') {
            $errors[] = 'Description is required.';
        }

        if ($amount <= 0) {
            $errors[] = 'Amount must be greater than zero.';
        }

        return $errors;
    }

    private function parseBulkLineText(string $pastedText, string $displayDateFormat = 'd/m/Y'): array {
        $pastedText = trim(str_replace(["\u{00a0}", "\r\n", "\r"], [' ', "\n", "\n"], $pastedText));
        if ($pastedText === '') {
            return ['success' => false, 'errors' => ['Paste at least one expense line.'], 'rows' => [], 'total' => 0.0];
        }

        $rows = [];
        $errors = [];
        $lineNumber = 0;
        $isTsv = substr_count($pastedText, "\t") >= 2;

        foreach (explode("\n", $pastedText) as $rawLine) {
            $lineNumber++;
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $cells = $isTsv
                ? $this->parseBulkTsvLineCells($line)
                : $this->parseBulkCsvLineCells($line, $lineNumber, $errors);
            if ($cells === [] || $this->bulkLineIsIgnorable($cells)) {
                continue;
            }

            if (count($cells) < 3) {
                if ($this->bulkLineLooksLikeData($line)) {
                    $errors[] = 'Line ' . $lineNumber . ' is not ' . ($isTsv ? 'tab-delimited' : 'quoted CSV') . ' into date, description, and amount.';
                }
                continue;
            }

            $expenseDate = $this->parseBulkLineDate((string)$cells[0]);
            $amount = $this->parseBulkLineAmount((string)$cells[count($cells) - 1]);
            $description = trim((string)$cells[1]);

            if ($expenseDate === null || $description === '' || $amount <= 0) {
                $errors[] = 'Line ' . $lineNumber . ' could not be read as date, description, and amount.';
                continue;
            }

            $rows[] = [
                'expense_date' => $expenseDate->format('Y-m-d'),
                'expense_date_display' => $expenseDate->format($this->normaliseDisplayDateFormat($displayDateFormat)),
                'description' => $description,
                'amount' => round($amount, 2),
            ];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'No expense lines were found in the pasted text.';
        }

        return [
            'success' => $errors === [] && $rows !== [],
            'errors' => $errors,
            'rows' => $rows,
            'total' => round(array_reduce(
                $rows,
                static fn(float $total, array $row): float => $total + round((float)$row['amount'], 2),
                0.0
            ), 2),
        ];
    }

    private function parseBulkTsvLineCells(string $line): array {
        return array_values(array_filter(
            array_map(static fn(string $cell): string => trim($cell), explode("\t", $line)),
            static fn(string $cell): bool => $cell !== ''
        ));
    }

    private function parseBulkCsvLineCells(string $line, int $lineNumber, array &$errors): array {
        $rawFields = $this->splitBulkCsvRawFields($line);
        if ($rawFields === null) {
            $errors[] = 'Line ' . $lineNumber . ' has malformed quoted CSV fields.';
            return [];
        }

        if (!$this->bulkCsvRawFieldsAreQuoted($rawFields)) {
            if (str_contains($line, ',') || $this->bulkLineLooksLikeData($line)) {
                $errors[] = 'Line ' . $lineNumber . ' CSV fields must be double-quoted.';
            }
            return [];
        }

        $cells = str_getcsv($line, ',', '"', '\\');
        if (!is_array($cells)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(string $cell): string => trim($cell), $cells),
            static fn(string $cell): bool => $cell !== ''
        ));
    }

    private function splitBulkCsvRawFields(string $line): ?array {
        $fields = [];
        $field = '';
        $inQuotes = false;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                $field .= $char;
                if ($inQuotes && $i + 1 < $length && $line[$i + 1] === '"') {
                    $field .= $line[$i + 1];
                    $i++;
                    continue;
                }

                $inQuotes = !$inQuotes;
                continue;
            }

            if ($char === ',' && !$inQuotes) {
                $fields[] = $field;
                $field = '';
                continue;
            }

            $field .= $char;
        }

        if ($inQuotes) {
            return null;
        }

        $fields[] = $field;

        return $fields;
    }

    private function bulkCsvRawFieldsAreQuoted(array $rawFields): bool {
        foreach ($rawFields as $rawField) {
            $field = trim((string)$rawField);
            if ($field === '') {
                continue;
            }

            if (!str_starts_with($field, '"') || !str_ends_with($field, '"')) {
                return false;
            }
        }

        return true;
    }

    private function bulkLineDedupeKeys(array $lines): array {
        $keys = [];

        foreach ($lines as $line) {
            if (is_array($line)) {
                $keys[$this->bulkLineDedupeKey($line)] = true;
            }
        }

        return $keys;
    }

    private function bulkLineDedupeKey(array $line): string {
        return implode("\t", [
            (string)($line['expense_date'] ?? ''),
            trim((string)($line['description'] ?? '')),
            number_format(round((float)($line['amount'] ?? 0), 2), 2, '.', ''),
        ]);
    }

    private function bulkImportMessage(int $importedCount, int $duplicateCount): string {
        $message = sprintf(
            '%d expense line%s imported.',
            $importedCount,
            $importedCount === 1 ? '' : 's'
        );

        if ($duplicateCount > 0) {
            $message = sprintf(
                '%d expense line%s imported; %d duplicate line%s skipped.',
                $importedCount,
                $importedCount === 1 ? '' : 's',
                $duplicateCount,
                $duplicateCount === 1 ? '' : 's'
            );
        }

        return $message;
    }

    private function bulkLineIsIgnorable(array $cells): bool {
        $joined = strtolower(trim(implode(' ', $cells)));
        $normalised = preg_replace('/\s+/', ' ', $joined) ?: $joined;

        if ($normalised === '' || preg_match('/^-+(\s+-+)*$/', $normalised) === 1) {
            return true;
        }

        if (isset($cells[0]) && preg_match('/^[ABCD]$/i', trim((string)$cells[0])) === 1) {
            return true;
        }

        foreach ([
            'date description amount claimed',
            'date description info amount claimed',
            'claimant',
            'year',
            'total amount claimed',
            'amount claimed during month',
            'signature',
            'office use',
            'balance outstanding',
            'payments made',
            'amount paid',
            'date paid',
            'fa proc',
            'fa ref',
        ] as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function bulkLineLooksLikeData(string $line): bool {
        $decodedLine = html_entity_decode($line, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return preg_match('/\d{1,4}[\/\-]\d{1,2}[\/\-]\d{1,4}/', $line) === 1
            || preg_match('/\p{Sc}/u', $decodedLine) === 1;
    }

    private function parseBulkLineDate(string $value): ?\DateTimeImmutable {
        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y', '!j/n/Y', '!d-m-Y', '!j-n-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date instanceof \DateTimeImmutable && (!is_array($errors) || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    private function parseBulkLineAmount(string $value): float {
        return (new \eel_accounts\Service\MoneyFormatService())->parseAmount($value) ?? 0.0;
    }

    private function normaliseDisplayDateFormat(string $format): string {
        return in_array($format, ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'd-m-y'], true)
            ? $format
            : 'd/m/Y';
    }

    private function normaliseLineAssetPayload(array $line, array $payload, int $accountingPeriodId, int $companyId): array {
        $assetService = new \eel_accounts\Service\AssetService();
        $normalised = $assetService->normaliseAssetValues($companyId, [
            'description' => trim((string)($line['description'] ?? '')),
            'category' => trim((string)($payload['asset_category'] ?? $payload['category'] ?? $line['asset_category'] ?? 'tools_equipment')),
            'purchase_date' => (string)($line['expense_date'] ?? ''),
            'cost' => (float)($line['amount'] ?? 0),
            'useful_life_years' => (int)($payload['asset_useful_life_years'] ?? $payload['useful_life_years'] ?? $line['asset_useful_life_years'] ?? 3),
            'depreciation_method' => trim((string)($payload['asset_depreciation_method'] ?? $payload['depreciation_method'] ?? $line['asset_depreciation_method'] ?? 'straight_line')),
            'residual_value' => (float)($payload['asset_residual_value'] ?? $payload['residual_value'] ?? $line['asset_residual_value'] ?? 0),
            'accounting_period_id' => $accountingPeriodId,
        ], [
            'description' => (string)($line['description'] ?? ''),
            'purchase_date' => (string)($line['expense_date'] ?? ''),
            'cost' => (float)($line['amount'] ?? 0),
            'accounting_period_id' => $accountingPeriodId,
        ]);

        return $normalised;
    }

    private function normaliseAssetValuesForPosting(\eel_accounts\Service\AssetService $assetService, int $companyId, int $accountingPeriodId, array $line): array {
        return $assetService->normaliseAssetValues($companyId, [
            'description' => trim((string)($line['description'] ?? '')),
            'category' => (string)($line['asset_category'] ?? 'tools_equipment'),
            'purchase_date' => (string)($line['expense_date'] ?? ''),
            'cost' => (float)($line['amount'] ?? 0),
            'useful_life_years' => (int)($line['asset_useful_life_years'] ?? 3),
            'depreciation_method' => (string)($line['asset_depreciation_method'] ?? 'straight_line'),
            'residual_value' => (float)($line['asset_residual_value'] ?? 0),
            'accounting_period_id' => $accountingPeriodId,
        ], [
            'description' => (string)($line['description'] ?? ''),
            'purchase_date' => (string)($line['expense_date'] ?? ''),
            'cost' => (float)($line['amount'] ?? 0),
            'accounting_period_id' => $accountingPeriodId,
        ]);
    }

    private function upsertLineAssetDetails(array $line, array $values): void {
        $lineId = (int)($line['id'] ?? 0);
        if ($lineId <= 0) {
            return;
        }

        $upsertSql = 'INSERT INTO expense_claim_line_assets (
                expense_claim_line_id,
                category,
                description,
                useful_life_years,
                depreciation_method,
                residual_value,
                created_at,
                updated_at
             ) VALUES (
                :expense_claim_line_id,
                :category,
                :description,
                :useful_life_years,
                :depreciation_method,
                :residual_value,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )';

        if (\InterfaceDB::driverName() === 'sqlite') {
            $upsertSql .= '
             ON CONFLICT(expense_claim_line_id) DO UPDATE SET
                category = excluded.category,
                description = excluded.description,
                useful_life_years = excluded.useful_life_years,
                depreciation_method = excluded.depreciation_method,
                residual_value = excluded.residual_value,
                updated_at = CURRENT_TIMESTAMP';
        } else {
            $upsertSql .= '
             ON DUPLICATE KEY UPDATE
                category = VALUES(category),
                description = VALUES(description),
                useful_life_years = VALUES(useful_life_years),
                depreciation_method = VALUES(depreciation_method),
                residual_value = VALUES(residual_value),
                updated_at = CURRENT_TIMESTAMP';
        }

        \InterfaceDB::prepare($upsertSql)->execute([
            'expense_claim_line_id' => $lineId,
            'category' => (string)($values['category'] ?? 'tools_equipment'),
            'description' => trim((string)($values['description'] ?? '')) !== '' ? trim((string)$values['description']) : null,
            'useful_life_years' => max(1, (int)($values['useful_life_years'] ?? 3)),
            'depreciation_method' => (string)($values['depreciation_method'] ?? 'straight_line'),
            'residual_value' => round((float)($values['residual_value'] ?? 0), 2),
        ]);
    }

    private function validateLineForPosting(int $companyId, int $accountingPeriodId, array $line): array {
        $errors = $this->validateLinePayload([
            'expense_date' => (string)($line['expense_date'] ?? ''),
            'description' => (string)($line['description'] ?? ''),
            'amount' => (float)($line['amount'] ?? 0),
        ]);

        if ((string)($line['line_type'] ?? 'expense') === 'asset') {
            $normalised = $this->normaliseAssetValuesForPosting(new \eel_accounts\Service\AssetService(), $companyId, $accountingPeriodId, $line);
            return array_merge($errors, $normalised['errors']);
        }

        if ((int)($line['nominal_account_id'] ?? 0) <= 0) {
            $errors[] = 'Every expense line needs a Charge To value before submitting.';
        }

        return $errors;
    }

    private function deriveMonthlyPeriod(int $year, int $month): array {
        $periodStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $periodEnd = $periodStart->modify('last day of this month');

        return [
            'period_start' => $periodStart->format('Y-m-d'),
            'period_end' => $periodEnd->format('Y-m-d'),
        ];
    }

    private function validateClaimPeriodSelection(int $companyId, int $claimYear, int $claimMonth): array {
        $period = $this->deriveMonthlyPeriod($claimYear, $claimMonth);
        $errors = [];

        if ($this->claimMonthIsAfterCurrentMonth($claimYear, $claimMonth)) {
            $errors[] = 'Claim month cannot be in the future.';
        }

        $incorporationDate = $this->fetchCompanyIncorporationDate($companyId);
        if ($incorporationDate !== '' && !$this->periodIsOnOrAfterIncorporation($claimYear, $claimMonth, $incorporationDate)) {
            $errors[] = 'Claim month cannot be earlier than the company incorporation date.';
        }

        $accountingPeriodId = $this->resolveAccountingPeriodIdForDate($companyId, $period['period_end']);
        if ($accountingPeriodId <= 0) {
            $errors[] = 'Claim month must fall inside an accounting period.';
        }

        return [
            'errors' => $errors,
            'period' => $period,
            'accounting_period_id' => $accountingPeriodId,
        ];
    }

    private function claimMonthIsAfterCurrentMonth(int $claimYear, int $claimMonth): bool {
        $claimMonthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $claimYear, $claimMonth));
        $currentMonthStart = new \DateTimeImmutable('first day of this month');

        return $claimMonthStart > $currentMonthStart;
    }

    private function fetchCompanyIncorporationDate(int $companyId): string {
        if ($companyId <= 0) {
            return '';
        }

        $value = (string)(\InterfaceDB::fetchColumn(
            'SELECT incorporation_date
             FROM companies
             WHERE id = :id
             LIMIT 1',
            ['id' => $companyId]
        ) ?: '');

        return $this->isValidDate($value) ? $value : '';
    }

    private function generateUniqueReferenceCode(int $companyId, int $claimYear, int $claimMonth): string {
        $prefix = 'EXP-' . substr((string)$claimYear, -2) . sprintf('%02d', $claimMonth);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $candidate = $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            if (!$this->referenceCodeExists($companyId, $candidate)) {
                return $candidate;
            }
        }

        return $prefix . '-' . strtoupper(substr(hash('sha256', uniqid((string)$companyId, true)), 0, 4));
    }

    private function referenceCodeExists(int $companyId, string $referenceCode): bool {
        return \InterfaceDB::countWhere('expense_claims', [
            'company_id' => $companyId,
            'claim_reference_code' => $referenceCode,
        ]) > 0;
    }

    private function clearNoLinesConfirmation(int $companyId, int $claimId): void {
        \InterfaceDB::prepare(
            'UPDATE expense_claims
             SET no_lines_confirmed_at = NULL,
                 no_lines_confirmed_by = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND company_id = :company_id
               AND no_lines_confirmed_at IS NOT NULL'
        )->execute([
            'id' => $claimId,
            'company_id' => $companyId,
        ]);
    }

    private function actorValue(string $changedBy): string {
        $changedBy = trim($changedBy);
        return $changedBy !== '' ? substr($changedBy, 0, 100) : 'web_app';
    }

    private function fetchExistingExpenseJournal(int $companyId, string $sourceRef): ?array {
        $stmt = \InterfaceDB::prepare(
            'SELECT id, company_id, source_ref
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => 'expense_register',
            'source_ref' => $sourceRef,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function linkedExpenseClaimLineAssetExists(int $lineId): bool
    {
        if ($lineId <= 0 || !\InterfaceDB::tableExists('asset_register')) {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT EXISTS(
                SELECT 1
                FROM asset_register
                WHERE linked_expense_claim_line_id = :line_id
            )',
            ['line_id' => $lineId]
        ) === 1;
    }

    private function payableNominalIdFromJournal(int $journalId): int
    {
        if ($journalId <= 0) {
            return 0;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT nominal_account_id
             FROM journal_lines
             WHERE journal_id = :journal_id
               AND credit > 0
             ORDER BY credit DESC, id ASC
             LIMIT 1',
            ['journal_id' => $journalId]
        );
    }

    private function rebuildPostedClaimJournal(int $companyId, array $claim, int $journalId, int $payableNominalId): void
    {
        $claimId = (int)($claim['id'] ?? 0);
        $lines = $this->fetchClaimLines($claimId);
        if ($lines === []) {
            throw new \RuntimeException('The claim has no lines to rebuild.');
        }

        $assetService = new \eel_accounts\Service\AssetService();
        \InterfaceDB::prepare('DELETE FROM journal_lines WHERE journal_id = :journal_id')
            ->execute(['journal_id' => $journalId]);

        foreach ($lines as $line) {
            if ((string)($line['line_type'] ?? 'expense') === 'asset') {
                $normalisedAsset = $this->normaliseAssetValuesForPosting($assetService, $companyId, (int)$claim['accounting_period_id'], $line);
                if ($normalisedAsset['errors'] !== []) {
                    throw new \RuntimeException(implode(' ', $normalisedAsset['errors']));
                }

                $values = (array)$normalisedAsset['values'];
                $this->insertJournalLine(
                    $journalId,
                    (int)$values['nominal_account_id'],
                    round((float)$line['amount'], 2),
                    0.0,
                    (string)$line['description']
                );

                if ((int)($line['generated_asset_id'] ?? 0) <= 0) {
                    $asset = $assetService->createAssetRecordFromValues($values, [
                        'linked_journal_id' => $journalId,
                        'linked_transaction_id' => null,
                        'linked_expense_claim_line_id' => (int)$line['id'],
                    ]);
                    if ($asset === null) {
                        throw new \RuntimeException('The asset could not be reloaded after save.');
                    }
                    \InterfaceDB::prepare(
                        'UPDATE expense_claim_line_assets
                         SET generated_asset_id = :generated_asset_id,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE expense_claim_line_id = :expense_claim_line_id'
                    )->execute([
                        'generated_asset_id' => (int)$asset['id'],
                        'expense_claim_line_id' => (int)$line['id'],
                    ]);
                }
                continue;
            }

            $nominalAccountId = (int)($line['nominal_account_id'] ?? 0);
            if ($nominalAccountId <= 0) {
                throw new \RuntimeException('Every expense line needs a Charge To value before rebuilding the posted journal.');
            }

            $this->insertJournalLine(
                $journalId,
                $nominalAccountId,
                round((float)$line['amount'], 2),
                0.0,
                (string)$line['description']
            );
        }

        $this->insertJournalLine(
            $journalId,
            $payableNominalId,
            0.0,
            $this->sumClaimLines($claimId),
            'Expense claim payable'
        );
    }

    private function insertJournalLine(int $journalId, int $nominalAccountId, float $debit, float $credit, string $description): void {
        \InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                debit,
                credit,
                line_description
             ) VALUES (
                :journal_id,
                :nominal_account_id,
                :debit,
                :credit,
                :line_description
             )'
        )->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalAccountId,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'line_description' => trim($description) !== '' ? $description : null,
        ]);
    }

    private function normaliseClaimPeriod(string $claimPeriod): ?array {
        $claimPeriod = trim($claimPeriod);
        if (!preg_match('/^\d{4}\-\d{2}$/', $claimPeriod)) {
            return null;
        }

        [$year, $month] = array_map('intval', explode('-', $claimPeriod, 2));
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return null;
        }

        return ['year' => $year, 'month' => $month];
    }

    private function normaliseClaimPeriodFromPayload(array $payload): ?array {
        $claimYear = isset($payload['claim_year']) ? (int)$payload['claim_year'] : 0;
        $claimMonth = isset($payload['claim_month']) ? (int)$payload['claim_month'] : 0;

        if ($claimYear > 0 || $claimMonth > 0) {
            if ($claimYear < 2000 || $claimYear > 2100 || $claimMonth < 1 || $claimMonth > 12) {
                return null;
            }

            return ['year' => $claimYear, 'month' => $claimMonth];
        }

        return $this->normaliseClaimPeriod((string)($payload['claim_period'] ?? ''));
    }

    private function periodIsOnOrAfterIncorporation(int $claimYear, int $claimMonth, string $incorporationDate): bool {
        if (!$this->isValidDate($incorporationDate)) {
            return true;
        }

        $incorporatedAt = new \DateTimeImmutable($incorporationDate);
        $incorporationYear = (int)$incorporatedAt->format('Y');
        $incorporationMonth = (int)$incorporatedAt->format('m');

        if ($claimYear > $incorporationYear) {
            return true;
        }

        if ($claimYear < $incorporationYear) {
            return false;
        }

        return $claimMonth >= $incorporationMonth;
    }

    private function normaliseStatusFilter(string $status): string {
        $status = strtolower(trim($status));
        return in_array($status, ['all', 'draft', 'posted'], true) ? $status : 'all';
    }

    private function normaliseSearchAmount(string $value): string {
        $value = trim(str_replace("\xC2\xA3", '', $value));

        if ($value === '' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = round((float)$value, 2);
        if ($amount <= 0.0) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function normaliseSearchStatuses(mixed $values): array {
        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        } elseif (!is_array($values)) {
            $values = [$values];
        }

        $statuses = [];
        foreach ($values as $value) {
            $status = strtolower(trim((string)$value));
            if (in_array($status, ['draft', 'posted'], true)) {
                $statuses[$status] = $status;
            }
        }

        return array_values($statuses);
    }

    private function normaliseSearchIds(mixed $values): array {
        if (is_string($values)) {
            $values = preg_split('/[,\s]+/', $values) ?: [];
        } elseif (!is_array($values)) {
            $values = [$values];
        }

        $ids = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function formatClaimSummary(array $claim): array {
        return [
            'id' => (int)$claim['id'],
            'claimant_id' => (int)$claim['claimant_id'],
            'claimant_name' => (string)$claim['claimant_name'],
            'claim_year' => (int)$claim['claim_year'],
            'claim_month' => (int)$claim['claim_month'],
            'claim_period' => sprintf('%04d-%02d', (int)$claim['claim_year'], (int)$claim['claim_month']),
            'period_start' => (string)$claim['period_start'],
            'period_end' => (string)$claim['period_end'],
            'claim_reference_code' => (string)$claim['claim_reference_code'],
            'A' => round((float)$claim['brought_forward_amount'], 2),
            'B' => round((float)$claim['claimed_amount'], 2),
            'C' => round((float)$claim['payments_amount'], 2),
            'D' => round((float)$claim['carried_forward_amount'], 2),
            'status' => (string)$claim['status'],
            'status_label' => $this->claimStatusLabel($claim),
            'line_count' => (int)($claim['line_count'] ?? 0),
            'payment_link_count' => (int)($claim['payment_link_count'] ?? 0),
            'last_updated' => (string)$claim['updated_at'],
        ];
    }

    private function claimStatusLabel(array $claim): string {
        $status = strtolower(trim((string)($claim['status'] ?? '')));
        if ($status === '') {
            $status = 'draft';
        }

        $lineCount = (int)($claim['line_count'] ?? count((array)($claim['lines'] ?? [])));
        $paymentLinkCount = (int)($claim['payment_link_count'] ?? count((array)($claim['payment_links'] ?? [])));
        $paymentsAmount = round(abs((float)($claim['payments_amount'] ?? 0)), 2);

        if ($status === 'draft' && $lineCount === 0 && ($paymentLinkCount > 0 || $paymentsAmount > 0.0)) {
            return 'Repayment Only';
        }

        return ucfirst($status);
    }

    private function isValidDate(string $value): bool {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}


