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
$harness->run(\eel_accounts\Service\YearEndCompaniesHouseComparisonService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndCompaniesHouseComparisonService::class, 'selects only an exact reporting-period filing for numeric comparison', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndCompaniesHouseComparisonService();
        $findExact = new ReflectionMethod($service, 'findExactSummary');
        $findExact->setAccessible(true);

        $summaries = [
            ['id' => 1, 'filing_date' => '2025-06-28', 'latest_year_period_start' => '2023-10-01', 'latest_year_period_end' => '2024-09-30'],
            ['id' => 2, 'filing_date' => '2026-06-28', 'latest_year_period_start' => '2024-10-01', 'latest_year_period_end' => '2025-09-30'],
        ];

        $harness->assertSame(null, $findExact->invoke($service, [$summaries[0]], '2025-09-30'));
        $exact = $findExact->invoke($service, $summaries, '2025-09-30');
        $harness->assertSame(2, (int)($exact['id'] ?? 0));
        $harness->assertSame('2025-09-30', (string)($exact['period_end'] ?? ''));
    });
});
