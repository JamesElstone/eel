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

$harness->check('CssFramework', 'keeps flash messages as a protected viewport overlay', function () use ($harness): void {
    $css = (string)file_get_contents(APP_CSS . 'index.css');

    $harness->assertSame(1, preg_match('/#flash-messages\.flash-messages\s*\{(?P<body>.*?)\n\}/s', $css, $matches));
    $body = (string)($matches['body'] ?? '');

    foreach ([
        'display: grid !important;',
        'position: fixed !important;',
        'top: 20px !important;',
        'right: 20px !important;',
        'bottom: auto !important;',
        'left: auto !important;',
        'width: min(420px, calc(100vw - 40px)) !important;',
        'z-index: 2147483000 !important;',
        'max-height: calc(100vh - 40px) !important;',
        'overflow: visible !important;',
    ] as $declaration) {
        $harness->assertTrue(str_contains($body, $declaration));
    }

    $harness->assertSame(false, str_contains($body, 'max-height: 1vh'));
    $harness->assertSame(0, substr_count($css, "\n.flash-messages {"));
});

$harness->check('CssFramework', 'styles warning alerts as advisory messages', function () use ($harness): void {
    $css = (string)file_get_contents(APP_CSS . 'index.css');

    $harness->assertTrue(str_contains($css, '.alert.warning'));
    $harness->assertTrue(str_contains($css, 'background: var(--warning-soft);'));
    $harness->assertTrue(str_contains($css, 'color: var(--warning);'));
});

$harness->check('CssFramework', 'defines target card reveal scroll offset', function () use ($harness): void {
    $css = (string)file_get_contents(APP_CSS . 'index.css');

    $harness->assertTrue(str_contains($css, '--page-card-reveal-offset: 96px;'));
    $harness->assertTrue(str_contains($css, ".page-stack-card,\n.card {"));
    $harness->assertTrue(str_contains($css, 'scroll-margin-top: var(--page-card-reveal-offset, 96px);'));
});
