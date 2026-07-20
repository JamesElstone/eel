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
        private readonly ?\eel_accounts\Service\YearEndAcknowledgementService $acknowledgementService = null,
        private readonly ?\Closure $prepaymentPreviewContextFetcher = null,
    ) {
    }

    public function fetchContext(
        int $companyId,
        int $accountingPeriodId,
        ?array $corporationTaxProvision = null,
        ?array $balanceSheetMetrics = null,
        ?array $depreciationPreview = null,
        ?array $prepaymentPreview = null
    ): array
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
        $priorPeriodDependency = $this->priorPeriodDependency($companyId, $periodStart);
        $settings = $metrics->fetchCompanySettings($companyId);
        $retainedEarningsNominal = $this->retainedEarningsNominal();
        if ($retainedEarningsNominal === null) {
            return [
                'available' => false,
                'errors' => ['Retained Earnings nominal 3000 is missing.'],
                'accounting_period' => $accountingPeriod,
            ];
        }

        $plRows = $this->profitAndLossRows($companyId, $accountingPeriodId, $periodStart, $periodEnd);
        $depreciationPreview ??= ($this->assetService ?? new \eel_accounts\Service\AssetService())
            ->previewDepreciationRun($companyId, $accountingPeriodId);
        $plRows = $this->includePendingDepreciationRows($plRows, $depreciationPreview);
        $prepaymentPreviewContext = [
            'available' => true,
            'success' => true,
            'errors' => [],
            'adjustments' => $prepaymentPreview ?? [],
        ];
        if ($prepaymentPreview === null) {
            $prepaymentPreviewContext = $this->prepaymentPreviewContextFetcher !== null
                ? (array)($this->prepaymentPreviewContextFetcher)($companyId, $accountingPeriodId)
                : (new \eel_accounts\Service\PrepaymentScheduleService())
                    ->fetchPreviewAdjustmentContext($companyId, $accountingPeriodId);
            $prepaymentPreview = (array)($prepaymentPreviewContext['adjustments'] ?? []);
        }
        if (empty($prepaymentPreviewContext['available']) || empty($prepaymentPreviewContext['success'])) {
            $prepaymentErrors = array_values(array_unique(array_filter(array_map(
                'strval',
                (array)($prepaymentPreviewContext['errors'] ?? ['The prepayment preview is unavailable or unreliable.'])
            ))));

            return [
                'available' => false,
                'errors' => $prepaymentErrors !== [] ? $prepaymentErrors : ['The prepayment preview is unavailable or unreliable.'],
                'company_id' => $companyId,
                'accounting_period' => $accountingPeriod,
                'settings' => $settings,
                'retained_earnings_nominal' => $retainedEarningsNominal,
                'depreciation_preview' => $depreciationPreview,
                'prepayment_preview' => $prepaymentPreview,
                'prepayment_preview_context' => $prepaymentPreviewContext,
                'prepayment_preview_reliable' => false,
                'prior_period_dependency' => $priorPeriodDependency,
                'acknowledged' => false,
                'acknowledgement_stale' => true,
                'acknowledgement_state' => 'unverifiable',
                'can_acknowledge' => false,
                'can_post' => false,
            ];
        }
        $plRows = $this->includePendingPrepaymentRows(
            $plRows,
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $prepaymentPreview
        );
        $corporationTaxProvision ??= ($this->corporationTaxProvisionService ?? new \eel_accounts\Service\CorporationTaxProvisionService())
            ->fetchAccountingPeriodPosition($companyId, $accountingPeriodId);
        $plRows = $this->includePendingCorporationTaxProvisionRows($plRows, $corporationTaxProvision);
        $profitAndLoss = $this->profitAndLossTotals($plRows);
        $openingEquity = $this->equityBalanceUntilDate($companyId, $periodStart, true, false);
        $closingEquityBeforeClose = $this->equityBalanceUntilDate($companyId, $periodEnd, false, true);
        $directEquityMovement = round($closingEquityBeforeClose - $openingEquity, 2);
        $shareCapitalMovement = $this->equityNominalMovementForPeriod(
            $companyId,
            $periodStart,
            $periodEnd,
            '3010',
            true
        );
        $otherDirectEquityMovement = round($directEquityMovement - $shareCapitalMovement, 2);
        $expectedClosingEquity = round(
            $openingEquity + $directEquityMovement + (float)$profitAndLoss['profit_before_tax'],
            2
        );
        $balanceSheet = $balanceSheetMetrics
            ?? $metrics->fetchBalanceSheetMetricValues(
                $companyId,
                $accountingPeriodId,
                $periodStart,
                $periodEnd,
                $depreciationPreview,
                $prepaymentPreview
            );
        $journalLines = $this->buildJournalLines($plRows, (int)$retainedEarningsNominal['id']);
        $existingJournal = ($this->journalService ?? new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $accountingPeriodId,
            self::JOURNAL_TAG,
            self::JOURNAL_KEY
        );
        $assets = round(
            (float)($balanceSheet['fixed_assets'] ?? 0)
                + (float)($balanceSheet['current_assets'] ?? 0)
                + (float)($balanceSheet['prepayments_accrued_income'] ?? 0),
            2
        );
        $liabilities = round((float)($balanceSheet['creditors_within_one_year'] ?? 0) + (float)($balanceSheet['creditors_after_more_than_one_year'] ?? 0), 2);
        $equity = round((float)($balanceSheet['equity_capital_reserves'] ?? 0), 2);
        $balanceEquationDifference = round($assets - $liabilities - $equity, 2);
        $prepaymentExpenseAdjustment = (new YearEndClosePreviewService())->prepaymentExpenseAdjustmentForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $prepaymentPreview
        );
        $reserveReview = (new \eel_accounts\Service\DividendReserveClassificationService())
            ->fetchReviewContext($companyId, $accountingPeriodId, $periodEnd);

        $summary = [
            'opening_equity' => round($openingEquity, 2),
            'current_profit_loss' => round((float)$profitAndLoss['profit_before_tax'], 2),
            'closing_equity_before_close' => round($closingEquityBeforeClose, 2),
            'expected_closing_equity' => $expectedClosingEquity,
            'direct_equity_movement' => $directEquityMovement,
            'share_capital_movement' => $shareCapitalMovement,
            'other_direct_equity_movement' => $otherDirectEquityMovement,
            'retained_earnings_movement' => round((float)$profitAndLoss['profit_before_tax'], 2),
            'unexplained_movement_before_close' => round($closingEquityBeforeClose - $expectedClosingEquity, 2),
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'balance_equation_difference' => $balanceEquationDifference,
            'is_balance_sheet_balanced' => abs($balanceEquationDifference) < 0.005,
            'prepayment_expense_adjustment' => round($prepaymentExpenseAdjustment, 2),
        ];

        $context = [
            'available' => true,
            'errors' => [],
            'company_id' => $companyId,
            'accounting_period' => $accountingPeriod,
            'settings' => $settings,
            'retained_earnings_nominal' => $retainedEarningsNominal,
            'summary' => $summary,
            'depreciation_preview' => $depreciationPreview,
            'prepayment_preview' => $prepaymentPreview,
            'prepayment_preview_context' => $prepaymentPreviewContext,
            'prepayment_preview_reliable' => true,
            'corporation_tax_provision' => $corporationTaxProvision,
            'profit_and_loss_rows' => $plRows,
            'journal_lines' => $journalLines,
            'existing_journal' => $existingJournal,
            'reserve_review' => $reserveReview,
            'prior_period_dependency' => $priorPeriodDependency,
            'warnings' => empty($priorPeriodDependency['satisfied'])
                ? [(string)($priorPeriodDependency['detail'] ?? 'Complete and lock the prior accounting period before closing retained earnings.')]
                : [],
        ];
        $service = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        $acknowledgement = $service->fetch($companyId, $accountingPeriodId, 'retained_earnings_close_confirmation');
        $evaluation = $service->evaluate(
            $acknowledgement,
            $this->acknowledgementBasisForContext($context),
            ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId)
        );

        return $context + [
            'acknowledged' => !empty($evaluation['current']),
            'acknowledgement_stale' => in_array((string)($evaluation['state'] ?? ''), ['stale', 'unverifiable'], true),
            'acknowledgement_state' => (string)($evaluation['state'] ?? 'absent'),
            'acknowledgement' => $acknowledgement,
            'can_acknowledge' => !empty($priorPeriodDependency['satisfied']),
            'can_post' => !empty($priorPeriodDependency['satisfied']),
        ];
    }

    public function saveAcknowledgement(int $companyId, int $accountingPeriodId, bool $acknowledged, string $changedBy = 'web_app', string $note = ''): array
    {
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'save a retained earnings Year End acknowledgement');
        if (!$acknowledged) {
            return ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
                ->revoke($companyId, $accountingPeriodId, 'retained_earnings_close_confirmation');
        }

        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }
        if (empty($context['can_acknowledge'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => [(string)(($context['prior_period_dependency'] ?? [])['detail'] ?? 'Complete and lock the prior accounting period before approving retained earnings.')],
                'context' => $context,
            ];
        }
        if (empty((($context['reserve_review'] ?? [])['snapshot_current'] ?? false))) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Complete and save the Distributable Profit Review before approving Profit & Loss.'],
                'context' => $context,
            ];
        }

        $service = $this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService();
        return $service->save(
            $companyId,
            $accountingPeriodId,
            'retained_earnings_close_confirmation',
            $this->acknowledgementBasisForContext($context),
            $changedBy,
            $note
        );
    }

    public function postClose(int $companyId, int $accountingPeriodId, string $changedBy = 'web_app', bool $acknowledgementPrevalidated = false): array
    {
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'post the retained earnings Year End close');
        $context = $this->fetchContext($companyId, $accountingPeriodId);
        if (empty($context['available'])) {
            return $context + ['success' => false];
        }
        if (empty($context['can_post'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => [(string)(($context['prior_period_dependency'] ?? [])['detail'] ?? 'Complete and lock the prior accounting period before posting retained earnings.')],
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

        if (empty($context['acknowledged'])) {
            return [
                'success' => false,
                'status' => 422,
                'errors' => ['Review and agree the retained earnings close before locking this accounting period.'],
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
        (new \eel_accounts\Service\VatSupportScopeService())
            ->assertTaxAndYearEndSupported($companyId, 'remove a retained earnings Year End close');
        return ['success' => true, 'deleted' => false, 'skipped' => true];
    }

    public function acknowledgementBasisForContext(array $context): array
    {
        return ($this->acknowledgementService ?? new \eel_accounts\Service\YearEndAcknowledgementService())
            ->buildBasis('retained_earnings_close_confirmation', [
                'summary' => $this->stableAcknowledgementSummary((array)($context['summary'] ?? [])),
                'journal_lines' => (array)($context['journal_lines'] ?? []),
                'reserve_review' => $this->stableReserveReview((array)($context['reserve_review'] ?? [])),
                'prior_period_dependency' => (array)($context['prior_period_dependency'] ?? []),
            ]);
    }

    private function stableReserveReview(array $review): array
    {
        $summary = (array)($review['summary'] ?? []);
        $stableSummary = array_intersect_key($summary, array_flip([
            'brought_forward_distributable_reserves',
            'ledger_profit_loss',
            'realised_profit_amount',
            'realised_loss_amount',
            'unrealised_gain_amount',
            'unrealised_loss_amount',
            'non_distributable_amount',
            'capital_amount',
            'tax_charge_amount',
            'dividend_distribution_amount',
            'unknown_amount',
            'distributable_current_profit',
            'dividends_declared',
            'closing_distributable_reserves',
        ]));

        return [
            'available' => !empty($review['available']),
            'status' => (string)($review['status'] ?? 'unavailable'),
            'as_at_date' => (string)($review['as_at_date'] ?? ''),
            'source_hash' => (string)($review['source_hash'] ?? ''),
            'snapshot_current' => !empty($review['snapshot_current']),
            'summary' => $stableSummary,
            'rows' => array_map(
                static fn(array $row): array => [
                    'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                    'profit_effect' => number_format((float)($row['profit_effect'] ?? 0), 2, '.', ''),
                    'default_treatment' => (string)($row['default_treatment'] ?? ''),
                    'treatment' => (string)($row['treatment'] ?? ''),
                ],
                array_values(array_filter(
                    (array)($review['rows'] ?? []),
                    static fn(mixed $row): bool => is_array($row)
                ))
            ),
        ];
    }

    private function stableAcknowledgementSummary(array $summary): array
    {
        return array_intersect_key($summary, array_flip([
            'opening_equity',
            'current_profit_loss',
            'closing_equity_before_close',
            'expected_closing_equity',
            'direct_equity_movement',
            'share_capital_movement',
            'other_direct_equity_movement',
            'retained_earnings_movement',
            'unexplained_movement_before_close',
            'balance_equation_difference',
            'is_balance_sheet_balanced',
        ]));
    }

    private function priorPeriodDependency(int $companyId, string $periodStart): array
    {
        $priorPeriod = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        );
        if (!is_array($priorPeriod)) {
            return [
                'status' => 'first_period',
                'satisfied' => true,
                'prior_accounting_period' => null,
                'detail' => 'This is the first recorded accounting period, so no prior-period lock is required.',
            ];
        }

        $locked = ($this->lockService ?? new \eel_accounts\Service\YearEndLockService())
            ->isLocked($companyId, (int)($priorPeriod['id'] ?? 0));

        return [
            'status' => $locked ? 'prior_period_locked' : 'prior_period_unlocked',
            'satisfied' => $locked,
            'prior_accounting_period' => $priorPeriod,
            'detail' => $locked
                ? 'The prior accounting period is locked and retained earnings can roll forward from it.'
                : 'The prior accounting period is not locked. Complete its Year End close before approving or posting this retained earnings close.',
        ];
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

    private function includePendingPrepaymentRows(
        array $plRows,
        int $companyId,
        int $accountingPeriodId,
        string $periodStart,
        string $periodEnd,
        array $prepaymentPreview
    ): array {
        $rows = (new \eel_accounts\Service\YearEndClosePreviewService())->prepaymentExpenseRowsForPeriod(
            $companyId,
            $accountingPeriodId,
            $periodStart,
            $periodEnd,
            $prepaymentPreview
        );
        foreach ($rows as $pendingRow) {
            $nominalId = (int)($pendingRow['nominal_account_id'] ?? 0);
            $amount = round((float)($pendingRow['amount'] ?? 0), 2);
            if ($nominalId <= 0 || abs($amount) < 0.005) {
                continue;
            }

            $matched = false;
            foreach ($plRows as $index => $row) {
                if ((int)($row['id'] ?? 0) !== $nominalId) {
                    continue;
                }

                if ($amount > 0) {
                    $plRows[$index]['total_debit'] = number_format(
                        round((float)($row['total_debit'] ?? 0) + $amount, 2),
                        2,
                        '.',
                        ''
                    );
                } else {
                    $plRows[$index]['total_credit'] = number_format(
                        round((float)($row['total_credit'] ?? 0) + abs($amount), 2),
                        2,
                        '.',
                        ''
                    );
                }
                $plRows[$index]['pending_prepayment_adjustment'] = number_format(
                    round((float)($row['pending_prepayment_adjustment'] ?? 0) + $amount, 2),
                    2,
                    '.',
                    ''
                );
                $matched = true;
                break;
            }
            if ($matched) {
                continue;
            }

            $plRows[] = [
                'id' => $nominalId,
                'code' => (string)($pendingRow['code'] ?? ''),
                'name' => (string)($pendingRow['name'] ?? ''),
                'account_type' => (string)($pendingRow['account_type'] ?? 'expense'),
                'tax_treatment' => (string)($pendingRow['tax_treatment'] ?? 'allowable'),
                'total_debit' => $amount > 0 ? number_format($amount, 2, '.', '') : '0.00',
                'total_credit' => $amount < 0 ? number_format(abs($amount), 2, '.', '') : '0.00',
                'pending_prepayment_adjustment' => number_format($amount, 2, '.', ''),
            ];
        }

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

    private function equityNominalMovementForPeriod(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        string $nominalCode,
        bool $excludeCloseJournal
    ): float {
        $join = '';
        $where = '';
        $params = [
            'company_id' => $companyId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'account_type' => 'equity',
            'nominal_code' => $nominalCode,
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
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND na.account_type = :account_type
               AND na.code = :nominal_code'
             . $where,
            $params
        ), 2);
    }
}
