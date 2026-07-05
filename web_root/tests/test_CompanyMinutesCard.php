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
$harness->run(_company_minutesCard::class, static function (GeneratedServiceClassTestHarness $harness, _company_minutesCard $card): void {
    $harness->check(_company_minutesCard::class, 'declares company minutes as a card service', static function () use ($harness, $card): void {
        $services = $card->services();

        $harness->assertTrue(count($services) === 1);
        $harness->assertSame('company_minutes', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\CompanyMinutesService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('listMinutes', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':company.accounting_period_id', (string)($services[0]['params']['accountingPeriodId'] ?? ''));
    });

    $harness->check(_company_minutesCard::class, 'renders minutes from service context using the table builder', static function () use ($harness, $card): void {
        $context = [
            'page' => [
                'page_id' => 'minutes',
                'page_cards' => ['company_minutes'],
            ],
            'services' => [
                'company_minutes' => [
                    [
                        'date' => '2026-06-30',
                        'minutes' => 'Director approved interim dividend.',
                    ],
                ],
            ],
        ];

        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Date</span>'));
        $harness->assertTrue(str_contains($html, '<span class="table-sort-label">Minutes</span>'));
        $harness->assertTrue(str_contains($html, '2026-06-30'));
        $harness->assertTrue(str_contains($html, 'Director approved interim dividend.'));
        $harness->assertTrue(!str_contains($html, '<th>Actions</th>'));

        $tables = $card->tables($context);
        $harness->assertTrue(count($tables) === 1);
        $export = $tables[0]->exportCsv();
        $harness->assertTrue(str_contains($export, 'Date,Minutes'));
        $harness->assertTrue(str_contains($export, '2026-06-30'));
    });
});
