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
$harness->run(_transactions_monthly_statusCard::class, static function (GeneratedServiceClassTestHarness $harness, _transactions_monthly_statusCard $card): void {
    $harness->check(_transactions_monthly_statusCard::class, 'renders supplied period months with transaction and upload counts', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'services' => [
                'month_status' => [
                    [
                        'month' => 'Jan 2026',
                        'year' => '',
                        'month_key' => '2026-01-01',
                        'status' => 'red',
                        'transactions' => 0,
                        'uncategorised' => 0,
                        'deferred' => 0,
                        'ready_to_post' => 0,
                        'staged' => 3,
                        'raw_rows' => 4,
                    ],
                    [
                        'month' => 'Feb 2026',
                        'year' => '',
                        'month_key' => '2026-02-01',
                        'status' => 'amber',
                        'transactions' => 5,
                        'uncategorised' => 2,
                        'deferred' => 1,
                        'ready_to_post' => 4,
                        'staged' => 0,
                        'raw_rows' => 5,
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Jan 2026'));
        $harness->assertTrue(str_contains($html, 'Feb 2026'));
        $harness->assertTrue(str_contains($html, 'value="2026-01-01"'));
        $harness->assertTrue(str_contains($html, 'value="2026-02-01"'));
        $harness->assertTrue(str_contains($html, '<strong>0 transactions</strong>'));
        $harness->assertTrue(str_contains($html, '<strong>5 transactions</strong>'));
        $harness->assertTrue(str_contains($html, '2 uncategorised'));
        $harness->assertTrue(str_contains($html, '1 deferred'));
        $harness->assertTrue(str_contains($html, '4 unposted'));
        $harness->assertTrue(str_contains($html, '3 staged'));
        $harness->assertTrue(str_contains($html, '5 raw rows'));
    });
});
