<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\DividendViewDataService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\DividendViewDataService $service): void {
    $harness->check(\eel_accounts\Service\DividendViewDataService::class, 'returns stable unavailable context without a selected company and period', static function () use ($harness, $service): void {
        $context = $service->fetchCapacityContext(0, 0);

        $harness->assertSame(false, (bool)($context['capacity']['available'] ?? true));
        $harness->assertSame(
            'Select a company and accounting period before reviewing dividends.',
            (string)($context['capacity']['errors'][0] ?? '')
        );
        $harness->assertSame(false, (bool)($context['reserve_review']['available'] ?? true));
        $harness->assertSame([], (array)($context['warnings'] ?? []));
        $harness->assertSame(false, (bool)($context['is_locked'] ?? true));
    });

    $harness->check(\eel_accounts\Service\DividendViewDataService::class, 'combines delegated capacity reserve warnings and lock state for a missing period', static function () use ($harness, $service): void {
        $context = $service->fetchCapacityContext(99999991, 99999992);

        $harness->assertSame(false, (bool)($context['capacity']['available'] ?? true));
        $harness->assertTrue(str_contains((string)($context['capacity']['errors'][0] ?? ''), 'could not be found'));
        $harness->assertSame(false, (bool)($context['reserve_review']['available'] ?? true));
        $harness->assertTrue(is_array($context['warnings'] ?? null));
        $harness->assertSame(false, (bool)($context['is_locked'] ?? true));
    });
});
