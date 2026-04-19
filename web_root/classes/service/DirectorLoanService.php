<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class DirectorLoanService
{
    /** @var array<string, array<int, string>> */
    private array $tableColumns = [];

    public function fetchPeriods(int $companyId): array {
        $validation = $this->validateCompany($companyId);
        if ($validation !== null) {
            return $validation;
        }

        $stmt = InterfaceDB::prepareExecute(
            'SELECT id, label, period_start, period_end
             FROM tax_years
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
            'selected_tax_year_id' => count($periods) > 0 ? (int)$periods[0]['id'] : 0,
        ];
    }

    public function fetchStatement(int $companyId, int $taxYearId): array {
        $validation = $this->validateCompany($companyId);
        if ($validation !== null) {
            return $validation;
        }

        $taxYear = $this->fetchTaxYear($companyId, $taxYearId);
        if ($taxYear === null) {
            return $this->errorResult('The selected accounting period could not be found for this company.', 'tax_year_not_found', 404);
        }

        $settings = $this->fetchCompanySettings($companyId);
        $directorLoanNominalId = (int)($settings['director_loan_nominal_id'] ?? 0);
        if ($directorLoanNominalId <= 0) {
            return $this->errorResult('No Director Loan nominal has been configured in Company Settings.', 'director_loan_nominal_missing', 422);
        }

        $nominal = $this->fetchNominalAccount($directorLoanNominalId);
        if ($nominal === null || (array_key_exists('is_active', $nominal) && (int)$nominal['is_active'] !== 1)) {
            return $this->errorResult('The configured Director Loan nominal could not be found or is inactive.', 'director_loan_nominal_invalid', 422);
        }

        $openingBalance = $this->fetchOpeningBalance($companyId, $directorLoanNominalId, (string)$taxYear['period_start']);
        $movementRows = $this->fetchMovementRows(
            $companyId,
            $directorLoanNominalId,
            (string)$taxYear['period_start'],
            (string)$taxYear['period_end']
        );

        $statementRows = [[
            'row_type' => 'opening_balance',
            'journal_id' => null,
            'journal_line_id' => null,
            'journal_date' => (string)$taxYear['period_start'],
            'description' => 'Balance brought forward',
            'source_type' => null,
            'signed_amount' => null,
            'running_balance' => round($openingBalance, 2),
        ]];

        $runningBalance = $openingBalance;
        $movementInPeriod = 0.0;

        foreach ($movementRows as $row) {
            $signedAmount = round((float)$row['signed_amount'], 2);
            $movementInPeriod += $signedAmount;
            $runningBalance += $signedAmount;

            $statementRows[] = [
                'row_type' => 'movement',
                'journal_id' => (int)$row['journal_id'],
                'journal_line_id' => (int)$row['journal_line_id'],
                'journal_date' => (string)$row['journal_date'],
                'description' => (string)$row['description'],
                'source_type' => (string)$row['source_type'],
                'signed_amount' => $signedAmount,
                'running_balance' => round($runningBalance, 2),
                'nominal_account_id' => (int)$directorLoanNominalId,
                'nominal_code' => (string)($nominal['code'] ?? ''),
                'nominal_name' => (string)($nominal['name'] ?? ''),
            ];
        }

        $movementInPeriod = round($movementInPeriod, 2);
        $closingBalance = round($openingBalance + $movementInPeriod, 2);
        $currencySymbol = html_entity_decode((string)($settings['default_currency_symbol'] ?? '&#163;'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return [
            'success' => true,
            'selected_tax_year' => [
                'id' => (int)$taxYear['id'],
                'label' => (string)$taxYear['label'],
                'period_start' => (string)$taxYear['period_start'],
                'period_end' => (string)$taxYear['period_end'],
            ],
            'director_loan_nominal' => [
                'id' => (int)($nominal['id'] ?? 0),
                'code' => (string)($nominal['code'] ?? ''),
                'name' => (string)($nominal['name'] ?? ''),
                'account_type' => (string)($nominal['account_type'] ?? ''),
            ],
            'opening_balance' => round($openingBalance, 2),
            'movement_in_period' => $movementInPeriod,
            'closing_balance' => $closingBalance,
            'balance_direction' => $this->balanceDirection($closingBalance),
            'balance_direction_label' => $this->balanceDirectionLabel($closingBalance),
            'statement_rows' => $statementRows,
            'has_movements_in_period' => count($movementRows) > 0,
            'date_format' => (string)($settings['date_format'] ?? 'd/m/Y'),
            'default_currency' => (string)($settings['default_currency'] ?? 'GBP'),
            'default_currency_symbol' => $currencySymbol !== '' ? $currencySymbol : '£',
        ];
    }

    private function fetchOpeningBalance(int $companyId, int $nominalAccountId, string $periodStart): float {
        if ($this->tableExists('journal_entry_metadata')) {
            $stmt = InterfaceDB::prepareExecute(
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

        $stmt = InterfaceDB::prepareExecute(
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

    private function fetchMovementRows(int $companyId, int $nominalAccountId, string $periodStart, string $periodEnd): array {
        $stmt = InterfaceDB::prepareExecute(
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
            ];
        }

        return $rows;
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

    private function fetchTaxYear(int $companyId, int $taxYearId): ?array {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return null;
        }

        $stmt = InterfaceDB::prepareExecute(
            'SELECT id, label, period_start, period_end
             FROM tax_years
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            [
            'id' => $taxYearId,
            'company_id' => $companyId,
            ]
        );
        $taxYear = $stmt->fetch();

        return is_array($taxYear) ? $taxYear : null;
    }

    private function fetchNominalAccount(int $nominalAccountId): ?array {
        if ($nominalAccountId <= 0) {
            return null;
        }

        $stmt = InterfaceDB::prepareExecute(
            'SELECT id, code, name, account_type, is_active
             FROM nominal_accounts
             WHERE id = :id
             LIMIT 1',
            ['id' => $nominalAccountId]
        );
        $nominal = $stmt->fetch();

        return is_array($nominal) ? $nominal : null;
    }

    private function fetchCompanySettings(int $companyId): array {
        if ($companyId <= 0 || !$this->tableExists('company_settings')) {
            return [];
        }

        $stmt = InterfaceDB::prepareExecute(
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

    private function validateCompany(int $companyId): ?array {
        if ($companyId <= 0) {
            return $this->errorResult('Select a company first.', 'company_required', 422);
        }

        $stmt = InterfaceDB::prepareExecute( 'SELECT * FROM companies WHERE id = :id LIMIT 1', ['id' => $companyId]);
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
            default => 'Balance settled',
        };
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
        } catch (Throwable $exception) {
            return false;
        }
    }

    private function fetchTableColumns(string $tableName): array {
        if (isset($this->tableColumns[$tableName])) {
            return $this->tableColumns[$tableName];
        }

        if (!InterfaceDB::tableExists($tableName)) {
            throw new RuntimeException('Required table not found: ' . $tableName);
        }
        $this->tableColumns[$tableName] = [];

        return $this->tableColumns[$tableName];
    }
}


