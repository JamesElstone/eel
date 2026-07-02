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

$harness->run(\eel_accounts\Service\ManualJournalService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ManualJournalService $service): void {
    $harness->check(\eel_accounts\Service\ManualJournalService::class, 'rejects unbalanced journal lines before saving', static function () use ($harness, $service): void {
        $result = $service->saveTaggedJournal(
            1,
            1,
            'opening_balance',
            'primary',
            '2025-01-01',
            'Opening balances',
            [
                [
                    'nominal_account_id' => 1,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'nominal_account_id' => 2,
                    'debit' => '0.00',
                    'credit' => '90.00',
                ],
            ]
        );

        $harness->assertSame(false, !empty($result['success']));
        $harness->assertTrue(in_array('Total debits must equal total credits before the journal can be saved.', (array)($result['errors'] ?? []), true));
    });
});
