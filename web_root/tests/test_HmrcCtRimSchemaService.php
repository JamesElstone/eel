<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimSchemaService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimSchemaService $service): void {
        $harness->check($service::class, 'accepts only real ISO calendar dates', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'isDate');
            $method->setAccessible(true);
            $harness->assertTrue((bool)$method->invoke($service, '2026-04-01'));
            $harness->assertFalse((bool)$method->invoke($service, '2026-02-30'));
        });
    }
);
