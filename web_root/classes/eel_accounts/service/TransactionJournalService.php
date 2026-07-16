<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class TransactionJournalService
{
    public function postCategorisedTransactions(
        int $companyId,
        int $accountingPeriodId,
        int $bankNominalId,
        ?string $monthKey = null,
        string $changedBy = 'system'
    ): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [
                'success' => false,
                'errors' => ['Select a company and accounting period before posting transactions.'],
            ];
        }

        $transactionIds = $this->fetchPostableTransactionIds($companyId, $accountingPeriodId, $monthKey);
        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'post categorised transactions');
        $summary = [
            'success' => true,
            'errors' => [],
            'created' => 0,
            'rebuilt' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'processed' => 0,
        ];

        foreach ($transactionIds as $transactionId) {
            $result = $this->syncJournalForTransaction($transactionId, $bankNominalId, $changedBy, true);

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $summary['errors'][] = (string)$error;
                }

                continue;
            }

            $summary['processed']++;

            if (!empty($result['created'])) {
                $summary['created']++;
            } elseif (!empty($result['rebuilt'])) {
                $summary['rebuilt']++;
            } elseif (!empty($result['removed'])) {
                $summary['removed']++;
            } else {
                $summary['unchanged']++;
            }
        }

        $summary['success'] = $summary['errors'] === [];

        return $summary;
    }

    public function syncJournalForTransaction(
        int $transactionId,
        int $bankNominalId,
        string $changedBy = 'system',
        bool $allowRebuild = false
    ): array {
        if ($transactionId <= 0) {
            return [
                'success' => false,
                'errors' => ['A valid transaction is required before a journal can be posted.'],
            ];
        }

        $transaction = $this->fetchTransactionForPosting($transactionId);

        if ($transaction === null) {
            return [
                'success' => false,
                'errors' => ['The selected transaction could not be found.'],
            ];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            (int)($transaction['company_id'] ?? 0),
            (int)($transaction['accounting_period_id'] ?? 0),
            'post journals in this period'
        );

        $sourceRef = $this->sourceRefForTransaction((int)$transaction['id']);
        $existingJournal = $this->fetchJournalBySourceRef((int)$transaction['company_id'], $sourceRef);
        $postingNominalErrors = $this->postingNominalErrors($transaction, $bankNominalId);
        if ($postingNominalErrors !== []) {
            return [
                'success' => false,
                'errors' => $postingNominalErrors,
            ];
        }

        $desiredJournal = $this->buildDesiredJournal($transaction, $bankNominalId);

        if ($desiredJournal === null) {
            if ($existingJournal === null) {
                return [
                    'success' => true,
                    'created' => false,
                    'rebuilt' => false,
                    'removed' => false,
                ];
            }

            if (!$allowRebuild) {
                return [
                    'success' => true,
                    'created' => false,
                    'rebuilt' => false,
                    'removed' => false,
                    'requires_confirmation' => true,
                ];
            }

            $this->deleteJournal((int)$existingJournal['id']);

            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => true,
            ];
        }

        if ($existingJournal !== null && $this->journalMatches($existingJournal, $desiredJournal)) {
            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => false,
            ];
        }

        if ($existingJournal !== null && !$allowRebuild) {
            return [
                'success' => true,
                'created' => false,
                'rebuilt' => false,
                'removed' => false,
                'requires_confirmation' => true,
            ];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($existingJournal !== null) {
                $this->deleteJournal((int)$existingJournal['id']);
            }

            $journalId = $this->insertJournal($desiredJournal, $changedBy);

            foreach ($desiredJournal['lines'] as $line) {
                $this->insertJournalLine($journalId, $line);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return [
                'success' => false,
                'errors' => ['The derived journal could not be written: ' . $exception->getMessage()],
            ];
        }

        return [
            'success' => true,
            'created' => $existingJournal === null,
            'rebuilt' => $existingJournal !== null,
            'removed' => false,
            'journal_id' => $journalId,
        ];
    }

    public function transactionHasDerivedJournal(int $transactionId): bool {
        if ($transactionId <= 0) {
            return false;
        }

        if ((new \eel_accounts\Service\TransactionInterAccountMarkerService())->isMatchedNoPostTransaction($transactionId)) {
            return false;
        }

        return \InterfaceDB::countWhere('journals', [
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction($transactionId),
        ]) > 0;
    }

    public function removeJournalForTransaction(int $transactionId): array
    {
        if ($transactionId <= 0) {
            return [
                'success' => false,
                'removed' => false,
                'errors' => ['A valid transaction is required before a journal can be removed.'],
            ];
        }

        $transaction = $this->fetchTransactionForPosting($transactionId);
        if ($transaction === null) {
            return [
                'success' => false,
                'removed' => false,
                'errors' => ['The selected transaction could not be found.'],
            ];
        }

        (new \eel_accounts\Service\YearEndLockService())->assertUnlocked(
            (int)($transaction['company_id'] ?? 0),
            (int)($transaction['accounting_period_id'] ?? 0),
            'remove journals in this period'
        );

        $sourceRef = $this->sourceRefForTransaction((int)$transaction['id']);
        $existingJournal = $this->fetchJournalBySourceRef((int)$transaction['company_id'], $sourceRef);
        if ($existingJournal === null) {
            return [
                'success' => true,
                'removed' => false,
                'errors' => [],
            ];
        }

        $this->deleteJournal((int)$existingJournal['id']);

        return [
            'success' => true,
            'removed' => true,
            'errors' => [],
        ];
    }

    public function fetchJournals(int $companyId, int $accountingPeriodId, int $limit = 200, array $filters = []): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $filters = $this->normaliseJournalFilters($filters);
        $hasFilters = $this->hasJournalFilters($filters);
        if ($hasFilters && $limit === 200) {
            $limit = 5000;
        }
        $limit = max(1, min($limit, $hasFilters ? 5000 : 500));
        [$where, $params] = $this->journalWhere($companyId, $accountingPeriodId, $filters);

        return $this->fetchJournalRows($where, $params, $limit, 0);
    }

    public function fetchJournalsPage(int $companyId, int $accountingPeriodId, array $filters = []): array
    {
        $page = max(1, (int)($filters['page'] ?? 1));
        $pageSize = max(1, min(200, (int)($filters['page_size'] ?? 30)));
        $exportAll = !empty($filters['export_all']);

        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->journalPagination([], $page, $pageSize, 0, $exportAll);
        }

        $filters = $this->normaliseJournalFilters($filters);
        [$where, $params] = $this->journalWhere($companyId, $accountingPeriodId, $filters);
        $total = (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM journals j WHERE ' . implode(' AND ', $where),
            $params
        );
        $pageCount = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        $page = $pageCount > 0 ? min($page, $pageCount) : 1;
        $offset = $exportAll ? 0 : ($page - 1) * $pageSize;
        $limit = $exportAll ? null : $pageSize;
        $journals = $this->fetchJournalRows($where, $params, $limit, $offset);

        return $this->journalPagination($journals, $page, $pageSize, $total, $exportAll);
    }

    private function journalWhere(int $companyId, int $accountingPeriodId, array $filters): array
    {
        $where = [
            'j.company_id = :company_id',
            'j.accounting_period_id = :accounting_period_id',
        ];
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];

        if ($filters['keyword'] !== '') {
            $where[] = "(j.description LIKE :keyword ESCAPE '!'
                OR COALESCE(j.source_ref, '') LIKE :keyword ESCAPE '!'
                OR EXISTS (
                    SELECT 1
                    FROM journal_lines keyword_jl
                    LEFT JOIN nominal_accounts keyword_na ON keyword_na.id = keyword_jl.nominal_account_id
                    LEFT JOIN company_accounts keyword_ca ON keyword_ca.id = keyword_jl.company_account_id
                    WHERE keyword_jl.journal_id = j.id
                      AND (
                          COALESCE(keyword_jl.line_description, '') LIKE :keyword ESCAPE '!'
                          OR COALESCE(keyword_na.code, '') LIKE :keyword ESCAPE '!'
                          OR COALESCE(keyword_na.name, '') LIKE :keyword ESCAPE '!'
                          OR COALESCE(keyword_ca.account_name, '') LIKE :keyword ESCAPE '!'
                      )
                ))";
            $params['keyword'] = '%' . $this->escapeLike((string)$filters['keyword']) . '%';
        }

        if ((int)$filters['source_account_id'] > 0) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM journal_lines source_jl
                WHERE source_jl.journal_id = j.id
                  AND source_jl.company_account_id = :source_account_id
            )';
            $params['source_account_id'] = (int)$filters['source_account_id'];
        }

        $lineWhere = ['filter_jl.journal_id = j.id'];
        $hasLineFilter = false;
        if ($filters['nominal_account_ids'] !== []) {
            $placeholders = [];
            foreach ($filters['nominal_account_ids'] as $index => $nominalAccountId) {
                $key = 'filter_nominal_account_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $nominalAccountId;
            }

            $lineWhere[] = 'filter_jl.nominal_account_id IN (' . implode(', ', $placeholders) . ')';
            $hasLineFilter = true;
        }

        if ($filters['amount'] !== '') {
            if ($filters['side'] === 'dr') {
                $lineWhere[] = 'filter_jl.debit = :filter_amount';
            } elseif ($filters['side'] === 'cr') {
                $lineWhere[] = 'filter_jl.credit = :filter_amount';
            } else {
                $lineWhere[] = '(filter_jl.debit = :filter_amount OR filter_jl.credit = :filter_amount)';
            }

            $params['filter_amount'] = (string)$filters['amount'];
            $hasLineFilter = true;
        } elseif ($filters['side'] === 'dr') {
            $lineWhere[] = 'filter_jl.debit > 0';
            $hasLineFilter = true;
        } elseif ($filters['side'] === 'cr') {
            $lineWhere[] = 'filter_jl.credit > 0';
            $hasLineFilter = true;
        }

        if ($hasLineFilter) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM journal_lines filter_jl
                WHERE ' . implode(' AND ', $lineWhere) . '
            )';
        }

        return [$where, $params];
    }

    private function fetchJournalRows(array $where, array $params, ?int $limit, int $offset): array
    {
        $limitSql = $limit === null
            ? ''
            : ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $stmt = \InterfaceDB::prepare(
            "SELECT j.id,
                    j.company_id,
                    j.accounting_period_id,
                    j.source_type,
                    COALESCE(j.source_ref, '') AS source_ref,
                    j.journal_date,
                    j.description,
                    j.is_posted
             FROM journals j
             WHERE " . implode(' AND ', $where) . "
             ORDER BY j.journal_date DESC, j.id DESC
             {$limitSql}"
        );
        $stmt->execute($params);

        $journals = $stmt->fetchAll();

        return $this->attachJournalLines($journals);
    }

    private function attachJournalLines(array $journals): array
    {
        $journalIds = array_values(array_filter(array_map(
            static fn(array $journal): int => (int)($journal['id'] ?? 0),
            $journals
        )));
        $linesByJournal = [];

        foreach (array_chunk($journalIds, 500) as $journalIdChunk) {
            $placeholders = [];
            $params = [];
            foreach ($journalIdChunk as $index => $journalId) {
                $key = 'journal_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $journalId;
            }

            $stmt = \InterfaceDB::prepare(
                'SELECT jl.id,
                        jl.journal_id,
                        jl.nominal_account_id,
                        jl.company_account_id,
                        jl.debit,
                        jl.credit,
                        COALESCE(jl.line_description, \'\') AS line_description,
                        COALESCE(na.code, \'\') AS nominal_code,
                        COALESCE(na.name, \'\') AS nominal_name,
                        COALESCE(ca.account_name, \'\') AS company_account_name
                 FROM journal_lines jl
                 LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
                 LEFT JOIN company_accounts ca ON ca.id = jl.company_account_id
                 WHERE jl.journal_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY jl.journal_id ASC, jl.id ASC'
            );
            $stmt->execute($params);

            foreach ($stmt->fetchAll() as $line) {
                $linesByJournal[(int)$line['journal_id']][] = $line;
            }
        }

        foreach ($journals as &$journal) {
            $lines = $linesByJournal[(int)$journal['id']] ?? [];
            $journal['lines'] = $lines;
            $journal['line_count'] = count($lines);
            $journal['total_debit'] = number_format(array_sum(array_map(
                static fn(array $line): float => (float)($line['debit'] ?? 0),
                $lines
            )), 2, '.', '');
        }
        unset($journal);

        return $journals;
    }

    private function journalPagination(array $items, int $page, int $pageSize, int $total, bool $exportAll): array
    {
        $pageCount = $total > 0 ? (int)ceil($total / $pageSize) : 0;
        $offset = $exportAll ? 0 : ($page - 1) * $pageSize;

        return [
            'items' => array_values($items),
            'page' => $page,
            'page_size' => $pageSize,
            'page_count' => $pageCount,
            'total' => $total,
            'offset' => $offset,
            'has_previous_page' => !$exportAll && $page > 1,
            'has_next_page' => !$exportAll && $pageCount > 0 && $page < $pageCount,
            'export_all' => $exportAll,
        ];
    }

    private function normaliseJournalFilters(array $filters): array
    {
        $side = $this->normaliseJournalSide((string)($filters['side'] ?? 'any'));

        return [
            'keyword' => trim((string)($filters['keyword'] ?? '')),
            'amount' => $this->normaliseJournalAmount((string)($filters['amount'] ?? '')),
            'side' => $side,
            'source_account_id' => max(0, (int)($filters['source_account_id'] ?? 0)),
            'nominal_account_ids' => $this->normalisePositiveIntList($filters['nominal_account_ids'] ?? []),
        ];
    }

    private function hasJournalFilters(array $filters): bool
    {
        return $filters['keyword'] !== ''
            || $filters['amount'] !== ''
            || $filters['side'] !== 'any'
            || (int)$filters['source_account_id'] > 0
            || $filters['nominal_account_ids'] !== [];
    }

    private function normaliseJournalAmount(string $value): string
    {
        $value = trim(str_replace("\xC2\xA3", '', $value));
        if ($value === '' || preg_match('/^-?\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return '';
        }

        $amount = abs(round((float)$value, 2));
        if ($amount < 0.005) {
            return '';
        }

        return number_format($amount, 2, '.', '');
    }

    private function normaliseJournalSide(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['dr', 'cr'], true) ? $value : 'any';
    }

    private function normalisePositiveIntList(mixed $values): array
    {
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

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['!', '%', '_'],
            ['!!', '!%', '!_'],
            $value
        );
    }

    private function fetchTransactionForPosting(int $transactionId): ?array {
        $internalTransferMarkerExpression = \InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')
            ? 'COALESCE(ca.internal_transfer_marker, \'\')'
            : '\'\'';
        $interAccountNoPostSelect = (new \eel_accounts\Service\TransactionInterAccountMarkerService())->hasSchema()
            ? "EXISTS (
                    SELECT 1
                    FROM transaction_inter_ac_marker tiam
                    WHERE tiam.matched_transaction_id = t.id
                )"
            : '0';
        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.account_id,
                    t.txn_date,
                    t.txn_type,
                    t.description,
                    t.amount,
                    t.nominal_account_id,
                    t.director_id,
                    t.transfer_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    ' . $internalTransferMarkerExpression . ' AS internal_transfer_marker,
                    COALESCE(ca.account_name, \'\') AS source_account_name,
                    COALESCE(ca.account_type, \'\') AS source_account_type,
                    COALESCE(ca.nominal_account_id, 0) AS source_account_nominal_id,
                    COALESCE(ta.account_name, \'\') AS transfer_account_name,
                    COALESCE(ta.account_type, \'\') AS transfer_account_type,
                    COALESCE(ta.nominal_account_id, 0) AS transfer_account_nominal_id,
                    ' . $interAccountNoPostSelect . ' AS inter_ac_no_post
             FROM transactions t
             LEFT JOIN company_accounts ca ON ca.id = t.account_id
             LEFT JOIN company_accounts ta ON ta.id = t.transfer_account_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $transactionId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function fetchPostableTransactionIds(int $companyId, int $accountingPeriodId, ?string $monthKey): array {
        $where = [
            't.company_id = :company_id',
            't.accounting_period_id = :accounting_period_id',
            '(
                (t.nominal_account_id IS NOT NULL AND t.category_status IN (\'auto\', \'manual\'))
                OR (t.transfer_account_id IS NOT NULL AND t.is_internal_transfer = 1 AND t.category_status = \'manual\')
            )',
        ];
        if ((new \eel_accounts\Service\TransactionInterAccountMarkerService())->hasSchema()) {
            $where[] = 'NOT EXISTS (
                SELECT 1
                FROM transaction_inter_ac_marker tiam
                WHERE tiam.matched_transaction_id = t.id
            )';
        }
        $params = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
        ];

        $monthKey = trim((string)$monthKey);

        if (preg_match('/^\d{4}-\d{2}-01$/', $monthKey) === 1) {
            $monthStart = new \DateTimeImmutable($monthKey);
            $monthEnd = $monthStart->modify('last day of this month');
            $where[] = 't.txn_date BETWEEN :month_start AND :month_end';
            $params['month_start'] = $monthStart->format('Y-m-d');
            $params['month_end'] = $monthEnd->format('Y-m-d');
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT t.id
             FROM transactions t
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.txn_date ASC, t.id ASC'
        );
        $stmt->execute($params);

        $transactionIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $stmt->fetchAll()
        );

        $interAccountMarkerService = new \eel_accounts\Service\TransactionInterAccountMarkerService();
        foreach ((new \eel_accounts\Service\TransactionSplitService())->fetchReadySplitTransactionIds($companyId, $accountingPeriodId, $monthKey !== '' ? $monthKey : null) as $splitTransactionId) {
            if ($interAccountMarkerService->isMatchedNoPostTransaction((int)$splitTransactionId)) {
                continue;
            }
            $transactionIds[] = (int)$splitTransactionId;
        }

        $unique = [];
        foreach ($transactionIds as $transactionId) {
            if ($transactionId > 0) {
                $unique[$transactionId] = $transactionId;
            }
        }

        return array_values($unique);
    }

    private function buildDesiredJournal(array $transaction, int $bankNominalId): ?array {
        if ((int)($transaction['inter_ac_no_post'] ?? 0) === 1) {
            return null;
        }

        if ($this->isTransferTransaction($transaction)) {
            return $this->buildTransferJournal($transaction, $bankNominalId);
        }

        $split = (new \eel_accounts\Service\TransactionSplitService())->fetchReadySplitForPosting((int)($transaction['id'] ?? 0));
        if ($split !== null) {
            return $this->buildSplitJournal($transaction, $bankNominalId, $split);
        }

        $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
        $categoryStatus = trim((string)($transaction['category_status'] ?? ''));

        if ($nominalAccountId <= 0 || !in_array($categoryStatus, ['auto', 'manual'], true)) {
            return null;
        }

        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);
        $sourceAccountId = (int)($transaction['account_id'] ?? 0);
        $sourceAccountType = (string)($transaction['source_account_type'] ?? '');
        $sourceNominalAccountId = $this->resolveCompanyAccountNominalId($transaction, 'source', $bankNominalId);

        if ($amount <= 0.0) {
            return null;
        }

        $description = trim((string)($transaction['description'] ?? ''));
        $journalDate = trim((string)($transaction['txn_date'] ?? ''));

        if ($sourceAccountType === \eel_accounts\Service\CompanyAccountService::TYPE_TRADE) {
            $lines = (float)$transaction['amount'] < 0
                ? [
                    [
                        'nominal_account_id' => $sourceNominalAccountId,
                        'company_account_id' => $sourceAccountId,
                        'debit' => number_format($amount, 2, '.', ''),
                        'credit' => '0.00',
                        'line_description' => $description,
                    ],
                    [
                        'nominal_account_id' => $nominalAccountId,
                        'debit' => '0.00',
                        'credit' => number_format($amount, 2, '.', ''),
                        'line_description' => $description,
                    ],
                ]
                : [
                    [
                        'nominal_account_id' => $nominalAccountId,
                        'debit' => number_format($amount, 2, '.', ''),
                        'credit' => '0.00',
                        'line_description' => $description,
                    ],
                    [
                        'nominal_account_id' => $sourceNominalAccountId,
                        'company_account_id' => $sourceAccountId,
                        'debit' => '0.00',
                        'credit' => number_format($amount, 2, '.', ''),
                        'line_description' => $description,
                    ],
                ];
        } else {
            $lines = (float)$transaction['amount'] < 0
                ? [
                [
                    'nominal_account_id' => $nominalAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $description,
                ],
                [
                    'nominal_account_id' => $sourceNominalAccountId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $description,
                ],
            ]
            : [
                [
                    'nominal_account_id' => $sourceNominalAccountId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $description,
                ],
                [
                    'nominal_account_id' => $nominalAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $description,
                ],
            ];
        }

        foreach ($lines as &$line) {
            if ((int)($line['nominal_account_id'] ?? 0) === $nominalAccountId) {
                $line['director_id'] = (int)($transaction['director_id'] ?? 0) ?: null;
            }
        }
        unset($line);

        return [
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction((int)$transaction['id']),
            'journal_date' => $journalDate,
            'description' => $description !== '' ? $description : 'Imported transaction',
            'is_posted' => 1,
            'lines' => $lines,
        ];
    }

    private function buildSplitJournal(array $transaction, int $bankNominalId, array $split): ?array
    {
        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);
        $sourceAccountId = (int)($transaction['account_id'] ?? 0);
        $sourceAccountType = (string)($transaction['source_account_type'] ?? '');
        $sourceNominalAccountId = $this->resolveCompanyAccountNominalId($transaction, 'source', $bankNominalId);

        if ($amount <= 0.0 || $sourceNominalAccountId <= 0) {
            return null;
        }

        $description = trim((string)($transaction['description'] ?? ''));
        $journalDate = trim((string)($transaction['txn_date'] ?? ''));
        $itemLines = [];

        foreach ((array)($split['lines'] ?? []) as $line) {
            if ((int)($line['is_deferred'] ?? 0) === 1) {
                continue;
            }

            $lineAmount = round((float)($line['amount'] ?? 0), 2);
            $nominalAccountId = (int)($line['nominal_account_id'] ?? 0);
            if ($lineAmount <= 0.0 || $nominalAccountId <= 0) {
                return null;
            }

            $lineDescription = trim((string)($line['description'] ?? ''));
            $itemLines[] = [
                'nominal_account_id' => $nominalAccountId,
                'director_id' => (int)($line['director_id'] ?? 0) ?: null,
                'debit' => '0.00',
                'credit' => '0.00',
                'line_description' => $lineDescription !== '' ? $lineDescription : $description,
                '_amount' => number_format($lineAmount, 2, '.', ''),
            ];
        }

        if ($itemLines === []) {
            return null;
        }

        $sourceLine = [
            'nominal_account_id' => $sourceNominalAccountId,
            'company_account_id' => $sourceAccountId,
            'debit' => '0.00',
            'credit' => '0.00',
            'line_description' => $description,
        ];

        $amountIsNegative = (float)($transaction['amount'] ?? 0) < 0;
        if ($sourceAccountType === \eel_accounts\Service\CompanyAccountService::TYPE_TRADE) {
            $itemLinesAreDebit = !$amountIsNegative;
        } else {
            $itemLinesAreDebit = $amountIsNegative;
        }

        foreach ($itemLines as &$line) {
            if ($itemLinesAreDebit) {
                $line['debit'] = (string)$line['_amount'];
            } else {
                $line['credit'] = (string)$line['_amount'];
            }
            unset($line['_amount']);
        }
        unset($line);

        if ($itemLinesAreDebit) {
            $sourceLine['credit'] = number_format($amount, 2, '.', '');
            $lines = array_merge($itemLines, [$sourceLine]);
        } else {
            $sourceLine['debit'] = number_format($amount, 2, '.', '');
            $lines = array_merge([$sourceLine], $itemLines);
        }

        return [
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction((int)$transaction['id']),
            'journal_date' => $journalDate,
            'description' => $description !== '' ? $description : 'Imported transaction',
            'is_posted' => 1,
            'lines' => $lines,
        ];
    }

    private function postingNominalErrors(array $transaction, int $fallbackBankNominalId): array
    {
        if ((int)($transaction['inter_ac_no_post'] ?? 0) === 1) {
            return [];
        }

        if ($this->isTransferTransaction($transaction)) {
            $sourceAccountId = (int)($transaction['account_id'] ?? 0);
            $transferAccountId = (int)($transaction['transfer_account_id'] ?? 0);
            $categoryStatus = trim((string)($transaction['category_status'] ?? ''));
            $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);

            if ($sourceAccountId <= 0 || $transferAccountId <= 0 || $categoryStatus !== 'manual' || $amount <= 0.0) {
                return [];
            }

            $errors = [];
            if ($this->resolveCompanyAccountNominalId($transaction, 'source', $fallbackBankNominalId) <= 0) {
                $errors[] = 'Assign a nominal to the source account before posting this transfer: ' . (string)($transaction['source_account_name'] ?? 'Unknown account') . '.';
            }

            if ($this->resolveCompanyAccountNominalId($transaction, 'transfer', $fallbackBankNominalId) <= 0) {
                $errors[] = 'Assign a nominal to the transfer account before posting this transfer: ' . (string)($transaction['transfer_account_name'] ?? 'Unknown account') . '.';
            }

            return $errors;
        }

        $split = (new \eel_accounts\Service\TransactionSplitService())->fetchReadySplitForPosting((int)($transaction['id'] ?? 0));
        if ($split !== null) {
            if ($this->resolveCompanyAccountNominalId($transaction, 'source', $fallbackBankNominalId) <= 0) {
                return ['Assign a nominal to the source account before posting this split transaction: ' . (string)($transaction['source_account_name'] ?? 'Unknown account') . '.'];
            }

            return [];
        }

        $nominalAccountId = (int)($transaction['nominal_account_id'] ?? 0);
        $categoryStatus = trim((string)($transaction['category_status'] ?? ''));
        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);

        if ($nominalAccountId <= 0 || !in_array($categoryStatus, ['auto', 'manual'], true) || $amount <= 0.0) {
            return [];
        }

        if ($this->resolveCompanyAccountNominalId($transaction, 'source', $fallbackBankNominalId) <= 0) {
            return ['Assign a nominal to the source account before posting this transaction: ' . (string)($transaction['source_account_name'] ?? 'Unknown account') . '.'];
        }

        return [];
    }

    private function fetchJournalBySourceRef(int $companyId, string $sourceRef): ?array {
        $stmt = \InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    source_type,
                    source_ref,
                    journal_date,
                    description,
                    is_posted
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => 'bank_csv',
            'source_ref' => $sourceRef,
        ]);
        $journal = $stmt->fetch();

        if (!is_array($journal)) {
            return null;
        }

        $journal['lines'] = $this->fetchJournalLines((int)$journal['id']);

        return $journal;
    }

    private function fetchJournalLines(int $journalId): array {
        $stmt = \InterfaceDB::prepare(
            'SELECT jl.id,
                    jl.nominal_account_id,
                    jl.director_id,
                    jl.company_account_id,
                    jl.debit,
                    jl.credit,
                    COALESCE(jl.line_description, \'\') AS line_description,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    COALESCE(ca.account_name, \'\') AS company_account_name
             FROM journal_lines jl
             LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN company_accounts ca ON ca.id = jl.company_account_id
             WHERE jl.journal_id = :journal_id
             ORDER BY jl.id ASC'
        );
        $stmt->execute(['journal_id' => $journalId]);

        return $stmt->fetchAll();
    }

    private function journalMatches(array $existingJournal, array $desiredJournal): bool {
        if (trim((string)$existingJournal['journal_date']) !== trim((string)$desiredJournal['journal_date'])) {
            return false;
        }

        if (trim((string)$existingJournal['description']) !== trim((string)$desiredJournal['description'])) {
            return false;
        }

        $existingLines = array_map([$this, 'normaliseLineForComparison'], $existingJournal['lines'] ?? []);
        $desiredLines = array_map([$this, 'normaliseLineForComparison'], $desiredJournal['lines'] ?? []);

        usort($existingLines, [$this, 'sortComparableLines']);
        usort($desiredLines, [$this, 'sortComparableLines']);

        return $existingLines === $desiredLines;
    }

    private function normaliseLineForComparison(array $line): array {
        return [
            'nominal_account_id' => (int)($line['nominal_account_id'] ?? 0),
            'director_id' => (int)($line['director_id'] ?? 0),
            'company_account_id' => (int)($line['company_account_id'] ?? 0),
            'debit' => number_format((float)($line['debit'] ?? 0), 2, '.', ''),
            'credit' => number_format((float)($line['credit'] ?? 0), 2, '.', ''),
            'line_description' => trim((string)($line['line_description'] ?? '')),
        ];
    }

    private function sortComparableLines(array $left, array $right): int {
        return [$left['nominal_account_id'], $left['company_account_id'], $left['debit'], $left['credit'], $left['line_description']]
            <=> [$right['nominal_account_id'], $right['company_account_id'], $right['debit'], $right['credit'], $right['line_description']];
    }

    private function insertJournal(array $journal, string $changedBy): int {
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $stmt = \InterfaceDB::prepare(
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
                :is_posted,
                :created_at,
                :updated_at
            )'
        );
        $stmt->execute([
            'company_id' => $journal['company_id'],
            'accounting_period_id' => $journal['accounting_period_id'],
            'source_type' => $journal['source_type'],
            'source_ref' => $journal['source_ref'],
            'journal_date' => $journal['journal_date'],
            'description' => $journal['description'],
            'is_posted' => $journal['is_posted'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $reloadedId = $this->findJournalId(
            (int)$journal['company_id'],
            (string)$journal['source_type'],
            (string)$journal['source_ref']
        );

        if ($reloadedId === null) {
            throw new \RuntimeException('The derived journal could not be reloaded after insert.');
        }

        return $reloadedId;
    }

    private function insertJournalLine(int $journalId, array $line): void {
        $stmt = \InterfaceDB::prepare(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                director_id,
                company_account_id,
                debit,
                credit,
                line_description
            ) VALUES (
                :journal_id,
                :nominal_account_id,
                :director_id,
                :company_account_id,
                :debit,
                :credit,
                :line_description
            )'
        );
        $stmt->execute([
            'journal_id' => $journalId,
            'nominal_account_id' => (int)$line['nominal_account_id'],
            'director_id' => (int)($line['director_id'] ?? 0) > 0 ? (int)$line['director_id'] : null,
            'company_account_id' => isset($line['company_account_id']) && (int)$line['company_account_id'] > 0
                ? (int)$line['company_account_id']
                : null,
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'line_description' => trim((string)($line['line_description'] ?? '')) !== ''
                ? trim((string)$line['line_description'])
                : null,
        ]);
    }

    private function buildTransferJournal(array $transaction, int $bankNominalId): ?array {
        $sourceAccountId = (int)($transaction['account_id'] ?? 0);
        $transferAccountId = (int)($transaction['transfer_account_id'] ?? 0);
        $categoryStatus = trim((string)($transaction['category_status'] ?? ''));
        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);

        if ($sourceAccountId <= 0 || $transferAccountId <= 0 || $categoryStatus !== 'manual' || $amount <= 0.0) {
            return null;
        }

        $description = trim((string)($transaction['description'] ?? ''));
        $journalDate = trim((string)($transaction['txn_date'] ?? ''));
        $sourceLineDescription = trim((string)($transaction['source_account_name'] ?? '')) !== ''
            ? trim((string)$transaction['source_account_name'])
            : $description;
        $transferLineDescription = trim((string)($transaction['transfer_account_name'] ?? '')) !== ''
            ? trim((string)$transaction['transfer_account_name'])
            : $description;
        $sourceNominalAccountId = $this->resolveCompanyAccountNominalId($transaction, 'source', $bankNominalId);
        $transferNominalAccountId = $this->resolveCompanyAccountNominalId($transaction, 'transfer', $bankNominalId);
        $sourceAccountType = (string)($transaction['source_account_type'] ?? '');
        $transferAccountType = (string)($transaction['transfer_account_type'] ?? '');

        if ($sourceAccountType === \eel_accounts\Service\CompanyAccountService::TYPE_TRADE && $transferAccountType === \eel_accounts\Service\CompanyAccountService::TYPE_BANK) {
            $lines = (float)$transaction['amount'] < 0
                ? [
                [
                    'nominal_account_id' => $sourceNominalAccountId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $sourceLineDescription,
                ],
                [
                    'nominal_account_id' => $transferNominalAccountId,
                    'company_account_id' => $transferAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $transferLineDescription,
                ],
            ]
            : [
                [
                    'nominal_account_id' => $transferNominalAccountId,
                    'company_account_id' => $transferAccountId,
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => $transferLineDescription,
                ],
                [
                    'nominal_account_id' => $sourceNominalAccountId,
                    'company_account_id' => $sourceAccountId,
                    'debit' => '0.00',
                    'credit' => number_format($amount, 2, '.', ''),
                    'line_description' => $sourceLineDescription,
                ],
            ];
        } else {
            $lines = (float)$transaction['amount'] < 0
                ? [
                    [
                        'nominal_account_id' => $transferNominalAccountId,
                        'company_account_id' => $transferAccountId,
                        'debit' => number_format($amount, 2, '.', ''),
                        'credit' => '0.00',
                        'line_description' => $transferLineDescription,
                    ],
                    [
                        'nominal_account_id' => $sourceNominalAccountId,
                        'company_account_id' => $sourceAccountId,
                        'debit' => '0.00',
                        'credit' => number_format($amount, 2, '.', ''),
                        'line_description' => $sourceLineDescription,
                    ],
                ]
                : [
                    [
                        'nominal_account_id' => $sourceNominalAccountId,
                        'company_account_id' => $sourceAccountId,
                        'debit' => number_format($amount, 2, '.', ''),
                        'credit' => '0.00',
                        'line_description' => $sourceLineDescription,
                    ],
                    [
                        'nominal_account_id' => $transferNominalAccountId,
                        'company_account_id' => $transferAccountId,
                        'debit' => '0.00',
                        'credit' => number_format($amount, 2, '.', ''),
                        'line_description' => $transferLineDescription,
                    ],
                ];
        }

        return [
            'company_id' => (int)$transaction['company_id'],
            'accounting_period_id' => (int)$transaction['accounting_period_id'],
            'source_type' => 'bank_csv',
            'source_ref' => $this->sourceRefForTransaction((int)$transaction['id']),
            'journal_date' => $journalDate,
            'description' => $description !== '' ? $description : 'Internal transfer',
            'is_posted' => 1,
            'lines' => $lines,
        ];
    }

    private function isTransferTransaction(array $transaction): bool {
        $txnType = trim((string)($transaction['txn_type'] ?? ''));
        $marker = trim((string)($transaction['internal_transfer_marker'] ?? ''));

        if ($txnType !== '' && $marker !== '' && strcasecmp($txnType, $marker) === 0) {
            return true;
        }

        return (int)($transaction['is_internal_transfer'] ?? 0) === 1
            || (int)($transaction['transfer_account_id'] ?? 0) > 0;
    }

    private function resolveCompanyAccountNominalId(array $transaction, string $prefix, int $fallbackBankNominalId): int
    {
        $nominalId = (int)($transaction[$prefix . '_account_nominal_id'] ?? 0);
        if ($nominalId > 0) {
            return $nominalId;
        }

        $accountType = (string)($transaction[$prefix . '_account_type'] ?? '');
        if ($accountType === \eel_accounts\Service\CompanyAccountService::TYPE_BANK && $fallbackBankNominalId > 0) {
            return $fallbackBankNominalId;
        }

        return 0;
    }

    private function findJournalId(int $companyId, string $sourceType, string $sourceRef): ?int {
        $stmt = \InterfaceDB::prepare(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int)$id : null;
    }

    private function deleteJournal(int $journalId): void {
        $stmt = \InterfaceDB::prepare(
            'DELETE FROM journals
             WHERE id = :id'
        );
        $stmt->execute(['id' => $journalId]);
    }

    private function sourceRefForTransaction(int $transactionId): string {
        return 'transaction:' . $transactionId;
    }
}
