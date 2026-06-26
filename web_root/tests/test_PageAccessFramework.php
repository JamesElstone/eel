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
$harness->run(PageAccessFramework::class);

$setConfig = static function (array $config): void {
    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $property->setValue(null, $config);
};

$baseConfig = AppConfigurationStore::config(true);

$harness->check(PageAccessFramework::class, 'marks configured pages as developer-only', function () use ($harness, $setConfig, $baseConfig): void {
    $config = $baseConfig;
    $config['navigation']['developer_only_pages'] = ['developer_page'];
    $setConfig($config);

    $harness->assertTrue(PageAccessFramework::isDeveloperOnlyPage('developer_page'));
    $harness->assertTrue(!PageAccessFramework::isDeveloperOnlyPage('dashboard'));
});

$harness->check(PageAccessFramework::class, 'only allows developer-only pages when developer options are enabled', function () use ($harness, $setConfig, $baseConfig): void {
    $config = $baseConfig;
    $config['developer_options'] = false;
    $config['navigation']['developer_only_pages'] = ['developer_page'];
    $setConfig($config);

    $harness->assertTrue(!PageAccessFramework::isPageAvailable('developer_page'));
    $harness->assertTrue(PageAccessFramework::isPageAvailable('dashboard'));

    $config['developer_options'] = true;
    $setConfig($config);

    $harness->assertTrue(PageAccessFramework::isPageAvailable('developer_page'));
});

AppConfigurationStore::config(true);
