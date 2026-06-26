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
$harness->run(NavigationFramework::class);

$setConfig = static function (array $config): void {
    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $property->setValue(null, $config);
};

$baseConfig = AppConfigurationStore::config(true);

$withConfig = static function (array $config, callable $callback) use ($setConfig, $baseConfig): void {
    $setConfig($config);

    try {
        $callback();
    } finally {
        $setConfig($baseConfig);
    }
};

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            $removeDirectory($child);
            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
};

$withPageFiles = static function (array $files, callable $callback) use ($removeDirectory): void {
    $directory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'navigation-framework-pages';
    $removeDirectory($directory);

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create navigation fixture directory: ' . $directory);
    }

    try {
        foreach ($files as $filename => $content) {
            $path = $directory . DIRECTORY_SEPARATOR . $filename;

            if ($content === null) {
                mkdir($path);
                continue;
            }

            file_put_contents($path, $content);
        }

        $callback($directory);
    } finally {
        $removeDirectory($directory);
    }
};

$harness->check(NavigationFramework::class, 'includes developer-only pages when developer options are enabled', function () use ($harness, $withConfig, $withPageFiles, $baseConfig): void {
    $withPageFiles(['developer_page.php' => '<?php'], function (string $directory) use ($harness, $withConfig, $baseConfig): void {
        $config = $baseConfig;
        $config['developer_options'] = true;
        $config['navigation']['developer_only_pages'] = ['developer_page'];

        $withConfig($config, function () use ($harness, $directory): void {
            $items = (new NavigationFramework($directory, 'developer_page', '/?page='))->build();
            $keys = array_column($items, 'key');

            $harness->assertTrue(in_array('developer_page', $keys, true));
        });
    });
});

$harness->check(NavigationFramework::class, 'excludes developer-only pages when developer options are disabled', function () use ($harness, $withConfig, $withPageFiles, $baseConfig): void {
    $withPageFiles(['developer_page.php' => '<?php'], function (string $directory) use ($harness, $withConfig, $baseConfig): void {
        $config = $baseConfig;
        $config['developer_options'] = false;
        $config['navigation']['developer_only_pages'] = ['developer_page'];

        $withConfig($config, function () use ($harness, $directory): void {
            $items = (new NavigationFramework($directory, 'dashboard', '/?page='))->build();
            $keys = array_column($items, 'key');

            $harness->assertTrue(!in_array('developer_page', $keys, true));
        });
    });
});

$harness->check(NavigationFramework::class, 'discovers only regular page PHP files', function () use ($harness, $withPageFiles): void {
    $withPageFiles(
        [
            'alpha.php' => '<?php',
            'beta_page.php' => '<?php',
            'example.nav.php' => '<?php',
            '_private.php' => '<?php',
            '2invalid.php' => '<?php',
            'bad-name.php' => '<?php',
            'notes.txt' => 'not a page',
            'nested.php' => null,
        ],
        function (string $directory) use ($harness): void {
            $pageKeys = (new NavigationFramework($directory, 'alpha', '/?page='))->pageKeys();
            sort($pageKeys);

            $harness->assertSame(['alpha', 'beta_page'], $pageKeys);
        }
    );
});

$setConfig($baseConfig);
