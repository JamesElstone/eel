<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcOutbound::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(HmrcOutbound::class, 'validates anti-fraud headers against HMRC sandbox', function () use ($harness): void {
        $companyId = 0;

        try {
            $companyId = (int)(InterfaceDB::fetchColumn('SELECT id FROM companies ORDER BY id LIMIT 1') ?: 0);
        } catch (Throwable $exception) {
            $harness->skip('Unable to load a company for HMRC anti-fraud testing: ' . $exception->getMessage());
        }

        if ($companyId <= 0) {
            $harness->skip('No company exists yet for HMRC anti-fraud testing.');
        }

        $hmrcMode = HelperFramework::normaliseEnvironmentMode(
            (string)(new CompanySettingsStore($companyId))->get('hmrc_mode', 'TEST')
        );
        $config = HmrcOutbound::antiFraudValidatorConfig($hmrcMode);

        try {
            HmrcOutbound::loadCredential(
                (string)($config['credential_tag'] ?? 'FPH_VALIDATOR'),
                $hmrcMode,
                (string)($config['keys_path'] ?? ''),
                (string)($config['credential_provider'] ?? 'HMRC')
            );
        } catch (Throwable $exception) {
            $harness->skip('HMRC FPH_VALIDATOR credentials are not configured: ' . $exception->getMessage());
        }

        $antiFraudConfig = (array)(AppConfigurationStore::config()['antifraud'] ?? []);
        if (trim((string)($antiFraudConfig['vendor_license_ids'] ?? '')) === '') {
            $harness->skip('antifraud.vendor_license_ids is blank.');
        }

        $previousServer = $_SERVER;
        $previousCookie = $_COOKIE;
        $previousGlobal = $GLOBALS['antifraud_data'] ?? null;

        $_SERVER['HTTP_X_ANTIFRAUD_CLIENT_BROWSER_JS_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) EEL Test Harness';
        $_SERVER['HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID'] = '550e8400-e29b-41d4-a716-446655440000';
        $_SERVER['HTTP_X_ANTIFRAUD_CLIENT_SCREENS'] = 'width=1920&height=1080&scaling-factor=1&colour-depth=24';
        $_SERVER['HTTP_X_ANTIFRAUD_CLIENT_TIMEZONE'] = 'UTC+00:00';
        $_SERVER['HTTP_X_ANTIFRAUD_CLIENT_WINDOW_SIZE'] = 'width=1440&height=900';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.25';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.25';
        $_SERVER['REMOTE_PORT'] = '443';
        unset($GLOBALS['antifraud_data']);

        try {
            $response = (new HmrcOutbound($config))->validateAntiFraudHeaders();
        } finally {
            $_SERVER = $previousServer;
            $_COOKIE = $previousCookie;

            if ($previousGlobal === null) {
                unset($GLOBALS['antifraud_data']);
            } else {
                $GLOBALS['antifraud_data'] = $previousGlobal;
            }
        }

        $statusCode = (int)($response['status_code'] ?? 0);
        $body = json_decode((string)($response['body'] ?? ''), true);

        $harness->assertTrue($statusCode >= 200 && $statusCode < 300);
        $harness->assertTrue(is_array($body));
    });
});
