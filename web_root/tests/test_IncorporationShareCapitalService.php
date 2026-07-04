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

$harness->run(\eel_accounts\Service\IncorporationShareCapitalService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\IncorporationShareCapitalService $service
): void {
    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'saves formation share capital and calculates totals', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => '100',
                'nominal_value_per_share' => '1.00',
                'paid_value_per_share' => '1.00',
                'unpaid_value_per_share' => '0.00',
                'source_note' => 'IN01 statement of capital',
                'document_reference' => 'incorporation-pdf',
            ]);

            $harness->assertSame(true, (bool)($saved['success'] ?? false));
            $summary = $service->fetchSummary((int)$fixture['company_id']);
            $harness->assertSame(true, (bool)($summary['available'] ?? false));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['issued_nominal_total'] ?? 0));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['expected_paid_total'] ?? 0));
            $harness->assertSame(0.00, (float)(($summary['totals'] ?? [])['unpaid_total'] ?? 0));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['unmatched_paid_total'] ?? 0));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['paid_up_unpaid_total'] ?? 0));
            $shareClass = (array)(($summary['share_classes'] ?? [])[0] ?? []);
            $harness->assertSame('shares_not_paid_up', (string)($summary['status'] ?? ''));
            $harness->assertSame('not_paid_up', (string)($shareClass['payment_status'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'derives per-share values from Companies House aggregate statement fields', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => '10,000',
                'aggregate_nominal_value' => '50,000',
                'total_aggregate_unpaid' => '0',
                'source_note' => 'FULL RIGHTS REGARDING VOTING, PAYMENT OF DIVIDENDS AND DISTRIBUTIONS',
                'document_reference' => 'Model articles adopted',
            ]);

            $harness->assertSame(true, (bool)($saved['success'] ?? false));
            $summary = $service->fetchSummary((int)$fixture['company_id']);
            $shareClass = (array)(($summary['share_classes'] ?? [])[0] ?? []);
            $harness->assertSame(50000.00, (float)(($summary['totals'] ?? [])['issued_nominal_total'] ?? 0));
            $harness->assertSame(50000.00, (float)(($summary['totals'] ?? [])['expected_paid_total'] ?? 0));
            $harness->assertSame(0.00, (float)(($summary['totals'] ?? [])['unpaid_total'] ?? 0));
            $harness->assertSame(50000.00, (float)(($summary['totals'] ?? [])['paid_up_unpaid_total'] ?? 0));
            $harness->assertSame(10000, (int)($shareClass['quantity'] ?? 0));
            $harness->assertSame(5.00, (float)($shareClass['nominal_value_per_share'] ?? 0));
            $harness->assertSame(5.00, (float)($shareClass['paid_value_per_share'] ?? 0));
            $harness->assertSame(0.00, (float)($shareClass['unpaid_value_per_share'] ?? 1));
        });
    });

    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'finds and posts exact incoming share payment matches', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => 100,
                'nominal_value_per_share' => '1.00',
                'paid_value_per_share' => '1.00',
                'unpaid_value_per_share' => '0.00',
            ]);
            $shareClassId = (int)($saved['share_class_id'] ?? 0);
            $transactionId = incorporation_share_service_insert_transaction($fixture, 100.00, '2026-08-10', 'Ordinary share capital payment');

            $candidates = $service->paymentCandidates((int)$fixture['company_id'], $shareClassId);
            $harness->assertSame(true, in_array($transactionId, array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $candidates), true));

            $matched = $service->matchPayment((int)$fixture['company_id'], $shareClassId, $transactionId, 'test');
            $harness->assertSame(true, (bool)($matched['success'] ?? false));

            $transaction = \InterfaceDB::fetchOne('SELECT nominal_account_id, category_status FROM transactions WHERE id = :id', ['id' => $transactionId]);
            $harness->assertSame((int)$fixture['share_capital_nominal_id'], (int)($transaction['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journals
                 WHERE company_id = :company_id
                   AND source_type = :source_type
                   AND source_ref = :source_ref',
                [
                    'company_id' => $fixture['company_id'],
                    'source_type' => 'bank_csv',
                    'source_ref' => 'transaction:' . $transactionId,
                ]
            ));
            $summary = $service->fetchSummary((int)$fixture['company_id']);
            $harness->assertSame('complete', (string)($summary['status'] ?? ''));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['matched_total'] ?? 0));
            $harness->assertSame(0.00, (float)(($summary['totals'] ?? [])['paid_up_unpaid_total'] ?? 1));
            $shareClass = (array)(($summary['share_classes'] ?? [])[0] ?? []);
            $currentMatch = (array)($shareClass['current_match'] ?? []);
            $harness->assertSame(true, (bool)($currentMatch['match_valid'] ?? false));
            $harness->assertSame('payment_matched', (string)($shareClass['payment_status'] ?? ''));
            $harness->assertSame([], $service->paymentCandidates((int)$fixture['company_id'], $shareClassId));
        });
    });

    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'treats recategorised matched transactions as not paid up without clearing audit history', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => 100,
                'nominal_value_per_share' => '1.00',
                'paid_value_per_share' => '1.00',
                'unpaid_value_per_share' => '0.00',
            ]);
            $shareClassId = (int)($saved['share_class_id'] ?? 0);
            $transactionId = incorporation_share_service_insert_transaction($fixture, 100.00, '2026-01-10', 'Ordinary share capital payment');
            $matched = $service->matchPayment((int)$fixture['company_id'], $shareClassId, $transactionId, 'test');
            $harness->assertSame(true, (bool)($matched['success'] ?? false));

            $expenseNominalId = incorporation_share_service_nominal('6071', 'Fixture Recategorised Expense ' . $fixture['marker'], 'expense');
            \InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET nominal_account_id = :nominal_account_id,
                     category_status = :category_status
                 WHERE id = :id',
                [
                    'nominal_account_id' => $expenseNominalId,
                    'category_status' => 'manual',
                    'id' => $transactionId,
                ]
            );

            $summary = $service->fetchSummary((int)$fixture['company_id']);
            $shareClass = (array)(($summary['share_classes'] ?? [])[0] ?? []);
            $currentMatch = (array)($shareClass['current_match'] ?? []);
            $harness->assertSame('shares_not_paid_up', (string)($summary['status'] ?? ''));
            $harness->assertSame(0.00, (float)(($summary['totals'] ?? [])['matched_total'] ?? 1));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['paid_up_unpaid_total'] ?? 0));
            $harness->assertSame('not_paid_up', (string)($shareClass['payment_status'] ?? ''));
            $harness->assertSame(false, (bool)($currentMatch['match_valid'] ?? true));
            $harness->assertSame('transaction_recategorised', (string)($currentMatch['match_invalid_reason'] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM company_incorporation_share_payment_matches WHERE share_class_id = :share_class_id AND match_status = :status',
                ['share_class_id' => $shareClassId, 'status' => 'current']
            ));

            \InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET nominal_account_id = :nominal_account_id,
                     category_status = :category_status
                 WHERE id = :id',
                [
                    'nominal_account_id' => (int)$fixture['share_capital_nominal_id'],
                    'category_status' => 'manual',
                    'id' => $transactionId,
                ]
            );

            $restoredSummary = $service->fetchSummary((int)$fixture['company_id']);
            $restoredShareClass = (array)(($restoredSummary['share_classes'] ?? [])[0] ?? []);
            $restoredMatch = (array)($restoredShareClass['current_match'] ?? []);
            $harness->assertSame('complete', (string)($restoredSummary['status'] ?? ''));
            $harness->assertSame(100.00, (float)(($restoredSummary['totals'] ?? [])['matched_total'] ?? 0));
            $harness->assertSame(0.00, (float)(($restoredSummary['totals'] ?? [])['paid_up_unpaid_total'] ?? 1));
            $harness->assertSame('payment_matched', (string)($restoredShareClass['payment_status'] ?? ''));
            $harness->assertSame(true, (bool)($restoredMatch['match_valid'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'rejects mismatched or out-of-company payment matches', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => 100,
                'nominal_value_per_share' => '1.00',
                'paid_value_per_share' => '1.00',
                'unpaid_value_per_share' => '0.00',
            ]);
            $shareClassId = (int)($saved['share_class_id'] ?? 0);
            $wrongAmountTransactionId = incorporation_share_service_insert_transaction($fixture, 99.00, '2026-01-10', 'Ordinary share capital payment');
            $wrongAmount = $service->matchPayment((int)$fixture['company_id'], $shareClassId, $wrongAmountTransactionId, 'test');
            $harness->assertSame(false, (bool)($wrongAmount['success'] ?? true));
            $harness->assertSame(true, str_contains(implode(' ', (array)($wrongAmount['errors'] ?? [])), 'amount'));

            $otherTransactionId = incorporation_share_service_insert_other_company_transaction($fixture, 100.00, '2026-01-10', 'Ordinary share capital payment');
            $wrongCompany = $service->matchPayment((int)$fixture['company_id'], $shareClassId, $otherTransactionId, 'test');
            $harness->assertSame(false, (bool)($wrongCompany['success'] ?? true));
            $harness->assertSame(true, str_contains(implode(' ', (array)($wrongCompany['errors'] ?? [])), 'different company'));
        });
    });

    $harness->check(\eel_accounts\Service\IncorporationShareCapitalService::class, 'clears payment matches without deleting audit history', static function () use ($harness, $service): void {
        incorporation_share_service_with_fixture($harness, static function (array $fixture) use ($harness, $service): void {
            $saved = $service->saveShareClass([
                'company_id' => $fixture['company_id'],
                'share_class' => 'Ordinary',
                'currency' => 'GBP',
                'quantity' => 100,
                'nominal_value_per_share' => '1.00',
                'paid_value_per_share' => '1.00',
                'unpaid_value_per_share' => '0.00',
            ]);
            $shareClassId = (int)($saved['share_class_id'] ?? 0);
            $transactionId = incorporation_share_service_insert_transaction($fixture, 100.00, '2026-01-10', 'Ordinary share capital payment');
            $matched = $service->matchPayment((int)$fixture['company_id'], $shareClassId, $transactionId, 'test');
            $harness->assertSame(true, (bool)($matched['success'] ?? false));

            $cleared = $service->clearPaymentMatch((int)$fixture['company_id'], $shareClassId, 'test');
            $harness->assertSame(true, (bool)($cleared['success'] ?? false));
            $harness->assertSame(0, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM company_incorporation_share_payment_matches WHERE share_class_id = :share_class_id AND match_status = :status',
                ['share_class_id' => $shareClassId, 'status' => 'current']
            ));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM company_incorporation_share_payment_matches WHERE share_class_id = :share_class_id AND match_status = :status',
                ['share_class_id' => $shareClassId, 'status' => 'cleared']
            ));
            $summary = $service->fetchSummary((int)$fixture['company_id']);
            $shareClass = (array)(($summary['share_classes'] ?? [])[0] ?? []);
            $harness->assertSame('shares_not_paid_up', (string)($summary['status'] ?? ''));
            $harness->assertSame(0.00, (float)(($summary['totals'] ?? [])['matched_total'] ?? 1));
            $harness->assertSame(100.00, (float)(($summary['totals'] ?? [])['paid_up_unpaid_total'] ?? 0));
            $harness->assertSame('not_paid_up', (string)($shareClass['payment_status'] ?? ''));
        });
    });
});

function incorporation_share_service_with_fixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    $requiredTables = [
        'companies',
        'accounting_periods',
        'company_accounts',
        'company_settings',
        'company_incorporation_share_classes',
        'company_incorporation_share_payment_matches',
        'statement_uploads',
        'transactions',
        'transaction_category_audit',
        'nominal_accounts',
        'journals',
        'journal_lines',
    ];
    foreach ($requiredTables as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            $harness->skip('Required table is not available on the default InterfaceDB connection: ' . $table);
        }
    }

    \InterfaceDB::beginTransaction();
    try {
        $marker = 'INC' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
        $bankNominalId = incorporation_share_service_nominal('1' . substr($marker, -8), 'Incorporation Fixture Bank ' . $marker, 'asset');
        $shareCapitalNominalId = incorporation_share_service_nominal('3010', 'Ordinary Share Capital', 'equity');

        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number, incorporation_date, is_active)
             VALUES (:company_name, :company_number, :incorporation_date, 1)',
            [
                'company_name' => 'Incorporation Share Fixture Limited',
                'company_number' => $marker,
                'incorporation_date' => '2026-01-01',
            ]
        );
        $companyId = (int)\InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $marker]);
        \InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'FY ' . $marker,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );
        $accountingPeriodId = (int)\InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);
        \InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id)
             VALUES (:company_id, :account_name, :account_type, :nominal_account_id)',
            [
                'company_id' => $companyId,
                'account_name' => 'Fixture bank',
                'account_type' => 'bank',
                'nominal_account_id' => $bankNominalId,
            ]
        );
        $accountId = (int)\InterfaceDB::fetchColumn('SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name', ['company_id' => $companyId, 'account_name' => 'Fixture bank']);
        \InterfaceDB::prepareExecute(
            'INSERT INTO company_settings (company_id, setting, type, value)
             VALUES (:company_id, :setting, :type, :value)',
            [
                'company_id' => $companyId,
                'setting' => 'default_bank_nominal_id',
                'type' => 'int',
                'value' => (string)$bankNominalId,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (company_id, accounting_period_id, account_id, workflow_status, statement_month, original_filename, stored_filename, file_sha256)
             VALUES (:company_id, :accounting_period_id, :account_id, :workflow_status, :statement_month, :original_filename, :stored_filename, :file_sha256)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'account_id' => $accountId,
                'workflow_status' => 'committed',
                'statement_month' => '2026-01-01',
                'original_filename' => $marker . '.csv',
                'stored_filename' => $marker . '.csv',
                'file_sha256' => hash('sha256', 'upload:' . $marker),
            ]
        );
        $statementUploadId = (int)\InterfaceDB::fetchColumn('SELECT id FROM statement_uploads WHERE company_id = :company_id', ['company_id' => $companyId]);

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'statement_upload_id' => $statementUploadId,
            'bank_nominal_id' => $bankNominalId,
            'share_capital_nominal_id' => $shareCapitalNominalId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}

function incorporation_share_service_insert_transaction(array $fixture, float $amount, string $date, string $description): int
{
    $dedupeHash = hash('sha256', $fixture['marker'] . ':' . $amount . ':' . $date . ':' . $description . ':' . uniqid('', true));
    \InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            txn_type,
            description,
            reference,
            amount,
            currency,
            dedupe_hash
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :txn_type,
            :description,
            :reference,
            :amount,
            :currency,
            :dedupe_hash
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'statement_upload_id' => (int)$fixture['statement_upload_id'],
            'account_id' => (int)$fixture['account_id'],
            'txn_date' => $date,
            'txn_type' => 'credit',
            'description' => $description,
            'reference' => 'SHARES',
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'GBP',
            'dedupe_hash' => $dedupeHash,
        ]
    );

    return (int)\InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE company_id = :company_id AND dedupe_hash = :dedupe_hash', [
        'company_id' => (int)$fixture['company_id'],
        'dedupe_hash' => $dedupeHash,
    ]);
}

function incorporation_share_service_insert_other_company_transaction(array $fixture, float $amount, string $date, string $description): int
{
    $marker = $fixture['marker'] . 'B';
    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, incorporation_date, is_active)
         VALUES (:company_name, :company_number, :incorporation_date, 1)',
        [
            'company_name' => 'Other Incorporation Share Fixture Limited',
            'company_number' => $marker,
            'incorporation_date' => '2026-01-01',
        ]
    );
    $companyId = (int)\InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $marker]);
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    $accountingPeriodId = (int)\InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);
    \InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id)
         VALUES (:company_id, :account_name, :account_type, :nominal_account_id)',
        [
            'company_id' => $companyId,
            'account_name' => 'Other fixture bank',
            'account_type' => 'bank',
            'nominal_account_id' => (int)$fixture['bank_nominal_id'],
        ]
    );
    $accountId = (int)\InterfaceDB::fetchColumn('SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name', ['company_id' => $companyId, 'account_name' => 'Other fixture bank']);
    \InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (company_id, accounting_period_id, account_id, workflow_status, statement_month, original_filename, stored_filename, file_sha256)
         VALUES (:company_id, :accounting_period_id, :account_id, :workflow_status, :statement_month, :original_filename, :stored_filename, :file_sha256)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'workflow_status' => 'committed',
            'statement_month' => '2026-01-01',
            'original_filename' => $marker . '.csv',
            'stored_filename' => $marker . '.csv',
            'file_sha256' => hash('sha256', 'upload:' . $marker),
        ]
    );
    $statementUploadId = (int)\InterfaceDB::fetchColumn('SELECT id FROM statement_uploads WHERE company_id = :company_id', ['company_id' => $companyId]);

    return incorporation_share_service_insert_transaction([
        'marker' => $marker,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'account_id' => $accountId,
        'statement_upload_id' => $statementUploadId,
    ], $amount, $date, $description);
}

function incorporation_share_service_nominal(string $code, string $name, string $accountType): int
{
    $existing = (int)\InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($existing > 0) {
        return $existing;
    }

    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, is_active)
         VALUES (:code, :name, :account_type, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
        ]
    );

    return (int)\InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}
