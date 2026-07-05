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
    \eel_accounts\Ui\TaxCardRenderer::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(\eel_accounts\Ui\TaxCardRenderer::class, 'declares tax workings service dependency', static function () use ($harness): void {
            $definition = \eel_accounts\Ui\TaxCardRenderer::serviceDefinition();

            $harness->assertSame('taxWorkings', (string)($definition['key'] ?? ''));
            $harness->assertSame(\eel_accounts\Service\TaxWorkingsService::class, (string)($definition['service'] ?? ''));
            $harness->assertSame('fetchWorkings', (string)($definition['method'] ?? ''));
        });

        $harness->check(\eel_accounts\Ui\TaxCardRenderer::class, 'renders escaped empty state and percent values', static function () use ($harness): void {
            $html = \eel_accounts\Ui\TaxCardRenderer::emptyState(['errors' => ['Select <company>']]);

            $harness->assertTrue(str_contains($html, 'Select &lt;company&gt;'));
            $harness->assertSame('-', \eel_accounts\Ui\TaxCardRenderer::percent(null));
            $harness->assertSame('19.00%', \eel_accounts\Ui\TaxCardRenderer::percent(0.19));
        });
    }
);
