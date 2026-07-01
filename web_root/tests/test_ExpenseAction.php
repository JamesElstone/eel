<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(ExpenseAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof ExpenseAction) {
        $harness->skip('Expense action did not instantiate.');
    }

    $harness->check(ExpenseAction::class, 'confirm_no_lines records confirmation and returns success flash', function () use ($harness, $instance): void {
        expenseActionWithFixture(static function (array $fixture) use ($harness, $instance): void {
            $request = new RequestFramework(
                [],
                [
                    'card_action' => 'Expense',
                    'intent' => 'confirm_no_lines',
                    'company_id' => (string)$fixture['company_id'],
                    'claim_id' => (string)$fixture['claim_id'],
                ],
                ['REQUEST_METHOD' => 'POST'],
                [],
                []
            );

            $result = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, in_array('expense.claim.editor', $result->changedFacts(), true));
            $harness->assertSame('No-lines month confirmed.', (string)(($result->flashMessages()[0] ?? [])['message'] ?? ''));
            $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM expense_claims
                 WHERE id = :id
                   AND no_lines_confirmed_at IS NOT NULL
                   AND no_lines_confirmed_by = :no_lines_confirmed_by',
                [
                    'id' => (int)$fixture['claim_id'],
                    'no_lines_confirmed_by' => 'web_app',
                ]
            ));
        });
    });
});

function expenseActionWithFixture(callable $callback): void
{
    if (!\InterfaceDB::tableExists('expense_claims')) {
        throw new RuntimeException('Expense claim database tables are not available.');
    }

    \InterfaceDB::beginTransaction();

    try {
        $marker = (string)random_int(1000, 9999);
        $companyId = (int)('71' . $marker);
        $periodId = (int)('72' . $marker);
        $claimantId = (int)('73' . $marker);
        $claimId = (int)('74' . $marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Expense Action Fixture ' . $marker,
                'company_number' => 'EA' . $marker,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $periodId,
                'company_id' => $companyId,
                'label' => 'Expense Action Fixture ' . $marker,
                'period_start' => '2026-04-01',
                'period_end' => '2027-03-31',
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO expense_claimants (id, company_id, claimant_name, is_active)
             VALUES (:id, :company_id, :claimant_name, 1)',
            [
                'id' => $claimantId,
                'company_id' => $companyId,
                'claimant_name' => 'Expense Action Claimant ' . $marker,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO expense_claims (
                id,
                company_id,
                accounting_period_id,
                claimant_id,
                claim_year,
                claim_month,
                period_start,
                period_end,
                claim_reference_code
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :claimant_id,
                :claim_year,
                :claim_month,
                :period_start,
                :period_end,
                :claim_reference_code
             )',
            [
                'id' => $claimId,
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'claimant_id' => $claimantId,
                'claim_year' => 2026,
                'claim_month' => 5,
                'period_start' => '2026-05-01',
                'period_end' => '2026-05-31',
                'claim_reference_code' => 'EXP-ACTION-' . $marker,
            ]
        );

        $callback([
            'company_id' => $companyId,
            'claim_id' => $claimId,
        ]);
    } finally {
        if (\InterfaceDB::inTransaction()) {
            \InterfaceDB::rollBack();
        }
    }
}
