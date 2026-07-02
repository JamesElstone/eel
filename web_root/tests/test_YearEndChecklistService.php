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
$harness->run(\eel_accounts\Service\YearEndChecklistService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndChecklistService::class, 'locking a period posts asset depreciation first', static function () use ($harness): void {
        yearEndChecklistServiceRequireDepreciationLockSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndChecklistServiceCreateDepreciationLockFixture();
            $result = (new \eel_accounts\Service\YearEndChecklistService())->lockPeriod(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1, (int)(($result['depreciation'] ?? [])['created'] ?? 0));
            $harness->assertSame(1, InterfaceDB::countWhere('asset_depreciation_entries', [
                'asset_id' => (int)$fixture['asset_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
            ]));
            $harness->assertSame(1, InterfaceDB::countWhere('year_end_reviews', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'is_locked' => 1,
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function yearEndChecklistServiceRequireDepreciationLockSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'nominal_accounts', 'asset_register', 'asset_depreciation_entries', 'year_end_reviews', 'year_end_check_results', 'year_end_audit_log', 'accounting_period_adjustments', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['1000', '1300', '1330', '4000', '6200'] as $code) {
        if (yearEndChecklistServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function yearEndChecklistServiceCreateDepreciationLockFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('61' . $marker);
    $accountingPeriodId = (int)('62' . $marker);
    $assetId = (int)('63' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Year End Depreciation Fixture ' . $marker,
            'company_number' => 'YED' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'YED FY ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
            'journal_date' => '2025-12-31',
            'description' => 'Year end depreciation fixture ' . $marker,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => 'year-end-depreciation-fixture-' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 1200.00, 0.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('1000'),
            'line_description' => 'Fixture bank debit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 1200.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => yearEndChecklistServiceNominalId('4000'),
            'line_description' => 'Fixture sales credit',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'YED-' . $marker,
            'description' => 'Year end depreciation fixture asset',
            'category' => 'tools_equipment',
            'nominal_account_id' => yearEndChecklistServiceNominalId('1300'),
            'accum_dep_nominal_id' => yearEndChecklistServiceNominalId('1330'),
            'purchase_date' => '2025-01-01',
            'cost' => 1200.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function yearEndChecklistServiceNominalId(string $code): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}
