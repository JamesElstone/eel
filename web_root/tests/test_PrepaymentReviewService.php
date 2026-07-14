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
    \eel_accounts\Service\PrepaymentReviewService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentReviewService $service): void {
        $harness->check(\eel_accounts\Service\PrepaymentReviewService::class, 'counts only persisted review decisions as reviewed', static function () use ($harness, $service): void {
            foreach (['companies', 'accounting_periods', 'statement_uploads', 'transactions', 'nominal_accounts', 'prepayment_reviews'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }
            if (!InterfaceDB::columnExists('nominal_accounts', 'prepayment_candidate')) {
                $harness->skip('Nominal prepayment candidate column is not available.');
            }

            InterfaceDB::beginTransaction();
            try {
                $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number, is_active)
                     VALUES (:company_name, :company_number, 1)',
                    ['company_name' => 'Prepayment Review Fixture Limited', 'company_number' => 'PR' . substr($marker, 0, 8)]
                );
                $companyId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM companies WHERE company_number = :company_number',
                    ['company_number' => 'PR' . substr($marker, 0, 8)]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'Prepayment ' . $marker,
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $accountingPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                    ['company_id' => $companyId, 'label' => 'Prepayment ' . $marker]
                );
                $nominalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_accounts WHERE account_type = :account_type AND is_active = 1 ORDER BY id ASC LIMIT 1',
                    ['account_type' => 'expense']
                );
                if ($nominalId <= 0) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, prepayment_candidate, is_active, sort_order)
                         VALUES (:code, :name, :account_type, :tax_treatment, 1, 1, 9000)',
                        [
                            'code' => 'PR' . substr($marker, 0, 6),
                            'name' => 'Prepayment Review Expense',
                            'account_type' => 'expense',
                            'tax_treatment' => 'allowable',
                        ]
                    );
                    $nominalId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM nominal_accounts WHERE code = :code',
                        ['code' => 'PR' . substr($marker, 0, 6)]
                    );
                }
                InterfaceDB::prepareExecute(
                    'UPDATE nominal_accounts SET prepayment_candidate = 1 WHERE id = :id',
                    ['id' => $nominalId]
                );

                InterfaceDB::prepareExecute(
                    'INSERT INTO statement_uploads (
                        company_id, accounting_period_id, statement_month, original_filename,
                        stored_filename, file_sha256, workflow_status
                     ) VALUES (
                        :company_id, :accounting_period_id, :statement_month, :original_filename,
                        :stored_filename, :file_sha256, :workflow_status
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'statement_month' => '2025-06-01',
                        'original_filename' => $marker . '.csv',
                        'stored_filename' => $marker . '.csv',
                        'file_sha256' => hash('sha256', $marker),
                        'workflow_status' => 'committed',
                    ]
                );
                $uploadId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM statement_uploads WHERE company_id = :company_id AND original_filename = :filename',
                    ['company_id' => $companyId, 'filename' => $marker . '.csv']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO transactions (
                        company_id, accounting_period_id, statement_upload_id, txn_date,
                        description, amount, dedupe_hash, nominal_account_id, category_status
                     ) VALUES (
                        :company_id, :accounting_period_id, :statement_upload_id, :txn_date,
                        :description, :amount, :dedupe_hash, :nominal_account_id, :category_status
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'statement_upload_id' => $uploadId,
                        'txn_date' => '2025-06-15',
                        'description' => 'Annual service candidate',
                        'amount' => -120.00,
                        'dedupe_hash' => hash('sha256', $marker . ':transaction'),
                        'nominal_account_id' => $nominalId,
                        'category_status' => 'manual',
                    ]
                );
                $transactionId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM transactions WHERE company_id = :company_id AND dedupe_hash = :dedupe_hash',
                    ['company_id' => $companyId, 'dedupe_hash' => hash('sha256', $marker . ':transaction')]
                );
                $bankNominalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_accounts WHERE account_type = :account_type AND is_active = 1 ORDER BY id ASC LIMIT 1',
                    ['account_type' => 'asset']
                );
                if ($bankNominalId <= 0) {
                    InterfaceDB::prepareExecute(
                        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
                         VALUES (:code, :name, :account_type, :tax_treatment, 1, 9001)',
                        [
                            'code' => 'PB' . substr($marker, 0, 6),
                            'name' => 'Prepayment Review Bank',
                            'account_type' => 'asset',
                            'tax_treatment' => 'other',
                        ]
                    );
                    $bankNominalId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM nominal_accounts WHERE code = :code',
                        ['code' => 'PB' . substr($marker, 0, 6)]
                    );
                }
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
                        'source_ref' => 'transaction:' . $transactionId,
                        'journal_date' => '2025-06-15',
                        'description' => 'Annual service purchase',
                    ]
                );
                $journalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
                    ['company_id' => $companyId, 'source_ref' => 'transaction:' . $transactionId]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, 120.00, 0.00, :description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $nominalId,
                        'description' => 'Annual service expense',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, 0.00, 120.00, :description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $bankNominalId,
                        'description' => 'Bank payment',
                    ]
                );

                $unreviewed = $service->fetchContext($companyId, $accountingPeriodId);
                $harness->assertSame(1, (int)($unreviewed['total_count'] ?? 0));
                $harness->assertSame(0, (int)($unreviewed['reviewed_count'] ?? -1));
                $harness->assertSame(1, (int)($unreviewed['pending_count'] ?? 0));
                $harness->assertSame('pending', (string)($unreviewed['items'][0]['review']['status'] ?? ''));
                $harness->assertSame(false, !empty($unreviewed['items'][0]['review']['persisted']));

                InterfaceDB::prepareExecute(
                    'INSERT INTO prepayment_reviews (
                        company_id, accounting_period_id, source_type, source_id, status,
                        reviewed_at, reviewed_by, created_at, updated_at
                     ) VALUES (
                        :company_id, :accounting_period_id, :source_type, :source_id, :status,
                        :reviewed_at, :reviewed_by, :created_at, :updated_at
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $accountingPeriodId,
                        'source_type' => 'transaction',
                        'source_id' => $transactionId,
                        'status' => 'not_prepaid',
                        'reviewed_at' => '2026-07-13 12:00:00',
                        'reviewed_by' => 'test',
                        'created_at' => '2026-07-13 12:00:00',
                        'updated_at' => '2026-07-13 12:00:00',
                    ]
                );

                $reviewed = $service->fetchContext($companyId, $accountingPeriodId);
                $harness->assertSame(1, (int)($reviewed['reviewed_count'] ?? 0));
                $harness->assertSame(0, (int)($reviewed['pending_count'] ?? -1));
                $harness->assertSame('not_prepaid', (string)($reviewed['items'][0]['review']['status'] ?? ''));
                $harness->assertSame(true, !empty($reviewed['items'][0]['review']['persisted']));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);
