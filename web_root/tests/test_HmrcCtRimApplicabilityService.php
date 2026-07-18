<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimApplicabilityService $service): void {
    $harness->check(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, 'provides V2 applicability', static function () use ($harness, $service): void {
        $harness->assertSame(['applicable_from' => '1900-01-01', 'applicable_to' => '2015-03-31'], $service->forFormVersion('V2'));
    });

    $harness->check(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, 'provides V3 applicability', static function () use ($harness, $service): void {
        $harness->assertSame(['applicable_from' => '2015-04-01', 'applicable_to' => null], $service->forFormVersion('V3'));
    });

    $harness->check(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, 'does not activate unknown future forms', static function () use ($harness, $service): void {
        $harness->assertSame(['applicable_from' => null, 'applicable_to' => null], $service->forFormVersion('V4'));
    });
});
