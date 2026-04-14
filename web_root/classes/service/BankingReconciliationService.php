<?php
declare(strict_types=1);

final class BankingReconciliationService
{
    public function fetchBankAccountPanels(int $companyId, int $taxYearId, int $bankNominalId): array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $accounts = $this->fetchBankAccounts($companyId);

        if ($accounts === []) {
            return [];
        }

        $uploadsByAccount = $this->fetchUploadsByAccount($companyId, $taxYearId, array_column($accounts, 'id'));
        $rowsByUpload = $this->fetchRowsByUpload($this->flattenUploadIds($uploadsByAccount));
        $ledgerDeltas = $bankNominalId > 0
            ? $this->fetchLedgerBankDeltas($companyId, $taxYearId, $bankNominalId)
            : [];

        $panels = [];

        foreach ($accounts as $account) {
            $accountId = (int)$account['id'];
            $uploadAnalyses = [];

            foreach ($uploadsByAccount[$accountId] ?? [] as $upload) {
                $uploadId = (int)$upload['id'];
                $uploadAnalyses[] = $this->analyseUpload($upload, $rowsByUpload[$uploadId] ?? []);
            }

            $uploadAnalyses = $this->applyContinuityChecks($uploadAnalyses);
            $ledgerSummary = $this->buildLedgerReconciliationSummary(
                $uploadAnalyses,
                $ledgerDeltas,
                $bankNominalId
            );

            $panels[] = [
                'account' => $account,
                'selected_tax_year_id' => $taxYearId,
                'statement_continuity_status' => $this->aggregateStatus($uploadAnalyses, 'continuity_status'),
                'running_balance_status' => $this->aggregateStatus($uploadAnalyses, 'running_balance_status'),
                'ledger_reconciliation_status' => (string)$ledgerSummary['status'],
                'uploads' => $uploadAnalyses,
                'ledger_summary' => $ledgerSummary,
            ];
        }

        return $panels;
    }

    private function fetchBankAccounts(int $companyId): array {
        return InterfaceDB::fetchAll( 'SELECT id,
                    company_id,
                    account_name,
                    account_type,
                    institution_name,
                    account_identifier,
                    is_active
             FROM company_accounts
             WHERE company_id = :company_id
               AND account_type = :account_type
             ORDER BY is_active DESC, account_name ASC, id ASC', [
            'company_id' => $companyId,
            'account_type' => CompanyAccountService::TYPE_BANK,
        ]);
    }

    private function fetchUploadsByAccount(int $companyId, int $taxYearId, array $accountIds): array {
        if ($accountIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($accountIds), '?'));
        $params = array_merge([$companyId, $taxYearId], array_map('intval', $accountIds));
        $stmt = InterfaceDB::prepare(
            'SELECT id,
                    company_id,
                    tax_year_id,
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
               AND tax_year_id = ?
               AND account_id IN (' . $placeholders . ')
             ORDER BY account_id ASC, COALESCE(date_range_start, statement_month, date_range_end) ASC, id ASC'
        );
        $stmt->execute($params);

        $grouped = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int)$row['account_id']][] = $row;
        }

        return $grouped;
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
            $stmt = InterfaceDB::prepare(
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

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $grouped[(int)$row['upload_id']][] = $row;
            }
        }

        return $grouped;
    }

    private function fetchLedgerBankDeltas(int $companyId, int $taxYearId, int $bankNominalId): array {
        $rows = InterfaceDB::fetchAll( 'SELECT j.journal_date,
                    COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0.00) AS day_delta,
                    SUM(CASE WHEN COALESCE(j.source_type, \'\') <> \'bank_csv\' THEN 1 ELSE 0 END) AS non_csv_lines
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND jl.nominal_account_id = :bank_nominal_id
               AND j.is_posted = 1
             GROUP BY j.journal_date
             ORDER BY j.journal_date ASC', [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
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
                $uploads[$index]['continuity_status'] = 'warning';
                $uploads[$index]['continuity_note'] = 'No previous statement exists to compare against.';
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

    private function buildLedgerReconciliationSummary(array $uploadAnalyses, array $ledgerDeltas, int $bankNominalId): array {
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
                    ? 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.'
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
                'scope_note' => 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.',
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
                'scope_note' => 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.',
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
            'scope_note' => 'Ledger reconciliation is still company-bank-wide because journal posting currently uses one generic Bank nominal.',
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


