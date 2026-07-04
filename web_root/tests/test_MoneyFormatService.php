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
$harness->run(\eel_accounts\Service\MoneyFormatService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\MoneyFormatService $service): void {
    $harness->check(\eel_accounts\Service\MoneyFormatService::class, 'formats money with decoded company currency symbol', static function () use ($harness, $service): void {
        $harness->assertSame('£ 1,234.56', $service->format(['default_currency_symbol' => '&#163;'], 1234.555));
        $harness->assertSame('-£ 318.47', $service->format(['default_currency_symbol' => '&#163;'], -318.474));
        $harness->assertSame('£ 0.00', $service->format(['default_currency_symbol' => '&#163;'], -0.004));
        $harness->assertSame('$ 50.00', $service->format(['default_currency_symbol' => '&#36;'], 50));
        $harness->assertSame('€ 1,000.00', $service->format(['default_currency_symbol' => '&#8364;'], '1,000'));
    });

    $harness->check(\eel_accounts\Service\MoneyFormatService::class, 'returns fallback for missing or non-parsable values', static function () use ($harness, $service): void {
        $harness->assertSame('-', $service->format(['default_currency_symbol' => '&#163;'], null));
        $harness->assertSame('-', $service->format(['default_currency_symbol' => '&#163;'], ''));
        $harness->assertSame('-', $service->format(['default_currency_symbol' => '&#163;'], 'not money'));
        $harness->assertSame('n/a', $service->format(['default_currency_symbol' => '&#163;'], [], 'n/a'));
    });

    $harness->check(\eel_accounts\Service\MoneyFormatService::class, 'renders coloured HTML for valid money values', static function () use ($harness, $service): void {
        $harness->assertSame('<span class="amount-positive">£ 12.34</span>', $service->formatHtml(['default_currency_symbol' => '&#163;'], 12.34));
        $harness->assertSame('<span class="amount-negative">-£ 12.34</span>', $service->formatHtml(['default_currency_symbol' => '&#163;'], -12.34));
        $harness->assertSame('<span class="amount-zero">£ 0.00</span>', $service->formatHtml(['default_currency_symbol' => '&#163;'], 0));
        $harness->assertSame('-', $service->formatHtml(['default_currency_symbol' => '&#163;'], 'not money'));
    });
});
