<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\TransactionJournalService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TransactionJournalService $service): void {
    $buildDesiredJournal = new ReflectionMethod(\eel_accounts\Service\TransactionJournalService::class, 'buildDesiredJournal');
    $buildDesiredJournal->setAccessible(true);

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'posts bank payment to a trade creditor nominal', static function () use ($harness, $service, $buildDesiredJournal): void {
        $journal = $buildDesiredJournal->invoke($service, [
            'id' => 501,
            'company_id' => 1,
            'accounting_period_id' => 2,
            'account_id' => 10,
            'transfer_account_id' => 20,
            'txn_date' => '2026-05-01',
            'description' => 'Example Trade Supplier payment',
            'amount' => -100.00,
            'category_status' => 'manual',
            'is_internal_transfer' => 1,
            'source_account_name' => 'BANK Current',
            'source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'source_account_nominal_id' => 1001,
            'transfer_account_name' => 'Example Trade Supplier',
            'transfer_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
            'transfer_account_nominal_id' => 2001,
        ], 0);

        $lines = $journal['lines'] ?? [];
        $harness->assertSame(2001, (int)$lines[0]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[0]['debit']);
        $harness->assertSame(1001, (int)$lines[1]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[1]['credit']);
    });

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'posts trade purchase on credit to creditor nominal', static function () use ($harness, $service, $buildDesiredJournal): void {
        $journal = $buildDesiredJournal->invoke($service, [
            'id' => 502,
            'company_id' => 1,
            'accounting_period_id' => 2,
            'account_id' => 20,
            'txn_date' => '2026-05-02',
            'description' => 'Example Trade Supplier materials',
            'amount' => 100.00,
            'nominal_account_id' => 5000,
            'category_status' => 'manual',
            'source_account_name' => 'Example Trade Supplier',
            'source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
            'source_account_nominal_id' => 2001,
        ], 0);

        $lines = $journal['lines'] ?? [];
        $harness->assertSame(5000, (int)$lines[0]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[0]['debit']);
        $harness->assertSame(2001, (int)$lines[1]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[1]['credit']);
    });

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'does not create desired journal for inter account matched evidence', static function () use ($harness, $service, $buildDesiredJournal): void {
        $journal = $buildDesiredJournal->invoke($service, [
            'id' => 5802,
            'company_id' => 1,
            'accounting_period_id' => 2,
            'account_id' => 10,
            'txn_date' => '2026-05-03',
            'description' => 'Matched Example Trade Supplier evidence',
            'amount' => -241.46,
            'nominal_account_id' => 2301,
            'category_status' => 'manual',
            'source_account_name' => 'Example Bank - Current Account',
            'source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'source_account_nominal_id' => 1001,
            'inter_ac_no_post' => 1,
        ], 0);

        $harness->assertSame(null, $journal);
    });

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'posts bank payment split lines to their own nominals', static function () use ($harness, $service, $buildDesiredJournal): void {
        foreach (['transaction_splits', 'transaction_split_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionJournalSplitTestCreateFixture();
            $splitService = new \eel_accounts\Service\TransactionSplitService();
            $splitService->startSplit($fixture['company_id'], $fixture['transaction_id']);
            $split = (array)$splitService->fetchSplitForTransaction($fixture['transaction_id']);
            $lines = array_values((array)($split['lines'] ?? []));
            $splitService->saveLine($fixture['company_id'], (int)$lines[0]['id'], [
                'split_line_description' => 'AMZNMKTPLACE tool item',
                'split_line_amount' => '89.99',
                'nominal_account_id' => $fixture['tool_nominal_id'],
            ]);
            $splitService->saveLine($fixture['company_id'], (int)$lines[1]['id'], [
                'split_line_description' => 'AMZNMKTPLACE materials',
                'split_line_amount' => '56.37',
                'nominal_account_id' => $fixture['materials_nominal_id'],
            ]);

            $journal = $buildDesiredJournal->invoke($service, [
                'id' => $fixture['transaction_id'],
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'account_id' => $fixture['account_id'],
                'txn_date' => '2023-10-30',
                'description' => 'AMZNMKTPLACE',
                'amount' => -146.36,
                'nominal_account_id' => null,
                'category_status' => 'manual',
                'source_account_name' => 'Example Bank - Current Account',
                'source_account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'source_account_nominal_id' => $fixture['source_nominal_id'],
            ], 0);

            $journalLines = (array)($journal['lines'] ?? []);
            $harness->assertSame(3, count($journalLines));
            $harness->assertSame($fixture['tool_nominal_id'], (int)$journalLines[0]['nominal_account_id']);
            $harness->assertSame('89.99', (string)$journalLines[0]['debit']);
            $harness->assertSame('0.00', (string)$journalLines[0]['credit']);
            $harness->assertSame($fixture['materials_nominal_id'], (int)$journalLines[1]['nominal_account_id']);
            $harness->assertSame('56.37', (string)$journalLines[1]['debit']);
            $harness->assertSame('0.00', (string)$journalLines[1]['credit']);
            $harness->assertSame($fixture['source_nominal_id'], (int)$journalLines[2]['nominal_account_id']);
            $harness->assertSame($fixture['account_id'], (int)$journalLines[2]['company_account_id']);
            $harness->assertSame('0.00', (string)$journalLines[2]['debit']);
            $harness->assertSame('146.36', (string)$journalLines[2]['credit']);

            $created = $service->syncJournalForTransaction(
                $fixture['transaction_id'],
                $fixture['source_nominal_id'],
                'test',
                true
            );
            $harness->assertSame(true, (bool)($created['success'] ?? false));
            $sourceJournalId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
                ['company_id' => $fixture['company_id'], 'source_ref' => 'transaction:' . $fixture['transaction_id']]
            );
            $harness->assertTrue($sourceJournalId > 0);

            $changedLine = $splitService->saveLine($fixture['company_id'], (int)$lines[0]['id'], [
                'split_line_description' => 'AMZNMKTPLACE reclassified item',
                'split_line_amount' => '89.99',
                'nominal_account_id' => $fixture['materials_nominal_id'],
            ]);
            $harness->assertSame(true, (bool)($changedLine['success'] ?? false));
            $rebuilt = $service->syncJournalForTransaction(
                $fixture['transaction_id'],
                $fixture['source_nominal_id'],
                'test',
                true
            );
            $harness->assertSame(true, (bool)($rebuilt['success'] ?? false));
            $harness->assertSame(1, InterfaceDB::countWhere('journals', ['id' => $sourceJournalId]));
            $lifecycle = InterfaceDB::fetchOne(
                'SELECT reversal_journal_id, replacement_journal_id
                 FROM journal_reversals WHERE source_journal_id = :source_journal_id',
                ['source_journal_id' => $sourceJournalId]
            );
            $harness->assertTrue((int)($lifecycle['reversal_journal_id'] ?? 0) > 0);
            $harness->assertTrue((int)($lifecycle['replacement_journal_id'] ?? 0) > 0);
            $harness->assertSame(3, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM journals WHERE company_id = :company_id',
                ['company_id' => $fixture['company_id']]
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'fetches filtered journals by nominal amount side source account and keyword', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionJournalSearchTestCreateFixture();

            $nominalMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                'nominal_account_ids' => [$fixture['software_nominal_id']],
            ]);
            $harness->assertSame(1, count($nominalMatches));
            $harness->assertSame('Office software annual plan', (string)$nominalMatches[0]['description']);
            $harness->assertSame(2, count((array)$nominalMatches[0]['lines']));

            $debitMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                'nominal_account_ids' => [$fixture['software_nominal_id']],
                'amount' => '-120',
                'side' => 'dr',
            ]);
            $harness->assertSame(1, count($debitMatches));
            $harness->assertSame($fixture['software_journal_id'], (int)$debitMatches[0]['id']);

            $sourceAndNominalMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                'source_account_id' => $fixture['account_id'],
                'nominal_account_ids' => [$fixture['materials_nominal_id']],
            ]);
            $harness->assertSame(1, count($sourceAndNominalMatches));
            $harness->assertSame($fixture['materials_journal_id'], (int)$sourceAndNominalMatches[0]['id']);

            $creditMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                'amount' => '80.00',
                'side' => 'cr',
                'source_account_id' => $fixture['account_id'],
            ]);
            $harness->assertSame(1, count($creditMatches));
            $harness->assertSame($fixture['materials_journal_id'], (int)$creditMatches[0]['id']);

            $keywordMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                'keyword' => 'Copper',
            ]);
            $harness->assertSame(1, count($keywordMatches));
            $harness->assertSame($fixture['materials_journal_id'], (int)$keywordMatches[0]['id']);

            foreach ([
                '%' => $fixture['software_journal_id'],
                '_' => $fixture['materials_journal_id'],
                '!' => $fixture['materials_journal_id'],
            ] as $literalKeyword => $expectedJournalId) {
                $literalMatches = $service->fetchJournals($fixture['company_id'], $fixture['accounting_period_id'], 200, [
                    'keyword' => $literalKeyword,
                ]);
                $harness->assertSame(1, count($literalMatches));
                $harness->assertSame($expectedJournalId, (int)$literalMatches[0]['id']);
            }
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\TransactionJournalService::class, 'paginates journals in SQL and returns every filtered journal for export', static function () use ($harness, $service): void {
        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionJournalSearchTestCreateFixture();

            $firstPage = $service->fetchJournalsPage($fixture['company_id'], $fixture['accounting_period_id'], [
                'page' => 1,
                'page_size' => 1,
            ]);
            $harness->assertSame(2, (int)$firstPage['total']);
            $harness->assertSame(2, (int)$firstPage['page_count']);
            $harness->assertSame(1, count((array)$firstPage['items']));
            $harness->assertSame($fixture['materials_journal_id'], (int)$firstPage['items'][0]['id']);
            $harness->assertSame(2, count((array)$firstPage['items'][0]['lines']));
            $harness->assertSame(80.0, (float)$firstPage['items'][0]['total_debit']);
            $harness->assertSame(true, (bool)$firstPage['has_next_page']);

            $secondPage = $service->fetchJournalsPage($fixture['company_id'], $fixture['accounting_period_id'], [
                'page' => 2,
                'page_size' => 1,
            ]);
            $harness->assertSame($fixture['software_journal_id'], (int)$secondPage['items'][0]['id']);
            $harness->assertSame(true, (bool)$secondPage['has_previous_page']);
            $harness->assertSame(false, (bool)$secondPage['has_next_page']);

            $export = $service->fetchJournalsPage($fixture['company_id'], $fixture['accounting_period_id'], [
                'page' => 2,
                'page_size' => 1,
                'export_all' => true,
            ]);
            $harness->assertSame(2, (int)$export['total']);
            $harness->assertSame(2, count((array)$export['items']));
            $harness->assertSame(true, (bool)$export['export_all']);
            $harness->assertSame(false, (bool)$export['has_previous_page']);
            $harness->assertSame(false, (bool)$export['has_next_page']);

            $filteredExport = $service->fetchJournalsPage($fixture['company_id'], $fixture['accounting_period_id'], [
                'keyword' => 'Copper',
                'page_size' => 1,
                'export_all' => true,
            ]);
            $harness->assertSame(1, (int)$filteredExport['total']);
            $harness->assertSame($fixture['materials_journal_id'], (int)$filteredExport['items'][0]['id']);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function transactionJournalSplitTestCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('41' . $marker);
    $accountingPeriodId = (int)('42' . $marker);
    $sourceNominalId = transactionJournalSplitTestInsertNominal('TJB' . substr($marker, 0, 4), 'Journal Split Bank ' . $marker, 'asset', 'other');
    $toolNominalId = transactionJournalSplitTestInsertNominal('TJT' . substr($marker, 0, 4), 'Journal Split Tools ' . $marker, 'asset', 'capital');
    $materialsNominalId = transactionJournalSplitTestInsertNominal('TJM' . substr($marker, 0, 4), 'Journal Split Materials ' . $marker, 'cost_of_sales', 'allowable');
    $accountId = (int)('43' . $marker);
    $uploadId = (int)('44' . $marker);
    $transactionId = (int)('45' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Journal Split Fixture ' . $marker,
            'company_number' => 'JS' . substr($marker, 0, 6),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Journal Split FY ' . $marker,
            'period_start' => '2023-10-01',
            'period_end' => '2024-09-30',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $accountId,
            'company_id' => $companyId,
            'account_name' => 'Example Bank - Current Account',
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'nominal_account_id' => $sourceNominalId,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id,
            company_id,
            accounting_period_id,
            account_id,
            workflow_status,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :account_id,
            :workflow_status,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256
         )',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'workflow_status' => 'committed',
            'statement_month' => '2023-10-01',
            'original_filename' => 'journal-split-' . $marker . '.csv',
            'stored_filename' => 'journal-split-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'journal-split-upload-' . $marker),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            txn_type,
            description,
            amount,
            currency,
            source_account_label,
            dedupe_hash
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :txn_type,
            :description,
            :amount,
            :currency,
            :source_account_label,
            :dedupe_hash
         )',
        [
            'id' => $transactionId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => '2023-10-30',
            'txn_type' => 'POS',
            'description' => 'AMZNMKTPLACE',
            'amount' => '-146.36',
            'currency' => 'GBP',
            'source_account_label' => 'Example Bank - Current Account',
            'dedupe_hash' => hash('sha256', 'journal-split-transaction-' . $marker),
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'account_id' => $accountId,
        'transaction_id' => $transactionId,
        'source_nominal_id' => $sourceNominalId,
        'tool_nominal_id' => $toolNominalId,
        'materials_nominal_id' => $materialsNominalId,
    ];
}

function transactionJournalSplitTestInsertNominal(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $id = (int)random_int(200000000, 899999999);
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'id' => $id,
            'code' => substr($code, 0, 32),
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
        ]
    );

    return $id;
}

function transactionJournalSearchTestCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('51' . $marker);
    $accountingPeriodId = (int)('52' . $marker);
    $accountId = (int)('53' . $marker);
    $bankNominalId = transactionJournalSplitTestInsertNominal('TJSB' . substr($marker, 0, 4), 'Journal Search Bank ' . $marker, 'asset', 'other');
    $softwareNominalId = transactionJournalSplitTestInsertNominal('TJSS' . substr($marker, 0, 4), 'Journal Search Software ' . $marker, 'expense', 'allowable');
    $materialsNominalId = transactionJournalSplitTestInsertNominal('TJSM' . substr($marker, 0, 4), 'Journal Search Materials ' . $marker, 'cost_of_sales', 'allowable');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Journal Search Fixture ' . $marker,
            'company_number' => 'JQ' . substr($marker, 0, 6),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Journal Search FY ' . $marker,
            'period_start' => '2023-01-01',
            'period_end' => '2023-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $accountId,
            'company_id' => $companyId,
            'account_name' => 'Journal Search Current Account',
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'nominal_account_id' => $bankNominalId,
        ]
    );

    $softwareJournalId = transactionJournalSearchTestInsertJournal($companyId, $accountingPeriodId, 'search:software:' . $marker, '2023-02-01', 'Office software annual plan', [
        [
            'nominal_account_id' => $softwareNominalId,
            'debit' => '120.00',
            'credit' => '0.00',
            'line_description' => 'SaaS annual plan 100%',
        ],
        [
            'nominal_account_id' => $bankNominalId,
            'company_account_id' => $accountId,
            'debit' => '0.00',
            'credit' => '120.00',
            'line_description' => 'Example Bank settlement',
        ],
    ]);
    $materialsJournalId = transactionJournalSearchTestInsertJournal($companyId, $accountingPeriodId, 'search:materials:' . $marker, '2023-03-01', 'Materials purchase', [
        [
            'nominal_account_id' => $materialsNominalId,
            'debit' => '80.00',
            'credit' => '0.00',
            'line_description' => 'Copper_pipe!',
        ],
        [
            'nominal_account_id' => $bankNominalId,
            'company_account_id' => $accountId,
            'debit' => '0.00',
            'credit' => '80.00',
            'line_description' => 'Bank settlement',
        ],
    ]);

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'account_id' => $accountId,
        'bank_nominal_id' => $bankNominalId,
        'software_nominal_id' => $softwareNominalId,
        'materials_nominal_id' => $materialsNominalId,
        'software_journal_id' => $softwareJournalId,
        'materials_journal_id' => $materialsJournalId,
    ];
}

function transactionJournalSearchTestInsertJournal(
    int $companyId,
    int $accountingPeriodId,
    string $sourceRef,
    string $journalDate,
    string $description,
    array $lines
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => $description,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_type = :source_type
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
        ]
    );

    foreach ($lines as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, company_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :company_account_id, :debit, :credit, :line_description)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)($line['nominal_account_id'] ?? 0),
                'company_account_id' => isset($line['company_account_id']) && (int)$line['company_account_id'] > 0 ? (int)$line['company_account_id'] : null,
                'debit' => (string)($line['debit'] ?? '0.00'),
                'credit' => (string)($line['credit'] ?? '0.00'),
                'line_description' => (string)($line['line_description'] ?? ''),
            ]
        );
    }

    return $journalId;
}
