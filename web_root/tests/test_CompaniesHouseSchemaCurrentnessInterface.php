<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $method = (new ReflectionClass(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class))->getMethod('ensureCurrent');
        $harness->check(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class, 'declares the dynamic currentness gate', static function () use ($harness, $method): void {
            $harness->assertSame(1, $method->getNumberOfParameters());
            $harness->assertSame('array', (string)$method->getReturnType());
        });
    }
);
