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

$harness->run(\eel_accounts\Service\DividendService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\DividendService $service): void {
    $harness->check(\eel_accounts\Service\DividendService::class, 'prepares dividend nominal accounts with numeric codes', function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('nominal_account_subtypes')) {
            $harness->skip('Nominal tables are not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();
        try {
            $result = $service->ensureDividendNominals(1);

            $harness->assertSame(true, (bool)($result['available'] ?? false));
            $harness->assertSame([], (array)($result['errors'] ?? []));

            $accounts = (array)($result['accounts'] ?? []);
            $harness->assertSame('3000', (string)($accounts['retained_earnings']['code'] ?? ''));
            $harness->assertSame('3100', (string)($accounts['dividends_paid']['code'] ?? ''));
            $harness->assertSame('2150', (string)($accounts['dividends_payable']['code'] ?? ''));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
