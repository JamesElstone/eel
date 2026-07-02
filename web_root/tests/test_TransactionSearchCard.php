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

$harness->run(_transaction_searchCard::class, static function (GeneratedServiceClassTestHarness $harness, _transaction_searchCard $card): void {
    $context = [
        'page' => [
            'page_id' => 'transactions',
            'page_cards' => ['transaction_search'],
        ],
        'company' => [
            'id' => 12,
            'accounting_period_id' => 34,
        ],
        'transaction_search' => [
            'keyword' => 'Brian',
            'amount' => '42.50',
            'flow' => 'out',
            'source_account_id' => 7,
            'nominal_account_ids' => [31, 32],
        ],
        'services' => [
            'company_accounts' => [[
                'id' => 7,
                'account_name' => 'Current Account',
                'institution_name' => 'Lloyds',
                'account_identifier' => '1234',
            ]],
            'nominal_accounts' => [[
                'id' => 31,
                'code' => '4000',
                'name' => 'Sales',
            ], [
                'id' => 32,
                'code' => '5000',
                'name' => 'Purchases',
            ]],
            'transaction_search_results' => [[
                'id' => 99,
                'statement_upload_id' => 15,
                'txn_date' => '2026-04-12',
                'month_key' => '2026-04-01',
                'txn_type' => 'card',
                'description' => 'Brian Supplies',
                'reference' => 'INV-22',
                'amount' => '42.50',
                'currency' => 'GBP',
                'balance' => '1000.00',
                'source_account_label' => 'Statement Current',
                'source_category' => 'materials',
                'document_download_status' => 'success',
                'owned_account_name' => 'Current Account',
                'owned_institution_name' => 'Lloyds',
                'transfer_account_name' => '',
                'nominal_code' => '5000',
                'assigned_nominal' => 'Purchases',
                'category_status' => 'auto',
                'auto_rule_id' => 5,
                'auto_rule_match_value' => 'Brian',
                'auto_rule_reference_match_value' => '',
                'is_auto_excluded' => 0,
                'has_derived_journal' => 1,
                'notes' => 'Checked',
                'created_at' => '2026-04-12 10:00:00',
                'updated_at' => '2026-04-12 11:00:00',
            ]],
        ],
    ];

    $harness->check(_transaction_searchCard::class, 'renders search controls and full audit result columns', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'name="transaction_search_keyword" value="Brian"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_search_amount" inputmode="decimal" value="-42.50"'));
        $harness->assertTrue(strpos($html, 'id="transaction_search_amount"') < strpos($html, 'id="transaction_search_flow"'));
        $harness->assertTrue(str_contains($html, 'name="transaction_search_flow"'));
        $harness->assertTrue(str_contains($html, '<option value="any">Any</option>'));
        $harness->assertTrue(str_contains($html, '<option value="in">In (positive)</option>'));
        $harness->assertTrue(str_contains($html, '<option value="out" selected>Out (negative)</option>'));
        $harness->assertTrue(str_contains($html, 'name="transaction_search_nominal_account_ids[]" multiple'));
        $harness->assertTrue(str_contains($html, 'name="_invalidate_fact" value="transaction.search"'));
        $harness->assertTrue(str_contains($html, '<option value="">Any</option>'));
        $harness->assertTrue(str_contains($html, '<option value="31" selected>4000 - Sales</option>'));
        $harness->assertTrue(str_contains($html, '<option value="32" selected>5000 - Purchases</option>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">ID</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Type</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Source</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">FX</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Currency</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Cat.</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Journal</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Upload</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Doc.</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Journal Status</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Upload ID</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Document Status</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Categorisation</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Source Category</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Transfer Account</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Auto Excluded</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Notes</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Created</span>'));
        $harness->assertSame(false, str_contains($html, '<span class="table-sort-label">Updated</span>'));
        $harness->assertTrue(str_contains($html, 'Brian Supplies'));
        $harness->assertTrue(str_contains($html, 'Rule #5 | Description: Brian'));
        $harness->assertTrue(str_contains($html, '?page=transactions&amp;show_card=transactions_imported&amp;month_key=2026-04-01&amp;category_filter=all'));
        $harness->assertTrue(str_contains($html, 'transaction-search-amount-total'));
        $harness->assertTrue(str_contains($html, 'Amount total:'));
    });

    $harness->check(_transaction_searchCard::class, 'shows page and query amount totals', static function () use ($harness, $card, $context): void {
        $totalContext = $context;
        $templateRow = $context['services']['transaction_search_results'][0];
        $totalContext['services']['transaction_search_results'] = [];

        for ($i = 1; $i <= 16; $i++) {
            $row = $templateRow;
            $row['id'] = $i;
            $row['amount'] = (string)$i;
            $totalContext['services']['transaction_search_results'][] = $row;
        }

        $html = $card->render($totalContext);

        $harness->assertTrue(str_contains($html, '<span>Page</span>'));
        $harness->assertTrue(str_contains($html, '<span>Query</span>'));
        $harness->assertTrue(str_contains($html, '<strong>' . FormattingFramework::money(120) . '</strong>'));
        $harness->assertTrue(str_contains($html, '<strong>' . FormattingFramework::money(136) . '</strong>'));
    });

    $harness->check(_transaction_searchCard::class, 'handle normalises selected source account and nominal ids', static function () use ($harness, $card, $context): void {
        $request = new RequestFramework(
            ['page' => 'transactions'],
            [
                'transaction_search_keyword' => ' Brian ',
                'transaction_search_amount' => "-\xC2\xA31000",
                'transaction_search_flow' => 'any',
                'transaction_search_source_account_id' => '7',
                'transaction_search_nominal_account_ids' => ['31', '32', '32', 'nope'],
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(APP_ROOT . 'uploads'));
        $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

        $harness->assertSame('Brian', (string)$handled['transaction_search']['keyword']);
        $harness->assertSame('-1000.00', (string)$handled['transaction_search']['amount']);
        $harness->assertSame('any', (string)$handled['transaction_search']['flow']);
        $harness->assertSame(7, (int)$handled['transaction_search']['source_account_id']);
        $harness->assertSame([31, 32], $handled['transaction_search']['nominal_account_ids']);
    });

    $harness->check(_transaction_searchCard::class, 'handle applies flow to amount sign and keeps flow-only criteria', static function () use ($harness, $card, $context): void {
        $cases = [
            ['100', 'in', '100.00'],
            ['100', 'out', '-100.00'],
            ['-100', 'in', '100.00'],
            ['-100', 'out', '-100.00'],
            ['', 'in', ''],
            ['', 'out', ''],
        ];

        foreach ($cases as [$amountInput, $flowInput, $expectedAmount]) {
            $request = new RequestFramework(
                ['page' => 'transactions'],
                [
                    'transaction_search_keyword' => '',
                    'transaction_search_amount' => $amountInput,
                    'transaction_search_flow' => $flowInput,
                    'transaction_search_source_account_id' => '0',
                    'transaction_search_nominal_account_ids' => [],
                ],
                ['REQUEST_METHOD' => 'POST'],
                [],
                []
            );
            $services = new PageServiceFramework(new AppService(APP_ROOT . 'uploads'));
            $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

            $harness->assertSame($expectedAmount, (string)$handled['transaction_search']['amount']);
            $harness->assertSame($flowInput, (string)$handled['transaction_search']['flow']);
        }
    });

    $harness->check(_transaction_searchCard::class, 'allows a blank keyword when another filter is selected', static function () use ($harness, $card, $context): void {
        $blankKeywordContext = $context;
        $blankKeywordContext['transaction_search']['keyword'] = '';
        $blankKeywordContext['transaction_search']['amount'] = '1000';
        $blankKeywordContext['transaction_search']['flow'] = 'any';
        $blankKeywordContext['transaction_search']['source_account_id'] = 0;
        $blankKeywordContext['transaction_search']['nominal_account_ids'] = [];
        $html = $card->render($blankKeywordContext);

        $harness->assertTrue(str_contains($html, 'name="transaction_search_keyword" value=""'));
        $harness->assertSame(false, str_contains($html, 'name="transaction_search_keyword" value="" required'));
        $harness->assertTrue(str_contains($html, 'name="transaction_search_amount" inputmode="decimal" value="1000.00"'));
    });

    $harness->check(_transaction_searchCard::class, 'allows flow as the only search criterion', static function () use ($harness, $card, $context): void {
        $flowContext = $context;
        $flowContext['transaction_search']['keyword'] = '';
        $flowContext['transaction_search']['amount'] = '';
        $flowContext['transaction_search']['flow'] = 'in';
        $flowContext['transaction_search']['source_account_id'] = 0;
        $flowContext['transaction_search']['nominal_account_ids'] = [];
        $flowContext['services']['transaction_search_results'] = [];
        $html = $card->render($flowContext);

        $harness->assertTrue(str_contains($html, '<option value="in" selected>In (positive)</option>'));
        $harness->assertSame(1, preg_match('/No transactions match this search \[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\./', $html));
    });

    $harness->check(_transaction_searchCard::class, 'timestamps the no match empty search message', static function () use ($harness, $card, $context): void {
        $emptyContext = $context;
        $emptyContext['services']['transaction_search_results'] = [];
        $html = $card->render($emptyContext);

        $harness->assertSame(
            1,
            preg_match('/No transactions match this search \[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]\./', $html)
        );
    });

    $harness->check(_transaction_searchCard::class, 'exports the full filtered result set', static function () use ($harness, $card, $context): void {
        $tables = $card->tables($context);
        $harness->assertTrue(isset($tables[0]) && $tables[0] instanceof TableFramework);

        $csv = $tables[0]->exportCsv();
        $harness->assertTrue(str_contains($csv, 'ID'));
        $harness->assertSame(false, str_contains($csv, 'Created'));
        $harness->assertSame(false, str_contains($csv, 'Updated'));
        $harness->assertTrue(str_contains($csv, 'Brian Supplies'));
        $harness->assertTrue(str_contains($csv, '2026-04-01'));
    });
});
