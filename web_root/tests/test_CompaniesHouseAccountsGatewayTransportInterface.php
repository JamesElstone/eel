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
                $prepare = $reflection->getMethod('prepareAccounts');
                $submit = $reflection->getMethod('sendPreparedAccounts');
                $status = $reflection->getMethod('getSubmissionStatus');
                $companyData = $reflection->getMethod('checkCompanyAuthentication');
                $statusAck = $reflection->getMethod('acknowledgeSubmissionStatus');
                $document = $reflection->getMethod('getDocument');

                $harness->assertSame(3, $prepare->getNumberOfParameters());
                $harness->assertSame(\eel_accounts\Client\CompaniesHousePreparedAccountsRequest::class, (string)$prepare->getReturnType());
                $harness->assertSame(2, $submit->getNumberOfParameters());
                $harness->assertSame('array', (string)$submit->getReturnType());
                $harness->assertSame(5, $status->getNumberOfParameters());
                $harness->assertSame('array', (string)$status->getReturnType());
                $harness->assertSame(6, $companyData->getNumberOfParameters());
                $harness->assertSame(4, $statusAck->getNumberOfParameters());
                $harness->assertSame(5, $document->getNumberOfParameters());
            }
        );
    }
);
