<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'invitation_settings.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_invitation_settingsCard::class, 'shows supported template variables', function () use ($harness): void {
    $html = (new _invitation_settingsCard())->render([
        'page' => [
            'csrf_token' => 'test-csrf',
            'page_cards' => ['invitation_settings'],
        ],
        'services' => [
            'current_user' => [
                'display_name' => 'Signed In User',
                'email_address' => 'signed@example.test',
                'mobile_number' => '+447700900123',
            ],
        ],
    ]);

    $harness->assertTrue(str_contains($html, '<fieldset class="panel-soft">'));
    $harness->assertTrue(str_contains($html, '<legend>Supported template variables</legend>'));
    $harness->assertTrue(strpos($html, 'Invitations Expires After This Many Days') < strpos($html, '<legend>Supported template variables</legend>'));
    $harness->assertTrue(!str_contains($html, 'External Base Web URL (Blank for Automatic)'));
    $harness->assertTrue(str_contains($html, 'These variables can be used in the email subject, email body, and SMS template fields.'));
    $harness->assertTrue(!str_contains($html, '<br><br>'));
    $harness->assertTrue(str_contains($html, '<code>{display_name}</code> - The signed-in user sending the invitation (Signed In User).'));
    $harness->assertTrue(str_contains($html, '<code>{display_email}</code> - The signed-in user email address (signed@example.test).'));
    $harness->assertTrue(str_contains($html, '<code>{display_mobile}</code> - The signed-in user mobile number (+447700900123).'));
    $harness->assertTrue(str_contains($html, '<code>{recipient_name}</code> - The user receiving the invitation.'));
    $harness->assertTrue(str_contains($html, '<code>{app_name}</code> - The configured name of this app (eelKit Framework Test).'));
    $harness->assertTrue(str_contains($html, '<code>{link}</code> - The invitation URL to respond to.'));
    $harness->assertTrue(str_contains($html, '<code>{expires_at}</code> - The date and time that the above link will expire by.'));
});
