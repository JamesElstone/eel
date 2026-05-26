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
    AuthPageRenderer::class,
    static function (GeneratedServiceClassTestHarness $harness, AuthPageRenderer $renderer): void {
        $harness->check(AuthPageRenderer::class, 'renders login form shell', static function () use ($harness, $renderer): void {
            $html = $renderer->loginPage(new SessionAuthenticationService());

            $harness->assertTrue(str_contains($html, 'auth_action" value="login"'));
            $harness->assertTrue(str_contains($html, 'Email address'));
        });
    }
);
