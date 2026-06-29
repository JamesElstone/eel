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

$harness->run(_tax_ratesCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_ratesCard $card): void {
    $context = [
        'page' => [
            'page_id' => 'tax_rates',
            'page_cards' => ['tax_rates'],
        ],
        'tax_rates' => [
            'rules' => tax_rates_card_test_rules(),
        ],
    ];

    $harness->check(_tax_ratesCard::class, 'defaults to active rules and paginates to five rows', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, '<option value="active" selected>Active</option>'));
        $harness->assertTrue(str_contains($html, '<option value="all">All</option>'));
        $harness->assertTrue(str_contains($html, 'Corporation Tax rate rules 1-5 of 6'));
        $harness->assertTrue(str_contains($html, 'active-version-5'));
        $harness->assertTrue(str_contains($html, '(GBP) £50,000.00'));
        $harness->assertTrue(str_contains($html, '(GBP) £250,000.00'));
        $harness->assertSame(false, str_contains($html, 'active-version-6'));
        $harness->assertSame(false, str_contains($html, 'inactive-version'));
        $harness->assertTrue(str_contains($html, 'name="tax_rates_status" value="active"'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Refresh HMRC Rates</button>'));
        $harness->assertSame(false, str_contains($html, 'Import Live HMRC Rates'));
    });

    $harness->check(_tax_ratesCard::class, 'empty table prompts live HMRC import', static function () use ($harness, $card, $context): void {
        $emptyContext = $context;
        $emptyContext['tax_rates']['rules'] = [];
        $html = $card->render($emptyContext);

        $harness->assertTrue(str_contains($html, 'No active Corporation Tax rate rules are stored yet.'));
        $harness->assertTrue(str_contains($html, '<button class="button danger" type="submit">Import Live HMRC Rates</button>'));
        $harness->assertSame(false, str_contains($html, 'Refresh HMRC Rates'));
    });

    $harness->check(_tax_ratesCard::class, 'all filter includes superseded rows and exports filtered rows', static function () use ($harness, $card, $context): void {
        $allContext = $context;
        $allContext['tax_rates']['status_filter'] = 'all';
        $html = $card->render($allContext);
        $tables = $card->tables($allContext);
        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($html, '<option value="all" selected>All</option>'));
        $harness->assertTrue(str_contains($html, 'Corporation Tax rate rules 1-5 of 7'));
        $harness->assertTrue(str_contains($html, 'inactive-version'));
        $harness->assertTrue(str_contains($html, '<span class="badge info">Superseded</span>'));
        $harness->assertTrue(str_contains($csv, 'inactive-version'));
    });

    $harness->check(_tax_ratesCard::class, 'handle stores normalised filter input', static function () use ($harness, $card, $context): void {
        $request = new RequestFramework(
            ['page' => 'tax_rates'],
            ['tax_rates_status' => 'all'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(APP_ROOT . 'uploads'));
        $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

        $harness->assertSame('all', (string)$handled['tax_rates']['status_filter']);
    });
});

function tax_rates_card_test_rules(): array
{
    $rules = [[
        'financial_year_start' => '2020-04-01',
        'financial_year_end' => '2021-03-31',
        'main_rate' => '0.190000',
        'small_profits_rate' => null,
        'lower_limit' => null,
        'upper_limit' => null,
        'marginal_relief_fraction' => null,
        'is_active' => 0,
        'rule_version' => 'inactive-version',
        'source_updated_at' => '2026-01-01',
        'source_checked_at' => '2026-01-02',
    ]];

    foreach (range(1, 6) as $index) {
        $year = 2020 + $index;
        $rules[] = [
            'financial_year_start' => $year . '-04-01',
            'financial_year_end' => ($year + 1) . '-03-31',
            'main_rate' => '0.250000',
            'small_profits_rate' => '0.190000',
            'lower_limit' => '50000.00',
            'upper_limit' => '250000.00',
            'marginal_relief_fraction' => '0.015000',
            'is_active' => 1,
            'rule_version' => 'active-version-' . $index,
            'source_updated_at' => '2026-01-01',
            'source_checked_at' => '2026-01-02',
        ];
    }

    return $rules;
}
