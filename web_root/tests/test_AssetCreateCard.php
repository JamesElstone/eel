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
        $harness->assertSame('fetchPageData', $assetPageDataService['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
        $harness->assertSame(':company.settings.default_bank_nominal_id', $params['defaultBankNominalId'] ?? null);
        $harness->assertSame(':prefill_transaction_id', $params['prefillTransactionId'] ?? null);

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
                    'code' => '3000',
                    'name' => 'Capital Introduced',
                    'account_type' => 'equity',
                    'subtype_code' => '',
                ], [
                    'id' => 45,
                    'code' => '4000',
                    'name' => 'Sales',
                    'account_type' => 'income',
                    'subtype_code' => '',
                ], [
                    'id' => 46,
                    'code' => '5000',
                    'name' => 'Materials',
                    'account_type' => 'cost_of_sales',
                    'subtype_code' => '',
                ], [
                    'id' => 47,
                    'code' => '7000',
                    'name' => 'General Expenses',
                    'account_type' => 'expense',
                    'subtype_code' => '',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="asset-create-form"'));
        $harness->assertTrue(str_contains($html, 'class="asset-create-controls"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Asset"'));
        $harness->assertTrue(str_contains($html, 'name="global_action" value="create_manual_asset"'));
        $harness->assertTrue(str_contains($html, 'name="manual_addition_reason" required'));
        $harness->assertTrue(str_contains($html, 'Supplier invoice pending payment'));
        $harness->assertTrue(str_contains($html, 'Opening / historical asset'));
        $harness->assertTrue(str_contains($html, 'Funding / clearing nominal'));
        $harness->assertTrue(str_contains($html, '<option value="tools_equipment">Tools &amp; Equipment</option>'));
        $harness->assertTrue(str_contains($html, '<option value="42" selected>'));
        $harness->assertTrue(str_contains($html, '<option value="43">'));
        $harness->assertTrue(str_contains($html, '<option value="44">'));
        $harness->assertSame(false, str_contains($html, '<option value="45">'));
        $harness->assertSame(false, str_contains($html, '<option value="46">'));
        $harness->assertSame(false, str_contains($html, '<option value="47">'));
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
        $harness->assertSame(false, str_contains($html, 'name="manual_addition_reason"'));
        $harness->assertSame(false, str_contains($html, 'name="offset_nominal_id"'));
        $harness->assertSame(false, str_contains($html, 'Funding / clearing nominal'));
    });
});
