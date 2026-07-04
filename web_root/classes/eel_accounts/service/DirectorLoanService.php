<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class DirectorLoanService
{
    /** @var array<string, array<int, string>> */
    private array $tableColumns = [];

    public function fetchPeriods(int $companyId): array {
        $validation = $this->validateCompany($companyId);
        if ($validation !== null) {
            return $validation;
        }

        $stmt = \InterfaceDB::prepareExecute(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE company_id = :company_id
             ORDER BY period_start DESC, id DESC',
            ['company_id' => $companyId]
        );
        $periods = [];

        foreach ($stmt->fetchAll() ?: [] as $row) {
            $periods[] = [
                'id' => (int)($row['id'] ?? 0),
                'label' => (string)($row['label'] ?? ''),
                'period_start' => (string)($row['period_start'] ?? ''),
                'period_end' => (string)($row['period_end'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'periods' => $periods,
            'selected_accounting_period_id' => count($periods) > 0 ? (int)$periods[0]['id'] : 0,
            'accounting_period_id' => count($periods) > 0 ? (int)$periods[0]['id'] : 0,
        ];
    }

    public function fetchStatement(int $companyId, int $accountingPeriodId): array {
        $validation = $this->validateCompany($companyId);
        if ($validation !== null) {
            return $validation;
        }

        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($accountingPeriod === null) {
            return $this->errorResult('The selected accounting period could not be found for this company.', 'accounting_period_not_found', 404);
        }

        $settings = $this->fetchCompanySettings($companyId);
        $assetNominal = $this->directorLoanNominal($settings, 'asset');
        $liabilityNominal = $this->directorLoanNominal($settings, 'liability');
        $errors = [];
        if ($assetNominal === null) {
            $errors[] = 'Director Loan Asset nominal could not be resolved from Company Settings, subtype director_loan_asset, or code 1200.';
        }
        if ($liabilityNominal === null) {
            $errors[] = 'Director Loan Liability nominal could not be resolved from Company Settings, legacy Director Loan setting, subtype director_loan_liability, or code 2100.';
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'error_code' => 'director_loan_nominal_missing',
                'status' => 422,
                'accounting_period' => [
                    'id' => (int)$accountingPeriod['id'],
                    'label' => (string)$accountingPeriod['label'],
                    'period_start' => (string)$accountingPeriod['period_start'],
                    'period_end' => (string)$accountingPeriod['period_end'],
                ],
                'asset_nominal' => $assetNominal,
                'liability_nominal' => $liabilityNominal,
            ];
        }

        $assetNominal = (array)$assetNominal;
        $liabilityNominal = (array)$liabilityNominal;
        $assetOpeningReceivable = round(-1 * $this->fetchOpeningBalance($companyId, (int)$assetNominal['id'], (string)$accountingPeriod['period_start']), 2);
        $liabilityOpeningPayable = round($this->fetchOpeningBalance($companyId, (int)$liabilityNominal['id'], (string)$accountingPeriod['period_start']), 2);
        $movementRows = array_merge(
            $this->fetchMovementRows(
                $companyId,
                (int)$assetNominal['id'],
                (string)$accountingPeriod['period_start'],
                (string)$accountingPeriod['period_end'],
                'asset',
                $assetNominal
            ),
            $this->fetchMovementRows(
                $companyId,
                (int)$liabilityNominal['id'],
                (string)$accountingPeriod['period_start'],
                (string)$accountingPeriod['period_end'],
                'liability',
                $liabilityNominal
            )
        );
        usort($movementRows, static function (array $left, array $right): int {
            return [
                (string)($left['journal_date'] ?? ''),
                (int)($left['journal_id'] ?? 0),
                (int)($left['journal_line_id'] ?? 0),
            ] <=> [
                (string)($right['journal_date'] ?? ''),
                (int)($right['journal_id'] ?? 0),
                (int)($right['journal_line_id'] ?? 0),
            ];
        });

        $statementRows = [[
            'row_type' => 'opening_balance',
            'journal_id' => null,
            'journal_line_id' => null,
            'journal_date' => (string)$accountingPeriod['period_start'],
            'description' => 'Balance brought forward',
            'source_type' => null,
            'signed_amount' => null,
            'running_balance' => round($liabilityOpeningPayable - $assetOpeningReceivable, 2),
            'account_label' => 'Combined',
        ]];

        $runningBalance = round($liabilityOpeningPayable - $assetOpeningReceivable, 2);
        $movementInPeriod = 0.0;
        $assetMovementReceivable = 0.0;
        $liabilityMovementPayable = 0.0;

        foreach ($movementRows as $row) {
            $signedAmount = round((float)$row['signed_amount'], 2);
            $movementInPeriod += $signedAmount;
            $runningBalance += $signedAmount;
            if ((string)($row['nominal_role'] ?? '') === 'asset') {
                $assetMovementReceivable += (float)($row['normal_amount'] ?? 0);
            } elseif ((string)($row['nominal_role'] ?? '') === 'liability') {
                $liabilityMovementPayable += (float)($row['normal_amount'] ?? 0);
            }

            $statementRows[] = [
                'row_type' => 'movement',
                'journal_id' => (int)$row['journal_id'],
                'journal_line_id' => (int)$row['journal_line_id'],
                'journal_date' => (string)$row['journal_date'],
                'description' => (string)$row['description'],
                'source_type' => (string)$row['source_type'],
                'signed_amount' => $signedAmount,
                'running_balance' => round($runningBalance, 2),
                'normal_amount' => round((float)($row['normal_amount'] ?? 0), 2),
                'nominal_role' => (string)($row['nominal_role'] ?? ''),
                'nominal_account_id' => (int)($row['nominal_account_id'] ?? 0),
                'nominal_code' => (string)($row['nominal_code'] ?? ''),
                'nominal_name' => (string)($row['nominal_name'] ?? ''),
                'account_label' => (string)($row['account_label'] ?? ''),
            ];
        }

        $movementInPeriod = round($movementInPeriod, 2);
        $assetReceivable = round($assetOpeningReceivable + $assetMovementReceivable, 2);
        $liabilityPayable = round($liabilityOpeningPayable + $liabilityMovementPayable, 2);
        $closingBalance = round($liabilityPayable - $assetReceivable, 2);
        $currencySymbol = (new \eel_accounts\Service\CompanySettingsService())->defaultCurrencySymbol($settings);

        return [
            'success' => true,
            'accounting_period' => [
                'id' => (int)$accountingPeriod['id'],
                'label' => (string)$accountingPeriod['label'],
                'period_start' => (string)$accountingPeriod['period_start'],
                'period_end' => (string)$accountingPeriod['period_end'],
            ],
            'director_loan_nominal' => [
                'id' => (int)($liabilityNominal['id'] ?? 0),
                'code' => (string)($liabilityNominal['code'] ?? ''),
                'name' => (string)($liabilityNominal['name'] ?? ''),
                'account_type' => (string)($liabilityNominal['account_type'] ?? ''),
            ],
            'asset_nominal' => $assetNominal,
            'liability_nominal' => $liabilityNominal,
            'asset_opening_receivable' => $assetOpeningReceivable,
            'liability_opening_payable' => $liabilityOpeningPayable,
            'asset_movement_receivable' => round($assetMovementReceivable, 2),
            'liability_movement_payable' => round($liabilityMovementPayable, 2),
            'asset_receivable' => $assetReceivable,
            'liability_payable' => $liabilityPayable,
            'net_position' => $closingBalance,
            'net_position_label' => $this->balanceDirectionLabel($closingBalance),
            'opening_balance' => round($liabilityOpeningPayable - $assetOpeningReceivable, 2),
            'movement_in_period' => $movementInPeriod,
            'closing_balance' => $closingBalance,
            'balance_direction' => $this->balanceDirection($closingBalance),
            'balance_direction_label' => $this->balanceDirectionLabel($closingBalance),
            'statement_rows' => $statementRows,
            'has_movements_in_period' => count($movementRows) > 0,
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
            'default_currency' => (string)($settings['default_currency'] ?? 'GBP'),
            'default_currency_symbol' => $currencySymbol,
        ];
    }

    public function fetchTaxReview(int $companyId, int $accountingPeriodId): array {
        $statement = $this->fetchStatement($companyId, $accountingPeriodId);
        if (empty($statement['success'])) {
            return [
                'available' => false,
                'success' => false,
                'errors' => (array)($statement['errors'] ?? ['Director loan statement is not available.']),
                'statement' => $statement,
            ];
        }

        $closingBalance = round((float)($statement['closing_balance'] ?? 0), 2);
        $directorOwesCompany = $closingBalance < -0.004;
        $exposureAmount = $directorOwesCompany ? abs($closingBalance) : 0.0;
        $periodEnd = (string)(($statement['accounting_period'] ?? [])['period_end'] ?? '');
        $repaymentReviewDate = $this->repaymentReviewDate($periodEnd);
        $reviewItems = [];

        if ($directorOwesCompany) {
            $reviewItems[] = [
                'key' => 's455',
                'label' => 's455 corporation tax review',
                'detail' => 'Director owes the company at period end. Review whether s455 tax is due and how any later repayment affects the position.',
                'severity' => 'warning',
            ];
            $reviewItems[] = [
                'key' => 'repayment_timing',
                'label' => 'Repayment timing',
                'detail' => $repaymentReviewDate !== ''
                    ? 'Check whether the loan is repaid or released by ' . $repaymentReviewDate . '.'
                    : 'Check repayment timing before relying on the closing position.',
                'severity' => 'warning',
            ];
            $reviewItems[] = [
                'key' => 'beneficial_loan_interest',
                'label' => 'Beneficial loan interest / BIK review',
                'detail' => 'Review whether the balance creates a taxable benefit or interest reporting issue.',
                'severity' => 'warning',
            ];
            $reviewItems[] = [
                'key' => 'write_off',
                'label' => 'Write-off or waiver review',
                'detail' => 'If any balance is written off or waived, review payroll, dividend, and corporation tax treatment before filing.',
                'severity' => 'warning',
            ];
            $reviewItems[] = [
                'key' => 'ct600_supplementary',
                'label' => 'CT600 supplementary review',
                'detail' => 'Review whether supplementary CT600 director loan disclosures are required.',
                'severity' => 'warning',
            ];
        }

        return [
            'available' => true,
            'success' => true,
            'status' => $directorOwesCompany ? 'review_required' : 'no_director_receivable',
            'status_label' => $directorOwesCompany ? 'Review required' : 'No director receivable',
            'review_required' => $directorOwesCompany,
            'director_owes_company' => $directorOwesCompany,
            'closing_balance' => $closingBalance,
            'exposure_amount' => round($exposureAmount, 2),
            'repayment_review_date' => $repaymentReviewDate,
            's455_review_required' => $directorOwesCompany,
            'repayment_timing_review_required' => $directorOwesCompany,
            'beneficial_loan_interest_review_required' => $directorOwesCompany,
            'write_off_review_required' => $directorOwesCompany,
            'ct600_supplementary_review_required' => $directorOwesCompany,
            'review_items' => $reviewItems,
            'statement' => $statement,
        ];
    }

    private function fetchOpeningBalance(int $companyId, int $nominalAccountId, string $periodStart): float {
        if ($this->tableExists('journal_entry_metadata')) {
            $stmt = \InterfaceDB::prepareExecute(
                'SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
                 FROM journals j
                 INNER JOIN journal_lines jl ON jl.journal_id = j.id
                 LEFT JOIN journal_entry_metadata jem ON jem.journal_id = j.id
                 WHERE j.company_id = :company_id
                   AND j.is_posted = 1
                   AND jl.nominal_account_id = :nominal_account_id
                   AND (
                        j.journal_date < :period_start_before
                        OR (j.journal_date = :period_start_on AND COALESCE(jem.journal_tag, \'\') = :opening_balance_tag)
                   )',
                [
                'company_id' => $companyId,
                'nominal_account_id' => $nominalAccountId,
                'period_start_before' => $periodStart,
                'period_start_on' => $periodStart,
                'opening_balance_tag' => 'opening_balance',
                ]
            );

            return round((float)$stmt->fetchColumn(), 2);
        }

        $stmt = \InterfaceDB::prepareExecute(
            'SELECT COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND jl.nominal_account_id = :nominal_account_id
               AND (
                    j.journal_date < :period_start_before
                    OR (j.journal_date = :period_start_on AND COALESCE(j.source_ref, \'\') LIKE :opening_balance_ref)
               )',
            [
            'company_id' => $companyId,
            'nominal_account_id' => $nominalAccountId,
            'period_start_before' => $periodStart,
            'period_start_on' => $periodStart,
            'opening_balance_ref' => 'meta:opening_balance:%',
            ]
        );

        return round((float)$stmt->fetchColumn(), 2);
    }

    private function fetchMovementRows(
        int $companyId,
        int $nominalAccountId,
        string $periodStart,
        string $periodEnd,
        string $nominalRole,
        array $nominal
    ): array {
        $stmt = \InterfaceDB::prepareExecute(
            'SELECT j.id AS journal_id,
                    j.journal_date,
                    j.description AS journal_description,
                    j.source_type,
                    jl.id AS journal_line_id,
                    jl.debit,
                    jl.credit,
                    jl.line_description
             FROM journals j
             INNER JOIN journal_lines jl ON jl.journal_id = j.id
             WHERE j.company_id = :company_id
               AND j.is_posted = 1
               AND jl.nominal_account_id = :nominal_account_id
               AND j.journal_date BETWEEN :period_start AND :period_end
             ORDER BY j.journal_date ASC, j.id ASC, jl.id ASC',
            [
            'company_id' => $companyId,
            'nominal_account_id' => $nominalAccountId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            ]
        );

        $rows = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $rows[] = [
                'journal_id' => (int)($row['journal_id'] ?? 0),
                'journal_line_id' => (int)($row['journal_line_id'] ?? 0),
                'journal_date' => (string)($row['journal_date'] ?? ''),
                'description' => $this->buildStatementDescription(
                    (string)($row['journal_description'] ?? ''),
                    (string)($row['line_description'] ?? '')
                ),
                'source_type' => (string)($row['source_type'] ?? ''),
                'signed_amount' => ((float)($row['credit'] ?? 0)) - ((float)($row['debit'] ?? 0)),
                'normal_amount' => $nominalRole === 'asset'
                    ? ((float)($row['debit'] ?? 0)) - ((float)($row['credit'] ?? 0))
                    : ((float)($row['credit'] ?? 0)) - ((float)($row['debit'] ?? 0)),
                'nominal_role' => $nominalRole,
                'nominal_account_id' => (int)($nominal['id'] ?? $nominalAccountId),
                'nominal_code' => (string)($nominal['code'] ?? ''),
                'nominal_name' => (string)($nominal['name'] ?? ''),
                'account_label' => \FormattingFramework::nominalLabel($nominal),
            ];
        }

        return $rows;
    }

    private function directorLoanNominal(array $settings, string $role): ?array
    {
        if ($role === 'asset') {
            $configuredId = $this->positiveInt($settings['director_loan_asset_nominal_id'] ?? 0);
            $configured = $configuredId > 0 ? $this->fetchNominalAccount($configuredId, 'asset') : null;
            if ($configured !== null) {
                return $configured;
            }

            return $this->fetchNominalBySubtype('director_loan_asset', 'asset')
                ?? $this->fetchNominalByCode('1200', 'asset');
        }

        $configuredId = $this->positiveInt($settings['director_loan_liability_nominal_id'] ?? 0);
        if ($configuredId <= 0) {
            $configuredId = $this->positiveInt($settings['director_loan_nominal_id'] ?? 0);
        }
        $configured = $configuredId > 0 ? $this->fetchNominalAccount($configuredId, 'liability') : null;
        if ($configured !== null) {
            return $configured;
        }

        return $this->fetchNominalBySubtype('director_loan_liability', 'liability')
            ?? $this->fetchNominalByCode('2100', 'liability');
    }

    private function buildStatementDescription(string $journalDescription, string $lineDescription): string {
        $journalDescription = trim($journalDescription);
        $lineDescription = trim($lineDescription);

        if ($lineDescription === '' || strcasecmp($journalDescription, $lineDescription) === 0) {
            return $journalDescription;
        }

        return $journalDescription === ''
            ? $lineDescription
            : $journalDescription . ' - ' . $lineDescription;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $stmt = \InterfaceDB::prepareExecute(
            'SELECT id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            ]
        );
        $accountingPeriod = $stmt->fetch();

        return is_array($accountingPeriod) ? $accountingPeriod : null;
    }

    private function fetchNominalAccount(int $nominalAccountId, ?string $accountType = null): ?array {
        if ($nominalAccountId <= 0) {
            return null;
        }

        $stmt = \InterfaceDB::prepareExecute(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    na.is_active,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.id = :id
               AND (:account_type = \'\' OR na.account_type = :account_type_match)
             LIMIT 1',
            [
                'id' => $nominalAccountId,
                'account_type' => (string)($accountType ?? ''),
                'account_type_match' => (string)($accountType ?? ''),
            ]
        );
        $nominal = $stmt->fetch();

        return is_array($nominal) && (int)($nominal['is_active'] ?? 0) === 1 ? $nominal : null;
    }

    private function fetchNominalBySubtype(string $subtypeCode, string $accountType): ?array
    {
        if (!$this->tableExists('nominal_account_subtypes')) {
            return null;
        }

        $nominal = \InterfaceDB::fetchOne(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    na.is_active,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             INNER JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE nas.code = :subtype_code
               AND na.account_type = :account_type
               AND COALESCE(na.is_active, 0) = 1
               AND COALESCE(nas.is_active, 0) = 1
             ORDER BY na.sort_order ASC, na.code ASC, na.id ASC
             LIMIT 1',
            [
                'subtype_code' => $subtypeCode,
                'account_type' => $accountType,
            ]
        );

        return is_array($nominal) ? $nominal : null;
    }

    private function fetchNominalByCode(string $code, string $accountType): ?array
    {
        $nominal = \InterfaceDB::fetchOne(
            'SELECT na.id,
                    na.code,
                    na.name,
                    na.account_type,
                    na.is_active,
                    COALESCE(nas.code, \'\') AS subtype_code
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE na.code = :code
               AND na.account_type = :account_type
               AND COALESCE(na.is_active, 0) = 1
             LIMIT 1',
            [
                'code' => $code,
                'account_type' => $accountType,
            ]
        );

        return is_array($nominal) ? $nominal : null;
    }

    private function fetchCompanySettings(int $companyId): array {
        if ($companyId <= 0 || !$this->tableExists('company_settings')) {
            return [];
        }

        $stmt = \InterfaceDB::prepareExecute(
            'SELECT setting, type, value
             FROM company_settings
             WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );

        $settings = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $setting = trim((string)($row['setting'] ?? ''));
            if ($setting === '') {
                continue;
            }

            $type = strtolower(trim((string)($row['type'] ?? '')));
            $value = $row['value'] ?? null;
            $settings[$setting] = match ($type) {
                'int', 'integer' => is_numeric($value) ? (int)$value : 0,
                'bool', 'boolean' => (int)$value === 1,
                default => (string)$value,
            };
        }

        return $settings;
    }

    private function positiveInt(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        $value = trim((string)$value);
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        return max(0, (int)$value);
    }

    private function validateCompany(int $companyId): ?array {
        if ($companyId <= 0) {
            return $this->errorResult('Select a company first.', 'company_required', 422);
        }

        $stmt = \InterfaceDB::prepareExecute( 'SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId]);
        $company = $stmt->fetch();

        if (!is_array($company)) {
            return $this->errorResult('The selected company could not be found.', 'company_not_found', 404);
        }

        if (array_key_exists('is_active', $company) && (int)$company['is_active'] !== 1) {
            return $this->errorResult('The selected company is not active.', 'company_inactive', 422);
        }

        return null;
    }

    private function balanceDirection(float $balance): string {
        if ($balance > 0) {
            return 'company_owes_director';
        }

        if ($balance < 0) {
            return 'director_owes_company';
        }

        return 'settled';
    }

    private function balanceDirectionLabel(float $balance): string {
        return match ($this->balanceDirection($balance)) {
            'company_owes_director' => 'Company owes director',
            'director_owes_company' => 'Director owes company',
            default => 'Settled',
        };
    }

    private function repaymentReviewDate(string $periodEnd): string {
        if (trim($periodEnd) === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($periodEnd))
                ->modify('+9 months +1 day')
                ->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function errorResult(string $message, string $code, int $status): array {
        return [
            'success' => false,
            'errors' => [$message],
            'error_code' => $code,
            'status' => $status,
        ];
    }

    private function tableExists(string $tableName): bool {
        try {
            $this->fetchTableColumns($tableName);
            return true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    private function fetchTableColumns(string $tableName): array {
        if (isset($this->tableColumns[$tableName])) {
            return $this->tableColumns[$tableName];
        }

        if (!\InterfaceDB::tableExists($tableName)) {
            throw new \RuntimeException('Required table not found: ' . $tableName);
        }
        $this->tableColumns[$tableName] = [];

        return $this->tableColumns[$tableName];
    }
}

