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
$harness->run(\eel_accounts\Support\Utf8::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Support\Utf8::class, 'owns every downstream HTML and JSON encoding boundary', static function () use ($harness): void {
        $root = dirname(__DIR__);
        $files = utf8OutputAuditPhpFiles([
            $root . DIRECTORY_SEPARATOR . 'content',
            $root . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'eel_accounts',
        ]);
        $violations = [];

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
            $source = (string)file_get_contents($file);
            if ($relative === 'classes/eel_accounts/support/Utf8.php') {
                continue;
            }

            foreach ([
                'HelperFramework::escape(' => 'shared eelKit HTML escape',
                'htmlspecialchars(' => 'direct HTML/XML escape',
                'json_encode(' => 'raw JSON encoding',
            ] as $needle => $label) {
                if (str_contains($source, $needle)) {
                    $violations[] = $relative . ': ' . $label;
                }
            }

            if (
                $relative !== 'classes/eel_accounts/support/Utf8Table.php'
                && str_contains($source, 'TableFramework::make(')
            ) {
                $violations[] = $relative . ': direct eelKit table construction';
            }
        }

        $harness->assertSame([], $violations);
    });
});

/** @param list<string> $directories
 *  @return list<string>
 */
function utf8OutputAuditPhpFiles(array $directories): array
{
    $files = [];
    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);
    return $files;
}
