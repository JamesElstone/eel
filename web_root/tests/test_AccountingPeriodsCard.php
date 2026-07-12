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

$harness->run(_accounting_periodsCard::class, static function (GeneratedServiceClassTestHarness $harness, _accounting_periodsCard $card): void {
    $context = [
        'page' => [
            'page_id' => 'companies',
        ],
        'company' => [
            'id' => 27,
            'accounting_period_id' => 31,
            'settings' => [
                'date_format' => 'd/m/Y',
            ],
        ],
        'services' => [
            'accounting_periods' => [
                [
                    'id' => '31',
                    'label' => '01/10/2023 to 30/09/2024',
                    'period_start' => '2023-10-01',
                    'period_end' => '2024-09-30',
                ],
                [
                    'id' => '30',
                    'label' => '05/09/2022 to 30/09/2023',
                    'period_start' => '2022-09-05',
                    'period_end' => '2023-09-30',
                ],
                [
                    'id' => '29',
                    'label' => '01/10/2021 to 30/09/2022',
                    'period_start' => '2021-10-01',
                    'period_end' => '2022-09-30',
                ],
                [
                    'id' => '28',
                    'label' => '01/10/2020 to 30/09/2021',
                    'period_start' => '2020-10-01',
                    'period_end' => '2021-09-30',
                ],
                [
                    'id' => '27',
                    'label' => '01/10/2019 to 30/09/2020',
                    'period_start' => '2019-10-01',
                    'period_end' => '2020-09-30',
                ],
                [
                    'id' => '26',
                    'label' => '01/10/2018 to 30/09/2019',
                    'period_start' => '2018-10-01',
                    'period_end' => '2019-09-30',
                ],
            ],
        ],
        'service_errors' => [
            'company_detail' => null,
            'accounting_periods' => null,
        ],
        'accounting_guidance' => [],
    ];

    $html = $card->render($context);

    $harness->check(_accounting_periodsCard::class, 'renders form values from the selected accounting period row', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, '<div class="card-toolbar">'));
        $harness->assertTrue(str_contains($html, 'name="_table_export_prepare" value="csv"'));
        $harness->assertTrue(str_contains($html, '<div class="table-scroll-mini"><table>'));
        $harness->assertTrue(str_contains($html, 'Accounting periods 1-5 of 6'));
        $harness->assertTrue(str_contains($html, 'name="accounting_periods_page" value="2"'));
        $harness->assertTrue(str_contains($html, 'form method="post" data-ajax="true" data-accounting-period-selector="true"'));
        $harness->assertTrue(str_contains($html, '<div class="panel-soft stack">'));
        $harness->assertTrue(str_contains($html, 'name="action" value="set-site-context"'));
        $harness->assertTrue(str_contains($html, 'name="page" value="companies"'));
        $harness->assertTrue(str_contains($html, 'name="site_context_key" value="accounting_period_id"'));
        $harness->assertTrue(str_contains($html, 'name="site_context_input_name" value="accounting_period_id"'));
        $harness->assertTrue(str_contains($html, 'name="company_id" value="27"'));
        $harness->assertTrue(str_contains($html, 'data-period-start="2023-10-01"'));
        $harness->assertTrue(str_contains($html, 'data-period-end="2024-09-30"'));
        $harness->assertTrue(str_contains($html, 'form method="post" data-ajax="true" data-accounting-period-form="true"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Company"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="update_accounting_period"'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="accounting_period_selected_accounting_period_id,financial_period_label,period_start,period_end"'));
        $harness->assertTrue(str_contains($html, 'name="financial_period_label" value="01/10/2023 to 30/09/2024"'));
        $harness->assertTrue(str_contains($html, 'name="period_start" value="2023-10-01"'));
        $harness->assertTrue(str_contains($html, 'name="period_end" value="2024-09-30"'));
        $harness->assertTrue(str_contains($html, '<option value="31"'));
        $harness->assertTrue(str_contains($html, 'data-period-start="2023-10-01" data-period-end="2024-09-30" selected>'));
    });

    $harness->check(_accounting_periodsCard::class, 'registers existing periods as a framework table', static function () use ($harness, $card, $context): void {
        $tables = $card->tables($context);

        $harness->assertCount(1, $tables);
        $harness->assertTrue($tables[0] instanceof TableFramework);

        $csv = $tables[0]->exportCsv();

        $harness->assertTrue(str_contains($csv, 'Alias,Start,End'));
        $harness->assertTrue(str_contains($csv, '01/10/2018 to 30/09/2019'));
        $harness->assertTrue(str_contains($csv, '01/10/2018'));
    });

    $harness->check(_accounting_periodsCard::class, 'renders a locked selected period as view only while leaving the selector available', static function () use ($harness, $card, $context): void {
        $lockedContext = $context;
        $lockedContext['services']['selected_period_locked'] = true;
        $html = $card->render($lockedContext);

        $harness->assertTrue(str_contains($html, 'data-accounting-period-locked="true"'));
        $harness->assertTrue(str_contains($html, 'This accounting period is locked. Its alias and dates are view only'));
        $harness->assertTrue(str_contains($html, 'name="financial_period_label" value="01/10/2023 to 30/09/2024" readonly'));
        $harness->assertTrue(str_contains($html, 'id="save_accounting_period_button" type="submit" disabled title="This accounting period is locked."'));
        $harness->assertTrue(str_contains($html, 'data-accounting-period-selector="true"'));

        $unlockedContext = $context;
        $unlockedContext['services']['selected_period_locked'] = false;
        $unlockedHtml = $card->render($unlockedContext);
        $harness->assertTrue(str_contains($unlockedHtml, 'data-accounting-period-locked="false"'));
        $harness->assertFalse(str_contains($unlockedHtml, 'aria-readonly="true"'));
    });
});
