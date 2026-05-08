<?php
/**
 * EEL Accounts
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

$harness->check(NavigationFramework::class, 'includes developer-only pages when developer options are enabled', function () use ($harness, $setConfig, $baseConfig): void {
    $config = $baseConfig;
    $config['developer_options'] = true;
    $config['navigation']['developer_only_pages'] = ['test'];
    $setConfig($config);

    $items = (new NavigationFramework(APP_PAGES, 'dashboard', '/?page='))->build();
    $keys = array_column($items, 'key');

    $harness->assertTrue(in_array('test', $keys, true));
});

$harness->check(NavigationFramework::class, 'excludes developer-only pages when developer options are disabled', function () use ($harness, $setConfig, $baseConfig): void {
    $config = $baseConfig;
    $config['developer_options'] = false;
    $config['navigation']['developer_only_pages'] = ['test'];
    $setConfig($config);

    $items = (new NavigationFramework(APP_PAGES, 'dashboard', '/?page='))->build();
    $keys = array_column($items, 'key');

    $harness->assertTrue(!in_array('test', $keys, true));
});

AppConfigurationStore::config(true);
