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
$harness->check(ApplicationSettingsAction::class, 'reads checkbox values from ajax duplicate fields', function () use ($harness): void {
    $action = new ApplicationSettingsAction();
    $method = new ReflectionMethod(ApplicationSettingsAction::class, 'checkboxValue');
    $method->setAccessible(true);

    $request = new RequestFramework(
        ['page' => 'settings'],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ],
        [],
        [],
        '{"checked":["0","1"],"unchecked":"0"}'
    );

    $harness->assertSame(true, $method->invoke($action, $request, 'checked'));
    $harness->assertSame(false, $method->invoke($action, $request, 'unchecked'));
    $harness->assertSame(false, $method->invoke($action, $request, 'missing'));
});

$harness->check(ApplicationSettingsAction::class, 'maps topbar page checkboxes to disabled pages', function () use ($harness): void {
    $action = new ApplicationSettingsAction();
    $method = new ReflectionMethod(ApplicationSettingsAction::class, 'topbarDisabledPagesFromRequest');
    $method->setAccessible(true);

    $request = new RequestFramework(
        ['page' => 'settings'],
        [],
        [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ],
        [],
        [],
        '{"topbar_enabled_pages":["dashboard"]}'
    );

    $harness->assertSame(
        ['settings'],
        $method->invoke($action, $request, ['dashboard', 'settings'])
    );
});

$harness->check(ApplicationSettingsAction::class, 'explains application setting changes in plain language', function () use ($harness): void {
    $action = new ApplicationSettingsAction();
    $method = new ReflectionMethod(ApplicationSettingsAction::class, 'successFlashMessage');
    $method->setAccessible(true);

    $previousConfig = [
        'app_name' => 'eelKit Framework',
        'app_strapline' => 'Old strapline',
        'app_footer' => '',
        'brand-mark' => 'E',
        'developer_options' => true,
        'navigation' => [
            'default_order' => [
                'dashboard' => 10,
            ],
            'topbar_disabled_pages' => [],
            'hide_collapsed_link_initials' => false,
        ],
        'antifraud' => [
            'vendor_license_ids' => 'old',
            'vendor_product_name' => 'eelKit',
            'vendor_public_ip' => '',
            'vendor_version' => 'dev',
        ],
        'session' => [
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'Strict',
        ],
        'user_defaults' => [
            'new_user_otp_required' => true,
        ],
    ];

    $settings = $previousConfig;
    $settings['developer_options'] = false;
    $settings['navigation']['hide_collapsed_link_initials'] = true;

    $harness->assertSame(
        'Developer options are now off. Collapsed sidebar link initials are now hidden.',
        $method->invoke($action, $previousConfig, $settings, false)
    );

    $settings['developer_options'] = true;
    $settings['navigation']['hide_collapsed_link_initials'] = false;
    $settings['antifraud']['vendor_public_ip'] = '203.0.113.10';
    $harness->assertSame(
        'Anti-fraud header defaults updated. Vendor public IP looked up.',
        $method->invoke($action, $previousConfig, $settings, true)
    );
    $harness->assertSame(
        'No changes needed; application settings are already up to date.',
        $method->invoke($action, $previousConfig, $previousConfig, false)
    );

    $settings = $previousConfig;
    $settings['app_footer'] = 'Updated footer';
    $harness->assertSame(
        'Branding updated.',
        $method->invoke($action, $previousConfig, $settings, false)
    );

    $settings = $previousConfig;
    $settings['navigation']['topbar_disabled_pages'] = ['dashboard'];
    $harness->assertSame(
        'Page topbar visibility updated.',
        $method->invoke($action, $previousConfig, $settings, false)
    );

    $settings = $previousConfig;
    $settings['user_defaults']['new_user_otp_required'] = false;
    $harness->assertSame(
        'User defaults updated.',
        $method->invoke($action, $previousConfig, $settings, false)
    );
});
