<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check('CSRF form coverage', 'downstream card action forms include CSRF helpers', function () use ($harness): void {
    $cardDirectory = APP_CARDS;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cardDirectory));
    $missing = [];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string)file_get_contents($file->getPathname());
        preg_match_all('/<form\b(?:(?!<\/form>).)*<\/form>/is', $source, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            $form = (string)$match[0];
            $offset = (int)$match[1];
            $submitsAction = preg_match('/name=["\'](?:action|card_action)["\']/', $form) === 1;

            if (!$submitsAction) {
                continue;
            }

            $hasCsrf = str_contains($form, 'name="csrf_token"')
                || str_contains($form, "name='csrf_token'")
                || str_contains($form, 'csrfHiddenInput');

            if ($hasCsrf) {
                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $missing[] = str_replace(PROJECT_ROOT, '', $file->getPathname()) . ':' . $line;
        }
    }

    $harness->assertSame([], $missing);
});
