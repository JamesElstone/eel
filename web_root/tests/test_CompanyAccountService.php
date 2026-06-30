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
$harness->run(\eel_accounts\Service\CompanyAccountService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompanyAccountService $service): void {
    $harness->check(\eel_accounts\Service\CompanyAccountService::class, 'returns expected account types', function () use ($harness): void {
        $harness->assertSame(
            [
                \eel_accounts\Service\CompanyAccountService::TYPE_BANK => 'Bank',
                \eel_accounts\Service\CompanyAccountService::TYPE_TRADE => 'Trade',
            ],
            \eel_accounts\Service\CompanyAccountService::accountTypes()
        );
    });

    $harness->check(\eel_accounts\Service\CompanyAccountService::class, 'reassesses internal transfer flags when an unposted marker changes', function () use ($harness, $service): void {
        withCompanyAccountTransferMarkerFixture(false, static function (array $fixture) use ($harness, $service): void {
            $result = $service->updateAccount(
                (int)$fixture['company_id'],
                (int)$fixture['account_id'],
                companyAccountTransferMarkerPayload($fixture, [
                    'account_name' => 'Updated Transfer Marker Fixture',
                    'internal_transfer_marker' => 'P2P',
                ])
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame([], (array)($result['warnings'] ?? []));

            $account = \InterfaceDB::fetchOne(
                'SELECT account_name, internal_transfer_marker FROM company_accounts WHERE id = :id',
                ['id' => (int)$fixture['account_id']]
            );
            $p2p = \InterfaceDB::fetchOne(
                'SELECT is_internal_transfer, nominal_account_id, auto_rule_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => (int)$fixture['p2p_transaction_id']]
            );
            $card = \InterfaceDB::fetchOne(
                'SELECT is_internal_transfer, nominal_account_id, auto_rule_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => (int)$fixture['card_transaction_id']]
            );

            $harness->assertSame('Updated Transfer Marker Fixture', (string)($account['account_name'] ?? ''));
            $harness->assertSame('P2P', (string)($account['internal_transfer_marker'] ?? ''));
            $harness->assertSame(1, (int)($p2p['is_internal_transfer'] ?? 0));
            $harness->assertSame(0, (int)($p2p['nominal_account_id'] ?? 0));
            $harness->assertSame(0, (int)($p2p['auto_rule_id'] ?? 0));
            $harness->assertSame('uncategorised', (string)($p2p['category_status'] ?? ''));
            $harness->assertSame(0, (int)($card['is_internal_transfer'] ?? 1));
            $harness->assertSame((int)$fixture['expense_nominal_id'], (int)($card['nominal_account_id'] ?? 0));
            $harness->assertSame('auto', (string)($card['category_status'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\CompanyAccountService::class, 'skips marker changes but saves other fields when source journals are posted', function () use ($harness, $service): void {
        withCompanyAccountTransferMarkerFixture(true, static function (array $fixture) use ($harness, $service): void {
            $result = $service->updateAccount(
                (int)$fixture['company_id'],
                (int)$fixture['account_id'],
                companyAccountTransferMarkerPayload($fixture, [
                    'account_name' => 'Posted Marker Fixture Renamed',
                    'internal_transfer_marker' => 'P2P',
                ])
            );

            $account = \InterfaceDB::fetchOne(
                'SELECT account_name, internal_transfer_marker FROM company_accounts WHERE id = :id',
                ['id' => (int)$fixture['account_id']]
            );
            $p2p = \InterfaceDB::fetchOne(
                'SELECT is_internal_transfer, nominal_account_id, auto_rule_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => (int)$fixture['p2p_transaction_id']]
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(
                ['Transactions have been posted for this account, so the internal transfer marker was not changed.'],
                array_values(array_map('strval', (array)($result['warnings'] ?? [])))
            );
            $harness->assertSame('Posted Marker Fixture Renamed', (string)($account['account_name'] ?? ''));
            $harness->assertSame('OLD', (string)($account['internal_transfer_marker'] ?? ''));
            $harness->assertSame(0, (int)($p2p['is_internal_transfer'] ?? 1));
            $harness->assertSame((int)$fixture['expense_nominal_id'], (int)($p2p['nominal_account_id'] ?? 0));
            $harness->assertSame((int)$fixture['rule_id'], (int)($p2p['auto_rule_id'] ?? 0));
            $harness->assertSame('auto', (string)($p2p['category_status'] ?? ''));
        });
    });
});

function withCompanyAccountTransferMarkerFixture(bool $withPostedJournal, callable $callback): void
{
    if (\InterfaceDB::inTransaction()) {
        throw new RuntimeException('Company account transfer marker fixture requires a clean transaction state.');
    }

    \InterfaceDB::beginTransaction();

    try {
        $marker = strtoupper(substr(hash('sha256', __FUNCTION__ . ($withPostedJournal ? 'posted' : 'unposted') . microtime(true)), 0, 10));
        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number, is_active)
             VALUES (:name, :number, 1)',
            [
                'name' => 'Transfer Marker Fixture ' . $marker,
                'number' => 'TM' . substr($marker, 0, 8),
            ]
        );
        $companyId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :number ORDER BY id DESC LIMIT 1',
            ['number' => 'TM' . substr($marker, 0, 8)]
        );

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
        $accountingPeriodId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'label' => 'FY ' . $marker]
        );

        $bankNominalId = companyAccountTransferMarkerNominal('BA' . substr($marker, 0, 6), 'Transfer Marker Bank ' . $marker, 'asset');
        $expenseNominalId = companyAccountTransferMarkerNominal('EX' . substr($marker, 0, 6), 'Transfer Marker Expense ' . $marker, 'expense');

        \InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (
                company_id,
                account_name,
                account_type,
                institution_name,
                account_identifier,
                nominal_account_id,
                internal_transfer_marker,
                contact_name,
                phone_number,
                address_line_1,
                is_active
             ) VALUES (
                :company_id,
                :account_name,
                :account_type,
                :institution_name,
                :account_identifier,
                :nominal_account_id,
                :internal_transfer_marker,
                :contact_name,
                :phone_number,
                :address_line_1,
                1
             )',
            [
                'company_id' => $companyId,
                'account_name' => 'Transfer Marker Account ' . $marker,
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'institution_name' => 'Fixture Bank',
                'account_identifier' => $marker,
                'nominal_account_id' => $bankNominalId,
                'internal_transfer_marker' => 'OLD',
                'contact_name' => 'Accounts',
                'phone_number' => '01234567890',
                'address_line_1' => '1 Test Street',
            ]
        );
        $accountId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM company_accounts WHERE company_id = :company_id AND account_name = :account_name ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId, 'account_name' => 'Transfer Marker Account ' . $marker]
        );

        \InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (company_id, priority, match_field, match_type, match_value, nominal_account_id, is_active)
             VALUES (:company_id, 100, :match_field, :match_type, :match_value, :nominal_account_id, 1)',
            [
                'company_id' => $companyId,
                'match_field' => 'type',
                'match_type' => 'equals',
                'match_value' => 'P2P',
                'nominal_account_id' => $expenseNominalId,
            ]
        );
        $ruleId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM categorisation_rules WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId]
        );

        $fileHash = hash('sha256', 'transfer-marker-upload-' . $marker);
        \InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                company_id,
                accounting_period_id,
                account_id,
                workflow_status,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
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
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'account_id' => $accountId,
                'workflow_status' => 'completed',
                'statement_month' => '2026-03-01',
                'original_filename' => 'transfer-marker-' . $marker . '.csv',
                'stored_filename' => 'transfer-marker-' . $marker . '.csv',
                'file_sha256' => $fileHash,
            ]
        );
        $uploadId = (int)\InterfaceDB::fetchColumn(
            'SELECT id FROM statement_uploads WHERE file_sha256 = :hash ORDER BY id DESC LIMIT 1',
            ['hash' => $fileHash]
        );

        $p2pTransactionId = companyAccountTransferMarkerTransaction(
            $companyId,
            $accountingPeriodId,
            $uploadId,
            $accountId,
            $expenseNominalId,
            $ruleId,
            'P2P',
            'Transfer from pot',
            hash('sha256', 'transfer-marker-p2p-' . $marker),
            0
        );
        $cardTransactionId = companyAccountTransferMarkerTransaction(
            $companyId,
            $accountingPeriodId,
            $uploadId,
            $accountId,
            $expenseNominalId,
            null,
            'POS',
            'Card purchase',
            hash('sha256', 'transfer-marker-card-' . $marker),
            1
        );

        if ($withPostedJournal) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                 VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'source_type' => 'bank_csv',
                    'source_ref' => 'transaction:' . $p2pTransactionId,
                    'journal_date' => '2026-03-15',
                    'description' => 'Posted transfer marker fixture',
                ]
            );
        }

        $callback([
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'bank_nominal_id' => $bankNominalId,
            'expense_nominal_id' => $expenseNominalId,
            'rule_id' => $ruleId,
            'p2p_transaction_id' => $p2pTransactionId,
            'card_transaction_id' => $cardTransactionId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}

function companyAccountTransferMarkerPayload(array $fixture, array $overrides = []): array
{
    return array_merge([
        'account_name' => 'Transfer Marker Account',
        'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
        'institution_name' => 'Fixture Bank',
        'account_identifier' => 'UPDATED-' . (int)($fixture['account_id'] ?? 0),
        'nominal_account_id' => (string)($fixture['bank_nominal_id'] ?? ''),
        'internal_transfer_marker' => 'OLD',
        'contact_name' => 'Accounts',
        'phone_number' => '01234567890',
        'address_line_1' => '1 Test Street',
        'address_line_2' => '',
        'address_locality' => '',
        'address_region' => '',
        'address_postal_code' => '',
        'address_country' => '',
        'is_active' => '1',
    ], $overrides);
}

function companyAccountTransferMarkerNominal(string $code, string $name, string $accountType): int
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => 'other',
        ]
    );

    return (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code ORDER BY id DESC LIMIT 1',
        ['code' => $code]
    );
}

function companyAccountTransferMarkerTransaction(
    int $companyId,
    int $accountingPeriodId,
    int $uploadId,
    int $accountId,
    int $nominalAccountId,
    ?int $ruleId,
    string $txnType,
    string $description,
    string $dedupeHash,
    int $isInternalTransfer
): int {
    \InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            txn_type,
            description,
            amount,
            dedupe_hash,
            nominal_account_id,
            is_internal_transfer,
            category_status,
            auto_rule_id
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :txn_type,
            :description,
            :amount,
            :dedupe_hash,
            :nominal_account_id,
            :is_internal_transfer,
            :category_status,
            :auto_rule_id
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => '2026-03-15',
            'txn_type' => $txnType,
            'description' => $description,
            'amount' => $txnType === 'P2P' ? '10.00' : '-12.34',
            'dedupe_hash' => $dedupeHash,
            'nominal_account_id' => $nominalAccountId,
            'is_internal_transfer' => $isInternalTransfer,
            'category_status' => 'auto',
            'auto_rule_id' => $ruleId,
        ]
    );

    return (int)\InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id AND dedupe_hash = :dedupe_hash LIMIT 1',
        ['company_id' => $companyId, 'dedupe_hash' => $dedupeHash]
    );
}
