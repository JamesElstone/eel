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
    FirstUserBootstrapService::class,
    static function (GeneratedServiceClassTestHarness $harness, FirstUserBootstrapService $service): void {
        $harness->check(FirstUserBootstrapService::class, 'validates bootstrap code state without touching files', static function () use ($harness, $service): void {
            $state = ['code' => 'ABCD 1234 EFAB 5678'];

            $harness->assertSame(null, $service->validateCode('ABCD 1234 EFAB 5678', $state));
            $harness->assertSame('Bootstrap code was not recognised.', $service->validateCode('ABCD 1234 EFAB 0000', $state));
        });
    }
);
