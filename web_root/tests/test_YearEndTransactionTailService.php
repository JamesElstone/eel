<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\YearEndTransactionTailService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndTransactionTailService $service): void {
    $harness->check(\eel_accounts\Service\YearEndTransactionTailService::class, 'uses same-day statement balance chain to select final transaction', static function () use ($harness, $service): void {
        $selectLastTransaction = year_end_transaction_tail_private_method($service, 'selectLastTransactionForDate');

        // Synthetic same-day chain; descriptions and balances are not copied from a bank statement.
        $selected = $selectLastTransaction->invoke($service, [
            [
                'id' => 102,
                'txn_date' => '2023-09-29',
                'description' => 'FIXTURE CUSTOMER ALPHA',
                'amount' => '125.00',
                'balance' => '425.00',
            ],
            [
                'id' => 103,
                'txn_date' => '2023-09-29',
                'description' => 'FIXTURE CUSTOMER BETA',
                'amount' => '175.00',
                'balance' => '600.00',
            ],
        ]);

        $harness->assertSame('FIXTURE CUSTOMER BETA', (string)($selected['description'] ?? ''));
        $harness->assertSame('600.00', (string)($selected['balance'] ?? ''));
    });
});

function year_end_transaction_tail_private_method(\eel_accounts\Service\YearEndTransactionTailService $service, string $methodName): ReflectionMethod
{
    $method = (new ReflectionClass($service))->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}
