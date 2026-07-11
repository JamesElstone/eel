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
$harness->run(\eel_accounts\Service\YearEndMetricsService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\YearEndMetricsService $service): void {
    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include first statement and mismatch detail', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'uploads' => [
                    yearEndMetricsUploadCheck([
                        'continuity_status' => 'warning',
                        'continuity_note' => 'No previous statement exists to compare against.',
                        'opening_balance' => 0.0,
                        'closing_balance' => 681.44,
                    ]),
                    yearEndMetricsUploadCheck([
                        'upload_id' => 314,
                        'filename' => 'BANK_011025_311025.csv',
                        'start' => '2025-10-01',
                        'end' => '2025-10-05',
                        'continuity_status' => 'fail',
                        'continuity_note' => 'Opening/closing mismatch.',
                        'opening_balance' => 600.00,
                        'closing_balance' => 121.44,
                        'previous_statement_closing_balance' => 681.44,
                    ]),
                ],
            ]),
        ]);

        $harness->assertCount(2, $issues);
        $harness->assertSame('statement_continuity', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame('Example Bank - Saving Pot (20%)', (string)($issues[0]['account_name'] ?? ''));
        $harness->assertSame('BANK_010925_300925.csv', (string)($issues[0]['upload_filename'] ?? ''));
        $harness->assertSame(0.0, (float)($issues[0]['opening_balance'] ?? -1));
        $harness->assertSame(null, $issues[0]['previous_statement_closing_balance'] ?? null);
        $harness->assertSame('No previous statement exists to compare against.', (string)($issues[0]['note'] ?? ''));
        $harness->assertSame('fail', (string)($issues[1]['status'] ?? ''));
        $harness->assertSame(681.44, (float)($issues[1]['previous_statement_closing_balance'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include running balance failed row numbers', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'statement_continuity_status' => 'pass',
                'running_balance_status' => 'fail',
                'uploads' => [
                    yearEndMetricsUploadCheck([
                        'continuity_status' => 'pass',
                        'running_balance_status' => 'fail',
                        'running_balance_note' => '10 rows tested, 2 balance breaks',
                        'balance_check_rows_tested' => 10,
                        'balance_check_rows_failed' => 2,
                        'failed_rows' => [
                            ['row_number' => 12],
                            ['row_number' => 15],
                        ],
                    ]),
                ],
            ]),
        ]);

        $harness->assertCount(1, $issues);
        $harness->assertSame('running_balance', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame(10, (int)($issues[0]['balance_check_rows_tested'] ?? 0));
        $harness->assertSame(2, (int)($issues[0]['balance_check_rows_failed'] ?? 0));
        $harness->assertSame([12, 15], $issues[0]['failed_row_numbers'] ?? []);
    });

    $harness->check(\eel_accounts\Service\YearEndMetricsService::class, 'statement continuity issues include ledger reconciliation balances', static function () use ($harness, $service): void {
        $method = yearEndMetricsPrivateMethod($service, 'statementContinuityIssues');
        $issues = $method->invoke($service, [
            yearEndMetricsPanel([
                'ledger_reconciliation_status' => 'fail',
                'ledger_summary' => [
                    'statement_closing_date' => '2025-09-30',
                    'statement_closing_balance' => 911.03,
                    'ledger_balance' => 900.00,
                    'difference' => -11.03,
                    'note' => 'Difference may come from missing statement imports.',
                ],
            ]),
        ]);

        $harness->assertCount(1, $issues);
        $harness->assertSame('ledger_reconciliation', (string)($issues[0]['type'] ?? ''));
        $harness->assertSame('2025-09-30', (string)($issues[0]['statement_closing_date'] ?? ''));
        $harness->assertSame(911.03, (float)($issues[0]['statement_closing_balance'] ?? 0));
        $harness->assertSame(900.00, (float)($issues[0]['ledger_balance'] ?? 0));
        $harness->assertSame(-11.03, (float)($issues[0]['difference'] ?? 0));
    });
});

function yearEndMetricsPrivateMethod(\eel_accounts\Service\YearEndMetricsService $service, string $methodName): ReflectionMethod
{
    $method = (new ReflectionClass($service))->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}

function yearEndMetricsPanel(array $overrides = []): array
{
    return array_replace_recursive([
        'account' => [
            'id' => 58,
            'account_name' => 'Example Bank - Saving Pot (20%)',
        ],
        'statement_continuity_status' => 'warning',
        'running_balance_status' => 'pass',
        'ledger_reconciliation_status' => 'pass',
        'uploads' => [],
        'ledger_summary' => [
            'status' => 'pass',
        ],
    ], $overrides);
}

function yearEndMetricsUploadCheck(array $overrides = []): array
{
    return array_replace_recursive([
        'upload' => [
            'id' => (int)($overrides['upload_id'] ?? 313),
            'original_filename' => (string)($overrides['filename'] ?? 'BANK_010925_300925.csv'),
            'statement_month' => '2025-09-01',
            'date_range_start' => (string)($overrides['start'] ?? '2025-09-20'),
            'date_range_end' => (string)($overrides['end'] ?? '2025-09-25'),
        ],
        'opening_balance' => 0.0,
        'closing_balance' => 681.44,
        'closing_date' => (string)($overrides['end'] ?? '2025-09-25'),
        'previous_statement_closing_balance' => null,
        'continuity_status' => 'warning',
        'continuity_note' => 'No previous statement exists to compare against.',
        'running_balance_status' => 'pass',
        'running_balance_note' => '3 rows tested, 0 breaks',
        'balance_check_rows_tested' => 3,
        'balance_check_rows_failed' => 0,
        'failed_rows' => [],
    ], array_diff_key($overrides, array_flip(['upload_id', 'filename', 'start', 'end'])));
}
