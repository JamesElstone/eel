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
$harness->run(_director_loan_stateCard::class, static function (GeneratedServiceClassTestHarness $harness, _director_loan_stateCard $card): void {
    $harness->check(_director_loan_stateCard::class, 'uses the selected company and period for the subledger statement', static function () use ($harness, $card): void {
        $services = $card->services();
        $harness->assertCount(2, $services);
        $harness->assertSame(\eel_accounts\Service\DirectorLoanService::class, $services[0]['service'] ?? null);
        $harness->assertSame('fetchStatement', $services[0]['method'] ?? null);
        $harness->assertSame(':company.id', $services[0]['params']['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $services[0]['params']['accountingPeriodId'] ?? null);
        $harness->assertSame(\eel_accounts\Service\DirectorLoanReportingPresentationService::class, $services[1]['service'] ?? null);
        $harness->assertSame('fetchPresentation', $services[1]['method'] ?? null);
        $harness->assertSame(':company.id', $services[1]['params']['companyId'] ?? null);
        $harness->assertSame(':company.accounting_period_id', $services[1]['params']['accountingPeriodId'] ?? null);
    });

    $harness->check(_director_loan_stateCard::class, 'is the single attribution workspace and keeps the counterparty separate', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 47, 'accounting_period_id' => 74],
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'default_currency_symbol' => '£',
                    'asset_receivable' => 253.00,
                    'liability_payable' => 1288.63,
                    'desired_reclassification' => 253.00,
                    'net_position' => 1035.63,
                    'potential_s455_exposure' => 0.00,
                    'unattributed_count' => 0,
                    'invalid_director_count' => 0,
                    'directors' => [
                        ['id' => 9, 'full_name' => 'Primary Director', 'is_active' => 1],
                        ['id' => 10, 'full_name' => 'Former Director', 'is_active' => 0],
                    ],
                    'per_director' => [[
                        'director_id' => 9,
                        'director_name' => 'Primary Director',
                        'gross_asset' => 253.00,
                        'gross_liability' => 1288.63,
                        'desired_reclassification' => 253.00,
                        'net_closing_position' => 1035.63,
                        'net_position_label' => 'Company owes director',
                        'potential_s455_exposure' => 0.00,
                    ]],
                    'attribution_entries' => [[
                        'journal_line_id' => 123,
                        'journal_date' => '2025-06-30',
                        'description' => 'Funds advanced on the primary director account',
                        'counterparty_name' => 'External Counterparty',
                        'source_label' => 'Transaction #456',
                        'source_url' => '?page=transactions&show_card=transactions_imported&transaction_id=456',
                        'signed_amount' => -253.00,
                        'director_id' => 9,
                    ]],
                ],
                'directorLoanReportingPresentation' => [
                    'success' => true,
                    'classification' => 'within_one_year',
                    'classification_label' => 'Due within one year',
                    'explicit' => false,
                    'revision' => 0,
                    'schema_ready' => true,
                    'is_locked' => true,
                    'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
                ],
            ],
        ]);

        foreach ([
            'counterparty' => 'External Counterparty',
            'party selector' => 'Choose party',
            'attribution intent' => 'name="intent" value="set_participator_loan_attribution"',
            'reporting intent' => 'name="intent" value="save_director_loan_reporting_presentation"',
            'within-year choice' => 'name="classification" value="within_one_year" checked required',
            'after-year choice' => 'name="classification" value="after_more_than_one_year" required',
            'locked reporting badge' => 'Period locked - reporting choice is read only',
            'disabled reporting save' => '<button class="button primary" type="submit" disabled>Save reporting presentation</button>',
        ] as $contract => $needle) {
            if (!str_contains($html, $needle)) {
                throw new RuntimeException('Director Loan card is missing the ' . $contract . ' contract.');
            }
        }

        $harness->assertSame('Assign each posted Participator Loan control-account entry to the eligible party whose loan account it belongs to. Eligibility is checked on the transaction date.', $card->helper([]));
        $harness->assertTrue(str_contains($html, 'External Counterparty'));
        $harness->assertTrue(str_contains($html, 'https://www.gov.uk/hmrc-internal-manuals/employment-income-manual/eim26198'));
        $harness->assertTrue(str_contains($html, 'target="_blank" rel="noopener noreferrer"'));
        $harness->assertTrue(str_contains($html, 'Primary Director'));
        $harness->assertSame(false, str_contains($html, 'For example,'));
        $harness->assertTrue(str_contains($html, 'Choose party'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="set_participator_loan_attribution"'));
        $harness->assertTrue(str_contains($html, 'name="journal_line_id" value="123"'));
        $harness->assertTrue(str_contains($html, '<select class="input" name="party_id" required>'));
        $harness->assertSame(false, str_contains($html, '<button class="button button-inline" type="submit">Save</button>'));
        $harness->assertTrue(str_contains($html, 'Calculated reclassification'));
        $harness->assertTrue(str_contains($html, 'Gross loan asset (not s455)'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="DirectorLoan"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="save_director_loan_reporting_presentation"'));
        $harness->assertTrue(str_contains($html, 'name="classification" value="within_one_year" checked required'));
        $harness->assertTrue(str_contains($html, 'name="classification" value="after_more_than_one_year" required'));
        $harness->assertTrue(str_contains($html, 'Period locked - reporting choice is read only'));
        $harness->assertTrue(str_contains($html, 'does not alter journals, transactions, balances, nominal accounts, or the Year End lock'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit" disabled>Save reporting presentation</button>'));
        $harness->assertSame(false, str_contains($html, 'director_loan_state.php'));
    });

    $harness->check(_director_loan_stateCard::class, 'keeps unattributed entries selectable without the removed warning', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => ['id' => 47, 'accounting_period_id' => 74],
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'asset_receivable' => 10,
                    'liability_payable' => 0,
                    'desired_reclassification' => 0,
                    'net_position' => -10,
                    'potential_s455_exposure' => 10,
                    'unattributed_count' => 1,
                    'invalid_director_count' => 0,
                    'directors' => [['id' => 9, 'full_name' => 'Primary Director', 'is_active' => 1]],
                    'per_director' => [],
                    'attribution_entries' => [[
                        'journal_line_id' => 124,
                        'journal_date' => '2025-07-01',
                        'description' => 'Legacy entry',
                        'source_label' => 'Manual journal #7',
                        'signed_amount' => -10,
                        'director_id' => null,
                    ]],
                ],
                'directorLoanReportingPresentation' => [
                    'success' => true,
                    'classification' => 'after_more_than_one_year',
                    'classification_label' => 'Due after more than one year',
                    'explicit' => true,
                    'revision' => 1,
                    'schema_ready' => true,
                    'is_locked' => false,
                    'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
                ],
            ],
        ]);

        $harness->assertSame(false, str_contains($html, 'Every Director Loan entry must be attributed'));
        $harness->assertTrue(str_contains($html, 'Choose party'));
        $harness->assertTrue(str_contains($html, 'value="" disabled selected'));
        $harness->assertTrue(str_contains($html, 'href="?page=loans&amp;show_card=director_loan_attribution"'));
        $harness->assertTrue(str_contains($html, 'Review entries'));
        $harness->assertTrue(str_contains($html, 'name="classification" value="after_more_than_one_year" checked required'));
    });

    $harness->check(_director_loan_stateCard::class, 'paginates attribution entries at ten and exports the full table', static function () use ($harness, $card): void {
        $entries = [];
        for ($index = 1; $index <= 11; $index++) {
            $entries[] = [
                'journal_line_id' => 200 + $index,
                'journal_date' => '2025-07-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'description' => 'Attribution fixture ' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'counterparty_name' => 'Counterparty ' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'source_label' => 'Transaction #' . (500 + $index),
                'source_url' => '?page=transactions&transaction_id=' . (500 + $index),
                'signed_amount' => 0 - $index,
                'director_id' => 9,
            ];
        }

        $context = [
            'company' => ['id' => 47, 'accounting_period_id' => 74],
            'page' => [
                'page_id' => 'loans',
                'page_cards' => ['director_loan_state'],
                'csrf_token' => 'test-csrf-token',
                'director_loan_state_page' => 1,
            ],
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'default_currency_symbol' => '£',
                    'asset_receivable' => 66,
                    'liability_payable' => 0,
                    'desired_reclassification' => 0,
                    'net_position' => -66,
                    'potential_s455_exposure' => 66,
                    'unattributed_count' => 0,
                    'invalid_director_count' => 0,
                    'directors' => [
                        ['id' => 9, 'full_name' => 'Primary Director', 'is_active' => 1],
                    ],
                    'per_director' => [],
                    'attribution_entries' => $entries,
                ],
                'directorLoanReportingPresentation' => [
                    'success' => true,
                    'classification' => 'within_one_year',
                    'explicit' => false,
                    'schema_ready' => true,
                    'is_locked' => false,
                    'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
                ],
            ],
        ];

        $pageOneHtml = $card->render($context);
        $harness->assertTrue(str_contains($pageOneHtml, 'data-table-key="director_loan_attribution"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'data-table-pagination-field="director_loan_state_page"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'Director attribution 1-10 of 11'));
        $harness->assertTrue(str_contains($pageOneHtml, 'Attribution fixture 10'));
        $harness->assertSame(false, str_contains($pageOneHtml, 'Attribution fixture 11'));
        $harness->assertTrue(str_contains($pageOneHtml, 'name="director_loan_state_page" value="2"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'name="_table_export_prepare" value="xlsx"'));

        $pageTwoContext = $context;
        $pageTwoContext['page']['director_loan_state_page'] = 2;
        $pageTwoHtml = $card->render($pageTwoContext);
        $harness->assertTrue(str_contains($pageTwoHtml, 'Director attribution 11 of 11'));
        $harness->assertTrue(str_contains($pageTwoHtml, 'Attribution fixture 11'));
        $harness->assertSame(false, str_contains($pageTwoHtml, 'Attribution fixture 10'));

        $tables = $card->tables($context);
        $harness->assertCount(2, $tables);
        $harness->assertTrue($tables[1] instanceof TableFramework);
        $export = $tables[1]->exportCsv();
        $harness->assertTrue(str_contains($export, 'Attribution fixture 01'));
        $harness->assertTrue(str_contains($export, 'Attribution fixture 11'));
        $harness->assertTrue(str_contains($export, 'Primary Director'));
        $harness->assertTrue(str_contains($export, '-11.00'));
        $harness->assertSame(false, str_contains($export, '<form'));
        $harness->assertSame(false, str_contains($export, '<select'));
        $harness->assertSame(false, str_contains($export, 'set_director_loan_attribution'));
        $harness->assertSame(false, str_contains($export, 'journal_line_id'));
    });

    $harness->check(_director_loan_stateCard::class, 'paginates per-director positions at five and exports every position', static function () use ($harness, $card): void {
        $positions = [];
        for ($index = 1; $index <= 6; $index++) {
            $positions[] = [
                'director_id' => $index,
                'director_name' => 'Position director ' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
                'gross_asset' => 100 + $index,
                'gross_liability' => 200 + $index,
                'desired_reclassification' => 10 + $index,
                'net_closing_position' => 90 + $index,
                'net_position_label' => $index % 2 === 0 ? 'Company owes director' : 'Director owes company',
                'potential_s455_exposure' => 30 + $index,
            ];
        }

        $context = [
            'company' => ['id' => 47, 'accounting_period_id' => 74],
            'page' => [
                'page_id' => 'loans',
                'page_cards' => ['director_loan_state'],
                'csrf_token' => 'test-csrf-token',
                'director_loan_state_positions' => 1,
            ],
            'services' => [
                'directorLoanStatement' => [
                    'success' => true,
                    'default_currency_symbol' => '£',
                    'asset_receivable' => 621,
                    'liability_payable' => 1221,
                    'desired_reclassification' => 81,
                    'net_position' => 600,
                    'potential_s455_exposure' => 201,
                    'unattributed_count' => 0,
                    'invalid_director_count' => 0,
                    'directors' => [],
                    'per_director' => $positions,
                    'attribution_entries' => [],
                ],
                'directorLoanReportingPresentation' => [
                    'success' => true,
                    'classification' => 'within_one_year',
                    'explicit' => false,
                    'schema_ready' => true,
                    'is_locked' => false,
                    'liability_nominal' => ['id' => 5, 'code' => '2100', 'name' => 'Director Loan Liability'],
                ],
            ],
        ];

        $pageOneHtml = $card->render($context);
        $harness->assertTrue(str_contains($pageOneHtml, 'data-table-key="director_loan_positions"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'data-table-pagination-field="director_loan_state_positions"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'Per-director positions 1-5 of 6'));
        $harness->assertTrue(str_contains($pageOneHtml, 'Position director 05'));
        $harness->assertSame(false, str_contains($pageOneHtml, 'Position director 06'));
        $harness->assertTrue(str_contains($pageOneHtml, 'name="director_loan_state_positions" value="2"'));
        $harness->assertTrue(str_contains($pageOneHtml, 'name="table_key" value="director_loan_positions"'));

        $pageTwoContext = $context;
        $pageTwoContext['page']['director_loan_state_positions'] = 2;
        $pageTwoHtml = $card->render($pageTwoContext);
        $harness->assertTrue(str_contains($pageTwoHtml, 'Per-director positions 6 of 6'));
        $harness->assertTrue(str_contains($pageTwoHtml, 'Position director 06'));
        $harness->assertSame(false, str_contains($pageTwoHtml, 'Position director 05'));

        $tables = $card->tables($context);
        $harness->assertCount(2, $tables);
        $harness->assertTrue($tables[0] instanceof TableFramework);
        $export = $tables[0]->exportCsv();
        $harness->assertTrue(str_contains($export, 'Position director 01'));
        $harness->assertTrue(str_contains($export, 'Position director 06'));
        $harness->assertTrue(str_contains($export, '106.00'));
        $harness->assertTrue(str_contains($export, '206.00'));
        $harness->assertTrue(str_contains($export, '36.00'));
        $harness->assertSame(false, str_contains($export, '&pound;'));
        $harness->assertSame(false, str_contains($export, '<table'));
    });
});
