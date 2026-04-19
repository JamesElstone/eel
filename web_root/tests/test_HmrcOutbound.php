<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HmrcOutbound::class, function (GeneratedServiceClassTestHarness $harness, HmrcOutbound $outbound): void {
    $harness->check(HmrcOutbound::class, 'normalises VAT numbers and strips GB prefix', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 200, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('123456789', $outbound->normaliseVatNumber(' GB 123 456 789 '));
    });

    $harness->check(HmrcOutbound::class, 'supports GB VAT lookups', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertTrue($outbound->supports('GB'));
        $harness->assertTrue(!$outbound->supports('FR'));
    });

    $harness->check(HmrcOutbound::class, 'returns an error result for blank VAT numbers', function () use ($harness): void {
        $outbound = new HmrcOutbound(
            ['mode' => 'TEST', 'test_base_url' => 'https://example.test'],
            static fn(): array => ['status_code' => 404, 'headers' => [], 'body' => '{}']
        );

        $harness->assertSame('error', $outbound->validate('GB', '')->status);
    });

    $harness->check(HmrcOutbound::class, 'builds anti-fraud validator config with HMRC defaults', function () use ($harness): void {
        $config = HmrcOutbound::antiFraudValidatorConfig('LIVE');

        $harness->assertSame('HMRC', $config['credential_provider'] ?? null);
        $harness->assertSame('FPH_VALIDATOR', $config['credential_tag'] ?? null);
        $harness->assertSame('GET', $config['validate_method'] ?? null);
        $harness->assertSame('LIVE', $config['mode'] ?? null);
    });

    $harness->check(HmrcOutbound::class, 'sends translated Gov headers through anti-fraud validation requests', function () use ($harness): void {
        $captured = null;
        $outbound = new HmrcOutbound(
            [
                'mode' => 'TEST',
                'test_base_url' => 'https://example.test',
                'validate_path' => '/test/fraud-prevention-headers/validate',
                'validate_method' => 'GET',
                'accept_header' => 'application/vnd.hmrc.1.0+json',
            ],
            static function (array $request) use (&$captured): array {
                if (($request['path'] ?? '') === '/oauth/token') {
                    return [
                        'status_code' => 200,
                        'headers' => [],
                        'body' => '{"access_token":"validator-token","expires_in":3600}',
                    ];
                }

                $captured = $request;

                return ['status_code' => 200, 'headers' => [], 'body' => '{}'];
            }
        );

        $response = $outbound->validateAntiFraudHeaders([
            'Gov-Client-Device-ID' => 'device-123',
            'Gov-Client-Timezone' => 'UTC+01:00',
        ]);

        $harness->assertSame(200, $response['status_code'] ?? null);
        $harness->assertSame('/test/fraud-prevention-headers/validate', $captured['path'] ?? null);
        $harness->assertSame('GET', $captured['validate_method'] ?? null);
        $harness->assertSame('device-123', $captured['headers']['Gov-Client-Device-ID'] ?? null);
        $harness->assertSame('application/vnd.hmrc.1.0+json', $captured['headers']['Accept'] ?? null);
    });

    $harness->check(HmrcOutbound::class, 'reports anti-fraud base URL configuration errors with anti-fraud wording', function () use ($harness): void {
        $outbound = new HmrcOutbound([
            'mode' => 'TEST',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'FPH_VALIDATOR',
            'keys_path' => APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-api.keys',
            'validate_path' => '/test/fraud-prevention-headers/validate',
        ]);

        try {
            $outbound->validateAntiFraudHeaders([
                'Gov-Client-Device-ID' => 'device-123',
            ]);
            $harness->assertTrue(false, 'Expected anti-fraud validation to fail when credentials are missing.');
        } catch (RuntimeException $exception) {
            $harness->assertSame(
                'HMRC anti-fraud validator credentials are not configured (HMRC / FPH_VALIDATOR / TEST): API key file was not found or is not readable: '
                . APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'missing-api.keys',
                $exception->getMessage()
            );
        }
    });

    $harness->check(HmrcOutbound::class, 'reports anti-fraud credentials with missing tag details', function () use ($harness): void {
        $tempPath = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'hmrc-antifraud-api-keys.csv';

        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0777, true);
        }

        file_put_contents(
            $tempPath,
            implode(PHP_EOL, [
                'PROVIDER,TAG,ENVIRONMENT,SCHEMA,URL,API_KEY',
                'HMRC,VAT_CHECK,TEST,HTTPS,test-api.service.hmrc.gov.uk,client-id:client-secret',
            ]) . PHP_EOL
        );

        $outbound = new HmrcOutbound([
            'mode' => 'TEST',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'FPH_VALIDATOR',
            'keys_path' => $tempPath,
            'validate_path' => '/test/fraud-prevention-headers/validate',
        ]);

        try {
            $outbound->validateAntiFraudHeaders([
                'Gov-Client-Device-ID' => 'device-123',
            ]);
            $harness->assertTrue(false, 'Expected anti-fraud validation to fail when the FPH_VALIDATOR tag is missing.');
        } catch (RuntimeException $exception) {
            $harness->assertSame(
                'HMRC anti-fraud validator credentials are not configured (HMRC / FPH_VALIDATOR / TEST).',
                $exception->getMessage()
            );
        }
    });
});
