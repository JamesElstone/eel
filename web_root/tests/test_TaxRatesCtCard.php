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

$harness->run(_tax_rates_ctCard::class, static function (GeneratedServiceClassTestHarness $harness, _tax_rates_ctCard $card): void {
    $context = [
        'page' => [
            'page_id' => 'tax_artifacts',
            'page_cards' => ['tax_rates_ct'],
        ],
        'tax_rates_ct' => [
            'rules' => tax_rates_card_test_rules(),
        ],
    ];

    $harness->check(_tax_rates_ctCard::class, 'defaults to active rules and paginates to five rows', static function () use ($harness, $card, $context): void {
        $html = $card->render($context);

        $harness->assertTrue(str_contains($html, '<option value="active" selected>Active</option>'));
        $harness->assertTrue(str_contains($html, '<option value="all">All</option>'));
        $harness->assertTrue(str_contains($html, 'sourced tax and allowance rules 1-5 of 6'));
        $harness->assertTrue(str_contains($html, 'active-version-5'));
        $harness->assertTrue(str_contains($html, 'Capital Allowances'));
        $harness->assertTrue(str_contains($html, 'GBP 1,000,000.00'));
        $harness->assertSame(false, str_contains($html, 'active-version-6'));
        $harness->assertSame(false, str_contains($html, 'inactive-version'));
        $harness->assertTrue(str_contains($html, 'name="tax_rates_ct_status" value="active"'));
        $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Refresh HMRC Rates and FRS 105 thresholds</button>'));
        $harness->assertSame(false, str_contains($html, 'Import Live His Majesty'));
    });

    $harness->check(_tax_rates_ctCard::class, 'empty table prompts live HMRC import', static function () use ($harness, $card, $context): void {
        $emptyContext = $context;
        $emptyContext['tax_rates_ct']['rules'] = [];
        $html = $card->render($emptyContext);

        $harness->assertTrue(str_contains($html, 'No active sourced tax or allowance rules are stored yet.'));
        $harness->assertTrue(str_contains($html, '<button class="button danger" type="submit">Import Live His Majesty&#039;s Revenue and Customs (HMRC) Rates and FRS 105 thresholds</button>'));
        $harness->assertSame(false, str_contains($html, 'Refresh His Majesty'));
    });

    $harness->check(_tax_rates_ctCard::class, 'all filter includes superseded rows and exports filtered rows', static function () use ($harness, $card, $context): void {
        $allContext = $context;
        $allContext['tax_rates_ct']['status_filter'] = 'all';
        $html = $card->render($allContext);
        $tables = $card->tables($allContext);
        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($html, '<option value="all" selected>All</option>'));
        $harness->assertTrue(str_contains($html, 'sourced tax and allowance rules 1-5 of 7'));
        $harness->assertTrue(str_contains($html, 'inactive-version'));
        $harness->assertTrue(str_contains($html, '<span class="badge info">Superseded</span>'));
        $harness->assertTrue(str_contains($csv, 'inactive-version'));
    });

    $harness->check(_tax_rates_ctCard::class, 'handle stores normalised filter input', static function () use ($harness, $card, $context): void {
        $request = new RequestFramework(
            ['page' => 'tax_artifacts'],
            ['tax_rates_ct_status' => 'all'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(test_tmp_directory()));
        $handled = $card->handle($request, $services, $context, ActionResultFramework::none());

        $harness->assertSame('all', (string)$handled['tax_rates_ct']['status_filter']);
    });
});

function tax_rates_card_test_rules(): array
{
    $rules = [[
        'domain_label' => 'Corporation Tax',
        'regime_label' => 'Non-ring-fence',
        'rule_label' => 'Rate bands and marginal relief',
        'period_start' => '2020-04-01',
        'period_end' => '2021-03-31',
        'value_summary' => 'Main 19.00%',
        'is_active' => 0,
        'rule_version' => 'inactive-version',
        'source_updated_at' => '2026-01-01',
        'source_checked_at' => '2026-01-02',
    ]];

    foreach (range(1, 6) as $index) {
        $year = 2020 + $index;
        $rules[] = [
            'domain_label' => $index === 1 ? 'Capital Allowances' : 'Corporation Tax',
            'regime_label' => $index === 1 ? 'Plant and machinery' : 'Non-ring-fence',
            'rule_label' => $index === 1 ? 'Annual investment allowance limit' : 'Rate bands and marginal relief',
            'period_start' => $year . '-04-01',
            'period_end' => $index === 1 ? '' : ($year + 1) . '-03-31',
            'value_summary' => $index === 1 ? 'GBP 1,000,000.00' : 'Main 25.00%; Small profits 19.00%; Lower GBP 50,000.00; Upper GBP 250,000.00; MR 0.015000',
            'is_active' => 1,
            'rule_version' => 'active-version-' . $index,
            'source_updated_at' => '2026-01-01',
            'source_checked_at' => '2026-01-02',
        ];
    }

    return $rules;
}
