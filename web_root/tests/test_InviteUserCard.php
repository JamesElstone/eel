<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'invite_user.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_invite_userCard::class, 'does not submit create-only selectors on change', function () use ($harness): void {
    $html = (new _invite_userCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['invite_user'],
        ],
        'services' => [
            'current_users_dashboard' => [
                'roles' => [
                    [
                        'id' => 1,
                        'role_name' => 'Admin',
                    ],
                ],
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, 'name="invite_mobile_country_code" autocomplete="tel-country-code" data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, 'name="invite_role_id" data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, 'data-require-invite-contact="true"'));
    $harness->assertTrue(str_contains($html, 'name="invite_email_address" type="email" data-invite-contact-field="email"'));
    $harness->assertTrue(str_contains($html, 'name="invite_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16" data-invite-contact-field="mobile"'));
});
