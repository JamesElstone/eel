<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\CharityCommissionRegistryClient::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $client = new \eel_accounts\Client\CharityCommissionRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => json_encode([[
                'charity_name' => 'Example Charity',
                'group_subsid_suffix' => 0,
                'date_of_registration' => '2012-03-04',
                'date_of_removal' => null,
            ]]),
        ]);
        $result = $client->lookup('1234567');
        $harness->assertSame(true, (bool)$result['success']);
        $harness->assertSame('Example Charity', (string)$result['records'][0]['registered_name']);
        $harness->assertSame('2012-03-04', (string)$result['records'][0]['registered_on']);
        $harness->assertSame(hash('sha256', json_encode([[
            'charity_name' => 'Example Charity',
            'group_subsid_suffix' => 0,
            'date_of_registration' => '2012-03-04',
            'date_of_removal' => null,
        ]])), (string)$result['response_sha256']);

        foreach ([
            404 => 'No registered charity was found.',
            500 => 'The Charity Commission lookup failed.',
        ] as $status => $message) {
            $failure = (new \eel_accounts\Client\CharityCommissionRegistryClient(
                static fn(array $request): array => ['status_code' => $status, 'body' => '']
            ))->lookup('1234567');
            $harness->assertSame(false, (bool)$failure['success']);
            $harness->assertSame($message, (string)$failure['errors'][0]);
        }
        $malformed = (new \eel_accounts\Client\CharityCommissionRegistryClient(
            static fn(array $request): array => ['status_code' => 200, 'body' => '{not-json']
        ))->lookup('1234567');
        $harness->assertSame(false, (bool)$malformed['success']);
        $harness->assertTrue(str_contains((string)$malformed['errors'][0], 'invalid response'));
        $empty = (new \eel_accounts\Client\CharityCommissionRegistryClient(
            static fn(array $request): array => ['status_code' => 200, 'body' => '[]']
        ))->lookup('1234567');
        $harness->assertSame(false, (bool)$empty['success']);
        $invalid = $client->lookup('not-a-number');
        $harness->assertSame(false, (bool)$invalid['success']);
    }
);

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\OscrCharityRegistryClient::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $client = new \eel_accounts\Client\OscrCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => json_encode([[
                'CharityName' => 'Scottish Example Charity',
                'Status' => 'Registered',
                'RegisteredDate' => '2015-06-01',
            ]]),
        ]);
        $result = $client->lookup('SC012345');
        $harness->assertSame(true, (bool)$result['success']);
        $harness->assertSame('oscr', (string)$result['records'][0]['authority']);
        $harness->assertSame(false, (bool)$client->lookup('12345')['success']);

        $removed = new \eel_accounts\Client\OscrCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => json_encode(['data' => [[
                'CharityName' => 'Former Scottish Charity',
                'Status' => 'Ceased',
                'RegisteredDate' => 'bad-date',
                'CeasedDate' => '2024-02-03',
            ]]]),
        ]);
        $removedResult = $removed->lookup(' sc012345 ');
        $harness->assertSame(true, (bool)$removedResult['success']);
        $harness->assertSame('SC012345', (string)$removedResult['records'][0]['registration_number']);
        $harness->assertSame(null, $removedResult['records'][0]['registered_on']);
        $harness->assertSame('2024-02-03', (string)$removedResult['records'][0]['removed_on']);

        foreach ([
            ['status_code' => 503, 'body' => ''],
            ['status_code' => 200, 'body' => '{bad-json'],
            ['status_code' => 200, 'body' => '[]'],
        ] as $response) {
            $failed = (new \eel_accounts\Client\OscrCharityRegistryClient(
                static fn(array $request): array => $response
            ))->lookup('SC012345');
            $harness->assertSame(false, (bool)$failed['success']);
        }
    }
);

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\CcniCharityRegistryClient::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $html = '<html><body><dl><dt>Charity name:</dt><dd>Northern Ireland Example</dd>'
            . '<dt>Charity number:</dt><dd>NIC100001</dd><dt>Status:</dt><dd>Registered</dd>'
            . '<dt>Date registered:</dt><dd>1 January 2016</dd><dt>Contact:</dt><dd>Example</dd></dl></body></html>';
        $client = new \eel_accounts\Client\CcniCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => $html,
        ]);
        $result = $client->lookup('NIC100001');
        $harness->assertSame(true, (bool)$result['success']);
        $harness->assertSame('Northern Ireland Example', (string)$result['records'][0]['registered_name']);

        $changedLayout = new \eel_accounts\Client\CcniCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => '<html><body>NIC100001 Unknown layout</body></html>',
        ]);
        $harness->assertSame(false, (bool)$changedLayout->lookup('NIC100001')['success']);

        $harness->assertSame(false, (bool)$client->lookup('100001')['success']);
        $wrongNumber = new \eel_accounts\Client\CcniCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 200,
            'body' => '<html><body>NIC999999 Charity name: Wrong Charity Status: Registered</body></html>',
        ]);
        $harness->assertSame(false, (bool)$wrongNumber->lookup('NIC100001')['success']);
        $httpFailure = new \eel_accounts\Client\CcniCharityRegistryClient(static fn(array $request): array => [
            'status_code' => 503,
            'body' => $html,
        ]);
        $harness->assertSame(false, (bool)$httpFailure->lookup('NIC100001')['success']);
    }
);
