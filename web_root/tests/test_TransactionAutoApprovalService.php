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
$harness->run(\eel_accounts\Service\TransactionAutoApprovalService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TransactionAutoApprovalService $service): void {
    $harness->check(\eel_accounts\Service\TransactionAutoApprovalService::class, 'checkbox and post confirmation create current approval state', static function () use ($harness, $service): void {
        transactionAutoApprovalRequireSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionAutoApprovalCreateFixture();

            $set = $service->setTransactionApprovalState(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['transaction_id'],
                true,
                null
            );
            $harness->assertSame(true, (bool)($set['success'] ?? false));
            $harness->assertSame('checked', (string)InterfaceDB::fetchColumn(
                'SELECT state FROM transaction_auto_approvals WHERE transaction_id = :transaction_id',
                ['transaction_id' => (int)$fixture['transaction_id']]
            ));

            $unset = $service->setTransactionApprovalState(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['transaction_id'],
                false,
                null
            );
            $harness->assertSame(true, (bool)($unset['success'] ?? false));
            $harness->assertSame('pending', (string)InterfaceDB::fetchColumn(
                'SELECT state FROM transaction_auto_approvals WHERE transaction_id = :transaction_id',
                ['transaction_id' => (int)$fixture['transaction_id']]
            ));

            $set = $service->setTransactionApprovalState(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['transaction_id'],
                true,
                null
            );
            $harness->assertSame(true, (bool)($set['success'] ?? false));

            $harness->assertSame(1, $service->pendingPostConfirmationCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                null
            ));

            $confirmed = $service->confirmPostableAutoTransactions(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                null,
                null
            );
            $harness->assertSame(true, (bool)($confirmed['success'] ?? false));
            $harness->assertSame(1, (int)($confirmed['confirmed'] ?? 0));
            $harness->assertSame(0, $service->pendingPostConfirmationCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                null
            ));

            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET notes = :notes,
                     updated_at = DATE_ADD(updated_at, INTERVAL 2 SECOND)
                 WHERE id = :id',
                [
                    'id' => (int)$fixture['transaction_id'],
                    'notes' => 'Changed after confirmation',
                ]
            );
            $harness->assertSame(1, $service->pendingPostConfirmationCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                null
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\TransactionAutoApprovalService::class, 'year end pending review count follows current checkbox decision', static function () use ($harness, $service): void {
        transactionAutoApprovalRequireSchema($harness);

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionAutoApprovalCreateFixture();
            $metrics = new \eel_accounts\Service\YearEndMetricsService();

            $harness->assertSame(1, $metrics->autoCategorisedPendingReviewCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            ));

            $set = $service->setTransactionApprovalState(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                (int)$fixture['transaction_id'],
                true,
                null
            );
            $harness->assertSame(true, (bool)($set['success'] ?? false));
            $harness->assertSame(0, $metrics->autoCategorisedPendingReviewCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            ));

            InterfaceDB::prepareExecute(
                'UPDATE transactions
                 SET notes = :notes,
                     updated_at = DATE_ADD(updated_at, INTERVAL 2 SECOND)
                 WHERE id = :id',
                [
                    'id' => (int)$fixture['transaction_id'],
                    'notes' => 'Changed after checkbox decision',
                ]
            );
            $harness->assertSame(1, $metrics->autoCategorisedPendingReviewCount(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-01-01',
                '2026-12-31'
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function transactionAutoApprovalRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'statement_uploads', 'nominal_accounts', 'categorisation_rules', 'transactions', 'transaction_auto_approvals'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function transactionAutoApprovalCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('71' . $marker);
    $accountingPeriodId = (int)('72' . $marker);
    $nominalAccountId = (int)('73' . $marker);
    $uploadId = (int)('74' . $marker);
    $ruleId = (int)('75' . $marker);
    $transactionId = (int)('76' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Auto Approval Fixture ' . $marker,
            'company_number' => 'AAF' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'AAF FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'id' => $nominalAccountId,
            'code' => 'A' . substr($marker, 0, 4),
            'name' => 'Auto Approval Nominal ' . $marker,
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
            'original_filename' => 'auto-approval-' . $marker . '.csv',
            'stored_filename' => 'auto-approval-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'auto-approval-upload-' . $marker),
        ]
    );
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
            'company_id' => $companyId,
            'match_field' => 'description',
            'desc_match_type' => 'contains',
            'desc_match_value' => 'AUTO APPROVAL',
            'nominal_account_id' => $nominalAccountId,
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
            amount,
            dedupe_hash,
            nominal_account_id,
            category_status,
            auto_rule_id
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :description,
            :amount,
            :dedupe_hash,
            :nominal_account_id,
            :category_status,
            :auto_rule_id
         )',
        [
            'id' => $transactionId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2026-03-15',
            'description' => 'AUTO APPROVAL SUPPLIES',
            'amount' => '-42.50',
            'dedupe_hash' => hash('sha256', 'auto-approval-transaction-' . $marker),
            'nominal_account_id' => $nominalAccountId,
            'category_status' => 'auto',
            'auto_rule_id' => $ruleId,
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'transaction_id' => $transactionId,
    ];
}
