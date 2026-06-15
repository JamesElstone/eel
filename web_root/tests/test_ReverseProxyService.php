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

$harness->check(ReverseProxyService::class, 'uses forwarded client IP only from trusted proxies', function () use ($harness): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';

    try {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'X-Real-IP'],
        ]);

        $service = new ReverseProxyService();
        $trustedRequest = new RequestFramework(
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.10', 'HTTP_X_FORWARDED_FOR' => '203.0.113.40, 198.51.100.10'],
            [],
            []
        );
        $untrustedRequest = new RequestFramework(
            [],
            [],
            ['REMOTE_ADDR' => '198.51.100.20', 'HTTP_X_FORWARDED_FOR' => '203.0.113.40'],
            [],
            []
        );

        $harness->assertSame('203.0.113.40', $service->clientIpAddress($trustedRequest));
        $harness->assertSame('198.51.100.20', $service->clientIpAddress($untrustedRequest));
    } finally {
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
});
