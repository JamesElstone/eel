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
$harness->run(_asset_createCard::class, static function (GeneratedServiceClassTestHarness $harness, _asset_createCard $card): void {
    $harness->check(_asset_createCard::class, 'declares services for asset data and nominal options', static function () use ($harness, $card): void {
        $services = $card->services();
        $assetPageDataService = (array)($services[0] ?? []);
        $nominalAccountsService = (array)($services[1] ?? []);
        $params = (array)($assetPageDataService['params'] ?? []);

        $harness->assertSame('assetPageData', $assetPageDataService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\AssetService::class, $assetPageDataService['service'] ?? null);
        $harness->assertSame('fetchCreateData', $assetPageDataService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.default_bank_nominal_id', $params['defaultBankNominalId'] ?? null);
        $harness->assertSame(':prefill_transaction_id', $params['prefillTransactionId'] ?? null);
        $harness->assertSame(':prefill_transaction_split_line_id', $params['prefillTransactionSplitLineId'] ?? null);

        $harness->assertSame('nominal_accounts', $nominalAccountsService['key'] ?? null);
        $harness->assertSame(\eel_accounts\Repository\NominalAccountRepository::class, $nominalAccountsService['service'] ?? null);
        $harness->assertSame('fetchNominalAccounts', $nominalAccountsService['method'] ?? null);
    });

    $harness->check(_asset_createCard::class, 'renders manual reason and funding nominal options', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
            ],
            'services' => [
                'assetPageData' => [
                    'default_bank_nominal_id' => 42,
                ],
                'nominal_accounts' => [[
                    'id' => 42,
                    'code' => '1000',
                    'name' => 'Bank',
                    'account_type' => 'asset',
                    'subtype_code' => 'bank',
                ], [
                    'id' => 43,
                    'code' => '2300',
                    'name' => 'Trade Creditors',
                    'account_type' => 'liability',
                    'subtype_code' => 'trade_creditor',
                ], [
                    'id' => 44,
                    'code' => '2110',
                    'name' => 'Expense Claims Payable',
                    'account_type' => 'liability',
                    'subtype_code' => 'expense_payable',
                ], [
                    'id' => 45,
                    'code' => '1200',
                    'name' => 'Director Loan Asset',
                    'account_type' => 'asset',
                    'subtype_code' => 'director_loan_asset',
                ], [
                    'id' => 46,
                    'code' => '2100',
                    'name' => 'Director Loan Liability',
                    'account_type' => 'liability',
                    'subtype_code' => 'director_loan_liability',
                ], [
                    'id' => 47,
                    'code' => '2000',
                    'name' => 'VAT Control',
                    'account_type' => 'liability',
                    'subtype_code' => 'vat_control',
                ], [
                    'id' => 48,
                    'code' => '1300',
                    'name' => 'Tools & Equipment (FA)',
                    'account_type' => 'asset',
                    'subtype_code' => 'fixed_asset',
                ], [
                    'id' => 49,
                    'code' => '3000',
                    'name' => 'Capital Introduced',
                    'account_type' => 'equity',
                    'subtype_code' => '',
                ], [
                    'id' => 50,
                    'code' => '4000',
                    'name' => 'Sales',
                    'account_type' => 'income',
                    'subtype_code' => '',
                ], [
                    'id' => 51,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'cost_of_sales',
                    'subtype_code' => '',
                ], [
                    'id' => 52,
                    'code' => '7000',
                    'name' => 'General Expenses',
                    'account_type' => 'expense',
                    'subtype_code' => '',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="asset-create-form"'));
        $harness->assertTrue(str_contains($html, 'enctype="multipart/form-data"'));
        $harness->assertTrue(str_contains($html, 'class="asset-create-controls"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Asset"'));
        $harness->assertTrue(str_contains($html, 'name="global_action" value="create_manual_asset"'));
        $harness->assertTrue(str_contains($html, 'name="manual_addition_reason" required'));
        $harness->assertTrue(str_contains($html, 'name="manual_asset_evidence"'));
        $harness->assertTrue(str_contains($html, 'accept=".jpg,.jpeg,.pdf,image/jpeg,application/pdf"'));
        $harness->assertTrue(str_contains($html, 'name="manual_asset_legal_acknowledged" value="0"'));
        $harness->assertTrue(str_contains($html, 'data-manual-asset-legal-check="true"'));
        $harness->assertTrue(str_contains($html, 'Manual asset legal warning'));
        $harness->assertTrue(str_contains($html, 'Supplier invoice pending payment'));
        $harness->assertTrue(str_contains($html, 'Opening / historical asset'));
        $harness->assertTrue(str_contains($html, 'Funding / clearing nominal'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="asset_category" name="category" data-no-submit-on-change="true">'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="asset_method" name="depreciation_method" data-no-submit-on-change="true">'));
        $harness->assertTrue(str_contains($html, '<option value="tools_equipment">Tools &amp; Equipment</option>'));
        $harness->assertTrue(str_contains($html, '<option value="42" selected>'));
        $harness->assertTrue(str_contains($html, '<option value="43">'));
        $harness->assertTrue(str_contains($html, '<option value="44">'));
        $harness->assertTrue(str_contains($html, '<option value="45">'));
        $harness->assertTrue(str_contains($html, '<option value="46">'));
        $harness->assertSame(false, str_contains($html, '<option value="47">'));
        $harness->assertSame(false, str_contains($html, '<option value="48">'));
        $harness->assertSame(false, str_contains($html, '<option value="49">'));
        $harness->assertSame(false, str_contains($html, '<option value="50">'));
        $harness->assertSame(false, str_contains($html, '<option value="51">'));
        $harness->assertSame(false, str_contains($html, '<option value="52">'));
    });

    $harness->check(_asset_createCard::class, 'transaction-prefilled creation omits manual-only fields', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
            ],
            'services' => [
                'assetPageData' => [
                    'default_bank_nominal_id' => 42,
                    'prefill_transaction' => [
                        'transaction_id' => 91,
                        'description' => 'Imported drill purchase',
                        'purchase_date' => '2026-06-18',
                        'cost' => '240.00',
                    ],
                ],
                'nominal_accounts' => [[
                    'id' => 42,
                    'code' => '1000',
                    'name' => 'Bank',
                    'account_type' => 'asset',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="card_action" value="Asset"'));
        $harness->assertTrue(str_contains($html, 'name="global_action" value="create_asset_from_transaction"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_id" value="91"'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="asset_category" name="category" data-no-submit-on-change="true">'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="asset_method" name="depreciation_method" data-no-submit-on-change="true">'));
        $harness->assertSame(false, str_contains($html, 'name="manual_addition_reason"'));
        $harness->assertSame(false, str_contains($html, 'name="offset_nominal_id"'));
        $harness->assertSame(false, str_contains($html, 'name="manual_asset_evidence"'));
        $harness->assertSame(false, str_contains($html, 'data-manual-asset-legal-check="true"'));
        $harness->assertSame(false, str_contains($html, 'Funding / clearing nominal'));
    });

    $harness->check(_asset_createCard::class, 'split-line prefilled creation posts through split-line action', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
            ],
            'services' => [
                'assetPageData' => [
                    'default_bank_nominal_id' => 42,
                    'prefill_transaction' => [
                        'transaction_id' => 91,
                        'transaction_split_line_id' => 9001,
                        'description' => 'AMZNMKTPLACE tool item',
                        'purchase_date' => '2023-10-30',
                        'cost' => '89.99',
                    ],
                ],
                'nominal_accounts' => [],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="global_action" value="create_asset_from_transaction_split_line"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_id" value="91"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_split_line_id" value="9001"'));
        $harness->assertTrue(str_contains($html, 'value="AMZNMKTPLACE tool item"'));
        $harness->assertTrue(str_contains($html, 'name="cost" value="89.99"'));
        $harness->assertTrue(str_contains($html, '<select class="select" id="asset_category" name="category" data-no-submit-on-change="true">'));
        $harness->assertSame(false, str_contains($html, 'name="manual_addition_reason"'));
    });
});
