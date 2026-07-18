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
                'default_sales_nominal_id' => '23',
                'default_trade_nominal_id' => '15',
            ],
        ],
        'services' => [
            'company_nominals' => [
                ['id' => 10, 'code' => '1000', 'name' => 'Bank', 'account_type' => 'asset', 'subtype_code' => 'bank'],
                ['id' => 23, 'code' => '4000', 'name' => 'Sales', 'account_type' => 'income', 'subtype_code' => 'turnover'],
                ['id' => 12, 'code' => '1200', 'name' => 'Director Loan Asset', 'account_type' => 'asset', 'subtype_code' => 'director_loan_asset'],
                ['id' => 13, 'code' => '2100', 'name' => 'Director Loan Liability', 'account_type' => 'liability', 'subtype_code' => 'director_loan_liability'],
                ['id' => 14, 'code' => '2110', 'name' => 'Expense Claims Payable', 'account_type' => 'liability', 'subtype_code' => 'expense_payable'],
                ['id' => 15, 'code' => '2300', 'name' => 'Trade Creditors', 'account_type' => 'liability', 'subtype_code' => 'trade_creditor'],
                ['id' => 16, 'code' => '1300', 'name' => 'Tools and Equipment', 'account_type' => 'asset', 'subtype_code' => ''],
                ['id' => 17, 'code' => '1330', 'name' => 'Accumulated Depreciation - Tools', 'account_type' => 'asset', 'subtype_code' => ''],
                ['id' => 18, 'code' => '6070', 'name' => 'Tools & Small Equipment', 'account_type' => 'expense', 'subtype_code' => 'overhead'],
                ['id' => 19, 'code' => '1150', 'name' => 'Prepayments', 'account_type' => 'asset', 'subtype_code' => 'prepayments'],
                ['id' => 20, 'code' => '5000', 'name' => 'Materials', 'account_type' => 'expense', 'subtype_code' => ''],
                ['id' => 21, 'code' => '8500', 'name' => 'Tax charge renamed safely', 'account_type' => 'expense', 'subtype_code' => 'corp_tax_expense'],
                ['id' => 22, 'code' => '2200', 'name' => 'Tax creditor renamed safely', 'account_type' => 'liability', 'subtype_code' => 'corp_tax'],
            ],
        ],
    ];

    $html = $card->render($context);

    $harness->check(_companies_nominalsCard::class, 'renders default trade nominal field', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'default_trade_nominal_id'));
        $harness->assertTrue(str_contains($html, '<label for="default_sales_nominal_id">Default Sales nominal</label>'));
        $harness->assertTrue(str_contains($html, '<label for="default_trade_nominal_id">Default Trade nominal</label>'));
        preg_match('/data-state-fields="([^"]+)"/', $html, $stateFieldMatch);
        $stateFields = explode(',', (string)($stateFieldMatch[1] ?? ''));
        foreach ([
            'default_bank_nominal_id',
            'default_sales_nominal_id',
            'default_trade_nominal_id',
            'default_expense_nominal_id',
            'tools_small_equipment_nominal_id',
            'prepayment_asset_nominal_id',
            'director_loan_asset_nominal_id',
            'director_loan_liability_nominal_id',
            'vat_nominal_id',
            'uncategorised_nominal_id',
            'corporation_tax_expense_nominal_id',
            'corporation_tax_liability_nominal_id',
            ...(new \eel_accounts\Service\CompanySettingsService())->helperNominalSettingKeys(),
        ] as $stateField) {
            $harness->assertTrue(in_array($stateField, $stateFields, true));
        }
        $harness->assertTrue(str_contains($html, '<option value="15" selected>2300 Trade Creditors</option>'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders and suggests explicit Corporation Tax nominal mappings', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'name="corporation_tax_expense_nominal_id"'));
        $harness->assertTrue(str_contains($html, 'name="corporation_tax_liability_nominal_id"'));
        $harness->assertTrue(str_contains($html, '<strong>Corporation Tax expense nominal</strong><span>8500 Tax charge renamed safely</span>'));
        $harness->assertTrue(str_contains($html, '<strong>Corporation Tax liability nominal</strong><span>2200 Tax creditor renamed safely</span>'));
    });

    $harness->check(_companies_nominalsCard::class, 'suggests expense claims payable as the default expense nominal', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<strong>Expense claims payable nominal</strong><span>2110 Expense Claims Payable</span>'));
        $harness->assertFalse(str_contains($html, '<strong>Expense claims payable nominal</strong><span>5000 Materials</span>'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders and suggests Tools and Small Equipment nominal', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<label for="tools_small_equipment_nominal_id">Tools &amp; Small Equipment nominal</label>'));
        $harness->assertTrue(str_contains($html, '<strong>Tools &amp; Small Equipment nominal</strong><span>6070 Tools &amp; Small Equipment</span>'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders and suggests the Prepayments current-asset nominal', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<label for="prepayment_asset_nominal_id">Prepayments asset nominal</label>'));
        $harness->assertTrue(str_contains($html, '<strong>Prepayments asset nominal</strong><span>1150 Prepayments</span>'));
        preg_match('/<select[^>]+id="prepayment_asset_nominal_id"[^>]*>(.*?)<\/select>/s', $html, $matches);
        $options = (string)($matches[1] ?? '');
        $harness->assertTrue(str_contains($options, '<option value="19">1150 Prepayments</option>'));
        $harness->assertFalse(str_contains($options, 'value="16"'));
        $harness->assertFalse(str_contains($options, 'value="17"'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders and suggests director loan asset and liability fields', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<label for="director_loan_asset_nominal_id">Director Loan Asset nominal</label>'));
        $harness->assertTrue(str_contains($html, '<label for="director_loan_liability_nominal_id">Director Loan Liability nominal</label>'));
        $harness->assertFalse(str_contains($html, 'name="director_loan_nominal_id"'));
        $harness->assertTrue(str_contains($html, '<strong>Director Loan Asset nominal</strong><span>1200 Director Loan Asset</span>'));
        $harness->assertTrue(str_contains($html, '<strong>Director Loan Liability nominal</strong><span>2100 Director Loan Liability</span>'));
    });

    $harness->check(_companies_nominalsCard::class, 'renders shared asset nominal mappings', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'name="tools_equipment_asset_cost_nominal_id"'));
        $harness->assertTrue(str_contains($html, 'name="tools_equipment_accum_dep_nominal_id"'));
        $harness->assertTrue(str_contains($html, '<strong>Tools &amp; Equipment cost nominal</strong><span>1300 Tools and Equipment</span>'));
        $harness->assertTrue(str_contains($html, '<strong>Tools &amp; Equipment accumulated depreciation nominal</strong><span>1330 Accumulated Depreciation - Tools</span>'));
    });
});
