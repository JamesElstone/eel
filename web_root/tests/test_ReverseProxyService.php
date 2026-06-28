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

$withRestoredConfig = static function (callable $callback): void {
    $configPath = AppConfigurationStore::configPath();
    $originalConfig = is_file($configPath) ? (string)file_get_contents($configPath) : '';

    try {
        $callback();
    } finally {
        if ($originalConfig !== '') {
            file_put_contents($configPath, $originalConfig);
            AppConfigurationStore::config(true);
        }
    }
};

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

$harness->check(ReverseProxyService::class, 'uses forwarded host and scheme only from trusted proxies', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For', 'Forwarded'],
        ]);

        $service = new ReverseProxyService();
        $trustedRequest = new RequestFramework(
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_HOST' => 'App.Example.Test:8443, proxy.local',
                'HTTP_X_FORWARDED_PROTO' => 'HTTPS, http',
            ],
            [],
            []
        );
        $untrustedRequest = new RequestFramework(
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.20',
                'HTTP_X_FORWARDED_HOST' => 'app.example.test',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            [],
            []
        );

        $harness->assertSame('app.example.test:8443', $service->forwardedHost($trustedRequest));
        $harness->assertSame('https', $service->forwardedScheme($trustedRequest));
        $harness->assertSame('', $service->forwardedHost($untrustedRequest));
        $harness->assertSame('', $service->forwardedScheme($untrustedRequest));
    });
});

$harness->check(ReverseProxyService::class, 'parses standard Forwarded host and scheme values', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['Forwarded'],
        ]);

        $request = new RequestFramework(
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_FORWARDED' => 'for=203.0.113.40;proto=https;host="[2001:db8::1]:8443"',
            ],
            [],
            []
        );
        $service = new ReverseProxyService();

        $harness->assertSame('[2001:db8::1]:8443', $service->forwardedHost($request));
        $harness->assertSame('https', $service->forwardedScheme($request));
    });
});

$harness->check(ReverseProxyService::class, 'rejects invalid forwarded host and scheme values', function () use ($harness, $withRestoredConfig): void {
    $withRestoredConfig(function () use ($harness): void {
        AppConfigurationStore::setWebEnvironmentSettings([
            'base_url_override' => '',
            'trusted_proxy_ips' => ['198.51.100.10'],
            'client_ip_headers' => ['X-Forwarded-For'],
        ]);

        $request = new RequestFramework(
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.10',
                'HTTP_X_FORWARDED_HOST' => 'bad host/example',
                'HTTP_X_FORWARDED_PROTO' => 'ftp',
            ],
            [],
            []
        );
        $service = new ReverseProxyService();

        $harness->assertSame('', $service->forwardedHost($request));
        $harness->assertSame('', $service->forwardedScheme($request));
    });
});
