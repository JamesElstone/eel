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

$harness->run(_year_end_checklistCard::class, static function (GeneratedServiceClassTestHarness $harness, _year_end_checklistCard $card): void {
    $harness->check(_year_end_checklistCard::class, 'renders review acknowledgement and reopen actions', static function () use ($harness, $card): void {
        $html = $card->render([
            'year_end' => [
                'checklist' => [
                    'company_id' => 12,
                    'accounting_period' => ['id' => 34],
                    'overall_status' => 'in_progress',
                    'sections' => [
                        'year_end_accounts_review' => [
                            [
                                'check_code' => 'prepayments_accruals_placeholder',
                                'title' => 'Prepayments and accruals review',
                                'status' => 'warning',
                                'detail_text' => 'Manual review reminder.',
                                'metric_value' => '',
                                'action_url' => '?page=journal&company_id=12&accounting_period_id=34&show_card=nominal_closing_balances',
                                'review_clearable' => true,
                            ],
                            [
                                'check_code' => 'filing_basis_reminder',
                                'title' => 'Filing basis reminder',
                                'status' => 'pass',
                                'detail_text' => 'Review acknowledged for this period.',
                                'metric_value' => 'Reviewed',
                                'action_url' => '?page=year_end&company_id=12&accounting_period_id=34&show_card=year_end_tax_readiness',
                                'review_clearable' => true,
                                'review_acknowledgement' => [
                                    'acknowledged_at' => '2026-07-03 12:00:00',
                                    'acknowledged_by' => 'test',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertSame(true, str_contains($html, 'Open Related Workflow'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="acknowledge_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Mark reviewed'));
        $harness->assertSame(true, str_contains($html, 'name="intent" value="reopen_review_check"'));
        $harness->assertSame(true, str_contains($html, 'Reopen review'));
    });
});
