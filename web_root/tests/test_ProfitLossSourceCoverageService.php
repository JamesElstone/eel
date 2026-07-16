<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\ProfitLossService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ProfitLossService $service): void {
        $harness->check(\eel_accounts\Service\ProfitLossService::class, 'does not count metadata-only or unknown posted sources as independently covered', static function () use ($harness, $service): void {
            foreach (['companies', 'accounting_periods', 'journals', 'journal_lines', 'journal_entry_metadata', 'nominal_accounts'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }

            InterfaceDB::beginTransaction();
            try {
                $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                    ['company_name' => 'P&L Source Coverage Fixture', 'company_number' => 'PSC' . $marker]
                );
                $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'PSC' . $marker]);
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end) VALUES (:company_id, :label, :period_start, :period_end)',
                    ['company_id' => $companyId, 'label' => 'Coverage ' . $marker, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
                );
                $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'Coverage ' . $marker]);
                StandardNominalTestFixture::ensureNominals(['1000']);
                $nominalId = StandardNominalTestFixture::id('1000');

                $manualJournalId = profitLossSourceCoverageJournal(
                    $companyId,
                    $periodId,
                    $nominalId,
                    'manual',
                    'meta:source_coverage:primary',
                    183.63
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_entry_metadata (
                        journal_id, company_id, accounting_period_id,
                        journal_tag, journal_key, entry_mode
                     ) VALUES (
                        :journal_id, :company_id, :accounting_period_id,
                        :journal_tag, :journal_key, :entry_mode
                     )',
                    [
                        'journal_id' => $manualJournalId,
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'journal_tag' => 'source_coverage',
                        'journal_key' => 'primary',
                        'entry_mode' => 'system',
                    ]
                );
                profitLossSourceCoverageJournal($companyId, $periodId, $nominalId, 'future_source', 'future-source-' . $marker, 25.00);

                $coverage = $service->getSourceCoverage($companyId, $periodId);
                $harness->assertSame(1, (int)($coverage['manual']['journal_count'] ?? 0));
                $harness->assertSame(1, (int)($coverage['other']['journal_count'] ?? 0));
                $harness->assertSame(2, (int)($coverage['coverage_summary']['posted_journal_count'] ?? 0));
                $harness->assertSame(0, (int)($coverage['coverage_summary']['covered_journal_count'] ?? -1));
                $harness->assertSame(2, (int)($coverage['coverage_summary']['uncovered_journal_count'] ?? -1));
                $harness->assertSame(false, (bool)($coverage['coverage_summary']['reconciled'] ?? true));
                $harness->assertCount(2, (array)($coverage['coverage_summary']['evidence_failures'] ?? []));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

function profitLossSourceCoverageJournal(int $companyId, int $periodId, int $nominalId, string $sourceType, string $sourceRef, float $amount): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => $companyId,
            'period_id' => $periodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => '2026-06-30',
            'description' => 'Source coverage fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => $companyId, 'source_ref' => $sourceRef]
    );
    foreach ([[number_format($amount, 2, '.', ''), '0.00'], ['0.00', number_format($amount, 2, '.', '')]] as [$debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
            ['journal_id' => $journalId, 'nominal_id' => $nominalId, 'debit' => $debit, 'credit' => $credit, 'description' => 'Source coverage fixture']
        );
    }

    return $journalId;
}
