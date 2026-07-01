<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(YearEndAction::class, static function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof YearEndAction) {
        throw new RuntimeException('Unexpected YearEndAction instance.');
    }

    $harness->check('YearEndAction', 'posts director loan offset journal idempotently', static function () use ($harness, $instance): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness, $instance): void {
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');

            $request = yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $first = $instance->handle($request, createTestPageServiceFramework());
            $second = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(true, $first->isSuccess());
            $harness->assertSame(true, $second->isSuccess());
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 200.00, 0.00, 'asset-increase');
            $third = $instance->handle($request, createTestPageServiceFramework());
            $harness->assertSame(true, $third->isSuccess());
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            $offsetDebit = (float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.debit), 0)
                 FROM journal_entry_metadata jem
                 INNER JOIN journal_lines jl ON jl.journal_id = jem.journal_id
                 WHERE jem.company_id = :company_id
                   AND jem.accounting_period_id = :accounting_period_id
                   AND jem.journal_tag = :journal_tag
                   AND jl.nominal_account_id = :nominal_account_id',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                    'nominal_account_id' => (int)$fixture['liability_nominal_id'],
                ]
            );
            $harness->assertSame(1200.00, round($offsetDebit, 2));
        });
    });

    $harness->check('YearEndAction', 'locked period blocks director loan offset posting', static function () use ($harness, $instance): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness, $instance): void {
            if (!InterfaceDB::tableExists('year_end_reviews')) {
                $harness->skip('Year-end review table is not available on the default InterfaceDB connection.');
            }

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, status, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, :status, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'status' => 'locked',
                    'locked_by' => 'test',
                ]
            );

            $result = $instance->handle(yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id']), createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'locked'));
        });
    });
});

function yearEndActionDirectorLoanTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journal_entry_metadata')) {
        $harness->skip('Ledger metadata tables are not available on the default InterfaceDB connection.');
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
            ['company_name' => 'Year End Action DLO Fixture Limited', 'company_number' => 'YEA' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'YEA' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'YEA ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'YEA ' . $marker]
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

function yearEndActionDirectorLoanTestInsertLineJournal(array $fixture, int $nominalId, float $debit, float $credit, string $key): void
{
    $sourceRef = 'test-year-end-action-dlo:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'Year end action DLO fixture ' . $key,
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
            'line_description' => 'Year end action DLO fixture',
        ]
    );
}

function yearEndActionDirectorLoanTestRequest(int $companyId, int $accountingPeriodId): RequestFramework
{
    return new RequestFramework(
        [],
        [
            'card_action' => 'YearEnd',
            'intent' => 'post_director_loan_offset',
            'company_id' => (string)$companyId,
            'accounting_period_id' => (string)$accountingPeriodId,
        ],
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        [],
        null
    );
}
