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
$harness->run(\eel_accounts\Service\UploadStatementCoverageService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\UploadStatementCoverageService $service): void {
    $harness->check(\eel_accounts\Service\UploadStatementCoverageService::class, 'builds month heatmap options from upload and continuity status', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\UploadStatementCoverageService::class, 'buildOptionsFromInputs');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-06-30',
        ], [
            [
                'month_key' => '2025-01-01',
                'label' => 'Jan 2025',
                'status' => 'red',
                'raw_rows' => 1,
                'transactions' => 0,
            ],
            [
                'month_key' => '2025-02-01',
                'label' => 'Feb 2025',
                'status' => 'amber',
                'raw_rows' => 0,
                'transactions' => 0,
            ],
            [
                'month_key' => '2025-03-01',
                'label' => 'Mar 2025',
                'status' => 'green',
                'raw_rows' => 0,
                'transactions' => 0,
            ],
            [
                'month_key' => '2025-04-01',
                'label' => 'Apr 2025',
                'status' => 'green',
                'raw_rows' => 1,
                'transactions' => 0,
            ],
            [
                'month_key' => '2025-05-01',
                'label' => 'May 2025',
                'status' => 'green',
                'raw_rows' => 0,
                'transactions' => 0,
            ],
            [
                'month_key' => '2025-06-01',
                'label' => 'Jun 2025',
                'status' => 'green',
                'raw_rows' => 2,
                'transactions' => 0,
            ],
        ], [
            [
                'account' => [
                    'account_name' => 'Current Account',
                ],
                'uploads' => [
                    [
                        'upload' => [
                            'id' => 1,
                            'date_range_start' => '2025-01-06',
                            'statement_month' => '2025-01-01',
                        ],
                        'closing_date' => '2025-01-06',
                        'opening_balance' => 2.91,
                        'closing_balance' => 0.0,
                        'running_balance_status' => 'not_available',
                        'running_balance_note' => 'No balance data available.',
                    ],
                    [
                        'upload' => [
                            'id' => 2,
                            'date_range_start' => '2025-04-20',
                            'statement_month' => '2025-04-01',
                            'original_filename' => '2025-04-ANNA.csv',
                            'account_id' => 48,
                            'date_range_end' => '2025-04-20',
                            'rows_parsed' => 999,
                            'rows_ready_to_import' => 1,
                            'rows_committed' => 1,
                        ],
                        'closing_date' => '2025-04-20',
                        'opening_balance' => 0.0,
                        'closing_balance' => 25.0,
                        'continuity_status' => 'pass',
                        'continuity_note' => 'Opening balance matches the previous statement closing balance.',
                        'running_balance_status' => 'not_available',
                        'running_balance_note' => 'No balance data available.',
                    ],
                    [
                        'upload' => [
                            'id' => 4,
                            'date_range_start' => '2025-04-20',
                            'statement_month' => '2025-04-01',
                            'original_filename' => '2025-04-ANNA.csv',
                            'account_id' => 48,
                            'date_range_end' => '2025-04-20',
                            'rows_parsed' => 1,
                            'rows_ready_to_import' => 1,
                            'rows_committed' => 0,
                        ],
                        'closing_date' => '2025-04-20',
                        'opening_balance' => 0.0,
                        'closing_balance' => 25.0,
                        'continuity_status' => 'fail',
                        'continuity_note' => 'Opening/closing mismatch.',
                        'running_balance_status' => 'not_available',
                        'running_balance_note' => 'No balance data available.',
                    ],
                    [
                        'upload' => [
                            'id' => 3,
                            'date_range_start' => '2025-06-01',
                            'statement_month' => '2025-06-01',
                        ],
                        'closing_date' => '2025-06-30',
                        'opening_balance' => 25.0,
                        'closing_balance' => 30.0,
                        'continuity_status' => 'pass',
                        'continuity_note' => 'Opening balance matches the previous statement closing balance.',
                        'running_balance_status' => 'fail',
                        'running_balance_note' => '2 rows tested, 1 balance break',
                    ],
                    [
                        'upload' => [
                            'id' => 5,
                            'date_range_start' => '2025-07-01',
                            'statement_month' => '2025-07-01',
                        ],
                        'closing_date' => '2025-07-31',
                        'opening_balance' => 31.0,
                        'closing_balance' => 40.0,
                        'continuity_status' => 'fail',
                        'continuity_note' => 'Opening/closing mismatch.',
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '2 rows tested, 0 breaks',
                    ],
                ],
            ],
        ], [
            '2025-01-01' => 1,
            '2025-02-01' => 0,
            '2025-03-01' => 0,
            '2025-04-01' => 1,
            '2025-05-01' => 0,
            '2025-06-01' => 2,
        ]);

        $harness->assertSame('uploads-statement-coverage', $options['id'] ?? null);
        $harness->assertSame('2025-01-01', $options['start'] ?? null);
        $harness->assertSame('2025-06-30', $options['end'] ?? null);

        $months = array_column((array)($options['months'] ?? []), null, 'month_key');
        $harness->assertSame('pass', $months['2025-01-01']['status'] ?? null);
        $harness->assertSame('Jan 2025', $months['2025-01-01']['label'] ?? null);
        $harness->assertSame('(1)', $months['2025-01-01']['display_value'] ?? null);
        $harness->assertSame(false, str_contains((string)($months['2025-01-01']['tooltip'] ?? ''), 'No balance data available.'));
        $harness->assertSame('pass', $months['2025-02-01']['status'] ?? null);
        $harness->assertSame('(0)', $months['2025-02-01']['display_value'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-02-01']['tooltip'] ?? ''), 'surrounding statement balances match'));
        $harness->assertSame('pass', $months['2025-03-01']['status'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-03-01']['tooltip'] ?? ''), 'surrounding statement balances match'));
        $harness->assertSame('pass', $months['2025-04-01']['status'] ?? null);
        $harness->assertSame(false, str_contains((string)($months['2025-04-01']['tooltip'] ?? ''), 'Opening boundary mismatch'));
        $harness->assertSame('pass', $months['2025-05-01']['status'] ?? null);
        $harness->assertSame('fail', $months['2025-06-01']['status'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-06-01']['tooltip'] ?? ''), 'Closing boundary mismatch'));
        $harness->assertTrue(str_contains((string)($months['2025-06-01']['tooltip'] ?? ''), '30/06/2025 at GBP 30.00; next statement opens on 01/07/2025 at GBP 31.00'));
        $harness->assertTrue(str_contains((string)($months['2025-06-01']['tooltip'] ?? ''), '2 rows tested, 1 balance break'));
    });

    $harness->check(\eel_accounts\Service\UploadStatementCoverageService::class, 'marks opening accounting period boundary mismatches on the first rendered month', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\UploadStatementCoverageService::class, 'buildOptionsFromInputs');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
        ], [
            [
                'month_key' => '2025-01-01',
                'label' => 'Jan 2025',
                'status' => 'green',
                'raw_rows' => 1,
                'transactions' => 0,
            ],
        ], [
            [
                'account' => [
                    'account_name' => 'Current Account',
                ],
                'uploads' => [
                    [
                        'upload' => [
                            'id' => 10,
                            'date_range_start' => '2024-12-01',
                            'statement_month' => '2024-12-01',
                        ],
                        'closing_date' => '2024-12-31',
                        'opening_balance' => 10.0,
                        'closing_balance' => 20.0,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '1 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 11,
                            'date_range_start' => '2025-01-02',
                            'statement_month' => '2025-01-01',
                        ],
                        'closing_date' => '2025-01-31',
                        'opening_balance' => 30.0,
                        'closing_balance' => 40.0,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '1 rows tested, 0 breaks',
                    ],
                ],
            ],
        ], [
            '2025-01-01' => 1,
        ]);

        $months = array_column((array)($options['months'] ?? []), null, 'month_key');
        $harness->assertSame('fail', $months['2025-01-01']['status'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-01-01']['tooltip'] ?? ''), 'Opening boundary mismatch'));
        $harness->assertTrue(str_contains((string)($months['2025-01-01']['tooltip'] ?? ''), '31/12/2024 at GBP 20.00; this statement opens on 02/01/2025 at GBP 30.00'));
    });

    $harness->check(\eel_accounts\Service\UploadStatementCoverageService::class, 'ignores overlapping duplicate statements when checking accounting period boundaries', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\UploadStatementCoverageService::class, 'buildOptionsFromInputs');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'period_start' => '2025-09-01',
            'period_end' => '2025-09-30',
        ], [
            [
                'month_key' => '2025-09-01',
                'label' => 'Sep 2025',
                'status' => 'green',
                'raw_rows' => 150,
                'transactions' => 75,
            ],
        ], [
            [
                'account' => [
                    'account_name' => 'Current Account',
                ],
                'uploads' => [
                    [
                        'upload' => [
                            'id' => 20,
                            'date_range_start' => '2025-08-06',
                            'statement_month' => '2025-08-01',
                        ],
                        'closing_date' => '2025-08-31',
                        'opening_balance' => 80.11,
                        'closing_balance' => 49.02,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '39 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 21,
                            'date_range_start' => '2025-09-01',
                            'statement_month' => '2025-09-01',
                            'original_filename' => '2025-09-ANNA_010925_300925.csv',
                        ],
                        'closing_date' => '2025-09-30',
                        'opening_balance' => 49.02,
                        'closing_balance' => 911.03,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '74 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 22,
                            'date_range_start' => '2025-09-01',
                            'statement_month' => '2025-09-01',
                            'original_filename' => 'duplicate-2025-09.csv',
                        ],
                        'closing_date' => '2025-09-30',
                        'opening_balance' => 49.02,
                        'closing_balance' => 911.03,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '74 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 23,
                            'date_range_start' => '2025-10-01',
                            'statement_month' => '2025-10-01',
                        ],
                        'closing_date' => '2025-10-26',
                        'opening_balance' => 911.03,
                        'closing_balance' => 390.24,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '53 rows tested, 0 breaks',
                    ],
                ],
            ],
        ], [
            '2025-09-01' => 75,
        ]);

        $months = array_column((array)($options['months'] ?? []), null, 'month_key');
        $harness->assertSame('pass', $months['2025-09-01']['status'] ?? null);
        $harness->assertSame(75, $months['2025-09-01']['value'] ?? null);
        $harness->assertSame('(75)', $months['2025-09-01']['display_value'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), '75 uploaded row(s), 75 committed transaction(s)'));
        $harness->assertSame(false, str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), 'Opening boundary mismatch'));
        $harness->assertSame(false, str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), 'Closing boundary mismatch'));
        $harness->assertSame(false, str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), '30/09/2025 at GBP 911.03; this statement opens on 01/09/2025 at GBP 49.02'));
    });

    $harness->check(\eel_accounts\Service\UploadStatementCoverageService::class, 'reports non-duplicate overlapping statement ranges', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\UploadStatementCoverageService::class, 'buildOptionsFromInputs');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'period_start' => '2025-09-01',
            'period_end' => '2025-09-30',
        ], [
            [
                'month_key' => '2025-09-01',
                'label' => 'Sep 2025',
                'status' => 'green',
                'raw_rows' => 75,
                'transactions' => 0,
            ],
        ], [
            [
                'account' => [
                    'account_name' => 'Current Account',
                ],
                'uploads' => [
                    [
                        'upload' => [
                            'id' => 30,
                            'date_range_start' => '2025-09-01',
                            'statement_month' => '2025-09-01',
                        ],
                        'closing_date' => '2025-09-30',
                        'opening_balance' => 49.02,
                        'closing_balance' => 911.03,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '74 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 31,
                            'date_range_start' => '2025-09-15',
                            'statement_month' => '2025-09-01',
                        ],
                        'closing_date' => '2025-10-15',
                        'opening_balance' => 500.00,
                        'closing_balance' => 600.00,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '10 rows tested, 0 breaks',
                    ],
                ],
            ],
        ], [
            '2025-09-01' => 75,
        ]);

        $months = array_column((array)($options['months'] ?? []), null, 'month_key');
        $harness->assertSame('fail', $months['2025-09-01']['status'] ?? null);
        $harness->assertTrue(str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), 'Statement date-range overlap'));
        $harness->assertTrue(str_contains((string)($months['2025-09-01']['tooltip'] ?? ''), 'Previous statement closes on 30/09/2025, but this statement opens on 15/09/2025'));
    });

    $harness->check(\eel_accounts\Service\UploadStatementCoverageService::class, 'builds account-level heatmap options for configured bank accounts', static function () use ($harness, $service): void {
        $method = new ReflectionMethod(\eel_accounts\Service\UploadStatementCoverageService::class, 'buildAccountHeatmapOptions');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'period_start' => '2025-01-01',
            'period_end' => '2025-03-31',
        ], [
            [
                'account' => [
                    'id' => 10,
                    'account_name' => 'Current Account',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'account_identifier' => '1234',
                ],
                'uploads' => [
                    [
                        'upload' => [
                            'id' => 100,
                            'date_range_start' => '2025-01-01',
                            'statement_month' => '2025-01-01',
                        ],
                        'closing_date' => '2025-01-31',
                        'opening_balance' => 10.0,
                        'closing_balance' => 20.0,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '10 rows tested, 0 breaks',
                    ],
                    [
                        'upload' => [
                            'id' => 101,
                            'date_range_start' => '2025-03-01',
                            'statement_month' => '2025-03-01',
                        ],
                        'closing_date' => '2025-03-31',
                        'opening_balance' => 20.0,
                        'closing_balance' => 30.0,
                        'running_balance_status' => 'pass',
                        'running_balance_note' => '12 rows tested, 0 breaks',
                    ],
                ],
            ],
            [
                'account' => [
                    'id' => 11,
                    'account_name' => 'Savings Account',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                    'institution_name' => 'Test Bank',
                ],
                'uploads' => [],
            ],
            [
                'account' => [
                    'id' => 12,
                    'account_name' => 'Trade Creditor',
                    'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                ],
                'uploads' => [],
            ],
        ], [
            10 => [
                '2025-01-01' => 5,
                '2025-03-01' => 7,
            ],
        ], [
            10 => [
                '2025-01-01' => 5,
                '2025-03-01' => 7,
            ],
        ]);

        $harness->assertCount(2, $options);
        $harness->assertSame('Current Account (1234)', $options[0]['label'] ?? null);
        $harness->assertSame('Savings Account (Test Bank)', $options[1]['label'] ?? null);

        $currentMonths = array_column((array)($options[0]['months'] ?? []), null, 'month_key');
        $savingsMonths = array_column((array)($options[1]['months'] ?? []), null, 'month_key');

        $harness->assertSame('pass', $currentMonths['2025-01-01']['status'] ?? null);
        $harness->assertSame(5, $currentMonths['2025-01-01']['value'] ?? null);
        $harness->assertSame('pass', $currentMonths['2025-02-01']['status'] ?? null);
        $harness->assertTrue(str_contains((string)($currentMonths['2025-02-01']['tooltip'] ?? ''), 'surrounding statement balances match'));
        $harness->assertSame('pass', $currentMonths['2025-03-01']['status'] ?? null);
        $harness->assertSame(7, $currentMonths['2025-03-01']['value'] ?? null);

        $harness->assertSame('warning', $savingsMonths['2025-01-01']['status'] ?? null);
        $harness->assertSame(0, $savingsMonths['2025-01-01']['value'] ?? null);
        $harness->assertTrue(str_contains((string)($savingsMonths['2025-01-01']['tooltip'] ?? ''), 'Savings Account (Test Bank), Jan 2025'));
    });
});
