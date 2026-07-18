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

$harness->run(_expense_searchCard::class, static function (GeneratedServiceClassTestHarness $harness, _expense_searchCard $card): void {
    $context = expenseSearchCardContext();

    $harness->check(_expense_searchCard::class, 'uses expense search service and lookup services', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertSame('expense_search_results', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\ExpenseClaimService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('searchExpenseLines', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($services[0]['params']['accountingPeriodId'] ?? ''));
        $harness->assertSame(':expense_search', (string)($services[0]['params']['filters'] ?? ''));
        $harness->assertSame('expense_search_claimants', (string)($services[1]['key'] ?? ''));
        $harness->assertSame('fetchClaimants', (string)($services[1]['method'] ?? ''));
        $harness->assertSame('expense_search_nominals', (string)($services[2]['key'] ?? ''));
        $harness->assertSame('fetchExpenseNominals', (string)($services[2]['method'] ?? ''));
    });

    $harness->check(_expense_searchCard::class, 'renders search controls and line results', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, 'name="expense_search_keyword" value="materials"'));
        $harness->assertTrue(str_contains($html, 'name="expense_search_amount" inputmode="decimal" value="42.50"'));
        $harness->assertTrue(str_contains($html, '<option value="">Any</option><option value="3" selected>Alex Example</option><option value="4">Bob</option>'));
        $harness->assertTrue(str_contains($html, 'name="expense_search_period" placeholder="MM/YYYY" value="05/2026"'));
        $harness->assertTrue(str_contains($html, 'name="expense_search_statuses[]" multiple size="2"'));
        $harness->assertTrue(str_contains($html, '<option value="draft" selected>Draft</option>'));
        $harness->assertTrue(str_contains($html, '<option value="posted">Posted</option>'));
        $harness->assertSame(false, str_contains($html, 'Repayment Only'));
        $harness->assertTrue(str_contains($html, 'name="expense_search_nominal_account_ids[]" multiple size="6"'));
        $harness->assertTrue(str_contains($html, '<option value="31" selected>5000 - Materials</option>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Reference</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Charge To</span>'));
        $harness->assertTrue(str_contains($html, 'Electric materials'));
        $harness->assertTrue(str_contains($html, 'receipt note'));
        $harness->assertTrue(str_contains($html, '$ 42.50'));
        $harness->assertTrue(str_contains($html, 'expense-search-amount-total'));
        $harness->assertTrue(str_contains($html, '<button class="button button-inline" type="submit" data-show-card="expense_claim_editor">Open</button>'));
    });

    $harness->check(_expense_searchCard::class, 'handle normalises filters and company date period order', static function () use ($harness, $card, $context): void {
        $request = new RequestFramework(
            ['page' => 'expense_claims'],
            [
                'expense_search_keyword' => ' cable ',
                'expense_search_amount' => "\xC2\xA312.3",
                'expense_search_claimant_id' => '4',
                'expense_search_period' => '06/2026',
                'expense_search_statuses' => ['posted', 'draft', 'repayment_only', 'posted'],
                'expense_search_nominal_account_ids' => ['31', '32', '32', 'bad'],
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(test_tmp_directory()));
        $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

        $harness->assertSame('cable', (string)$handled['expense_search']['keyword']);
        $harness->assertSame('12.30', (string)$handled['expense_search']['amount']);
        $harness->assertSame(4, (int)$handled['expense_search']['claimant_id']);
        $harness->assertSame('06/2026', (string)$handled['expense_search']['period']);
        $harness->assertSame(2026, (int)$handled['expense_search']['claim_year']);
        $harness->assertSame(6, (int)$handled['expense_search']['claim_month']);
        $harness->assertSame(['posted', 'draft'], $handled['expense_search']['statuses']);
        $harness->assertSame([31, 32], $handled['expense_search']['nominal_account_ids']);

        $yearFirstContext = $context;
        $yearFirstContext['expense_page_settings']['date_format'] = 'Y-m-d';
        $yearFirstRequest = new RequestFramework(
            ['page' => 'expense_claims'],
            ['expense_search_period' => '2026-07'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $yearFirstHandled = $card->handle($yearFirstRequest, $services, $yearFirstContext, ActionResultFramework::none());
        $harness->assertSame('2026-07', (string)$yearFirstHandled['expense_search']['period']);
        $harness->assertSame(2026, (int)$yearFirstHandled['expense_search']['claim_year']);
        $harness->assertSame(7, (int)$yearFirstHandled['expense_search']['claim_month']);
    });

    $harness->check(_expense_searchCard::class, 'shows page and query amount totals and exports filtered rows', static function () use ($harness, $card, $context): void {
        $totalContext = $context;
        $templateRow = $context['services']['expense_search_results'][0];
        $totalContext['services']['expense_search_results'] = [];

        for ($i = 1; $i <= 16; $i++) {
            $row = $templateRow;
            $row['id'] = $i;
            $row['amount'] = (float)$i;
            $row['description'] = 'Line ' . $i;
            $totalContext['services']['expense_search_results'][] = $row;
        }

        $html = $card->render($totalContext);

        $harness->assertTrue(str_contains($html, '<span>Page</span>'));
        $harness->assertTrue(str_contains($html, '<span>Query</span>'));
        $harness->assertTrue(str_contains($html, '<strong>$ 120.00</strong>'));
        $harness->assertTrue(str_contains($html, '<strong>$ 136.00</strong>'));

        $tables = $card->tables($totalContext);
        $harness->assertTrue(isset($tables[0]) && $tables[0] instanceof TableFramework);
        $csv = $tables[0]->exportCsv();
        $harness->assertTrue(str_starts_with($csv, 'Reference,Claimant,Period,Date,Description,Notes,Amount,"Charge To",Status,Updated'));
        $harness->assertTrue(str_contains($csv, 'Line 16'));
        $harness->assertSame(false, str_contains($csv, 'Open'));
    });
});

function expenseSearchCardContext(): array
{
    return [
        'page' => [
            'page_id' => 'expense_claims',
            'page_cards' => ['expense_search'],
        ],
        'company' => [
            'id' => 7,
            'accounting_period_id' => 102,
            'settings' => [
                'date_format' => 'd/m/Y',
                'default_currency_symbol' => '&#36;',
            ],
        ],
        'expense_page_settings' => [
            'date_format' => 'd/m/Y',
        ],
        'expense_search' => [
            'keyword' => 'materials',
            'amount' => '42.50',
            'claimant_id' => 3,
            'period' => '05/2026',
            'claim_year' => 2026,
            'claim_month' => 5,
            'statuses' => ['draft'],
            'nominal_account_ids' => [31],
        ],
        'services' => [
            'expense_search_claimants' => [
                ['id' => 3, 'claimant_name' => 'Alex Example', 'is_active' => 1],
                ['id' => 4, 'claimant_name' => 'Bob', 'is_active' => 1],
            ],
            'expense_search_nominals' => [
                ['id' => 31, 'code' => '5000', 'name' => 'Materials'],
                ['id' => 32, 'code' => '6000', 'name' => 'Travel'],
            ],
            'expense_search_results' => [
                [
                    'id' => 99,
                    'expense_claim_id' => 55,
                    'claim_reference_code' => 'EXP-2605-001',
                    'claimant_id' => 3,
                    'claimant_name' => 'Alex Example',
                    'claim_year' => 2026,
                    'claim_month' => 5,
                    'claim_period' => '2026-05',
                    'expense_date' => '2026-05-12',
                    'line_number' => 1,
                    'description' => 'Electric materials',
                    'notes' => 'receipt note',
                    'amount' => 42.50,
                    'nominal_account_id' => 31,
                    'nominal_code' => '5000',
                    'nominal_name' => 'Materials',
                    'status' => 'draft',
                    'updated_at' => '2026-05-12 10:00:00',
                ],
            ],
        ],
    ];
}
