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

$harness->run(\eel_accounts\Service\TaxRateRuleService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check('tax rate source policy', 'does not hard-code tax percentages in executable app PHP', static function () use ($harness): void {
        $roots = [
            APP_ROOT . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts',
            APP_ROOT . 'content',
        ];
        $forbidden = '/\b(?:0\.015|0\.06|0\.14|0\.18|0\.19|0\.20|0\.25|0\.30)\b|(?:3\s*\/\s*200|11\s*\/\s*400|6%|14%|18%|19%|20%|25%|30%)/';
        $violations = [];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $contents = (string)file_get_contents($path);
                if (preg_match($forbidden, $contents) === 1) {
                    $violations[] = str_replace(PROJECT_ROOT, '', $path);
                }
            }
        }

        $harness->assertSame([], $violations);
    });
});
