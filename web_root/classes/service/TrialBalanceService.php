<?php
declare(strict_types=1);

final class TrialBalanceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?YearEndMetricsService $metricsService = null,
        private readonly ?YearEndLockService $lockService = null,
    ) {
    }

    public function fetchPageContext(int $companyId, int $taxYearId): ?array {
        $metrics = $this->metricsService ?? new YearEndMetricsService($this->pdo);
        $taxYear = $metrics->fetchTaxYear($companyId, $taxYearId);
        $company = $metrics->fetchCompanySummary($companyId);

        if ($taxYear === null || $company === null) {
            return null;
        }

        $settings = $metrics->fetchCompanySettings($companyId);
        $review = ($this->lockService ?? new YearEndLockService($this->pdo))->fetchReview($companyId, $taxYearId);

        return [
            'company' => $company,
            'tax_year' => $taxYear,
            'settings' => $settings,
            'review' => $review,
        ];
    }

    public function fetchTrialBalance(
        int $companyId,
        int $taxYearId,
        bool $includeZero = false,
        bool $includeUnposted = false,
        array $filters = []
    ): array {
        $context = $this->fetchPageContext($companyId, $taxYearId);
        if ($context === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $rows = $this->fetchNominalTrialBalanceRows($companyId, $taxYearId, $includeUnposted);
        $summary = $this->buildSummary($context, $rows);
        $filteredRows = $this->applyFilters($rows, $includeZero, $filters);
        $totals = $this->buildTotals($filteredRows);

        return [
            'available' => true,
            'company' => $context['company'],
            'tax_year' => $context['tax_year'],
            'include_zero' => $includeZero,
            'include_unposted' => $includeUnposted,
            'filters' => [
                'search' => trim((string)($filters['search'] ?? '')),
                'account_type' => trim((string)($filters['account_type'] ?? 'all')),
                'focus' => trim((string)($filters['focus'] ?? 'all')),
            ],
            'rows' => $filteredRows,
            'totals' => $totals,
            'summary' => $summary,
            'has_rows' => $rows !== [],
            'has_visible_rows' => $filteredRows !== [],
            'source_basis' => $includeUnposted ? 'posted_and_unposted_journals' : 'posted_journals_only',
        ];
    }

    public function fetchSummary(int $companyId, int $taxYearId, bool $includeUnposted = false): array {
        $context = $this->fetchPageContext($companyId, $taxYearId);
        if ($context === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $rows = $this->fetchNominalTrialBalanceRows($companyId, $taxYearId, $includeUnposted);

        return [
            'available' => true,
            'summary' => $this->buildSummary($context, $rows),
        ];
    }

    public function fetchNominalLedger(
        int $companyId,
        int $taxYearId,
        int $nominalAccountId,
        bool $includeUnposted = false
    ): array {
        $context = $this->fetchPageContext($companyId, $taxYearId);
        if ($context === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        if ($nominalAccountId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a nominal account before loading ledger detail.'],
            ];
        }

        $nominal = $this->fetchNominalAccount($nominalAccountId);
        if ($nominal === null) {
            return [
                'available' => false,
                'errors' => ['The selected nominal account could not be found.'],
            ];
        }

        $entries = $this->fetchNominalLedgerEntries($companyId, $taxYearId, $nominalAccountId, $includeUnposted);
        $runningBalance = 0.0;

        foreach ($entries as &$entry) {
            $runningBalance = round($runningBalance + (float)$entry['debit'] - (float)$entry['credit'], 2);
            $entry['running_balance'] = $runningBalance;
            $entry['link_url'] = $this->sourceLink((string)($entry['source_type'] ?? ''), (string)($entry['source_ref'] ?? ''), $companyId, $taxYearId, (string)($entry['journal_date'] ?? ''));
        }
        unset($entry);

        return [
            'available' => true,
            'company' => $context['company'],
            'tax_year' => $context['tax_year'],
            'nominal' => $nominal,
            'entries' => $entries,
        ];
    }

    public function fetchCsvExport(
        int $companyId,
        int $taxYearId,
        bool $includeZero = false,
        bool $includeUnposted = false,
        array $filters = []
    ): array {
        $result = $this->fetchTrialBalance($companyId, $taxYearId, $includeZero, $includeUnposted, $filters);
        if (empty($result['available'])) {
            return $result;
        }

        $context = $this->fetchPageContext($companyId, $taxYearId);
        $timestamp = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $summary = $result['summary'] ?? [];
        $rows = [];

        $rows[] = ['Company', (string)($context['company']['company_name'] ?? '')];
        $rows[] = ['Period', (string)($context['tax_year']['label'] ?? '')];
        $rows[] = ['Generated At', $timestamp];
        $rows[] = ['Balanced Status', !empty($summary['trial_balance_status']['is_balanced']) ? 'Balanced' : 'Not balanced'];
        $rows[] = [];
        $rows[] = ['Nominal Code', 'Nominal Name', 'Account Type', 'Subtype', 'Debit', 'Credit', 'Net', 'Closing Balance Nature', 'Flags'];

        foreach ((array)($result['rows'] ?? []) as $row) {
            $rows[] = [
                (string)($row['nominal_code'] ?? ''),
                (string)($row['nominal_name'] ?? ''),
                (string)($row['account_type'] ?? ''),
                (string)($row['subtype_name'] ?? ''),
                number_format((float)($row['display_debit'] ?? 0), 2, '.', ''),
                number_format((float)($row['display_credit'] ?? 0), 2, '.', ''),
                number_format((float)($row['net_movement'] ?? 0), 2, '.', ''),
                (string)($row['closing_balance_nature'] ?? ''),
                implode('; ', array_map(static fn(array $flag): string => (string)($flag['label'] ?? ''), (array)($row['flags'] ?? []))),
            ];
        }

        $rows[] = [];
        $rows[] = [
            'Totals',
            '',
            '',
            '',
            number_format((float)($result['totals']['total_debits'] ?? 0), 2, '.', ''),
            number_format((float)($result['totals']['total_credits'] ?? 0), 2, '.', ''),
            number_format((float)($result['totals']['difference'] ?? 0), 2, '.', ''),
            '',
            '',
        ];

        return [
            'available' => true,
            'filename' => $this->buildCsvFileName((string)($context['company']['company_name'] ?? 'company'), (string)($context['tax_year']['label'] ?? 'period')),
            'rows' => $rows,
        ];
    }

    private function fetchNominalTrialBalanceRows(int $companyId, int $taxYearId, bool $includeUnposted): array {
        $postingPredicate = $includeUnposted ? '' : 'AND COALESCE(j.is_posted, 0) = 1';
        $sql = '
            SELECT na.id,
                   COALESCE(na.code, \'\') AS nominal_code,
                   COALESCE(na.name, \'\') AS nominal_name,
                   COALESCE(na.account_type, \'\') AS account_type,
                   COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                   COALESCE(na.sort_order, 100) AS sort_order,
                   COALESCE(nas.code, \'\') AS subtype_code,
                   COALESCE(nas.name, \'\') AS subtype_name,
                   COALESCE(agg.total_debit, 0) AS total_debit,
                   COALESCE(agg.total_credit, 0) AS total_credit,
                   COALESCE(agg.journal_count, 0) AS journal_count,
                   COALESCE(agg.has_manual_journal, 0) AS has_manual_journal,
                   COALESCE(agg.has_activity, 0) AS has_activity
            FROM nominal_accounts na
            LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
            LEFT JOIN (
                SELECT jl.nominal_account_id,
                       COALESCE(SUM(COALESCE(jl.debit, 0)), 0) AS total_debit,
                       COALESCE(SUM(COALESCE(jl.credit, 0)), 0) AS total_credit,
                       COUNT(DISTINCT j.id) AS journal_count,
                       MAX(CASE WHEN COALESCE(j.source_type, \'\') = \'manual\' THEN 1 ELSE 0 END) AS has_manual_journal,
                       MAX(CASE WHEN COALESCE(j.source_type, \'\') <> \'\' THEN 1 ELSE 0 END) AS has_activity
                FROM journals j
                INNER JOIN journal_lines jl ON jl.journal_id = j.id
                WHERE j.company_id = :company_id
                  AND j.tax_year_id = :tax_year_id
                  ' . $postingPredicate . '
                GROUP BY jl.nominal_account_id
            ) agg ON agg.nominal_account_id = na.id
            WHERE COALESCE(na.is_active, 0) = 1
            GROUP BY na.id, na.code, na.name, na.account_type, na.tax_treatment, na.sort_order, nas.code, nas.name, agg.total_debit, agg.total_credit, agg.journal_count, agg.has_manual_journal, agg.has_activity
            ORDER BY COALESCE(na.sort_order, 100) ASC, COALESCE(na.code, \'\') ASC, COALESCE(na.name, \'\') ASC, na.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $debit = round((float)($row['total_debit'] ?? 0), 2);
            $credit = round((float)($row['total_credit'] ?? 0), 2);
            $net = round($debit - $credit, 2);
            $flags = $this->rowFlags($row);
            $rows[] = [
                'nominal_account_id' => (int)($row['id'] ?? 0),
                'nominal_code' => (string)($row['nominal_code'] ?? ''),
                'nominal_name' => (string)($row['nominal_name'] ?? ''),
                'account_type' => (string)($row['account_type'] ?? ''),
                'subtype_code' => (string)($row['subtype_code'] ?? ''),
                'subtype_name' => (string)($row['subtype_name'] ?? ''),
                'tax_treatment' => (string)($row['tax_treatment'] ?? 'allowable'),
                'sort_order' => (int)($row['sort_order'] ?? 100),
                'total_debit' => $debit,
                'total_credit' => $credit,
                'net_movement' => $net,
                'display_debit' => $net >= 0 ? $net : 0.0,
                'display_credit' => $net < 0 ? abs($net) : 0.0,
                'closing_balance_nature' => $this->closingBalanceNature($net),
                'journal_count' => (int)($row['journal_count'] ?? 0),
                'has_activity' => (int)($row['has_activity'] ?? 0) === 1,
                'has_manual_journal' => (int)($row['has_manual_journal'] ?? 0) === 1,
                'flags' => $flags,
                'is_exception' => $this->isExceptionRow($flags, $row),
            ];
        }

        return $rows;
    }

    private function buildSummary(array $context, array $rows): array {
        $settings = (array)($context['settings'] ?? []);
        $metrics = $this->metricsService ?? new YearEndMetricsService($this->pdo);
        $companyId = (int)($context['company']['id'] ?? 0);
        $taxYearId = (int)($context['tax_year']['id'] ?? 0);
        $periodStart = (string)($context['tax_year']['period_start'] ?? '');
        $periodEnd = (string)($context['tax_year']['period_end'] ?? '');

        $totalDebits = 0.0;
        $totalCredits = 0.0;
        $bankBalance = 0.0;
        $directorLoanBalance = 0.0;
        $vatBalance = 0.0;
        $uncategorisedExposure = 0.0;
        $corporationTaxBalance = 0.0;

        $directorLoanNominalId = (int)($settings['director_loan_nominal_id'] ?? 0);
        $vatNominalId = (int)($settings['vat_nominal_id'] ?? 0);
        $uncategorisedNominalId = (int)($settings['uncategorised_nominal_id'] ?? 0);
        $defaultBankNominalId = (int)($settings['default_bank_nominal_id'] ?? 0);

        foreach ($rows as $row) {
            $totalDebits += (float)($row['display_debit'] ?? 0);
            $totalCredits += (float)($row['display_credit'] ?? 0);

            $nominalId = (int)($row['nominal_account_id'] ?? 0);
            $subtypeCode = (string)($row['subtype_code'] ?? '');
            $name = strtolower((string)($row['nominal_name'] ?? ''));
            $code = (string)($row['nominal_code'] ?? '');
            $net = round((float)($row['net_movement'] ?? 0), 2);

            if ($subtypeCode === 'bank' || $nominalId === $defaultBankNominalId) {
                $bankBalance += $net;
            }
            if ($nominalId === $directorLoanNominalId) {
                $directorLoanBalance = $net;
            }
            if ($nominalId === $vatNominalId) {
                $vatBalance = $net;
            }
            if ($nominalId === $uncategorisedNominalId || $code === '9990' || str_contains($name, 'suspense')) {
                $uncategorisedExposure += $net;
            }
            if ($subtypeCode === 'corp_tax' || str_contains($name, 'corporation tax')) {
                $corporationTaxBalance += $net;
            }
        }

        $difference = round($totalDebits - $totalCredits, 2);
        $isBalanced = abs($difference) < 0.005;
        $profitAndLoss = $metrics->profitAndLossSummary($companyId, $taxYearId, $periodStart, $periodEnd);
        $balanceSheet = $metrics->fetchBalanceSheetMetricValues($companyId, $taxYearId, $periodStart, $periodEnd);
        $taxComputation = (new YearEndTaxReadinessService($this->pdo, $metrics))->fetchSummary($companyId, $taxYearId);
        $reviewNotes = trim((string)($context['review']['review_notes'] ?? ''));

        return [
            'trial_balance_status' => [
                'label' => $isBalanced ? 'Balanced' : 'Not balanced',
                'is_balanced' => $isBalanced,
                'difference' => $difference,
            ],
            'profit_before_tax' => round((float)($profitAndLoss['profit_before_tax'] ?? 0), 2),
            'net_assets' => round((float)($balanceSheet['net_assets_liabilities'] ?? 0), 2),
            'bank_balance_total' => round($bankBalance, 2),
            'director_loan_balance' => round($directorLoanBalance, 2),
            'vat_control_balance' => round($vatBalance, 2),
            'uncategorised_exposure' => round($uncategorisedExposure, 2),
            'corporation_tax_balance' => round($corporationTaxBalance, 2),
            'ct_adjustment_workings' => [
                'status' => !empty($taxComputation['available']) ? 'Calculated' : 'Warnings present',
                'note' => !empty($taxComputation['available'])
                    ? 'Taxable profit now reflects ledger profit, disallowables, depreciation add-backs, capital allowances, and loss utilisation.'
                    : 'Tax computation is not available for this period yet.',
                'review_notes_present' => $reviewNotes !== '',
            ],
            'tax_computation' => $taxComputation,
        ];
    }

    private function applyFilters(array $rows, bool $includeZero, array $filters): array {
        $search = strtolower(trim((string)($filters['search'] ?? '')));
        $accountType = trim((string)($filters['account_type'] ?? 'all'));
        $focus = trim((string)($filters['focus'] ?? 'all'));

        return array_values(array_filter($rows, static function (array $row) use ($includeZero, $search, $accountType, $focus): bool {
            $hasBalance = abs((float)($row['net_movement'] ?? 0)) >= 0.005
                || abs((float)($row['total_debit'] ?? 0)) >= 0.005
                || abs((float)($row['total_credit'] ?? 0)) >= 0.005;

            if (!$includeZero && !$hasBalance) {
                return false;
            }

            if ($search !== '') {
                $haystack = strtolower(
                    trim((string)($row['nominal_code'] ?? ''))
                    . ' '
                    . trim((string)($row['nominal_name'] ?? ''))
                    . ' '
                    . trim((string)($row['subtype_name'] ?? ''))
                );
                if (!str_contains($haystack, $search)) {
                    return false;
                }
            }

            if ($accountType !== '' && $accountType !== 'all' && (string)($row['account_type'] ?? '') !== $accountType) {
                return false;
            }

            if ($focus === 'income_statement' && !in_array((string)($row['account_type'] ?? ''), ['income', 'cost_of_sales', 'expense'], true)) {
                return false;
            }

            if ($focus === 'balance_sheet' && !in_array((string)($row['account_type'] ?? ''), ['asset', 'liability', 'equity'], true)) {
                return false;
            }

            if ($focus === 'exception' && empty($row['is_exception'])) {
                return false;
            }

            return true;
        }));
    }

    private function buildTotals(array $rows): array {
        $debits = 0.0;
        $credits = 0.0;

        foreach ($rows as $row) {
            $debits += (float)($row['display_debit'] ?? 0);
            $credits += (float)($row['display_credit'] ?? 0);
        }

        return [
            'total_debits' => round($debits, 2),
            'total_credits' => round($credits, 2),
            'difference' => round($debits - $credits, 2),
        ];
    }

    private function rowFlags(array $row): array {
        $flags = [];
        $name = strtolower((string)($row['nominal_name'] ?? ''));
        $code = (string)($row['nominal_code'] ?? '');
        $subtypeCode = (string)($row['subtype_code'] ?? '');
        $hasActivity = (int)($row['has_activity'] ?? 0) === 1;

        if ($code === '9999' || str_contains($name, 'uncategorised')) {
            $flags[] = ['key' => 'uncategorised', 'label' => 'Uncategorised'];
        }
        if ($code === '9990' || str_contains($name, 'suspense')) {
            $flags[] = ['key' => 'suspense', 'label' => 'Suspense'];
        }
        if ($subtypeCode === 'vat_control' || str_contains($name, 'vat')) {
            $flags[] = ['key' => 'vat', 'label' => 'VAT related'];
        }
        if ($subtypeCode === 'director_loan_liability' || str_contains($name, 'director loan')) {
            $flags[] = ['key' => 'director_loan', 'label' => 'Director loan related'];
        }
        if ($subtypeCode === 'corp_tax' || str_contains($name, 'corporation tax')) {
            $flags[] = ['key' => 'corporation_tax', 'label' => 'Corporation tax related'];
        }
        if (!$hasActivity) {
            $flags[] = ['key' => 'no_activity', 'label' => 'No activity this period'];
        }
        if ((int)($row['has_manual_journal'] ?? 0) === 1) {
            $flags[] = ['key' => 'manual', 'label' => 'Manual journals present'];
        }

        return $flags;
    }

    private function isExceptionRow(array $flags, array $row): bool {
        $flagKeys = array_map(static fn(array $flag): string => (string)($flag['key'] ?? ''), $flags);
        if (array_intersect($flagKeys, ['uncategorised', 'suspense', 'vat', 'director_loan', 'corporation_tax', 'manual']) !== []) {
            return true;
        }

        return abs((float)($row['total_debit'] ?? 0)) >= 0.005 || abs((float)($row['total_credit'] ?? 0)) >= 0.005;
    }

    private function closingBalanceNature(float $net): string {
        if (abs($net) < 0.005) {
            return 'Nil';
        }

        return $net >= 0 ? 'Debit' : 'Credit';
    }

    private function fetchNominalLedgerEntries(int $companyId, int $taxYearId, int $nominalAccountId, bool $includeUnposted): array {
        $postingPredicate = $includeUnposted ? '' : 'AND j.is_posted = 1';
        $stmt = $this->pdo->prepare(
            'SELECT j.id AS journal_id,
                    j.journal_date,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.description AS journal_description,
                    j.is_posted,
                    COALESCE(jl.debit, 0) AS debit,
                    COALESCE(jl.credit, 0) AS credit,
                    COALESCE(jl.line_description, \'\') AS line_description
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               ' . $postingPredicate . '
               AND jl.nominal_account_id = :nominal_account_id
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'nominal_account_id' => $nominalAccountId,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    private function fetchNominalAccount(int $nominalAccountId): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT na.id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(nas.name, \'\') AS subtype_name
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $nominalAccountId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function sourceLink(string $sourceType, string $sourceRef, int $companyId, int $taxYearId, string $journalDate): string {
        return match ($sourceType) {
            'bank_csv' => preg_match('/transaction:(\d+)/', $sourceRef, $matches) === 1
                ? '?page=transactions&company_id=' . $companyId . '&tax_year_id=' . $taxYearId . '&month_key=' . urlencode(substr($journalDate, 0, 7) . '-01') . '&category_filter=all#transaction-' . (int)$matches[1]
                : '?page=transactions&company_id=' . $companyId . '&tax_year_id=' . $taxYearId,
            'director_loan_register' => '?page=director-loan&company_id=' . $companyId . '&tax_year_id=' . $taxYearId,
            'expense_register', 'expense_claim_post', 'expense_claim_payment_link' => '?page=expenses&company_id=' . $companyId,
            'manual' => '?page=journals&company_id=' . $companyId . '&tax_year_id=' . $taxYearId,
            default => '?page=journals&company_id=' . $companyId . '&tax_year_id=' . $taxYearId,
        };
    }

    private function buildCsvFileName(string $companyName, string $periodLabel): string {
        $companySlug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $companyName), '-'));
        $periodSlug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $periodLabel), '-'));

        return ($companySlug !== '' ? $companySlug : 'company')
            . '-trial-balance-'
            . ($periodSlug !== '' ? $periodSlug : 'period')
            . '.csv';
    }
}
