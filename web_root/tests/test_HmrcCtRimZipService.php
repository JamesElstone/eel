<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimZipService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimZipService $service): void {
        $harness->check($service::class, 'derives a sibling extraction directory from a ZIP path', static function () use ($harness, $service): void {
            $path = 'C:' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ct600-v2.zip';
            $harness->assertSame('C:' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ct600-v2', $service->extractionDirectory($path));
        });
    }
);
