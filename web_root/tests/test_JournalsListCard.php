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
                'journal_entries' => [],
            ],
            'services' => [
                'journal_entries' => [
                    [
                        'journal_date' => '2026-02-14',
                        'description' => 'Materials purchase',
                        'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:55',
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
                                'debit' => 0,
                                'credit' => 123.45,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'Materials purchase'));
        $harness->assertTrue(str_contains($html, '5000 - Purchases'));
        $harness->assertTrue(str_contains($html, 'Transaction #55'));
        $harness->assertTrue(str_contains($html, 'company_id=12'));
        $harness->assertTrue(str_contains($html, 'accounting_period_id=34'));
        $harness->assertTrue(str_contains($html, 'month_key=2026-02-01'));
        $harness->assertTrue(!str_contains($html, 'No journals exist yet'));
    });
});
