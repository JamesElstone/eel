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
$harness->run(_tax_ct_period_factsCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_ct_period_factsCard $card): void {
    $harness->check(_tax_ct_period_factsCard::class, 'uses the application default without a separate CT-period confirmation', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 33, 'accounting_period_id' => 70],
            'services' => [
                'ct_period_facts' => [
                    'available' => true,
                    'periods' => [[
                        'ct_period_id' => 501,
                        'sequence_no' => 1,
                        'period_start' => '2025-10-01',
                        'period_end' => '2026-09-30',
                    ]],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'value="0"'));
        $harness->assertSame(true, str_contains($html, 'The application uses 0 until you change it.'));
        $harness->assertSame(true, str_contains($html, 'Close company — Cannot calculate'));
        $harness->assertSame(false, str_contains($html, 'name="close_company_status"'));
        $harness->assertSame(false, str_contains($html, 'I have reviewed the associated-company position for this CT period'));
        $harness->assertSame(false, str_contains($html, 'name="confirmed"'));
    });
});
