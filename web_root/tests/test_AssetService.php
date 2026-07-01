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
    \eel_accounts\Service\AssetService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\AssetService $service): void {
        $harness->check(\eel_accounts\Service\AssetService::class, 'fixed asset schema is available', static function () use ($harness, $service): void {
            $harness->assertTrue(InterfaceDB::tableExists('asset_register'));
            $harness->assertTrue(InterfaceDB::tableExists('asset_depreciation_entries'));

            $pageData = $service->fetchPageData(0, 0);
            $harness->assertSame(true, $pageData['schema_ready'] ?? false);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'normalises blank default bank nominal from settings context', static function () use ($harness, $service): void {
            $pageData = $service->fetchPageData(0, 0, '');

            $harness->assertSame(0, $pageData['default_bank_nominal_id'] ?? null);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'journal source enum supports asset postings', static function () use ($harness): void {
            if (InterfaceDB::driverName() === 'sqlite') {
                $schemaPath = PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql';
                $columnType = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
            } else {
                $columnType = (string)InterfaceDB::fetchColumn(
                    'SELECT COLUMN_TYPE
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND COLUMN_NAME = :column_name
                     LIMIT 1',
                    ['table_name' => 'journals', 'column_name' => 'source_type']
                );
            }

            foreach (['asset_register', 'asset_depreciation', 'asset_disposal'] as $sourceType) {
                $harness->assertTrue(str_contains($columnType, "'" . $sourceType . "'"));
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'none depreciation method posts no depreciation', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'calculateDepreciationAmount');
            $method->setAccessible(true);

            $amount = $method->invoke($service, [
                'id' => 0,
                'depreciation_method' => 'none',
                'cost' => 1200,
                'residual_value' => 100,
                'useful_life_years' => 4,
            ], '2026-01-01', '2026-12-31');

            $harness->assertSame(0.0, $amount);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual asset offset nominal must be a balance sheet account', static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('nominal_accounts')) {
                $harness->skip('Nominal accounts table is not available on the default InterfaceDB connection.');
            }

            $balanceSheetNominalId = assetServiceTestInsertNominal('AST', 'Asset Offset Candidate', 'asset');
            $expenseNominalId = assetServiceTestInsertNominal('EXP', 'Expense Offset Candidate', 'expense');

            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'isManualAssetOffsetNominal');
            $method->setAccessible(true);

            $harness->assertSame(true, $method->invoke($service, $balanceSheetNominalId));
            $harness->assertSame(false, $method->invoke($service, $expenseNominalId));
        });
    }
);

function assetServiceTestInsertNominal(string $prefix, string $name, string $accountType): int
{
    $code = $prefix . strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name . ' ' . $code,
            'account_type' => $accountType,
            'tax_treatment' => 'other',
            'sort_order' => 9900,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}
