<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Client\HmrcCtGatewayClientInterface::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $reflection = new ReflectionClass(\eel_accounts\Client\HmrcCtGatewayClientInterface::class);

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClientInterface::class,
            'declares the complete Transaction Engine conversation contract',
            static function () use ($harness, $reflection): void {
                foreach (
                    [
                        'configurationStatus' => 1,
                        'submit' => 4,
                        'poll' => 4,
                        'delete' => 4,
                        'requestData' => 3,
                    ] as $methodName => $parameterCount
                ) {
                    $method = $reflection->getMethod($methodName);
                    $harness->assertSame($parameterCount, $method->getNumberOfParameters());
                    $harness->assertSame('array', (string)$method->getReturnType());
                }
            }
        );

        $harness->check(
            \eel_accounts\Client\HmrcCtGatewayClientInterface::class,
            'real and fake clients implement the same contract',
            static function () use ($harness): void {
                $harness->assertTrue(
                    is_subclass_of(
                        \eel_accounts\Client\HmrcCtGatewayClient::class,
                        \eel_accounts\Client\HmrcCtGatewayClientInterface::class
                    )
                );
                $harness->assertTrue(
                    is_subclass_of(
                        \eel_accounts\Client\FakeHmrcCtGatewayClient::class,
                        \eel_accounts\Client\HmrcCtGatewayClientInterface::class
                    )
                );
            }
        );
    }
);
