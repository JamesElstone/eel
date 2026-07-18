<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\DirectorLoanService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'calculates the primary director position while preserving the external counterparty', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $primaryDirectorId = (int)$fixture['primary_director_id'];
            $otherId = (int)$fixture['other_director_id'];

            directorLoanStatementInsertTransactionJournal(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                253.00,
                $primaryDirectorId,
                'External Counterparty'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                1288.63,
                $primaryDirectorId,
                'Primary director funds introduced'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                100.00,
                0.00,
                $otherId,
                'Other director advance'
            );

            $statement = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $disclosure = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $taxReview = $service->fetchTaxReviewSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $positions = [];
            foreach ((array)($statement['per_director'] ?? []) as $position) {
                $positions[(int)($position['director_id'] ?? 0)] = $position;
            }
            $primaryDirector = (array)($positions[$primaryDirectorId] ?? []);
            $other = (array)($positions[$otherId] ?? []);
            $externalCounterpartyEntry = array_values(array_filter(
                (array)($statement['attribution_entries'] ?? []),
                static fn(array $entry): bool => (string)($entry['counterparty_name'] ?? '') === 'External Counterparty'
            ));

            $harness->assertSame(true, (bool)($statement['success'] ?? false));
            $harness->assertSame(true, (bool)($disclosure['has_company_to_director_exposure'] ?? false));
            $harness->assertSame('353.00', directorLoanStatementMoney($disclosure['total_advances'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($disclosure['total_repayments'] ?? 0));
            $harness->assertSame('1035.63', directorLoanStatementMoney($disclosure['total_director_funding'] ?? 0));
            $statementText = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->directorLoanStatementText($disclosure);
            $harness->assertTrue(str_contains($statementText, 'advanced £253.00 to Primary Director'));
            $harness->assertTrue(str_contains($statementText, 'advanced £100.00 to Other Director'));
            $harness->assertSame('253.00', directorLoanStatementMoney($primaryDirector['gross_asset'] ?? 0));
            $harness->assertSame('1288.63', directorLoanStatementMoney($primaryDirector['gross_liability'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($primaryDirector['desired_reclassification'] ?? 0));
            $harness->assertSame('1035.63', directorLoanStatementMoney($primaryDirector['net_closing_position'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($primaryDirector['potential_s455_exposure'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($other['desired_reclassification'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($other['potential_s455_exposure'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($statement['desired_reclassification'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($statement['potential_s455_exposure'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($taxReview['exposure_amount'] ?? 0));
            $harness->assertCount(1, $externalCounterpartyEntry);
            $harness->assertSame($primaryDirectorId, (int)($externalCounterpartyEntry[0]['director_id'] ?? 0));
            $harness->assertSame(true, str_contains((string)($externalCounterpartyEntry[0]['source_url'] ?? ''), 'transaction_id='));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'lists unattributed entries and never offsets balances between different directors', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                500.00,
                0.00,
                (int)$fixture['primary_director_id'],
                'Primary director receivable'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                500.00,
                (int)$fixture['other_director_id'],
                'Other director payable'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                25.00,
                null,
                'Unattributed legacy line'
            );

            $statement = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame('0.00', directorLoanStatementMoney($statement['desired_reclassification'] ?? 0));
            $harness->assertSame('500.00', directorLoanStatementMoney($statement['potential_s455_exposure'] ?? 0));
            $harness->assertSame(1, (int)($statement['unattributed_count'] ?? 0));
            $harness->assertCount(1, (array)($statement['unattributed_entries'] ?? []));
        });
    });
});

function directorLoanStatementWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    foreach (['company_directors', 'journal_lines', 'statement_uploads', 'transactions'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' schema is not available.');
        }
    }

    InterfaceDB::beginTransaction();
    try {
        StandardNominalTestFixture::ensureNominals(['1200', '2100']);
        $assetNominalId = StandardNominalTestFixture::id('1200');
        $liabilityNominalId = StandardNominalTestFixture::id('2100');
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Director Loan Subledger Fixture Limited', 'company_number' => 'DLS' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number',
            ['company_number' => 'DLS' . $marker]
        );
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            ['company_id' => $companyId, 'label' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        foreach ([
            ['key' => 'primary-director:' . $marker, 'name' => 'Primary Director', 'appointed' => '2020-01-01'],
            ['key' => 'other:' . $marker, 'name' => 'Other Director', 'appointed' => '2021-01-01'],
        ] as $director) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $companyId,
                    'source' => 'companies_house',
                    'external_key' => $director['key'],
                    'full_name' => $director['name'],
                    'officer_role' => 'director',
                    'appointed_on' => $director['appointed'],
                ]
            );
        }
        $primaryDirectorId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors WHERE company_id = :company_id AND full_name = :name',
            ['company_id' => $companyId, 'name' => 'Primary Director']
        );
        $otherId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors WHERE company_id = :company_id AND full_name = :name',
            ['company_id' => $companyId, 'name' => 'Other Director']
        );

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
            'primary_director_id' => $primaryDirectorId,
            'other_director_id' => $otherId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function directorLoanStatementInsertManualLine(
    array $fixture,
    int $nominalId,
    float $debit,
    float $credit,
    ?int $directorId,
    string $description
): int {
    $sourceRef = 'dla-manual:' . $fixture['marker'] . ':' . hash('sha256', $description . microtime(true));
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => $description,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => $sourceRef]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, director_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :director_id, :debit, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => $nominalId,
            'director_id' => $directorId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'description' => $description,
        ]
    );
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journal_lines WHERE journal_id = :journal_id',
        ['journal_id' => $journalId]
    );
}

function directorLoanStatementInsertTransactionJournal(
    array $fixture,
    int $nominalId,
    float $amount,
    int $directorId,
    string $counterparty
): void {
    $hash = hash('sha256', 'dla-upload:' . $fixture['marker']);
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256
         ) VALUES (
            :company_id, :period_id, :statement_month, :original_filename, :stored_filename, :file_sha256
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'statement_month' => '2025-12-01',
            'original_filename' => 'dla.csv',
            'stored_filename' => 'dla-' . $fixture['marker'] . '.csv',
            'file_sha256' => $hash,
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND file_sha256 = :hash',
        ['company_id' => (int)$fixture['company_id'], 'hash' => $hash]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id, accounting_period_id, statement_upload_id, txn_date, description,
            amount, counterparty_name, dedupe_hash, nominal_account_id, director_id, category_status
         ) VALUES (
            :company_id, :period_id, :upload_id, :txn_date, :description,
            :amount, :counterparty_name, :dedupe_hash, :nominal_id, :director_id, :category_status
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'upload_id' => $uploadId,
            'txn_date' => '2025-06-30',
            'description' => 'Funds advanced on the primary director account',
            'amount' => number_format($amount, 2, '.', ''),
            'counterparty_name' => $counterparty,
            'dedupe_hash' => hash('sha256', 'dla-transaction:' . $fixture['marker']),
            'nominal_id' => $nominalId,
            'director_id' => $directorId,
            'category_status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id AND statement_upload_id = :upload_id',
        ['company_id' => (int)$fixture['company_id'], 'upload_id' => $uploadId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $transactionId,
            'journal_date' => '2025-06-30',
            'description' => 'Funds advanced on the primary director account',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => 'transaction:' . $transactionId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, director_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :director_id, :debit, 0.00, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => $nominalId,
            'director_id' => $directorId,
            'debit' => number_format($amount, 2, '.', ''),
            'description' => 'Funds advanced on the primary director account',
        ]
    );
}

function directorLoanStatementMoney(mixed $amount): string
{
    return number_format(round((float)$amount, 2), 2, '.', '');
}
