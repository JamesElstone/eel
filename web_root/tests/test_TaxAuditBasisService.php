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
});
