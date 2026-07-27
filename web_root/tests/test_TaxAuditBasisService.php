<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->run(\eel_accounts\Service\TaxAuditBasisService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\TaxAuditBasisService $service
): void {
    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'publishes a fixed audit area catalogue', static function () use ($harness): void {
        $catalogue = \eel_accounts\Service\TaxAuditBasisService::areaCatalogue();
        $harness->assertSame([
            'accounting_profit',
            'expense_treatments',
            'depreciation_capital',
            'capital_allowances',
            'losses',
            'tax_liability',
        ], array_keys($catalogue));
        $harness->assertSame(true, \eel_accounts\Service\TaxAuditBasisService::isSupportedArea('losses'));
        $harness->assertSame(false, \eel_accounts\Service\TaxAuditBasisService::isSupportedArea('journals; DELETE'));
    });

    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'does not load detail until an area is selected', static function () use ($harness, $service): void {
        $result = $service->fetchAreaDetail(1, 1, 1, '');
        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertSame(true, (bool)($result['empty_selection'] ?? false));
        $harness->assertSame([], $result['errors'] ?? null);
    });

    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'rejects arbitrary detail area input before querying period data', static function () use ($harness, $service): void {
        $result = $service->fetchAreaDetail(1, 1, 1, 'not_a_real_area');
        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertTrue(str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'not supported'));
    });

    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'requires an owned CT period for the lightweight index', static function () use ($harness, $service): void {
        $result = $service->fetchAreaIndex(0, 0, 0);
        $harness->assertSame(false, (bool)($result['available'] ?? true));
        $harness->assertTrue(str_contains((string)(($result['errors'] ?? [])[0] ?? ''), 'Select a company'));
    });

    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'snapshot persistence is restricted to the Year End transaction', static function () use ($harness, $service): void {
        $thrown = false;
        try {
            $service->persistSnapshot(1, 1, 1, 1, []);
        } catch (RuntimeException $exception) {
            $thrown = str_contains($exception->getMessage(), 'Year End lock transaction');
        }
        $harness->assertSame(true, $thrown);
    });

    $harness->check(\eel_accounts\Service\TaxAuditBasisService::class, 'does not count AIA again when the asset is disposed in the same period', static function () use ($harness, $service): void {
        $aia = [
            'asset_id' => 15,
            'asset_code' => 'FA-15',
            'description' => 'DeWalt diamond drill',
            'purchase_date' => '2024-08-02',
            'cost' => 67.39,
            'pool_type' => 'main_pool',
            'allowance_type' => 'aia',
            'addition_amount' => 67.39,
            'allowance_amount' => 67.39,
            'disposal_value' => 0.00,
        ];
        $disposal = array_replace($aia, [
            'allowance_type' => 'aia, disposal_value',
            'disposal_date' => '2024-08-08',
            'disposal_proceeds' => 0.00,
        ]);
        $method = new ReflectionMethod($service::class, 'capitalAllowanceRows');
        $method->setAccessible(true);
        $rows = (array)$method->invoke($service, [
            'aia_allocation' => [$aia],
            'disposals_balancing' => [$disposal],
        ]);

        $harness->assertSame(2, count($rows));
        $harness->assertSame(
            ['aia_allocation', 'disposal_balancing'],
            array_map(
                static fn(array $row): string => (string)($row['metadata']['audit_component'] ?? ''),
                $rows
            )
        );
        $harness->assertSame(
            ['67.39', '0.00'],
            array_map(
                static fn(array $row): string => number_format((float)($row['tax_adjustment_amount'] ?? 0), 2, '.', ''),
                $rows
            )
        );
        $harness->assertSame(
            '67.39',
            number_format(array_sum(array_column($rows, 'tax_adjustment_amount')), 2, '.', '')
        );
        $harness->assertSame(
            [],
            array_values(array_filter(
                $rows,
                static fn(array $row): bool => (string)($row['source_type'] ?? '') === 'calculation_reconciliation'
            ))
        );
    });
});
