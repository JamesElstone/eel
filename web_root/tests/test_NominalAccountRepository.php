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
$harness->run(\eel_accounts\Repository\NominalAccountRepository::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Repository\NominalAccountRepository $repository): void {
    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'deletes unused nominal accounts', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Unused Delete Fixture');

        $harness->assertSame(0, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(true, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(true, $repository->deleteNominalAccountIfUnused($nominalId));
        $harness->assertSame(null, $repository->findById($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts assigned as company defaults', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Default Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Default Reference Fixture Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO company_settings (company_id, setting, type, value)
             VALUES (:company_id, :setting, :type, :value)',
            [
                'company_id' => $companyId,
                'setting' => 'default_bank_nominal_id',
                'type' => 'int',
                'value' => (string)$nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
        $harness->assertSame(true, $repository->findById($nominalId) !== null);
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts assigned to bank or trade accounts', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Account Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Account Reference Fixture Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id)
             VALUES (:company_id, :account_name, :account_type, :nominal_id)',
            [
                'company_id' => $companyId,
                'account_name' => 'Fixture Bank',
                'account_type' => 'bank',
                'nominal_id' => $nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'catalog still renders when an optional reference table is absent', function () use ($harness): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Missing Reference Table Fixture');

        InterfaceDB::execute('DROP TABLE IF EXISTS corporation_tax_treatment_rules');

        $repository = new \eel_accounts\Repository\NominalAccountRepository();
        $catalog = $repository->fetchNominalAccountCatalog();
        $fixtureRow = null;

        foreach ($catalog as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $nominalId) {
                $fixtureRow = $row;
                break;
            }
        }

        $harness->assertSame(true, $fixtureRow !== null);
        $harness->assertSame(0, (int)($fixtureRow['can_delete'] ?? 1));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });
});

function nominalAccountRepositoryInsertNominal(string $name): int
{
    $code = '9N' . strtoupper(substr(hash('sha256', $name . microtime(true)), 0, 6));

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => 'asset',
            'tax_treatment' => 'other',
            'sort_order' => 9000,
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $code]);
}

function nominalAccountRepositoryInsertCompany(string $companyName): int
{
    $number = 'NR' . strtoupper(substr(hash('sha256', $companyName . microtime(true)), 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
        ['name' => $companyName, 'number' => $number]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => $number]);
}
