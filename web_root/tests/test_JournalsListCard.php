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

        $harness->assertTrue(count($services) === 1);
        $harness->assertTrue(($services[0]['key'] ?? '') === 'journal_entries');
        $harness->assertTrue(($services[0]['service'] ?? '') === \eel_accounts\Service\TransactionJournalService::class);
        $harness->assertTrue(($services[0]['method'] ?? '') === 'fetchJournals');
        $harness->assertTrue(($services[0]['params']['companyId'] ?? '') === ':company.id');
        $harness->assertTrue(($services[0]['params']['accountingPeriodId'] ?? '') === ':company.accounting_period_id');
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
