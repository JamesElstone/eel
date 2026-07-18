<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimPackageDeleteService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimPackageDeleteService $service): void {
        $harness->check($service::class, 'rejects deletion of a missing package', static function () use ($harness, $service): void {
            $result = $service->delete(0);
            $harness->assertFalse((bool)($result['success'] ?? true));
            $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), 'not found'));
        });
    }
);
