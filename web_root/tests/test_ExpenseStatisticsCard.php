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

$harness->run(_expense_statisticsCard::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expense_statisticsCard) {
        $harness->skip('Expense statistics card did not instantiate.');
    }

    $harness->check(_expense_statisticsCard::class, 'uses expense statistics service with selected filters', function () use ($harness, $instance): void {
        $services = $instance->services();

        $harness->assertSame('expenseStatistics', (string)($services[0]['key'] ?? ''));
        $harness->assertSame(\eel_accounts\Service\ExpenseClaimService::class, (string)($services[0]['service'] ?? ''));
        $harness->assertSame('fetchStatistics', (string)($services[0]['method'] ?? ''));
        $harness->assertSame(':company.id', (string)($services[0]['params']['companyId'] ?? ''));
        $harness->assertSame(':expense_filters', (string)($services[0]['params']['filters'] ?? ''));
    });

    $harness->check(_expense_statisticsCard::class, 'renders populated statistics panels and charts', function () use ($harness, $instance): void {
        $html = $instance->render(expenseStatisticsCardContext());

        $harness->assertSame(5, substr_count($html, '<section class="panel-soft">'));
        $harness->assertTrue(str_contains($html, 'Claimant Balances'));
        $harness->assertTrue(str_contains($html, '<th>Claims</th><th>Items</th><th>Claimed</th><th>Payments</th><th>Balance c/f</th>'));
        $harness->assertTrue(str_contains($html, '<td>Alice</td>'));
        $harness->assertTrue(str_contains($html, '<td class="numeric">2</td>'));
        $harness->assertTrue(str_contains($html, 'Claims By Nominal'));
        $harness->assertTrue(str_contains($html, '6000 Materials'));
        $harness->assertTrue(str_contains($html, 'Unassigned'));
        $harness->assertTrue(str_contains($html, 'Claims By Claimant'));
        $harness->assertTrue(str_contains($html, 'Claims Over Time'));
        $harness->assertTrue(str_contains($html, 'Health Checks'));
        $harness->assertSame(2, substr_count($html, 'class="chart chart-pie"'));
        $harness->assertTrue(str_contains($html, 'chart-line'));
        $harness->assertTrue(str_contains($html, 'Missing receipts'));
        $harness->assertTrue(str_contains($html, 'Oldest outstanding'));
        $harness->assertTrue(str_contains($html, 'EXP-2605-001'));

        $nominalSection = expenseStatisticsCardSection($html, 'Claims By Nominal');
        $claimantSection = expenseStatisticsCardSection($html, 'Claims By Claimant');

        $harness->assertTrue(str_contains($nominalSection, 'expense-statistics-nominal-layout'));
        $harness->assertTrue(strpos($nominalSection, '<div class="table-scroll">') < strpos($nominalSection, 'class="chart chart-pie"'));
        $harness->assertTrue(!str_contains($nominalSection, 'chart-legend-swatch'));
        $harness->assertTrue(!str_contains($nominalSection, 'chart-legend-label'));
        foreach (['#828146', '#825746', '#614682', '#468278', '#D7D7BC'] as $colour) {
            $harness->assertTrue(str_contains($nominalSection, 'fill="' . $colour . '"'));
        }

        $harness->assertTrue(str_contains($claimantSection, 'chart-legend-swatch'));
        $harness->assertTrue(str_contains($claimantSection, 'chart-legend-label'));
    });

    $harness->check(_expense_statisticsCard::class, 'renders useful empty state without service data', function () use ($harness, $instance): void {
        $html = $instance->render(['services' => []]);

        $harness->assertTrue(str_contains($html, 'No expense claims were found for the selected accounting period.'));
        $harness->assertTrue(str_contains($html, 'No expense claim lines were found for the selected accounting period.'));
        $harness->assertTrue(str_contains($html, 'No claimant totals were found for the selected accounting period.'));
        $harness->assertTrue(str_contains($html, 'No monthly expense claim totals were found for the selected accounting period.'));
        $harness->assertTrue(str_contains($html, 'No outstanding claims'));
    });
});

$harness->run(_expenses::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof _expenses) {
        $harness->skip('Expenses page did not instantiate.');
    }

    $harness->check(_expenses::class, 'registers expense statistics on the summary tab', function () use ($harness, $instance): void {
        $harness->assertSame(
            ['expense_statistics', 'expense_claimants', 'expense_add_claimant', 'expense_claim_create', 'expenses_state', 'expense_claim_editor'],
            $instance->cards()
        );

        $layout = $instance->cardLayout();
        $harness->assertSame('Summary', (string)($layout[0]['tab'] ?? ''));
        $harness->assertSame(['expense_statistics'], (array)($layout[0]['cards'] ?? []));
    });
});

function expenseStatisticsCardContext(): array
{
    return [
        'services' => [
            'expenseStatistics' => [
                'claimants' => [
                    [
                        'claimant_name' => 'Alice',
                        'claim_count' => 2,
                        'item_count' => 3,
                        'claimed_total' => 175.00,
                        'payments_made' => 40.00,
                        'carried_forward' => 245.00,
                    ],
                    [
                        'claimant_name' => 'Bob',
                        'claim_count' => 1,
                        'item_count' => 1,
                        'claimed_total' => 80.00,
                        'payments_made' => 20.00,
                        'carried_forward' => 60.00,
                    ],
                ],
                'nominals' => [
                    [
                        'nominal_account_id' => 1,
                        'code' => '6000',
                        'name' => 'Materials',
                        'line_count' => 3,
                        'claimed_total' => 205.00,
                    ],
                    [
                        'nominal_account_id' => 0,
                        'code' => '',
                        'name' => 'Unassigned',
                        'line_count' => 1,
                        'claimed_total' => 50.00,
                    ],
                    [
                        'nominal_account_id' => 2,
                        'code' => '6100',
                        'name' => 'Travel',
                        'line_count' => 2,
                        'claimed_total' => 35.00,
                    ],
                    [
                        'nominal_account_id' => 3,
                        'code' => '6200',
                        'name' => 'Subsistence',
                        'line_count' => 1,
                        'claimed_total' => 25.00,
                    ],
                    [
                        'nominal_account_id' => 4,
                        'code' => '6300',
                        'name' => 'Software',
                        'line_count' => 1,
                        'claimed_total' => 15.00,
                    ],
                ],
                'claimant_breakdown' => [
                    [
                        'claimant_name' => 'Alice',
                        'claimed_total' => 175.00,
                    ],
                    [
                        'claimant_name' => 'Bob',
                        'claimed_total' => 80.00,
                    ],
                ],
                'monthly_trend' => [
                    [
                        'period' => '2026-05',
                        'label' => 'May 26',
                        'claimed_total' => 230.00,
                    ],
                    [
                        'period' => '2026-06',
                        'label' => 'Jun 26',
                        'claimed_total' => 25.00,
                    ],
                ],
                'health_checks' => [
                    'draft' => [
                        'claim_count' => 2,
                        'claimed_total' => 230.00,
                    ],
                    'posted' => [
                        'claim_count' => 1,
                        'claimed_total' => 25.00,
                    ],
                    'missing_receipts' => [
                        'count' => 2,
                        'value' => 130.00,
                    ],
                    'missing_nominals' => [
                        'count' => 1,
                        'value' => 50.00,
                    ],
                    'oldest_outstanding_claim' => [
                        'claim_reference_code' => 'EXP-2605-001',
                        'claimant_name' => 'Alice',
                        'carried_forward' => 110.00,
                    ],
                    'largest_outstanding_claimant' => [
                        'claimant_name' => 'Alice',
                        'carried_forward' => 245.00,
                    ],
                ],
            ],
        ],
    ];
}

function expenseStatisticsCardSection(string $html, string $title): string
{
    $heading = '<h3 class="card-title">' . $title . '</h3>';
    $start = strpos($html, $heading);
    if ($start === false) {
        return '';
    }

    $sectionStart = strrpos(substr($html, 0, $start), '<section class="panel-soft">');
    if ($sectionStart === false) {
        return '';
    }

    $end = strpos($html, '</section>', $start);
    if ($end === false) {
        return '';
    }

    return substr($html, $sectionStart, $end + strlen('</section>') - $sectionStart);
}
