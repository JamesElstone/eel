<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'current_user_details.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_current_user_detailsCard::class, 'loads stored mobile number into country and local fields', function () use ($harness): void {
    $card = new _current_user_detailsCard();
    $html = $card->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['current_user_details', 'current_users'],
        ],
        'services' => [
            'current_user' => [
                'display_name' => 'Test User',
                'email_address' => 'test@example.test',
                'mobile_number' => '+447775633330',
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'value="+44" selected'));
    $harness->assertTrue(str_contains($html, 'name="mobile_number" type="tel" value="7775633330"'));
    $harness->assertTrue(str_contains($html, 'name="mobile_country_code" autocomplete="tel-country-code" data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, 'data-invalidate-page="true"'));
});
