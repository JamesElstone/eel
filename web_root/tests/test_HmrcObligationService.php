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
    $harness->check(\eel_accounts\Service\HmrcObligationService::class, 'cancels a notice with an equal linked reversal and retains the source record', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata', 'hmrc_obligations'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        $service->ensureSchema();
        (new \eel_accounts\Service\JournalCorrectionService())->ensureSchema();
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

            if (InterfaceDB::tableExists('year_end_reviews')) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked)
                     VALUES (:company_id, :accounting_period_id, 1)',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                );
                $lockedError = '';
                try {
                    $service->correctManualObligation($companyId, (int)$obligation['id'], [
                        'correction_mode' => 'cancel',
                        'effective_date' => '2025-03-01',
                        'reason' => 'HMRC withdrew the notice.',
                    ], 'test-suite');
                } catch (RuntimeException $exception) {
                    $lockedError = $exception->getMessage();
                }
                $harness->assertTrue(str_contains($lockedError, 'accounting period is locked'));
                $harness->assertSame(1, InterfaceDB::countWhere('hmrc_obligations', ['id' => (int)$obligation['id']]));
                InterfaceDB::prepareExecute(
                    'UPDATE year_end_reviews SET is_locked = 0 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                );
            }

            $correction = $service->correctManualObligation($companyId, (int)$obligation['id'], [
                'correction_mode' => 'cancel',
                'effective_date' => '2025-03-01',
                'reason' => 'HMRC withdrew the notice.',
            ], 'test-suite');
            $harness->assertSame(true, (bool)($correction['success'] ?? false));
            $harness->assertSame(1, InterfaceDB::countWhere('hmrc_obligations', ['id' => (int)$obligation['id']]));
            $harness->assertSame(1, InterfaceDB::countWhere('journals', ['id' => $journalId]));
            $harness->assertSame(2, InterfaceDB::countWhere('journal_lines', ['journal_id' => $journalId]));
            $reversalJournalId = (int)($correction['reversal_journal_id'] ?? 0);
            $harness->assertTrue($reversalJournalId > 0);
            $cancelled = InterfaceDB::fetchOne(
                'SELECT status, reversal_journal_id, cancelled_on, cancellation_reason
                 FROM hmrc_obligations WHERE id = :id',
                ['id' => (int)$obligation['id']]
            );
            $harness->assertSame('cancelled', (string)($cancelled['status'] ?? ''));
            $harness->assertSame($reversalJournalId, (int)($cancelled['reversal_journal_id'] ?? 0));
            $harness->assertSame('2025-03-01', (string)($cancelled['cancelled_on'] ?? ''));
            $net = InterfaceDB::fetchAll(
                'SELECT nominal_account_id, ROUND(SUM(debit - credit), 2) AS balance
                 FROM journal_lines
                 WHERE journal_id IN (:source_journal_id, :reversal_journal_id)
                 GROUP BY nominal_account_id',
                ['source_journal_id' => $journalId, 'reversal_journal_id' => $reversalJournalId]
            );
            $harness->assertSame(2, count($net));
            $harness->assertSame(true, count(array_filter($net, static fn(array $row): bool => abs((float)$row['balance']) > 0.001)) === 0);
            $summary = $service->getOutstandingSummary($companyId);
            $harness->assertSame('0.00', number_format((float)$summary['total_owed'], 2, '.', ''));

            $retry = $service->correctManualObligation($companyId, (int)$obligation['id'], [
                'correction_mode' => 'cancel',
                'effective_date' => '2025-03-01',
                'reason' => 'HMRC withdrew the notice.',
            ], 'test-suite');
            $harness->assertSame(true, (bool)($retry['already_corrected'] ?? false));
            $harness->assertSame(1, InterfaceDB::countWhere('journal_reversals', ['source_journal_id' => $journalId]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(\eel_accounts\Service\HmrcObligationService::class, 'reassesses a paid notice with a replacement accrual and carried-forward credit', static function () use ($harness, $service): void {
        $service->ensureSchema();
        (new \eel_accounts\Service\JournalCorrectionService())->ensureSchema();
        InterfaceDB::beginTransaction();
        try {
            hmrcObligationTestEnsureNominal('6230', 'HMRC Penalties', 'expense', 'disallowable');
            hmrcObligationTestEnsureNominal('2210', 'HMRC Penalties & Interest Payable', 'liability', 'other');
            $marker = substr(hash('sha256', __FILE__ . 'reassess' . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active) VALUES (:name, :number, 1)',
                ['name' => 'HMRC Reassessment ' . $marker, 'number' => 'HRA' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => 'HRA' . $marker]);
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                ['company_id' => $companyId, 'label' => 'Reassessment FY', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
            );
            $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);
            $created = $service->createManualObligation([
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'obligation_type' => 'hmrc_penalty',
                'notice_date' => '2025-02-01',
                'due_date' => '2025-03-01',
                'amount_due' => '100.00',
                'source_reference' => 'OLD-' . $marker,
            ]);
            $oldObligationId = (int)($created['obligation_id'] ?? 0);
            $oldJournalId = (int)($created['journal_id'] ?? 0);
            $harness->assertTrue($oldObligationId > 0 && $oldJournalId > 0);
            $paid = $service->markPaid($oldObligationId, 100.00, 'PAY-' . $marker);
            $harness->assertSame(true, (bool)($paid['success'] ?? false));

            $result = $service->correctManualObligation($companyId, $oldObligationId, [
                'correction_mode' => 'reassess',
                'effective_date' => '2025-04-01',
                'reason' => 'HMRC reduced the assessment.',
                'replacement_due_date' => '2025-05-01',
                'replacement_amount_due' => '75.00',
                'replacement_source_reference' => 'NEW-' . $marker,
            ], 'test-suite');
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame('75.00', number_format((float)($result['credit_carried_forward'] ?? 0), 2, '.', ''));
            $harness->assertSame('25.00', number_format((float)($result['expected_refund_amount'] ?? 0), 2, '.', ''));

            $replacementId = (int)($result['replacement_obligation_id'] ?? 0);
            $replacement = InterfaceDB::fetchOne(
                'SELECT status, amount_due, amount_paid, related_journal_id
                 FROM hmrc_obligations WHERE id = :id',
                ['id' => $replacementId]
            );
            $harness->assertSame('paid', (string)($replacement['status'] ?? ''));
            $harness->assertSame('75.00', number_format((float)($replacement['amount_paid'] ?? 0), 2, '.', ''));
            $harness->assertTrue((int)($replacement['related_journal_id'] ?? 0) > 0);
            $harness->assertSame(1, InterfaceDB::countWhere('hmrc_obligation_credit_transfers', [
                'from_obligation_id' => $oldObligationId,
                'to_obligation_id' => $replacementId,
            ]));
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
