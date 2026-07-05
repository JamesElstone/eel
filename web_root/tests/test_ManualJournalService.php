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

$harness->run(\eel_accounts\Service\ManualJournalService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\ManualJournalService $service): void {
    $harness->check(\eel_accounts\Service\ManualJournalService::class, 'rejects unbalanced journal lines before saving', static function () use ($harness, $service): void {
        $result = $service->saveTaggedJournal(
            1,
            1,
            'opening_balance',
            'primary',
            '2025-01-01',
            'Opening balances',
            [
                [
                    'nominal_account_id' => 1,
                    'debit' => '100.00',
                    'credit' => '0.00',
                ],
                [
                    'nominal_account_id' => 2,
                    'debit' => '0.00',
                    'credit' => '90.00',
                ],
            ]
        );

        $harness->assertSame(false, !empty($result['success']));
        $harness->assertTrue(in_array('Total debits must equal total credits before the journal can be saved.', (array)($result['errors'] ?? []), true));
    });

    $harness->check(\eel_accounts\Service\ManualJournalService::class, 'appends repeated tagged journals without deleting the original', static function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('71' . $marker);
            $periodId = (int)('72' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (id, company_name, company_number, is_active)
                 VALUES (:id, :company_name, :company_number, 1)',
                ['id' => $companyId, 'company_name' => 'Manual Journal Append ' . $marker, 'company_number' => 'MJ' . $marker]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                ['id' => $periodId, 'company_id' => $companyId, 'label' => 'Append FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
            );
            $debitNominalId = manualJournalTestInsertNominal('MJD' . $marker, 'Manual Journal Debit ' . $marker, 'expense');
            $creditNominalId = manualJournalTestInsertNominal('MJC' . $marker, 'Manual Journal Credit ' . $marker, 'liability');
            $lines = [
                ['nominal_account_id' => $debitNominalId, 'debit' => '10.00', 'credit' => '0.00'],
                ['nominal_account_id' => $creditNominalId, 'debit' => '0.00', 'credit' => '10.00'],
            ];

            $first = $service->saveTaggedJournal($companyId, $periodId, 'append_test', 'primary', '2026-12-31', 'Append one', $lines);
            $second = $service->saveTaggedJournal($companyId, $periodId, 'append_test', 'primary', '2026-12-31', 'Append two', $lines);

            $harness->assertSame(true, (bool)($first['success'] ?? false));
            $harness->assertSame(true, (bool)($second['success'] ?? false));
            $harness->assertSame(true, (bool)($second['appended_to_existing'] ?? false));
            $journals = $service->listJournalsByTags($companyId, $periodId, ['append_test']);
            $harness->assertSame(2, count($journals));
            $latest = $service->fetchJournalByTag($companyId, $periodId, 'append_test', 'primary');
            $harness->assertTrue(is_array($latest));
            $harness->assertSame('Append two', (string)($latest['description'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function manualJournalTestInsertNominal(string $code, string $name, string $type): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, is_active)
         VALUES (:code, :name, :account_type, 1)',
        ['code' => $code, 'name' => $name, 'account_type' => $type]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => $code]);
}
