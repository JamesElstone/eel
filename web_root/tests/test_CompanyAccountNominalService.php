<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

ensureCompanyAccountsInternalTransferMarkerForCompanyAccountNominalTest();

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompanyAccountNominalService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CompanyAccountNominalService $service): void {
        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'normalises positive nominal ids only', static function () use ($harness, $service): void {
            $harness->assertSame(42, $service->normaliseNominalId('42'));
            $harness->assertSame(null, $service->normaliseNominalId('0'));
            $harness->assertSame(null, $service->normaliseNominalId('abc'));
        });

        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'rejects missing company for bulk assignment', static function () use ($harness, $service): void {
            $result = $service->assignMissingNominals(0);

            $harness->assertSame(false, $result['success'] ?? null);
            $harness->assertSame(0, $result['assigned'] ?? null);
        });

        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'creates trade account nominals above the default trade nominal', static function () use ($harness, $service): void {
            $companyId = 910027;
            $defaultCode = '2300';
            $defaultId = insertNominalAccountForCompanyAccountNominalTest(
                $defaultCode,
                'Trade Creditors',
                'liability',
                'trade_creditor'
            );
            $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settingsStore->set('default_trade_nominal_id', $defaultId, 'int');
            $settingsStore->flush();

            $method = new ReflectionMethod(\eel_accounts\Service\CompanyAccountNominalService::class, 'createNominalForAccount');
            $method->setAccessible(true);

            $result = $method->invoke($service, [
                'company_id' => $companyId,
                'account_name' => 'CEF',
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
            ]);

            $harness->assertSame(true, $result['success'] ?? null);
            $harness->assertSame('2301', (string)($result['nominal_code'] ?? ''));
        });

        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'tags auto-created account nominals with company account origin', static function () use ($harness): void {
            $companyId = insertCompanyForCompanyAccountNominalTest('Auto Origin Fixture Limited');
            $result = (new \eel_accounts\Service\CompanyAccountService())->createAccount($companyId, [
                'account_name' => 'Fixture Bank',
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'institution_name' => 'Fixture Bank',
                'account_identifier' => 'AUTO-' . strtoupper(substr(hash('sha256', __FUNCTION__ . microtime(true)), 0, 8)),
                'contact_name' => 'Accounts',
                'phone_number' => '01234567890',
                'address_line_1' => '1 Test Street',
                'is_active' => '1',
            ]);

            $harness->assertSame(true, $result['success'] ?? null);

            $origin = InterfaceDB::fetchOne(
                'SELECT origin_type, origin_company_id, origin_company_account_id
                 FROM nominal_accounts
                 WHERE id = :id',
                ['id' => (int)($result['nominal_account_id'] ?? 0)]
            );

            $harness->assertSame('company_account_auto', (string)($origin['origin_type'] ?? ''));
            $harness->assertSame($companyId, (int)($origin['origin_company_id'] ?? 0));
            $harness->assertSame((int)($result['account_id'] ?? 0), (int)($origin['origin_company_account_id'] ?? 0));
        });

        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'leaves manually selected account nominals as manual origin', static function () use ($harness): void {
            $companyId = insertCompanyForCompanyAccountNominalTest('Manual Origin Fixture Limited');
            $manualNominalId = insertNominalAccountForCompanyAccountNominalTest(
                '9M' . substr(hash('sha256', __FUNCTION__ . microtime(true)), 0, 6),
                'Manual Fixture Bank',
                'asset',
                'bank'
            );
            $result = (new \eel_accounts\Service\CompanyAccountService())->createAccount($companyId, [
                'account_name' => 'Manual Bank',
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_BANK,
                'nominal_account_id' => (string)$manualNominalId,
                'institution_name' => 'Manual Bank',
                'account_identifier' => 'MANUAL-' . strtoupper(substr(hash('sha256', __FUNCTION__ . microtime(true)), 0, 8)),
                'contact_name' => 'Accounts',
                'phone_number' => '01234567890',
                'address_line_1' => '1 Test Street',
                'is_active' => '1',
            ]);

            $harness->assertSame(true, $result['success'] ?? null);

            $origin = InterfaceDB::fetchOne(
                'SELECT origin_type, origin_company_id, origin_company_account_id
                 FROM nominal_accounts
                 WHERE id = :id',
                ['id' => $manualNominalId]
            );

            $harness->assertSame('manual', (string)($origin['origin_type'] ?? ''));
            $harness->assertSame(0, (int)($origin['origin_company_id'] ?? 0));
            $harness->assertSame(0, (int)($origin['origin_company_account_id'] ?? 0));
        });

        $harness->check(\eel_accounts\Service\CompanyAccountNominalService::class, 'treats default trade nominal as needing account-specific assignment', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\CompanyAccountNominalService::class, 'needsNominalAssignment');
            $method->setAccessible(true);

            $needsAssignment = $method->invoke($service, [
                'account_type' => \eel_accounts\Service\CompanyAccountService::TYPE_TRADE,
                'nominal_account_id' => 77,
                'nominal_code' => '2300',
                'nominal_account_type' => 'liability',
                'nominal_subtype_code' => 'trade_creditor',
                'nominal_is_active' => 1,
            ], 0, 77);

            $harness->assertSame(true, $needsAssignment);
        });
    }
);

function insertCompanyForCompanyAccountNominalTest(string $name): int
{
    $number = 'CA' . strtoupper(substr(hash('sha256', $name . microtime(true)), 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
        ['name' => $name, 'number' => $number]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => $number]);
}

function ensureCompanyAccountsInternalTransferMarkerForCompanyAccountNominalTest(): void
{
    if (InterfaceDB::driverName() !== 'sqlite') {
        return;
    }

    $columns = InterfaceDB::fetchAll('PRAGMA table_info(company_accounts)');
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'internal_transfer_marker') {
            return;
        }
    }

    InterfaceDB::prepareExecute('ALTER TABLE company_accounts ADD COLUMN internal_transfer_marker TEXT DEFAULT NULL');
}

function insertNominalAccountForCompanyAccountNominalTest(string $code, string $name, string $accountType, ?string $subtypeCode = null): int
{
    $existingId = InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );

    if ((int)$existingId > 0) {
        return (int)$existingId;
    }

    $subtypeId = null;
    if ($subtypeCode !== null) {
        $subtypeId = InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
            ['code' => $subtypeCode]
        );
        $subtypeId = (int)$subtypeId > 0 ? (int)$subtypeId : null;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$code, $name, $accountType, $subtypeId, 'other', 1, (int)$code]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}
