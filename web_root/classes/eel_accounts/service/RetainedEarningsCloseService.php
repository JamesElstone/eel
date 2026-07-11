<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class RetainedEarningsCloseService
{
    public const JOURNAL_TAG = 'year_end_retained_earnings_close';
    public const JOURNAL_KEY = 'primary';
    public const RETAINED_EARNINGS_CODE = '3000';

    public function __construct(
        private readonly ?\eel_accounts\Service\YearEndMetricsService $metricsService = null,
        private readonly ?\eel_accounts\Service\ManualJournalService $journalService = null,
        private readonly ?\eel_accounts\Service\YearEndLockService $lockService = null,
        private readonly ?\eel_accounts\Service\AssetService $assetService = null,
        private readonly ?\eel_accounts\Service\CorporationTaxProvisionService $corporationTaxProvisionService = null,
    ) {
    }

    public function fetchContext(int $companyId, int $accountingPeriodId): array
    {
        $metrics = $this->metricsService ?? new \eel_accounts\Service\YearEndMetricsService();
        $accountingPeriod = $metrics->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $accountingPeriod === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $settings = $metrics->fetchCompanySettings($companyId);
        $review = ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->fetchReview($companyId, $accountingPeriodId) ?? [];
        $retainedEarningsNominal = $this->retainedEarningsNominal();
        if ($retainedEarningsNominal === null) {
            return [
                'available' => false,
                'errors' => ['Retained Earnings nominal 3000 is missing.'],
                'accounting_period' => $accountingPeriod,
                'review' => $review,
            ];
        }

        $plRows = $this->profitAndLossRows($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $depreciationPreview = ($this->assetService ?? new \eel_accounts\Service\AssetService())->previewDepreciationRun($companyId, $accountingPeriodId);
        $plRows = $this->includePendingDepreciationRows($plRows, $depreciationPreview);
        $corporationTaxProvision = ($this->corporationTaxProvisionService ?? new \eel_accounts\Service\CorporationTaxProvisionService())
            ->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
        $plRows = $this->includePendingCorporationTaxProvisionRows($plRows, $corporationTaxProvision);
        $profitAndLoss = $this->profitAndLossTotals($plRows);
        $openingEquity = $this->equityBalanceUntilDate($companyId, $periodStart, true, false);
        $closingEquityBeforeClose = $this->equityBalanceUntilDate($companyId, $periodEnd, false, true);
        $expectedClosingEquity = round($openingEquity + (float)$profitAndLoss['profit_before_tax'], 2);
        $balanceSheet = $metrics->fetchBalanceSheetMetricValues($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $journalLines = $this->buildJournalLines($plRows, (int)$retainedEarningsNominal['id']);
        $existingJournal = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $accountingPeriodId,
            self::JOURNAL_TAG,
            self::JOURNAL_KEY
        );

        $summary = [
            'opening_equity' => round($openingEquity, 2),
            'current_profit_loss' => round((float)$profitAndLoss['profit_before_tax'], 2),
            'closing_equity_before_close' => round($closingEquityBeforeClose, 2),
            'expected_closing_equity' => $expectedClosingEquity,
            'retained_earnings_movement' => round((float)$profitAndLoss['profit_before_tax'], 2),
            'unexplained_movement_before_close' => round($closingEquityBeforeClose - $expectedClosingEquity, 2),
            'assets' => round((float)($balanceSheet['fixed_assets'] ?? 0) + (float)($balanceSheet['current_assets'] ?? 0), 2),
            'liabilities' => round((float)($balanceSheet['creditors_within_one_year'] ?? 0) + (float)($balanceSheet['creditors_after_more_than_one_year'] ?? 0), 2),
            'equity' => round((float)($balanceSheet['equity_capital_reserves'] ?? 0), 2),
        ];

        return [
            'available' => true,
            'errors' => [],
            'company_id' => $companyId,
            'accounting_period' => $accountingPeriod,
            'settings' => $settings,
            'review' => $review,
            'acknowledged' => $this->isAcknowledged($review),
            'acknowledgement_stale' => $this->acknowledgementIsStale($review, $summary),
            'retained_earnings_nominal' => $retainedEarningsNominal,
            'summary' => $summary,
            'depreciation_preview' => $depreciationPreview,
            'corporation_tax_provision' => $corporationTaxProvision,
            'profit_and_loss_rows' => $plRows,
            'journal_lines' => $journalLines,
            'existing_journal' => $existingJournal,
        ];
    }

    public function saveAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array
    {
        if (!$acknowledged) {
            return ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->saveRetainedEarningsCloseAcknowledgement(
                $companyId,
                $accountingPeriodId,
                false,
                [],
                $changedBy,
                ''
            );
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }

        return ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->saveRetainedEarningsCloseAcknowledgement(
            $companyId,
            $accountingPeriodId,
            true,
            (array)$context['summary'],
            $changedBy,
            $note
        );
    }

    public function postClose(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }

        if (empty($context['acknowledged'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Review and agree the retained earnings close before locking this accounting period.'],
                'context' => $context,
            ];
        }

        if (!empty($context['acknowledgement_stale'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['The retained earnings figures have changed since acknowledgement. Review and agree the retained earnings close again.'],
                'context' => $context,
            ];
        }

        $journalLines = (array)($context['journal_lines'] ?? []);
        $journalLines = $this->deltaJournalLines(
            $journalLines,
            ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->listJournalsByTags($companyId, $accountingPeriodId, [self::JOURNAL_TAG])
        );

        if (count($journalLines) < 2) {
            return [
                'success' => true,
                'skipped' => true,
                'context' => $context,
            ];
        }

        $periodEnd = (string)(((array)$context['accounting_period'])['period_end'] ?? '');
        $result = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
            $companyId,
            $accountingPeriodId,
            self::JOURNAL_TAG,
            self::JOURNAL_KEY,
            $periodEnd,
            'Carry current profit/loss into retained earnings',
            $journalLines,
            'system_generated',
            null,
            null,
            'Year-end close: reset income and expense nominal balances for the next period (clear them). Original source entries are unchanged.',
            $changedBy
        );

        return $result + [
            'context' => $context,
        ];
    }

    public function removeCloseJournal(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        return ['success' => true, 'deleted' => false, 'skipped' => true];
    }

    public function acknowledgementIsCurrent(array $review, array $summary): bool
    {
        return $this->isAcknowledged($review) && !$this->acknowledgementIsStale($review, $summary);
    }

    private function isAcknowledged(array $review): bool
    {
        return trim((string)($review['retained_earnings_close_acknowledged_at'] ?? '')) !== '';
    }

    private function acknowledgementIsStale(array $review, array $summary): bool
    {
        if (!$this->isAcknowledged($review)) {
            return false;
        }

        $checks = [
            'retained_earnings_close_opening_equity' => 'opening_equity',
            'retained_earnings_close_current_profit_loss' => 'current_profit_loss',
            'retained_earnings_close_closing_equity_before' => 'closing_equity_before_close',
            'retained_earnings_close_amount' => 'retained_earnings_movement',
        ];

        foreach ($checks as $reviewKey => $summaryKey) {
            if (abs(round((float)($review[$reviewKey] ?? 0) - (float)($summary[$summaryKey] ?? 0), 2)) >= 0.005) {
                return true;
            }
        }

        return false;
    }

    private function retainedEarningsNominal(): ?array
    {
        return $this->nominalByCode(self::RETAINED_EARNINGS_CODE, 'equity');
    }

    private function nominalByCode(string $code, string $accountType = ''): ?array
    {
        $conditions = [
            'code' => $code,
        ];
        $accountTypeSql = '';
        if ($accountType !== '') {
            $accountTypeSql = ' AND account_type = :account_type';
            $conditions['account_type'] = $accountType;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, tax_treatment, is_active
             FROM nominal_accounts
             WHERE code = :code
               ' . $accountTypeSql . '
               AND is_active = 1
             LIMIT 1',
            $conditions
        );

        return is_array($row) ? $row : null;
    }

    private function profitAndLossRows(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    SUM(COALESCE(jl.debit, 0)) AS total_debit,
                    SUM(COALESCE(jl.credit, 0)) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.id, na.code, na.name, na.account_type, na.tax_treatment
             ORDER BY na.code, na.name, na.id',
            [
                'close_journal_tag' => self::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );
    }

    private function profitAndLossTotals(array $rows): array
    {
        $income = 0.0;
        $expenses = 0.0;

        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)($row['total_debit'] ?? 0);
            $credit = (float)($row['total_credit'] ?? 0);

            if ($accountType === 'income') {
                $income += round($credit - $debit, 2);
            } elseif ($accountType === 'expense' || $accountType === 'cost_of_sales') {
                $expenses += round($debit - $credit, 2);
            }
        }

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'profit_before_tax' => round($income - $expenses, 2),
        ];
    }

    private function includePendingDepreciationRows(array $plRows, array $depreciationPreview): array
    {
        if (empty($depreciationPreview['success']) || (int)($depreciationPreview['created'] ?? 0) <= 0) {
            return $plRows;
        }

        $amount = round((float)($depreciationPreview['total_amount'] ?? 0), 2);
        if (abs($amount) < 0.005) {
            return $plRows;
        }

        $depreciationNominal = $this->nominalByCode('6200');
        if ($depreciationNominal === null) {
            return $plRows;
        }

        $nominalId = (int)$depreciationNominal['id'];
        foreach ($plRows as $index => $row) {
            if ((int)($row['id'] ?? 0) !== $nominalId) {
                continue;
            }

            $plRows[$index]['total_debit'] = number_format(round((float)($row['total_debit'] ?? 0) + $amount, 2), 2, '.', '');
            $plRows[$index]['pending_year_end_depreciation'] = number_format($amount, 2, '.', '');

            return $plRows;
        }

        $plRows[] = [
            'id' => $nominalId,
            'code' => (string)($depreciationNominal['code'] ?? '6200'),
            'name' => (string)($depreciationNominal['name'] ?? 'Depreciation Expense'),
            'account_type' => (string)($depreciationNominal['account_type'] ?? 'expense'),
            'tax_treatment' => (string)($depreciationNominal['tax_treatment'] ?? 'allowable'),
            'total_debit' => number_format($amount, 2, '.', ''),
            'total_credit' => '0.00',
            'pending_year_end_depreciation' => number_format($amount, 2, '.', ''),
        ];

        return $plRows;
    }

    private function includePendingCorporationTaxProvisionRows(array $plRows, array $provision): array
    {
        if (empty($provision['available'])) {
            return $plRows;
        }

        $amount = round((float)($provision['unposted_corporation_tax_adjustment'] ?? 0), 2);
        if (abs($amount) < 0.005) {
            return $plRows;
        }

        foreach ($plRows as $index => $row) {
            if ((string)($row['code'] ?? '') !== '8500') {
                continue;
            }

            if ($amount > 0) {
                $plRows[$index]['total_debit'] = number_format(round((float)($row['total_debit'] ?? 0) + $amount, 2), 2, '.', '');
            } else {
                $plRows[$index]['total_credit'] = number_format(round((float)($row['total_credit'] ?? 0) + abs($amount), 2), 2, '.', '');
            }
            $plRows[$index]['pending_corporation_tax_provision'] = number_format($amount, 2, '.', '');

            return $plRows;
        }

        $corporationTaxNominal = $this->nominalByCode('8500', 'expense');
        if ($corporationTaxNominal === null) {
            return $plRows;
        }

        $plRows[] = [
            'id' => (int)$corporationTaxNominal['id'],
            'code' => (string)($corporationTaxNominal['code'] ?? '8500'),
            'name' => (string)($corporationTaxNominal['name'] ?? 'Corporation Tax Expense'),
            'account_type' => (string)($corporationTaxNominal['account_type'] ?? 'expense'),
            'tax_treatment' => (string)($corporationTaxNominal['tax_treatment'] ?? 'disallowable'),
            'total_debit' => $amount > 0 ? number_format($amount, 2, '.', '') : '0.00',
            'total_credit' => $amount < 0 ? number_format(abs($amount), 2, '.', '') : '0.00',
            'pending_corporation_tax_provision' => number_format($amount, 2, '.', ''),
        ];

        return $plRows;
    }

    private function buildJournalLines(array $plRows, int $retainedEarningsNominalId): array
    {
        $lines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($plRows as $row) {
            $nominalId = (int)($row['id'] ?? 0);
            $balance = round((float)($row['total_debit'] ?? 0) - (float)($row['total_credit'] ?? 0), 2);
            if ($nominalId <= 0 || abs($balance) < 0.005) {
                continue;
            }

            $label = trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
            if ($balance < 0) {
                $amount = abs($balance);
                $lines[] = [
                    'nominal_account_id' => $nominalId,
                    'nominal_code' => (string)($row['code'] ?? ''),
                    'nominal_name' => (string)($row['name'] ?? ''),
                    'debit' => number_format($amount, 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => 'Move ' . $label . ' into retained earnings',
                ];
                $totalDebit += $amount;
            } else {
                $lines[] = [
                    'nominal_account_id' => $nominalId,
                    'nominal_code' => (string)($row['code'] ?? ''),
                    'nominal_name' => (string)($row['name'] ?? ''),
                    'debit' => '0.00',
                    'credit' => number_format($balance, 2, '.', ''),
                    'line_description' => 'Move ' . $label . ' into retained earnings',
                ];
                $totalCredit += $balance;
            }
        }

        $difference = round($totalDebit - $totalCredit, 2);
        if (abs($difference) >= 0.005 && $retainedEarningsNominalId > 0) {
            if ($difference > 0) {
                $lines[] = [
                    'nominal_account_id' => $retainedEarningsNominalId,
                    'nominal_code' => self::RETAINED_EARNINGS_CODE,
                    'nominal_name' => 'Retained Earnings',
                    'debit' => '0.00',
                    'credit' => number_format($difference, 2, '.', ''),
                    'line_description' => 'Carry profit into retained earnings',
                ];
            } else {
                $lines[] = [
                    'nominal_account_id' => $retainedEarningsNominalId,
                    'nominal_code' => self::RETAINED_EARNINGS_CODE,
                    'nominal_name' => 'Retained Earnings',
                    'debit' => number_format(abs($difference), 2, '.', ''),
                    'credit' => '0.00',
                    'line_description' => 'Carry loss into retained earnings',
                ];
            }
        }

        return $lines;
    }

    private function deltaJournalLines(array $targetLines, array $existingJournals): array
    {
        $existing = [];
        foreach ($existingJournals as $journal) {
            foreach ((array)($journal['lines'] ?? []) as $line) {
                $nominalId = (int)($line['nominal_account_id'] ?? 0);
                if ($nominalId <= 0) {
                    continue;
                }
                $existing[$nominalId] = round(
                    (float)($existing[$nominalId] ?? 0)
                    + (float)($line['debit'] ?? 0)
                    - (float)($line['credit'] ?? 0),
                    2
                );
            }
        }

        $deltaLines = [];
        foreach ($targetLines as $line) {
            $nominalId = (int)($line['nominal_account_id'] ?? 0);
            if ($nominalId <= 0) {
                continue;
            }
            $target = round((float)($line['debit'] ?? 0) - (float)($line['credit'] ?? 0), 2);
            $delta = round($target - (float)($existing[$nominalId] ?? 0), 2);
            if (abs($delta) < 0.005) {
                continue;
            }

            $deltaLines[] = [
                'nominal_account_id' => $nominalId,
                'nominal_code' => (string)($line['nominal_code'] ?? ''),
                'nominal_name' => (string)($line['nominal_name'] ?? ''),
                'debit' => $delta > 0 ? number_format($delta, 2, '.', '') : '0.00',
                'credit' => $delta < 0 ? number_format(abs($delta), 2, '.', '') : '0.00',
                'line_description' => (string)($line['line_description'] ?? 'Retained earnings close adjustment'),
            ];
        }

        return $deltaLines;
    }

    private function equityBalanceUntilDate(int $companyId, string $date, bool $exclusive, bool $excludeCloseJournal): float
    {
        $operator = $exclusive ? '<' : '<=';
        $join = '';
        $where = '';
        $params = [
            'company_id' => $companyId,
            'date' => $date,
            'account_type' => 'equity',
        ];

        if ($excludeCloseJournal) {
            $join = ' LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag';
            $where = ' AND jem_close.id IS NULL';
            $params['close_journal_tag'] = self::JOURNAL_TAG;
        }

        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.credit, 0) - COALESCE(jl.debit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id'
             . $join . '
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date ' . $operator . ' :date
               AND na.account_type = :account_type'
             . $where,
            $params
        ), 2);
    }
}
