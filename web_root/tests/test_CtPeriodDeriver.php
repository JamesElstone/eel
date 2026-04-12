<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(CtPeriodDeriver::class, function (GeneratedServiceClassTestHarness $harness, CtPeriodDeriver $deriver): void {
    $harness->check(CtPeriodDeriver::class, 'splits long accounting periods into continuous CT periods', function () use ($harness, $deriver): void {
        $periods = $deriver->derive('2024-01-01', '2025-03-31');

        $harness->assertCount(2, $periods);
        $harness->assertSame('2024-01-01', $periods[0]['start']);
        $harness->assertSame('2024-12-31', $periods[0]['end']);
        $harness->assertSame('2025-01-01', $periods[1]['start']);
        $harness->assertSame('2025-03-31', $periods[1]['end']);
    });
});
