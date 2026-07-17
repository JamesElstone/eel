<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->runInterface(
    \eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $reflection = new ReflectionClass(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface::class
        );

        $harness->check(
            \eel_accounts\Client\CompaniesHouseAccountsGatewayTransportInterface::class,
            'declares the accounts submission contract',
            static function () use ($harness, $reflection): void {
                $submit = $reflection->getMethod('submitAccounts');
                $status = $reflection->getMethod('getSubmissionStatus');

                $harness->assertSame(2, $submit->getNumberOfParameters());
                $harness->assertSame('array', (string)$submit->getReturnType());
                $harness->assertSame(2, $status->getNumberOfParameters());
                $harness->assertSame('array', (string)$status->getReturnType());
            }
        );
    }
);
