<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'web_environment.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_web_environmentCard::class, 'renders base URL and reverse proxy settings', function () use ($harness): void {
    $previousServerAddress = $_SERVER['SERVER_ADDR'] ?? null;
    $_SERVER['SERVER_ADDR'] = '203.0.113.7';

    try {
        $html = (new _web_environmentCard())->render([
            'page' => [
                'csrf_token' => 'test-csrf',
                'page_cards' => ['web_environment'],
            ],
        ]);
    } finally {
        if ($previousServerAddress === null) {
            unset($_SERVER['SERVER_ADDR']);
        } else {
            $_SERVER['SERVER_ADDR'] = $previousServerAddress;
        }
    }

    $harness->assertTrue(str_contains($html, 'name="card_action" value="WebEnvironment"'));
    $harness->assertTrue(str_contains($html, '<legend>Server Address</legend>'));
    $harness->assertTrue(str_contains($html, 'Current server IP address'));
    $harness->assertTrue(str_contains($html, '<code>203.0.113.7</code>'));
    $harness->assertTrue(str_contains($html, '<legend>Upload Limits</legend>'));
    $harness->assertTrue(str_contains($html, 'Upload max filesize'));
    $harness->assertTrue(str_contains($html, 'Post max size'));
    $harness->assertTrue(str_contains($html, 'Max file uploads'));
    $harness->assertTrue(str_contains($html, 'Memory limit'));
    $harness->assertTrue(strpos($html, '<legend>Server Address</legend>') < strpos($html, '<legend>Upload Limits</legend>'));
    $harness->assertTrue(strpos($html, '<legend>Upload Limits</legend>') < strpos($html, '<legend>Web Environment</legend>'));
    $harness->assertTrue(str_contains($html, 'External Base Web URL (Blank for Automatic)'));
    $harness->assertTrue(str_contains($html, 'Trusted Reverse Proxy IPs'));
    $harness->assertTrue(str_contains($html, 'name="add_current_reverse_proxy" value="1"'));
    $harness->assertTrue(str_contains($html, '<div class="form-row-actions align-right"><button class="button button-inline"'));
    $harness->assertTrue(strpos($html, 'Trusted Reverse Proxy IPs') < strpos($html, 'One proxy IP address per line.'));
    $harness->assertTrue(strpos($html, 'One proxy IP address per line.') < strpos($html, 'name="reverse_proxy_trusted_proxy_ips"'));
    $harness->assertTrue(strpos($html, 'name="reverse_proxy_trusted_proxy_ips"') < strpos($html, 'Add Current Reverse Proxy'));
    $harness->assertTrue(str_contains($html, 'Client IP Headers'));
    $harness->assertTrue(str_contains($html, 'X-Forwarded-For'));
    $harness->assertTrue(str_contains($html, 'X-Real-IP'));
});
