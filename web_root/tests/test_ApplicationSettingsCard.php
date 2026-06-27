<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once APP_CARDS . 'application_settings.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->check(_application_settingsCard::class, 'renders user defaults OTP requirement state', function () use ($harness): void {
    $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
    $property->setAccessible(true);
    $baseConfig = AppConfigurationStore::config(true);

    try {
        $config = $baseConfig;
        $config['user_defaults']['new_user_otp_required'] = false;
        $property->setValue(null, $config);

        $html = (new _application_settingsCard())->render([
            'page' => [
                'csrf_token' => 'test-csrf',
                'page_cards' => ['application_settings'],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<legend>User Defaults</legend>'));
        $harness->assertTrue(str_contains($html, 'name="new_user_otp_required"'));
        $harness->assertTrue(str_contains($html, '<option value="1">Required</option>'));
        $harness->assertTrue(str_contains($html, '<option value="0" selected>Optional</option>'));

        $config['user_defaults']['new_user_otp_required'] = true;
        $property->setValue(null, $config);
        $html = (new _application_settingsCard())->render([
            'page' => [
                'csrf_token' => 'test-csrf',
                'page_cards' => ['application_settings'],
            ],
        ]);

        $harness->assertTrue(str_contains($html, '<option value="1" selected>Required</option>'));
        $harness->assertTrue(str_contains($html, '<option value="0">Optional</option>'));
    } finally {
        $property->setValue(null, $baseConfig);
    }
});
