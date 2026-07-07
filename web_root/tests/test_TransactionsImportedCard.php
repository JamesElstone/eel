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

$harness->run(_transactions_importedCard::class, static function (GeneratedServiceClassTestHarness $harness, _transactions_importedCard $card): void {
    $html = $card->render([
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
            'settings' => [],
        ],
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transactions_imported'],
            'month_key' => '2026-01',
            'category_filter' => 'not_posted',
            'csrf_token' => 'test-csrf',
        ],
        'services' => [
            'month_status' => [
                [
                    'month_key' => '2026-01',
                    'label' => 'Jan 2026',
                ],
            ],
            'transactions_by_month' => [],
            'nominal_accounts' => [],
            'company_accounts' => [],
            'year_end_review' => [
                'is_locked' => false,
            ],
            'pending_auto_approval_count' => 0,
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'data-auto-approval-batch-form="true"'));
    $harness->assertTrue(str_contains($html, 'data-transactions-imported-post-form="true"'));
    $harness->assertTrue(str_contains($html, 'data-initial-pending-auto-approval-count="0"'));
    $harness->assertTrue(str_contains($html, 'name="confirm_auto_categorisations" value="0"'));
    $harness->assertTrue(str_contains($html, 'name="card_action" value="Transaction"'));
    $harness->assertTrue(str_contains($html, 'name="global_action" value="sync_auto_approval_state"'));
    $harness->assertTrue(str_contains($html, 'name="csrf_token"'));
});
