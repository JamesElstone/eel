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
    }
);
