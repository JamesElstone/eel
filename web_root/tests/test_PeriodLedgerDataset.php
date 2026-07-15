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

$harness->check(\eel_accounts\Service\PeriodLedgerDataset::class, 'preserves its scope rows and journal count', static function () use ($harness): void {
    $scope = new \eel_accounts\Service\PeriodLedgerScope(3, 4, '2025-01-01', '2025-12-31', '2025-09-30');
    $rows = [['nominal_account_id' => 17, 'total_debit' => '10.00', 'total_credit' => '0.00']];
    $dataset = new \eel_accounts\Service\PeriodLedgerDataset($scope, $rows, 2);

    $harness->assertTrue($dataset->scope === $scope);
    $harness->assertSame($rows, $dataset->rows);
    $harness->assertSame(2, $dataset->journalCount);
});
