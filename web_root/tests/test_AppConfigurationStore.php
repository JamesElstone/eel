<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AppConfigurationStore::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(AppConfigurationStore::class, 'loads the application config array', function () use ($harness): void {
        $config = AppConfigurationStore::config();

        $harness->assertTrue(is_array($config));
    });
});
