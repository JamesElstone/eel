<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(TaxPeriodService::class, function (GeneratedServiceClassTestHarness $harness, TaxPeriodService $deriver): void {
    $harness->check(TaxPeriodService::class, 'splits long accounting periods into continuous CT periods', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2024-01-01', '2025-03-31');

        $harness->assertCount(2, $periods);
        $harness->assertSame('2024-01-01', $periods[0]['start']);
        $harness->assertSame('2024-12-31', $periods[0]['end']);
        $harness->assertSame('01/01/24 to 31/12/24', $periods[0]['label']);
        $harness->assertSame('2025-01-01', $periods[1]['start']);
        $harness->assertSame('2025-03-31', $periods[1]['end']);
    });

    $harness->check(TaxPeriodService::class, 'suggests the first accounting period', function () use ($harness, $deriver): void {
        $first = $deriver->suggestFirstPeriod(new DateTimeImmutable('2024-01-15'));

        $harness->assertSame('2024-01-15', $first['start']);
        $harness->assertSame('2025-01-31', $first['end']);
        $harness->assertSame('15/01/24 to 31/01/25', $first['label']);
        $harness->assertSame('suggested_first_period', $first['source']);
    });

    $harness->check(TaxPeriodService::class, 'uses the supplied company date format for labels', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2024-01-01', '2024-12-31', 44, 'Y-m-d');

        $harness->assertSame('2024-01-01 to 2024-12-31', $periods[0]['label']);
    });

    $harness->check(TaxPeriodService::class, 'filters out existing suggested periods', function () use ($harness, $deriver): void {
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
