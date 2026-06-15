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
$harness->run(SignupTokenRateLimitService::class);

$harness->check(SignupTokenRateLimitService::class, 'blocks a client after repeated failed signup tokens', function () use ($harness): void {
    if (!InterfaceDB::tableExists('signup_token_rate_limits')) {
        $harness->skip('signup token rate limit table is not available.');
    }

    $clientIp = '203.0.113.' . random_int(1, 250);
    $request = new RequestFramework([], [], ['REMOTE_ADDR' => $clientIp], [], []);
    $service = new SignupTokenRateLimitService();

    InterfaceDB::beginTransaction();
    try {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $status = $service->recordFailedToken($request);
            $harness->assertSame($attempt, (int)($status['failed_attempts'] ?? 0));
            $harness->assertTrue(!$service->isBlocked($request));
        }

        $status = $service->recordFailedToken($request);
        $harness->assertSame(5, (int)($status['failed_attempts'] ?? 0));
        $harness->assertTrue($service->isBlocked($request));

        $activeBlocks = $service->activeBlocks();
        $harness->assertTrue(in_array($clientIp, array_map(static fn(array $row): string => (string)($row['client_ip'] ?? ''), $activeBlocks), true));
        $harness->assertSame(1, $service->clearBlock($clientIp));
        $harness->assertTrue(!$service->isBlocked($request));
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
});
