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
    PageRequestGuard::class,
    static function (GeneratedServiceClassTestHarness $harness, PageRequestGuard $guard): void {
        $harness->check(PageRequestGuard::class, 'detects ajax action requests', static function () use ($harness, $guard): void {
            $request = new RequestFramework(
                [],
                ['card_action' => 'Activity'],
                ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
                [],
                [],
                null
            );

            $harness->assertSame(true, $guard->requestHasAjaxAction($request));
        });

        $harness->check(PageRequestGuard::class, 'builds ajax nonce error responses', static function () use ($harness, $guard): void {
            $response = $guard->ajaxNonceErrorResponse('Token failed.');

            $harness->assertSame('application/json; charset=utf-8', $response->contentType());
            $harness->assertTrue(str_contains($response->body(), 'Token failed.'));
        });
    }
);
