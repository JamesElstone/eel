<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Client\CompaniesHousePreparedAccountsRequest::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $inventory = [
            'root_path' => 'third_party/companies_house/assets',
            'files' => [[
                'source_url' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/CompanyData-v3-6.xsd',
                'relative_path' => 'v1-0/schema/CompanyData-v3-6.xsd',
                'sha256' => str_repeat('a', 64),
            ]],
        ];
        $request = new \eel_accounts\Client\CompaniesHousePreparedAccountsRequest(
            'TEST',
            'ABC123',
            'A1',
            '<raw/>',
            '<redacted/>',
            ['secret'],
            $inventory
        );
        $harness->check($request::class, 'retains the exact validated request identity', static function () use ($harness, $request): void {
            $harness->assertSame('<raw/>', $request->requestXml());
            $harness->assertSame(
                'v1-0/schema/CompanyData-v3-6.xsd',
                $request->schemaInventory()['files'][0]['relative_path']
            );
        });
    }
);
