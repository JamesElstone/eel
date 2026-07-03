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

$harness->run(\eel_accounts\Service\YearEndExpenseConfirmationService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndExpenseConfirmationService::class, 'summarises expense claim position for the accounting period', static function () use ($harness): void {
        foreach (['companies', 'accounting_periods', 'expense_claimants', 'expense_claims', 'expense_claim_lines', 'expense_claim_payment_links'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = yearEndExpenseConfirmationServiceCreateFixture();
            $context = (new \eel_accounts\Service\YearEndExpenseConfirmationService())->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );

            $harness->assertSame(true, (bool)($context['available'] ?? false));
            $harness->assertSame(100.00, (float)(($context['totals'] ?? [])['brought_forward'] ?? 0));
            $harness->assertSame(250.00, (float)(($context['totals'] ?? [])['claimed_total'] ?? 0));
            $harness->assertSame(0.00, (float)(($context['totals'] ?? [])['payments_made'] ?? 0));
            $harness->assertSame(350.00, (float)(($context['totals'] ?? [])['carried_forward'] ?? 0));
            $harness->assertSame(1, count((array)($context['claimants'] ?? [])));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function yearEndExpenseConfirmationServiceCreateFixture(): array
{
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'Year End Expense Confirmation Fixture Limited', 'company_number' => 'YEEC' . $marker]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'YEEC' . $marker]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => 'YEEC ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'YEEC ' . $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claimants (company_id, claimant_name, is_active)
         VALUES (:company_id, :claimant_name, 1)',
        [
            'company_id' => $companyId,
            'claimant_name' => 'Expense Fixture Claimant ' . $marker,
        ]
    );
    $claimantId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM expense_claimants WHERE company_id = :company_id AND claimant_name = :claimant_name',
        [
            'company_id' => $companyId,
            'claimant_name' => 'Expense Fixture Claimant ' . $marker,
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            company_id,
            accounting_period_id,
            claimant_id,
            claim_year,
            claim_month,
            period_start,
            period_end,
            claim_reference_code,
            brought_forward_amount,
            claimed_amount,
            payments_amount,
            carried_forward_amount,
            status
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :claimant_id,
            2025,
            1,
            :period_start,
            :period_end,
            :claim_reference_code,
            100.00,
            250.00,
            0.00,
            350.00,
            :status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'claimant_id' => $claimantId,
            'period_start' => '2025-01-01',
            'period_end' => '2025-01-31',
            'claim_reference_code' => 'YEEC' . $marker,
            'status' => 'draft',
        ]
    );
    $claimId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM expense_claims WHERE company_id = :company_id AND claim_reference_code = :claim_reference_code',
        ['company_id' => $companyId, 'claim_reference_code' => 'YEEC' . $marker]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount)
         VALUES (:expense_claim_id, 1, :expense_date, :description, 250.00)',
        [
            'expense_claim_id' => $claimId,
            'expense_date' => '2025-01-15',
            'description' => 'Fixture expense line',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
    ];
}
