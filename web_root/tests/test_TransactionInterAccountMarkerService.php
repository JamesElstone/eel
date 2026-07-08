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

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\TransactionInterAccountMarkerService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TransactionInterAccountMarkerService $service): void {
    $harness->check(\eel_accounts\Service\TransactionInterAccountMarkerService::class, 'creates 5802 to 4516 marker and filters candidates', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker'] as $table) {
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

            $secondResult = $service->saveMarker((int)$fixture['same_account_transaction_id'], (int)$fixture['source_transaction_id'], 'test');
            $harness->assertSame(false, (bool)($secondResult['success'] ?? true));

            $clearResult = $service->clearMarkerForTransaction((int)$fixture['matched_transaction_id']);
            $harness->assertSame(true, (bool)($clearResult['removed'] ?? false));
            $harness->assertSame(false, $service->isMatchedNoPostTransaction((int)$fixture['matched_transaction_id']));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(TransactionAction::class, 'save and cancel inter-account match clean backend state', static function () use ($harness): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'company_accounts', 'statement_uploads', 'transactions', 'transaction_inter_ac_marker', 'transaction_auto_approvals', 'transaction_category_audit', 'journals'] as $table) {
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
            $harness->assertSame(null, $matched['transfer_account_id'] ?? null);
            $harness->assertSame(0, (int)($matched['is_internal_transfer'] ?? 1));
            $harness->assertSame('uncategorised', (string)($matched['category_status'] ?? ''));
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
                'auto_rule_id' => 9001,
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
