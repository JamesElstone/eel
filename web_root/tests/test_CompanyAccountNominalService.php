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
    }
);
