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

$harness->check(\eel_accounts\Service\PeriodLedgerScope::class, 'stores a valid bounded ledger scope and stable cache key', static function () use ($harness): void {
    $scope = new \eel_accounts\Service\PeriodLedgerScope(7, 11, '2025-01-01', '2025-12-31', '2025-06-30');

    $harness->assertSame(7, $scope->companyId);
    $harness->assertSame(11, $scope->accountingPeriodId);
    $harness->assertSame('2025-01-01', $scope->periodStart);
    $harness->assertSame('2025-12-31', $scope->accountingPeriodEnd);
    $harness->assertSame('2025-06-30', $scope->asAtDate);
    $harness->assertSame('7:11:2025-01-01:2025-06-30', $scope->cacheKey());
});

$harness->check(\eel_accounts\Service\PeriodLedgerScope::class, 'rejects missing identifiers invalid dates and out-of-period as-at dates', static function () use ($harness): void {
    $cases = [
        [0, 11, '2025-01-01', '2025-12-31', '2025-06-30'],
        [7, 11, '2025-02-30', '2025-12-31', '2025-06-30'],
        [7, 11, '2025-01-01', '2025-12-31', '2026-01-01'],
    ];

    foreach ($cases as $arguments) {
        $thrown = false;
        try {
            new \eel_accounts\Service\PeriodLedgerScope(...$arguments);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        $harness->assertTrue($thrown);
    }
});
