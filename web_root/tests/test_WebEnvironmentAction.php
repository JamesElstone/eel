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

$harness->check(WebEnvironmentAction::class, 'normalises IP and header lists', function () use ($harness): void {
    $action = new WebEnvironmentAction();
    $ipList = new ReflectionMethod(WebEnvironmentAction::class, 'ipList');
    $headerList = new ReflectionMethod(WebEnvironmentAction::class, 'headerList');
    $currentReverseProxyIp = new ReflectionMethod(WebEnvironmentAction::class, 'currentReverseProxyIp');
    $withCurrentReverseProxyIp = new ReflectionMethod(WebEnvironmentAction::class, 'withCurrentReverseProxyIp');
    $ipList->setAccessible(true);
    $headerList->setAccessible(true);
    $currentReverseProxyIp->setAccessible(true);
    $withCurrentReverseProxyIp->setAccessible(true);

    $harness->assertSame(['192.0.2.10', '2001:db8::1'], $ipList->invoke($action, "192.0.2.10\n2001:db8::1\n192.0.2.10"));
    $harness->assertSame(null, $ipList->invoke($action, 'not-an-ip'));
    $harness->assertSame(['X-Forwarded-For', 'X-Real-Ip'], $headerList->invoke($action, "X-Forwarded-For\nX-Real-IP"));
    $harness->assertSame(null, $headerList->invoke($action, "X Good\n"));
    $harness->assertSame('198.51.100.10', $currentReverseProxyIp->invoke(
        $action,
        new RequestFramework([], [], ['REMOTE_ADDR' => '198.51.100.10'], [], [])
    ));
    $harness->assertSame('', $currentReverseProxyIp->invoke(
        $action,
        new RequestFramework([], [], ['REMOTE_ADDR' => 'not-an-ip'], [], [])
    ));
    $harness->assertSame(['192.0.2.10', '198.51.100.10'], $withCurrentReverseProxyIp->invoke(
        $action,
        ['192.0.2.10'],
        '198.51.100.10'
    ));
    $harness->assertSame(['198.51.100.10'], $withCurrentReverseProxyIp->invoke(
        $action,
        ['198.51.100.10'],
        '198.51.100.10'
    ));
});
