<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(BasePageFramework::class, 'loads as an abstract framework page base', function () use ($harness): void {
    $reflection = new ReflectionClass(BasePageFramework::class);

    $harness->assertTrue($reflection->isAbstract());
    $harness->assertTrue($reflection->implementsInterface(PageInterfaceFramework::class));
});
