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
$harness->run(BrandMarkRenderer::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(BrandMarkRenderer::class, 'renders configured initials as escaped text', function () use ($harness): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('brand-mark', 'E&K');
            $harness->assertSame('E&amp;K', BrandMarkRenderer::html('brand-mark-image'));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });

    $harness->check(BrandMarkRenderer::class, 'renders local jpg and png paths as web root images', function () use ($harness): void {
        $path = AppConfigurationStore::configPath();
        $original = file_get_contents($path);

        if (!is_string($original)) {
            throw new RuntimeException('Unable to read fixture config.');
        }

        try {
            AppConfigurationStore::set('brand-mark', 'swallowtail_butterfly_42x42.png');
            $harness->assertSame(true, BrandMarkRenderer::isConfiguredImage());
            $harness->assertSame(
                '<img class="brand-mark-image" src="/swallowtail_butterfly_42x42.png" alt="" aria-hidden="true">',
                BrandMarkRenderer::html('brand-mark-image')
            );

            AppConfigurationStore::set('brand-mark', 'images/logo.JPG');
            $harness->assertSame(true, BrandMarkRenderer::isConfiguredImage());
            $harness->assertSame(
                '<img class="brand-mark-image" src="/images/logo.JPG" alt="" aria-hidden="true">',
                BrandMarkRenderer::html('brand-mark-image')
            );

            AppConfigurationStore::set('brand-mark', 'https://example.test/logo.png');
            $harness->assertSame(false, BrandMarkRenderer::isConfiguredImage());
            $harness->assertSame('https://example.test/logo.png', BrandMarkRenderer::html('brand-mark-image'));
        } finally {
            file_put_contents($path, $original, LOCK_EX);
            AppConfigurationStore::config(true);
        }
    });
});
