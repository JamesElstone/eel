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
$harness->run(AuthPageRenderer::class, function (GeneratedServiceClassTestHarness $harness, AuthPageRenderer $renderer): void {
    $shell = new ReflectionMethod(AuthPageRenderer::class, 'shell');
    $shell->setAccessible(true);

    $harness->check(AuthPageRenderer::class, 'renders configured image brand mark in auth shell', function () use ($harness, $renderer, $shell): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('brand-mark', 'swallowtail_butterfly_42x42.png');
            $html = (string)$shell->invoke($renderer, 'Sign in', 'Enter credentials.', [], '<form></form>');

            $harness->assertTrue(str_contains(
                $html,
                '<div class="auth-logo-mark"><img class="auth-logo-mark-image" src="/swallowtail_butterfly_42x42.png" alt="" aria-hidden="true"></div>'
            ));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });
});
