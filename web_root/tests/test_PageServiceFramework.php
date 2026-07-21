<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(PageServiceFramework::class, function (GeneratedServiceClassTestHarness $harness): void {
    $services = new PageServiceFramework(new AppService(''));

    $harness->assertTrue($services->actionProgress() instanceof ActionProgressFramework);
    $harness->assertSame($services->actionProgress(), $services->actionProgress());

    $injected = new ActionProgressFramework(static function (string $line): void {}, false);
    $injectedServices = new PageServiceFramework(new AppService(''), null, $injected);
    $harness->assertSame($injected, $injectedServices->actionProgress());
});
