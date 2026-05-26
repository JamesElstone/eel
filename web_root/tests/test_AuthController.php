<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    AuthController::class,
    static function (GeneratedServiceClassTestHarness $harness, AuthController $controller): void {
        $harness->check(AuthController::class, 'renders a login response for unauthenticated get requests', static function () use ($harness, $controller): void {
            $request = new RequestFramework([], [], ['REQUEST_METHOD' => 'GET'], [], [], null);
            $response = $controller->response($request, 'test-device', false);

            $harness->assertTrue($response instanceof ResponseFramework);
            $harness->assertSame('text/html; charset=utf-8', $response->contentType());
            $harness->assertTrue(str_contains($response->body(), 'auth_action" value="login"'));
        });
    }
);
