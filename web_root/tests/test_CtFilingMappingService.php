<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\CtFilingMappingService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CtFilingMappingService $service): void {
    $h->check($service::class, 'exposes independent mapping targets', static function () use ($h): void { $h->assertSame('ct600_rim', \eel_accounts\Service\CtFilingMappingService::TARGET_RIM); $h->assertSame('computation_ixbrl', \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION); });
});
