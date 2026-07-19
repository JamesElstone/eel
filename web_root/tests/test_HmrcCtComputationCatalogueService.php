<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\HmrcCtComputationCatalogueService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\HmrcCtComputationCatalogueService $service): void {
    $h->check($service::class, 'rejects a missing taxonomy directory', static function () use ($h, $service): void { try { $service->catalogueDirectory(1, '__missing__'); $h->assertTrue(false); } catch (InvalidArgumentException) { $h->assertTrue(true); } });
});
