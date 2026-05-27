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
$harness->run(_uploads_statement_coverageCard::class, static function (GeneratedServiceClassTestHarness $harness, _uploads_statement_coverageCard $card): void {
    $harness->check(_uploads_statement_coverageCard::class, 'renders eelKit month heatmap for statement coverage', static function () use ($harness, $card): void {
        $html = $card->render([
            'accounting_period' => [
                'label' => '05/09/2022 to 30/09/2023',
            ],
            'services' => [
                'statement_coverage_heatmap' => [
                    'id' => 'uploads-statement-coverage',
                    'label' => 'Statement coverage',
                    'start' => '2022-09-05',
                    'end' => '2022-10-31',
                    'months' => [
                        [
                            'month_key' => '2022-09-01',
                            'label' => 'Sep 2022',
                            'status' => 'fail',
                            'value' => 0,
                            'display_value' => '(0)',
                            'tooltip' => 'Sep 2022: no uploaded CSV rows or committed transactions found.',
                        ],
                        [
                            'month_key' => '2022-10-01',
                            'label' => 'Oct 2022',
                            'status' => 'pass',
                            'value' => 5,
                            'display_value' => '(5)',
                            'tooltip' => 'Oct 2022: 5 uploaded row(s), 5 committed transaction(s).',
                        ],
                    ],
                    'legend' => [
                        'pass' => 'Covered',
                        'warning' => 'Needs review',
                        'fail' => 'Gap',
                        'muted' => 'No data',
                    ],
                ],
            ],
        ]);

        $harness->assertTrue(str_contains($html, 'class="month-heatmap"'));
        $harness->assertTrue(str_contains($html, 'id="uploads-statement-coverage"'));
        $harness->assertTrue(str_contains($html, '<h3>Statement Coverage from 05/09/2022 to 30/09/2023</h3>'));
        $harness->assertTrue(str_contains($html, 'month-heatmap-cell--fail'));
        $harness->assertTrue(str_contains($html, '<span class="month-heatmap-cell-value" aria-hidden="true">(5)</span>'));
        $harness->assertTrue(str_contains($html, 'title="Sep 2022: no uploaded CSV rows or committed transactions found."'));
    });
});
