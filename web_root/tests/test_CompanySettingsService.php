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
$harness->run(\eel_accounts\Service\CompanySettingsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompanySettingsService $service): void {
    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'strips whitespace from pasted HMRC UTR values', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'normaliseUtr');
        $method->setAccessible(true);

        $harness->assertSame('2794616478', $method->invoke($service, '27946 16478'));
        $harness->assertSame('2794616478', $method->invoke($service, " 27946\t16478\n"));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'suggests a default trade nominal from trade creditor liabilities', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 10, 'code' => '1200', 'name' => 'Bank', 'account_type' => 'asset', 'subtype_code' => 'bank'],
            ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
            ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors', 'account_type' => 'liability', 'subtype_code' => 'trade_creditor'],
            ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
        ]);

        $harness->assertSame(15, (int)($suggestions['default_trade_nominal_id']['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'suggests expense claims payable as the default expense nominal', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
            ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
        ]);

        $harness->assertSame(14, (int)($suggestions['default_expense_nominal_id']['id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\CompanySettingsService::class, 'prefers director loan liability over director loan asset', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\CompanySettingsService::class, 'buildNominalDefaultSuggestions');
        $method->setAccessible(true);

        $suggestions = $method->invoke($service, [
            ['id' => 3, 'code' => '1200', 'name' => 'Director Loan Asset', 'account_type' => 'asset', 'subtype_code' => 'director_loan_asset'],
            ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability', 'account_type' => 'liability', 'subtype_code' => 'director_loan_liability'],
        ]);

        $harness->assertSame(5, (int)($suggestions['director_loan_nominal_id']['id'] ?? 0));
    });
});
