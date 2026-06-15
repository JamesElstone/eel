<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'smtp_settings.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_smtp_settingsCard::class, 'posts SMTP tests without submitted settings fields', function () use ($harness): void {
    $card = new _smtp_settingsCard();
    $html = $card->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['smtp_settings'],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'form="smtp-test-form"'));
    $harness->assertTrue(str_contains($html, 'id="smtp-test-form"'));
    $harness->assertTrue(str_contains($html, 'name="card_action" value="SmtpTest"'));

    $testFormHtml = substr($html, (int)strpos($html, 'id="smtp-test-form"'));
    $harness->assertTrue(!str_contains($testFormHtml, 'smtp_host'));
    $harness->assertTrue(!str_contains($testFormHtml, 'smtp_username'));
    $harness->assertTrue(!str_contains($testFormHtml, 'smtp_password'));
    $harness->assertTrue(!str_contains($testFormHtml, 'smtp_from_address'));
});
