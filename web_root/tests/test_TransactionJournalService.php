<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(TransactionJournalService::class, static function (GeneratedServiceClassTestHarness $harness, TransactionJournalService $service): void {
    $buildDesiredJournal = new ReflectionMethod(TransactionJournalService::class, 'buildDesiredJournal');
    $buildDesiredJournal->setAccessible(true);

    $harness->check(TransactionJournalService::class, 'posts bank payment to a trade creditor nominal', static function () use ($harness, $service, $buildDesiredJournal): void {
        $journal = $buildDesiredJournal->invoke($service, [
            'id' => 501,
            'company_id' => 1,
            'accounting_period_id' => 2,
            'account_id' => 10,
            'transfer_account_id' => 20,
            'txn_date' => '2026-05-01',
            'description' => 'TLC payment',
            'amount' => -100.00,
            'category_status' => 'manual',
            'is_internal_transfer' => 1,
            'source_account_name' => 'ANNA Current',
            'source_account_type' => CompanyAccountService::TYPE_BANK,
            'source_account_nominal_id' => 1001,
            'transfer_account_name' => 'TLC Direct',
            'transfer_account_type' => CompanyAccountService::TYPE_TRADE,
            'transfer_account_nominal_id' => 2001,
        ], 0);

        $lines = $journal['lines'] ?? [];
        $harness->assertSame(2001, (int)$lines[0]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[0]['debit']);
        $harness->assertSame(1001, (int)$lines[1]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[1]['credit']);
    });

    $harness->check(TransactionJournalService::class, 'posts trade purchase on credit to creditor nominal', static function () use ($harness, $service, $buildDesiredJournal): void {
        $journal = $buildDesiredJournal->invoke($service, [
            'id' => 502,
            'company_id' => 1,
            'accounting_period_id' => 2,
            'account_id' => 20,
            'txn_date' => '2026-05-02',
            'description' => 'TLC materials',
            'amount' => 100.00,
            'nominal_account_id' => 5000,
            'category_status' => 'manual',
            'source_account_name' => 'TLC Direct',
            'source_account_type' => CompanyAccountService::TYPE_TRADE,
            'source_account_nominal_id' => 2001,
        ], 0);

        $lines = $journal['lines'] ?? [];
        $harness->assertSame(5000, (int)$lines[0]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[0]['debit']);
        $harness->assertSame(2001, (int)$lines[1]['nominal_account_id']);
        $harness->assertSame('100.00', (string)$lines[1]['credit']);
    });
});
