<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimApplicabilityService $service): void {
    $harness->check(\eel_accounts\Service\HmrcCtRimApplicabilityService::class, 'does not invent applicability for an unknown form', static function () use ($harness, $service): void {
        $harness->assertSame(['applicable_from' => null, 'applicable_to' => null], $service->forFormVersion('V4'));
    });
});
