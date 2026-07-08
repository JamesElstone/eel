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
$harness->run(\eel_accounts\Service\BankingReconciliationService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\BankingReconciliationService $service): void {
    $harness->check(\eel_accounts\Service\BankingReconciliationService::class, 'uses adjacent previous statement for selected-period opening continuity', static function () use ($harness, $service): void {
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

    $harness->check(\eel_accounts\Service\BankingReconciliationService::class, 'hides adjacent context statements from normal banking reconciliation uploads', static function () use ($harness, $service): void {
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

    $harness->check(\eel_accounts\Service\BankingReconciliationService::class, 'ledger summary uses latest selected-period statement after adjacent filtering', static function () use ($harness, $service): void {
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

    $harness->check(\eel_accounts\Service\BankingReconciliationService::class, 'ledger summary compares statements with cumulative bank ledger balance', static function () use ($harness, $service): void {
        banking_reconciliation_fixture('cumulative-ledger', static function (array $fixture) use ($harness, $service): void {
            $fetchLedgerDeltas = banking_reconciliation_private_method($service, 'fetchLedgerBankDeltas');
            $buildLedgerSummary = banking_reconciliation_private_method($service, 'buildLedgerReconciliationSummary');

            $ledgerDeltas = $fetchLedgerDeltas->invoke(
                $service,
                (int)$fixture['company_id'],
                (int)$fixture['bank_nominal_id']
            );
            $summary = $buildLedgerSummary->invoke(
                $service,
                [
                    banking_reconciliation_upload_check(
                        267,
                        (int)$fixture['current_period_id'],
                        '2024-09-01',
                        '2024-09-30',
                        62.31,
                        124.14
                    ),
                ],
                $ledgerDeltas,
                (int)$fixture['bank_nominal_id'],
                true
            );

            $harness->assertSame('pass', (string)($summary['status'] ?? ''));
            $harness->assertSame(124.14, (float)($summary['ledger_balance'] ?? 0));
            $harness->assertSame(0.0, (float)($summary['difference'] ?? 0));
            $harness->assertSame('Ledger reconciliation uses the cumulative posted balance for this company account nominal.', (string)($summary['scope_note'] ?? ''));
        });
    });
});

function banking_reconciliation_private_method(\eel_accounts\Service\BankingReconciliationService $service, string $methodName): ReflectionMethod
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

function banking_reconciliation_fixture(string $label, callable $callback): void
{
    \InterfaceDB::beginTransaction();
    try {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('71' . substr($marker, 0, 4));
        $priorPeriodId = (int)('72' . substr($marker, 0, 4));
        $currentPeriodId = (int)('73' . substr($marker, 0, 4));
        $bankNominalId = banking_reconciliation_insert_nominal('BRB' . substr($marker, 0, 5), 'Fixture Bank ' . $label . ' ' . $marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :name, :number, 1)',
            [
                'id' => $companyId,
                'name' => 'Banking Reconciliation Fixture ' . $label . ' ' . $marker,
                'number' => 'BR' . substr($marker, 0, 6),
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :start, :end)',
            [
                'id' => $priorPeriodId,
                'company_id' => $companyId,
                'label' => 'Prior FY ' . $marker,
                'start' => '2022-09-05',
                'end' => '2023-09-30',
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :start, :end)',
            [
                'id' => $currentPeriodId,
                'company_id' => $companyId,
                'label' => 'Current FY ' . $marker,
                'start' => '2023-10-01',
                'end' => '2024-09-30',
            ]
        );

        banking_reconciliation_insert_journal_line($companyId, $priorPeriodId, $bankNominalId, '2023-09-30', 714.26, 0.0, 'Opening year one bank balance');
        banking_reconciliation_insert_journal_line($companyId, $currentPeriodId, $bankNominalId, '2024-09-30', 0.0, 590.12, 'Year two bank movement');
        banking_reconciliation_insert_journal_line($companyId, $currentPeriodId, $bankNominalId, '2024-10-01', 50.0, 0.0, 'Future movement outside latest statement date');

        $callback([
            'company_id' => $companyId,
            'prior_period_id' => $priorPeriodId,
            'current_period_id' => $currentPeriodId,
            'bank_nominal_id' => $bankNominalId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}

function banking_reconciliation_insert_nominal(string $code, string $name): int
{
    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => 'asset',
            'tax_treatment' => 'allowable',
            'sort_order' => random_int(100000, 999999),
        ]
    );

    return (int)\InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}

function banking_reconciliation_insert_journal_line(
    int $companyId,
    int $accountingPeriodId,
    int $bankNominalId,
    string $date,
    float $debit,
    float $credit,
    string $description
): void {
    $sourceRef = 'banking-reconciliation-test:' . hash('sha256', $description . $date . random_int(100000, 999999));
    \InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'bank_csv',
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => $description,
        ]
    );
    $journalId = (int)\InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => $sourceRef,
        ]
    );

    \InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $bankNominalId,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'line_description' => $description,
        ]
    );
}
