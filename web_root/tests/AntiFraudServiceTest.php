<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . 'AntiFraudService.php';

final class AntiFraudServiceTestHarness
{
    private AntiFraudService $service;

    public function __construct()
    {
        $this->service = AntiFraudService::instance();
    }

    public function run(): void
    {
        $this->testHeaderValueTakesPriorityOverCookie();
        $this->testRequestValueFallsBackToCookie();
        $this->testDetectClientPublicIpPrefersPublicForwardedAddress();
        $this->testDetectVendorForwardedEncodesKnownHeaders();
        $this->testDetectVendorPublicIpUsesConfiguredValue();

        fwrite(STDOUT, "AntiFraudService tests passed.\n");
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

    private function testDetectVendorForwardedEncodesKnownHeaders(): void
    {
        $this->withRequestState(
            [
                'HTTP_X_FORWARDED_FOR' => '198.51.100.25',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            [],
            function (): void {
                $this->assertSame(
                    'x-forwarded-for=198.51.100.25&x-forwarded-proto=https',
                    $this->service->detectVendorForwarded()
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

    private function withRequestState(array $server, array $cookie, callable $callback): void
    {
        $previousServer = $_SERVER;
        $previousCookie = $_COOKIE;

        $_SERVER = $server;
        $_COOKIE = $cookie;

        try {
            $callback();
        } finally {
            $_SERVER = $previousServer;
            $_COOKIE = $previousCookie;
        }
    }

    private function assertSame(mixed $expected, mixed $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'Assertion failed. Expected ' . var_export($expected, true) . ' but received ' . var_export($actual, true) . '.'
            );
        }
    }
}

(new AntiFraudServiceTestHarness())->run();
