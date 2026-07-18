<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimCatalogueService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimCatalogueService $service): void {
        $harness->check($service::class, 'uses a project-scoped cache directory', static function () use ($harness, $service): void {
            $directory = $service->cacheDirectory();
            $normalised = str_replace('\\', '/', $directory);
            $harness->assertTrue(str_contains($normalised, '/third_party/hmrc/'));
            $harness->assertTrue(str_ends_with($normalised, '/ct600-rim'));
        });
    }
);
