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
$harness->run(\eel_accounts\Service\YearEndMetricsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndMetricsService $service): void {
    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include first statement and mismatch detail', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'uploads' => [
                    yearEndMetricsUploadCheck([
                        'continuity_status' => 'warning',
                        'continuity_note' => 'No previous statement exists to compare against.',
                        'opening_balance' => 0.0,
                        'closing_balance' => 681.44,
                    ]),
                    yearEndMetricsUploadCheck([
                        'upload_id' => 314,
                        'filename' => 'BANK_011025_311025.csv',
                        'start' => '2025-10-01',
                        'end' => '2025-10-05',
                        'continuity_status' => 'fail',
                        'continuity_note' => 'Opening/closing mismatch.',
                        'opening_balance' => 600.00,
                        'closing_balance' => 121.44,
                        'previous_statement_closing_balance' => 681.44,
                    ]),
                ],
            ]),
        ]);

        $harness->assertCount(2, $issues);
        $harness->assertSame('statement_continuity', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame('Example Bank - Saving Pot (20%)', (string)($issues[0]['account_name'] ?? ''));
        $harness->assertSame('BANK_010925_300925.csv', (string)($issues[0]['upload_filename'] ?? ''));
        $harness->assertSame(0.0, (float)($issues[0]['opening_balance'] ?? -1));
        $harness->assertSame(null, $issues[0]['previous_statement_closing_balance'] ?? null);
        $harness->assertSame('No previous statement exists to compare against.', (string)($issues[0]['note'] ?? ''));
        $harness->assertSame('fail', (string)($issues[1]['status'] ?? ''));
        $harness->assertSame(681.44, (float)($issues[1]['previous_statement_closing_balance'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include running balance failed row numbers', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'statement_continuity_status' => 'pass',
                'running_balance_status' => 'fail',
                'uploads' => [
                    yearEndMetricsUploadCheck([
                        'continuity_status' => 'pass',
                        'running_balance_status' => 'fail',
                        'running_balance_note' => '10 rows tested, 2 balance breaks',
                        'balance_check_rows_tested' => 10,
                        'balance_check_rows_failed' => 2,
                        'failed_rows' => [
                            ['row_number' => 12],
                            ['row_number' => 15],
                        ],
                    ]),
                ],
            ]),
        ]);

        $harness->assertCount(1, $issues);
        $harness->assertSame('running_balance', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame(10, (int)($issues[0]['balance_check_rows_tested'] ?? 0));
        $harness->assertSame(2, (int)($issues[0]['balance_check_rows_failed'] ?? 0));
        $harness->assertSame([12, 15], $issues[0]['failed_row_numbers'] ?? []);
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include ledger reconciliation balances', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'ledger_reconciliation_status' => 'fail',
                'ledger_summary' => [
                    'statement_closing_date' => '2025-09-30',
                    'statement_closing_balance' => 911.03,
                    'ledger_balance' => 900.00,
                    'difference' => -11.03,
                    'note' => 'Difference may come from missing statement imports.',
                ],
            ]),
        ]);

        $harness->assertCount(1, $issues);
        $harness->assertSame('ledger_reconciliation', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame('2025-09-30', (string)($issues[0]['statement_closing_date'] ?? ''));
        $harness->assertSame(911.03, (float)($issues[0]['statement_closing_balance'] ?? 0));
        $harness->assertSame(900.00, (float)($issues[0]['ledger_balance'] ?? 0));
        $harness->assertSame(-11.03, (float)($issues[0]['difference'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'stranded committed rows require a same-company period journal', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'company_accounts', 'statement_uploads', 'statement_import_rows', 'transactions', 'journals', 'nominal_accounts'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $marker = 'YEMS' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 4));
            $primary = yearEndMetricsStrandedFixture($marker . 'A');
            $other = yearEndMetricsStrandedFixture($marker . 'B');
            $transactionId = (int)$primary['transaction_id'];
            $sourceRef = 'transaction:' . $transactionId;

            $harness->assertSame(1, $service->strandedCommittedSourceRowsCount((int)$primary['company_id'], (int)$primary['accounting_period_id']));

            yearEndMetricsInsertSourceJournal((int)$other['company_id'], (int)$other['accounting_period_id'], $sourceRef, 'Other company journal');
            $harness->assertSame(1, $service->strandedCommittedSourceRowsCount((int)$primary['company_id'], (int)$primary['accounting_period_id']));

            yearEndMetricsInsertSourceJournal((int)$primary['company_id'], (int)$primary['accounting_period_id'], $sourceRef, 'Matching journal');
            $harness->assertSame(0, $service->strandedCommittedSourceRowsCount((int)$primary['company_id'], (int)$primary['accounting_period_id']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function yearEndMetricsPrivateMethod(\eel_accounts\Service\YearEndMetricsService $service, string $methodName): ReflectionMethod
{
    $method = (new ReflectionClass($service))->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}

function yearEndMetricsPanel(array $overrides = []): array
{
    return array_replace_recursive([
        'account' => [
            'id' => 58,
            'account_name' => 'Example Bank - Saving Pot (20%)',
        ],
        'statement_continuity_status' => 'warning',
        'running_balance_status' => 'pass',
        'ledger_reconciliation_status' => 'pass',
        'uploads' => [],
        'ledger_summary' => [
            'status' => 'pass',
        ],
    ], $overrides);
}

function yearEndMetricsUploadCheck(array $overrides = []): array
{
    return array_replace_recursive([
        'upload' => [
            'id' => (int)($overrides['upload_id'] ?? 313),
            'original_filename' => (string)($overrides['filename'] ?? 'BANK_010925_300925.csv'),
            'statement_month' => '2025-09-01',
            'date_range_start' => (string)($overrides['start'] ?? '2025-09-20'),
            'date_range_end' => (string)($overrides['end'] ?? '2025-09-25'),
        ],
        'opening_balance' => 0.0,
        'closing_balance' => 681.44,
        'closing_date' => (string)($overrides['end'] ?? '2025-09-25'),
        'previous_statement_closing_balance' => null,
        'continuity_status' => 'warning',
        'continuity_note' => 'No previous statement exists to compare against.',
        'running_balance_status' => 'pass',
        'running_balance_note' => '3 rows tested, 0 breaks',
        'balance_check_rows_tested' => 3,
        'balance_check_rows_failed' => 0,
        'failed_rows' => [],
    ], array_diff_key($overrides, array_flip(['upload_id', 'filename', 'start', 'end'])));
}

function yearEndMetricsStrandedFixture(string $marker): array
{
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:company_name, :company_number, 1)',
        ['company_name' => 'Year End Metrics ' . $marker, 'company_number' => $marker]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => $marker, 'period_start' => '2024-01-01', 'period_end' => '2024-12-31']
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'label' => $marker]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (company_id, account_name, account_type, is_active)
         VALUES (:company_id, :account_name, :account_type, 1)',
        ['company_id' => $companyId, 'account_name' => $marker, 'account_type' => 'bank']
    );
    $accountId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'account_name' => $marker]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, account_id, statement_month,
            original_filename, stored_filename, file_sha256, workflow_status
         ) VALUES (
            :company_id, :accounting_period_id, :account_id, :statement_month,
            :original_filename, :stored_filename, :file_sha256, :workflow_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'statement_month' => '2024-06-01',
            'original_filename' => $marker . '.csv',
            'stored_filename' => $marker . '.csv',
            'file_sha256' => hash('sha256', $marker),
            'workflow_status' => 'completed',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND original_filename = :filename ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'filename' => $marker . '.csv']
    );
    $nominalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    if ($nominalId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active)
             VALUES (:code, :name, :account_type, :tax_treatment, 1)',
            [
                'code' => $marker,
                'name' => 'Stranded row nominal ' . $marker,
                'account_type' => 'expense',
                'tax_treatment' => 'allowable',
            ]
        );
        $nominalId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code ORDER BY id DESC LIMIT 1',
            ['code' => $marker]
        );
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id, accounting_period_id, account_id, statement_upload_id,
            txn_date, description, amount, dedupe_hash, nominal_account_id, category_status
         ) VALUES (
            :company_id, :accounting_period_id, :account_id, :statement_upload_id,
            :txn_date, :description, :amount, :dedupe_hash, :nominal_account_id, :category_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2024-06-02',
            'description' => 'Stranded row ' . $marker,
            'amount' => '10.00',
            'dedupe_hash' => hash('sha256', $marker . ':transaction'),
            'nominal_account_id' => $nominalId,
            'category_status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id AND dedupe_hash = :dedupe_hash ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId, 'dedupe_hash' => hash('sha256', $marker . ':transaction')]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_rows (
            upload_id, row_number, raw_json, accounting_period_id,
            validation_status, committed_transaction_id
         ) VALUES (
            :upload_id, 1, :raw_json, :accounting_period_id,
            :validation_status, :committed_transaction_id
         )',
        [
            'upload_id' => $uploadId,
            'raw_json' => '{}',
            'accounting_period_id' => $accountingPeriodId,
            'validation_status' => 'valid',
            'committed_transaction_id' => $transactionId,
        ]
    );

    return ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'transaction_id' => $transactionId];
}

function yearEndMetricsInsertSourceJournal(int $companyId, int $accountingPeriodId, string $sourceRef, string $description): void
{
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
            'source_type' => 'bank_csv',
            'source_ref' => $sourceRef,
            'journal_date' => '2024-06-02',
            'description' => $description,
        ]
    );
}
