<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

interface AppServiceNullableContractTestInterface
{
}

final class AppServiceNullableDependencyTestClass
{
}

final class AppServiceNullableConsumerTestClass
{
    public function __construct(
        public readonly ?AppServiceNullableDependencyTestClass $dependency
    ) {
    }
}

final class AppServiceDefaultNullableConsumerTestClass
{
    public function __construct(
        public readonly ?AppServiceNullableDependencyTestClass $dependency = null
    ) {
    }
}

final class AppServiceNullableInterfaceConsumerTestClass
{
    public function __construct(
        public readonly ?AppServiceNullableContractTestInterface $dependency
    ) {
    }
}

final class AppServiceAmbiguousUnionConsumerTestClass
{
    public function __construct(
        public readonly AppServiceNullableDependencyTestClass|AppServiceDefaultNullableConsumerTestClass|null $dependency
    ) {
    }
}

final class AppServiceCircularConsumerATestClass
{
    public function __construct(AppServiceCircularConsumerBTestClass $dependency)
    {
    }
}

final class AppServiceCircularConsumerBTestClass
{
    public function __construct(AppServiceCircularConsumerATestClass $dependency)
    {
    }
}

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AppService::class);

$appServices = new AppService(APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp');

$harness->check(AppService::class, 'resolves nullable concrete dependencies without defaults', function () use ($harness, $appServices): void {
    $consumer = $appServices->get(AppServiceNullableConsumerTestClass::class);

    $harness->assertTrue($consumer instanceof AppServiceNullableConsumerTestClass);
    $harness->assertTrue($consumer->dependency instanceof AppServiceNullableDependencyTestClass);
});

$harness->check(AppService::class, 'preserves explicit nullable defaults', function () use ($harness, $appServices): void {
    $consumer = $appServices->get(AppServiceDefaultNullableConsumerTestClass::class);

    $harness->assertTrue($consumer instanceof AppServiceDefaultNullableConsumerTestClass);
    $harness->assertSame(null, $consumer->dependency);
});

$harness->check(AppService::class, 'does not invent nullable interface dependencies', function () use ($harness, $appServices): void {
    $consumer = $appServices->get(AppServiceNullableInterfaceConsumerTestClass::class);

    $harness->assertTrue($consumer instanceof AppServiceNullableInterfaceConsumerTestClass);
    $harness->assertSame(null, $consumer->dependency);
});

$harness->check(AppService::class, 'does not resolve ambiguous nullable unions', function () use ($harness, $appServices): void {
    $consumer = $appServices->get(AppServiceAmbiguousUnionConsumerTestClass::class);

    $harness->assertTrue($consumer instanceof AppServiceAmbiguousUnionConsumerTestClass);
    $harness->assertSame(null, $consumer->dependency);
});

$harness->check(AppService::class, 'detects circular nullable dependency graphs', function () use ($appServices): void {
    try {
        $appServices->get(AppServiceCircularConsumerATestClass::class);
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'Circular service dependency')) {
            throw new RuntimeException('Circular dependency failure did not identify the dependency cycle.');
        }

        return;
    }

    throw new RuntimeException('Circular dependency resolution unexpectedly succeeded.');
});
