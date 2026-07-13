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
    \eel_accounts\Service\TransactionSplitService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TransactionSplitService $service): void {
        $harness->check(\eel_accounts\Service\TransactionSplitService::class, 'creates and balances a two-line transaction split', static function () use ($harness, $service): void {
            transactionSplitTestRequireSchema($harness);

            \InterfaceDB::beginTransaction();
            try {
                $fixture = transactionSplitTestCreateFixture();
                $started = $service->startSplit($fixture['company_id'], $fixture['transaction_id']);
                $split = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $lines = array_values((array)($split['lines'] ?? []));

                $harness->assertSame(true, (bool)($started['success'] ?? false));
                $harness->assertSame(2, count($lines));
                $harness->assertSame('146.36', (string)($split['target_amount'] ?? ''));
                $harness->assertSame('146.36', (string)($split['difference'] ?? ''));
                $harness->assertSame(0, (int)($split['is_ready'] ?? 1));

                $firstLineId = (int)$lines[0]['id'];
                $secondLineId = (int)$lines[1]['id'];
                foreach (['56', '56.3', '56.370', '£56.37', '1,234.56'] as $invalidAmount) {
                    $invalidResult = $service->saveLine($fixture['company_id'], $firstLineId, [
                        'split_line_description' => 'Invalid amount test',
                        'split_line_amount' => $invalidAmount,
                        'nominal_account_id' => $fixture['tool_nominal_id'],
                    ]);
                    $harness->assertSame(false, (bool)($invalidResult['success'] ?? true));
                    $harness->assertSame(true, str_contains(implode("\n", (array)($invalidResult['errors'] ?? [])), 'exactly 2 decimal places'));
                }
                $unchangedLine = \InterfaceDB::fetchOne(
                    'SELECT description, amount, nominal_account_id
                     FROM transaction_split_lines
                     WHERE id = :id',
                    ['id' => $firstLineId]
                );
                $harness->assertSame(null, $unchangedLine['description'] ?? null);
                $harness->assertSame(null, $unchangedLine['amount'] ?? null);
                $harness->assertSame(null, $unchangedLine['nominal_account_id'] ?? null);

                $draftLine = $service->saveLine($fixture['company_id'], $firstLineId, [
                    'split_line_description' => 'AMZNMKTPLACE tool item',
                    'split_line_amount' => '89.99',
                    'nominal_account_id' => '',
                ]);
                $draftSplit = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $harness->assertSame(true, (bool)($draftLine['success'] ?? false));
                $harness->assertSame('89.99', (string)($draftSplit['line_total'] ?? ''));
                $harness->assertSame('56.37', (string)($draftSplit['difference'] ?? ''));
                $harness->assertSame(0, (int)($draftSplit['is_ready'] ?? 1));

                $service->saveLine($fixture['company_id'], $firstLineId, [
                    'split_line_description' => 'AMZNMKTPLACE tool item',
                    'split_line_amount' => '89.99',
                    'nominal_account_id' => $fixture['tool_nominal_id'],
                ]);
                $partSplit = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $harness->assertSame('56.37', (string)($partSplit['difference'] ?? ''));
                $harness->assertSame(0, (int)($partSplit['is_ready'] ?? 1));

                $service->saveLine($fixture['company_id'], $secondLineId, [
                    'split_line_description' => 'AMZNMKTPLACE materials',
                    'split_line_amount' => '56.37',
                    'nominal_account_id' => $fixture['materials_nominal_id'],
                ]);
                $readySplit = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $transaction = \InterfaceDB::fetchOne(
                    'SELECT nominal_account_id, category_status
                     FROM transactions
                     WHERE id = :id',
                    ['id' => $fixture['transaction_id']]
                );

                $harness->assertSame('0.00', (string)($readySplit['difference'] ?? ''));
                $harness->assertSame(1, (int)($readySplit['is_ready'] ?? 0));
                $harness->assertSame(null, $transaction['nominal_account_id'] ?? null);
                $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));

                $service->addLine($fixture['company_id'], $fixture['transaction_id']);
                $expandedSplit = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $harness->assertSame(3, count((array)($expandedSplit['lines'] ?? [])));
                $harness->assertSame(0, (int)($expandedSplit['is_ready'] ?? 1));

                $expandedLines = array_values((array)($expandedSplit['lines'] ?? []));
                $removed = $service->removeLine($fixture['company_id'], (int)$expandedLines[2]['id']);
                $harness->assertSame(true, (bool)($removed['success'] ?? false));
                $readyAgainSplit = (array)$service->fetchSplitForTransaction($fixture['transaction_id']);
                $harness->assertSame(2, count((array)($readyAgainSplit['lines'] ?? [])));
                $harness->assertSame(1, (int)($readyAgainSplit['is_ready'] ?? 0));

                $merged = $service->mergeSplit($fixture['company_id'], $fixture['transaction_id'], true);
                $harness->assertSame(true, (bool)($merged['success'] ?? false));
                $harness->assertSame(null, $service->fetchSplitForTransaction($fixture['transaction_id']));
            } finally {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\TransactionSplitService::class, 'rejects locked accounting periods', static function () use ($harness, $service): void {
            transactionSplitTestRequireSchema($harness);

            \InterfaceDB::beginTransaction();
            try {
                $fixture = transactionSplitTestCreateFixture('locked');
                \InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, review_notes)
                     VALUES (:company_id, :accounting_period_id, 1, NULL)',
                    [
                        'company_id' => $fixture['company_id'],
                        'accounting_period_id' => $fixture['accounting_period_id'],
                    ]
                );

                try {
                    $service->startSplit($fixture['company_id'], $fixture['transaction_id']);
                    $harness->assertTrue(false);
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains($exception->getMessage(), 'locked'));
                }
            } finally {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            }
        });
    }
);

function transactionSplitTestRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach ([
        'companies',
        'accounting_periods',
        'statement_uploads',
        'company_accounts',
        'nominal_accounts',
        'transactions',
        'transaction_splits',
        'transaction_split_lines',
        'year_end_reviews',
    ] as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function transactionSplitTestCreateFixture(string $label = 'fixture'): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('31' . $marker);
    $accountingPeriodId = (int)('32' . $marker);
    $sourceNominalId = transactionSplitTestInsertNominal('TSB' . substr($marker, 0, 4), 'Split Test Bank ' . $marker, 'asset', 'other');
    $toolNominalId = transactionSplitTestInsertNominal('TST' . substr($marker, 0, 4), 'Split Test Tools ' . $marker, 'asset', 'capital');
    $materialsNominalId = transactionSplitTestInsertNominal('TSM' . substr($marker, 0, 4), 'Split Test Materials ' . $marker, 'cost_of_sales', 'allowable');
    $accountId = (int)('33' . $marker);
    $uploadId = (int)('34' . $marker);
    $transactionId = (int)('35' . $marker);

    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Transaction Split ' . $label . ' ' . $marker,
            'company_number' => 'TS' . substr($marker, 0, 6),
        ]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Split FY ' . $marker,
            'period_start' => '2023-10-01',
            'period_end' => '2024-09-30',
        ]
    );
    \InterfaceDB::prepareExecute(
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
    \InterfaceDB::prepareExecute(
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
            'original_filename' => 'split-' . $marker . '.csv',
            'stored_filename' => 'split-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'split-upload-' . $marker),
        ]
    );
    \InterfaceDB::prepareExecute(
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
            'dedupe_hash' => hash('sha256', 'split-transaction-' . $marker),
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'account_id' => $accountId,
        'source_nominal_id' => $sourceNominalId,
        'tool_nominal_id' => $toolNominalId,
        'materials_nominal_id' => $materialsNominalId,
        'transaction_id' => $transactionId,
    ];
}

function transactionSplitTestInsertNominal(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $id = (int)random_int(200000000, 899999999);
    \InterfaceDB::prepareExecute(
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
