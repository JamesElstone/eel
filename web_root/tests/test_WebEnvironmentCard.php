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
    $html = (new _web_environmentCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['web_environment'],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="card_action" value="WebEnvironment"'));
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
