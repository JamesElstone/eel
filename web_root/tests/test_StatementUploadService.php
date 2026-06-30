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
$harness->run(\eel_accounts\Service\StatementUploadService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\StatementUploadService $service): void {
    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'returns null for a missing file MIME detection', function () use ($harness, $service): void {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('detectMimeType');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-file.bin'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'resolveUploadDirectory uses the shared statement directory helper', function () use ($harness): void {
        $baseDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'statement-upload-service';
        $fileCheckService = new \eel_accounts\Service\FileCheckService([
            'upload_base_dir' => $baseDirectory,
            'statement_relative_path' => './statements/',
        ], null, static fn(int $companyId): string => $companyId === 42 ? '12345678' : '');
        $service = new \eel_accounts\Service\StatementUploadService($baseDirectory, null, null, $fileCheckService);
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveUploadDirectory');
        $method->setAccessible(true);

        $harness->assertSame(
            $baseDirectory . DIRECTORY_SEPARATOR . '12345678' . DIRECTORY_SEPARATOR . 'statements' . DIRECTORY_SEPARATOR,
            $method->invoke($service, 42)
        );
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'running balance check accepts matching rows in upload order', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, [
            statement_upload_test_row(1, '100.00', '1000.00'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(3, '10.00', '984.50'),
        ]));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'running balance check accepts matching rows in reverse upload order', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $harness->assertSame(null, $method->invoke($service, [
            statement_upload_test_row(3, '10.00', '984.50'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(1, '100.00', '1000.00'),
        ]));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'running balance break identifies only the trusted prefix', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('firstRunningBalanceBreak');
        $method->setAccessible(true);

        $break = $method->invoke($service, [
            statement_upload_test_row(1, '100.00', '1000.00'),
            statement_upload_test_row(2, '-25.50', '974.50'),
            statement_upload_test_row(3, '10.00', '990.00'),
            statement_upload_test_row(4, '-5.00', '985.00'),
        ]);

        $harness->assertSame(3, $break['break_row_number'] ?? null);
        $harness->assertSame([1, 2], $break['trusted_row_numbers'] ?? null);
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'duplicate file warning states no new upload record is created', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('buildDuplicateFileWarning');
        $method->setAccessible(true);

        $message = $method->invoke($service, [
            'id' => 42,
            'rows_parsed' => 93,
            'rows_committed' => 0,
        ]);

        $harness->assertTrue(str_contains($message, 'upload #42'));
        $harness->assertTrue(str_contains($message, 'no duplicate record was created'));
        $harness->assertSame(false, str_contains($message, 'A fresh upload record will be created'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'upload summary query deduplicates exact file hashes', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('uploadSummaryByAccountingPeriodSql');
        $method->setAccessible(true);

        $sql = $method->invoke($service);

        $harness->assertTrue(str_contains($sql, 'unique_uploads'));
        $harness->assertTrue(str_contains($sql, 'su.file_sha256'));
        $harness->assertTrue(str_contains($sql, 'GROUP BY COALESCE(su.accounting_period_id, ty.id),'));
        $harness->assertTrue(str_contains($sql, "COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id))"));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'unique monthly row count query deduplicates exact file hashes by source row', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('uniqueUploadedRowsByMonthSql');
        $method->setAccessible(true);

        $sql = $method->invoke($service);

        $harness->assertTrue(str_contains($sql, "COALESCE(NULLIF(su.file_sha256, ''), CONCAT('upload:', su.id))"));
        $harness->assertTrue(str_contains($sql, "sir.`row_number`"));
        $harness->assertTrue(str_contains($sql, 'unique_import_rows'));
        $harness->assertTrue(str_contains($sql, 'MAX(su.rows_parsed) AS raw_row_count'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'upload history period filter constrains unassigned uploads by statement dates', function () use ($harness, $service): void {
        $method = (new ReflectionClass($service))->getMethod('uploadHistoryAccountingPeriodFilterClause');
        $method->setAccessible(true);

        $sql = $method->invoke($service);

        $harness->assertTrue(str_contains($sql, 'su.accounting_period_id = ?'));
        $harness->assertTrue(str_contains($sql, 'su.accounting_period_id IS NULL'));
        $harness->assertTrue(str_contains($sql, 'COALESCE(su.date_range_start, su.statement_month) <= ?'));
        $harness->assertTrue(str_contains($sql, 'COALESCE(su.date_range_end, su.statement_month) >= ?'));
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'account mapping preview uses committed mappings without confirmed timestamp', function () use ($harness, $service): void {
        foreach (['companies', 'company_accounts', 'statement_uploads', 'statement_import_mappings', 'statement_import_rows'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
            }
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = 'MAP' . bin2hex(random_bytes(4));
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                [
                    'company_name' => 'Mapping Preview Fixture ' . $marker,
                    'company_number' => $marker,
                ]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
                ['company_number' => $marker]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO company_accounts (company_id, account_name, account_type, is_active) VALUES (:company_id, :account_name, :account_type, 1)',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture Current Account ' . $marker,
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                ]
            );
            $accountId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'account_name' => 'Fixture Current Account ' . $marker,
                ]
            );

            $headers = ['Date', 'Description', 'Amount'];
            InterfaceDB::prepareExecute(
                'INSERT INTO statement_uploads (
                    company_id,
                    account_id,
                    source_type,
                    workflow_status,
                    statement_month,
                    original_filename,
                    stored_filename,
                    file_sha256,
                    source_headers_json,
                    rows_parsed,
                    rows_committed
                ) VALUES (
                    :company_id,
                    :account_id,
                    :source_type,
                    :workflow_status,
                    :statement_month,
                    :original_filename,
                    :stored_filename,
                    :file_sha256,
                    :source_headers_json,
                    :rows_parsed,
                    :rows_committed
                )',
                [
                    'company_id' => $companyId,
                    'account_id' => $accountId,
                    'source_type' => \eel_accounts\Service\StatementUploadService::SOURCE_TYPE,
                    'workflow_status' => 'committed',
                    'statement_month' => '2026-01-01',
                    'original_filename' => 'committed-fixture.csv',
                    'stored_filename' => 'missing-fixture.csv',
                    'file_sha256' => hash('sha256', $marker),
                    'source_headers_json' => json_encode($headers, JSON_THROW_ON_ERROR),
                    'rows_parsed' => 1,
                    'rows_committed' => 1,
                ]
            );
            $uploadId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM statement_uploads WHERE company_id = :company_id AND stored_filename = :stored_filename ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'stored_filename' => 'missing-fixture.csv',
                ]
            );

            InterfaceDB::prepareExecute(
                'INSERT INTO statement_import_mappings (
                    upload_id,
                    source_type,
                    mapping_origin,
                    original_headers_json,
                    mapping_json,
                    confirmed_at
                ) VALUES (
                    :upload_id,
                    :source_type,
                    :mapping_origin,
                    :original_headers_json,
                    :mapping_json,
                    NULL
                )',
                [
                    'upload_id' => $uploadId,
                    'source_type' => \eel_accounts\Service\StatementUploadService::SOURCE_TYPE,
                    'mapping_origin' => 'auto',
                    'original_headers_json' => json_encode($headers, JSON_THROW_ON_ERROR),
                    'mapping_json' => json_encode([
                        'created' => ['header' => 'Date', 'index' => 0],
                        'processed' => null,
                        'description' => ['header' => 'Description', 'index' => 1],
                        'amount' => ['header' => 'Amount', 'index' => 2],
                    ], JSON_THROW_ON_ERROR),
                ]
            );

            $preview = $service->fetchAccountMappingPreview($companyId, $accountId);

            $harness->assertSame($uploadId, (int)($preview['upload']['id'] ?? 0));
            $harness->assertSame('committed-fixture.csv', (string)($preview['upload']['original_filename'] ?? ''));
            $harness->assertSame('auto', (string)($preview['mapping']['mapping_origin'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'commit writes mapped reference counterparty and card fields', function () use ($harness, $service): void {
        statement_upload_require_tables($harness, ['companies', 'company_accounts', 'accounting_periods', 'statement_uploads', 'statement_import_mappings', 'statement_import_rows', 'transactions']);

        $companyId = 0;

        try {
            $fixture = statement_upload_create_import_fixture('commit-mapped-fields');
            $companyId = $fixture['company_id'];
            $periodId = $fixture['period_id'];
            $uploadId = $fixture['upload_id'];
            $headers = ['date', 'type', 'description', 'reference', 'amount', 'balance', 'name', 'card'];
            $values = ['30/10/2022', 'FP', 'ELSTONE IT SERVICE', 'TOOL HIRE', '-100.00', '197.55', 'James Elstone', 'CARD-1234'];

            statement_upload_insert_mapping($uploadId, $headers, [
                'created' => ['header' => 'date', 'index' => 0],
                'type' => ['header' => 'type', 'index' => 1],
                'description' => ['header' => 'description', 'index' => 2],
                'reference' => ['header' => 'reference', 'index' => 3],
                'amount' => ['header' => 'amount', 'index' => 4],
                'balance' => ['header' => 'balance', 'index' => 5],
                'counterparty' => ['header' => 'name', 'index' => 6],
                'card' => ['header' => 'card', 'index' => 7],
                'currency' => ['default_value' => 'GBP', 'label' => '£ GBP'],
            ]);

            statement_upload_insert_import_row($uploadId, $periodId, $companyId, $headers, $values);

            $result = $service->commitUpload($companyId, $uploadId);
            $harness->assertSame(true, $result['success'] ?? false);

            $transaction = InterfaceDB::fetchOne(
                'SELECT txn_type, description, reference, counterparty_name, card, amount, balance
                 FROM transactions
                 WHERE company_id = :company_id
                   AND statement_upload_id = :upload_id
                 LIMIT 1',
                [
                    'company_id' => $companyId,
                    'upload_id' => $uploadId,
                ]
            );

            $harness->assertSame('FP', (string)($transaction['txn_type'] ?? ''));
            $harness->assertSame('ELSTONE IT SERVICE', (string)($transaction['description'] ?? ''));
            $harness->assertSame('TOOL HIRE', (string)($transaction['reference'] ?? ''));
            $harness->assertSame('James Elstone', (string)($transaction['counterparty_name'] ?? ''));
            $harness->assertSame('CARD-1234', (string)($transaction['card'] ?? ''));
            $harness->assertSame('-100.00', number_format((float)($transaction['amount'] ?? 0), 2, '.', ''));
            $harness->assertSame('197.55', number_format((float)($transaction['balance'] ?? 0), 2, '.', ''));
        } finally {
            statement_upload_delete_company($companyId);
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'mapping backfill fills legacy raw json values without overwriting existing fields', function () use ($harness, $service): void {
        statement_upload_require_tables($harness, ['companies', 'company_accounts', 'accounting_periods', 'statement_uploads', 'statement_import_mappings', 'statement_import_rows', 'transactions']);

        $companyId = 0;

        try {
            $fixture = statement_upload_create_import_fixture('backfill-mapped-fields');
            $companyId = $fixture['company_id'];
            $periodId = $fixture['period_id'];
            $uploadId = $fixture['upload_id'];
            $headers = ['date', 'type', 'description', 'reference', 'amount', 'balance', 'name', 'card'];

            statement_upload_insert_mapping($uploadId, $headers, [
                'created' => ['header' => 'date', 'index' => 0],
                'description' => ['header' => 'description', 'index' => 2],
                'amount' => ['header' => 'amount', 'index' => 4],
                'currency' => ['default_value' => 'GBP', 'label' => '£ GBP'],
            ]);

            $blankTransactionId = statement_upload_insert_transaction($companyId, $periodId, $uploadId, 'Blank metadata transaction', '-100.00', [
                'txn_type' => null,
                'reference' => null,
                'counterparty_name' => null,
                'card' => null,
            ]);
            statement_upload_insert_import_row(
                $uploadId,
                $periodId,
                $companyId,
                $headers,
                ['30/10/2022', 'FP', 'ELSTONE IT SERVICE', 'TOOL HIRE', '-100.00', '197.55', 'James Elstone', 'CARD-1234'],
                $blankTransactionId
            );

            $existingTransactionId = statement_upload_insert_transaction($companyId, $periodId, $uploadId, 'Existing metadata transaction', '-25.00', [
                'txn_type' => 'KEEP TYPE',
                'reference' => 'KEEP REF',
                'counterparty_name' => null,
                'card' => 'KEEP CARD',
            ]);
            statement_upload_insert_import_row(
                $uploadId,
                $periodId,
                $companyId,
                $headers,
                ['31/10/2022', 'CP', 'SECOND PAYMENT', 'RAW REF', '-25.00', '172.55', 'Raw Name', 'RAW CARD'],
                $existingTransactionId
            );

            $result = $service->backfillTransactionTypesFromStagedImportJson($companyId);
            $harness->assertSame(true, $result['success'] ?? false);
            $harness->assertSame(2, (int)($result['rows_scanned'] ?? 0));
            $harness->assertSame(2, (int)($result['rows_updated'] ?? 0));

            $blankTransaction = InterfaceDB::fetchOne(
                'SELECT txn_type, reference, counterparty_name, card FROM transactions WHERE id = :id',
                ['id' => $blankTransactionId]
            );
            $harness->assertSame('FP', (string)($blankTransaction['txn_type'] ?? ''));
            $harness->assertSame('TOOL HIRE', (string)($blankTransaction['reference'] ?? ''));
            $harness->assertSame('James Elstone', (string)($blankTransaction['counterparty_name'] ?? ''));
            $harness->assertSame('CARD-1234', (string)($blankTransaction['card'] ?? ''));

            $existingTransaction = InterfaceDB::fetchOne(
                'SELECT txn_type, reference, counterparty_name, card FROM transactions WHERE id = :id',
                ['id' => $existingTransactionId]
            );
            $harness->assertSame('KEEP TYPE', (string)($existingTransaction['txn_type'] ?? ''));
            $harness->assertSame('KEEP REF', (string)($existingTransaction['reference'] ?? ''));
            $harness->assertSame('Raw Name', (string)($existingTransaction['counterparty_name'] ?? ''));
            $harness->assertSame('KEEP CARD', (string)($existingTransaction['card'] ?? ''));
        } finally {
            statement_upload_delete_company($companyId);
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'upload save rejects committed mapping changes without correction flag', function () use ($harness, $service): void {
        statement_upload_require_tables($harness, ['companies', 'company_accounts', 'accounting_periods', 'statement_uploads', 'statement_import_mappings']);

        $companyId = 0;

        try {
            $fixture = statement_upload_create_import_fixture('committed-mapping-reject');
            $companyId = $fixture['company_id'];
            $uploadId = $fixture['upload_id'];
            $accountId = $fixture['account_id'];
            $headers = ['date', 'description', 'reference', 'amount', 'balance', 'currency'];

            statement_upload_mark_committed($uploadId);
            statement_upload_insert_mapping($uploadId, $headers, [
                'created' => ['header' => 'date', 'index' => 0],
                'description' => ['header' => 'description', 'index' => 1],
                'amount' => ['header' => 'amount', 'index' => 3],
                'balance' => ['header' => 'balance', 'index' => 4],
                'currency' => ['header' => 'currency', 'index' => 5],
            ]);

            $result = $service->saveFieldMapping([
                'company_id' => $companyId,
                'upload_id' => $uploadId,
                'account_id' => $accountId,
                'mapping_created' => 'date',
                'mapping_description' => 'description',
                'mapping_reference' => 'reference',
                'mapping_amount' => 'amount',
                'mapping_balance' => 'balance',
                'mapping_currency' => 'currency',
            ]);

            $harness->assertSame(false, $result['success'] ?? true);
            $harness->assertSame(409, (int)($result['http_status'] ?? 0));
            $harness->assertTrue(str_contains((string)($result['errors'][0] ?? ''), 'already been committed'));
        } finally {
            statement_upload_delete_company($companyId);
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'committed correction saves editable mappings and preserves protected mappings', function () use ($harness, $service): void {
        statement_upload_require_tables($harness, ['companies', 'company_accounts', 'accounting_periods', 'statement_uploads', 'statement_import_mappings']);

        $companyId = 0;

        try {
            $fixture = statement_upload_create_import_fixture('committed-mapping-correction');
            $companyId = $fixture['company_id'];
            $uploadId = $fixture['upload_id'];
            $accountId = $fixture['account_id'];
            $headers = ['date', 'posted', 'description', 'reference', 'amount', 'balance', 'currency', 'memo'];

            statement_upload_mark_committed($uploadId);
            statement_upload_insert_mapping($uploadId, $headers, [
                'created' => ['header' => 'date', 'index' => 0],
                'processed' => ['header' => 'posted', 'index' => 1],
                'description' => ['header' => 'description', 'index' => 2],
                'reference' => null,
                'amount' => ['header' => 'amount', 'index' => 4],
                'balance' => ['header' => 'balance', 'index' => 5],
                'currency' => ['header' => 'currency', 'index' => 6],
            ]);

            $result = $service->saveFieldMapping([
                'company_id' => $companyId,
                'upload_id' => $uploadId,
                'account_id' => $accountId,
                'allow_committed_mapping_update' => true,
                'mapping_created' => 'posted',
                'mapping_processed' => 'date',
                'mapping_description' => 'memo',
                'mapping_reference' => 'reference',
                'mapping_amount' => 'memo',
                'mapping_balance' => 'memo',
                'mapping_currency' => 'memo',
            ]);

            $harness->assertSame(true, $result['success'] ?? false);
            $harness->assertTrue(str_contains(implode(' ', array_map('strval', (array)($result['warnings'] ?? []))), 'Protected committed mappings were not changed'));
            $harness->assertSame(
                'Mapped CSV column “memo” to field “Description” for ' . $fixture['account_name'] . '.',
                (string)($result['mapping_flash_message'] ?? '')
            );

            $upload = InterfaceDB::fetchOne(
                'SELECT workflow_status, rows_committed FROM statement_uploads WHERE id = :id',
                ['id' => $uploadId]
            );
            $harness->assertSame('committed', (string)($upload['workflow_status'] ?? ''));
            $harness->assertSame(1, (int)($upload['rows_committed'] ?? 0));

            $mappingRow = $service->fetchUploadMapping($uploadId);
            $mapping = json_decode((string)($mappingRow['mapping_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

            $harness->assertSame('date', (string)($mapping['created']['header'] ?? ''));
            $harness->assertSame('posted', (string)($mapping['processed']['header'] ?? ''));
            $harness->assertSame('amount', (string)($mapping['amount']['header'] ?? ''));
            $harness->assertSame('balance', (string)($mapping['balance']['header'] ?? ''));
            $harness->assertSame('currency', (string)($mapping['currency']['header'] ?? ''));
            $harness->assertSame('memo', (string)($mapping['description']['header'] ?? ''));
            $harness->assertSame('reference', (string)($mapping['reference']['header'] ?? ''));
        } finally {
            statement_upload_delete_company($companyId);
        }
    });

    $harness->check(\eel_accounts\Service\StatementUploadService::class, 'backfill uses corrected committed reference mapping', function () use ($harness, $service): void {
        statement_upload_require_tables($harness, ['companies', 'company_accounts', 'accounting_periods', 'statement_uploads', 'statement_import_mappings', 'statement_import_rows', 'transactions']);

        $companyId = 0;

        try {
            $fixture = statement_upload_create_import_fixture('committed-reference-backfill');
            $companyId = $fixture['company_id'];
            $periodId = $fixture['period_id'];
            $uploadId = $fixture['upload_id'];
            $accountId = $fixture['account_id'];
            $headers = ['date', 'type', 'description', 'reference', 'amount', 'balance'];

            statement_upload_mark_committed($uploadId);
            statement_upload_insert_mapping($uploadId, $headers, [
                'created' => ['header' => 'date', 'index' => 0],
                'description' => ['header' => 'description', 'index' => 2],
                'reference' => null,
                'amount' => ['header' => 'amount', 'index' => 4],
                'balance' => ['header' => 'balance', 'index' => 5],
                'currency' => ['default_value' => 'GBP', 'label' => '£ GBP'],
            ]);

            $transactionId = statement_upload_insert_transaction($companyId, $periodId, $uploadId, 'ELSTONE IT SERVICE', '-100.00', [
                'reference' => null,
            ]);
            statement_upload_insert_import_row(
                $uploadId,
                $periodId,
                $companyId,
                $headers,
                ['30/10/2022', 'FP', 'ELSTONE IT SERVICE', 'TOOL HIRE', '-100.00', '197.55'],
                $transactionId
            );

            $saveResult = $service->saveFieldMapping([
                'company_id' => $companyId,
                'upload_id' => $uploadId,
                'account_id' => $accountId,
                'allow_committed_mapping_update' => true,
                'mapping_created' => 'date',
                'mapping_description' => 'description',
                'mapping_reference' => 'reference',
                'mapping_amount' => 'amount',
                'mapping_balance' => 'balance',
                'mapping_currency' => \eel_accounts\Service\StatementUploadService::CURRENCY_DEFAULT_OPTION_GBP,
            ]);
            $harness->assertSame(true, $saveResult['success'] ?? false);
            $harness->assertSame(
                'Mapped CSV column “reference” to field “Reference” for ' . $fixture['account_name'] . '.',
                (string)($saveResult['mapping_flash_message'] ?? '')
            );

            $backfillResult = $service->backfillTransactionTypesFromStagedImportJson($companyId);
            $harness->assertSame(true, $backfillResult['success'] ?? false);

            $transaction = InterfaceDB::fetchOne(
                'SELECT reference FROM transactions WHERE id = :id',
                ['id' => $transactionId]
            );
            $harness->assertSame('TOOL HIRE', (string)($transaction['reference'] ?? ''));
        } finally {
            statement_upload_delete_company($companyId);
        }
    });
});

function statement_upload_test_row(int $rowNumber, string $amount, string $balance): array
{
    return [
        'row_number' => $rowNumber,
        'normalised_amount' => $amount,
        'normalised_balance' => $balance,
    ];
}

function statement_upload_require_tables(GeneratedServiceClassTestHarness $harness, array $tables): void
{
    foreach ($tables as $table) {
        if (!InterfaceDB::tableExists((string)$table)) {
            $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
        }
    }
}

function statement_upload_create_import_fixture(string $label): array
{
    $marker = $label . '-' . bin2hex(random_bytes(4));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        [
            'company_name' => 'Statement Upload Fixture ' . $marker,
            'company_number' => $marker,
        ]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :company_number ORDER BY id DESC LIMIT 1',
        ['company_number' => $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'Statement Upload Fixture FY',
            'period_start' => '2022-10-01',
            'period_end' => '2022-10-31',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (company_id, account_name, account_type, is_active) VALUES (:company_id, :account_name, :account_type, :is_active)',
        [
            'company_id' => $companyId,
            'account_name' => 'Fixture Account ' . $marker,
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'is_active' => 1,
        ]
    );
    $accountId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_accounts WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            account_id,
            source_type,
            workflow_status,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            source_headers_json,
            rows_parsed,
            rows_ready_to_import
        ) VALUES (
            :company_id,
            :accounting_period_id,
            :account_id,
            :source_type,
            :workflow_status,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :source_headers_json,
            :rows_parsed,
            :rows_ready_to_import
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'account_id' => $accountId,
            'source_type' => \eel_accounts\Service\StatementUploadService::SOURCE_TYPE,
            'workflow_status' => 'staged',
            'statement_month' => '2022-10-01',
            'original_filename' => $marker . '.csv',
            'stored_filename' => $marker . '.csv',
            'file_sha256' => hash('sha256', $marker),
            'source_headers_json' => '[]',
            'rows_parsed' => 1,
            'rows_ready_to_import' => 1,
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    return [
        'company_id' => $companyId,
        'period_id' => $periodId,
        'account_id' => $accountId,
        'account_name' => 'Fixture Account ' . $marker,
        'upload_id' => $uploadId,
    ];
}

function statement_upload_mark_committed(int $uploadId): void
{
    InterfaceDB::prepareExecute(
        'UPDATE statement_uploads
         SET workflow_status = :workflow_status,
             rows_committed = :rows_committed,
             committed_at = :committed_at
         WHERE id = :id',
        [
            'workflow_status' => 'committed',
            'rows_committed' => 1,
            'committed_at' => '2026-01-01 00:00:00',
            'id' => $uploadId,
        ]
    );
}

function statement_upload_insert_mapping(int $uploadId, array $headers, array $mapping): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_mappings (
            upload_id,
            source_type,
            mapping_origin,
            original_headers_json,
            mapping_json,
            confirmed_at
        ) VALUES (
            :upload_id,
            :source_type,
            :mapping_origin,
            :original_headers_json,
            :mapping_json,
            :confirmed_at
        )',
        [
            'upload_id' => $uploadId,
            'source_type' => \eel_accounts\Service\StatementUploadService::SOURCE_TYPE,
            'mapping_origin' => 'manual',
            'original_headers_json' => json_encode($headers, JSON_THROW_ON_ERROR),
            'mapping_json' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'confirmed_at' => '2026-01-01 00:00:00',
        ]
    );
}

function statement_upload_insert_import_row(
    int $uploadId,
    int $periodId,
    int $companyId,
    array $headers,
    array $values,
    ?int $committedTransactionId = null
): void {
    $description = (string)($values[2] ?? '');
    $amount = (string)($values[4] ?? '0.00');
    $balance = (string)($values[5] ?? '');
    $rowHash = \eel_accounts\Service\StatementUploadService::buildRowHash(
        $companyId,
        '2022-10-30',
        $description,
        $amount,
        $balance,
        'GBP',
        'Fixture Account'
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_rows (
            upload_id,
            row_number,
            raw_json,
            source_account,
            source_created,
            source_description,
            source_amount,
            source_balance,
            source_currency,
            accounting_period_id,
            chosen_txn_date,
            chosen_date_source,
            normalised_description,
            normalised_amount,
            normalised_balance,
            normalised_currency,
            row_hash,
            validation_status,
            is_duplicate_within_upload,
            is_duplicate_existing,
            committed_transaction_id
        ) VALUES (
            :upload_id,
            :row_number,
            :raw_json,
            :source_account,
            :source_created,
            :source_description,
            :source_amount,
            :source_balance,
            :source_currency,
            :accounting_period_id,
            :chosen_txn_date,
            :chosen_date_source,
            :normalised_description,
            :normalised_amount,
            :normalised_balance,
            :normalised_currency,
            :row_hash,
            :validation_status,
            :is_duplicate_within_upload,
            :is_duplicate_existing,
            :committed_transaction_id
        )',
        [
            'upload_id' => $uploadId,
            'row_number' => $committedTransactionId === null ? 1 : $committedTransactionId,
            'raw_json' => json_encode([
                'headers' => array_values($headers),
                'values' => array_values($values),
                'column_count' => count($values),
            ], JSON_THROW_ON_ERROR),
            'source_account' => 'Fixture Account',
            'source_created' => (string)($values[0] ?? ''),
            'source_description' => $description,
            'source_amount' => $amount,
            'source_balance' => $balance,
            'source_currency' => 'GBP',
            'accounting_period_id' => $periodId,
            'chosen_txn_date' => '2022-10-30',
            'chosen_date_source' => 'created',
            'normalised_description' => $description,
            'normalised_amount' => $amount,
            'normalised_balance' => $balance,
            'normalised_currency' => 'GBP',
            'row_hash' => $rowHash . ($committedTransactionId === null ? '' : substr(hash('sha256', (string)$committedTransactionId), 0, 4)),
            'validation_status' => 'valid',
            'is_duplicate_within_upload' => 0,
            'is_duplicate_existing' => 0,
            'committed_transaction_id' => $committedTransactionId,
        ]
    );
}

function statement_upload_insert_transaction(
    int $companyId,
    int $periodId,
    int $uploadId,
    string $description,
    string $amount,
    array $metadata
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id,
            accounting_period_id,
            statement_upload_id,
            txn_date,
            txn_type,
            description,
            reference,
            amount,
            currency,
            source_type,
            counterparty_name,
            card,
            dedupe_hash
        ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :txn_type,
            :description,
            :reference,
            :amount,
            :currency,
            :source_type,
            :counterparty_name,
            :card,
            :dedupe_hash
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2022-10-30',
            'txn_type' => $metadata['txn_type'] ?? null,
            'description' => $description,
            'reference' => $metadata['reference'] ?? null,
            'amount' => $amount,
            'currency' => 'GBP',
            'source_type' => \eel_accounts\Service\StatementUploadService::SOURCE_TYPE,
            'counterparty_name' => $metadata['counterparty_name'] ?? null,
            'card' => $metadata['card'] ?? null,
            'dedupe_hash' => hash('sha256', uniqid('statement-upload-test', true)),
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );
}

function statement_upload_delete_company(int $companyId): void
{
    if ($companyId <= 0 || !InterfaceDB::tableExists('companies')) {
        return;
    }

    InterfaceDB::prepareExecute('DELETE FROM companies WHERE id = :id', ['id' => $companyId]);
}
