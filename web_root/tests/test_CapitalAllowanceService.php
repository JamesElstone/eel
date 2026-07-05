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
    \eel_accounts\Service\CapitalAllowanceService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CapitalAllowanceService $service): void {
        $harness->check(\eel_accounts\Service\CapitalAllowanceService::class, 'returns no runs for an invalid company', static function () use ($harness, $service): void {
            $harness->assertSame([], $service->rebuildForCompany(0));
        });

        $harness->check(\eel_accounts\Service\CapitalAllowanceService::class, 'returns unavailable breakdown for invalid context', static function () use ($harness, $service): void {
            $breakdown = $service->fetchPeriodBreakdown(0, 0);

            $harness->assertSame(false, (bool)($breakdown['available'] ?? true));
            $harness->assertSame([], (array)($breakdown['rows'] ?? ['unexpected']));
            $harness->assertSame([], (array)($breakdown['warnings'] ?? ['unexpected']));
        });
    }
);
