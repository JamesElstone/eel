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
$harness->run(CsrfGuardFramework::class);

$actionRequest = static function (array $post): RequestFramework {
    return new RequestFramework([], $post, ['REQUEST_METHOD' => 'POST'], [], []);
};

$session = new SessionAuthenticationService();
$session->startSession();
$token = $session->csrfToken();

$harness->check(CsrfGuardFramework::class, 'passes valid supplied action tokens', function () use ($harness, $actionRequest, $session, $token): void {
    $result = (new CsrfGuardFramework($session, CsrfGuardFramework::MODE_SUPPLIED))->validateActionRequest($actionRequest([
        'card_action' => 'Test',
        'csrf_token' => $token,
    ]));

    $harness->assertTrue($result->isSuccess());
});

$harness->check(CsrfGuardFramework::class, 'fails invalid supplied action tokens', function () use ($harness, $actionRequest, $session): void {
    $result = (new CsrfGuardFramework($session, CsrfGuardFramework::MODE_SUPPLIED))->validateActionRequest($actionRequest([
        'card_action' => 'Test',
        'csrf_token' => 'invalid-token',
    ]));

    $harness->assertSame(false, $result->isSuccess());
    $harness->assertSame('error', $result->flashMessages()[0]['type'] ?? null);
});

$harness->check(CsrfGuardFramework::class, 'allows missing tokens in supplied mode', function () use ($harness, $actionRequest, $session): void {
    $result = (new CsrfGuardFramework($session, CsrfGuardFramework::MODE_SUPPLIED))->validateActionRequest($actionRequest([
        'card_action' => 'Test',
    ]));

    $harness->assertTrue($result->isSuccess());
});

$harness->check(CsrfGuardFramework::class, 'requires tokens in required mode', function () use ($harness, $actionRequest, $session): void {
    $result = (new CsrfGuardFramework($session, CsrfGuardFramework::MODE_REQUIRED))->validateActionRequest($actionRequest([
        'card_action' => 'Test',
    ]));

    $harness->assertSame(false, $result->isSuccess());
});

$harness->check(CsrfGuardFramework::class, 'skips enforcement in off mode', function () use ($harness, $actionRequest, $session): void {
    $result = (new CsrfGuardFramework($session, CsrfGuardFramework::MODE_OFF))->validateActionRequest($actionRequest([
        'card_action' => 'Test',
        'csrf_token' => 'invalid-token',
    ]));

    $harness->assertTrue($result->isSuccess());
});

$harness->check(HelperFramework::class, 'renders escaped CSRF hidden inputs from context', function () use ($harness): void {
    $html = HelperFramework::csrfHiddenInput([
        'page' => [
            'csrf_token' => 'token-value"&',
        ],
    ]);

    $harness->assertSame(
        '<input type="hidden" name="csrf_token" value="token-value&quot;&amp;">',
        $html
    );
});
