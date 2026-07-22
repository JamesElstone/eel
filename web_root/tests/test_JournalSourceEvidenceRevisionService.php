<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\JournalSourceEvidenceService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\JournalSourceEvidenceService $service): void {
        $harness->check(\eel_accounts\Service\JournalSourceEvidenceService::class, 'verifies revised transaction and expense journals, reversals, and HMRC accruals', static function () use ($harness, $service): void {
            foreach (['companies', 'accounting_periods', 'statement_uploads', 'transactions', 'journals', 'journal_lines', 'journal_entry_metadata', 'journal_reversals', 'expense_claimants', 'expense_claims', 'hmrc_obligations'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }
            InterfaceDB::beginTransaction();
            try {
                $fixture = journalSourceEvidenceRevisionFixture();
                $results = $service->verify($fixture['headers'], $fixture['company_id'], $fixture['period_id']);

                foreach ($fixture['valid_ids'] as $journalId) {
                    $harness->assertSame(true, (bool)($results[$journalId]['verified'] ?? false));
                }
                foreach ($fixture['invalid_ids'] as $journalId) {
                    $harness->assertSame(false, (bool)($results[$journalId]['verified'] ?? true));
                }
                $harness->assertTrue(str_contains((string)($results[$fixture['bank_replacement_id']]['reason'] ?? ''), 'replacement relationship'));
                $harness->assertTrue(str_contains((string)($results[$fixture['expense_replacement_id']]['reason'] ?? ''), 'replacement relationship'));
                $harness->assertTrue(str_contains((string)($results[$fixture['hmrc_journal_id']]['reason'] ?? ''), 'HMRC obligation'));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

/** @return array{company_id: int, period_id: int, headers: list<array<string, mixed>>, valid_ids: list<int>, invalid_ids: list<int>, bank_replacement_id: int, expense_replacement_id: int, hmrc_journal_id: int} */
function journalSourceEvidenceRevisionFixture(): array
{
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active) VALUES (:name, :number, 1)',
        ['name' => 'Evidence revision ' . $marker, 'number' => 'JER' . $marker]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => 'JER' . $marker]);
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :start, :end)',
        ['company_id' => $companyId, 'label' => 'Evidence revision', 'start' => '2026-01-01', 'end' => '2026-12-31']
    );
    $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id', ['company_id' => $companyId]);
    $bankNominalId = journalSourceEvidenceRevisionNominal('1000', 'Evidence bank', 'asset');
    $payableNominalId = journalSourceEvidenceRevisionNominal('2210', 'HMRC Penalties & Interest Payable', 'liability');
    $expenseNominalId = journalSourceEvidenceRevisionNominal('5000', 'Evidence expense', 'expense');
    $hmrcExpenseNominalId = journalSourceEvidenceRevisionNominal('6230', 'HMRC Penalties', 'expense');

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256, workflow_status)
         VALUES (:company_id, :period_id, :month, :original, :stored, :sha, :status)',
        [
            'company_id' => $companyId, 'period_id' => $periodId, 'month' => '2026-05-01',
            'original' => 'evidence-revision.csv', 'stored' => 'evidence-revision.csv',
            'sha' => hash('sha256', $marker), 'status' => 'committed',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn('SELECT id FROM statement_uploads WHERE company_id = :company_id', ['company_id' => $companyId]);
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (company_id, accounting_period_id, statement_upload_id, txn_date, description, amount, dedupe_hash, category_status)
         VALUES (:company_id, :period_id, :upload_id, :date, :description, :amount, :hash, :status)',
        [
            'company_id' => $companyId, 'period_id' => $periodId, 'upload_id' => $uploadId,
            'date' => '2026-05-15', 'description' => 'Evidence revision transaction', 'amount' => '-25.00',
            'hash' => hash('sha256', 'transaction-' . $marker), 'status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE company_id = :company_id', ['company_id' => $companyId]);

    $bankSourceId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'bank_csv', 'transaction:' . $transactionId, '2026-05-15', [
        [$expenseNominalId, 25.00, 0.00], [$bankNominalId, 0.00, 25.00],
    ]);
    $corrections = new \eel_accounts\Service\JournalCorrectionService();
    $bankReversal = $corrections->reverseJournal($companyId, $bankSourceId, $periodId, '2026-05-15', 'Evidence correction.', 'test-suite', 'bank-' . $marker);
    $bankReversalId = (int)($bankReversal['reversal_journal_id'] ?? 0);
    $bankReplacementId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'bank_csv', 'transaction:' . $transactionId . ':revision-of:' . $bankSourceId, '2026-05-15', [
        [$payableNominalId, 25.00, 0.00], [$bankNominalId, 0.00, 25.00],
    ]);
    $harnessLink = $corrections->linkReplacementJournal($companyId, $bankSourceId, $bankReplacementId);
    if (empty($harnessLink['success'])) {
        throw new RuntimeException('Could not create bank replacement evidence fixture.');
    }
    $invalidBankReplacementId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'bank_csv', 'transaction:' . $transactionId . ':revision-of:999999', '2026-05-15', [
        [$payableNominalId, 25.00, 0.00], [$bankNominalId, 0.00, 25.00],
    ]);

    InterfaceDB::prepareExecute('INSERT INTO expense_claimants (company_id, claimant_name, is_active) VALUES (:company_id, :name, 1)', ['company_id' => $companyId, 'name' => 'Evidence claimant']);
    $claimantId = (int)InterfaceDB::fetchColumn('SELECT id FROM expense_claimants WHERE company_id = :company_id', ['company_id' => $companyId]);
    $claimReference = 'ER-' . $marker;
    $expenseSourceId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'expense_register', $claimReference, '2026-05-31', [
        [$expenseNominalId, 30.00, 0.00], [$payableNominalId, 0.00, 30.00],
    ]);
    $expenseReversal = $corrections->reverseJournal($companyId, $expenseSourceId, $periodId, '2026-05-31', 'Evidence correction.', 'test-suite', 'expense-' . $marker);
    $expenseReversalId = (int)($expenseReversal['reversal_journal_id'] ?? 0);
    $expenseReplacementId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'expense_register', $claimReference . ':revision-of:' . $expenseSourceId, '2026-05-31', [
        [$expenseNominalId, 30.00, 0.00], [$payableNominalId, 0.00, 30.00],
    ]);
    $expenseLink = $corrections->linkReplacementJournal($companyId, $expenseSourceId, $expenseReplacementId);
    if (empty($expenseLink['success'])) {
        throw new RuntimeException('Could not create expense replacement evidence fixture.');
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (company_id, accounting_period_id, claimant_id, claim_year, claim_month, period_start, period_end, claim_reference_code, claimed_amount, status, posted_journal_id)
         VALUES (:company_id, :period_id, :claimant_id, 2026, 5, :start, :end, :reference, :amount, :status, :journal_id)',
        [
            'company_id' => $companyId, 'period_id' => $periodId, 'claimant_id' => $claimantId,
            'start' => '2026-05-01', 'end' => '2026-05-31', 'reference' => $claimReference,
            'amount' => '30.00', 'status' => 'posted', 'journal_id' => $expenseReplacementId,
        ]
    );
    $invalidExpenseReplacementId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'expense_register', $claimReference . ':revision-of:999999', '2026-05-31', [
        [$expenseNominalId, 30.00, 0.00], [$payableNominalId, 0.00, 30.00],
    ]);

    $hmrc = (new \eel_accounts\Service\HmrcObligationService())->createManualObligation([
        'company_id' => $companyId, 'accounting_period_id' => $periodId, 'obligation_type' => 'hmrc_penalty',
        'notice_date' => '2026-06-15', 'due_date' => '2026-07-15', 'amount_due' => '40.00',
        'source_reference' => 'ER-HMRC-' . $marker,
    ]);
    $hmrcJournalId = (int)($hmrc['journal_id'] ?? 0);
    $invalidHmrcJournalId = journalSourceEvidenceRevisionJournal($companyId, $periodId, 'manual', 'meta:hmrc_obligation_accrual:' . $periodId . ':obligation_999999', '2026-06-15', [
        [$hmrcExpenseNominalId, 40.00, 0.00], [$payableNominalId, 0.00, 40.00],
    ]);
    journalSourceEvidenceRevisionMetadata($invalidHmrcJournalId, $companyId, $periodId, 'hmrc_obligation_accrual', 'obligation_999999');

    InterfaceDB::prepareExecute('UPDATE journal_lines SET debit = 24.00 WHERE journal_id = :journal_id AND debit > 0', ['journal_id' => $bankReversalId]);
    InterfaceDB::prepareExecute('UPDATE journal_lines SET credit = 24.00 WHERE journal_id = :journal_id AND credit > 0', ['journal_id' => $bankReversalId]);

    $journalIds = [$bankReplacementId, $bankReversalId, $invalidBankReplacementId, $expenseReplacementId, $expenseReversalId, $invalidExpenseReplacementId, $hmrcJournalId, $invalidHmrcJournalId];
    return [
        'company_id' => $companyId,
        'period_id' => $periodId,
        'headers' => journalSourceEvidenceRevisionHeaders($journalIds),
        'valid_ids' => [$bankReplacementId, $expenseReplacementId, $expenseReversalId, $hmrcJournalId],
        'invalid_ids' => [$bankReversalId, $invalidBankReplacementId, $invalidExpenseReplacementId, $invalidHmrcJournalId],
        'bank_replacement_id' => $bankReplacementId,
        'expense_replacement_id' => $expenseReplacementId,
        'hmrc_journal_id' => $hmrcJournalId,
    ];
}

/** @param list<array{0: int, 1: float, 2: float}> $lines */
function journalSourceEvidenceRevisionJournal(int $companyId, int $periodId, string $sourceType, string $sourceRef, string $date, array $lines): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        ['company_id' => $companyId, 'period_id' => $periodId, 'source_type' => $sourceType, 'source_ref' => $sourceRef, 'journal_date' => $date, 'description' => 'Evidence revision fixture']
    );
    $journalId = (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref ORDER BY id DESC LIMIT 1', ['company_id' => $companyId, 'source_ref' => $sourceRef]);
    foreach ($lines as [$nominalId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit, :description)',
            ['journal_id' => $journalId, 'nominal_account_id' => $nominalId, 'debit' => number_format($debit, 2, '.', ''), 'credit' => number_format($credit, 2, '.', ''), 'description' => 'Evidence revision fixture']
        );
    }
    return $journalId;
}

function journalSourceEvidenceRevisionMetadata(int $journalId, int $companyId, int $periodId, string $tag, string $key): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_entry_metadata (journal_id, company_id, accounting_period_id, journal_tag, journal_key, entry_mode)
         VALUES (:journal_id, :company_id, :period_id, :tag, :journal_key, :entry_mode)',
        ['journal_id' => $journalId, 'company_id' => $companyId, 'period_id' => $periodId, 'tag' => $tag, 'journal_key' => $key, 'entry_mode' => 'system_generated']
    );
}

function journalSourceEvidenceRevisionNominal(string $code, string $name, string $accountType): int
{
    $existing = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
    if ($existing > 0) {
        return $existing;
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active)
         VALUES (:code, :name, :account_type, :tax_treatment, 1)',
        ['code' => $code, 'name' => $name, 'account_type' => $accountType, 'tax_treatment' => 'other']
    );
    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}

/** @return list<array<string, mixed>> */
function journalSourceEvidenceRevisionHeaders(array $journalIds): array
{
    return InterfaceDB::fetchAll(
        'SELECT j.id, j.source_type, j.source_ref, j.journal_date, SUM(jl.debit) AS debit_total, SUM(jl.credit) AS credit_total
         FROM journals j INNER JOIN journal_lines jl ON jl.journal_id = j.id
         WHERE j.id IN (' . implode(', ', array_fill(0, count($journalIds), '?')) . ')
         GROUP BY j.id, j.source_type, j.source_ref, j.journal_date',
        $journalIds
    );
}
