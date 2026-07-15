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

if (
    InterfaceDB::tableExists('company_accounts')
    && !InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')
) {
    InterfaceDB::execute('ALTER TABLE company_accounts ADD COLUMN internal_transfer_marker TEXT NULL');
}

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\TransactionInterAccountMarkerService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TransactionInterAccountMarkerService $service): void {
    $harness->check(\eel_accounts\Service\TransactionInterAccountMarkerService::class, 'creates 5802 to 4516 marker and filters candidates', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker', 'transaction_category_audit', 'journals', 'journal_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionInterAccountMarkerCreateFixture();

            $candidates = $service->fetchCandidates((int)$fixture['source_transaction_id']);
            $candidateIds = array_map(static fn(array $row): int => (int)$row['id'], $candidates);

            $harness->assertTrue(in_array((int)$fixture['matched_transaction_id'], $candidateIds, true));
            $harness->assertFalse(in_array((int)$fixture['same_account_transaction_id'], $candidateIds, true));
            $harness->assertFalse(in_array((int)$fixture['wrong_amount_transaction_id'], $candidateIds, true));
            $harness->assertFalse(in_array((int)$fixture['too_late_transaction_id'], $candidateIds, true));
            $harness->assertSame('Example Trade Supplier', (string)($candidates[0]['account_name'] ?? ''));

            $result = $service->saveMarker((int)$fixture['source_transaction_id'], (int)$fixture['matched_transaction_id'], 'test');
            $harness->assertSame(true, (bool)($result['success'] ?? false));

            $marker = InterfaceDB::fetchOne(
                'SELECT transaction_id, matched_transaction_id
                 FROM transaction_inter_ac_marker
                 WHERE transaction_id = :transaction_id
                 LIMIT 1',
                ['transaction_id' => (int)$fixture['source_transaction_id']]
            );
            $harness->assertSame((int)$fixture['source_transaction_id'], (int)($marker['transaction_id'] ?? 0));
            $harness->assertSame((int)$fixture['matched_transaction_id'], (int)($marker['matched_transaction_id'] ?? 0));
            $harness->assertSame(false, $service->isMatchedNoPostTransaction((int)$fixture['source_transaction_id']));
            $harness->assertSame(true, $service->isMatchedNoPostTransaction((int)$fixture['matched_transaction_id']));

            $source = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['source_transaction_id']]);
            $matched = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['matched_transaction_id']]);

            $harness->assertSame(null, $source['nominal_account_id'] ?? null);
            $harness->assertSame((int)$fixture['trade_account_id'], (int)($source['transfer_account_id'] ?? 0));
            $harness->assertSame(1, (int)($source['is_internal_transfer'] ?? 0));
            $harness->assertSame('manual', (string)($source['category_status'] ?? ''));
            $harness->assertSame(null, $source['auto_rule_id'] ?? null);
            $harness->assertSame(0, (int)($source['is_auto_excluded'] ?? 1));

            $harness->assertSame(null, $matched['nominal_account_id'] ?? null);
            $harness->assertSame((int)$fixture['bank_account_id'], (int)($matched['transfer_account_id'] ?? 0));
            $harness->assertSame(1, (int)($matched['is_internal_transfer'] ?? 0));
            $harness->assertSame('manual', (string)($matched['category_status'] ?? ''));
            $harness->assertSame(null, $matched['auto_rule_id'] ?? null);
            $harness->assertSame(0, (int)($matched['is_auto_excluded'] ?? 1));

            $harness->assertSame(1, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['source_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['matched_transaction_id']]));

            $secondResult = $service->saveMarker((int)$fixture['same_account_transaction_id'], (int)$fixture['source_transaction_id'], 'test');
            $harness->assertSame(false, (bool)($secondResult['success'] ?? true));

            $clearResult = $service->clearMarkerForTransaction((int)$fixture['matched_transaction_id']);
            $harness->assertSame(true, (bool)($clearResult['removed'] ?? false));
            $harness->assertSame(false, $service->isMatchedNoPostTransaction((int)$fixture['matched_transaction_id']));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['source_transaction_id']]));

            $sourceAfterClear = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['source_transaction_id']]);
            $matchedAfterClear = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['matched_transaction_id']]);
            foreach ([$sourceAfterClear, $matchedAfterClear] as $row) {
                $harness->assertSame(null, $row['nominal_account_id'] ?? null);
                $harness->assertSame(null, $row['transfer_account_id'] ?? null);
                $harness->assertSame(0, (int)($row['is_internal_transfer'] ?? 1));
                $harness->assertSame('uncategorised', (string)($row['category_status'] ?? ''));
                $harness->assertSame(null, $row['auto_rule_id'] ?? null);
                $harness->assertSame(0, (int)($row['is_auto_excluded'] ?? 1));
            }
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\TransactionInterAccountMarkerService::class, 'auto matches same-day transfer marker pair through inter-account flow', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker', 'transaction_category_audit', 'journals', 'journal_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionInterAccountMarkerCreateP2PFixture();

            $result = $service->autoMatchTransferMarkerTransaction((int)$fixture['incoming_transaction_id']);
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(true, (bool)($result['matched'] ?? false));
            $harness->assertSame((int)$fixture['outgoing_transaction_id'], (int)($result['source_transaction_id'] ?? 0));
            $harness->assertSame((int)$fixture['incoming_transaction_id'], (int)($result['matched_transaction_id'] ?? 0));

            $marker = InterfaceDB::fetchOne(
                'SELECT transaction_id, matched_transaction_id, created_by
                 FROM transaction_inter_ac_marker
                 WHERE transaction_id = :transaction_id
                 LIMIT 1',
                ['transaction_id' => (int)$fixture['outgoing_transaction_id']]
            );
            $harness->assertSame((int)$fixture['outgoing_transaction_id'], (int)($marker['transaction_id'] ?? 0));
            $harness->assertSame((int)$fixture['incoming_transaction_id'], (int)($marker['matched_transaction_id'] ?? 0));
            $harness->assertSame('transfer_marker:auto', (string)($marker['created_by'] ?? ''));

            $outgoing = InterfaceDB::fetchOne('SELECT transfer_account_id, is_internal_transfer, category_status FROM transactions WHERE id = :id', ['id' => (int)$fixture['outgoing_transaction_id']]);
            $incoming = InterfaceDB::fetchOne('SELECT transfer_account_id, is_internal_transfer, category_status FROM transactions WHERE id = :id', ['id' => (int)$fixture['incoming_transaction_id']]);
            $harness->assertSame((int)$fixture['savings_account_id'], (int)($outgoing['transfer_account_id'] ?? 0));
            $harness->assertSame((int)$fixture['current_account_id'], (int)($incoming['transfer_account_id'] ?? 0));
            $harness->assertSame(1, (int)($outgoing['is_internal_transfer'] ?? 0));
            $harness->assertSame(1, (int)($incoming['is_internal_transfer'] ?? 0));
            $harness->assertSame('manual', (string)($outgoing['category_status'] ?? ''));
            $harness->assertSame('manual', (string)($incoming['category_status'] ?? ''));

            $harness->assertSame(1, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['outgoing_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['incoming_transaction_id']]));
            $lines = InterfaceDB::fetchAll(
                'SELECT jl.nominal_account_id, jl.company_account_id, jl.debit, jl.credit
                 FROM journals j
                 INNER JOIN journal_lines jl ON jl.journal_id = j.id
                 WHERE j.source_type = :source_type
                   AND j.source_ref = :source_ref
                 ORDER BY jl.id ASC',
                [
                    'source_type' => 'bank_csv',
                    'source_ref' => 'transaction:' . (int)$fixture['outgoing_transaction_id'],
                ]
            );
            $harness->assertSame((int)$fixture['savings_nominal_id'], (int)($lines[0]['nominal_account_id'] ?? 0));
            $harness->assertSame((int)$fixture['savings_account_id'], (int)($lines[0]['company_account_id'] ?? 0));
            $harness->assertSame('270.00', number_format((float)($lines[0]['debit'] ?? 0), 2, '.', ''));
            $harness->assertSame((int)$fixture['current_nominal_id'], (int)($lines[1]['nominal_account_id'] ?? 0));
            $harness->assertSame((int)$fixture['current_account_id'], (int)($lines[1]['company_account_id'] ?? 0));
            $harness->assertSame('270.00', number_format((float)($lines[1]['credit'] ?? 0), 2, '.', ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\TransactionInterAccountMarkerService::class, 'does not auto match ambiguous or unsafe transfer marker pairs', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker', 'journals', 'journal_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        $scenarios = [
            'extra_candidate' => 'ambiguous',
            'same_sign' => 'no_candidate',
            'missing_marker' => 'no_candidate',
            'existing_marker' => 'no_candidate',
            'posted_journal' => 'no_candidate',
            'split_transaction' => 'no_candidate',
        ];
        if (!InterfaceDB::tableExists('transaction_splits')) {
            unset($scenarios['split_transaction']);
        }

        foreach ($scenarios as $scenario => $expectedReason) {
            InterfaceDB::beginTransaction();
            try {
                $fixture = transactionInterAccountMarkerCreateP2PFixture(['scenario' => $scenario]);
                $result = $service->autoMatchTransferMarkerTransaction((int)$fixture['outgoing_transaction_id']);

                $harness->assertSame(true, (bool)($result['success'] ?? false));
                $harness->assertSame(false, (bool)($result['matched'] ?? true));
                $harness->assertSame($expectedReason, (string)($result['skipped_reason'] ?? ''));
                $harness->assertSame(0, InterfaceDB::countWhere('transaction_inter_ac_marker', ['transaction_id' => (int)$fixture['outgoing_transaction_id']]));
                $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['outgoing_transaction_id']]));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    });

    $harness->check(TransactionAction::class, 'save and cancel inter-account match clean backend state', static function () use ($harness): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker', 'transaction_auto_approvals', 'transaction_category_audit', 'journals', 'journal_lines'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionInterAccountMarkerCreateFixture();
            transactionInterAccountMarkerPrepareStaleAutoState($fixture);
            transactionInterAccountMarkerInsertJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['matched_transaction_id'],
                'stale matched evidence journal'
            );

            $action = new TransactionAction();
            $saveResult = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'Transaction',
                        'global_action' => 'save_inter_ac_transaction',
                        'company_id' => (string)$fixture['company_id'],
                        'accounting_period_id' => (string)$fixture['accounting_period_id'],
                        'transaction_id' => (string)$fixture['source_transaction_id'],
                        'matched_transaction_id' => (string)$fixture['matched_transaction_id'],
                    ],
                    ['REQUEST_METHOD' => 'POST'],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $saveResult->isSuccess());
            $saveMessages = transactionInterAccountMarkerFlashMessages($saveResult);
            $harness->assertTrue(in_array('Inter-account transaction match saved.', $saveMessages, true));
            $harness->assertFalse(in_array('The matched transaction journal was removed because it is evidence only.', $saveMessages, true));

            $source = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['source_transaction_id']]);
            $matched = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['matched_transaction_id']]);

            $harness->assertSame(null, $source['nominal_account_id'] ?? null);
            $harness->assertSame((int)$fixture['trade_account_id'], (int)($source['transfer_account_id'] ?? 0));
            $harness->assertSame(1, (int)($source['is_internal_transfer'] ?? 0));
            $harness->assertSame('manual', (string)($source['category_status'] ?? ''));
            $harness->assertSame(null, $source['auto_rule_id'] ?? null);
            $harness->assertSame(0, (int)($source['is_auto_excluded'] ?? 1));

            $harness->assertSame(null, $matched['nominal_account_id'] ?? null);
            $harness->assertSame((int)$fixture['bank_account_id'], (int)($matched['transfer_account_id'] ?? 0));
            $harness->assertSame(1, (int)($matched['is_internal_transfer'] ?? 0));
            $harness->assertSame('manual', (string)($matched['category_status'] ?? ''));
            $harness->assertSame(null, $matched['auto_rule_id'] ?? null);
            $harness->assertSame(0, (int)($matched['is_auto_excluded'] ?? 1));

            $harness->assertSame(0, InterfaceDB::countWhere('transaction_auto_approvals', ['transaction_id' => (int)$fixture['source_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('transaction_auto_approvals', ['transaction_id' => (int)$fixture['matched_transaction_id']]));
            $harness->assertSame(1, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['source_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['matched_transaction_id']]));
            $harness->assertSame(2, InterfaceDB::countWhere('transaction_category_audit', ['changed_by' => 'inter_ac_marker']));

            $cancelResult = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'Transaction',
                        'global_action' => 'cancel_inter_ac_transaction',
                        'company_id' => (string)$fixture['company_id'],
                        'accounting_period_id' => (string)$fixture['accounting_period_id'],
                        'transaction_id' => (string)$fixture['source_transaction_id'],
                    ],
                    ['REQUEST_METHOD' => 'POST'],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $cancelResult->isSuccess());
            $harness->assertSame(0, InterfaceDB::countWhere('transaction_inter_ac_marker', ['transaction_id' => (int)$fixture['source_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['source_transaction_id']]));
            $harness->assertSame(0, InterfaceDB::countWhere('journals', ['source_type' => 'bank_csv', 'source_ref' => 'transaction:' . (int)$fixture['matched_transaction_id']]));

            $sourceAfterCancel = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['source_transaction_id']]);
            $matchedAfterCancel = InterfaceDB::fetchOne('SELECT nominal_account_id, transfer_account_id, is_internal_transfer, category_status, auto_rule_id, is_auto_excluded FROM transactions WHERE id = :id', ['id' => (int)$fixture['matched_transaction_id']]);
            foreach ([$sourceAfterCancel, $matchedAfterCancel] as $row) {
                $harness->assertSame(null, $row['nominal_account_id'] ?? null);
                $harness->assertSame(null, $row['transfer_account_id'] ?? null);
                $harness->assertSame(0, (int)($row['is_internal_transfer'] ?? 1));
                $harness->assertSame('uncategorised', (string)($row['category_status'] ?? ''));
                $harness->assertSame(null, $row['auto_rule_id'] ?? null);
                $harness->assertSame(0, (int)($row['is_auto_excluded'] ?? 1));
            }
            $harness->assertSame(2, InterfaceDB::countWhere('transaction_category_audit', ['changed_by' => 'inter_ac_cancel']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function transactionInterAccountMarkerCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('51' . $marker);
    $accountingPeriodId = (int)('52' . $marker);
    $bankNominalId = (int)('53' . $marker);
    $tradeNominalId = (int)('54' . $marker);
    $bankAccountId = (int)('55' . $marker);
    $tradeAccountId = (int)('56' . $marker);
    $uploadId = (int)('57' . $marker);
    $sourceTransactionId = (int)('58' . $marker);
    $matchedTransactionId = (int)('59' . $marker);
    $sameAccountTransactionId = (int)('60' . $marker);
    $wrongAmountTransactionId = (int)('61' . $marker);
    $tooLateTransactionId = (int)('62' . $marker);
    $autoRuleId = (int)('63' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Inter Account Fixture ' . $marker,
            'company_number' => 'IA' . substr($marker, 0, 6),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'IA FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );

    transactionInterAccountMarkerInsertNominal($bankNominalId, 'IA' . substr($marker, 0, 4), 'Example Bank Nominal ' . $marker, 'asset', 'other');
    transactionInterAccountMarkerInsertNominal($tradeNominalId, 'IT' . substr($marker, 0, 4), 'Example Trade Supplier Nominal ' . $marker, 'liability', 'other');

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
            'id' => $autoRuleId,
            'company_id' => $companyId,
            'match_field' => 'description',
            'desc_match_type' => 'contains',
            'desc_match_value' => 'STALE INTER ACCOUNT AUTO STATE',
            'nominal_account_id' => $bankNominalId,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $bankAccountId,
            'company_id' => $companyId,
            'account_name' => 'Example Bank - Current Account',
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'nominal_account_id' => $bankNominalId,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $tradeAccountId,
            'company_id' => $companyId,
            'account_name' => 'Example Trade Supplier',
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
            'nominal_account_id' => $tradeNominalId,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (id, company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256)
         VALUES (:id, :company_id, :accounting_period_id, :statement_month, :original_filename, :stored_filename, :file_sha256)',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => '2026-03-01',
            'original_filename' => 'inter-account-' . $marker . '.csv',
            'stored_filename' => 'inter-account-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'inter-account-upload-' . $marker),
        ]
    );

    transactionInterAccountMarkerInsertTransaction($sourceTransactionId, $companyId, $accountingPeriodId, $uploadId, $bankAccountId, '2026-03-15', 'Example Bank payment to Example Trade Supplier', '-241.46', $marker . '-source');
    transactionInterAccountMarkerInsertTransaction($matchedTransactionId, $companyId, $accountingPeriodId, $uploadId, $tradeAccountId, '2026-03-16', 'Example Trade Supplier payment received', '241.46', $marker . '-matched');
    transactionInterAccountMarkerInsertTransaction($sameAccountTransactionId, $companyId, $accountingPeriodId, $uploadId, $bankAccountId, '2026-03-16', 'Same account payment', '241.46', $marker . '-same-account');
    transactionInterAccountMarkerInsertTransaction($wrongAmountTransactionId, $companyId, $accountingPeriodId, $uploadId, $tradeAccountId, '2026-03-16', 'Wrong amount payment', '250.00', $marker . '-wrong-amount');
    transactionInterAccountMarkerInsertTransaction($tooLateTransactionId, $companyId, $accountingPeriodId, $uploadId, $tradeAccountId, '2026-03-25', 'Late matching payment', '241.46', $marker . '-too-late');

    return [
        'source_transaction_id' => $sourceTransactionId,
        'matched_transaction_id' => $matchedTransactionId,
        'same_account_transaction_id' => $sameAccountTransactionId,
        'wrong_amount_transaction_id' => $wrongAmountTransactionId,
        'too_late_transaction_id' => $tooLateTransactionId,
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'bank_nominal_id' => $bankNominalId,
        'trade_nominal_id' => $tradeNominalId,
        'bank_account_id' => $bankAccountId,
        'trade_account_id' => $tradeAccountId,
        'auto_rule_id' => $autoRuleId,
    ];
}

function transactionInterAccountMarkerPrepareStaleAutoState(array $fixture): void
{
    foreach ([(int)$fixture['source_transaction_id'], (int)$fixture['matched_transaction_id']] as $transactionId) {
        InterfaceDB::prepareExecute(
            'UPDATE transactions
             SET nominal_account_id = :nominal_account_id,
                 category_status = :category_status,
                 auto_rule_id = :auto_rule_id,
                 is_auto_excluded = 1
             WHERE id = :id',
            [
                'id' => $transactionId,
                'nominal_account_id' => (int)$fixture['bank_nominal_id'],
                'category_status' => 'auto',
                'auto_rule_id' => (int)$fixture['auto_rule_id'],
            ]
        );
        (new \eel_accounts\Service\TransactionAutoApprovalService())->setTransactionApprovalState(
            (int)$fixture['company_id'],
            (int)$fixture['accounting_period_id'],
            $transactionId,
            true,
            null
        );
    }
}

function transactionInterAccountMarkerCreateP2PFixture(array $options = []): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('71' . $marker);
    $accountingPeriodId = (int)('72' . $marker);
    $currentNominalId = (int)('73' . $marker);
    $savingsNominalId = (int)('74' . $marker);
    $extraNominalId = (int)('75' . $marker);
    $currentAccountId = (int)('76' . $marker);
    $savingsAccountId = (int)('77' . $marker);
    $extraAccountId = (int)('78' . $marker);
    $uploadId = (int)('79' . $marker);
    $outgoingTransactionId = (int)('80' . $marker);
    $incomingTransactionId = (int)('81' . $marker);
    $extraTransactionId = (int)('82' . $marker);
    $scenario = (string)($options['scenario'] ?? '');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'P2P Fixture ' . $marker,
            'company_number' => 'P2P' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'P2P FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );

    transactionInterAccountMarkerInsertNominal($currentNominalId, 'PC' . substr($marker, 0, 4), 'P2P Current ' . $marker, 'asset', 'other');
    transactionInterAccountMarkerInsertNominal($savingsNominalId, 'PS' . substr($marker, 0, 4), 'P2P Savings ' . $marker, 'asset', 'other');
    transactionInterAccountMarkerInsertNominal($extraNominalId, 'PX' . substr($marker, 0, 4), 'P2P Extra ' . $marker, 'asset', 'other');

    transactionInterAccountMarkerInsertBankAccount($currentAccountId, $companyId, 'Example Bank - Current Account', $currentNominalId, 'Example Bank', 'P2P');
    transactionInterAccountMarkerInsertBankAccount(
        $savingsAccountId,
        $companyId,
        'Example Bank - Saving Pot',
        $savingsNominalId,
        'Example Bank',
        $scenario === 'missing_marker' ? '' : 'P2P'
    );
    transactionInterAccountMarkerInsertBankAccount($extraAccountId, $companyId, 'Example Bank - Extra Pot', $extraNominalId, 'Example Bank', 'P2P');

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (id, company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256)
         VALUES (:id, :company_id, :accounting_period_id, :statement_month, :original_filename, :stored_filename, :file_sha256)',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => '2026-03-01',
            'original_filename' => 'p2p-' . $marker . '.csv',
            'stored_filename' => 'p2p-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'p2p-upload-' . $marker),
        ]
    );

    transactionInterAccountMarkerInsertP2PTransaction($outgoingTransactionId, $companyId, $accountingPeriodId, $uploadId, $currentAccountId, '2026-03-20', 'Transfer to Pot', '-270.00', 'P2P', $marker . '-outgoing');
    transactionInterAccountMarkerInsertP2PTransaction(
        $incomingTransactionId,
        $companyId,
        $accountingPeriodId,
        $uploadId,
        $savingsAccountId,
        '2026-03-20',
        'Deposit',
        $scenario === 'same_sign' ? '-270.00' : '270.00',
        'P2P',
        $marker . '-incoming'
    );

    if ($scenario === 'extra_candidate') {
        transactionInterAccountMarkerInsertP2PTransaction($extraTransactionId, $companyId, $accountingPeriodId, $uploadId, $extraAccountId, '2026-03-20', 'Deposit', '270.00', 'P2P', $marker . '-extra');
    } elseif ($scenario === 'existing_marker') {
        transactionInterAccountMarkerInsertP2PTransaction($extraTransactionId, $companyId, $accountingPeriodId, $uploadId, $extraAccountId, '2026-03-19', 'Earlier transfer', '-270.00', 'P2P', $marker . '-extra');
        InterfaceDB::prepareExecute(
            'INSERT INTO transaction_inter_ac_marker (company_id, accounting_period_id, transaction_id, matched_transaction_id, created_by)
             VALUES (:company_id, :accounting_period_id, :transaction_id, :matched_transaction_id, :created_by)',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'transaction_id' => $extraTransactionId,
                'matched_transaction_id' => $incomingTransactionId,
                'created_by' => 'test_existing_marker',
            ]
        );
    } elseif ($scenario === 'posted_journal') {
        transactionInterAccountMarkerInsertJournal($companyId, $accountingPeriodId, $incomingTransactionId, 'existing incoming journal');
    } elseif ($scenario === 'split_transaction' && InterfaceDB::tableExists('transaction_splits')) {
        InterfaceDB::prepareExecute(
            'INSERT INTO transaction_splits (transaction_id) VALUES (:transaction_id)',
            ['transaction_id' => $incomingTransactionId]
        );
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'current_nominal_id' => $currentNominalId,
        'savings_nominal_id' => $savingsNominalId,
        'current_account_id' => $currentAccountId,
        'savings_account_id' => $savingsAccountId,
        'outgoing_transaction_id' => $outgoingTransactionId,
        'incoming_transaction_id' => $incomingTransactionId,
    ];
}

function transactionInterAccountMarkerInsertBankAccount(
    int $id,
    int $companyId,
    string $accountName,
    int $nominalAccountId,
    string $institutionName,
    string $internalTransferMarker
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (
            id,
            company_id,
            account_name,
            account_type,
            institution_name,
            nominal_account_id,
            internal_transfer_marker,
            is_active
         ) VALUES (
            :id,
            :company_id,
            :account_name,
            :account_type,
            :institution_name,
            :nominal_account_id,
            :internal_transfer_marker,
            1
         )',
        [
            'id' => $id,
            'company_id' => $companyId,
            'account_name' => $accountName,
            'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
            'institution_name' => $institutionName,
            'nominal_account_id' => $nominalAccountId,
            'internal_transfer_marker' => $internalTransferMarker !== '' ? $internalTransferMarker : null,
        ]
    );
}

function transactionInterAccountMarkerInsertP2PTransaction(
    int $id,
    int $companyId,
    int $accountingPeriodId,
    int $uploadId,
    int $accountId,
    string $txnDate,
    string $description,
    string $amount,
    string $txnType,
    string $dedupeSuffix
): void {
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
            source_account_label,
            dedupe_hash,
            is_internal_transfer,
            category_status
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
            :source_account_label,
            :dedupe_hash,
            1,
            :category_status
         )',
        [
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => $txnDate,
            'txn_type' => $txnType,
            'description' => $description,
            'amount' => $amount,
            'source_account_label' => 'P2P Fixture',
            'dedupe_hash' => hash('sha256', 'p2p-transaction-' . $dedupeSuffix),
            'category_status' => 'uncategorised',
        ]
    );
}

function transactionInterAccountMarkerInsertJournal(int $companyId, int $accountingPeriodId, int $transactionId, string $description): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $transactionId,
            'journal_date' => '2026-03-16',
            'description' => $description,
        ]
    );
}

function transactionInterAccountMarkerFlashMessages(ActionResultFramework $result): array
{
    $messages = [];
    foreach ($result->flashMessages() as $flashMessage) {
        if (is_array($flashMessage)) {
            $messages[] = (string)($flashMessage['message'] ?? '');
            continue;
        }
        $messages[] = (string)$flashMessage;
    }

    return $messages;
}

function transactionInterAccountMarkerInsertNominal(int $id, string $code, string $name, string $accountType, string $taxTreatment): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
        ]
    );
}

function transactionInterAccountMarkerInsertTransaction(
    int $id,
    int $companyId,
    int $accountingPeriodId,
    int $uploadId,
    int $accountId,
    string $txnDate,
    string $description,
    string $amount,
    string $dedupeSuffix
): void {
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            description,
            amount,
            source_account_label,
            dedupe_hash
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :description,
            :amount,
            :source_account_label,
            :dedupe_hash
         )',
        [
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => $txnDate,
            'description' => $description,
            'amount' => $amount,
            'source_account_label' => 'Inter Account Fixture',
            'dedupe_hash' => hash('sha256', 'inter-account-transaction-' . $dedupeSuffix),
        ]
    );
}
