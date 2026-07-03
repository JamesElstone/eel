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

$harness->run(TransactionAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof TransactionAction) {
        throw new RuntimeException('Unexpected TransactionAction instance.');
    }

    $harness->check('TransactionAction', 'implements the action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });

    $harness->check('TransactionAction', 'treats array id inputs as invalid integers', function () use ($harness): void {
        $method = new ReflectionMethod(TransactionAction::class, 'positiveInt');
        $method->setAccessible(true);

        $harness->assertSame(0, $method->invoke(null, ['57', '51']));
    });

    $harness->check('TransactionAction', 'select_transaction_month returns normalised card context', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'month_key' => '2026-03-01',
                'category_filter' => 'manual',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['page.context'], $result->changedFacts());
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame('2026-03-01', (string)($result->query()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->query()['category_filter'] ?? ''));
    });

    $harness->check('TransactionAction', 'defaults missing category filter to not posted', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'month_key' => '2026-03-01',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('not_posted', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame('not_posted', (string)($result->query()['category_filter'] ?? ''));
    });

    $harness->check('TransactionAction', 'preserves explicit all category filter', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('all', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame('all', (string)($result->query()['category_filter'] ?? ''));
    });

    $harness->check('TransactionAction', 'imported transaction filters only invalidate the imported card', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'select_transaction_month',
                'selection_source' => 'transactions_imported_filters',
                'month_key' => '2026-03-01',
                'category_filter' => 'manual',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['transactions.imported'], $result->changedFacts());
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('manual', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame('transactions_imported', (string)($result->query()['show_card'] ?? ''));
    });

    $harness->check('TransactionAction', 'auto approval sync does not invalidate cards', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'sync_auto_approval_state',
                'company_id' => '0',
                'accounting_period_id' => '0',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame([], $result->changedFacts());
    });

    $harness->check('TransactionAction', 'edit_categorisation_rule preserves selected rule id', function () use ($harness, $instance): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'edit_categorisation_rule',
                'rule_id' => '42',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(42, (int)($result->context()['editing_rule_id'] ?? 0));
    });

    $harness->check('TransactionAction', 'auto_create_transaction_rule opens an unsaved rule draft', function () use ($harness, $instance): void {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('91' . $marker);
        $accountingPeriodId = (int)('92' . $marker);
        $nominalAccountId = (int)('93' . $marker);
        $uploadId = (int)('94' . $marker);
        $transactionId = (int)('95' . $marker);

        if (!InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')) {
            InterfaceDB::prepareExecute('ALTER TABLE company_accounts ADD COLUMN internal_transfer_marker TEXT DEFAULT NULL');
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Draft Rule Test ' . $marker,
                'company_number' => 'DR' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $accountingPeriodId,
                'company_id' => $companyId,
                'label' => 'FY ' . $marker,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
             VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
            [
                'id' => $nominalAccountId,
                'code' => 'D' . substr($marker, 0, 4),
                'name' => 'Draft Rule Materials ' . $marker,
                'account_type' => 'expense',
                'tax_treatment' => 'allowable',
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                id,
                company_id,
                accounting_period_id,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256
             )',
            [
                'id' => $uploadId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'statement_month' => '2026-03-01',
                'original_filename' => 'draft-rule-' . $marker . '.csv',
                'stored_filename' => 'draft-rule-' . $marker . '.csv',
                'file_sha256' => hash('sha256', 'draft-rule-upload-' . $marker),
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO transactions (
                id,
                company_id,
                accounting_period_id,
                statement_upload_id,
                txn_date,
                description,
                reference,
                amount,
                source_account_label,
                source_category,
                dedupe_hash
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_upload_id,
                :txn_date,
                :description,
                :reference,
                :amount,
                :source_account_label,
                :source_category,
                :dedupe_hash
             )',
            [
                'id' => $transactionId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'statement_upload_id' => $uploadId,
                'txn_date' => '2026-03-15',
                'description' => 'CARD PAYMENT ACME ELECTRICAL 1234',
                'reference' => 'INV-ACME-' . $marker,
                'amount' => '-42.50',
                'source_account_label' => 'Main account',
                'source_category' => 'Materials',
                'dedupe_hash' => hash('sha256', 'draft-rule-transaction-' . $marker),
            ]
        );

        $beforeRules = InterfaceDB::countWhere('categorisation_rules', ['company_id' => $companyId]);
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'auto_create_transaction_rule',
                'company_id' => (string)$companyId,
                'accounting_period_id' => (string)$accountingPeriodId,
                'transaction_id' => (string)$transactionId,
                'nominal_account_id' => (string)$nominalAccountId,
                'month_key' => '2026-03-01',
                'category_filter' => 'uncategorised',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $instance->handle($request, createTestPageServiceFramework());
        $draft = (array)($result->context()['rule_form'] ?? []);

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame('transactions_rule_form', (string)($result->query()['show_card'] ?? ''));
        $harness->assertSame('2026-03-01', (string)($result->context()['month_key'] ?? ''));
        $harness->assertSame('uncategorised', (string)($result->context()['category_filter'] ?? ''));
        $harness->assertSame(0, (int)($result->context()['editing_rule_id'] ?? -1));
        $harness->assertSame($transactionId, (int)($draft['transaction_id'] ?? 0));
        $harness->assertSame($nominalAccountId, (int)($draft['nominal_account_id'] ?? 0));
        $harness->assertSame('contains', (string)($draft['desc_match_type'] ?? ''));
        $harness->assertSame('none', (string)($draft['ref_match_type'] ?? ''));
        $harness->assertSame('INV-ACME-' . $marker, (string)($draft['ref_match_value'] ?? ''));
        $harness->assertSame($beforeRules, InterfaceDB::countWhere('categorisation_rules', ['company_id' => $companyId]));

        $missingNominalRequest = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'auto_create_transaction_rule',
                'company_id' => (string)$companyId,
                'accounting_period_id' => (string)$accountingPeriodId,
                'transaction_id' => (string)$transactionId,
                'month_key' => '2026-03-01',
                'category_filter' => 'uncategorised',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $missingNominalResult = $instance->handle($missingNominalRequest, createTestPageServiceFramework());
        $harness->assertSame(false, $missingNominalResult->isSuccess());
        $harness->assertSame($beforeRules, InterfaceDB::countWhere('categorisation_rules', ['company_id' => $companyId]));
    });

    $createDirectorLoanFixture = static function (float $amount, array $settings = [], array $transactionOverrides = [], bool $locked = false): array {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('81' . $marker);
        $accountingPeriodId = (int)('82' . $marker);
        $bankNominalId = (int)('80' . $marker);
        $assetNominalId = (int)('83' . $marker);
        $liabilityNominalId = (int)('84' . $marker);
        $legacyNominalId = (int)('85' . $marker);
        $uploadId = (int)('86' . $marker);
        $transactionId = (int)('87' . $marker);
        $bankAccountId = (int)('89' . $marker);

        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Director Loan Shortcut ' . $marker,
                'company_number' => 'DL' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $accountingPeriodId,
                'company_id' => $companyId,
                'label' => 'FY ' . $marker,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );

        foreach ([
            [$bankNominalId, 'B' . $marker, 'Bank ' . $marker, 'asset'],
            [$assetNominalId, 'A' . $marker, 'Director Loan Asset ' . $marker, 'asset'],
            [$liabilityNominalId, 'L' . $marker, 'Director Loan Liability ' . $marker, 'liability'],
            [$legacyNominalId, 'G' . $marker, 'Legacy Director Loan ' . $marker, 'liability'],
        ] as $nominal) {
            InterfaceDB::prepareExecute(
                'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
                 VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
                [
                    'id' => $nominal[0],
                    'code' => $nominal[1],
                    'name' => $nominal[2],
                    'account_type' => $nominal[3],
                    'tax_treatment' => 'none',
                ]
            );
        }
        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
             VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
            [
                'id' => $bankAccountId,
                'company_id' => $companyId,
                'account_name' => 'Shortcut Bank ' . $marker,
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'nominal_account_id' => $bankNominalId,
            ]
        );

        $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
        foreach ($settings as $setting => $value) {
            $resolvedValue = match ($value) {
                'asset' => $assetNominalId,
                'liability' => $liabilityNominalId,
                'legacy' => $legacyNominalId,
                default => $value,
            };
            $settingsStore->set((string)$setting, $resolvedValue, 'int');
        }
        $settingsStore->flush();

        InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                id,
                company_id,
                accounting_period_id,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256
             )',
            [
                'id' => $uploadId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'statement_month' => '2026-03-01',
                'original_filename' => 'director-loan-' . $marker . '.csv',
                'stored_filename' => 'director-loan-' . $marker . '.csv',
                'file_sha256' => hash('sha256', 'director-loan-upload-' . $marker),
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
                description,
                reference,
                amount,
                source_account_label,
                source_category,
                dedupe_hash
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_upload_id,
                :account_id,
                :txn_date,
                :description,
                :reference,
                :amount,
                :source_account_label,
                :source_category,
                :dedupe_hash
             )',
            [
                'id' => $transactionId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'statement_upload_id' => $uploadId,
                'account_id' => (int)($transactionOverrides['account_id'] ?? $bankAccountId),
                'txn_date' => (string)($transactionOverrides['txn_date'] ?? '2026-03-15'),
                'description' => (string)($transactionOverrides['description'] ?? 'DIRECTOR LOAN TEST ' . $marker),
                'reference' => (string)($transactionOverrides['reference'] ?? 'DL-' . $marker),
                'amount' => number_format($amount, 2, '.', ''),
                'source_account_label' => (string)($transactionOverrides['source_account_label'] ?? 'Main account'),
                'source_category' => (string)($transactionOverrides['source_category'] ?? 'Uncategorised'),
                'dedupe_hash' => hash('sha256', 'director-loan-transaction-' . $marker),
            ]
        );

        if ($locked && InterfaceDB::tableExists('year_end_reviews')) {
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, status, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, :status, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'status' => 'locked',
                    'locked_by' => 'test',
                ]
            );
        }

        return [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'transaction_id' => $transactionId,
            'upload_id' => $uploadId,
            'bank_nominal_id' => $bankNominalId,
            'bank_account_id' => $bankAccountId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
            'legacy_nominal_id' => $legacyNominalId,
        ];
    };

    $markDirectorLoan = static function (array $fixture, array $extraPost = []) use ($instance): ActionResultFramework {
        $request = new RequestFramework(
            [],
            array_merge([
                'card_action' => 'Transaction',
                'global_action' => 'mark_director_loan',
                'company_id' => (string)$fixture['company_id'],
                'accounting_period_id' => (string)$fixture['accounting_period_id'],
                'transaction_id' => (string)$fixture['transaction_id'],
                'month_key' => '2026-03-01',
                'category_filter' => 'uncategorised',
            ], $extraPost),
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        return $instance->handle($request, createTestPageServiceFramework());
    };

    $transactionNominalId = static function (int $transactionId): int {
        return (int)InterfaceDB::fetchColumn(
            'SELECT nominal_account_id FROM transactions WHERE id = :id',
            ['id' => $transactionId]
        );
    };

    $transactionCategoryStatus = static function (int $transactionId): string {
        return (string)InterfaceDB::fetchColumn(
            'SELECT category_status FROM transactions WHERE id = :id',
            ['id' => $transactionId]
        );
    };

    $saveTransactionNote = static function (array $fixture, string $notes) use ($instance): ActionResultFramework {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'save_transaction_note',
                'company_id' => (string)$fixture['company_id'],
                'accounting_period_id' => (string)$fixture['accounting_period_id'],
                'transaction_id' => (string)$fixture['transaction_id'],
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
                'notes' => $notes,
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        return $instance->handle($request, createTestPageServiceFramework());
    };

    $transactionNotes = static function (int $transactionId): string {
        return (string)InterfaceDB::fetchColumn(
            'SELECT COALESCE(notes, \'\') FROM transactions WHERE id = :id',
            ['id' => $transactionId]
        );
    };

    $insertDerivedJournal = static function (array $fixture): int {
        InterfaceDB::prepareExecute(
            'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
             VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
            [
                'company_id' => $fixture['company_id'],
                'accounting_period_id' => $fixture['accounting_period_id'],
                'source_type' => 'bank_csv',
                'source_ref' => 'transaction:' . $fixture['transaction_id'],
                'journal_date' => '2026-03-15',
                'description' => 'Existing derived journal',
            ]
        );

        return (int)InterfaceDB::fetchColumn(
            'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref',
            [
                'company_id' => $fixture['company_id'],
                'source_type' => 'bank_csv',
                'source_ref' => 'transaction:' . $fixture['transaction_id'],
            ]
        );
    };

    $harness->check('TransactionAction', 'save_transaction_note updates only the transaction note', function () use ($harness, $createDirectorLoanFixture, $saveTransactionNote, $transactionNotes, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(-25.00);

        $result = $saveTransactionNote($fixture, 'Void reason: recategorised to director loan.');

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['transactions.imported'], $result->changedFacts());
        $harness->assertSame('Void reason: recategorised to director loan.', $transactionNotes((int)$fixture['transaction_id']));
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'save_transaction_note is blocked for locked accounting periods', function () use ($harness, $createDirectorLoanFixture, $saveTransactionNote, $transactionNotes): void {
        if (!InterfaceDB::tableExists('year_end_reviews')) {
            $harness->skip('Year end review table is not available on the default InterfaceDB connection.');
        }

        $fixture = $createDirectorLoanFixture(-25.00, [], [], true);

        $result = $saveTransactionNote($fixture, 'Should not save.');

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame('', $transactionNotes((int)$fixture['transaction_id']));
        $harness->assertSame(true, str_contains(transactionActionFlashText($result), 'locked'));
    });

    $harness->check('TransactionAction', 'mark_director_loan uses the asset nominal for money leaving the bank', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId, $transactionCategoryStatus): void {
        $fixture = $createDirectorLoanFixture(-250.00, [
            'director_loan_asset_nominal_id' => 'asset',
            'director_loan_liability_nominal_id' => 'liability',
        ]);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame($fixture['asset_nominal_id'], $transactionNominalId((int)$fixture['transaction_id']));
        $harness->assertSame('manual', $transactionCategoryStatus((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan uses the liability nominal for money entering the bank', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(250.00, [
            'director_loan_asset_nominal_id' => 'asset',
            'director_loan_liability_nominal_id' => 'liability',
        ]);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame($fixture['liability_nominal_id'], $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan falls back to the legacy liability setting for money entering the bank', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(125.00, [
            'director_loan_nominal_id' => 'legacy',
        ]);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame($fixture['legacy_nominal_id'], $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan rejects missing configured nominals without changing the transaction', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(-75.00, []);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan rejects transfer rows', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(100.00, [
            'director_loan_liability_nominal_id' => 'liability',
        ], [
            'source_category' => 'Internal transfer',
        ]);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan rejects zero amount transactions', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(0.00, [
            'director_loan_asset_nominal_id' => 'asset',
            'director_loan_liability_nominal_id' => 'liability',
        ]);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan is blocked for locked accounting periods', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId): void {
        $fixture = $createDirectorLoanFixture(-60.00, [
            'director_loan_asset_nominal_id' => 'asset',
        ], [], true);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan requires confirmation before changing a derived journal transaction', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId, $insertDerivedJournal): void {
        $fixture = $createDirectorLoanFixture(-90.00, [
            'director_loan_asset_nominal_id' => 'asset',
        ]);
        $insertDerivedJournal($fixture);

        $result = $markDirectorLoan($fixture);

        $harness->assertSame(false, $result->isSuccess());
        $harness->assertSame(0, $transactionNominalId((int)$fixture['transaction_id']));
    });

    $harness->check('TransactionAction', 'mark_director_loan rebuilds a derived journal after confirmation', function () use ($harness, $createDirectorLoanFixture, $markDirectorLoan, $transactionNominalId, $insertDerivedJournal): void {
        $fixture = $createDirectorLoanFixture(-95.00, [
            'director_loan_asset_nominal_id' => 'asset',
        ]);
        $oldJournalId = $insertDerivedJournal($fixture);

        $result = $markDirectorLoan($fixture, ['confirm_rebuild_journal' => '1']);
        $newJournalId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref',
            [
                'company_id' => $fixture['company_id'],
                'source_type' => 'bank_csv',
                'source_ref' => 'transaction:' . $fixture['transaction_id'],
            ]
        );
        $lineCount = InterfaceDB::countWhere('journal_lines', ['journal_id' => $newJournalId]);

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame($fixture['asset_nominal_id'], $transactionNominalId((int)$fixture['transaction_id']));
        $harness->assertSame(true, $newJournalId > 0);
        $harness->assertSame(false, $newJournalId === $oldJournalId);
        $harness->assertSame(2, $lineCount);
    });

    $harness->check('TransactionAction', 'post categorised transactions confirms only checked auto decisions', function () use ($harness, $instance, $createDirectorLoanFixture): void {
        foreach (['categorisation_rules', 'transaction_auto_approvals', 'journals', 'journal_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        $fixture = $createDirectorLoanFixture(-42.50);
        $checkedTransactionId = (int)$fixture['transaction_id'];
        $uncheckedTransactionId = $checkedTransactionId + 1;
        $ruleId = $checkedTransactionId + 100;

        InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (
                id,
                company_id,
                priority,
                match_field,
                desc_match_type,
                desc_match_value,
                nominal_account_id,
                is_active
             ) VALUES (
                :id,
                :company_id,
                100,
                :match_field,
                :desc_match_type,
                :desc_match_value,
                :nominal_account_id,
                1
             )',
            [
                'id' => $ruleId,
                'company_id' => (int)$fixture['company_id'],
                'match_field' => 'description',
                'desc_match_type' => 'contains',
                'desc_match_value' => 'AUTO POST',
                'nominal_account_id' => (int)$fixture['asset_nominal_id'],
            ]
        );
        InterfaceDB::prepareExecute(
            'UPDATE transactions
             SET nominal_account_id = :nominal_account_id,
                 category_status = :category_status,
                 auto_rule_id = :auto_rule_id
             WHERE id = :id',
            [
                'id' => $checkedTransactionId,
                'nominal_account_id' => (int)$fixture['asset_nominal_id'],
                'category_status' => 'auto',
                'auto_rule_id' => $ruleId,
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
                description,
                reference,
                amount,
                source_account_label,
                source_category,
                dedupe_hash,
                nominal_account_id,
                category_status,
                auto_rule_id
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_upload_id,
                :account_id,
                :txn_date,
                :description,
                :reference,
                :amount,
                :source_account_label,
                :source_category,
                :dedupe_hash,
                :nominal_account_id,
                :category_status,
                :auto_rule_id
             )',
            [
                'id' => $uncheckedTransactionId,
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'statement_upload_id' => (int)$fixture['upload_id'],
                'account_id' => (int)$fixture['bank_account_id'],
                'txn_date' => '2026-03-16',
                'description' => 'AUTO POST UNCHECKED',
                'reference' => 'APU-' . $uncheckedTransactionId,
                'amount' => '-36.00',
                'source_account_label' => 'Main account',
                'source_category' => 'Auto',
                'dedupe_hash' => hash('sha256', 'auto-post-unchecked-' . $uncheckedTransactionId),
                'nominal_account_id' => (int)$fixture['asset_nominal_id'],
                'category_status' => 'auto',
                'auto_rule_id' => $ruleId,
            ]
        );

        $approvalService = new \eel_accounts\Service\TransactionAutoApprovalService();
        $checked = $approvalService->setTransactionApprovalState(
            (int)$fixture['company_id'],
            (int)$fixture['accounting_period_id'],
            $checkedTransactionId,
            true,
            null
        );
        $harness->assertSame(true, (bool)($checked['success'] ?? false));

        $requestWithoutConfirmation = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'post_categorised_transactions',
                'company_id' => (string)$fixture['company_id'],
                'accounting_period_id' => (string)$fixture['accounting_period_id'],
                'month_key' => '2026-03-01',
                'category_filter' => 'auto',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );
        $blocked = $instance->handle($requestWithoutConfirmation, createTestPageServiceFramework());
        $harness->assertSame(false, $blocked->isSuccess());
        $harness->assertSame(true, str_contains(transactionActionFlashText($blocked), 'Confirm 1 checked auto decision(s)'));
        $harness->assertSame(0, InterfaceDB::countWhere('journals', [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'bank_csv',
        ]));

        $requestWithConfirmation = new RequestFramework(
            [],
            [
                'card_action' => 'Transaction',
                'global_action' => 'post_categorised_transactions',
                'company_id' => (string)$fixture['company_id'],
                'accounting_period_id' => (string)$fixture['accounting_period_id'],
                'month_key' => '2026-03-01',
                'category_filter' => 'auto',
                'confirm_auto_categorisations' => '1',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );
        $posted = $instance->handle($requestWithConfirmation, createTestPageServiceFramework());

        $harness->assertSame(true, $posted->isSuccess());
        $harness->assertSame(true, str_contains(transactionActionFlashText($posted), '1 checked auto decision(s) confirmed.'));
        $harness->assertSame(1, InterfaceDB::countWhere('journals', [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $checkedTransactionId,
        ]));
        $harness->assertSame(1, InterfaceDB::countWhere('journals', [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $uncheckedTransactionId,
        ]));
        $harness->assertSame(1, InterfaceDB::countWhere('transaction_auto_approvals', [
            'transaction_id' => $checkedTransactionId,
            'state' => \eel_accounts\Service\TransactionAutoApprovalService::STATE_CONFIRMED,
        ]));
        $harness->assertSame(0, InterfaceDB::countWhere('transaction_auto_approvals', [
            'transaction_id' => $uncheckedTransactionId,
            'state' => \eel_accounts\Service\TransactionAutoApprovalService::STATE_CONFIRMED,
        ]));
    });

    $harness->check('TransactionAction cards', 'transaction cards render Transaction card action forms', function () use ($harness): void {
        $context = [
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
                'selected_transaction_filter' => 'all',
                'editing_rule_id' => 0,
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'month' => 'Mar',
                    'year' => '2026',
                    'status' => 'good',
                    'transactions' => 1,
                    'uncategorised' => 0,
                    'deferred' => 0,
                    'ready_to_post' => 1,
                ]],
                'transactions_by_month' => [],
                'nominal_accounts' => [],
                'company_accounts' => [],
                'categorisation_rules' => [[
                    'id' => 3,
                    'priority' => 100,
                    'desc_match_type' => 'contains',
                    'desc_match_value' => 'Test',
                    'nominal_name' => 'Sales',
                    'is_active' => 1,
                ]],
                'blank_rule_form' => [
                    'priority' => 100,
                    'desc_match_type' => 'contains',
                    'desc_match_value' => '',
                    'ref_match_type' => 'none',
                    'ref_match_value' => '',
                    'nominal_account_id' => '',
                    'is_active' => true,
                ],
                'editing_rule' => null,
                'transaction_audit_rows' => [],
            ],
        ];

        $html = (new _transactions_monthly_statusCard())->render($context)
            . (new _transactions_importedCard())->render($context)
            . (new _transactions_rulesCard())->render($context)
            . (new _transactions_rule_formCard())->render($context)
            . (new _transaction_category_audit_logCard())->render($context);

        $harness->assertSame(true, str_contains($html, 'name="card_action" value="Transaction"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="select_transaction_month"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="run_auto_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="save_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'No transaction categorisation audit events'));
    });

    $harness->check('_transactions_rule_formCard', 'renders source filters as service-backed dropdowns', function () use ($harness): void {
        $html = (new _transactions_rule_formCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
                'settings' => [
                    'director_loan_asset_nominal_id' => 1200,
                    'director_loan_liability_nominal_id' => 2100,
                ],
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
                'rule_form' => [
                    'priority' => 100,
                    'desc_match_type' => 'contains',
                    'desc_match_value' => 'Test',
                    'ref_match_type' => 'starts_with',
                    'ref_match_value' => 'INV-',
                    'source_category_value' => 'Legacy Category',
                    'source_account_value' => 'Current account',
                    'nominal_account_id' => 7,
                    'is_active' => true,
                ],
            ],
            'services' => [
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'source_category_options' => [
                    'Materials',
                    'Travel',
                ],
                'source_account_options' => [
                    'Current account',
                    'Savings account',
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<fieldset class="form-row full settings-fieldset">'));
        $harness->assertSame(true, str_contains($html, '<legend>Description Matching</legend>'));
        $harness->assertSame(true, str_contains($html, '<legend>Reference Matching</legend>'));
        $harness->assertSame(true, str_contains($html, '<legend>Optional</legend>'));
        $harness->assertSame(true, str_contains($html, 'id="rule_priority" name="rule_priority"'));
        $harness->assertSame(true, str_contains($html, 'id="rule_desc_type" name="rule_desc_type"'));
        $harness->assertSame(true, str_contains($html, 'id="rule_desc_value" name="rule_desc_value"'));
        $harness->assertSame(true, str_contains($html, 'id="rule_ref_type" name="rule_ref_type"'));
        $harness->assertSame(true, str_contains($html, 'id="rule_ref_value" name="rule_ref_value"'));
        $harness->assertSame(true, str_contains($html, '<option value="none">None</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="starts_with" selected>Starts with</option>'));
        $harness->assertSame(true, str_contains($html, '<select class="select" id="rule_source_category_value" name="source_category_value" data-no-submit-on-change="true">'));
        $harness->assertSame(true, str_contains($html, '<select class="select" id="rule_source_account_value" name="source_account_value" data-no-submit-on-change="true">'));
        $harness->assertSame(false, str_contains($html, '<input class="input" id="rule_source_category_value" name="source_category_value"'));
        $harness->assertSame(false, str_contains($html, '<input class="input" id="rule_source_account_value" name="source_account_value"'));
        $harness->assertSame(true, str_contains($html, '<option value="">Any Category</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="">Any Account</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="Materials">Materials</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="Savings account">Savings account</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="Legacy Category" selected>Legacy Category</option>'));
        $harness->assertSame(true, str_contains($html, '<option value="Current account" selected>Current account</option>'));
    });

    $harness->check('_transactions_importedCard', 'renders imported transactions with table builder columns', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
                'settings' => [
                    'director_loan_asset_nominal_id' => 1200,
                    'director_loan_liability_nominal_id' => 2100,
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'page' => [
                'month_key' => '2026-03-01',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-02-01',
                    'label' => 'Feb 2026',
                ], [
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ], [
                    'month_key' => '2026-04-01',
                    'label' => 'Apr 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'txn_date' => '2026-03-15',
                    'description' => 'Test transaction',
                    'reference' => 'INV-42',
                    'source_account' => 'Current account',
                    'source_category' => 'Materials',
                    'amount' => -12.34,
                    'document_download_status' => 'downloaded',
                    'local_document_path' => 'uploads/company/1/receipt.pdf',
                    'nominal_account_id' => 7,
                    'category_status' => 'manual',
                    'has_derived_journal' => 0,
                    'auto_rule_id' => 3,
                    'auto_rule_match_value' => 'Test',
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertSame(true, str_contains($html, 'card-toolbar transactions-imported-controls'));
        $harness->assertSame(true, str_contains($html, 'id="transaction_month_key" name="month_key"'));
        $previousMonthButtonPosition = strpos($html, 'data-month-navigation="previous"');
        $nextMonthButtonPosition = strpos($html, 'data-month-navigation="next"');
        $harness->assertSame(true, $previousMonthButtonPosition !== false);
        $harness->assertSame(true, $nextMonthButtonPosition !== false);
        $harness->assertSame(true, strpos($html, 'name="month_key" value="2026-02-01"', (int)$previousMonthButtonPosition) !== false);
        $harness->assertSame(true, strpos($html, '<button class="button" type="submit">&lt;</button>', (int)$previousMonthButtonPosition) !== false);
        $harness->assertSame(true, strpos($html, 'name="month_key" value="2026-04-01"', (int)$nextMonthButtonPosition) !== false);
        $harness->assertSame(true, strpos($html, '<button class="button" type="submit">&gt;</button>', (int)$nextMonthButtonPosition) !== false);
        $harness->assertSame(true, str_contains($html, 'id="table-filter-transactions_imported-category_filter" name="category_filter"'));
        $harness->assertSame(true, str_contains($html, '<option value="not_posted" selected>Not yet Posted</option>'));
        $harness->assertSame(false, str_contains($html, 'id="transaction_category_filter"'));
        $harness->assertSame(true, str_contains($html, 'Condensed View'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $autoApplyPosition = strpos($html, 'Run Auto Rules');
        $postCategorisedPosition = strpos($html, 'Post Categorised Transactions');
        $categoryFilterPosition = strpos($html, 'Category filter');
        $condensedViewPosition = strpos($html, 'Condensed View');
        $harness->assertSame(true, $autoApplyPosition !== false);
        $harness->assertSame(true, $postCategorisedPosition !== false);
        $harness->assertSame(true, $categoryFilterPosition !== false);
        $harness->assertSame(true, $condensedViewPosition !== false);
        $harness->assertSame(true, $autoApplyPosition < $categoryFilterPosition);
        $harness->assertSame(true, $postCategorisedPosition < $categoryFilterPosition);
        $harness->assertSame(true, $categoryFilterPosition < $condensedViewPosition);
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="run_auto_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="post_categorised_transactions"'));
        $harness->assertSame(true, str_contains($html, 'name="month_key" value="2026-03-01"'));
        $harness->assertSame(true, str_contains($html, '<th>Date</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Auto Decision</th>'));
        $harness->assertSame(true, str_contains($html, 'Test transaction'));
        $harness->assertSame(true, str_contains($html, '<span class="amount-negative">$-12.34</span>'));
        $harness->assertSame(true, str_contains($html, 'Ref: INV-42<br>Matched by rule #3 (Test)'));
        $harness->assertSame(true, str_contains($html, 'Matched by rule #3 (Test)'));
        $harness->assertSame(true, str_contains($html, 'View Receipt'));
        $harness->assertSame(true, str_contains($html, 'data-autosave-submit-target=".js-transaction-autosave-submit"'));
        $harness->assertSame(true, str_contains($html, '<button class="js-transaction-autosave-submit" type="submit" name="global_action" value="save_transaction_category" hidden>Autosave</button>'));
        $harness->assertSame(false, str_contains($html, 'Save Row'));
        $harness->assertSame(false, str_contains($html, '<input type="hidden" name="nominal_account_id"'));
        $harness->assertSame(true, str_contains($html, 'action="?page=assets&amp;show_card=asset_create"'));
        $harness->assertSame(true, str_contains($html, 'name="transaction_reference" value="INV-42"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="auto_create_transaction_rule" data-show-card="transactions_rule_form"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="mark_director_loan"'));
        $harness->assertSame(true, str_contains($html, '<span class="badge success">Manual</span>'));
    });

    $harness->check('_transactions_importedCard', 'does not require chicken for unticked auto decisions', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'auto',
            ],
            'services' => [
                'pending_auto_approval_count' => 0,
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'txn_date' => '2026-03-15',
                    'description' => 'Auto rule transaction',
                    'source_account' => 'Current account',
                    'amount' => -12.34,
                    'document_download_status' => 'skipped',
                    'nominal_account_id' => 7,
                    'category_status' => 'auto',
                    'has_derived_journal' => 0,
                    'auto_rule_id' => 3,
                    'auto_rule_match_value' => 'Auto',
                    'auto_approval_checked_current' => 0,
                    'auto_approval_confirmed_current' => 0,
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Post Categorised Transactions'));
        $harness->assertSame(false, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(false, str_contains($html, 'Confirm checked auto decisions'));
        $harness->assertSame(true, str_contains($html, 'Unconfirmed'));
    });

    $harness->check('_transactions_importedCard', 'requires chicken for checked auto decisions', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'auto',
            ],
            'services' => [
                'pending_auto_approval_count' => 1,
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 43,
                    'txn_date' => '2026-03-16',
                    'description' => 'Checked auto rule transaction',
                    'source_account' => 'Current account',
                    'amount' => -56.78,
                    'document_download_status' => 'skipped',
                    'nominal_account_id' => 7,
                    'category_status' => 'auto',
                    'has_derived_journal' => 0,
                    'auto_rule_id' => 3,
                    'auto_rule_match_value' => 'Auto',
                    'auto_approval_checked_current' => 1,
                    'auto_approval_confirmed_current' => 0,
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'data-chicken-check="true"'));
        $harness->assertSame(true, str_contains($html, 'Confirm checked auto decisions'));
        $harness->assertSame(true, str_contains($html, 'confirm 1 checked auto decision(s)'));
        $harness->assertSame(true, str_contains($html, 'Unticked auto decisions will post but remain unconfirmed.'));
        $harness->assertSame(true, str_contains($html, 'Correct'));
    });

    $harness->check('_transactions_importedCard', 'renders dividend declaration shortcut for payable transactions', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2022-11-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2022-11-01',
                    'label' => 'Nov 2022',
                ]],
                'transactions_by_month' => [[
                    'id' => 6171,
                    'txn_date' => '2022-11-02',
                    'description' => 'ALEX EXAMPLE',
                    'source_account' => 'Example Bank - Current Account',
                    'source_category' => 'DIVIDEND',
                    'amount' => -129.00,
                    'document_download_status' => 'skipped',
                    'nominal_account_id' => 54,
                    'nominal_code' => '2150',
                    'category_status' => 'manual',
                    'has_derived_journal' => 1,
                    'has_dividend_declaration' => 0,
                ], [
                    'id' => 6172,
                    'txn_date' => '2022-11-03',
                    'description' => 'Existing dividend',
                    'source_account' => 'Example Bank - Current Account',
                    'source_category' => 'DIVIDEND',
                    'amount' => -50.00,
                    'document_download_status' => 'skipped',
                    'nominal_account_id' => 54,
                    'nominal_code' => '2150',
                    'category_status' => 'manual',
                    'has_derived_journal' => 1,
                    'has_dividend_declaration' => 1,
                ]],
                'nominal_accounts' => [[
                    'id' => 54,
                    'code' => '2150',
                    'name' => 'Dividends Payable',
                    'account_type' => 'liability',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'id="transaction-dividend-form-6171" data-ajax="true"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="card_action" value="Dividend">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="intent" value="declare_dividend_from_transaction">'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="transaction_id" value="6171">'));
        $harness->assertSame(true, str_contains($html, 'form="transaction-dividend-form-6171" formnovalidate'));
        $harness->assertSame(true, str_contains($html, 'data-chicken-title="Create dividend declaration"'));
        $harness->assertSame(true, str_contains($html, 'The transaction will remain categorised to Dividends Payable.'));
        $harness->assertSame(true, str_contains($html, '<span class="badge success">Dividend created</span>'));
    });

    $harness->check('_transactions_importedCard', 'preserves selected filters in imported transactions pagination', function () use ($harness): void {
        $transactions = [];
        for ($i = 1; $i <= 21; $i++) {
            $transactions[] = [
                'id' => $i,
                'txn_date' => '2026-03-15',
                'description' => 'Test transaction ' . $i,
                'reference' => 'INV-' . $i,
                'source_account' => 'Current account',
                'source_category' => 'Materials',
                'amount' => -12.34,
                'document_download_status' => 'downloaded',
                'local_document_path' => 'uploads/company/1/receipt.pdf',
                'nominal_account_id' => 7,
                'category_status' => 'manual',
                'has_derived_journal' => 0,
            ];
        }

        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
                'settings' => [
                    'director_loan_asset_nominal_id' => 1200,
                    'director_loan_liability_nominal_id' => 2100,
                ],
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => $transactions,
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [],
            ],
        ]);

        $nextPagePosition = strpos($html, 'name="transactions_imported_page" value="2"');
        $harness->assertSame(true, $nextPagePosition !== false);

        $beforeNextPage = substr($html, 0, (int)$nextPagePosition);
        $nextFormStart = strrpos($beforeNextPage, '<form');
        $nextFormEnd = strpos($html, '</form>', (int)$nextPagePosition);
        $harness->assertSame(true, $nextFormStart !== false);
        $harness->assertSame(true, $nextFormEnd !== false);

        $nextFormHtml = substr($html, (int)$nextFormStart, (int)$nextFormEnd - (int)$nextFormStart);
        $harness->assertSame(true, str_contains($nextFormHtml, 'name="month_key" value="2026-03-01"'));
        $harness->assertSame(true, str_contains($nextFormHtml, 'name="category_filter" value="all"'));
        $harness->assertSame(true, str_contains($nextFormHtml, 'name="company_id" value="1"'));
        $harness->assertSame(true, str_contains($nextFormHtml, 'name="accounting_period_id" value="2"'));
    });

    $harness->check('_transactions_importedCard', 'disables month navigation buttons at first and last entries', function () use ($harness): void {
        $renderForMonth = static function (string $monthKey): string {
            return (new _transactions_importedCard())->render([
                'company' => [
                    'id' => 1,
                    'accounting_period_id' => 2,
                ],
                'page' => [
                    'month_key' => $monthKey,
                ],
                'services' => [
                    'month_status' => [[
                        'month_key' => '2026-02-01',
                        'label' => 'Feb 2026',
                    ], [
                        'month_key' => '2026-03-01',
                        'label' => 'Mar 2026',
                    ], [
                        'month_key' => '2026-04-01',
                        'label' => 'Apr 2026',
                    ]],
                    'transactions_by_month' => [],
                    'nominal_accounts' => [],
                    'company_accounts' => [],
                ],
            ]);
        };

        $firstMonthHtml = $renderForMonth('2026-02-01');
        $firstPreviousPosition = strpos($firstMonthHtml, 'data-month-navigation="previous"');
        $firstNextPosition = strpos($firstMonthHtml, 'data-month-navigation="next"');
        $harness->assertSame(true, $firstPreviousPosition !== false);
        $harness->assertSame(true, $firstNextPosition !== false);
        $harness->assertSame(true, strpos($firstMonthHtml, '<button class="button" type="button" disabled>&lt;</button>', (int)$firstPreviousPosition) !== false);
        $harness->assertSame(true, strpos($firstMonthHtml, '<button class="button" type="submit">&gt;</button>', (int)$firstNextPosition) !== false);

        $lastMonthHtml = $renderForMonth('2026-04-01');
        $lastPreviousPosition = strpos($lastMonthHtml, 'data-month-navigation="previous"');
        $lastNextPosition = strpos($lastMonthHtml, 'data-month-navigation="next"');
        $harness->assertSame(true, $lastPreviousPosition !== false);
        $harness->assertSame(true, $lastNextPosition !== false);
        $harness->assertSame(true, strpos($lastMonthHtml, '<button class="button" type="submit">&lt;</button>', (int)$lastPreviousPosition) !== false);
        $harness->assertSame(true, strpos($lastMonthHtml, '<button class="button" type="button" disabled>&gt;</button>', (int)$lastNextPosition) !== false);
    });

    $harness->check('_transactions_importedCard', 'treats internal transfer flagged transactions as transfer rows', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'account_id' => 10,
                    'txn_date' => '2026-03-15',
                    'description' => 'Transfer from pot',
                    'source_account' => 'Current account',
                    'source_category' => 'P2P',
                    'amount' => 10.00,
                    'document_download_status' => 'skipped',
                    'nominal_account_id' => 7,
                    'is_internal_transfer' => 1,
                    'category_status' => 'uncategorised',
                    'has_derived_journal' => 0,
                    'auto_rule_id' => 3,
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [[
                    'id' => 10,
                    'account_name' => 'Current account',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'is_active' => 1,
                ], [
                    'id' => 11,
                    'account_name' => 'Savings pot',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'is_active' => 1,
                ]],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Transfer from:'));
        $harness->assertSame(true, str_contains($html, '<option value="">Select owned account</option>'));
        $harness->assertSame(true, str_contains($html, 'data-autosave-submit-target=".js-transaction-autosave-submit"'));
        $harness->assertSame(true, str_contains($html, 'Savings pot [Bank]'));
        $harness->assertSame(true, str_contains($html, '<span class="badge warning">Transfer pending</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="badge info">Rule #3</span>'));
        $harness->assertSame(false, str_contains($html, 'name="nominal_account_id" form="transaction-form-42"'));
        $harness->assertSame(false, str_contains($html, 'value="mark_director_loan"'));
    });

    $harness->check('_transactions_importedCard', 'renders transactions read only when accounting period is locked', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
                'settings' => [
                    'director_loan_asset_nominal_id' => 1200,
                    'director_loan_liability_nominal_id' => 2100,
                ],
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'account_id' => 10,
                    'txn_date' => '2026-03-15',
                    'description' => 'Materials purchase',
                    'source_account' => 'Current account',
                    'source_category' => 'Materials',
                    'amount' => -12.34,
                    'document_download_status' => 'downloaded',
                    'local_document_path' => 'uploads/company/1/receipt.pdf',
                    'nominal_account_id' => 7,
                    'category_status' => 'manual',
                    'has_derived_journal' => 1,
                    'auto_rule_id' => 3,
                    'auto_rule_match_value' => 'Materials',
                ], [
                    'id' => 43,
                    'account_id' => 10,
                    'txn_date' => '2026-03-16',
                    'description' => 'Transfer from pot',
                    'source_account' => 'Current account',
                    'source_category' => 'P2P',
                    'amount' => 10.00,
                    'document_download_status' => 'skipped',
                    'is_internal_transfer' => 1,
                    'transfer_account_id' => 11,
                    'category_status' => 'manual',
                    'has_derived_journal' => 1,
                ]],
                'nominal_accounts' => [[
                    'id' => 7,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'expense',
                ]],
                'company_accounts' => [[
                    'id' => 10,
                    'account_name' => 'Current account',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'is_active' => 1,
                ], [
                    'id' => 11,
                    'account_name' => 'Savings pot',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'is_active' => 1,
                ]],
                'year_end_review' => [
                    'is_locked' => 1,
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<span class="badge warning">Period locked</span>'));
        $harness->assertSame(true, str_contains($html, 'Transactions can be reviewed but not changed.'));
        $harness->assertSame(true, str_contains($html, 'id="transaction_month_key" name="month_key"'));
        $harness->assertSame(true, str_contains($html, 'id="table-filter-transactions_imported-category_filter" name="category_filter"'));
        $harness->assertSame(true, str_contains($html, '5000 - Materials'));
        $harness->assertSame(true, str_contains($html, 'Savings pot [Bank]'));
        $harness->assertSame(false, str_contains($html, '<select class="select js-transaction-nominal" name="nominal_account_id"'));
        $harness->assertSame(false, str_contains($html, '<select class="select js-transaction-transfer" name="transfer_account_id"'));
        $harness->assertSame(false, str_contains($html, 'data-autosave-submit-target=".js-transaction-autosave-submit"'));
        $harness->assertSame(false, str_contains($html, 'class="js-transaction-autosave-submit"'));
        $harness->assertSame(true, str_contains($html, '<input type="hidden" name="nominal_account_id" value="7">'));
        $harness->assertSame(true, str_contains($html, '<button class="button" type="button" disabled title="Period locked">Run Auto Rules</button>'));
        $harness->assertSame(true, str_contains($html, '<button class="button primary" type="button" disabled title="Period locked">Post Categorised Transactions</button>'));
        $harness->assertSame(false, str_contains($html, 'name="global_action" value="save_transaction_category"'));
        $harness->assertSame(true, str_contains($html, '<button class="button primary" type="submit" name="global_action" value="auto_create_transaction_rule" data-show-card="transactions_rule_form">Rule</button>'));
        $harness->assertSame(true, str_contains($html, '<button class="button" type="button" disabled title="Period locked">Director Loan</button>'));
        $harness->assertSame(true, str_contains($html, 'type="button" disabled title="Period locked" name="global_action" value="defer_transaction"'));
        $harness->assertSame(true, str_contains($html, '<button class="button" type="button" disabled title="Period locked">Asset</button>'));
        $harness->assertSame(true, str_contains($html, 'View Receipt'));
    });

    $harness->check('_transactions_importedCard', 'disables Director Loan shortcut when the required nominal is missing', function () use ($harness): void {
        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
                'settings' => [
                    'director_loan_liability_nominal_id' => 2100,
                ],
            ],
            'page' => [
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => [[
                    'id' => 42,
                    'txn_date' => '2026-03-15',
                    'description' => 'Director loan payment',
                    'source_account' => 'Current account',
                    'source_category' => 'Manual',
                    'amount' => -10.00,
                    'document_download_status' => 'skipped',
                    'category_status' => 'uncategorised',
                    'has_derived_journal' => 0,
                ]],
                'nominal_accounts' => [],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<button class="button" type="button" disabled title="Set Director Loan Asset nominal in Company Nominals">Director Loan</button>'));
        $harness->assertSame(false, str_contains($html, 'value="mark_director_loan"'));
    });

    $harness->check('_transactions_rulesCard', 'renders categorisation rules with table builder exports', function () use ($harness): void {
        $html = (new _transactions_rulesCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'categorisation_rules' => [[
                    'id' => 3,
                    'priority' => 100,
                    'desc_match_type' => 'contains',
                    'desc_match_value' => 'Test',
                    'ref_match_type' => 'starts_with',
                    'ref_match_value' => 'INV-',
                    'nominal_code' => '4000',
                    'nominal_name' => 'Sales',
                    'is_active' => 1,
                ]],
            ],
        ]);

        $exportRulesPosition = strpos($html, 'Export Rules');
        $condensedViewPosition = strpos($html, 'Condensed View');

        $harness->assertSame(true, str_contains($html, '<div class="table-scroll"><table>'));
        $harness->assertSame(true, str_contains($html, '<th>Priority</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Match</th>'));
        $harness->assertSame(true, str_contains($html, '<th>Nominal</th>'));
        $harness->assertSame(true, str_contains($html, 'Description Contains &quot;Test&quot; | reference Starts with &quot;INV-&quot;'));
        $harness->assertSame(true, str_contains($html, '4000 - Sales'));
        $harness->assertSame(true, str_contains($html, '<span class="badge success">Active</span>'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="export_categorisation_rules"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertSame(true, str_contains($html, 'name="_table_export_prepare" value="xlsx"'));
        $harness->assertSame(true, $exportRulesPosition !== false);
        $harness->assertSame(true, $condensedViewPosition !== false);
        $harness->assertSame(true, $exportRulesPosition < $condensedViewPosition);
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="edit_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="toggle_categorisation_rule"'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="delete_categorisation_rule"'));
        $harness->assertSame(2, substr_count($html, '<section class="panel-soft">'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">Categorisation rules</h3>'));
        $harness->assertSame(true, str_contains($html, '<h3 class="card-title">Upload exported JSON rules</h3>'));
        $harness->assertSame(true, str_contains($html, 'name="global_action" value="import_categorisation_rules"'));
    });

    $harness->check('_transactions_rulesCard', 'paginates categorisation rules at fifteen rows', function () use ($harness): void {
        $rules = [];
        for ($i = 1; $i <= 16; $i++) {
            $rules[] = [
                'id' => $i,
                'priority' => $i,
                'desc_match_type' => 'contains',
                'desc_match_value' => 'Rule ' . $i,
                'nominal_code' => '4000',
                'nominal_name' => 'Sales',
                'is_active' => 1,
            ];
        }

        $html = (new _transactions_rulesCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'categorisation_rules' => $rules,
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Categorisation rules 1-15 of 16'));
        $harness->assertSame(true, str_contains($html, 'Rule 15'));
        $harness->assertSame(false, str_contains($html, 'Rule 16'));
        $harness->assertSame(true, str_contains($html, 'name="transactions_rules_page" value="2"'));
    });

    $harness->check('_transactions_importedCard', 'paginates imported transactions at twenty rows', function () use ($harness): void {
        $transactions = [];
        for ($i = 1; $i <= 21; $i++) {
            $transactions[] = [
                'id' => $i,
                'txn_date' => '2026-03-' . str_pad((string)min($i, 28), 2, '0', STR_PAD_LEFT),
                'description' => 'Imported transaction ' . $i,
                'source_account' => 'Current account',
                'source_category' => 'Materials',
                'amount' => -1 * $i,
                'document_download_status' => 'missing',
                'category_status' => 'uncategorised',
                'has_derived_journal' => 0,
            ];
        }

        $html = (new _transactions_importedCard())->render([
            'company' => [
                'id' => 1,
                'accounting_period_id' => 2,
            ],
            'page' => [
                'page_id' => 'transactions',
                'month_key' => '2026-03-01',
                'category_filter' => 'all',
            ],
            'services' => [
                'month_status' => [[
                    'month_key' => '2026-03-01',
                    'label' => 'Mar 2026',
                ]],
                'transactions_by_month' => $transactions,
                'nominal_accounts' => [],
                'company_accounts' => [],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Imported transactions 1-20 of 21'));
        $harness->assertSame(true, str_contains($html, 'Imported transaction 20'));
        $harness->assertSame(false, str_contains($html, 'Imported transaction 21'));
        $harness->assertSame(true, str_contains($html, 'name="transactions_imported_page" value="2"'));
    });
});

function transactionActionFlashText(ActionResultFramework $result): string
{
    $messages = [];
    foreach ($result->flashMessages() as $flashMessage) {
        if (is_array($flashMessage)) {
            $messages[] = (string)($flashMessage['message'] ?? '');
            continue;
        }

        $messages[] = (string)$flashMessage;
    }

    return implode("\n", $messages);
}
