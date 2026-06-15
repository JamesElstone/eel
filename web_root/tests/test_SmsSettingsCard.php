<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'sms_settings.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_sms_settingsCard::class, 'disables test button without a current-user mobile number', function () use ($harness): void {
    $card = new _sms_settingsCard();
    $html = $card->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['sms_settings'],
        ],
        'services' => [
            'current_user' => [
                'mobile_number' => '',
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="card_action" value="SmsTest"'));
    $harness->assertTrue(str_contains($html, 'form="sms-test-form" disabled'));
    $harness->assertTrue(str_contains($html, 'No mobile number for current user.'));
});

$harness->check(_sms_settingsCard::class, 'enables test button for a numeric non-zero current-user mobile number', function () use ($harness): void {
    $card = new _sms_settingsCard();
    $html = $card->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['sms_settings'],
        ],
        'services' => [
            'current_user' => [
                'mobile_number' => '+447700900123',
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="card_action" value="SmsTest"'));
    $harness->assertTrue(str_contains($html, 'form="sms-test-form" formnovalidate'));
    $harness->assertTrue(!str_contains($html, 'form="sms-test-form" disabled'));
    $harness->assertTrue(str_contains($html, 'class="form-row full sms-settings-actions"'));
    $harness->assertTrue(str_contains($html, 'form="sms-test-form"'));
    $harness->assertTrue(str_contains($html, 'id="sms-test-form"'));
    $testFormHtml = substr($html, (int)strpos($html, 'id="sms-test-form"'));
    $harness->assertTrue(!str_contains($testFormHtml, 'sms_api_url'));
    $harness->assertTrue(!str_contains($testFormHtml, 'sms_auth_header'));
    $harness->assertTrue(!str_contains($testFormHtml, 'sms_auth_token'));
});
