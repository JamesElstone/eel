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

$harness->run(_dividend_historyCard::class, static function (GeneratedServiceClassTestHarness $harness, _dividend_historyCard $card): void {
    $harness->check(_dividend_historyCard::class, 'renders dividend amounts with company currency symbol', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 7,
                'accounting_period_id' => 22,
                'settings' => [
                    'default_currency_symbol' => '&#36;',
                ],
            ],
            'dividends' => [
                'history' => [[
                    'journal_date' => '2026-06-30',
                    'description' => 'Interim dividend',
                    'amount' => 125.5,
                    'settlement_account' => 'Dividends payable',
                    'source_ref' => 'DIV-1',
                    'status' => 'posted',
                    'payment_link_status' => 'linked',
                    'payment_link_label' => 'Linked',
                    'payment_link_detail' => 'Matched to bank transaction',
                ]],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Interim dividend'));
        $harness->assertTrue(str_contains($html, '$ 125.50'));
        $harness->assertTrue(str_contains($html, 'Dividends payable'));
        $harness->assertTrue(str_contains($html, 'DIV-1'));
    });
});
