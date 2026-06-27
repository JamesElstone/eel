<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'add_user.php';

$harness = new GeneratedServiceClassTestHarness();

$context = static function (bool $inviteAvailable): array {
    return [
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['add_user'],
        ],
        'services' => [
            'password_policy' => 'Use a strong password.',
            'invite_availability' => [
                'available' => $inviteAvailable,
                'smtp_ready' => $inviteAvailable,
                'sms_ready' => $inviteAvailable,
            ],
            'current_users_dashboard' => [
                'roles' => [
                    [
                        'id' => 1,
                        'role_name' => 'Admin',
                    ],
                ],
            ],
        ],
    ];
};

$harness->check(_add_userCard::class, 'renders add-directly mode by default', function () use ($harness, $context): void {
    $card = new _add_userCard();
    $html = $card->render($context(false));

    $harness->assertSame('Create User', $card->title());
    $harness->assertTrue(str_contains($html, 'name="action" value="users-create-user"'));
    $harness->assertTrue(str_contains($html, 'data-password-requirements-for="add-user-password"'));
    $harness->assertTrue(str_contains($html, 'name="new_email_address" type="email" required'));
    $harness->assertTrue(str_contains($html, 'name="new_password" type="password" autocomplete="new-password"'));
    $harness->assertTrue(str_contains($html, 'required'));
    $harness->assertTrue(!str_contains($html, 'name="action" value="users-create-invited-user"'));
    $harness->assertTrue(!str_contains($html, 'data-user-create-mode-button="invite"'));
});

$harness->check(_add_userCard::class, 'renders invite mode when live delivery is available', function () use ($harness, $context): void {
    $html = (new _add_userCard())->render($context(true));
    $addPosition = strpos($html, 'name="action" value="users-create-user"');
    $invitePosition = strpos($html, 'name="action" value="users-create-invited-user"');

    $harness->assertTrue($addPosition !== false && $invitePosition !== false && $addPosition < $invitePosition);
    $harness->assertTrue(str_contains($html, 'data-user-create-mode-button="add">Add directly</button>'));
    $harness->assertTrue(str_contains($html, 'data-user-create-mode-button="invite">Invite</button>'));
    $harness->assertTrue(str_contains($html, 'role="tab" aria-selected="true"'));
    $harness->assertTrue(str_contains($html, 'role="tab" aria-selected="false"'));
    $harness->assertTrue(str_contains($html, 'data-require-invite-contact="true"'));
    $harness->assertTrue(str_contains($html, 'name="invite_email_address" type="email" data-invite-contact-field="email"'));
    $harness->assertTrue(str_contains($html, 'name="invite_mobile_country_code" autocomplete="tel-country-code" data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, 'name="invite_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16" data-invite-contact-field="mobile"'));
    $harness->assertTrue(str_contains($html, 'name="invite_role_id" data-no-submit-on-change="true"'));
    $harness->assertTrue(str_contains($html, '<button class="button primary" type="submit">Create invitation</button>'));
});

$harness->check(_add_userCard::class, 'renders only live invite contact channels', function () use ($harness, $context): void {
    $emailOnlyContext = $context(true);
    $emailOnlyContext['services']['invite_availability']['sms_ready'] = false;
    $emailOnlyHtml = (new _add_userCard())->render($emailOnlyContext);

    $harness->assertTrue(str_contains($emailOnlyHtml, 'name="invite_email_address" type="email" data-invite-contact-field="email"'));
    $harness->assertTrue(!str_contains($emailOnlyHtml, 'name="invite_mobile_number"'));

    $smsOnlyContext = $context(true);
    $smsOnlyContext['services']['invite_availability']['smtp_ready'] = false;
    $smsOnlyHtml = (new _add_userCard())->render($smsOnlyContext);

    $harness->assertTrue(!str_contains($smsOnlyHtml, 'name="invite_email_address"'));
    $harness->assertTrue(str_contains($smsOnlyHtml, 'name="invite_mobile_number" type="tel" autocomplete="tel-national" inputmode="tel" maxlength="16" data-invite-contact-field="mobile"'));
});
