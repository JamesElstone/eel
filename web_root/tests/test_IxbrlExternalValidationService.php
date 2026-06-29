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
    IxbrlExternalValidationService::class,
    static function (GeneratedServiceClassTestHarness $harness, IxbrlExternalValidationService $service): void {
        $harness->check(IxbrlExternalValidationService::class, 'reports error when no run exists', static function () use ($harness, $service): void {
            $result = $service->validateLatestRun(0, 0);
            $harness->assertSame('error', $result['status'] ?? '');
        });

        $harness->check(IxbrlExternalValidationService::class, 'summarises missing external validation as not configured', static function () use ($harness, $service): void {
            $status = $service->externalStatusForRun([]);
            $harness->assertSame('not_configured', $status['status'] ?? '');
            $harness->assertSame(false, $status['blocking'] ?? true);
        });
    }
);
