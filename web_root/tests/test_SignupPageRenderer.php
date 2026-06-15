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

$harness->check(SignupPageRenderer::class, 'renders signup verification form', function () use ($harness): void {
    $renderer = new SignupPageRenderer('EEL Test', 'Test strapline');
    $html = $renderer->verificationPage(new SessionAuthenticationService(), [], [
        'display_name' => 'Invite Person',
    ]);

    $harness->assertTrue(str_contains($html, 'Verify your details'));
    $harness->assertTrue(str_contains($html, 'name="signup_action" value="verify_identity"'));
    $harness->assertTrue(str_contains($html, 'name="mobile_number"'));
});
