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

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\JournalCorrectionService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\JournalCorrectionService::class, 'database rejects direct journal mutations and parent deletion', static function () use ($harness): void {
        if (!in_array(InterfaceDB::driverName(), ['mysql', 'odbc'], true)) {
            $harness->skip('MySQL/MariaDB journal guard triggers are verified by this database test.');
        }

        $triggerCount = (int)InterfaceDB::fetchColumn(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND TRIGGER_NAME IN (
                   'trg_journals_append_only_update', 'trg_journals_append_only_delete',
                   'trg_journal_lines_append_only_update', 'trg_journal_lines_append_only_delete',
                   'trg_journal_metadata_append_only_update', 'trg_journal_metadata_append_only_delete'
               )"
        );
        if ($triggerCount !== 6) {
            $harness->skip('Apply 2026_07_22_004_append_only_journal_guards.sql before running database guard assertions.');
        }

        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['1000', '2000']);
            $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 12);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
                ['name' => 'Append Only Guard Fixture Limited', 'number' => 'AOG' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :number',
                ['number' => 'AOG' . $marker]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                ['company_id' => $companyId, 'label' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
            );
            $periodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id',
                ['company_id' => $companyId]
            );
            $created = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                $companyId,
                $periodId,
                'append_only_guard_test',
                $marker,
                '2025-12-31',
                'Append-only guard test',
                [
                    ['nominal_account_id' => StandardNominalTestFixture::id('1000'), 'debit' => 10.00, 'credit' => 0.00],
                    ['nominal_account_id' => StandardNominalTestFixture::id('2000'), 'debit' => 0.00, 'credit' => 10.00],
                ],
                'system_generated',
                null,
                null,
                'Database guard verification.',
                'test'
            );
            $harness->assertSame(true, (bool)($created['success'] ?? false));
            $journalId = (int)(($created['journal'] ?? [])['id'] ?? 0);
            $lineId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM journal_lines WHERE journal_id = :journal_id ORDER BY id LIMIT 1',
                ['journal_id' => $journalId]
            );

            $rejected = static function (string $sql, array $params = []): bool {
                try {
                    InterfaceDB::prepareExecute($sql, $params);
                    return false;
                } catch (Throwable) {
                    return true;
                }
            };

            $harness->assertTrue($rejected('UPDATE journals SET description = :description WHERE id = :id', ['description' => 'Mutated', 'id' => $journalId]));
            $harness->assertTrue($rejected('DELETE FROM journals WHERE id = :id', ['id' => $journalId]));
            $harness->assertTrue($rejected('UPDATE journal_lines SET debit = 9.00 WHERE id = :id', ['id' => $lineId]));
            $harness->assertTrue($rejected('DELETE FROM journal_lines WHERE id = :id', ['id' => $lineId]));
            $harness->assertTrue($rejected('UPDATE journal_entry_metadata SET notes = :notes WHERE journal_id = :journal_id', ['notes' => 'Mutated', 'journal_id' => $journalId]));
            $harness->assertTrue($rejected('DELETE FROM journal_entry_metadata WHERE journal_id = :journal_id', ['journal_id' => $journalId]));
            $harness->assertTrue($rejected('DELETE FROM accounting_periods WHERE id = :id', ['id' => $periodId]));
            $harness->assertTrue($rejected('DELETE FROM companies WHERE id = :id', ['id' => $companyId]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
