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

$harness->run(_hmrc_fines_tableCard::class, static function (GeneratedServiceClassTestHarness $harness, _hmrc_fines_tableCard $card): void {
    $rows = [];
    foreach (range(1, 16) as $index) {
        $rows[] = [
            'id' => $index,
            'accounting_period_id' => $index === 16 ? 80 : 79,
            'accounting_period_label' => $index === 16 ? 'AP80' : 'AP79',
            'obligation_type' => $index % 2 === 0 ? 'hmrc_interest' : 'hmrc_penalty',
            'notice_date' => '2024-09-' . str_pad((string)min($index, 28), 2, '0', STR_PAD_LEFT),
            'due_date' => '2024-10-31',
            'amount_due' => 10 + $index,
            'amount_paid' => 0,
            'effective_status' => 'not_started',
            'related_journal_id' => $index,
            'source_reference' => 'HMRC-' . $index,
        ];
    }
    $rows[] = [
        'accounting_period_id' => 79,
        'accounting_period_label' => 'AP79',
        'obligation_type' => 'ct600_filing',
        'source_reference' => 'EXCLUDED',
    ];

    $context = [
        'page' => ['page_id' => 'hmrc_obligations', 'page_cards' => ['hmrc_fines_table']],
        'company' => [
            'id' => 49,
            'accounting_period_id' => 79,
            'settings' => ['default_currency' => 'GBP', 'date_format' => 'd/m/Y'],
        ],
        'hmrc_obligations' => ['all_obligations' => $rows],
    ];

    $harness->check(_hmrc_fines_tableCard::class, 'renders the framework table with export, filter, and 15-row pagination', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertSame('HMRC penalties post to 6230 and HMRC interest posts to 6231, with the unpaid balance held in 2210 HMRC Penalties & Interest Payable. Later bank payments should clear 2210.', $card->helper($context));
        $harness->assertFalse(str_contains($html, 'HMRC penalties post to 6230'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, 'data-table-key="hmrc_fines_table"'));
        $harness->assertTrue(str_contains($html, 'HMRC notices 1-15 of 16'));
        $harness->assertTrue(str_contains($html, 'name="hmrc_fines_table_page" value="2"'));
        $harness->assertTrue(str_contains($html, '<option value="all" selected>All</option>'));
        $harness->assertTrue(str_contains($html, '<option value="current">In current Accounting Period</option>'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="hmrc_notice_type,hmrc_notice_date,hmrc_notice_due_date,hmrc_notice_amount_due,hmrc_notice_reference"'));
        $harness->assertTrue(str_contains($html, 'data-state-target="save_hmrc_notice_button"'));
        $harness->assertTrue(str_contains($html, 'id="save_hmrc_notice_button" type="submit" disabled'));
        $harness->assertTrue(str_contains($html, 'id="hmrc_notice_reference" name="source_reference" required'));
        $harness->assertTrue(str_contains($html, '<label for="hmrc_notice_type">Type *</label>'));
        $harness->assertTrue(str_contains($html, '<label for="hmrc_notice_reference">HMRC reference *</label>'));
        $harness->assertFalse(str_contains($html, '<label for="hmrc_notice_notes">Notes / evidence path *</label>'));
        $harness->assertTrue(strpos($html, '>Notice date</') < strpos($html, '>Period</'));
        $harness->assertTrue(strpos($html, 'HMRC-15') < strpos($html, 'HMRC-1<'));
        $harness->assertTrue(str_contains($html, '15/09/2024'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="delete_manual_obligation"'));
        $harness->assertTrue(str_contains($html, 'data-chicken-confirm-text="Delete"'));
    });

    $harness->check(_hmrc_fines_tableCard::class, 'filters both the screen table and export to the current accounting period', static function () use ($harness, $card, $context): void {
        $filteredContext = $context;
        $filteredContext['hmrc_fines_table']['period_scope'] = 'current';
        $html = $card->render($filteredContext);
        $tables = $card->tables($filteredContext);
        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($html, 'HMRC notices 1-15 of 15'));
        $harness->assertTrue(str_contains($html, '<option value="current" selected>In current Accounting Period</option>'));
        $harness->assertFalse(str_contains($csv, 'HMRC-16'));
        $harness->assertFalse(str_contains($csv, 'EXCLUDED'));
        $harness->assertTrue(str_contains($csv, 'HMRC-15'));
    });
});
