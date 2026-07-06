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

$harness->run(_year_end_audit_logCard::class, static function (
    GeneratedServiceClassTestHarness $harness,
    _year_end_audit_logCard $card
): void {
    $harness->check(_year_end_audit_logCard::class, 'renders user name column', static function () use ($harness, $card): void {
        $html = $card->render([
            'services' => [
                'year_end_audit_rows' => [
                    [
                        'action_at' => '2026-07-06 10:11:12',
                        'company_name' => 'Audit Fixture Limited',
                        'accounting_period_start' => '2025-01-01',
                        'accounting_period_end' => '2025-12-31',
                        'action' => 'notes',
                        'action_by' => 'Alex Example using the web_app',
                        'old_value_json' => '',
                        'new_value_json' => '{"review_notes":"Reviewed"}',
                        'notes' => '',
                    ],
                ],
            ],
            'page' => [
                'page_id' => 'year_end',
                'page_cards' => ['year_end_audit_log'],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, '<th>User Name</th>'));
        $harness->assertSame(true, str_contains($html, 'Alex Example using the web_app'));
    });
});
