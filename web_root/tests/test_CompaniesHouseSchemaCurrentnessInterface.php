<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $reflection = new ReflectionClass(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class);
        $method = $reflection->getMethod('refreshInstalledSchemas');
        $harness->check(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class, 'declares the dynamic currentness gate', static function () use ($harness, $method): void {
            $harness->assertSame(1, $method->getNumberOfParameters());
            $harness->assertSame('array', (string)$method->getReturnType());
        });
        $installed = $reflection->getMethod('installedSchemas');
        $harness->check(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class, 'declares the installed file inventory gate', static function () use ($harness, $installed): void {
            $harness->assertSame(0, $installed->getNumberOfParameters());
            $harness->assertSame('array', (string)$installed->getReturnType());
        });
        $operation = $reflection->getMethod('installedSchemasForOperation');
        $harness->check(\eel_accounts\Service\CompaniesHouseSchemaCurrentnessInterface::class, 'declares the operation-specific installed inventory gate', static function () use ($harness, $operation): void {
            $harness->assertSame(1, $operation->getNumberOfParameters());
            $harness->assertSame('array', (string)$operation->getReturnType());
        });
        $harness->check(\eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class, 'keeps schema downloads out of preflight and submission', static function () use ($harness): void {
            $path = (new ReflectionClass(
                \eel_accounts\Service\CompaniesHouseAccountsSubmissionService::class
            ))->getFileName();
            $source = is_string($path) ? file_get_contents($path) : false;
            $harness->assertTrue(is_string($source));
            $harness->assertFalse(str_contains((string)$source, '->refreshInstalledSchemas('));
            $harness->assertTrue(substr_count((string)$source, '->installedSchemas()') >= 1);
            $harness->assertTrue(str_contains(
                (string)$source,
                "->installedSchemasForOperation('company_data')"
            ));
            $harness->assertFalse(str_contains(
                (string)$source,
                'CompaniesHouseSchemaCompatibilityService'
            ));
            $harness->assertFalse(str_contains((string)$source, 'prepareAndCompile('));
            $validatorPath = (new ReflectionClass(
                \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator::class
            ))->getFileName();
            $validatorSource = is_string($validatorPath)
                ? file_get_contents($validatorPath)
                : false;
            $harness->assertTrue(is_string($validatorSource));
            $harness->assertFalse(str_contains(
                (string)$validatorSource,
                'CompaniesHouseSchemaCompatibilityService'
            ));
        });
    }
);
