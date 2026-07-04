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

$harness->run(\eel_accounts\Service\DirectorLoanService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'calculates combined asset liability net position', static function () use ($harness, $service): void {
        directorLoanStatementTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_asset_nominal_id', (string)$fixture['asset_nominal_id']);
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_liability_nominal_id', (string)$fixture['liability_nominal_id']);
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, '2025-12-31', 'opening-asset');
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, '2025-12-31', 'opening-liability');
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 250.00, 0.00, '2026-03-01', 'asset-movement');
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 400.00, '2026-03-02', 'liability-movement');

            $result = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1250.00, (float)($result['asset_receivable'] ?? 0));
            $harness->assertSame(1900.00, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(650.00, (float)($result['net_position'] ?? 0));
            $harness->assertSame(500.00, (float)($result['opening_balance'] ?? 0));
            $harness->assertSame(150.00, (float)($result['movement_in_period'] ?? 0));
            $harness->assertSame('Company owes director', (string)($result['net_position_label'] ?? ''));
            $harness->assertCount(3, (array)($result['statement_rows'] ?? []));

            $rows = (array)($result['statement_rows'] ?? []);
            $harness->assertSame('Combined', (string)($rows[0]['account_label'] ?? ''));
            $harness->assertSame(-250.00, (float)($rows[1]['signed_amount'] ?? 0));
            $harness->assertSame('asset', (string)($rows[1]['nominal_role'] ?? ''));
            $harness->assertSame(400.00, (float)($rows[2]['signed_amount'] ?? 0));
            $harness->assertSame('liability', (string)($rows[2]['nominal_role'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'uses legacy liability setting when pair setting is missing', static function () use ($harness, $service): void {
        directorLoanStatementTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_asset_nominal_id', (string)$fixture['asset_nominal_id']);
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_nominal_id', (string)$fixture['liability_nominal_id']);
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 75.00, '2026-01-15', 'legacy-liability');

            $result = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame((int)$fixture['liability_nominal_id'], (int)($result['liability_nominal']['id'] ?? 0));
            $harness->assertSame(75.00, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(75.00, (float)($result['net_position'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'falls back to configured chart subtype or code when settings are absent', static function () use ($harness, $service): void {
        directorLoanStatementTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $initial = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($initial['success'] ?? false));
            $assetNominalId = (int)($initial['asset_nominal']['id'] ?? 0);
            $liabilityNominalId = (int)($initial['liability_nominal']['id'] ?? 0);
            $harness->assertTrue($assetNominalId > 0);
            $harness->assertTrue($liabilityNominalId > 0);

            directorLoanStatementTestInsertLineJournal($fixture, $assetNominalId, 60.00, 0.00, '2026-02-01', 'fallback-asset');
            directorLoanStatementTestInsertLineJournal($fixture, $liabilityNominalId, 0.00, 40.00, '2026-02-02', 'fallback-liability');

            $result = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame('asset', (string)($result['asset_nominal']['account_type'] ?? ''));
            $harness->assertSame('liability', (string)($result['liability_nominal']['account_type'] ?? ''));
            $harness->assertSame(-20.00, (float)($result['net_position'] ?? 0));
            $harness->assertSame('Director owes company', (string)($result['net_position_label'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'reports settled when asset and liability normal balances match', static function () use ($harness, $service): void {
        directorLoanStatementTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_asset_nominal_id', (string)$fixture['asset_nominal_id']);
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_liability_nominal_id', (string)$fixture['liability_nominal_id']);
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 100.00, 0.00, '2026-04-01', 'settled-asset');
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 100.00, '2026-04-02', 'settled-liability');

            $result = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(0.00, (float)($result['net_position'] ?? 1));
            $harness->assertSame('Settled', (string)($result['net_position_label'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'flags director loan tax review when director owes company at year end', static function () use ($harness, $service): void {
        directorLoanStatementTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_asset_nominal_id', (string)$fixture['asset_nominal_id']);
            directorLoanStatementTestInsertSetting($fixture, 'director_loan_liability_nominal_id', (string)$fixture['liability_nominal_id']);
            directorLoanStatementTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 500.00, 0.00, '2026-12-31', 'director-owes-company');

            $review = $service->fetchTaxReview((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($review['available'] ?? false));
            $harness->assertSame('review_required', (string)($review['status'] ?? ''));
            $harness->assertSame(true, (bool)($review['s455_review_required'] ?? false));
            $harness->assertSame(true, (bool)($review['beneficial_loan_interest_review_required'] ?? false));
            $harness->assertSame(true, (bool)($review['write_off_review_required'] ?? false));
            $harness->assertSame(true, (bool)($review['ct600_supplementary_review_required'] ?? false));
            $harness->assertSame(500.00, (float)($review['exposure_amount'] ?? 0));
            $harness->assertSame('2027-10-01', (string)($review['repayment_review_date'] ?? ''));
            $harness->assertTrue(count((array)($review['review_items'] ?? [])) >= 4);
        });
    });
});

function directorLoanStatementTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
        $harness->skip('Ledger tables are not available on the default InterfaceDB connection.');
    }

    $assetNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code AND account_type = :account_type LIMIT 1', ['code' => '1200', 'account_type' => 'asset']);
    $liabilityNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code AND account_type = :account_type LIMIT 1', ['code' => '2100', 'account_type' => 'liability']);
    if ($assetNominalId <= 0 || $liabilityNominalId <= 0) {
        $harness->skip('Director loan nominal accounts are not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Director Loan Statement Fixture Limited', 'company_number' => 'DLS' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'DLS' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'DLS ' . $marker,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'DLS ' . $marker]
        );

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function directorLoanStatementTestInsertSetting(array $fixture, string $setting, string $value): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO company_settings (company_id, setting, type, value)
         VALUES (:company_id, :setting, :type, :value)',
        [
            'company_id' => (int)$fixture['company_id'],
            'setting' => $setting,
            'type' => 'int',
            'value' => $value,
        ]
    );
}

function directorLoanStatementTestInsertLineJournal(array $fixture, int $nominalId, float $debit, float $credit, string $date, string $key): void
{
    $sourceRef = 'test-director-loan-statement:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => 'Director loan statement fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Director loan statement fixture',
        ]
    );
}
