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
$harness->run(CompanySettingsService::class, static function (GeneratedServiceClassTestHarness $harness, CompanySettingsService $service): void {
    $harness->check(CompanySettingsService::class, 'suggests a default trade nominal from trade creditor liabilities', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank', 'account_type' => 'asset', 'subtype_code' => 'bank'],
            ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
            ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors', 'account_type' => 'liability', 'subtype_code' => 'trade_creditor'],
            ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
        ]);

        $harness->assertSame(15, (int)($suggestions['default_trade_nominal_id']['id'] ?? 0));
    });
});
