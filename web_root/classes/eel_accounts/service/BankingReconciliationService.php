<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class BankingReconciliationService
{
    public function fetchBankAccountPanels(int $companyId, int $accountingPeriodId, int $bankNominalId): array {
        return $this->fetchAccountPanelsInternal($companyId, $accountingPeriodId, $bankNominalId, false);
    }

    public function fetchBankAccountPanelsWithAdjacentStatements(int $companyId, int $accountingPeriodId, int $bankNominalId): array {
        return $this->fetchAccountPanelsInternal($companyId, $accountingPeriodId, $bankNominalId, true);
    }

    public function fetchAccountPanels(int $companyId, int $accountingPeriodId, int $bankNominalId): array {
        return $this->fetchAccountPanelsInternal($companyId, $accountingPeriodId, $bankNominalId, false);
    }

    private function fetchAccountPanelsInternal(int $companyId, int $accountingPeriodId, int $bankNominalId, bool $returnAdjacentStatements): array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $accounts = $this->fetchCompanyAccounts($companyId);

        if ($accounts === []) {
            return [];
        }

        $bankAccounts = array_values(array_filter(
            $accounts,
            static fn(array $account): bool => (string)($account['account_type'] ?? '') === \eel_accounts\Service\CompanyAccountService::TYPE_BANK
        ));
        $accountingPeriod = (new \eel_accounts\Repository\AccountingPeriodRepository())->fetchAccountingPeriod($companyId, $accountingPeriodId);
        $uploadsByAccount = $this->fetchUploadsByAccount($companyId, $accountingPeriodId, array_column($bankAccounts, 'id'), $accountingPeriod);
        $rowsByUpload = $this->fetchRowsByUpload($this->flattenUploadIds($uploadsByAccount));
        $panels = [];

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $accountType = (string)($account['account_type'] ?? '');
            $accountNominalId = (int)($account['nominal_account_id'] ?? 0);
            $uploadAnalyses = [];
            $ledgerNominalId = $accountNominalId > 0 ? $accountNominalId : $bankNominalId;
            $ledgerDeltas = $accountType === \eel_accounts\Service\CompanyAccountService::TYPE_BANK && $ledgerNominalId > 0
                ? $this->fetchLedgerBankDeltas($companyId, $ledgerNominalId)
                : [];

            if ($accountType === \eel_accounts\Service\CompanyAccountService::TYPE_BANK) {
                foreach ($uploadsByAccount[$accountId] ?? [] as $upload) {
                    $uploadId = (int)$upload['id'];
                    $uploadAnalyses[] = $this->analyseUpload($upload, $rowsByUpload[$uploadId] ?? []);
                }
            }

            $uploadAnalyses = $this->applyContinuityChecks($uploadAnalyses);
            $visibleUploadAnalyses = $returnAdjacentStatements
                ? $uploadAnalyses
                : $this->filterUploadAnalysesForAccountingPeriod($uploadAnalyses, $accountingPeriodId);
            $ledgerSummary = $this->buildLedgerReconciliationSummary(
                $visibleUploadAnalyses,
                $ledgerDeltas,
                $ledgerNominalId,
                $accountNominalId > 0
            );
            $tradeSummary = $accountType === \eel_accounts\Service\CompanyAccountService::TYPE_TRADE
                ? $this->buildTradeLedgerSummary($companyId, $accountingPeriodId, $accountId)
                : null;

            $panels[] = [
                'account' => $account,
                'account_type' => $accountType,
                'accounting_period_id' => $accountingPeriodId,
                'statement_continuity_status' => $this->aggregateStatus($visibleUploadAnalyses, 'continuity_status'),
                'running_balance_status' => $this->aggregateStatus($visibleUploadAnalyses, 'running_balance_status'),
                'ledger_reconciliation_status' => $tradeSummary !== null ? (string)$tradeSummary['status'] : (string)$ledgerSummary['status'],
                'uploads' => $visibleUploadAnalyses,
                'ledger_summary' => $ledgerSummary,
                'trade_summary' => $tradeSummary,
            ];
        }

        return $panels;
    }

    private function fetchCompanyAccounts(int $companyId): array {
        return \InterfaceDB::fetchAll( 'SELECT ca.id,
                    ca.company_id,
                    ca.account_name,
                    ca.account_type,
                    ca.institution_name,
                    ca.account_identifier,
                    ca.nominal_account_id,
                    COALESCE(na.code, \'\') AS nominal_code,
                    COALESCE(na.name, \'\') AS nominal_name,
                    ca.is_active
             FROM company_accounts ca
             LEFT JOIN nominal_accounts na ON na.id = ca.nominal_account_id
             WHERE ca.company_id = :company_id
             ORDER BY ca.is_active DESC, ca.account_type ASC, ca.account_name ASC, ca.id ASC', [
            'company_id' => $companyId,
        ]);
    }

    private function buildTradeLedgerSummary(int $companyId, int $accountingPeriodId, int $accountId): array {
        $row = \InterfaceDB::fetchOne( 'SELECT COUNT(jl.id) AS line_count,
                    COALESCE(SUM(COALESCE(jl.debit, 0)), 0.00) AS debit_total,
                    COALESCE(SUM(COALESCE(jl.credit, 0)), 0.00) AS credit_total,
                    MIN(j.journal_date) AS first_journal_date,
                    MAX(j.journal_date) AS last_journal_date
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND jl.company_account_id = :company_account_id', [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'company_account_id' => $accountId,
        ]);

        $lineCount = (int)($row['line_count'] ?? 0);
        $debitTotal = round((float)($row['debit_total'] ?? 0), 2);
        $creditTotal = round((float)($row['credit_total'] ?? 0), 2);
        $netBalance = $this->roundMoney($debitTotal - $creditTotal);

        if ($lineCount <= 0) {
            return [
                'status' => 'not_available',
                'line_count' => 0,
                'debit_total' => 0.0,
                'credit_total' => 0.0,
                'net_balance' => 0.0,
                'balance_label' => 'None',
                'first_journal_date' => null,
                'last_journal_date' => null,
                'note' => 'No posted ledger lines are tagged to this trade account yet.',
                'scope_note' => 'Trade checks use journal_lines.company_account_id, so supplier balances become visible once postings are tagged to the trade account.',
            ];
        }

        $status = $netBalance > 0.0 ? 'warning' : 'pass';

        return [
            'status' => $status,
            'line_count' => $lineCount,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'net_balance' => abs($netBalance),
            'balance_label' => $netBalance < 0.0 ? 'Credit' : ($netBalance > 0.0 ? 'Debit' : 'Nil'),
            'first_journal_date' => $row['first_journal_date'] ?? null,
            'last_journal_date' => $row['last_journal_date'] ?? null,
            'note' => $status === 'pass'
                ? 'Posted ledger lines tagged to this trade account produce a nil or creditor balance.'
                : 'This trade account currently has a debit balance. Review whether it is a supplier prepayment, refund, or mis-posting.',
            'scope_note' => 'Supplier statement matching is not implemented yet; this is a ledger-tagged trade account check.',
        ];
    }

    private function fetchUploadsByAccount(int $companyId, int $accountingPeriodId, array $accountIds, ?array $continuityWindowAccountingPeriod = null): array {
        if ($accountIds === []) {
            return [];
        }

        if ($continuityWindowAccountingPeriod !== null) {
            return $this->fetchUploadsByAccountWithAdjacentStatements($companyId, $accountingPeriodId, $accountIds, $continuityWindowAccountingPeriod);
        }

        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));
        $params = array_merge([$companyId, $accountingPeriodId], array_map('intval', $accountIds));
        $stmt = \InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    account_id,
                    original_filename,
                    statement_month,
                    date_range_start,
                    date_range_end,
                    workflow_status,
                    rows_parsed,
                    rows_ready_to_import,
                    rows_committed
             FROM statement_uploads
             WHERE company_id = ?
               AND accounting_period_id = ?
               AND account_id IN (' . $placeholders . ')
             ORDER BY account_id ASC, COALESCE(date_range_start, statement_month, date_range_end) ASC, id ASC'
        );
        $stmt->execute($params);

        $grouped = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $grouped[(int)$row['account_id']][] = $row;
        }

        return $grouped;
    }

    private function fetchUploadsByAccountWithAdjacentStatements(int $companyId, int $accountingPeriodId, array $accountIds, array $accountingPeriod): array {
        $periodStart = trim((string)($accountingPeriod['period_start'] ?? ''));
        $periodEnd = trim((string)($accountingPeriod['period_end'] ?? ''));

        if ($periodStart === '' || $periodEnd === '') {
            return $this->fetchUploadsByAccount($companyId, $accountingPeriodId, $accountIds);
        }

        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));
        $params = array_merge(
            [$companyId, $accountingPeriodId, $periodStart, $periodEnd],
            array_map('intval', $accountIds)
        );
        $stmt = \InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    accounting_period_id,
                    account_id,
                    original_filename,
                    statement_month,
                    date_range_start,
                    date_range_end,
                    workflow_status,
                    rows_parsed,
                    rows_ready_to_import,
                    rows_committed
             FROM statement_uploads
             WHERE company_id = ?
               AND (
                    accounting_period_id = ?
                    OR COALESCE(date_range_end, date_range_start, statement_month) < ?
                    OR COALESCE(date_range_start, statement_month, date_range_end) > ?
               )
               AND account_id IN (' . $placeholders . ')
             ORDER BY account_id ASC, COALESCE(date_range_start, statement_month, date_range_end) ASC, id ASC'
        );
        $stmt->execute($params);

        $selected = [];
        $previous = [];
        $next = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $accountId = (int)$row['account_id'];

            if ((int)($row['accounting_period_id'] ?? 0) === $accountingPeriodId) {
                $selected[$accountId][] = $row;
                continue;
            }

            $endDate = $this->uploadEndDate($row);
            if ($endDate !== '' && $endDate < $periodStart) {
                if (!isset($previous[$accountId]) || $endDate > $this->uploadEndDate($previous[$accountId])) {
                    $previous[$accountId] = $row;
                }

                continue;
            }

            $startDate = $this->uploadStartDate($row);
            if ($startDate !== '' && $startDate > $periodEnd) {
                if (!isset($next[$accountId]) || $startDate < $this->uploadStartDate($next[$accountId])) {
                    $next[$accountId] = $row;
                }
            }
        }

        $grouped = [];
        foreach ($accountIds as $accountId) {
            $accountId = (int)$accountId;
            $uploads = [];

            if (isset($previous[$accountId])) {
                $uploads[] = $previous[$accountId];
            }

            foreach ($selected[$accountId] ?? [] as $upload) {
                $uploads[] = $upload;
            }

            if (isset($next[$accountId])) {
                $uploads[] = $next[$accountId];
            }

            usort($uploads, fn(array $left, array $right): int => strcmp($this->uploadStartDate($left), $this->uploadStartDate($right)) ?: ((int)$left['id'] <=> (int)$right['id']));
            $grouped[$accountId] = $uploads;
        }

        return $grouped;
    }

    private function uploadStartDate(array $upload): string {
        foreach (['date_range_start', 'statement_month', 'date_range_end'] as $field) {
            $value = trim((string)($upload[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function uploadEndDate(array $upload): string {
        foreach (['date_range_end', 'date_range_start', 'statement_month'] as $field) {
            $value = trim((string)($upload[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function flattenUploadIds(array $uploadsByAccount): array {
        $ids = [];

        foreach ($uploadsByAccount as $uploads) {
            foreach ($uploads as $upload) {
                $ids[] = (int)$upload['id'];
            }
        }

        return $ids;
    }

    private function fetchRowsByUpload(array $uploadIds): array {
        if ($uploadIds === []) {
            return [];
        }

        $grouped = [];

        foreach (array_chunk($uploadIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $stmt = \InterfaceDB::prepare(
                'SELECT upload_id,
                        id,
                        `row_number`,
                        chosen_txn_date,
                        normalised_amount,
                        normalised_balance,
                        source_balance,
                        validation_status,
                        validation_notes
                 FROM statement_import_rows
                 WHERE upload_id IN (' . $placeholders . ')
                 ORDER BY upload_id ASC, `row_number` ASC, id ASC'
            );
            $stmt->execute(array_map('intval', $chunk));

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $grouped[(int)$row['upload_id']][] = $row;
            }
        }

        return $grouped;
    }

    private function fetchLedgerBankDeltas(int $companyId, int $bankNominalId): array {
        $rows = \InterfaceDB::fetchAll( 'SELECT j.journal_date,
                    COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0.00) AS day_delta,
                    SUM(CASE WHEN COALESCE(j.source_type, \'\') <> \'bank_csv\' THEN 1 ELSE 0 END) AS non_csv_lines
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND jl.nominal_account_id = :bank_nominal_id
               AND j.is_posted = 1
             GROUP BY j.journal_date
             ORDER BY j.journal_date ASC', [
            'company_id' => $companyId,
            'bank_nominal_id' => $bankNominalId,
        ]);

        $ledgerByDate = [];
        $runningBalance = 0.0;
        $runningNonCsv = 0;

        foreach ($rows as $row) {
            $date = trim((string)$row['journal_date']);
            $runningBalance += (float)$row['day_delta'];
            $runningNonCsv += (int)$row['non_csv_lines'];
            $ledgerByDate[$date] = [
                'balance' => $this->roundMoney($runningBalance),
                'non_csv_lines' => $runningNonCsv,
            ];
        }

        return $ledgerByDate;
    }

    private function analyseUpload(array $upload, array $rows): array {
        $orderedRows = $this->orderRowsForBalanceChecks($rows);
        $usableRows = array_values(array_filter(
            $orderedRows,
            static fn (array $row): bool => $row['normalised_amount'] !== null && $row['normalised_balance'] !== null
        ));

        $rowsTested = 0;
        $rowsFailed = 0;
        $failedRows = [];

        for ($index = 1, $max = count($usableRows); $index < $max; $index++) {
            $priorBalance = (float)$usableRows[$index - 1]['normalised_balance'];
            $currentAmount = (float)$usableRows[$index]['normalised_amount'];
            $currentBalance = (float)$usableRows[$index]['normalised_balance'];
            $rowsTested++;

            if (!$this->moneyMatches($priorBalance + $currentAmount, $currentBalance)) {
                $rowsFailed++;
                $failedRows[] = [
                    'row_number' => (int)$usableRows[$index]['row_number'],
                    'note' => 'Prior balance plus current amount does not equal the current statement balance.',
                ];
            }
        }

        $runningStatus = 'not_available';
        $runningNote = 'No balance data available.';

        if ($rowsTested > 0) {
            if ($rowsFailed === 0) {
                $runningStatus = 'pass';
                $runningNote = sprintf('%d rows tested, 0 breaks', $rowsTested);
            } else {
                $runningStatus = 'fail';
                $runningNote = sprintf('%d rows tested, %d balance breaks', $rowsTested, $rowsFailed);
            }
        }

        $firstUsableRow = $usableRows[0] ?? null;
        $lastUsableRow = $usableRows[count($usableRows) - 1] ?? null;
        $openingBalance = null;
        $closingBalance = null;

        if ($firstUsableRow !== null) {
            $openingBalance = $this->roundMoney(
                (float)$firstUsableRow['normalised_balance'] - (float)$firstUsableRow['normalised_amount']
            );
        }

        if ($lastUsableRow !== null) {
            $closingBalance = $this->roundMoney((float)$lastUsableRow['normalised_balance']);
        }

        return [
            'upload' => $upload,
            'statement_month' => $this->statementLabel($upload),
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'closing_date' => $this->closingDate($upload, $lastUsableRow),
            'previous_statement_closing_balance' => null,
            'continuity_status' => $openingBalance !== null && $closingBalance !== null ? 'warning' : 'not_available',
            'continuity_note' => $openingBalance !== null && $closingBalance !== null
                ? 'No previous statement exists to compare against.'
                : 'Insufficient balance values.',
            'running_balance_status' => $runningStatus,
            'running_balance_note' => $runningNote,
            'balance_check_rows_tested' => $rowsTested,
            'balance_check_rows_failed' => $rowsFailed,
            'failed_rows' => $failedRows,
        ];
    }

    private function applyContinuityChecks(array $uploads): array {
        $previousClosingBalance = null;
        $hasPreviousStatement = false;

        foreach ($uploads as $index => $upload) {
            if ($upload['opening_balance'] === null || $upload['closing_balance'] === null) {
                $uploads[$index]['continuity_status'] = 'not_available';
                $uploads[$index]['continuity_note'] = 'Insufficient balance values.';
                continue;
            }

            if (!$hasPreviousStatement) {
                $isInitialZeroOpening = $this->moneyMatches(0.0, (float)$upload['opening_balance']);
                $uploads[$index]['continuity_status'] = $isInitialZeroOpening ? 'pass' : 'warning';
                $uploads[$index]['continuity_note'] = $isInitialZeroOpening
                    ? 'First statement opens at zero; no previous statement is required.'
                    : 'No previous statement exists to compare against.';
                $uploads[$index]['initial_opening_statement'] = $isInitialZeroOpening;
                $hasPreviousStatement = true;
                $previousClosingBalance = $upload['closing_balance'];
                continue;
            }

            $uploads[$index]['previous_statement_closing_balance'] = $previousClosingBalance;

            if ($this->moneyMatches((float)$previousClosingBalance, (float)$upload['opening_balance'])) {
                $uploads[$index]['continuity_status'] = 'pass';
                $uploads[$index]['continuity_note'] = 'Opening balance matches the previous statement closing balance.';
            } else {
                $uploads[$index]['continuity_status'] = 'fail';
                $uploads[$index]['continuity_note'] = 'Opening/closing mismatch.';
            }

            $previousClosingBalance = $upload['closing_balance'];
        }

        return $uploads;
    }

    private function filterUploadAnalysesForAccountingPeriod(array $uploads, int $accountingPeriodId): array {
        return array_values(array_filter(
            $uploads,
            static function (array $uploadCheck) use ($accountingPeriodId): bool {
                $upload = is_array($uploadCheck['upload'] ?? null) ? $uploadCheck['upload'] : [];

                return (int)($upload['accounting_period_id'] ?? 0) === $accountingPeriodId;
            }
        ));
    }

    private function buildLedgerReconciliationSummary(array $uploadAnalyses, array $ledgerDeltas, int $bankNominalId, bool $usesAccountNominal = true): array {
        $latestStatement = null;

        foreach ($uploadAnalyses as $upload) {
            if ($upload['closing_balance'] === null || trim((string)$upload['closing_date']) === '') {
                continue;
            }

            $latestStatement = $upload;
        }

        if ($latestStatement === null) {
            return [
                'status' => 'not_available',
                'statement_closing_balance' => null,
                'statement_closing_date' => null,
                'ledger_balance' => null,
                'difference' => null,
                'note' => 'No statement closing balance is available yet for this bank account.',
                'scope_note' => $bankNominalId > 0
                    ? ($usesAccountNominal ? 'Ledger reconciliation uses the cumulative posted balance for this company account nominal.' : 'Ledger reconciliation is using the cumulative posted balance for the default Bank nominal fallback.')
                    : 'Set the default Bank nominal to enable ledger reconciliation.',
            ];
        }

        if ($bankNominalId <= 0) {
            return [
                'status' => 'not_available',
                'statement_closing_balance' => $latestStatement['closing_balance'],
                'statement_closing_date' => $latestStatement['closing_date'],
                'ledger_balance' => null,
                'difference' => null,
                'note' => 'Set the default Bank nominal before ledger reconciliation can run.',
                'scope_note' => 'Assign a nominal to this bank account to enable account-level ledger reconciliation.',
            ];
        }

        $ledgerPoint = $this->findLedgerPointOnOrBefore($ledgerDeltas, (string)$latestStatement['closing_date']);

        if ($ledgerPoint === null) {
            return [
                'status' => 'warning',
                'statement_closing_balance' => $latestStatement['closing_balance'],
                'statement_closing_date' => $latestStatement['closing_date'],
                'ledger_balance' => 0.0,
                'difference' => $latestStatement['closing_balance'] !== null ? $this->roundMoney(0.0 - (float)$latestStatement['closing_balance']) : null,
                'note' => 'No posted ledger activity hits the configured Bank nominal by the statement closing date.',
                'scope_note' => $usesAccountNominal ? 'Ledger reconciliation uses the cumulative posted balance for this company account nominal.' : 'Ledger reconciliation is using the cumulative posted balance for the default Bank nominal fallback.',
            ];
        }

        $difference = $this->roundMoney((float)$ledgerPoint['balance'] - (float)$latestStatement['closing_balance']);
        $status = $this->moneyMatches((float)$difference, 0.0) ? 'pass' : 'fail';
        $note = $status === 'pass'
            ? 'Statement closing balance matches the ledger Bank control balance.'
            : 'Difference may come from missing statement imports, uncommitted transactions, manual journals, director loan entries, or expense repayments.';

        if ((int)$ledgerPoint['non_csv_lines'] > 0 && $status !== 'pass') {
            $note .= ' Non-CSV journals also hit the Bank nominal before this date.';
        }

        return [
            'status' => $status,
            'statement_closing_balance' => $latestStatement['closing_balance'],
            'statement_closing_date' => $latestStatement['closing_date'],
            'ledger_balance' => $ledgerPoint['balance'],
            'difference' => $difference,
            'note' => $note,
            'scope_note' => $usesAccountNominal ? 'Ledger reconciliation uses the cumulative posted balance for this company account nominal.' : 'Ledger reconciliation is using the cumulative posted balance for the default Bank nominal fallback.',
        ];
    }

    private function orderRowsForBalanceChecks(array $rows): array {
        if (count($rows) < 2) {
            return $rows;
        }

        $forward = $this->scoreRunningOrder($rows);
        $reverseRows = array_reverse($rows);
        $reverse = $this->scoreRunningOrder($reverseRows);

        if ($reverse['tested'] > $forward['tested']) {
            return $reverseRows;
        }

        if ($reverse['tested'] === $forward['tested'] && $reverse['failed'] < $forward['failed']) {
            return $reverseRows;
        }

        return $rows;
    }

    private function scoreRunningOrder(array $rows): array {
        $usableRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['normalised_amount'] !== null && $row['normalised_balance'] !== null
        ));
        $tested = 0;
        $failed = 0;

        for ($index = 1, $max = count($usableRows); $index < $max; $index++) {
            $tested++;
            $expectedBalance = (float)$usableRows[$index - 1]['normalised_balance'] + (float)$usableRows[$index]['normalised_amount'];

            if (!$this->moneyMatches($expectedBalance, (float)$usableRows[$index]['normalised_balance'])) {
                $failed++;
            }
        }

        return [
            'tested' => $tested,
            'failed' => $failed,
        ];
    }

    private function statementLabel(array $upload): string {
        foreach (['statement_month', 'date_range_start', 'date_range_end'] as $field) {
            $value = trim((string)($upload[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return trim((string)($upload['original_filename'] ?? ''));
    }

    private function closingDate(array $upload, ?array $lastUsableRow): ?string {
        if ($lastUsableRow !== null && trim((string)($lastUsableRow['chosen_txn_date'] ?? '')) !== '') {
            return trim((string)$lastUsableRow['chosen_txn_date']);
        }

        foreach (['date_range_end', 'date_range_start', 'statement_month'] as $field) {
            $value = trim((string)($upload[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function findLedgerPointOnOrBefore(array $ledgerDeltas, string $date): ?array {
        $match = null;

        foreach ($ledgerDeltas as $ledgerDate => $point) {
            if ($ledgerDate > $date) {
                break;
            }

            $match = $point;
        }

        return $match;
    }

    private function aggregateStatus(array $uploads, string $statusField): string {
        $priority = ['fail' => 4, 'pass' => 3, 'warning' => 2, 'not_available' => 1];
        $selected = 'not_available';

        foreach ($uploads as $upload) {
            $status = trim((string)($upload[$statusField] ?? 'not_available'));

            if (($priority[$status] ?? 0) > ($priority[$selected] ?? 0)) {
                $selected = $status;
            }
        }

        return $selected;
    }

    private function moneyMatches(float $left, float $right): bool {
        return abs($this->roundMoney($left) - $this->roundMoney($right)) < 0.0001;
    }

    private function roundMoney(float $value): float {
        return round($value, 2);
    }
}


