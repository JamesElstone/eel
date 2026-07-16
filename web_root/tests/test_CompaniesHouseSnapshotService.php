<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseSnapshotService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompaniesHouseSnapshotService $service): void {
        $harness->check(\eel_accounts\Service\CompaniesHouseSnapshotService::class, 'returns unavailable snapshot for missing selections', static function () use ($harness, $service): void {
            $snapshot = $service->fetchSnapshot(0, 0);
            $harness->assertSame(false, (bool)($snapshot['available'] ?? true));
        });

        $harness->check(\eel_accounts\Service\CompaniesHouseSnapshotService::class, 'derives Companies House balance sheet equations from posted ledger balances', static function () use ($harness, $service): void {
            companiesHouseSnapshotTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                companiesHouseSnapshotTestInsertJournal($fixture, [
                    [$fixture['fixed_asset_nominal_id'], 1000.00, 0.00],
                    [$fixture['bank_nominal_id'], 5000.00, 0.00],
                    [$fixture['director_loan_liability_nominal_id'], 0.00, 750.00],
                    [$fixture['long_term_director_loan_nominal_id'], 0.00, 1250.00],
                    [$fixture['equity_nominal_id'], 0.00, 4000.00],
                    [$fixture['income_nominal_id'], 0.00, 300.00],
                    [$fixture['expense_nominal_id'], 300.00, 0.00],
                ]);

                $snapshot = $service->fetchSnapshot((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
                $fields = companiesHouseSnapshotTestFields($snapshot);
                $sourceLabels = implode(' ', array_map(
                    static fn(array $source): string => (string)($source['label'] ?? ''),
                    (array)($snapshot['sources'] ?? [])
                ));

                $harness->assertSame(true, (bool)($snapshot['available'] ?? false));
                $harness->assertSame(1000.00, $fields['fixed_assets']);
                $harness->assertSame(5000.00, $fields['current_assets']);
                $harness->assertSame(750.00, $fields['creditors_within_one_year']);
                $harness->assertSame(1250.00, $fields['creditors_after_more_than_one_year']);
                $harness->assertSame(4250.00, $fields['net_current_assets_liabilities']);
                $harness->assertSame(5250.00, $fields['total_assets_less_current_liabilities']);
                $harness->assertSame(4000.00, $fields['net_assets_liabilities']);
                $harness->assertSame(4000.00, $fields['equity_capital_reserves']);
                $harness->assertSame(true, (bool)($snapshot['is_balance_sheet_balanced'] ?? false));
                $harness->assertSame(0.00, round((float)($snapshot['balance_equation_difference'] ?? 0), 2));
                $harness->assertSame(false, array_key_exists('expenses', $fields));
                $harness->assertSame(false, array_key_exists('profit_loss', $fields));
                $harness->assertSame(false, str_contains($sourceLabels, 'Expenses'));
                $harness->assertSame(false, str_contains($sourceLabels, 'Turnover'));
            });
        });

        $harness->check(\eel_accounts\Service\CompaniesHouseSnapshotService::class, 'surfaces balance equation mismatch warnings', static function () use ($harness, $service): void {
            companiesHouseSnapshotTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                companiesHouseSnapshotTestInsertJournal($fixture, [
                    [$fixture['bank_nominal_id'], 5000.00, 0.00],
                    [$fixture['equity_nominal_id'], 0.00, 3000.00],
                ]);

                $snapshot = $service->fetchSnapshot((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

                $harness->assertSame(false, (bool)($snapshot['is_balance_sheet_balanced'] ?? true));
                $harness->assertSame(2000.00, round((float)($snapshot['balance_equation_difference'] ?? 0), 2));
                $harness->assertTrue((array)($snapshot['warnings'] ?? []) !== []);
            });
        });

        $harness->check(\eel_accounts\Service\CompaniesHouseSnapshotService::class, 'uses the period-specific Director Loan repayment horizon without moving other creditors', static function () use ($harness, $service): void {
            companiesHouseSnapshotTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                companiesHouseSnapshotTestInsertJournal($fixture, [
                    [$fixture['fixed_asset_nominal_id'], 1000.00, 0.00],
                    [$fixture['bank_nominal_id'], 5000.00, 0.00],
                    [$fixture['director_loan_liability_nominal_id'], 0.00, 750.00],
                    [$fixture['long_term_director_loan_nominal_id'], 0.00, 1250.00],
                    [$fixture['equity_nominal_id'], 0.00, 4000.00],
                ]);

                $before = companiesHouseSnapshotTestFields(
                    $service->fetchSnapshot((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])
                );
                $harness->assertSame(750.0, $before['creditors_within_one_year']);
                $harness->assertSame(1250.0, $before['creditors_after_more_than_one_year']);

                $saved = (new \eel_accounts\Service\DirectorLoanReportingPresentationService())->save(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'after_more_than_one_year',
                    'test'
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));

                $after = companiesHouseSnapshotTestFields(
                    $service->fetchSnapshot((int)$fixture['company_id'], (int)$fixture['accounting_period_id'])
                );
                $harness->assertSame(0.0, $after['creditors_within_one_year']);
                $harness->assertSame(2000.0, $after['creditors_after_more_than_one_year']);
                $harness->assertSame(5000.0, $after['net_current_assets_liabilities']);
                $harness->assertSame(6000.0, $after['total_assets_less_current_liabilities']);
                $harness->assertSame(4000.0, $after['net_assets_liabilities']);
            });
        });
    }
);

function companiesHouseSnapshotTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    foreach (['companies', 'accounting_periods', 'nominal_account_subtypes', 'nominal_accounts', 'journals', 'journal_lines'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip('Required ledger table is not available on the default InterfaceDB connection: ' . $table);
        }
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Companies House Snapshot Fixture Limited', 'company_number' => 'CHS' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'CHS' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'CHS ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'CHS ' . $marker]
        );

        $fixture = [
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'fixed_asset_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'fixed_asset', 'asset', 'fixed_asset'),
            'bank_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'bank', 'asset', 'bank'),
            'director_loan_liability_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'director_loan_liability', 'liability', 'director_loan_liability'),
            'long_term_director_loan_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'director_loan_long_term_liability', 'liability', 'director_loan_long_term_liability'),
            'equity_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'capital_reserves', 'equity', 'capital_reserves'),
            'income_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'turnover', 'income', 'turnover'),
            'expense_nominal_id' => companiesHouseSnapshotTestNominal($marker, 'overhead', 'expense', 'overhead'),
        ];
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set(
            'director_loan_liability_nominal_id',
            (int)$fixture['director_loan_liability_nominal_id'],
            'int'
        );
        $settings->flush();

        $callback($fixture);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function companiesHouseSnapshotTestNominal(string $marker, string $subtypeCode, string $accountType, string $name): int
{
    $subtypeId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
        ['code' => $subtypeCode]
    );
    if ($subtypeId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
             VALUES (:code, :name, :parent_account_type, 900, 1)',
            [
                'code' => $subtypeCode,
                'name' => HelperFramework::labelFromKey($subtypeCode, '_'),
                'parent_account_type' => $accountType,
            ]
        );
        $subtypeId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
            ['code' => $subtypeCode]
        );
    }

    $code = 'T' . substr(hash('sha256', $marker . $subtypeCode), 0, 10);
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :account_subtype_id, :tax_treatment, 1, 900)',
        [
            'code' => $code,
            'name' => 'Snapshot ' . HelperFramework::labelFromKey($name, '_'),
            'account_type' => $accountType,
            'account_subtype_id' => $subtypeId,
            'tax_treatment' => 'allowable',
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}

function companiesHouseSnapshotTestInsertJournal(array $fixture, array $lines): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => 'test-companies-house-snapshot:' . $fixture['marker'] . ':' . count($lines),
            'journal_date' => '2025-12-31',
            'description' => 'Companies House snapshot fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_ref' => 'test-companies-house-snapshot:' . $fixture['marker'] . ':' . count($lines),
        ]
    );

    foreach ($lines as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$line[0],
                'debit' => number_format((float)$line[1], 2, '.', ''),
                'credit' => number_format((float)$line[2], 2, '.', ''),
                'line_description' => 'Companies House snapshot fixture',
            ]
        );
    }
}

function companiesHouseSnapshotTestFields(array $snapshot): array
{
    $fields = [];
    foreach ((array)($snapshot['fields'] ?? []) as $field) {
        $fields[(string)($field['key'] ?? '')] = round((float)($field['value'] ?? 0), 2);
    }

    return $fields;
}
