<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\CtFilingMappingService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CtFilingMappingService $service): void {
    $h->check($service::class, 'exposes independent mapping targets', static function () use ($h): void { $h->assertSame('ct600_rim', \eel_accounts\Service\CtFilingMappingService::TARGET_RIM); $h->assertSame('computation_ixbrl', \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION); });
    $h->check($service::class, 'fails both targets closed without a sealed frozen model', static function () use ($h, $service): void {
        foreach ([\eel_accounts\Service\CtFilingMappingService::TARGET_RIM, \eel_accounts\Service\CtFilingMappingService::TARGET_COMPUTATION] as $target) {
            $result = $service->mapFrozenFacts($target, ['available' => false], []);
            $h->assertSame(false, (bool)($result['success'] ?? true));
            $h->assertSame([], (array)($result['canonical_values'] ?? ['unexpected']));
        }
    });
});
