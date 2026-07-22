<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(_pl_source_coverageCard::class, static function (GeneratedServiceClassTestHarness $harness, _pl_source_coverageCard $card): void {
    $html = $card->render([
        'page' => [
            'page_cards' => ['pl_source_coverage'],
        ],
        'profit_loss' => [
            'source_coverage' => [
                'coverage_summary' => [
                    'reconciled' => false,
                    'covered_journal_count' => 1,
                    'posted_journal_count' => 2,
                    'uncovered_journal_count' => 1,
                    'evidence_failures' => [[
                        'journal_id' => 2118,
                        'source_type' => 'director_loan_offset',
                        'source_ref' => 'meta:director_loan_offset:79:primary',
                        'reason' => 'The journal evidence needs review.',
                    ]],
                ],
                'manual' => [
                    'label' => 'Manual',
                    'present' => true,
                    'journal_count' => 1,
                    'unverified_journal_count' => 1,
                    'debit_total' => 253,
                    'credit_total' => 253,
                ],
            ],
        ],
    ]);

    $harness->check(_pl_source_coverageCard::class, 'links unverified evidence journals to the journal search', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'href="?page=journal&amp;show_card=journals_list&amp;journals_list_keyword=2118"'));
        $harness->assertTrue(str_contains($html, '<a class="button button-inline"'));
        $harness->assertTrue(str_contains($html, '>#2118</a>'));
        $harness->assertTrue(str_contains($html, 'System-generated Director Loan journal'));
        $harness->assertSame(false, str_contains($html, '>Manual<'));
    });
});
