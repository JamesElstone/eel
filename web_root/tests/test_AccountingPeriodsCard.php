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
            'tax_year_id' => 31,
            'settings' => [
                'date_format' => 'd/m/Y',
            ],
        ],
        'services' => [
            'tax_years' => [
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
            ],
        ],
        'service_errors' => [
            'company_detail' => null,
            'tax_years' => null,
        ],
        'accounting_guidance' => [],
    ];

    $html = $card->render($context);

    $harness->check(_accounting_periodsCard::class, 'renders form values from the selected tax year row', static function () use ($harness, $html): void {
        $harness->assertTrue(str_contains($html, 'form method="post" data-ajax="true" data-accounting-period-selector="true"'));
        $harness->assertTrue(str_contains($html, 'name="action" value="set-page-context"'));
        $harness->assertTrue(str_contains($html, 'name="page" value="companies"'));
        $harness->assertTrue(str_contains($html, 'name="company_id" value="27"'));
        $harness->assertTrue(str_contains($html, 'data-period-start="2023-10-01"'));
        $harness->assertTrue(str_contains($html, 'data-period-end="2024-09-30"'));
        $harness->assertTrue(str_contains($html, 'form method="post" data-ajax="true" data-accounting-period-form="true"'));
        $harness->assertTrue(str_contains($html, 'name="card_action" value="Company"'));
        $harness->assertTrue(str_contains($html, 'name="intent" value="update_tax_period"'));
        $harness->assertTrue(str_contains($html, 'data-state-fields="accounting_period_selected_tax_year_id,financial_period_label,period_start,period_end"'));
        $harness->assertTrue(str_contains($html, 'name="financial_period_label" value="01/10/2023 to 30/09/2024"'));
        $harness->assertTrue(str_contains($html, 'name="period_start" value="2023-10-01"'));
        $harness->assertTrue(str_contains($html, 'name="period_end" value="2024-09-30"'));
        $harness->assertTrue(str_contains($html, '<option value="31"'));
        $harness->assertTrue(str_contains($html, 'data-period-start="2023-10-01" data-period-end="2024-09-30" selected>'));
    });
});
