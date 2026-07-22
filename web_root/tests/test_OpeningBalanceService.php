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
$harness->run(\eel_accounts\Service\OpeningBalanceService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\OpeningBalanceService $service): void {
    $harness->check(\eel_accounts\Service\OpeningBalanceService::class, 'replace preserves and reverses the existing opening balance before saving replacement', static function () use ($harness, $service): void {
        openingBalanceServiceRequireTables($harness);

        \InterfaceDB::beginTransaction();
        try {
            openingBalanceServiceEnsureNominal('1000', 'Bank', 'asset');
            openingBalanceServiceEnsureNominal('3000', 'Retained Earnings', 'equity');

            $fixture = openingBalanceServiceCreateFixture();
            $lines = [
                ['nominal_account_id' => (int)$fixture['bank_nominal_id'], 'debit' => '100.00', 'credit' => '0.00'],
                ['nominal_account_id' => (int)$fixture['equity_nominal_id'], 'debit' => '0.00', 'credit' => '100.00'],
            ];

            $first = $service->saveOpeningBalance((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], [
                'description' => 'Opening balance one',
                'replace_existing' => false,
                'lines' => $lines,
            ], 'test');
            $harness->assertSame(true, (bool)($first['success'] ?? false));

            $blocked = $service->saveOpeningBalance((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], [
                'description' => 'Opening balance blocked duplicate',
                'replace_existing' => false,
                'lines' => $lines,
            ], 'test');
            $harness->assertSame(false, (bool)($blocked['success'] ?? true));

            $second = $service->saveOpeningBalance((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], [
                'description' => 'Opening balance replacement',
                'replace_existing' => true,
                'lines' => $lines,
            ], 'test');
            $harness->assertSame(true, (bool)($second['success'] ?? false));
            $harness->assertSame(true, (bool)($second['replaced_existing'] ?? false));

            $rows = \InterfaceDB::fetchAll(
                'SELECT j.id, j.description, j.is_posted
                 FROM journal_entry_metadata jem
                 INNER JOIN journals j ON j.id = jem.journal_id
                 WHERE jem.company_id = :company_id
                   AND jem.accounting_period_id = :accounting_period_id
                   AND jem.journal_tag = :journal_tag
                 ORDER BY j.id ASC',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => 'opening_balance',
                ]
            );

            $harness->assertSame(2, count($rows));
            $harness->assertSame(2, count(array_filter($rows, static fn(array $row): bool => (int)($row['is_posted'] ?? 0) === 1)));
            $harness->assertSame('Opening balance replacement', (string)($rows[1]['description'] ?? ''));
            $reversal = \InterfaceDB::fetchOne(
                'SELECT source_journal_id, reversal_journal_id, replacement_journal_id
                 FROM journal_reversals WHERE source_journal_id = :source_journal_id',
                ['source_journal_id' => (int)$rows[0]['id']]
            );
            $harness->assertTrue(is_array($reversal));
            $harness->assertTrue((int)($reversal['reversal_journal_id'] ?? 0) > 0);
            $harness->assertSame((int)$rows[1]['id'], (int)($reversal['replacement_journal_id'] ?? 0));
        } finally {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
        }
    });
});

function openingBalanceServiceRequireSchema(GeneratedServiceClassTestHarness $harness): void
{
    openingBalanceServiceRequireTables($harness);

    foreach (['1000', '3000'] as $code) {
        if (openingBalanceServiceNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }
}

function openingBalanceServiceRequireTables(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'journal_entry_metadata', 'year_end_reviews'] as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function openingBalanceServiceCreateFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('81' . $marker);
    $periodId = (int)('82' . $marker);

    \InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        ['id' => $companyId, 'company_name' => 'Opening Balance Fixture ' . $marker, 'company_number' => 'OB' . $marker]
    );
    \InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        ['id' => $periodId, 'company_id' => $companyId, 'label' => 'Opening Balance FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $periodId,
        'bank_nominal_id' => openingBalanceServiceNominalId('1000'),
        'equity_nominal_id' => openingBalanceServiceNominalId('3000'),
    ];
}

function openingBalanceServiceNominalId(string $code): int
{
    return (int)(\InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code AND is_active = 1 LIMIT 1',
        ['code' => $code]
    ) ?: 0);
}

function openingBalanceServiceEnsureNominal(string $code, string $name, string $accountType): int
{
    $existingId = openingBalanceServiceNominalId($code);
    if ($existingId > 0) {
        return $existingId;
    }

    \InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, is_active)
         VALUES (:code, :name, :account_type, 1)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
        ]
    );

    return openingBalanceServiceNominalId($code);
}
