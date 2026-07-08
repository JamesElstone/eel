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

$harness->run(\eel_accounts\Service\HmrcObligationService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcObligationService $service): void {
    $harness->check(\eel_accounts\Service\HmrcObligationService::class, 'posts a balanced accrual journal for HMRC penalty notices', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata', 'hmrc_obligations'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            hmrcObligationTestEnsureNominal('6230', 'HMRC Penalties', 'expense', 'disallowable');
            hmrcObligationTestEnsureNominal('2210', 'HMRC Penalties & Interest Payable', 'liability', 'other');

            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active)
                 VALUES (:company_name, :company_number, 1)',
                ['company_name' => 'HMRC Notice Fixture ' . $marker, 'company_number' => 'HNO' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'HNO' . $marker]);
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'HMRC Notice FY',
                    'period_start' => '2024-04-01',
                    'period_end' => '2025-03-31',
                ]
            );
            $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id LIMIT 1', ['company_id' => $companyId]);

            $result = $service->createManualObligation([
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'obligation_type' => 'hmrc_penalty',
                'notice_date' => '2025-02-10',
                'due_date' => '2025-03-10',
                'amount_due' => '123.45',
                'source_reference' => 'PEN-' . $marker,
                'notes' => 'Notice PDF stored in test evidence.',
            ]);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $obligation = InterfaceDB::fetchOne(
                'SELECT id, notice_date, related_journal_id
                 FROM hmrc_obligations
                 WHERE company_id = :company_id
                   AND obligation_type = :obligation_type
                 LIMIT 1',
                ['company_id' => $companyId, 'obligation_type' => 'hmrc_penalty']
            );
            $harness->assertTrue(is_array($obligation));
            $harness->assertSame('2025-02-10', (string)($obligation['notice_date'] ?? ''));
            $journalId = (int)($obligation['related_journal_id'] ?? 0);
            $harness->assertTrue($journalId > 0);

            $lines = InterfaceDB::fetchAll(
                'SELECT na.code, jl.debit, jl.credit
                 FROM journal_lines jl
                 INNER JOIN nominal_accounts na ON na.id = jl.nominal_account_id
                 WHERE jl.journal_id = :journal_id
                 ORDER BY na.code ASC',
                ['journal_id' => $journalId]
            );
            $harness->assertSame(2, count($lines));
            $totalDebit = round(array_sum(array_map(static fn(array $line): float => (float)$line['debit'], $lines)), 2);
            $totalCredit = round(array_sum(array_map(static fn(array $line): float => (float)$line['credit'], $lines)), 2);
            $harness->assertSame('123.45', number_format($totalDebit, 2, '.', ''));
            $harness->assertSame('123.45', number_format($totalCredit, 2, '.', ''));
            $harness->assertSame(true, in_array('6230', array_column($lines, 'code'), true));
            $harness->assertSame(true, in_array('2210', array_column($lines, 'code'), true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function hmrcObligationTestEnsureNominal(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $existing = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($existing > 0) {
        return $existing;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active)
         VALUES (:code, :name, :account_type, :tax_treatment, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}
