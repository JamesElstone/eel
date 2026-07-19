<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\CtPeriodFilingModelService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\CtPeriodFilingModelService $service): void {
    $h->check($service::class, 'fails closed without a complete CT context', static function () use ($h, $service): void { $result = $service->build(0, 0, 0); $h->assertSame(false, $result['available']); });
});
