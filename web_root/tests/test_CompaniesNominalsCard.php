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

$harness->run(_companies_nominalsCard::class, static function (GeneratedServiceClassTestHarness $harness, _companies_nominalsCard $card): void {
    $context = [
        'company' => [
            'id' => 27,
            'settings' => [
                'default_bank_nominal_id' => '10',
                'default_trade_nominal_id' => '15',
            ],
        ],
        'services' => [
            'company_nominals' => [
                ['id' => 10, 'code' => '1200', 'name' => 'Bank', 'account_type' => 'asset', 'subtype_code' => 'bank'],
                ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
                ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors', 'account_type' => 'liability', 'subtype_code' => 'trade_creditor'],
                ['id' => 16, 'code' => '1300', 'name' => 'Tools and Equipment', 'account_type' => 'asset', 'subtype_code' => ''],
                ['id' => 17, 'code' => '1330', 'name' => 'Accumulated Depreciation - Tools', 'account_type' => 'asset', 'subtype_code' => ''],
                ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
            ],
        ],
    ];

    $html = $card->render($context);

    $harness->check(_companies_nominalsCard::class, 'renders default trade nominal field', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'default_trade_nominal_id'));
        $harness->assertTrue(str_contains($html, '<label for="default_trade_nominal_id">Default Trade nominal</label>'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="default_bank_nominal_id,default_trade_nominal_id,default_expense_nominal_id,director_loan_nominal_id,vat_nominal_id,uncategorised_nominal_id"'));
        $harness->assertTrue(str_contains($html, '<option value="15" selected>2300 Trade Creditors</option>'));
    });

    $harness->check(_companies_nominalsCard::class, 'suggests expense claims payable as the default expense nominal', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<strong>Expense claims payable nominal</strong><span>2110 Expense Claims Payable</span>'));
        $harness->assertFalse(str_contains($html, '<strong>Expense claims payable nominal</strong><span>5000 Materials</span>'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders shared asset nominal mappings', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'Asset Nominal Mappings'));
        $harness->assertTrue(str_contains($html, 'Tools &amp; Equipment'));
        $harness->assertTrue(str_contains($html, 'Ready: cost 1300 Tools and Equipment, accumulated depreciation 1330 Accumulated Depreciation - Tools'));
    });
});
