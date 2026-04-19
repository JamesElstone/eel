<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

final class TestAntiFraudServiceHarness
{
    private AntiFraudService $service;

    public function __construct()
    {
        $this->service = AntiFraudService::instance();
    }

    public function run(): void
    {
        $this->runTest('instantiates successfully', function (): void {
            $this->assertTrue($this->service instanceof AntiFraudService);
        });
        $this->runTest('header value takes priority over cookie', [$this, 'testHeaderValueTakesPriorityOverCookie']);
        $this->runTest('request value falls back to cookie', [$this, 'testRequestValueFallsBackToCookie']);
        $this->runTest('init antifraud data stores X-AntiFraud values under af', [$this, 'testInitAntifraudDataStoresHeadersUnderAf']);
        $this->runTest('client public IP prefers public forwarded address', [$this, 'testDetectClientPublicIpPrefersPublicForwardedAddress']);
        $this->runTest('vendor forwarded builds by-for chain from client to vendor', [$this, 'testDetectVendorForwardedBuildsByForChain']);
        $this->runTest('vendor public IP uses configured value', [$this, 'testDetectVendorPublicIpUsesConfiguredValue']);
        $this->runTest('builds Gov headers from internal anti-fraud headers', [$this, 'testBuildGovHeadersFromInternalHeaders']);
        $this->runTest('includes authenticated user ids and MFA in anti-fraud headers', [$this, 'testAuthenticatedSessionHeadersAreIncluded']);
        $this->runTest('derives HMRC-shaped vendor license ids and version values', [$this, 'testVendorFieldsAreNormalisedForHmrc']);
        $this->runTest('encodes internal anti-fraud headers before Gov header translation', [$this, 'testInternalHeadersAreEncoded']);
        $this->runTest('derives cookie suffixes from antifraud field names', function (): void {
            $this->assertSame('client_timezone', $this->service->cookieSuffixFromField('Client-Timezone'));
        });
        $this->runTest('normalises blank optional strings to null', function (): void {
            $this->assertSame(null, $this->service->normaliseOptionalString('   '));
        });
        $this->runTest('percent-encodes non ascii characters for HMRC header values', function (): void {
            $this->assertSame('Cafe%C3%A9', $this->service->encodeUsAsciiPercent("Cafe\xC3\xA9"));
        });
        $this->runTest('extracts an IP address from host and port input', function (): void {
            $this->assertSame('198.51.100.25', $this->service->extractIp('198.51.100.25:443'));
        });
    }

    private function testHeaderValueTakesPriorityOverCookie(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID' => 'header-device',
            ],
            [
                'af_client_device_id' => 'cookie-device',
            ],
            function (): void {
                $this->assertSame('header-device', $this->service->requestValue('Client-Device-ID'));
            }
        );
    }

    private function testRequestValueFallsBackToCookie(): void
    {
        $this->withRequestState(
            [],
            [
                'af_client_timezone' => 'Europe/London',
            ],
            function (): void {
                $this->assertSame('Europe/London', $this->service->requestValue('Client-Timezone'));
            }
        );
    }

    private function testInitAntifraudDataStoresHeadersUnderAf(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID' => 'device-123',
                'HTTP_X_ANTIFRAUD_CLIENT_BROWSER_JS_USER_AGENT' => 'Mozilla/5.0 Test',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'HTTP_X_ANTIFRAUD_CLIENT_SCREENS' => 'width=1920&height=1080',
                'HTTP_X_ANTIFRAUD_CLIENT_TIMEZONE' => 'UTC+01:00',
                'HTTP_X_ANTIFRAUD_CLIENT_WINDOW_SIZE' => 'width=1440&height=900',
                'REMOTE_PORT' => '443',
            ],
            [],
            function (): void {
                $data = $this->service->getAntifraudData();

                $this->assertTrue(is_array($data['af'] ?? null));
                $this->assertSame('device-123', $data['af']['X-AntiFraud-Client-Device-ID'] ?? null);
                $this->assertSame('WEB_APP_VIA_SERVER', $data['af']['X-AntiFraud-Client-Connection-Method'] ?? null);
                $this->assertSame('EEL%20Accounts', $data['af']['X-AntiFraud-Vendor-Product-Name'] ?? null);
            }
        );
    }

    private function testDetectClientPublicIpPrefersPublicForwardedAddress(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_FORWARDED_FOR' => '10.0.0.1, 198.51.100.25',
                'REMOTE_ADDR' => '192.168.0.10',
            ],
            [],
            function (): void {
                $this->assertSame('198.51.100.25', $this->service->detectClientPublicIp());
            }
        );
    }

    private function testDetectVendorForwardedBuildsByForChain(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25, 203.0.113.10',
                'REMOTE_ADDR' => '10.0.0.5',
            ],
            [],
            function (): void {
                $this->assertSame(
                    'by=203.0.113.10&for=198.51.100.25,by=10.0.0.5&for=203.0.113.10,by=203.0.113.9&for=10.0.0.5',
                    $this->service->detectVendorForwarded('203.0.113.9')
                );
            }
        );
    }

    private function testDetectVendorPublicIpUsesConfiguredValue(): void
    {
        $this->withRequestState(
            [
                'SERVER_ADDR' => '10.0.0.5',
            ],
            [],
            function (): void {
                $this->assertSame('203.0.113.9', $this->service->detectVendorPublicIp('203.0.113.9'));
            }
        );
    }

    private function testBuildGovHeadersFromInternalHeaders(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID' => 'device-123',
                'HTTP_X_ANTIFRAUD_CLIENT_BROWSER_JS_USER_AGENT' => 'Mozilla/5.0 Test',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'HTTP_X_ANTIFRAUD_CLIENT_SCREENS' => 'width=1920&height=1080',
                'HTTP_X_ANTIFRAUD_CLIENT_TIMEZONE' => 'UTC+01:00',
                'HTTP_X_ANTIFRAUD_CLIENT_WINDOW_SIZE' => 'width=1440&height=900',
                'REMOTE_PORT' => '443',
            ],
            [],
            function (): void {
                $headers = $this->service->buildGovHeaders();

                $this->assertSame('device-123', $headers['Gov-Client-Device-ID'] ?? null);
                $this->assertSame('width=1920&height=1080', $headers['Gov-Client-Screens'] ?? null);
                $this->assertSame('UTC+01:00', $headers['Gov-Client-Timezone'] ?? null);
            }
        );
    }

    private function testAuthenticatedSessionHeadersAreIncluded(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID' => 'device-123',
                'HTTP_X_ANTIFRAUD_CLIENT_BROWSER_JS_USER_AGENT' => 'Mozilla/5.0 Test',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'HTTP_X_ANTIFRAUD_CLIENT_SCREENS' => 'width=1920&height=1080',
                'HTTP_X_ANTIFRAUD_CLIENT_TIMEZONE' => 'UTC+01:00',
                'HTTP_X_ANTIFRAUD_CLIENT_WINDOW_SIZE' => 'width=1440&height=900',
                'REMOTE_PORT' => '443',
            ],
            [],
            function (): void {
                $sessionAuthenticationService = new SessionAuthenticationService();
                $sessionAuthenticationService->startSession();
                $_SESSION = [];
                $sessionAuthenticationService->completeAuthentication(
                    42,
                    'device-123',
                    'session-hash',
                    'user@example.test',
                    [
                        'type' => 'TOTP',
                        'timestamp' => '2026-04-17T18:00:00.000Z',
                        'unique_reference' => 'abc123',
                    ]
                );

                $headers = $this->service->buildGovHeaders();

                $this->assertSame('eel-accounts-user-id=42&email=user%40example.test', $headers['Gov-Client-User-IDs'] ?? null);
                $this->assertSame(
                    'type=TOTP&timestamp=2026-04-17T18%3A00%3A00.000Z&unique-reference=abc123',
                    $headers['Gov-Client-Multi-Factor'] ?? null
                );
            }
        );
    }

    private function testVendorFieldsAreNormalisedForHmrc(): void
    {
        $config = $this->service->config();

        $this->assertSame(
            'eel-accounts=' . hash('sha256', '1234'),
            $config['vendor_license_ids'] ?? null
        );
        $this->assertSame('eel-accounts=dev', $config['vendor_version'] ?? null);
    }

    private function testInternalHeadersAreEncoded(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_ANTIFRAUD_CLIENT_DEVICE_ID' => 'device-123',
                'HTTP_X_ANTIFRAUD_CLIENT_BROWSER_JS_USER_AGENT' => 'Mozilla/5.0 Test',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'HTTP_X_ANTIFRAUD_CLIENT_SCREENS' => 'width=1920&height=1080',
                'HTTP_X_ANTIFRAUD_CLIENT_TIMEZONE' => 'UTC+01:00',
                'HTTP_X_ANTIFRAUD_CLIENT_WINDOW_SIZE' => 'width=1440&height=900',
                'REMOTE_PORT' => '443',
            ],
            [],
            function (): void {
                $sessionAuthenticationService = new SessionAuthenticationService();
                $sessionAuthenticationService->startSession();
                $_SESSION = [];
                $sessionAuthenticationService->completeAuthentication(
                    42,
                    'device-123',
                    'session-hash',
                    'user@example.test',
                    [
                        'type' => 'TOTP',
                        'timestamp' => '2026-04-17T18:00:00.000Z',
                        'unique_reference' => 'abc123',
                    ]
                );

                $headers = $this->service->getAntiFraudHeaders();

                $this->assertSame('type=TOTP&timestamp=2026-04-17T18%3A00%3A00.000Z&unique-reference=abc123', $headers['X-AntiFraud-Client-Multi-Factor'] ?? null);
                $this->assertSame('eel-accounts-user-id=42&email=user%40example.test', $headers['X-AntiFraud-Client-User-IDs'] ?? null);
                $this->assertSame('EEL%20Accounts', $headers['X-AntiFraud-Vendor-Product-Name'] ?? null);
                $this->assertSame('eel-accounts=dev', $headers['X-AntiFraud-Vendor-Version'] ?? null);
            }
        );
    }

    private function withRequestState(array $server, array $cookie, callable $callback): void
    {
        $previousServer = $_SERVER;
        $previousCookie = $_COOKIE;
        $previousGlobal = $GLOBALS['antifraud_data'] ?? null;

        $_SERVER = $server;
        $_COOKIE = $cookie;
        unset($GLOBALS['antifraud_data']);

        try {
            $callback();
        } finally {
            $_SERVER = $previousServer;
            $_COOKIE = $previousCookie;
            if ($previousGlobal === null) {
                unset($GLOBALS['antifraud_data']);
            } else {
                $GLOBALS['antifraud_data'] = $previousGlobal;
            }
        }
    }

    private function runTest(string $description, callable $callback): void
    {
        $callback();
        test_output_line('AntiFraudService: ' . $description . '.');
    }

    private function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Assertion failed. Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }

    private function assertTrue(bool $condition): void
    {
        if (!$condition) {
            throw new RuntimeException('Assertion failed. Expected condition to be true.');
        }
    }
}

(new TestAntiFraudServiceHarness())->run();
