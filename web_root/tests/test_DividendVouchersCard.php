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

$harness->run(_dividend_vouchersCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_vouchersCard $card): void {
    $harness->check(_dividend_vouchersCard::class, 'declares focused voucher list service', static function () use ($harness, $card): void {
        $service = (array)($card->services()[0] ?? []);
        $params = (array)($service['params'] ?? []);

        $harness->assertSame('dividendVouchers', $service['key'] ?? null);
        $harness->assertSame(\eel_accounts\Service\DividendService::class, $service['service'] ?? null);
        $harness->assertSame('listDividendVouchers', $service['method'] ?? null);
        $harness->assertSame(':company.id', $params['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $params['accountingPeriodId'] ?? null);
    });

    $harness->check(_dividend_vouchersCard::class, 'renders rows returned directly by the focused service', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['settings' => []],
            'services' => ['dividendVouchers' => [[
                'declaration_date' => '2026-07-01',
                'shareholder_name' => 'Focused Reader',
                'amount' => 25,
                'source_ref' => 'DIRECT-1',
            ]]],
        ]);
        $harness->assertTrue(str_contains($html, 'Focused Reader'));
        $harness->assertTrue(str_contains($html, 'DIRECT-1'));
    });

    $harness->check(_dividend_vouchersCard::class, 'renders voucher amounts with company currency symbol', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'dividends' => [
                'vouchers' => [[
                    'declaration_date' => '2026-06-30',
                    'shareholder_name' => 'Alex Example',
                    'company_name' => 'Example Company Limited',
                    'amount' => 125.5,
                    'voucher_text' => 'Voucher text.',
                    'minutes_text' => 'Minutes text.',
                    'issued_at' => '2026-06-30 12:00:00',
                    'source_ref' => 'DIV-1',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Alex Example'));
        $harness->assertTrue(str_contains($html, '$ 125.50'));
        $harness->assertTrue(str_contains($html, 'Voucher text.'));
        $harness->assertTrue(str_contains($html, 'DIV-1'));
    });
});
