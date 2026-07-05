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
$harness->run(\eel_accounts\Service\TaxPeriodService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TaxPeriodService $deriver): void {
    $harness->check(\eel_accounts\Service\TaxPeriodService::class, 'splits long accounting periods into continuous CT periods', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2024-01-01', '2025-03-31');

        $harness->assertCount(2, $periods);
        $harness->assertSame('2024-01-01', $periods[0]['start']);
        $harness->assertSame('2024-12-31', $periods[0]['end']);
        $harness->assertSame('01/01/2024 to 31/12/2024', $periods[0]['label']);
        $harness->assertSame('2025-01-01', $periods[1]['start']);
        $harness->assertSame('2025-03-31', $periods[1]['end']);
    });

    $harness->check(\eel_accounts\Service\TaxPeriodService::class, 'splits the September 2022 to September 2023 accounting period into two CT periods', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2022-09-05', '2023-09-30');

        $harness->assertCount(2, $periods);
        $harness->assertSame('2022-09-05', $periods[0]['start']);
        $harness->assertSame('2023-09-04', $periods[0]['end']);
        $harness->assertSame('05/09/2022 to 04/09/2023', $periods[0]['label']);
        $harness->assertSame('2023-09-05', $periods[1]['start']);
        $harness->assertSame('2023-09-30', $periods[1]['end']);
        $harness->assertSame('05/09/2023 to 30/09/2023', $periods[1]['label']);
    });

    $harness->check(\eel_accounts\Service\TaxPeriodService::class, 'suggests the first accounting period', function () use ($harness, $deriver): void {
        $first = $deriver->suggestFirstPeriod(new DateTimeImmutable('2024-01-15'));

        $harness->assertSame('2024-01-15', $first['start']);
        $harness->assertSame('2025-01-31', $first['end']);
        $harness->assertSame('15/01/2024 to 31/01/2025', $first['label']);
        $harness->assertSame('suggested_first_period', $first['source']);
    });

    $harness->check(\eel_accounts\Service\TaxPeriodService::class, 'uses four digit year codes for canonical labels', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2024-01-01', '2024-12-31');

        $harness->assertSame('01/01/2024 to 31/12/2024', $periods[0]['label']);
        $harness->assertSame(
            '01/10/2023 to 30/09/2024',
            \eel_accounts\Service\TaxPeriodService::accountingPeriodLabel('2023-10-01', '2024-09-30')
        );
    });

    $harness->check(\eel_accounts\Service\TaxPeriodService::class, 'filters out existing suggested periods', function () use ($harness, $deriver): void {
        $first = $deriver->suggestFirstPeriod(new DateTimeImmutable('2024-01-15'));
        $missing = $deriver->missingSuggestedPeriods(
            [
                [
                    'period_start' => '2024-01-15',
                    'period_end' => '2025-01-31',
                ],
            ],
            [
                $first,
                [
                    'start' => '2025-02-01',
                    'end' => '2026-01-31',
                    'label' => '01/02/2025 to 31/01/2026',
                    'source' => 'suggested_follow_on_period',
                ],
            ]
        );

        $harness->assertCount(1, $missing);
        $harness->assertSame('2025-02-01', $missing[0]['start']);
    });
});
