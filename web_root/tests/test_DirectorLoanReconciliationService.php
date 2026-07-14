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

$harness->run(\eel_accounts\Service\DirectorLoanReconciliationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanReconciliationService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'prefers subtype nominal before code fallback', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'chooseDirectorLoanNominal');
        $method->setAccessible(true);

        $chosen = $method->invoke($service, [
            ['id' => 12, 'code' => '1200', 'name' => 'Fallback Asset', 'account_type' => 'asset', 'subtype_code' => ''],
            ['id' => 99, 'code' => '1999', 'name' => 'Subtype Asset', 'account_type' => 'asset', 'subtype_code' => 'director_loan_asset'],
        ], 'director_loan_asset', '1200', 'asset');

        $harness->assertSame(99, (int)($chosen['id'] ?? 0));

        $fallback = $method->invoke($service, [
            ['id' => 12, 'code' => '1200', 'name' => 'Fallback Asset', 'account_type' => 'asset', 'subtype_code' => ''],
        ], 'director_loan_asset', '1200', 'asset');

        $harness->assertSame(12, (int)($fallback['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'calculates proposed offset from normal balances', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 10000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 15000.00, 'liability-payable');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(10000.00, (float)($result['asset_receivable'] ?? 0));
            $harness->assertSame(15000.00, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(10000.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame(5000.00, (float)($result['net_position'] ?? 0));
            $harness->assertSame('missing', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(true, (bool)($result['can_post'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'returns no proposed offset when only one side has a balance', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 750.00, 'liability-only');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(0.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame('not_required', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(false, (bool)($result['can_post'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'rolls prior period balances into the closing offset proposal', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 253.00, 0.00, 'prior-asset', (int)$fixture['prior_accounting_period_id'], '2024-12-31');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1288.63, 'prior-liability', (int)$fixture['prior_accounting_period_id'], '2024-12-31');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 4620.83, 0.00, 'current-asset');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 10873.46, 'current-liability');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(4873.83, (float)($result['asset_receivable'] ?? 0));
            $harness->assertSame(12162.09, (float)($result['liability_payable'] ?? 0));
            $harness->assertSame(4873.83, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame(7288.26, (float)($result['net_position'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'confirmation context includes lightweight tax review', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 500.00, 0.00, 'director-owes-company');

            $result = $service->fetchYearEndConfirmationContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $taxReview = (array)($result['tax_review'] ?? []);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame(true, (bool)($taxReview['available'] ?? false));
            $harness->assertSame('review_required', (string)($taxReview['status'] ?? ''));
            $harness->assertSame(500.00, (float)($taxReview['exposure_amount'] ?? 0));
            $harness->assertSame(false, array_key_exists('statement', $taxReview));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'excludes existing offset journal and identifies current status', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');

            $postResult = $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($postResult['success'] ?? false));

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(1000.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame(1000.00, (float)($result['posted_offset_amount'] ?? 0));
            $harness->assertSame('current', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(false, (bool)($result['can_post'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'flags stale existing offset after underlying balances change', static function () use ($harness, $service): void {
        directorLoanReconciliationTestWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset-receivable');
            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability-payable');
            $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');

            directorLoanReconciliationTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 250.00, 0.00, 'asset-increase');

            $result = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame(1250.00, (float)($result['offset_amount'] ?? 0));
            $harness->assertSame(1000.00, (float)($result['posted_offset_amount'] ?? 0));
            $harness->assertSame('stale', (string)($result['offset_status'] ?? ''));
            $harness->assertSame(true, (bool)($result['can_post'] ?? false));
        });
    });
});

function directorLoanReconciliationTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journals') || !InterfaceDB::tableExists('journal_lines')) {
        $harness->skip('Ledger tables are not available on the default InterfaceDB connection.');
    }

    $assetNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => '1200']);
    $liabilityNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => '2100']);
    if ($assetNominalId <= 0 || $liabilityNominalId <= 0) {
        $harness->skip('Director loan nominal accounts are not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Director Loan Offset Fixture Limited', 'company_number' => 'DLO' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'DLO' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'DLO Prior ' . $marker,
                'period_start' => '2024-01-01',
                'period_end' => '2024-12-31',
            ]
        );
        $priorAccountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'DLO Prior ' . $marker]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'DLO ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'DLO ' . $marker]
        );

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'prior_accounting_period_id' => $priorAccountingPeriodId,
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

function directorLoanReconciliationTestInsertLineJournal(array $fixture, int $nominalId, float $debit, float $credit, string $key, ?int $accountingPeriodId = null, ?string $journalDate = null): void
{
    $sourceRef = 'test-director-loan-offset:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => $accountingPeriodId ?? (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate ?? '2025-12-31',
            'description' => 'Director loan test fixture ' . $key,
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
            'line_description' => 'Director loan test fixture',
        ]
    );
}
