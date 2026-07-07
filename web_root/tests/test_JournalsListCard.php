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
$harness->run(_journals_listCard::class, static function (GeneratedServiceClassTestHarness $harness, _journals_listCard $card): void {
    $harness->check(_journals_listCard::class, 'declares journal entries as a card service', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertTrue(count($services) === 3);
        $harness->assertTrue(($services[0]['key'] ?? '') === 'journal_entries');
        $harness->assertTrue(($services[0]['service'] ?? '') === \eel_accounts\Service\TransactionJournalService::class);
        $harness->assertTrue(($services[0]['method'] ?? '') === 'fetchJournals');
        $harness->assertTrue(($services[0]['params']['companyId'] ?? '') === ':company.id');
        $harness->assertTrue(($services[0]['params']['accountingPeriodId'] ?? '') === ':company.accounting_period_id');
        $harness->assertTrue(($services[0]['params']['filters'] ?? '') === ':journals_list');
        $harness->assertTrue(($services[1]['key'] ?? '') === 'company_accounts');
        $harness->assertTrue(($services[1]['service'] ?? '') === \eel_accounts\Service\CompanyAccountService::class);
        $harness->assertTrue(($services[1]['method'] ?? '') === 'fetchAccounts');
        $harness->assertTrue(($services[2]['key'] ?? '') === 'nominal_accounts');
        $harness->assertTrue(($services[2]['service'] ?? '') === \eel_accounts\Repository\NominalAccountRepository::class);
        $harness->assertTrue(($services[2]['method'] ?? '') === 'fetchNominalAccounts');
    });

    $harness->check(_journals_listCard::class, 'renders journals from service context and links to the source transaction', static function () use ($harness, $card): void {
        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'page' => [
                'page_id' => 'journals',
                'page_cards' => ['journals_list'],
                'journal_entries' => [],
            ],
            'journals_list' => [
                'keyword' => 'Materials',
                'amount' => '123.45',
                'side' => 'dr',
                'source_account_id' => 7,
                'nominal_account_ids' => [5000, 1200],
            ],
            'services' => [
                'company_accounts' => [[
                    'id' => 7,
                    'account_name' => 'Current Account',
                    'institution_name' => 'Example Bank',
                    'account_identifier' => '1234',
                ]],
                'nominal_accounts' => [[
                    'id' => 5000,
                    'code' => '5000',
                    'name' => 'Purchases',
                ], [
                    'id' => 1200,
                    'code' => '1200',
                    'name' => 'Bank',
                ]],
                'journal_entries' => [
                    [
                        'journal_date' => '2026-02-14',
                        'description' => 'Materials purchase',
                        'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:55',
                        'is_posted' => 1,
                        'total_debit' => 123.45,
                        'lines' => [
                            [
                                'nominal_code' => '5000',
                                'nominal_name' => 'Purchases',
                                'debit' => 123.45,
                                'credit' => 0,
                            ],
                            [
                                'nominal_code' => '1200',
                                'nominal_name' => 'Bank',
                                'company_account_id' => 7,
                                'company_account_name' => 'Current Account',
                                'debit' => 0,
                                'credit' => 123.45,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'name="journals_list_keyword" value="Materials"'));
        $harness->assertTrue(str_contains($html, 'name="journals_list_amount" inputmode="decimal" value="123.45"'));
        $harness->assertTrue(str_contains($html, '<option value="dr" selected>DR</option>'));
        $harness->assertTrue(str_contains($html, '<option value="7" selected>Current Account / Example Bank / 1234</option>'));
        $harness->assertTrue(str_contains($html, 'name="journals_list_nominal_account_ids[]" multiple'));
        $harness->assertTrue(str_contains($html, '<option value="5000" selected>5000 - Purchases</option>'));
        $harness->assertTrue(str_contains($html, '<option value="1200" selected>1200 - Bank</option>'));
        $harness->assertTrue(str_contains($html, 'href="?page=journals&amp;show_card=journals_list"'));
        $harness->assertTrue(str_contains($html, 'Materials purchase'));
        $harness->assertTrue(str_contains($html, '<td>5000</td>'));
        $harness->assertTrue(str_contains($html, '<td>Purchases</td>'));
        $harness->assertTrue(str_contains($html, '<th>Code</th>'));
        $harness->assertTrue(str_contains($html, '<th>Label</th>'));
        $harness->assertTrue(str_contains($html, '<th>CR</th>'));
        $harness->assertTrue(str_contains($html, '<th>DR</th>'));
        $harness->assertTrue(str_contains($html, '<th>Status</th>'));
        $harness->assertTrue(str_contains($html, '<td>1200</td>'));
        $harness->assertTrue(str_contains($html, '<td>Bank</td>'));
        $harness->assertTrue(str_contains($html, 'Transaction #55'));
        $harness->assertTrue(str_contains($html, '<form method="post" action="?page=transactions" data-ajax="true"'));
        $harness->assertTrue(str_contains($html, '<input type="hidden" name="company_id" value="12">'));
        $harness->assertTrue(str_contains($html, '<input type="hidden" name="accounting_period_id" value="34">'));
        $harness->assertTrue(str_contains($html, '<input type="hidden" name="month_key" value="2026-02-01">'));
        $harness->assertTrue(!str_contains($html, '?page=transactions&amp;company_id=12'));
        $harness->assertTrue(!str_contains($html, 'Posted transaction journals will appear here'));

        $tables = $card->tables([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'page' => [
                'page_id' => 'journals',
                'page_cards' => ['journals_list'],
            ],
            'services' => [
                'journal_entries' => [
                    [
                        'journal_date' => '2026-02-14',
                        'description' => 'Materials purchase',
                        'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:55',
                        'is_posted' => 1,
                        'total_debit' => 123.45,
                        'lines' => [
                            [
                                'nominal_code' => '5000',
                                'nominal_name' => 'Purchases',
                                'company_account_id' => null,
                                'debit' => 123.45,
                                'credit' => 0,
                            ],
                            [
                                'nominal_code' => '1200',
                                'nominal_name' => 'Bank',
                                'company_account_id' => 7,
                                'company_account_name' => 'Current Account',
                                'debit' => 0,
                                'credit' => 123.45,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $harness->assertTrue(count($tables) === 1);
        $export = $tables[0]->exportCsv();
        $harness->assertTrue(str_contains($export, 'Date,Description,Source,Status,Total,Code,Label,CR,DR'));
        $harness->assertTrue(str_contains($export, '5000,Purchases,,123.45'));
        $harness->assertTrue(str_contains($export, '1200,Bank,123.45,'));
        $harness->assertTrue(str_contains($export, 'Posted'));
        $harness->assertTrue(!str_contains($export, 'Review Transaction'));
    });

    $harness->check(_journals_listCard::class, 'handle normalises journal search filters', static function () use ($harness, $card): void {
        $request = new RequestFramework(
            ['page' => 'journals'],
            [
                'journals_list_keyword' => ' Materials ',
                'journals_list_amount' => "-\xC2\xA31000",
                'journals_list_side' => 'cr',
                'journals_list_source_account_id' => '7',
                'journals_list_nominal_account_ids' => ['31', '32', '32', 'nope'],
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(APP_ROOT . 'uploads'));
        $handled = $card->handle($request, $services, [
            'page' => [
                'page_id' => 'journals',
                'page_cards' => ['journals_list'],
            ],
        ], ActionResultFramework::none());

        $harness->assertSame('Materials', (string)$handled['journals_list']['keyword']);
        $harness->assertSame('1000.00', (string)$handled['journals_list']['amount']);
        $harness->assertSame('cr', (string)$handled['journals_list']['side']);
        $harness->assertSame(7, (int)$handled['journals_list']['source_account_id']);
        $harness->assertSame([31, 32], $handled['journals_list']['nominal_account_ids']);
    });

    $harness->check(_journals_listCard::class, 'paginates journals at thirty rows', static function () use ($harness, $card): void {
        $journals = [];
        for ($index = 1; $index <= 31; $index++) {
            $journals[] = [
                'journal_date' => '2026-02-' . str_pad((string)(($index % 9) + 1), 2, '0', STR_PAD_LEFT),
                'description' => 'Journal ' . $index,
                'source_type' => 'bank_csv',
                'source_ref' => 'transaction:' . $index,
                'is_posted' => 1,
                'total_debit' => 10 + $index,
                'lines' => [
                    [
                        'nominal_code' => '5000',
                        'nominal_name' => 'Purchases',
                        'company_account_id' => null,
                        'debit' => 10 + $index,
                        'credit' => 0,
                    ],
                ],
            ];
        }

        $html = $card->render([
            'company' => [
                'id' => 12,
                'accounting_period_id' => 34,
            ],
            'page' => [
                'page_id' => 'journals',
                'page_cards' => ['journals_list'],
                'journals_list_page' => 1,
            ],
            'services' => [
                'journal_entries' => $journals,
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Journal 30'));
        $harness->assertTrue(!str_contains($html, 'Journal 31'));
        $harness->assertTrue(str_contains($html, 'Next &gt;'));
    });
});
