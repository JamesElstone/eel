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
$harness->run(BankingReconciliationService::class, static function (GeneratedServiceClassTestHarness $harness, BankingReconciliationService $service): void {
    $harness->check(BankingReconciliationService::class, 'uses adjacent previous statement for selected-period opening continuity', static function () use ($harness, $service): void {
        $applyContinuity = banking_reconciliation_private_method($service, 'applyContinuityChecks');
        $filterAccountingPeriod = banking_reconciliation_private_method($service, 'filterUploadAnalysesForAccountingPeriod');

        $analysed = $applyContinuity->invoke($service, [
            banking_reconciliation_upload_check(217, 73, '2025-09-01', '2025-09-30', 49.02, 911.03),
            banking_reconciliation_upload_check(267, 74, '2025-10-01', '2025-10-26', 911.03, 390.24),
        ]);
        $visible = $filterAccountingPeriod->invoke($service, $analysed, 74);

        $harness->assertCount(1, $visible);
        $harness->assertSame(267, (int)($visible[0]['upload']['id'] ?? 0));
        $harness->assertSame('pass', $visible[0]['continuity_status'] ?? null);
        $harness->assertSame(911.03, $visible[0]['previous_statement_closing_balance'] ?? null);
        $harness->assertSame('Opening balance matches the previous statement closing balance.', $visible[0]['continuity_note'] ?? null);
    });

    $harness->check(BankingReconciliationService::class, 'hides adjacent context statements from normal banking reconciliation uploads', static function () use ($harness, $service): void {
        $applyContinuity = banking_reconciliation_private_method($service, 'applyContinuityChecks');
        $filterAccountingPeriod = banking_reconciliation_private_method($service, 'filterUploadAnalysesForAccountingPeriod');

        $analysed = $applyContinuity->invoke($service, [
            banking_reconciliation_upload_check(217, 73, '2025-09-01', '2025-09-30', 49.02, 911.03),
            banking_reconciliation_upload_check(267, 74, '2025-10-01', '2025-10-26', 911.03, 390.24),
            banking_reconciliation_upload_check(999, 75, '2026-10-01', '2026-10-31', 390.24, 999.99),
        ]);
        $visible = $filterAccountingPeriod->invoke($service, $analysed, 74);

        $harness->assertCount(1, $visible);
        $harness->assertSame([267], array_map(
            static fn(array $uploadCheck): int => (int)($uploadCheck['upload']['id'] ?? 0),
            $visible
        ));
    });

    $harness->check(BankingReconciliationService::class, 'ledger summary uses latest selected-period statement after adjacent filtering', static function () use ($harness, $service): void {
        $applyContinuity = banking_reconciliation_private_method($service, 'applyContinuityChecks');
        $filterAccountingPeriod = banking_reconciliation_private_method($service, 'filterUploadAnalysesForAccountingPeriod');
        $buildLedgerSummary = banking_reconciliation_private_method($service, 'buildLedgerReconciliationSummary');

        $analysed = $applyContinuity->invoke($service, [
            banking_reconciliation_upload_check(267, 74, '2025-10-01', '2025-10-26', 911.03, 390.24),
            banking_reconciliation_upload_check(268, 74, '2025-11-01', '2025-11-30', 390.24, 165.20),
            banking_reconciliation_upload_check(999, 75, '2026-10-01', '2026-10-31', 165.20, 999.99),
        ]);
        $visible = $filterAccountingPeriod->invoke($service, $analysed, 74);
        $summary = $buildLedgerSummary->invoke($service, $visible, [], 0);

        $harness->assertSame(165.20, $summary['statement_closing_balance'] ?? null);
        $harness->assertSame('2025-11-30', $summary['statement_closing_date'] ?? null);
    });
});

function banking_reconciliation_private_method(BankingReconciliationService $service, string $methodName): ReflectionMethod
{
    $method = (new ReflectionClass($service))->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}

function banking_reconciliation_upload_check(int $uploadId, int $accountingPeriodId, string $startDate, string $closingDate, float $openingBalance, float $closingBalance): array
{
    return [
        'upload' => [
            'id' => $uploadId,
            'accounting_period_id' => $accountingPeriodId,
            'date_range_start' => $startDate,
            'statement_month' => substr($startDate, 0, 7) . '-01',
            'date_range_end' => $closingDate,
            'original_filename' => 'statement-' . $uploadId . '.csv',
        ],
        'statement_month' => substr($startDate, 0, 7) . '-01',
        'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance,
        'closing_date' => $closingDate,
        'previous_statement_closing_balance' => null,
        'continuity_status' => 'warning',
        'continuity_note' => 'No previous statement exists to compare against.',
        'running_balance_status' => 'pass',
        'running_balance_note' => '1 rows tested, 0 breaks',
    ];
}
