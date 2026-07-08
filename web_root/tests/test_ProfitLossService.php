<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(\eel_accounts\Service\ProfitLossService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ProfitLossService $service): void {
    $harness->check(\eel_accounts\Service\ProfitLossService::class, 'separates Corporation Tax from operating expenses', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $bankNominalId = profitLossTestEnsureNominal('1000', 'Bank', 'asset', 'allowable');
            $incomeNominalId = profitLossTestEnsureNominal('4000', 'Sales', 'income', 'allowable');
            $expenseNominalId = profitLossTestEnsureNominal('6090', 'Sundry Expenses', 'expense', 'allowable');
            $taxExpenseNominalId = profitLossTestEnsureNominal('8500', 'Corporation Tax Expense', 'expense', 'disallowable');
            $taxLiabilityNominalId = profitLossTestEnsureNominal('2200', 'Corporation Tax', 'liability', 'allowable');

            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                ['company_name' => 'P and L Fixture ' . $marker, 'company_number' => 'PL' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'PL' . $marker]);
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'P and L FY',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id LIMIT 1', ['company_id' => $companyId]);

            profitLossTestJournal($companyId, $periodId, '2025-01-31', 'Income', [
                [$bankNominalId, 1000.00, 0.00],
                [$incomeNominalId, 0.00, 1000.00],
            ]);
            profitLossTestJournal($companyId, $periodId, '2025-02-28', 'Operating expense', [
                [$expenseNominalId, 200.00, 0.00],
                [$bankNominalId, 0.00, 200.00],
            ]);
            profitLossTestJournal($companyId, $periodId, '2025-12-31', 'CT provision', [
                [$taxExpenseNominalId, 190.00, 0.00],
                [$taxLiabilityNominalId, 0.00, 190.00],
            ], \eel_accounts\Service\CorporationTaxProvisionService::JOURNAL_TAG, 'ct_period_test');

            $summary = $service->getProfitLossSummary($companyId, $periodId);
            $harness->assertSame(true, (bool)($summary['available'] ?? false));
            $harness->assertSame('200.00', number_format((float)($summary['operating_expense_total'] ?? 0), 2, '.', ''));
            $harness->assertSame('190.00', number_format((float)($summary['posted_corporation_tax_charge'] ?? 0), 2, '.', ''));
            $harness->assertSame('800.00', number_format((float)($summary['profit_before_tax'] ?? 0), 2, '.', ''));
            $harness->assertSame('610.00', number_format((float)($summary['profit_after_posted_tax'] ?? 0), 2, '.', ''));

            $breakdown = $service->getProfitLossBreakdown($companyId, $periodId);
            $harness->assertSame(1, count((array)($breakdown['expense'] ?? [])));
            $harness->assertSame(1, count((array)($breakdown['tax_charge'] ?? [])));
            $harness->assertSame('8500', (string)($breakdown['tax_charge'][0]['code'] ?? ''));

            $trend = $service->getMonthlyProfitLossTrend($companyId, $periodId);
            $december = array_values(array_filter($trend, static fn(array $row): bool => (string)($row['month_start'] ?? '') === '2025-12-01'))[0] ?? [];
            $harness->assertSame('190.00', number_format((float)($december['corporation_tax_expense_total'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function profitLossTestEnsureNominal(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $existing = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($existing > 0) {
        return $existing;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active)
         VALUES (:code, :name, :account_type, :tax_treatment, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}

function profitLossTestJournal(int $companyId, int $periodId, string $date, string $description, array $lines, string $journalTag = '', string $journalKey = ''): void
{
    $sourceRef = 'test-profit-loss:' . hash('sha256', $description . microtime(true) . random_int(1, PHP_INT_MAX));
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => $description,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        ['company_id' => $companyId, 'source_ref' => $sourceRef]
    );

    foreach ($lines as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$line[0],
                'debit' => number_format((float)$line[1], 2, '.', ''),
                'credit' => number_format((float)$line[2], 2, '.', ''),
            ]
        );
    }

    if ($journalTag !== '') {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_entry_metadata (journal_id, company_id, accounting_period_id, journal_tag, journal_key, entry_mode)
             VALUES (:journal_id, :company_id, :accounting_period_id, :journal_tag, :journal_key, :entry_mode)',
            [
                'journal_id' => $journalId,
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'journal_tag' => $journalTag,
                'journal_key' => $journalKey !== '' ? $journalKey : 'primary',
                'entry_mode' => 'system_generated',
            ]
        );
    }
}
