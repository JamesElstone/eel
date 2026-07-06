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

        $selected = $selectLastTransaction->invoke($service, [
            [
                'id' => 102,
                'txn_date' => '2023-09-29',
                'description' => 'LAURA IRVINE',
                'amount' => '379.41',
                'balance' => '461.87',
            ],
            [
                'id' => 103,
                'txn_date' => '2023-09-29',
                'description' => 'R Howard',
                'amount' => '252.39',
                'balance' => '714.26',
            ],
        ]);

        $harness->assertSame('R Howard', (string)($selected['description'] ?? ''));
        $harness->assertSame('714.26', (string)($selected['balance'] ?? ''));
    });
});

function year_end_transaction_tail_private_method(\eel_accounts\Service\YearEndTransactionTailService $service, string $methodName): ReflectionMethod
{
    $method = (new ReflectionClass($service))->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}
