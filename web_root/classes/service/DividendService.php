<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

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
            self::RETAINED_EARNINGS_CODE => [
                'name' => 'Retained Earnings',
                'account_type' => 'equity',
                'sort_order' => 70,
            ],
            self::DIVIDENDS_PAID_CODE => [
                'name' => 'Dividends Paid',
                'account_type' => 'equity',
                'sort_order' => 71,
            ],
            self::DIVIDENDS_PAYABLE_CODE => [
                'name' => 'Dividends Payable',
                'account_type' => 'liability',
                'sort_order' => 56,
            ],
        ];

        try {
            foreach ($required as $code => $definition) {
                $existing = $this->findNominalByCode($code);
                if ($existing !== null) {
                    continue;
                }

                InterfaceDB::prepareExecute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
                     VALUES (?, ?, ?, NULL, ?, 1, ?)',
                    [
                        $code,
                        $definition['name'],
                        $definition['account_type'],
                        'allowable',
                        $definition['sort_order'],
                    ]
                );
            }

            return [
                'available' => true,
                'errors' => [],
                'accounts' => $this->dividendNominals(),
            ];
        } catch (Throwable $exception) {
            return [
                'available' => false,
                'errors' => [$exception->getMessage()],
                'accounts' => $this->dividendNominals(),
            ];
        }
    }

    public function getDividendCapacity(int $companyId, int $taxYearId, ?string $asAtDate = null): array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [
                'available' => false,
                'errors' => ['Select a company and accounting period before reviewing dividend capacity.'],
            ];
        }

        $taxYear = $this->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null) {
            return [
                'available' => false,
                'errors' => ['The selected accounting period could not be found.'],
            ];
        }

        $periodStart = (string)$taxYear['period_start'];
        $periodEnd = (string)$taxYear['period_end'];
        $effectiveDate = $this->effectiveAsAtDate($asAtDate, $periodStart, $periodEnd);
        $profit = $this->profitForPeriod($companyId, $taxYearId, $periodStart, $effectiveDate);
        $dividendsDeclared = $this->dividendsDeclaredForPeriod($companyId, $taxYearId, $periodStart, $effectiveDate);
        $retainedEarningsBroughtForward = 0.0;
        $availableReserves = round($retainedEarningsBroughtForward + $profit - $dividendsDeclared, 2);

        return [
            'available' => true,
            'errors' => [],
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'tax_year' => $taxYear,
            'as_at_date' => $effectiveDate,
            'retained_earnings_brought_forward' => $retainedEarningsBroughtForward,
            'retained_earnings_status' => 'Pending prior-period close',
            'current_year_profit_loss' => $profit,
            'dividends_declared' => $dividendsDeclared,
            'available_distributable_reserves' => $availableReserves,
            'status' => $availableReserves > 0 ? 'available' : 'blocked',
            'status_label' => $availableReserves > 0 ? 'Available reserves' : 'No distributable reserves',
            'status_badge_class' => $availableReserves > 0 ? 'success' : 'danger',
        ];
    }

    public function declareDividend(array $input): array
    {
        $companyId = (int)($input['company_id'] ?? 0);
        $taxYearId = (int)($input['tax_year_id'] ?? 0);
        $declarationDate = trim((string)($input['declaration_date'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $settlementTarget = trim((string)($input['settlement_target'] ?? ''));
        $amount = round((float)($input['amount'] ?? 0), 2);

        if ($description === '') {
            $description = 'Interim dividend';
        }

        $errors = [];
        if ($companyId <= 0 || $taxYearId <= 0) {
            $errors[] = 'Select a company and accounting period before declaring a dividend.';
        }
        if (!$this->isValidDate($declarationDate)) {
            $errors[] = 'Enter a valid declaration date.';
        }
        if ($amount <= 0) {
            $errors[] = 'Dividend amount must be greater than zero.';
        }
        if (!in_array($settlementTarget, ['director_loan_liability', 'unpaid_dividend_liability'], true)) {
            $errors[] = 'Choose a valid dividend settlement target.';
        }

        $taxYear = $companyId > 0 && $taxYearId > 0 ? $this->fetchTaxYear($companyId, $taxYearId) : null;
        if ($taxYear === null && $companyId > 0 && $taxYearId > 0) {
            $errors[] = 'The selected accounting period could not be found.';
        }
        if ($taxYear !== null && $this->dateInsidePeriod($declarationDate, (string)$taxYear['period_start'], (string)$taxYear['period_end']) === false) {
            $errors[] = 'Declaration date must fall inside the selected accounting period.';
        }

        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
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
        $settings = (new CompanySettingsStore($companyId))->all();
        $directorLoanNominalId = (int)($settings['director_loan_nominal_id'] ?? 0);
        $creditNominalId = $settlementTarget === 'director_loan_liability'
            ? $directorLoanNominalId
            : $dividendsPayableNominalId;

        if ($dividendsPaidNominalId <= 0) {
            return ['success' => false, 'errors' => ['Dividends Paid nominal account is missing.']];
        }
        if ($creditNominalId <= 0) {
            return ['success' => false, 'errors' => [$settlementTarget === 'director_loan_liability'
                ? 'Set the director loan nominal before declaring a dividend to director loan.'
                : 'Dividends Payable nominal account is missing.']];
        }

        $capacity = $this->getDividendCapacity($companyId, $taxYearId, $declarationDate);
        $availableReserves = round((float)($capacity['available_distributable_reserves'] ?? 0), 2);
        if ($availableReserves <= 0) {
            return ['success' => false, 'errors' => ['Dividend declaration is blocked because distributable reserves are not positive.']];
        }
        if ($amount > $availableReserves) {
            return ['success' => false, 'errors' => ['Dividend amount exceeds available distributable reserves.']];
        }

        try {
            (new YearEndLockService())->assertUnlocked($companyId, $taxYearId, 'declare dividends in this period');
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $sourceRef = $this->sourceRef($companyId, $taxYearId, $declarationDate);
        $ownsTransaction = !InterfaceDB::inTransaction();

        if ($ownsTransaction) {
            InterfaceDB::beginTransaction();
        }

        try {
            InterfaceDB::prepareExecute(
                'INSERT INTO journals (
                    company_id,
                    tax_year_id,
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
                    $taxYearId,
                    'manual',
                    $sourceRef,
                    $declarationDate,
                    $description,
                ]
            );

            $journalId = $this->findJournalId($companyId, $sourceRef);
            if ($journalId <= 0) {
                throw new RuntimeException('The dividend journal could not be reloaded after insert.');
            }

            $this->insertJournalLine($journalId, $dividendsPaidNominalId, $amount, 0.0, 'Dividend declared');
            $this->insertJournalLine($journalId, $creditNominalId, 0.0, $amount, $this->settlementLabel($settlementTarget));

            if ($ownsTransaction) {
                InterfaceDB::commit();
            }

            return [
                'success' => true,
                'journal_id' => $journalId,
                'source_ref' => $sourceRef,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    public function listDividends(int $companyId, int $taxYearId): array
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return [];
        }

        $dividendsPaid = $this->findNominalByCode(self::DIVIDENDS_PAID_CODE);
        $dividendsPaidId = (int)($dividendsPaid['id'] ?? 0);
        $params = [$companyId, $taxYearId, 'manual', 'dividend:%'];
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

        $stmt = InterfaceDB::prepare(
            'SELECT j.id,
                    j.journal_date,
                    j.description,
                    COALESCE(j.source_ref, \'\') AS source_ref,
                    j.is_posted,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = ?
               AND j.tax_year_id = ?
               AND (
                    (j.source_type = ? AND j.source_ref LIKE ?)' . $nominalCondition . '
               )
             GROUP BY j.id, j.journal_date, j.description, j.source_ref, j.is_posted
             ORDER BY j.journal_date DESC, j.id DESC'
        );
        if ($stmt === false) {
            return [];
        }

        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['amount'] = $this->dividendAmountForJournal((int)$row['id'], $dividendsPaidId);
            $row['settlement_account'] = $this->settlementAccountForJournal((int)$row['id'], $dividendsPaidId);
            $row['status'] = !empty($row['is_posted']) ? 'posted' : 'draft';
        }
        unset($row);

        return $rows;
    }

    public function getDividendWarnings(int $companyId, int $taxYearId): array
    {
        $warnings = [];
        if ($companyId <= 0 || $taxYearId <= 0) {
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

        if ($companyId > 0 && $taxYearId > 0) {
            $capacity = $this->getDividendCapacity($companyId, $taxYearId);
            $profit = (float)($capacity['current_year_profit_loss'] ?? 0);
            $reserves = (float)($capacity['available_distributable_reserves'] ?? 0);

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
        }

        $warnings[] = [
            'severity' => 'warning',
            'title' => 'Retained earnings pending close',
            'detail' => 'Retained earnings brought forward is currently estimated as zero until prior-period close is implemented.',
        ];
        $warnings[] = [
            'severity' => 'info',
            'title' => 'Bookkeeping workflow only',
            'detail' => 'This tool is conservative and does not replace formal legal or accounting advice.',
        ];

        return $warnings;
    }

    public function dividendNominals(): array
    {
        return [
            'retained_earnings' => $this->findNominalByCode(self::RETAINED_EARNINGS_CODE) ?? [],
            'dividends_paid' => $this->findNominalByCode(self::DIVIDENDS_PAID_CODE) ?? [],
            'dividends_payable' => $this->findNominalByCode(self::DIVIDENDS_PAYABLE_CODE) ?? [],
        ];
    }

    private function fetchTaxYear(int $companyId, int $taxYearId): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
               AND id = :tax_year_id
             LIMIT 1',
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function findNominalByCode(string $code): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, is_active
             FROM nominal_accounts
             WHERE code = :code
             LIMIT 1',
            ['code' => $code]
        );

        return is_array($row) ? $row : null;
    }

    private function profitForPeriod(int $companyId, int $taxYearId, string $periodStart, string $asAtDate): float
    {
        $rows = InterfaceDB::fetchAll(
            'SELECT na.account_type,
                    COALESCE(SUM(jl.debit), 0) AS total_debit,
                    COALESCE(SUM(jl.credit), 0) AS total_credit
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :as_at_date
               AND na.account_type IN (:income_type, :cost_type, :expense_type)
             GROUP BY na.account_type',
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
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

    private function dividendsDeclaredForPeriod(int $companyId, int $taxYearId, string $periodStart, string $asAtDate): float
    {
        $nominal = $this->findNominalByCode(self::DIVIDENDS_PAID_CODE);
        $nominalId = (int)($nominal['id'] ?? 0);
        if ($nominalId <= 0) {
            return 0.0;
        }

        return round((float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.debit, 0) - COALESCE(jl.credit, 0)), 0)
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.tax_year_id = :tax_year_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :as_at_date
               AND jl.nominal_account_id = :nominal_account_id',
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
                'period_start' => $periodStart,
                'as_at_date' => $asAtDate,
                'nominal_account_id' => $nominalId,
            ]
        ), 2);
    }

    private function findJournalId(int $companyId, string $sourceRef): int
    {
        return (int)(InterfaceDB::fetchColumn(
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
        InterfaceDB::prepareExecute(
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
            return round((float)InterfaceDB::fetchColumn(
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

        return round((float)InterfaceDB::fetchColumn(
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
        $row = InterfaceDB::fetchOne($sql, $params);
        if (!is_array($row)) {
            return '';
        }

        return trim((string)($row['code'] ?? '') . ' ' . (string)($row['name'] ?? ''));
    }

    private function sourceRef(int $companyId, int $taxYearId, string $declarationDate): string
    {
        $date = str_replace('-', '', $declarationDate);
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        return 'dividend:' . $companyId . ':' . $taxYearId . ':' . $date . ':' . $suffix;
    }

    private function effectiveAsAtDate(?string $asAtDate, string $periodStart, string $periodEnd): string
    {
        $value = trim((string)($asAtDate ?? ''));
        if (!$this->isValidDate($value)) {
            $value = (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if ($value < $periodStart) {
            return $periodStart;
        }
        if ($value > $periodEnd) {
            return $periodEnd;
        }

        return $value;
    }

    private function dateInsidePeriod(string $date, string $periodStart, string $periodEnd): bool
    {
        return $this->isValidDate($date) && $date >= $periodStart && $date <= $periodEnd;
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', trim($value));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === trim($value);
    }

    private function settlementLabel(string $settlementTarget): string
    {
        return $settlementTarget === 'director_loan_liability'
            ? 'Settled to director loan liability'
            : 'Unpaid dividend liability';
    }
}
