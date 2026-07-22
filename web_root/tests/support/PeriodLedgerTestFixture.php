<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

/** @return array<string, int|string> */
function periodLedgerTestCreateFixture(): array
{
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
    $companyNumber = 'LD' . $marker;

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        [
            'company_name' => 'Period Ledger Fixture ' . $marker,
            'company_number' => $companyNumber,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number',
        ['company_number' => $companyNumber]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'Ledger FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    $incomeNominalId = periodLedgerTestInsertNominal('LI' . $marker, 'Ledger Income ' . $marker, 'income', 'allowable');
    $costNominalId = periodLedgerTestInsertNominal('LC' . $marker, 'Ledger Cost ' . $marker, 'cost_of_sales', 'allowable');
    $expenseNominalId = periodLedgerTestInsertNominal('LE' . $marker, 'Ledger Expense ' . $marker, 'expense', 'allowable');
    $disallowableNominalId = periodLedgerTestInsertNominal('LD' . $marker, 'Ledger Disallowable ' . $marker, 'expense', 'disallowable');
    $assetNominalId = periodLedgerTestInsertNominal('LA' . $marker, 'Ledger Bank ' . $marker, 'asset', 'other');

    periodLedgerTestInsertJournal(
        $companyId,
        $accountingPeriodId,
        '2025-03-15',
        'period-ledger-base-' . $marker,
        [
            [$incomeNominalId, 0.0, 1000.0],
            [$costNominalId, 200.0, 0.0],
            [$expenseNominalId, 100.0, 0.0],
            [$disallowableNominalId, 50.0, 0.0],
            [$assetNominalId, 650.0, 0.0],
        ]
    );
    periodLedgerTestInsertJournal(
        $companyId,
        $accountingPeriodId,
        '2025-10-15',
        'period-ledger-future-' . $marker,
        [
            [$incomeNominalId, 0.0, 200.0],
            [$assetNominalId, 200.0, 0.0],
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'income_nominal_id' => $incomeNominalId,
        'cost_nominal_id' => $costNominalId,
        'expense_nominal_id' => $expenseNominalId,
        'disallowable_nominal_id' => $disallowableNominalId,
        'asset_nominal_id' => $assetNominalId,
        'period_start' => '2025-01-01',
        'period_end' => '2025-12-31',
    ];
}

function periodLedgerTestInsertNominal(string $code, string $name, string $accountType, string $taxTreatment): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

/** @param array<int, array{0: int, 1: float, 2: float}> $lines */
function periodLedgerTestInsertJournal(
    int $companyId,
    int $accountingPeriodId,
    string $journalDate,
    string $sourceRef,
    array $lines,
    string $sourceType = 'manual'
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => 'Period ledger fixture journal',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        ['company_id' => $companyId, 'source_ref' => $sourceRef]
    );

    foreach ($lines as [$nominalAccountId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => $nominalAccountId,
                'debit' => number_format($debit, 2, '.', ''),
                'credit' => number_format($credit, 2, '.', ''),
            ]
        );
    }

    return $journalId;
}
