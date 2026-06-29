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
$harness->run(CompanyRepository::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(CompanyRepository::class, 'fetchCompanyDetails returns null for non-positive ids', function () use ($harness): void {
        $repository = new CompanyRepository();

        $harness->assertSame(null, $repository->fetchCompanyDetails(0));
    });

    $harness->check(CompanyRepository::class, 'normalises Companies House profile fields for storage', function () use ($harness): void {
        $repository = new CompanyRepository();
        $result = $repository->normaliseCompaniesHouseProfileForStorage([
            'company_status' => 'active',
            'type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'can_file' => true,
            'registered_office_address' => [
                'address_line_1' => '1 Test Street',
                'postal_code' => 'AB1 2CD',
            ],
        ], 'LIVE');

        $harness->assertSame('active', $result['company_status'] ?? null);
        $harness->assertSame('ltd', $result['companies_house_type'] ?? null);
        $harness->assertSame('england-wales', $result['companies_house_jurisdiction'] ?? null);
        $harness->assertSame('1 Test Street', $result['registered_office_address_line_1'] ?? null);
        $harness->assertSame('AB1 2CD', $result['registered_office_postal_code'] ?? null);
        $harness->assertSame(1, $result['can_file'] ?? null);
        $harness->assertSame('LIVE', $result['companies_house_environment'] ?? null);
    });

    $harness->check(CompanyRepository::class, 'deletes unreferenced auto-created company account nominals', function () use ($harness): void {
        $repository = new CompanyRepository();
        $fixture = companyRepositoryNominalDeleteFixture('Delete Auto Nominal Fixture Limited');

        $result = $repository->deleteCompany((int)$fixture['company_id']);

        $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM companies WHERE id = :id', ['id' => $fixture['company_id']]));
        $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM nominal_accounts WHERE id = :id', ['id' => $fixture['nominal_id']]));
        $harness->assertSame(1, (int)(($result['auto_nominals'] ?? [])['deleted'] ?? 0));
        $harness->assertSame(0, (int)(($result['auto_nominals'] ?? [])['skipped'] ?? 0));
    });

    $harness->check(CompanyRepository::class, 'retains manual shared nominals when deleting a company', function () use ($harness): void {
        $repository = new CompanyRepository();
        $fixture = companyRepositoryNominalDeleteFixture('Retain Manual Nominal Fixture Limited', 'manual');

        $result = $repository->deleteCompany((int)$fixture['company_id']);

        $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM nominal_accounts WHERE id = :id', ['id' => $fixture['nominal_id']]));
        $harness->assertSame(0, (int)(($result['auto_nominals'] ?? [])['deleted'] ?? 0));
    });

    $harness->check(CompanyRepository::class, 'skips auto-created company account nominals still referenced elsewhere', function () use ($harness): void {
        $repository = new CompanyRepository();
        $fixture = companyRepositoryNominalDeleteFixture('Skip Referenced Nominal Fixture Limited');
        $otherCompanyId = companyRepositoryInsertCompany('Other Referencer Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (company_id, match_value, nominal_account_id)
             VALUES (:company_id, :match_value, :nominal_id)',
            [
                'company_id' => $otherCompanyId,
                'match_value' => 'fixture-reference',
                'nominal_id' => (int)$fixture['nominal_id'],
            ]
        );

        $result = $repository->deleteCompany((int)$fixture['company_id']);

        $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM nominal_accounts WHERE id = :id', ['id' => $fixture['nominal_id']]));
        $harness->assertSame(0, (int)(($result['auto_nominals'] ?? [])['deleted'] ?? 0));
        $harness->assertSame(1, (int)(($result['auto_nominals'] ?? [])['skipped'] ?? 0));
    });
});

function companyRepositoryNominalDeleteFixture(string $companyName, string $originType = 'company_account_auto'): array
{
    $companyId = companyRepositoryInsertCompany($companyName);
    $subtypeId = companyRepositoryNominalSubtype('bank', 'Bank', 'asset');
    $code = '9D' . strtoupper(substr(hash('sha256', $companyName . microtime(true)), 0, 6));

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order, origin_type, origin_company_id)
         VALUES (:code, :name, :account_type, :subtype_id, :tax_treatment, 1, :sort_order, :origin_type, :origin_company_id)',
        [
            'code' => $code,
            'name' => $companyName . ' Bank',
            'account_type' => 'asset',
            'subtype_id' => $subtypeId,
            'tax_treatment' => 'other',
            'sort_order' => 9000,
            'origin_type' => $originType,
            'origin_company_id' => $originType === 'company_account_auto' ? $companyId : null,
        ]
    );
    $nominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $code]);

    InterfaceDB::prepareExecute(
        'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id)
         VALUES (:company_id, :account_name, :account_type, :nominal_id)',
        [
            'company_id' => $companyId,
            'account_name' => $companyName . ' Bank',
            'account_type' => 'bank',
            'nominal_id' => $nominalId,
        ]
    );
    $accountId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_accounts WHERE company_id = :company_id AND nominal_account_id = :nominal_id',
        ['company_id' => $companyId, 'nominal_id' => $nominalId]
    );

    if ($originType === 'company_account_auto') {
        InterfaceDB::prepareExecute(
            'UPDATE nominal_accounts
             SET origin_company_account_id = :account_id
             WHERE id = :nominal_id',
            ['account_id' => $accountId, 'nominal_id' => $nominalId]
        );
    }

    return [
        'company_id' => $companyId,
        'account_id' => $accountId,
        'nominal_id' => $nominalId,
    ];
}

function companyRepositoryInsertCompany(string $companyName): int
{
    $number = 'CR' . strtoupper(substr(hash('sha256', $companyName . microtime(true)), 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
        ['name' => $companyName, 'number' => $number]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => $number]);
}

function companyRepositoryNominalSubtype(string $code, string $name, string $parentAccountType): int
{
    $id = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_account_subtypes WHERE code = :code', ['code' => $code]);
    if ($id > 0) {
        return $id;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
         VALUES (:code, :name, :parent_account_type, :sort_order, 1)',
        ['code' => $code, 'name' => $name, 'parent_account_type' => $parentAccountType, 'sort_order' => 10]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_account_subtypes WHERE code = :code', ['code' => $code]);
}
