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

$harness->run(\eel_accounts\Service\JournalCorrectionService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\JournalCorrectionService $service
): void {
    $harness->check($service::class, 'posts one dimension-preserving reversal and returns it on retry', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }
        $service->ensureSchema();

        InterfaceDB::beginTransaction();
        try {
            $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number, is_active) VALUES (:name, :number, 1)',
                ['name' => 'Journal correction ' . $marker, 'number' => 'JCR' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => 'JCR' . $marker]);
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Correction period',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);
            $debitNominalId = journalCorrectionTestNominal('6998', 'Correction debit');
            $creditNominalId = journalCorrectionTestNominal('2298', 'Correction credit', 'liability');

            $posted = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                $companyId,
                $periodId,
                'correction_fixture',
                $marker,
                '2025-03-01',
                'Original correction fixture',
                [
                    ['nominal_account_id' => $debitNominalId, 'debit' => 42.75, 'credit' => 0, 'line_description' => 'Original debit'],
                    ['nominal_account_id' => $creditNominalId, 'debit' => 0, 'credit' => 42.75, 'line_description' => 'Original credit'],
                ],
                'system_generated'
            );
            $harness->assertSame(true, (bool)($posted['success'] ?? false));
            $sourceJournalId = (int)($posted['journal']['id'] ?? 0);

            $result = $service->reverseJournal(
                $companyId,
                $sourceJournalId,
                $periodId,
                '2025-04-01',
                'Source document withdrawn.',
                'test-suite',
                'fixture:' . $marker
            );
            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(false, (bool)($result['already_reversed'] ?? true));
            $reversalJournalId = (int)($result['reversal_journal_id'] ?? 0);
            $harness->assertTrue($reversalJournalId > 0);

            $lines = InterfaceDB::fetchAll(
                'SELECT nominal_account_id, debit, credit FROM journal_lines WHERE journal_id = :journal_id ORDER BY id',
                ['journal_id' => $reversalJournalId]
            );
            $harness->assertSame(2, count($lines));
            $harness->assertSame('42.75', number_format((float)$lines[0]['credit'], 2, '.', ''));
            $harness->assertSame('42.75', number_format((float)$lines[1]['debit'], 2, '.', ''));

            $retry = $service->reverseJournal(
                $companyId,
                $sourceJournalId,
                $periodId,
                '2025-04-01',
                'Source document withdrawn.',
                'test-suite',
                'fixture:' . $marker
            );
            $harness->assertSame(true, (bool)($retry['success'] ?? false));
            $harness->assertSame(true, (bool)($retry['already_reversed'] ?? false));
            $harness->assertSame($reversalJournalId, (int)($retry['reversal_journal_id'] ?? 0));
            $harness->assertSame(1, InterfaceDB::countWhere('journal_reversals', ['source_journal_id' => $sourceJournalId]));

            $differentOperation = $service->reverseJournal(
                $companyId,
                $sourceJournalId,
                $periodId,
                '2025-04-01',
                'A different correction attempt.',
                'test-suite',
                'different-operation:' . $marker
            );
            $harness->assertSame(false, (bool)($differentOperation['success'] ?? true));
            $harness->assertSame(1, InterfaceDB::countWhere('journal_reversals', ['source_journal_id' => $sourceJournalId]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function journalCorrectionTestNominal(string $code, string $name, string $accountType = 'expense'): int
{
    $id = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($id > 0) {
        return $id;
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active)
         VALUES (:code, :name, :account_type, :tax_treatment, 1)',
        ['code' => $code, 'name' => $name, 'account_type' => $accountType, 'tax_treatment' => 'other']
    );
    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}
