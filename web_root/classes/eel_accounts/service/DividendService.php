<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class DividendService
{
    private const RETAINED_EARNINGS_CODE = '3000';
    private const DIVIDENDS_PAID_CODE = '3100';
    private const DIVIDENDS_PAYABLE_CODE = '2150';

    public function ensureDividendNominals(int $companyId): array
    {
        if ($companyId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company before preparing dividend nominal accounts.'],
                'accounts' => [],
            ];
        }

        $required = [
            [
                'code' => self::RETAINED_EARNINGS_CODE,
                'name' => 'Retained Earnings',
                'account_type' => 'equity',
                'subtype_code' => 'capital_reserves',
                'tax_treatment' => 'other',
                'sort_order' => 66,
            ],
            [
                'code' => self::DIVIDENDS_PAID_CODE,
                'name' => 'Dividends Paid',
                'account_type' => 'equity',
                'subtype_code' => 'capital_reserves',
                'tax_treatment' => 'other',
                'sort_order' => 71,
            ],
            [
                'code' => self::DIVIDENDS_PAYABLE_CODE,
                'name' => 'Dividends Payable',
                'account_type' => 'liability',
                'subtype_code' => 'dividends_payable',
                'tax_treatment' => 'other',
                'sort_order' => 56,
            ],
        ];

        try {
            foreach ($required as $definition) {
                $code = (string)$definition['code'];
                $existing = $this->findNominalByCode($code);
                if ($existing !== null) {
                    continue;
                }
                $subtypeId = $this->findSubtypeIdForCode(
                    (string)($definition['subtype_code'] ?? ''),
                    (string)$definition['account_type']
                );

                \InterfaceDB::prepareExecute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
                     VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [
                        $code,
                        $definition['name'],
                        $definition['account_type'],
                        $subtypeId > 0 ? $subtypeId : null,
                        $definition['tax_treatment'],
                        $definition['sort_order'],
                    ]
                );
            }

            return [
                'available' => true,
                'errors' => [],
                'accounts' => $this->dividendNominals(),
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'errors' => [$exception->getMessage()],
                'accounts' => $this->dividendNominals(),
            ];
        }
    }

    public function getDividendCapacity(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        return (array)$this->getDividendCapacityContext($companyId, $accountingPeriodId, $asAtDate)['capacity'];
    }

    public function getDividendCapacityContext(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            $capacity = [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing dividend capacity.'],
            ];

            return [
                'capacity' => $capacity,
                'reserve_review' => $this->unavailableReserveReview('Select a company and accounting period before reviewing dividend reserves.'),
            ];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            $capacity = [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];

            return [
                'capacity' => $capacity,
                'reserve_review' => $this->unavailableReserveReview('The selected accounting period could not be found.'),
            ];
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $effectiveDate = $this->effectiveAsAtDate($asAtDate, $periodStart, $periodEnd);
        $ledgerProfit = $this->profitForPeriod($companyId, $accountingPeriodId, $periodStart, $effectiveDate);
        $dividendsDeclared = $this->dividendsDeclaredForPeriod($companyId, $accountingPeriodId, $periodStart, $effectiveDate);
        $retainedEarningsPosition = $this->retainedEarningsBroughtForward($companyId, $accountingPeriodId, $periodStart);
        $retainedEarningsBroughtForward = (float)($retainedEarningsPosition['amount'] ?? 0.0);
        $reserveReview = (new \eel_accounts\Service\DividendReserveClassificationService())->fetchReviewContext($companyId, $accountingPeriodId, $effectiveDate);
        $reserveReviewReliable = !empty($reserveReview['available']) && !empty($reserveReview['snapshot_current']);
        $reserveReviewDetail = $this->reserveReviewDetail($reserveReview);
        $reserveSnapshot = is_array($reserveReview['snapshot'] ?? null) ? (array)$reserveReview['snapshot'] : [];
        $classifiedProfit = $reserveReviewReliable
            ? round((float)($reserveSnapshot['distributable_current_profit'] ?? 0), 2)
            : 0.0;
        $taxPosition = $this->corporationTaxCapacityPosition($companyId, $accountingPeriodId, $accountingPeriod, $ledgerProfit, $effectiveDate);
        $currentYearProfitAfterTax = round($classifiedProfit - (float)($taxPosition['unposted_corporation_tax_adjustment'] ?? 0), 2);
        $availableReserves = round($retainedEarningsBroughtForward + $currentYearProfitAfterTax - $dividendsDeclared, 2);
        $reservesReliable = !empty($retainedEarningsPosition['reliable'])
            && $reserveReviewReliable
            && !empty($taxPosition['reliable']);
        $reserveBasisDetail = $this->reserveBasisDetail($retainedEarningsPosition, $reserveReviewReliable, $reserveReviewDetail, $taxPosition);

        $capacity = [
            'available' => true,
            'errors' => [],
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'accounting_period' => $accountingPeriod,
            'as_at_date' => $effectiveDate,
            'retained_earnings_brought_forward' => $retainedEarningsBroughtForward,
            'distributable_reserves_brought_forward' => $retainedEarningsBroughtForward,
            'retained_earnings_status' => (string)($retainedEarningsPosition['status'] ?? 'unknown'),
            'retained_earnings_detail' => (string)($retainedEarningsPosition['detail'] ?? ''),
            'reserve_basis_detail' => $reserveBasisDetail,
            'reserve_review_status' => (string)($reserveReview['status'] ?? 'unavailable'),
            'reserve_review_detail' => $reserveReviewDetail,
            'reserves_reliable' => $reservesReliable,
            'ledger_current_year_profit_loss' => $ledgerProfit,
            'classified_current_year_profit_loss' => $classifiedProfit,
            'posted_corporation_tax_charge' => (float)($taxPosition['posted_corporation_tax_charge'] ?? 0),
            'estimated_corporation_tax' => (float)($taxPosition['estimated_corporation_tax'] ?? 0),
            'unposted_corporation_tax_adjustment' => (float)($taxPosition['unposted_corporation_tax_adjustment'] ?? 0),
            'tax_periods' => (array)($taxPosition['tax_periods'] ?? []),
            'tax_totals' => (array)($taxPosition['tax_totals'] ?? []),
            'current_year_profit_loss_after_tax' => $currentYearProfitAfterTax,
            'current_year_profit_loss' => $currentYearProfitAfterTax,
            'corporation_tax_status' => (string)($taxPosition['status'] ?? 'unknown'),
            'corporation_tax_detail' => (string)($taxPosition['detail'] ?? ''),
            'dividends_declared' => $dividendsDeclared,
            'available_distributable_reserves' => $availableReserves,
            'reliability_warnings' => $this->dividendReliabilityWarnings($companyId, $accountingPeriodId, $periodStart, $effectiveDate),
            'status' => $reservesReliable && $availableReserves > 0 ? 'available' : 'blocked',
            'status_label' => !$reservesReliable ? 'Reserve basis not ready' : ($availableReserves > 0 ? 'Available reserves' : 'No distributable reserves'),
            'status_badge_class' => $reservesReliable && $availableReserves > 0 ? 'success' : 'danger',
        ];

        return [
            'capacity' => $capacity,
            'reserve_review' => $reserveReview,
        ];
    }

    public function declareDividend(array $input): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        $accountingPeriodId = (int)($input['accounting_period_id'] ?? 0);
        $declarationDate = trim((string)($input['declaration_date'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $settlementTarget = trim((string)($input['settlement_target'] ?? ''));
        $changedBy = $this->actorValue((string)($input['changed_by'] ?? 'web_app'));
        $amount = round((float)($input['amount'] ?? 0), 2);
        $reconciliationTransactionId = (int)($input['reconciliation_transaction_id'] ?? 0);

        if ($description === '') {
            $description = 'Interim dividend';
        }

        $errors = [];
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            $errors[] = 'Select a company and accounting period before declaring a dividend.';
        }
        if (!$this->isValidDate($declarationDate)) {
            $errors[] = 'Enter a valid declaration date.';
        }
        if ($reconciliationTransactionId <= 0 && $amount <= 0) {
            $errors[] = 'Dividend amount must be greater than zero.';
        }
        if (!in_array($settlementTarget, ['director_loan_liability', 'unpaid_dividend_liability'], true)) {
            $errors[] = 'Choose a valid dividend settlement target.';
        }

        $accountingPeriod = $companyId > 0 && $accountingPeriodId > 0 ? $this->fetchAccountingPeriod($companyId, $accountingPeriodId) : null;
        if ($accountingPeriod === null && $companyId > 0 && $accountingPeriodId > 0) {
            $errors[] = 'The selected accounting period could not be found.';
        }
        if ($this->isValidDate($declarationDate) && $declarationDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            $errors[] = 'Declaration date cannot be in the future.';
        }
        if ($accountingPeriod !== null && $this->dateInsidePeriod($declarationDate, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end']) === false) {
            $errors[] = 'Declaration date must fall inside the selected accounting period.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($reconciliationTransactionId > 0) {
            return $this->declareDividendFromTransaction($reconciliationTransactionId, $companyId, $accountingPeriodId, $changedBy);
        }

        $nominalResult = $this->ensureDividendNominals($companyId);
        if (empty($nominalResult['available'])) {
            return [
                'success' => false,
                'errors' => (array)($nominalResult['errors'] ?? ['Dividend nominal accounts could not be prepared.']),
            ];
        }

        $nominals = (array)($nominalResult['accounts'] ?? []);
        $dividendsPaidNominalId = (int)($nominals['dividends_paid']['id'] ?? 0);
        $dividendsPayableNominalId = (int)($nominals['dividends_payable']['id'] ?? 0);
        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $directorLoanNominalId = (int)($settings['director_loan_liability_nominal_id'] ?? 0);
        if ($directorLoanNominalId <= 0) {
            $directorLoanNominalId = (int)($settings['director_loan_nominal_id'] ?? 0);
        }
        $creditNominalId = $settlementTarget === 'director_loan_liability'
            ? $directorLoanNominalId
            : $dividendsPayableNominalId;

        if ($dividendsPaidNominalId <= 0) {
            return ['success' => false, 'errors' => ['Dividends Paid nominal account is missing.']];
        }
        if ($creditNominalId <= 0) {
            return ['success' => false, 'errors' => [$settlementTarget === 'director_loan_liability'
                ? 'Set the director loan liability nominal before declaring a dividend to director loan.'
                : 'Dividends Payable nominal account is missing.']];
        }

        $capacity = $this->getDividendCapacity($companyId, $accountingPeriodId, $declarationDate);
        if (empty($capacity['reserves_reliable'])) {
            return ['success' => false, 'errors' => [(string)($capacity['reserve_basis_detail'] ?? $capacity['retained_earnings_detail'] ?? 'Dividend declaration is blocked until distributable reserves can be verified.')]];
        }
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        if ($availableReserves < 0) {
            return ['success' => false, 'errors' => ['Dividend declaration is blocked because distributable reserves are negative.']];
        }
        if ($amount > $availableReserves) {
            return ['success' => false, 'errors' => ['Dividend amount exceeds available distributable reserves.']];
        }
        if ($availableReserves <= 0) {
            return ['success' => false, 'errors' => ['Dividend declaration is blocked because distributable reserves are not positive.']];
        }

        try {
            (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'declare dividends in this period');
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $sourceRef = $this->sourceRef($companyId, $accountingPeriodId, $declarationDate);
        $ownsTransaction = !\InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            \InterfaceDB::prepareExecute(
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
                 ) VALUES (?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [
                    $companyId,
                    $accountingPeriodId,
                    'manual',
                    $sourceRef,
                    $declarationDate,
                    $description,
                ]
            );

            $journalId = $this->findJournalId($companyId, $sourceRef);
            if ($journalId <= 0) {
                throw new \RuntimeException('The dividend journal could not be reloaded after insert.');
            }

            $this->insertJournalLine($journalId, $dividendsPaidNominalId, $amount, 0.0, 'Dividend declared');
            $this->insertJournalLine($journalId, $creditNominalId, 0.0, $amount, $this->settlementLabel($settlementTarget));
            $voucher = $this->ensureVoucherForJournal($journalId, null, $changedBy);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'posted' => false,
                'journal_id' => $journalId,
                'source_ref' => $sourceRef,
                'voucher_id' => (int)($voucher['id'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function declareDividendFromTransaction(int $transactionId, int $companyId, int $accountingPeriodId, string $changedBy = 'web_app'): array
    {
        $changedBy = $this->actorValue($changedBy);
        if ($transactionId <= 0 || $companyId <= 0 || $accountingPeriodId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid dividend payment transaction.']];
        }

        $transaction = $this->fetchDividendPaymentTransaction($transactionId);
        if ($transaction === null) {
            return ['success' => false, 'errors' => ['The selected transaction could not be found.']];
        }

        if ((int)($transaction['company_id'] ?? 0) !== $companyId || (int)($transaction['accounting_period_id'] ?? 0) !== $accountingPeriodId) {
            return ['success' => false, 'errors' => ['The selected transaction does not belong to the selected company and accounting period.']];
        }
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1) {
            return ['success' => false, 'errors' => ['Internal transfer rows cannot create dividend declarations.']];
        }
        if (!in_array((string)($transaction['category_status'] ?? ''), ['auto', 'manual'], true)) {
            return ['success' => false, 'errors' => ['Categorise the transaction to Dividends Payable before creating a dividend declaration.']];
        }
        if ((string)($transaction['nominal_code'] ?? '') !== self::DIVIDENDS_PAYABLE_CODE) {
            return ['success' => false, 'errors' => ['Only transactions categorised to Dividends Payable can create dividend declarations.']];
        }

        $amount = round(abs((float)($transaction['amount'] ?? 0)), 2);
        if ((float)($transaction['amount'] ?? 0) >= 0 || $amount <= 0) {
            return ['success' => false, 'errors' => ['Only outgoing dividend payment transactions can create dividend declarations.']];
        }

        $declarationDate = (string)($transaction['txn_date'] ?? '');
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return ['success' => false, 'errors' => ['The selected accounting period could not be found.']];
        }
        if ($declarationDate > (new \DateTimeImmutable('today'))->format('Y-m-d')) {
            return ['success' => false, 'errors' => ['Declaration date cannot be in the future.']];
        }
        if (!$this->dateInsidePeriod($declarationDate, (string)$accountingPeriod['period_start'], (string)$accountingPeriod['period_end'])) {
            return ['success' => false, 'errors' => ['The transaction date must fall inside the selected accounting period.']];
        }

        $sourceRef = $this->transactionDividendSourceRef($transactionId);
        $existingJournalId = $this->findJournalId($companyId, $sourceRef);
        if ($existingJournalId > 0) {
            $voucher = $this->ensureVoucherForJournal($existingJournalId, $transactionId, $changedBy);
            return [
                'success' => true,
                'posted' => true,
                'already_exists' => true,
                'journal_id' => $existingJournalId,
                'source_ref' => $sourceRef,
                'voucher_id' => (int)($voucher['id'] ?? 0),
            ];
        }

        $capacity = $this->getDividendCapacity($companyId, $accountingPeriodId, $declarationDate);
        if (empty($capacity['reserves_reliable'])) {
            return ['success' => false, 'errors' => [(string)($capacity['reserve_basis_detail'] ?? $capacity['retained_earnings_detail'] ?? 'Dividend declaration is blocked until distributable reserves can be verified.')]];
        }
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        if ($availableReserves < 0) {
            return ['success' => false, 'errors' => ['Dividend declaration is blocked because distributable reserves are negative.']];
        }
        if ($amount > $availableReserves) {
            return ['success' => false, 'errors' => ['Dividend amount exceeds available distributable reserves.']];
        }
        if ($availableReserves <= 0) {
            return ['success' => false, 'errors' => ['Dividend declaration is blocked because distributable reserves are not positive.']];
        }

        try {
            (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'create dividend declarations in this period');
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $nominalResult = $this->ensureDividendNominals($companyId);
        if (empty($nominalResult['available'])) {
            return [
                'success' => false,
                'errors' => (array)($nominalResult['errors'] ?? ['Dividend nominal accounts could not be prepared.']),
            ];
        }

        $nominals = (array)($nominalResult['accounts'] ?? []);
        $dividendsPaidNominalId = (int)($nominals['dividends_paid']['id'] ?? 0);
        $dividendsPayableNominalId = (int)($nominals['dividends_payable']['id'] ?? 0);
        if ($dividendsPaidNominalId <= 0 || $dividendsPayableNominalId <= 0) {
            return ['success' => false, 'errors' => ['Dividend nominal accounts are missing.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $description = trim((string)($transaction['description'] ?? ''));
            $journalDescription = trim('Dividend declared from transaction ' . $transactionId . ($description !== '' ? ': ' . $description : ''));
            if (strlen($journalDescription) > 255) {
                $journalDescription = substr($journalDescription, 0, 252) . '...';
            }

            \InterfaceDB::prepareExecute(
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
                 ) VALUES (?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                [
                    $companyId,
                    $accountingPeriodId,
                    'manual',
                    $sourceRef,
                    $declarationDate,
                    $journalDescription,
                ]
            );

            $journalId = $this->findJournalId($companyId, $sourceRef);
            if ($journalId <= 0) {
                throw new \RuntimeException('The dividend declaration journal could not be reloaded after insert.');
            }

            $this->insertJournalLine($journalId, $dividendsPaidNominalId, $amount, 0.0, 'Dividend declared from imported transaction');
            $this->insertJournalLine($journalId, $dividendsPayableNominalId, 0.0, $amount, 'Dividend payable created from imported transaction');
            $voucher = $this->ensureVoucherForJournal($journalId, $transactionId, $changedBy);

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'posted' => true,
                'already_exists' => false,
                'journal_id' => $journalId,
                'source_ref' => $sourceRef,
                'voucher_id' => (int)($voucher['id'] ?? 0),
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function listDividendReconciliationCandidates(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $dividendsPayable = $this->findNominalByCode(self::DIVIDENDS_PAYABLE_CODE);
        $dividendsPayableId = (int)($dividendsPayable['id'] ?? 0);
        if ($dividendsPayableId <= 0) {
            return [];
        }

        $stmt = \InterfaceDB::prepare(
            'SELECT t.id,
                    t.txn_date,
                    t.description,
                    t.amount
             FROM transactions t
             WHERE t.company_id = ?
               AND t.accounting_period_id = ?
               AND COALESCE(t.is_internal_transfer, 0) = 0
               AND t.nominal_account_id = ?
               AND t.amount < 0
               AND t.category_status IN (\'auto\', \'manual\')
               AND NOT EXISTS (
                    SELECT 1
                    FROM journals dividend_j
                    WHERE dividend_j.company_id = t.company_id
                      AND dividend_j.source_type = \'manual\'
                      AND dividend_j.source_ref = CONCAT(\'dividend:transaction:\', t.id)
               )
             ORDER BY t.txn_date DESC, t.id DESC'
        );
        if ($stmt === false) {
            return [];
        }

        $stmt->execute([$companyId, $accountingPeriodId, $dividendsPayableId]);
        return $stmt->fetchAll() ?: [];
    }

    public function listDividends(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $dividendsPaid = $this->findNominalByCode(self::DIVIDENDS_PAID_CODE);
        $dividendsPaidId = (int)($dividendsPaid['id'] ?? 0);
        $params = [$companyId, $accountingPeriodId, 'manual', 'dividend:%'];
        $nominalCondition = '';
        if ($dividendsPaidId > 0) {
            $nominalCondition = ' OR EXISTS (
                    SELECT 1
                    FROM journal_lines touch_line
                    WHERE touch_line.journal_id = j.id
                      AND touch_line.nominal_account_id = ?
                )';
            $params[] = $dividendsPaidId;
        }

        $voucherSelect = $this->tableExists('dividend_vouchers')
            ? ', dv.id AS voucher_id,
                    dv.transaction_id AS voucher_transaction_id,
                    dv.reversal_journal_id,
                    dv.voided_at,
                    dv.voided_by,
                    dv.void_reason'
            : ', NULL AS voucher_id,
                    NULL AS voucher_transaction_id,
                    NULL AS reversal_journal_id,
                    NULL AS voided_at,
                    NULL AS voided_by,
                    NULL AS void_reason';
        $voucherJoin = $this->tableExists('dividend_vouchers')
            ? ' LEFT JOIN dividend_vouchers dv ON dv.journal_id = j.id'
            : '';

        $stmt = \InterfaceDB::prepare(
            'SELECT j.id,
                    j.journal_date,
                    j.description,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.is_posted,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
                    ' . $voucherSelect . '
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             ' . $voucherJoin . '
             WHERE j.company_id = ?
               AND j.accounting_period_id = ?
               AND COALESCE(j.source_ref, \'\') NOT LIKE \'dividend:void:%\'
               AND (
                    (j.source_type = ? AND j.source_ref LIKE ?)' . $nominalCondition . '
               )
             GROUP BY j.id, j.journal_date, j.description, j.source_ref, j.is_posted,
                      voucher_id, voucher_transaction_id, reversal_journal_id, voided_at, voided_by, void_reason
             ORDER BY j.journal_date DESC, j.id DESC'
        );
        if ($stmt === false) {
            return [];
        }

        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        $isPeriodLocked = $this->isPeriodLocked($companyId, $accountingPeriodId);
        foreach ($rows as &$row) {
            $transactionId = (int)($row['voucher_transaction_id'] ?? 0);
            if ($transactionId <= 0) {
                $transactionId = $this->transactionIdFromDividendSourceRef((string)($row['source_ref'] ?? ''));
            }
            if ((int)($row['voucher_id'] ?? 0) <= 0) {
                $voucher = $this->ensureVoucherForJournal((int)($row['id'] ?? 0), $transactionId > 0 ? $transactionId : null);
                $row['voucher_id'] = (int)($voucher['id'] ?? 0);
                $row['voucher_transaction_id'] = (int)($voucher['transaction_id'] ?? 0);
                $row['voided_at'] = (string)($voucher['voided_at'] ?? '');
                $row['voided_by'] = (string)($voucher['voided_by'] ?? '');
                $row['void_reason'] = (string)($voucher['void_reason'] ?? '');
                $row['reversal_journal_id'] = (int)($voucher['reversal_journal_id'] ?? 0);
            }

            $paymentLink = $this->paymentLinkState((string)($row['source_ref'] ?? ''), $transactionId);
            $row['amount'] = $this->dividendAmountForJournal((int)$row['id'], $dividendsPaidId);
            $row['settlement_account'] = $this->settlementAccountForJournal((int)$row['id'], $dividendsPaidId);
            $row['payment_link_status'] = $paymentLink['status'];
            $row['payment_link_label'] = $paymentLink['label'];
            $row['payment_link_detail'] = $paymentLink['detail'];
            $row['transaction_notes'] = $paymentLink['notes'];
            $isVoided = trim((string)($row['voided_at'] ?? '')) !== '';
            if ($isVoided) {
                $row['payment_link_status'] = 'voided';
                $row['payment_link_label'] = 'Voided';
                $row['payment_link_detail'] = 'This dividend has been voided and retained for audit history.';
            }
            $row['status'] = $isVoided ? 'voided' : (!empty($row['is_posted']) ? 'posted' : 'draft');
            $row['can_void'] = !$isVoided
                && !$isPeriodLocked
                && (bool)$paymentLink['voidable']
                && trim((string)$paymentLink['notes']) !== '';
        }
        unset($row);

        return $rows;
    }

    public function listDividendVouchers(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !$this->tableExists('dividend_vouchers')) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT dv.id,
                    dv.company_id,
                    dv.accounting_period_id,
                    dv.journal_id,
                    dv.transaction_id,
                    dv.reversal_journal_id,
                    dv.company_name,
                    dv.shareholder_name,
                    dv.director_name,
                    dv.declaration_date,
                    dv.payment_date,
                    dv.amount,
                    dv.description,
                    dv.voucher_text,
                    dv.minutes_text,
                    dv.issued_at,
                    dv.issued_by,
                    dv.voided_at,
                    dv.voided_by,
                    dv.void_reason,
                    COALESCE(j.source_ref, \'\') AS source_ref
             FROM dividend_vouchers dv
             LEFT JOIN journals j ON j.id = dv.journal_id
             WHERE dv.company_id = :company_id
               AND dv.accounting_period_id = :accounting_period_id
             ORDER BY dv.declaration_date DESC, dv.id DESC',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );
        return $rows;
    }

    public function voidDividend(int $companyId, int $accountingPeriodId, int $journalId, string $changedBy = 'web_app'): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $journalId <= 0) {
            return ['success' => false, 'errors' => ['Select a valid dividend before voiding it.']];
        }
        if (!$this->tableExists('dividend_vouchers')) {
            return ['success' => false, 'errors' => ['Run the dividend voucher migration before voiding dividend records.']];
        }

        try {
            (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'void dividend records in this period');
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $journal = $this->fetchDividendJournal($companyId, $accountingPeriodId, $journalId);
        if ($journal === null) {
            return ['success' => false, 'errors' => ['The selected dividend journal could not be found.']];
        }

        $transactionId = $this->transactionIdFromDividendSourceRef((string)($journal['source_ref'] ?? ''));
        if ($transactionId <= 0) {
            return ['success' => false, 'errors' => ['Only dividends created from imported transactions can be voided from this workflow.']];
        }

        $voucher = $this->ensureVoucherForJournal($journalId, $transactionId, $changedBy);
        if (trim((string)($voucher['voided_at'] ?? '')) !== '') {
            return ['success' => false, 'errors' => ['This dividend has already been voided.']];
        }

        $paymentLink = $this->paymentLinkState((string)($journal['source_ref'] ?? ''), $transactionId);
        if (empty($paymentLink['voidable'])) {
            return ['success' => false, 'errors' => ['This dividend still has a valid linked dividend payment.']];
        }

        $reason = trim((string)($paymentLink['notes'] ?? ''));
        if ($reason === '') {
            return ['success' => false, 'errors' => ['Add a transaction note explaining the correction before voiding this dividend.']];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $reversalJournalId = (int)($voucher['reversal_journal_id'] ?? 0);
            if ($reversalJournalId <= 0 && (int)($journal['is_posted'] ?? 0) === 1) {
                $reversalJournalId = $this->createDividendReversalJournal($journal, $reason, $changedBy);
            }
            $voidedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            \InterfaceDB::prepareExecute(
                'UPDATE dividend_vouchers
                 SET reversal_journal_id = :reversal_journal_id,
                     voided_at = :voided_at,
                     voided_by = :voided_by,
                     void_reason = :void_reason
                 WHERE id = :id',
                [
                    'reversal_journal_id' => $reversalJournalId > 0 ? $reversalJournalId : null,
                    'voided_at' => $voidedAt,
                    'voided_by' => $changedBy,
                    'void_reason' => $reason,
                    'id' => (int)$voucher['id'],
                ]
            );

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }

            return [
                'success' => true,
                'journal_id' => $journalId,
                'voucher_id' => (int)$voucher['id'],
                'reversal_journal_id' => $reversalJournalId,
            ];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function getDividendWarnings(int $companyId, int $accountingPeriodId): array
    {
        $capacity = $companyId > 0 && $accountingPeriodId > 0
            ? $this->getDividendCapacity($companyId, $accountingPeriodId)
            : null;

        return $this->getDividendWarningsForCapacity($companyId, $accountingPeriodId, $capacity);
    }

    public function getDividendWarningsForCapacity(int $companyId, int $accountingPeriodId, ?array $capacity = null): array
    {
        $warnings = [];
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            $warnings[] = [
                'severity' => 'danger',
                'title' => 'Selected period missing',
                'detail' => 'Select a company and accounting period before using dividend tools.',
            ];
        }

        $nominals = $this->dividendNominals();
        foreach (['retained_earnings', 'dividends_paid', 'dividends_payable'] as $key) {
            if ((int)($nominals[$key]['id'] ?? 0) <= 0) {
                $warnings[] = [
                    'severity' => 'warning',
                    'title' => 'Dividend nominal missing',
                    'detail' => 'One or more dividend nominal accounts could not be found or created.',
                ];
                break;
            }
        }

        if ($companyId > 0 && $accountingPeriodId > 0) {
            $capacity ??= $this->getDividendCapacity($companyId, $accountingPeriodId);
            $profit = (float)($capacity['ledger_current_year_profit_loss'] ?? $capacity['current_year_profit_loss'] ?? 0);
            $reserves = (float)($capacity['available_distributable_reserves'] ?? 0);

            if (empty($capacity['reserves_reliable'])) {
                $warnings[] = [
                    'severity' => 'danger',
                    'title' => 'Reserve basis blocked',
                    'detail' => (string)($capacity['reserve_basis_detail'] ?? 'Dividend declaration is blocked until the reserve basis has been reviewed.'),
                ];
            }
            if ($reserves <= 0) {
                $warnings[] = [
                    'severity' => 'danger',
                    'title' => 'Insufficient reserves',
                    'detail' => 'Available distributable reserves are not positive, so dividend declarations are blocked.',
                ];
            }
            if ($profit < 0) {
                $warnings[] = [
                    'severity' => 'warning',
                    'title' => 'Negative current-year profit',
                    'detail' => 'The selected period currently shows a loss up to the capacity date.',
                ];
            }
            if ((float)($capacity['unposted_corporation_tax_adjustment'] ?? 0) > 0.0) {
                $warnings[] = [
                    'severity' => 'warning',
                    'title' => 'Corporation Tax estimate deducted',
                    'detail' => 'Dividend capacity deducts the estimated Corporation Tax not yet posted into the ledger.',
                ];
            }
        }
        $warnings[] = [
            'severity' => 'info',
            'title' => 'Dividend review scope',
            'detail' => 'Capacity is based on reviewed as-at distributable reserve snapshots, posted ledgers, and CT estimates. It is not legal advice and does not by itself prove every Companies Act capital maintenance condition.',
        ];

        return $warnings;
    }

    public function getDividendReliabilityWarnings(int $companyId, int $accountingPeriodId, ?string $asAtDate = null): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return [];
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return [];
        }

        $periodStart = (string)$accountingPeriod['period_start'];
        $periodEnd = (string)$accountingPeriod['period_end'];
        $effectiveDate = $this->effectiveAsAtDate($asAtDate, $periodStart, $periodEnd);

        return $this->dividendReliabilityWarnings($companyId, $accountingPeriodId, $periodStart, $effectiveDate);
    }

    public function dividendNominals(): array
    {
        return [
            'retained_earnings' => $this->findNominalByCode(self::RETAINED_EARNINGS_CODE) ?? [],
            'dividends_paid' => $this->findNominalByCode(self::DIVIDENDS_PAID_CODE) ?? [],
            'dividends_payable' => $this->findNominalByCode(self::DIVIDENDS_PAYABLE_CODE) ?? [],
        ];
    }

    private function dividendReliabilityWarnings(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): array
    {
        $warnings = [];
        $latestBankDate = $this->latestBankSourceDate($companyId, $accountingPeriodId, $periodStart, $asAtDate);
        if ($latestBankDate === '' || $latestBankDate < $asAtDate) {
            $warnings[] = [
                'severity' => 'warning',
                'title' => 'Bank CSV coverage may be incomplete',
                'detail' => $latestBankDate === ''
                    ? 'No uploaded or committed bank transaction source data was found up to the capacity date. Upload the latest bank CSV before relying on the dividend figure.'
                    : 'The latest uploaded or committed bank transaction source date is ' . $latestBankDate . ', before the capacity date ' . $asAtDate . '. Upload the latest bank CSV before relying on the dividend figure.',
                'action_label' => 'Open Related Workflow',
                'action_url' => '?page=uploads',
                'workflow_page' => 'uploads',
                'workflow_fields' => [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                ],
                'code' => 'bank_csv_coverage',
            ];
        }

        $uncategorisedCount = $this->uncategorisedTransactionsCount($companyId, $accountingPeriodId, $periodStart, $asAtDate);
        if ($uncategorisedCount > 0) {
            $warnings[] = [
                'severity' => 'warning',
                'title' => 'Uncategorised transactions affect capacity',
                'detail' => $uncategorisedCount . ' transaction(s) dated on or before the capacity date are uncategorised or missing a nominal account.',
                'action_label' => 'Open Related Workflow',
                'action_url' => '?page=transactions',
                'workflow_page' => 'transactions',
                'workflow_fields' => [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'category_filter' => 'uncategorised',
                ],
                'code' => 'uncategorised_transactions',
            ];
        }

        return $warnings;
    }

    private function latestBankSourceDate(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): string
    {
        $dates = [];
        if ($this->tableExists('transactions')) {
            $transactionDate = trim((string)(\InterfaceDB::fetchColumn(
                'SELECT MAX(txn_date)
                 FROM transactions
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND txn_date BETWEEN :period_start AND :as_at_date',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => $periodStart,
                    'as_at_date' => $asAtDate,
                ]
            ) ?: ''));
            if ($transactionDate !== '') {
                $dates[] = $transactionDate;
            }
        }

        if ($this->tableExists('statement_import_rows')) {
            $importDate = trim((string)(\InterfaceDB::fetchColumn(
                'SELECT MAX(chosen_txn_date)
                 FROM statement_import_rows
                 WHERE accounting_period_id = :accounting_period_id
                   AND chosen_txn_date BETWEEN :period_start AND :as_at_date',
                [
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => $periodStart,
                    'as_at_date' => $asAtDate,
                ]
            ) ?: ''));
            if ($importDate !== '') {
                $dates[] = $importDate;
            }
        }

        if ($this->tableExists('statement_uploads')) {
            $uploadDate = trim((string)(\InterfaceDB::fetchColumn(
                'SELECT MAX(COALESCE(date_range_end, statement_month, date_range_start))
                 FROM statement_uploads
                 WHERE company_id = :company_id
                   AND (
                        accounting_period_id = :accounting_period_id
                        OR accounting_period_id IS NULL
                   )
                   AND COALESCE(date_range_end, statement_month, date_range_start) BETWEEN :period_start AND :as_at_date',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'period_start' => $periodStart,
                    'as_at_date' => $asAtDate,
                ]
            ) ?: ''));
            if ($uploadDate !== '') {
                $dates[] = $uploadDate;
            }
        }

        sort($dates);
        return (string)($dates[array_key_last($dates)] ?? '');
    }

    private function uncategorisedTransactionsCount(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): int
    {
        if (!$this->tableExists('transactions')) {
            return 0;
        }

        $noPostExclusionSql = $this->tableExists('transaction_inter_ac_marker')
            ? 'AND NOT EXISTS (
                   SELECT 1
                   FROM transaction_inter_ac_marker tiam
                   WHERE tiam.matched_transaction_id = transactions.id
               )'
            : '';

        return (int)(\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
               AND txn_date BETWEEN :period_start AND :as_at_date
               AND (category_status = :category_status OR nominal_account_id IS NULL)
               ' . $noPostExclusionSql,
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'as_at_date' => $asAtDate,
                'category_status' => 'uncategorised',
            ]
        ) ?: 0);
    }

    private function reserveReviewDetail(array $reserveReview): string
    {
        if (empty($reserveReview['available'])) {
            $errors = (array)($reserveReview['errors'] ?? []);
            return (string)($errors[0] ?? 'Dividend reserve classification review is not available.');
        }
        if (!empty($reserveReview['snapshot_current'])) {
            return 'Current-year reserve movements are based on a current as-at dividend reserve classification review.';
        }

        $status = (string)($reserveReview['status'] ?? 'missing');
        return $status === 'stale'
            ? 'Dividend declaration is blocked because the dividend reserve classification review is stale.'
            : 'Dividend declaration is blocked until current-year reserve movements are classified and reviewed.';
    }

    private function unavailableReserveReview(string $message): array
    {
        return [
            'available' => false,
            'errors' => [$message],
        ];
    }

    private function reserveBasisDetail(array $retainedEarningsPosition, bool $reserveReviewReliable, string $reserveReviewDetail, array $taxPosition): string
    {
        if (empty($retainedEarningsPosition['reliable'])) {
            return (string)($retainedEarningsPosition['detail'] ?? 'Dividend declaration is blocked until retained earnings brought forward can be verified.');
        }
        if (!$reserveReviewReliable) {
            return $reserveReviewDetail;
        }
        if (empty($taxPosition['reliable'])) {
            return (string)($taxPosition['detail'] ?? 'Dividend declaration is blocked until Corporation Tax capacity can be reviewed.');
        }

        return 'Reserve basis is based on locked prior distributable reserves, reviewed current-year reserve classifications, and Corporation Tax capacity checks.';
    }

    private function corporationTaxCapacityPosition(int $companyId, int $accountingPeriodId, array $accountingPeriod, float $ledgerProfit, string $asAtDate): array
    {
        $postedCharge = $this->postedCorporationTaxChargeForPeriod(
            $companyId,
            $accountingPeriodId,
            (string)($accountingPeriod['period_start'] ?? ''),
            $asAtDate
        );
        $estimate = 0.0;
        $estimateAvailable = false;
        $errors = [];
        $result = [];

        try {
            $result = (new \eel_accounts\Service\YearEndTaxReadinessService())->fetchAccountingPeriodCtSummary($companyId, $accountingPeriodId);
            $estimateAvailable = !empty($result['available']);
            if ($estimateAvailable) {
                $estimate = max(0.0, round((float)($result['estimated_corporation_tax'] ?? 0), 2));
            } else {
                $errors = (array)($result['errors'] ?? []);
            }
        } catch (\Throwable $exception) {
            $errors = [$exception->getMessage()];
        }

        if (!$estimateAvailable && $ledgerProfit > 0.0) {
            return [
                'reliable' => false,
                'status' => 'ct_estimate_unavailable',
                'posted_corporation_tax_charge' => $postedCharge,
                'estimated_corporation_tax' => 0.0,
                'unposted_corporation_tax_adjustment' => 0.0,
                'detail' => (string)($errors[0] ?? 'Dividend declaration is blocked until a Corporation Tax estimate is available for the selected period.'),
            ];
        }

        $unpostedAdjustment = max(0.0, round($estimate - $postedCharge, 2));

        return [
            'reliable' => true,
            'status' => $unpostedAdjustment > 0.0 ? 'ct_estimate_adjusted' : 'ct_posted_or_nil',
            'posted_corporation_tax_charge' => $postedCharge,
            'estimated_corporation_tax' => $estimate,
            'unposted_corporation_tax_adjustment' => $unpostedAdjustment,
            'tax_periods' => (array)($result['periods'] ?? []),
            'tax_totals' => (array)($result['totals'] ?? []),
            'detail' => $unpostedAdjustment > 0.0
                ? 'Dividend capacity deducts estimated Corporation Tax across all CT periods that has not yet been posted into the ledger.'
                : 'Corporation Tax across all CT periods is either nil by estimate or already reflected by posted ledger entries.',
        ];
    }

    private function postedCorporationTaxChargeForPeriod(int $companyId, int $accountingPeriodId, string $periodStart, string $periodEnd): float
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $periodStart === '' || $periodEnd === '') {
            return 0.0;
        }

        return max(0.0, round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(pjl.debit - pjl.credit), 0)
             FROM journals j
             INNER JOIN journal_lines pjl ON pjl.journal_id = j.id
             INNER JOIN nominal_accounts pna ON pna.id = pjl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND pna.account_type IN (:expense_type, :cost_type)
               AND (
                    LOWER(pna.name) LIKE :corporation_tax_name
                    OR EXISTS (
                        SELECT 1
                        FROM journal_lines ctl
                        INNER JOIN nominal_accounts ctna ON ctna.id = ctl.nominal_account_id
                        LEFT JOIN nominal_account_subtypes cts ON cts.id = ctna.account_subtype_id
                        WHERE ctl.journal_id = j.id
                          AND (
                            ctna.code = :corporation_tax_code
                            OR LOWER(ctna.name) LIKE :corporation_tax_name_exists
                            OR cts.code = :corporation_tax_subtype
                          )
                    )
               )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'expense_type' => 'expense',
                'cost_type' => 'cost_of_sales',
                'corporation_tax_name' => '%corporation tax%',
                'corporation_tax_name_exists' => '%corporation tax%',
                'corporation_tax_code' => '2200',
                'corporation_tax_subtype' => 'corp_tax',
            ]
        ), 2));
    }

    private function ensureVoucherForJournal(int $journalId, ?int $transactionId = null, string $changedBy = 'web_app'): ?array
    {
        if ($journalId <= 0 || !$this->tableExists('dividend_vouchers')) {
            return null;
        }

        $existing = \InterfaceDB::fetchOne(
            'SELECT *
             FROM dividend_vouchers
             WHERE journal_id = :journal_id
             LIMIT 1',
            ['journal_id' => $journalId]
        );
        if (is_array($existing)) {
            return $existing;
        }

        $journal = $this->fetchDividendJournal(0, 0, $journalId);
        if ($journal === null) {
            return null;
        }

        $company = $this->fetchCompany((int)$journal['company_id']);
        $transactionId = $transactionId !== null && $transactionId > 0
            ? $transactionId
            : $this->transactionIdFromDividendSourceRef((string)($journal['source_ref'] ?? ''));
        $transaction = $transactionId > 0 ? $this->fetchDividendPaymentTransaction($transactionId) : null;
        $amount = abs($this->dividendAmountForJournal($journalId, (int)($this->findNominalByCode(self::DIVIDENDS_PAID_CODE)['id'] ?? 0)));
        $companyName = trim((string)($company['company_name'] ?? ''));
        $directorName = $this->directorNameFromCompany($company);
        $shareholderName = $directorName !== '' ? $directorName : $this->shareholderNameFromTransaction($transaction);
        if ($shareholderName === '') {
            $shareholderName = 'Shareholder';
        }
        if ($directorName === '') {
            $directorName = $shareholderName;
        }
        $companyNameForRecords = $companyName !== '' ? $companyName : 'Company';

        $declarationDate = (string)($journal['journal_date'] ?? '');
        $paymentDate = is_array($transaction) && trim((string)($transaction['txn_date'] ?? '')) !== ''
            ? (string)$transaction['txn_date']
            : $declarationDate;
        $description = trim((string)($journal['description'] ?? 'Dividend'));
        $voucherText = $this->voucherText($companyNameForRecords, $shareholderName, $declarationDate, $amount, $description);
        $minutesText = $this->minutesText($companyNameForRecords, $directorName, $declarationDate, $amount, $description);

        \InterfaceDB::prepareExecute(
            'INSERT INTO dividend_vouchers (
                company_id,
                accounting_period_id,
                journal_id,
                transaction_id,
                company_name,
                shareholder_name,
                director_name,
                declaration_date,
                payment_date,
                amount,
                description,
                voucher_text,
                minutes_text,
                issued_by
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :journal_id,
                :transaction_id,
                :company_name,
                :shareholder_name,
                :director_name,
                :declaration_date,
                :payment_date,
                :amount,
                :description,
                :voucher_text,
                :minutes_text,
                :issued_by
             )',
            [
                'company_id' => (int)$journal['company_id'],
                'accounting_period_id' => (int)$journal['accounting_period_id'],
                'journal_id' => $journalId,
                'transaction_id' => $transactionId > 0 ? $transactionId : null,
                'company_name' => $companyNameForRecords,
                'shareholder_name' => $shareholderName,
                'director_name' => $directorName,
                'declaration_date' => $declarationDate,
                'payment_date' => $paymentDate,
                'amount' => number_format($amount, 2, '.', ''),
                'description' => $description,
                'voucher_text' => $voucherText,
                'minutes_text' => $minutesText,
                'issued_by' => $changedBy,
            ]
        );

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM dividend_vouchers
             WHERE journal_id = :journal_id
             LIMIT 1',
            ['journal_id' => $journalId]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchDividendJournal(int $companyId, int $accountingPeriodId, int $journalId): ?array
    {
        $where = ['j.id = :journal_id'];
        $params = ['journal_id' => $journalId];
        if ($companyId > 0) {
            $where[] = 'j.company_id = :company_id';
            $params['company_id'] = $companyId;
        }
        if ($accountingPeriodId > 0) {
            $where[] = 'j.accounting_period_id = :accounting_period_id';
            $params['accounting_period_id'] = $accountingPeriodId;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT j.id,
                    j.company_id,
                    j.accounting_period_id,
                    j.source_type,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.journal_date,
                    j.description,
                    j.is_posted
             FROM journals j
             WHERE ' . implode(' AND ', $where) . '
               AND j.source_type = :source_type
               AND COALESCE(j.source_ref, \'\') LIKE :source_ref
               AND COALESCE(j.source_ref, \'\') NOT LIKE :void_source_ref
             LIMIT 1',
            $params + [
                'source_type' => 'manual',
                'source_ref' => 'dividend:%',
                'void_source_ref' => 'dividend:void:%',
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function createDividendReversalJournal(array $journal, string $reason, string $changedBy): int
    {
        $journalId = (int)($journal['id'] ?? 0);
        $sourceRef = 'dividend:void:' . $journalId;
        $existingId = $this->findJournalId((int)$journal['company_id'], $sourceRef);
        if ($existingId > 0) {
            return $existingId;
        }

        \InterfaceDB::prepareExecute(
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
             )',
            [
                'company_id' => (int)$journal['company_id'],
                'accounting_period_id' => (int)$journal['accounting_period_id'],
                'source_type' => 'manual',
                'source_ref' => $sourceRef,
                'journal_date' => (string)$journal['journal_date'],
                'description' => substr('Void dividend declaration ' . $journalId . ': ' . $reason, 0, 255),
            ]
        );

        $reversalJournalId = $this->findJournalId((int)$journal['company_id'], $sourceRef);
        if ($reversalJournalId <= 0) {
            throw new \RuntimeException('The dividend reversal journal could not be reloaded after insert.');
        }

        foreach ($this->fetchJournalLines($journalId) as $line) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO journal_lines (
                    journal_id,
                    nominal_account_id,
                    company_account_id,
                    debit,
                    credit,
                    line_description
                 ) VALUES (
                    :journal_id,
                    :nominal_account_id,
                    :company_account_id,
                    :debit,
                    :credit,
                    :line_description
                 )',
                [
                    'journal_id' => $reversalJournalId,
                    'nominal_account_id' => (int)$line['nominal_account_id'],
                    'company_account_id' => $line['company_account_id'] !== null ? (int)$line['company_account_id'] : null,
                    'debit' => number_format((float)$line['credit'], 2, '.', ''),
                    'credit' => number_format((float)$line['debit'], 2, '.', ''),
                    'line_description' => substr('Void: ' . (string)($line['line_description'] ?? ''), 0, 255),
                ]
            );
        }

        return $reversalJournalId;
    }

    private function fetchJournalLines(int $journalId): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT id,
                    journal_id,
                    nominal_account_id,
                    company_account_id,
                    debit,
                    credit,
                    line_description
             FROM journal_lines
             WHERE journal_id = :journal_id
             ORDER BY id ASC',
            ['journal_id' => $journalId]
        );
    }

    private function paymentLinkState(string $sourceRef, int $transactionId = 0): array
    {
        $sourceTransactionId = $transactionId > 0 ? $transactionId : $this->transactionIdFromDividendSourceRef($sourceRef);
        if ($sourceTransactionId <= 0) {
            return [
                'status' => 'manual',
                'label' => 'Manual / draft',
                'detail' => 'This dividend is not linked to an imported transaction.',
                'notes' => '',
                'voidable' => false,
            ];
        }

        $transaction = $this->fetchDividendPaymentTransaction($sourceTransactionId);
        if ($transaction === null) {
            return [
                'status' => 'missing',
                'label' => 'Missing',
                'detail' => 'The source payment transaction could not be found.',
                'notes' => '',
                'voidable' => false,
            ];
        }

        $notes = trim((string)($transaction['notes'] ?? ''));
        if ($this->transactionIsValidDividendPayment($transaction)) {
            return [
                'status' => 'linked',
                'label' => 'Linked',
                'detail' => 'The source payment is still categorised to Dividends Payable.',
                'notes' => $notes,
                'voidable' => false,
            ];
        }

        return [
            'status' => 'recategorised',
            'label' => 'Re-categorised',
            'detail' => 'The source payment is no longer a valid dividend payment.',
            'notes' => $notes,
            'voidable' => true,
        ];
    }

    private function transactionIsValidDividendPayment(array $transaction): bool
    {
        return (int)($transaction['is_internal_transfer'] ?? 0) !== 1
            && (float)($transaction['amount'] ?? 0) < 0
            && in_array((string)($transaction['category_status'] ?? ''), ['auto', 'manual'], true)
            && (string)($transaction['nominal_code'] ?? '') === self::DIVIDENDS_PAYABLE_CODE;
    }

    private function transactionIdFromDividendSourceRef(string $sourceRef): int
    {
        return preg_match('/^dividend:transaction:(\d+)$/', trim($sourceRef), $matches) === 1
            ? (int)$matches[1]
            : 0;
    }

    private function fetchCompany(int $companyId): array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id,
                    company_name,
                    companies_house_officers_json
             FROM companies
             WHERE id = :company_id
             LIMIT 1',
            ['company_id' => $companyId]
        );

        return is_array($row) ? $row : [];
    }

    private function directorNameFromCompany(array $company): string
    {
        $payload = json_decode((string)($company['companies_house_officers_json'] ?? ''), true);
        foreach ((array)($payload['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (strtolower(trim((string)($item['officer_role'] ?? ''))) !== 'director') {
                continue;
            }
            if (trim((string)($item['resigned_on'] ?? '')) !== '') {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    private function shareholderNameFromTransaction(?array $transaction): string
    {
        if (!is_array($transaction)) {
            return '';
        }

        $counterparty = trim((string)($transaction['counterparty_name'] ?? ''));
        return $counterparty !== '' ? $counterparty : trim((string)($transaction['description'] ?? ''));
    }

    private function voucherText(string $companyName, string $shareholderName, string $date, float $amount, string $description): string
    {
        return trim($companyName . "\n"
            . 'Dividend voucher' . "\n"
            . 'Date: ' . $date . "\n"
            . 'Shareholder: ' . $shareholderName . "\n"
            . 'Dividend amount: ' . number_format($amount, 2, '.', '') . "\n"
            . 'Description: ' . $description);
    }

    private function minutesText(string $companyName, string $directorName, string $date, float $amount, string $description): string
    {
        return trim('Minutes of a meeting of the sole director of ' . $companyName . "\n"
            . 'Date: ' . $date . "\n\n"
            . $directorName . ' considered the company records and available distributable reserves. '
            . 'It was resolved that an interim dividend of ' . number_format($amount, 2, '.', '')
            . ' be declared and recorded as "' . $description . '". '
            . 'The director authorised the dividend voucher and company records to be kept.');
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $cache[$table] = \InterfaceDB::tableExists($table);
        } catch (\Throwable) {
            $cache[$table] = false;
        }

        return $cache[$table];
    }

    private function isPeriodLocked(int $companyId, int $accountingPeriodId): bool
    {
        return (new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $accountingPeriodId);
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND id = :accounting_period_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function retainedEarningsBroughtForward(int $companyId, int $accountingPeriodId, string $periodStart): array
    {
        $previousPeriod = $this->fetchPreviousAccountingPeriod($companyId, $periodStart);
        if ($previousPeriod === null) {
            return [
                'reliable' => true,
                'amount' => 0.0,
                'status' => 'first_period_zero',
                'detail' => 'This is the first recorded accounting period, so retained earnings brought forward are treated as zero.',
            ];
        }

        $previousPeriodId = (int)($previousPeriod['id'] ?? 0);
        $review = (new \eel_accounts\Service\YearEndLockService())->fetchReview($companyId, $previousPeriodId);
        if (!is_array($review) || (int)($review['is_locked'] ?? 0) !== 1) {
            return [
                'reliable' => false,
                'amount' => 0.0,
                'status' => 'prior_period_not_locked',
                'detail' => 'Dividend declaration is blocked until the prior accounting period is locked.',
            ];
        }

        $closeJournal = (new \eel_accounts\Service\ManualJournalService())->fetchJournalByTag(
            $companyId,
            $previousPeriodId,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
            \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_KEY
        );
        if (!is_array($closeJournal)) {
            return [
                'reliable' => false,
                'amount' => 0.0,
                'status' => 'prior_close_missing',
                'detail' => 'Dividend declaration is blocked until the prior period retained earnings close journal exists.',
            ];
        }

        $closeContext = (new \eel_accounts\Service\RetainedEarningsCloseService())->fetchContext($companyId, $previousPeriodId);
        if (!empty($closeContext['available'])) {
            if (empty($closeContext['acknowledged'])) {
                return [
                    'reliable' => false,
                    'amount' => 0.0,
                    'status' => 'prior_close_unacknowledged',
                    'detail' => 'Dividend declaration is blocked until the prior period retained earnings close is acknowledged.',
                ];
            }
            if (!empty($closeContext['acknowledgement_stale'])) {
                return [
                    'reliable' => false,
                    'amount' => 0.0,
                    'status' => 'prior_close_stale',
                    'detail' => 'Dividend declaration is blocked because the prior period retained earnings close is stale.',
                ];
            }
        }

        $priorReserveReview = (new \eel_accounts\Service\DividendReserveClassificationService())->fetchReviewContext(
            $companyId,
            $previousPeriodId,
            (string)($previousPeriod['period_end'] ?? '')
        );
        if (empty($priorReserveReview['available']) || empty($priorReserveReview['snapshot_current'])) {
            $status = empty($priorReserveReview['available']) ? 'prior_classification_unavailable' : (string)($priorReserveReview['status'] ?? 'missing');
            return [
                'reliable' => false,
                'amount' => 0.0,
                'status' => $status === 'stale' ? 'prior_classification_stale' : 'prior_close_unclassified',
                'detail' => $status === 'stale'
                    ? 'Dividend declaration is blocked because the prior period distributable reserve review is stale.'
                    : 'Dividend declaration is blocked until the prior period distributable reserve review is current.',
            ];
        }

        $priorSnapshot = (array)($priorReserveReview['snapshot'] ?? []);
        if (!array_key_exists('closing_distributable_reserves', $priorSnapshot)) {
            return [
                'reliable' => false,
                'amount' => 0.0,
                'status' => 'prior_distributable_snapshot_legacy',
                'detail' => 'Dividend declaration is blocked until the prior period distributable reserve review is resaved with roll-forward values.',
            ];
        }

        return [
            'reliable' => true,
            'amount' => round((float)($priorSnapshot['closing_distributable_reserves'] ?? 0), 2),
            'status' => 'locked_prior_distributable_snapshot',
            'detail' => 'Distributable reserves brought forward are based on the locked prior-period reserve review.',
        ];
    }

    private function fetchPreviousAccountingPeriod(int $companyId, string $periodStart): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'period_start' => $periodStart,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function retainedEarningsBalanceBefore(int $companyId, int $retainedEarningsNominalId, string $periodStart): float
    {
        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND j.journal_date < :period_start
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => $companyId,
                'period_start' => $periodStart,
                'nominal_account_id' => $retainedEarningsNominalId,
            ]
        ), 2);
    }

    private function findNominalByCode(string $code): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, is_active
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => $code]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchDividendPaymentTransaction(int $transactionId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT t.id,
                    t.company_id,
                    t.accounting_period_id,
                    t.txn_date,
                    t.description,
                    t.counterparty_name,
                    t.amount,
                    COALESCE(t.notes, \'\') AS notes,
                    t.nominal_account_id,
                    t.is_internal_transfer,
                    t.category_status,
                    COALESCE(na.code, \'\') AS nominal_code
             FROM transactions t
             LEFT JOIN nominal_accounts na ON na.id = t.nominal_account_id
             WHERE t.id = :transaction_id
             LIMIT 1',
            ['transaction_id' => $transactionId]
        );

        return is_array($row) ? $row : null;
    }

    private function findSubtypeIdForCode(string $code, string $accountType): int
    {
        if ($code === '' || $accountType === '') {
            return 0;
        }

        return (int)(\InterfaceDB::fetchColumn(
            'SELECT id
             FROM nominal_account_subtypes
             WHERE code = :code
               AND parent_account_type = :account_type
             LIMIT 1',
            [
                'code' => $code,
                'account_type' => $accountType,
            ]
        ) ?: 0);
    }

    private function profitForPeriod(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): float
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             LEFT JOIN journal_entry_metadata jem_close
               ON jem_close.journal_id = j.id
              AND jem_close.journal_tag = :close_journal_tag
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :as_at_date
               AND jem_close.id IS NULL
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.account_type',
            [
                'close_journal_tag' => \eel_accounts\Service\RetainedEarningsCloseService::JOURNAL_TAG,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'as_at_date' => $asAtDate,
                'income_type' => 'income',
                'cost_type' => 'cost_of_sales',
                'expense_type' => 'expense',
            ]
        );

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

        return round($income - $expenses, 2);
    }

    private function dividendsDeclaredForPeriod(int $companyId, int $accountingPeriodId, string $periodStart, string $asAtDate): float
    {
        $nominal = $this->findNominalByCode(self::DIVIDENDS_PAID_CODE);
        $nominalId = (int)($nominal['id'] ?? 0);
        if ($nominalId <= 0) {
            return 0.0;
        }

        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND (j.is_posted = 1 OR (j.source_type = :draft_source_type AND j.source_ref LIKE :draft_source_ref))
               AND j.journal_date BETWEEN :period_start AND :as_at_date
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'period_start' => $periodStart,
                'as_at_date' => $asAtDate,
                'nominal_account_id' => $nominalId,
                'draft_source_type' => 'manual',
                'draft_source_ref' => 'dividend:%',
            ]
        ), 2);
    }

    private function findJournalId(int $companyId, string $sourceRef): int
    {
        return (int)(\InterfaceDB::fetchColumn(
            'SELECT id
             FROM journals
             WHERE company_id = :company_id
               AND source_type = :source_type
               AND source_ref = :source_ref
             ORDER BY id DESC
             LIMIT 1',
            [
                'company_id' => $companyId,
                'source_type' => 'manual',
                'source_ref' => $sourceRef,
            ]
        ) ?: 0);
    }

    private function insertJournalLine(int $journalId, int $nominalAccountId, float $debit, float $credit, string $description): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (
                journal_id,
                nominal_account_id,
                company_account_id,
                debit,
                credit,
                line_description
             ) VALUES (?, ?, NULL, ?, ?, ?)',
            [
                $journalId,
                $nominalAccountId,
                number_format($debit, 2, '.', ''),
                number_format($credit, 2, '.', ''),
                $description,
            ]
        );
    }

    private function dividendAmountForJournal(int $journalId, int $dividendsPaidId): float
    {
        if ($dividendsPaidId > 0) {
            return round((float)\InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(COALESCE(debit, 0) - COALESCE(credit, 0)), 0)
                 FROM journal_lines
                 WHERE journal_id = :journal_id
                   AND nominal_account_id = :nominal_account_id',
                [
                    'journal_id' => $journalId,
                    'nominal_account_id' => $dividendsPaidId,
                ]
            ), 2);
        }

        return round((float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(MAX(debit), 0)
             FROM journal_lines
             WHERE journal_id = :journal_id',
            ['journal_id' => $journalId]
        ), 2);
    }

    private function settlementAccountForJournal(int $journalId, int $dividendsPaidId): string
    {
        $sql = 'SELECT COALESCE(na.code, \'\') AS code,
                       COALESCE(na.name, \'\') AS name
                FROM journal_lines jl
                LEFT JOIN nominal_accounts na ON na.id = jl.nominal_account_id
                WHERE jl.journal_id = :journal_id
                  AND jl.credit > 0';
        $params = ['journal_id' => $journalId];

        if ($dividendsPaidId > 0) {
            $sql .= ' AND jl.nominal_account_id <> :dividends_paid_id';
            $params['dividends_paid_id'] = $dividendsPaidId;
        }

        $sql .= ' ORDER BY jl.credit DESC, jl.id ASC LIMIT 1';
        $row = \InterfaceDB::fetchOne($sql, $params);
        if (!is_array($row)) {
            return '';
        }

        return trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
    }

    private function sourceRef(int $companyId, int $accountingPeriodId, string $declarationDate): string
    {
        $date = str_replace('-', '', $declarationDate);
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        return 'dividend:' . $companyId . ':' . $accountingPeriodId . ':' . $date . ':' . $suffix;
    }

    private function transactionDividendSourceRef(int $transactionId): string
    {
        return 'dividend:transaction:' . $transactionId;
    }

    private function actorValue(string $changedBy): string
    {
        $changedBy = trim($changedBy);
        return $changedBy !== '' ? substr($changedBy, 0, 100) : 'web_app';
    }

    private function effectiveAsAtDate(?string $asAtDate, string $periodStart, string $periodEnd): string
    {
        $value = trim((string)($asAtDate ?? ''));
        if (!$this->isValidDate($value)) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            return $today > $periodEnd ? $periodEnd : $today;
        }
        if ($value < $periodStart) {
            return $periodStart;
        }
        if ($value > $periodEnd) {
            return $periodEnd;
        }

        return $value;
    }

    private function accountingPeriodEndsAfterToday(array $accountingPeriod): bool
    {
        $periodEnd = (string)($accountingPeriod['period_end'] ?? '');
        return $periodEnd !== '' && $periodEnd > (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function dateInsidePeriod(string $date, string $periodStart, string $periodEnd): bool
    {
        return $this->isValidDate($date) && $date >= $periodStart && $date <= $periodEnd;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === trim($value);
    }

    private function settlementLabel(string $settlementTarget): string
    {
        return $settlementTarget === 'director_loan_liability'
            ? 'Settled to director loan liability'
            : 'Unpaid dividend liability';
    }
}
