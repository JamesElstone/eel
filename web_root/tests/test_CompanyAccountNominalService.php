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
    CompanyAccountNominalService::class,
    static function (GeneratedServiceClassTestHarness $harness, CompanyAccountNominalService $service): void {
        $harness->check(CompanyAccountNominalService::class, 'normalises positive nominal ids only', static function () use ($harness, $service): void {
            $harness->assertSame(42, $service->normaliseNominalId('42'));
            $harness->assertSame(null, $service->normaliseNominalId('0'));
            $harness->assertSame(null, $service->normaliseNominalId('abc'));
        });

        $harness->check(CompanyAccountNominalService::class, 'rejects missing company for bulk assignment', static function () use ($harness, $service): void {
            $result = $service->assignMissingNominals(0);

            $harness->assertSame(false, $result['success'] ?? null);
            $harness->assertSame(0, $result['assigned'] ?? null);
        });

        $harness->check(CompanyAccountNominalService::class, 'creates trade account nominals above the default trade nominal', static function () use ($harness, $service): void {
            $companyId = 910027;
            $defaultCode = '2300';
            $defaultId = insertNominalAccountForCompanyAccountNominalTest(
                $defaultCode,
                'Trade Creditors',
                'liability',
                'trade_creditor'
            );
            $settingsStore = new CompanySettingsStore($companyId);
            $settingsStore->set('default_trade_nominal_id', $defaultId, 'int');
            $settingsStore->flush();

            $method = new ReflectionMethod(CompanyAccountNominalService::class, 'createNominalForAccount');
            $method->setAccessible(true);

            $result = $method->invoke($service, [
                'company_id' => $companyId,
                'account_name' => 'CEF',
                'account_type' => CompanyAccountService::TYPE_TRADE,
            ]);

            $harness->assertSame(true, $result['success'] ?? null);
            $harness->assertSame('2301', (string)($result['nominal_code'] ?? ''));
        });

        $harness->check(CompanyAccountNominalService::class, 'treats default trade nominal as needing account-specific assignment', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(CompanyAccountNominalService::class, 'needsNominalAssignment');
            $method->setAccessible(true);

            $needsAssignment = $method->invoke($service, [
                'account_type' => CompanyAccountService::TYPE_TRADE,
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
