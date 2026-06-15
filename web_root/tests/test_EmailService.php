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
$harness->run(EmailService::class);

$harness->check(EmailService::class, 'builds CRAM-MD5 SMTP digest responses', function () use ($harness): void {
    $service = new EmailService();
    $challenge = base64_encode('<challenge@example.test>');
    $expected = base64_encode('user ' . hash_hmac('md5', '<challenge@example.test>', 'secret'));

    $harness->assertSame($expected, $service->cramMd5Response($challenge, 'user', 'secret'));
});

$harness->check(EmailService::class, 'explains SMTP socket failures without exposing secrets', function () use ($harness): void {
    $service = new EmailService();
    $method = new ReflectionMethod(EmailService::class, 'socketFailureMessage');
    $method->setAccessible(true);

    $harness->assertSame(
        'SMTP host could not be resolved: missing.example.test. DNS reported: php_network_getaddresses: getaddrinfo for missing.example.test failed.',
        $method->invoke($service, 'missing.example.test', 587, 0, 'php_network_getaddresses: getaddrinfo for missing.example.test failed')
    );
    $harness->assertSame(
        'Could not connect to SMTP host mail.example.test on port 587: Connection refused (error 111).',
        $method->invoke($service, 'mail.example.test', 587, 111, 'Connection refused')
    );
});

$harness->check(EmailService::class, 'builds a generic SMTP test email body', function () use ($harness): void {
    $service = new EmailService();
    $subjectMethod = new ReflectionMethod(EmailService::class, 'testEmailSubject');
    $bodyMethod = new ReflectionMethod(EmailService::class, 'testEmailBody');
    $subjectMethod->setAccessible(true);
    $bodyMethod->setAccessible(true);

    $subject = (string)$subjectMethod->invoke($service);
    $body = (string)$bodyMethod->invoke($service);

    $harness->assertSame(true, str_starts_with($subject, 'SMTP Test from '));
    $harness->assertSame(false, str_contains($subject, 'eelKit SMTP test'));
    $harness->assertSame(true, str_contains($body, 'your email settings are working'));
    $harness->assertSame(false, str_contains(strtolower($body), 'password'));
    $harness->assertSame(false, str_contains(strtolower($body), 'token'));
});

$harness->check(EmailService::class, 'renders invite sender and recipient names in templates', function () use ($harness): void {
    $service = new EmailService();
    $method = new ReflectionMethod(EmailService::class, 'renderTemplate');
    $method->setAccessible(true);

    $harness->assertSame(
        'Hello Invite Target, James Admin <james@example.test> +447700900123 invited Invite Target to eelKit Framework Test: https://example.test/signup before 2026-06-20 10:00:00.',
        $method->invoke(
            $service,
            'Hello {recipient_name}, {display_name} <{display_email}> {display_mobile} invited {recipient} to {app_name}: {link} before {expires_at}.',
            'https://example.test/signup',
            '2026-06-20 10:00:00',
            'James Admin',
            'Invite Target',
            'JAMES@EXAMPLE.TEST',
            '+447700900123'
        )
    );
});

$harness->check(EmailService::class, 'builds SMTP test messages from invite templates', function () use ($harness): void {
    $path = AppConfigurationStore::configPath();
    $original = is_file($path) ? (string)file_get_contents($path) : null;

    try {
        AppConfigurationStore::setInvitationSettings([
            'email_subject_template' => 'Invite from {display_name} ({display_email}) for {recipient_name} in {app_name}',
            'email_body_template' => "Hello {recipient_name}\nFrom {display_name}\nEmail {display_email}\nMobile {display_mobile}\n{link}\n{expires_at}",
        ]);

        $service = new EmailService();
        $method = new ReflectionMethod(EmailService::class, 'inviteEmailContent');
        $method->setAccessible(true);
        $content = $method->invoke(
            $service,
            'https://example.test/signup/?test_email=1',
            '2026-06-20 10:00:00',
            'Signed In User',
            'Invite Target',
            'signed@example.test',
            '+447700900123'
        );

        $harness->assertSame('Invite from Signed In User (signed@example.test) for Invite Target in eelKit Framework Test', (string)$content['subject']);
        $harness->assertSame("Hello Invite Target\nFrom Signed In User\nEmail signed@example.test\nMobile +447700900123\nhttps://example.test/signup/?test_email=1\n2026-06-20 10:00:00", (string)$content['body']);
    } finally {
        if ($original !== null) {
            file_put_contents($path, $original);
        }
        AppConfigurationStore::config(true);
    }
});

$harness->check(EmailService::class, 'requires a valid recipient for SMTP template test emails', function () use ($harness): void {
    $service = new EmailService();

    try {
        $service->sendTemplateTestEmail('not-an-email-address', 'https://example.test/signup/?test_email=1', '2026-06-20 10:00:00', 'Signed In User');
    } catch (RuntimeException $exception) {
        $harness->assertSame('SMTP template test is missing a valid recipient email address.', $exception->getMessage());
        return;
    }

    throw new RuntimeException('Expected invalid recipient to be rejected.');
});

$harness->check(EmailService::class, 'normalises SMTP message bodies to CRLF line endings', function () use ($harness): void {
    $service = new EmailService();
    $method = new ReflectionMethod(EmailService::class, 'smtpBody');
    $method->setAccessible(true);

    $body = (string)$method->invoke($service, "Hi\n\n.Link\nBye");

    $harness->assertSame("Hi\r\n\r\n..Link\r\nBye", $body);
    $harness->assertSame(false, str_contains($body, "\n\n"));
});
