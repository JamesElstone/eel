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
    \eel_accounts\Service\TaxGuidanceService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Service\TaxGuidanceService::class, 'returns configured and fallback guidance URLs', static function () use ($harness): void {
            $all = \eel_accounts\Service\TaxGuidanceService::all();

            $harness->assertTrue(isset($all['capital_allowances']));
            $harness->assertSame($all['capital_allowances'], \eel_accounts\Service\TaxGuidanceService::url('capital_allowances'));
            $harness->assertSame($all['company_tax_returns'], \eel_accounts\Service\TaxGuidanceService::url('missing-guidance-key'));
        });
    }
);
