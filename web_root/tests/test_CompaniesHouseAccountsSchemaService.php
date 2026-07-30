<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseAccountsSchemaService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseAccountsSchemaService::class,
            'downloads the pinned profile and recursive dependencies entirely offline',
            static function () use ($harness): void {
                $host = 'https://xmlgw.companieshouse.gov.uk';
                $urls = [
                    "$host/v1-0/schema/Egov_ch-v2-0.xsd",
                    "$host/v1-0/schema/forms/FormSubmission-v2-11.xsd",
                    "$host/v1-0/schema/forms/GetSubmissionStatus-v2-9.xsd",
                    "$host/v1-0/schema/forms/GetStatusAck-v1-1.xsd",
                    "$host/v1-0/schema/CompanyData-v3-6.xsd",
                    "$host/v1-0/schema/forms/GetDocument-v1-1.xsd",
                ];
                $rows = '';
                foreach (array_slice($urls, 1) as $url) {
                    $rows .= '<tr><td><a href="' . $url . '">' . basename($url) . '</a></td><td>Live</td><td>01/01/2026</td><td>02/01/2026</td></tr>';
                }
                $rows .= '<tr><td><a href="/v1-0/schema/forms/FormCommon-v1-0.xsd">'
                    . 'FormCommon-v1-0.xsd</a></td><td>Deprecated</td></tr>';
                $schemas = [
                    $urls[0] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.govtalk.gov.uk/CM/envelope" elementFormDefault="qualified"><xs:element name="GovTalkMessage"><xs:complexType><xs:sequence><xs:any minOccurs="0" maxOccurs="unbounded" processContents="skip"/></xs:sequence></xs:complexType></xs:element></xs:schema>',
                    $urls[1] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk/Header" elementFormDefault="qualified"><xs:include schemaLocation="FormCommon-v1-0.xsd"/><xs:element name="FormSubmission"><xs:complexType><xs:sequence><xs:any minOccurs="0" maxOccurs="unbounded" processContents="skip"/></xs:sequence></xs:complexType></xs:element></xs:schema>',
                    $urls[2] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="GetSubmissionStatus" type="xs:string"/></xs:schema>',
                    $urls[3] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="StatusAck" type="xs:string"/></xs:schema>',
                    $urls[4] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="CompanyDataRequest" type="xs:string"/></xs:schema>',
                    $urls[5] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="GetDocument" type="xs:string"/></xs:schema>',
                    "$host/v1-0/schema/forms/FormCommon-v1-0.xsd" => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:simpleType name="Unused"><xs:restriction base="xs:string"/></xs:simpleType></xs:schema>',
                ];
                $calls = [];
                $downloadRound = 0;
                $fetcher = static function (string $url) use (&$calls, &$downloadRound, $rows, $schemas): array {
                    $calls[] = $url;
                    if ($url === \eel_accounts\Service\CompaniesHouseAccountsSchemaService::SOURCE_URL) {
                        $downloadRound++;
                    }
                    $body = $url === \eel_accounts\Service\CompaniesHouseAccountsSchemaService::SOURCE_URL
                        ? '<html><table>' . $rows . '</table></html>'
                        : ($schemas[$url] ?? null);
                    if (!is_string($body)) { return ['status_code'=>404,'headers'=>[],'body'=>'','final_url'=>$url]; }
                    return [
                        'status_code'=>200,
                        'headers'=>[
                            'ETag'=>'fixture-' . $downloadRound,
                            'Last-Modified'=>'round-' . $downloadRound,
                        ],
                        'body'=>$body,
                        'final_url'=>$url,
                    ];
                };
                $testRoot = test_tmp_directory() . DIRECTORY_SEPARATOR
                    . 'eel-ch-schema-' . bin2hex(random_bytes(5));
                $cache = $testRoot . DIRECTORY_SEPARATOR . 'companies_house'
                    . DIRECTORY_SEPARATOR . 'assets';
                $staging = test_tmp_directory() . DIRECTORY_SEPARATOR
                    . 'companies-house-schema-staging-' . bin2hex(random_bytes(5));
                $validation = $testRoot . DIRECTORY_SEPARATOR . 'companies_house'
                    . DIRECTORY_SEPARATOR . 'validation'
                    . DIRECTORY_SEPARATOR . 'libxml-v1';
                $service = new \eel_accounts\Service\CompaniesHouseAccountsSchemaService(
                    $fetcher,
                    $cache,
                    $staging,
                    $validation
                );
                $first = $service->refreshInstalledSchemas();
                $second = $service->refreshInstalledSchemas();
                $harness->assertSame(true, $first['success']);
                $harness->assertSame(true, $first['changed']);
                $harness->assertSame(false, $second['changed']);
                $fileIdentities = static function (array $files): array {
                    foreach ($files as &$file) {
                        unset(
                            $file['checked_at'],
                            $file['verified_at'],
                            $file['validation_verified_at']
                        );
                    }
                    unset($file);
                    return $files;
                };
                $harness->assertSame(
                    $fileIdentities($first['files']),
                    $fileIdentities($second['files'])
                );
                $harness->assertSame($cache, $first['root_path']);
                $harness->assertSame($validation, $first['validation_root_path']);
                $harness->assertSame('libxml-v1', $first['validation_profile']);
                $harness->assertTrue(
                    is_file($cache . DIRECTORY_SEPARATOR . 'v1-0'
                        . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR
                        . 'CompanyData-v3-6.xsd')
                );
                $harness->assertFalse(is_dir($cache . DIRECTORY_SEPARATOR . 'snapshots'));
                $harness->assertTrue(is_file(
                    $validation . DIRECTORY_SEPARATOR . 'v1-0'
                        . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR
                        . 'CompanyData-v3-6.xsd'
                ));
                $harness->assertTrue(in_array("$host/v1-0/schema/forms/FormCommon-v1-0.xsd", $calls, true));
                $harness->assertSame(7, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM companies_house_schema_files'));
                $harness->assertSame(
                    'deprecated',
                    (string)\InterfaceDB::fetchColumn(
                        'SELECT catalogue_status
                         FROM companies_house_schema_files
                         WHERE schema_name = :schema_name',
                        ['schema_name' => 'FormCommon-v1-0.xsd']
                    )
                );

                $xml = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body><FormSubmission xmlns="http://xmlgw.companieshouse.gov.uk/Header"/></Body></GovTalkMessage>';
                $validated = (new \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator())->validateAccountsRequest($xml, $first);
                $harness->assertSame(true, $validated['success']);

                $callsBeforeInstalledCheck = count($calls);
                $installed = $service->installedSchemas();
                $harness->assertSame($second['files'], $installed['files']);
                $harness->assertSame($callsBeforeInstalledCheck, count($calls));
                $companyData = $service->installedSchemasForOperation('company_data');
                $harness->assertSame(
                    ['CompanyData-v3-6.xsd', 'Egov_ch-v2-0.xsd'],
                    array_values(array_map(
                        static fn (array $file): string => basename((string)$file['relative_path']),
                        (array)$companyData['files']
                    ))
                );
                $companyDataStatus = $service->fetchOperationStatus('company_data');
                $harness->assertSame(true, (bool)$companyDataStatus['state']['ready']);
                $harness->assertSame(2, (int)$companyDataStatus['state']['file_count']);
                $harness->assertSame($callsBeforeInstalledCheck, count($calls));
                $companyDataValidationPath = $validation . DIRECTORY_SEPARATOR . 'v1-0'
                    . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR
                    . 'CompanyData-v3-6.xsd';
                unlink($companyDataValidationPath);
                try {
                    $service->installedSchemasForOperation('company_data');
                    $harness->assertTrue(
                        false,
                        'A missing validation asset must block filing.'
                    );
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains(
                        $exception->getMessage(),
                        'validation asset is missing or has changed'
                    ));
                }
                $service->refreshInstalledSchemas();
                $harness->assertTrue(is_file($companyDataValidationPath));

                $legacyRoot = $testRoot . DIRECTORY_SEPARATOR . 'companies_house'
                    . DIRECTORY_SEPARATOR . 'schema'
                    . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . str_repeat('c', 64);
                $storedFiles = \InterfaceDB::fetchAll('SELECT relative_path FROM companies_house_schema_files');
                foreach ($storedFiles as $storedFile) {
                    $relative = str_replace('/', DIRECTORY_SEPARATOR, (string)$storedFile['relative_path']);
                    $source = $cache . DIRECTORY_SEPARATOR . $relative;
                    $destination = $legacyRoot . DIRECTORY_SEPARATOR . $relative;
                    if (!is_dir(dirname($destination))) {
                        mkdir(dirname($destination), 0777, true);
                    }
                    copy($source, $destination);
                }
                $migrated = $service->refreshInstalledSchemas();
                $harness->assertSame(false, $migrated['changed']);
                $harness->assertFalse(is_dir($legacyRoot));

                $companyDataPath = $cache . DIRECTORY_SEPARATOR . 'v1-0'
                    . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR
                    . 'CompanyData-v3-6.xsd';
                $companyData = file_get_contents($companyDataPath);
                file_put_contents($companyDataPath, (string)$companyData . "\n<!-- changed -->");
                try {
                    try {
                        $service->installedSchemas();
                        $harness->assertTrue(false, 'A damaged installed schema must block filing.');
                    } catch (RuntimeException $exception) {
                        $harness->assertTrue(str_contains(
                            $exception->getMessage(),
                            'missing or has changed'
                        ));
                    }
                    $service->refreshInstalledSchemas();
                    $harness->assertTrue(false, 'Changed content at an existing pathname must fail.');
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains(
                        $exception->getMessage(),
                        'changed an existing schema pathname'
                    ));
                } finally {
                    file_put_contents($companyDataPath, (string)$companyData);
                }

                InterfaceDB::prepareExecute(
                    'DELETE FROM companies_house_schema_dependencies
                     WHERE child_file_id = (
                         SELECT id FROM companies_house_schema_files
                         WHERE relative_path = :path
                     )',
                    ['path' => 'v1-0/schema/forms/FormCommon-v1-0.xsd']
                );
                try {
                    $service->installedSchemas();
                    $harness->assertTrue(false, 'An incomplete dependency inventory must block filing.');
                } catch (RuntimeException $exception) {
                    $harness->assertTrue(str_contains(
                        $exception->getMessage(),
                        'dependency inventory is incomplete'
                    ));
                }
                $service->refreshInstalledSchemas();

                $newVersionSource = tempnam(test_tmp_directory(), 'ch-schema-new-version-');
                if (!is_string($newVersionSource)) {
                    $harness->skip('Could not create a new-version schema fixture.');
                }
                file_put_contents(
                    $newVersionSource,
                    '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"/>'
                );
                try {
                    $publish = new ReflectionMethod($service, 'publishFile');
                    $publish->setAccessible(true);
                    $publish->invoke(
                        $service,
                        $newVersionSource,
                        'v1-0/schema/CompanyData-v3-7.xsd',
                        hash_file('sha256', $newVersionSource)
                    );
                    $harness->assertTrue(is_file($companyDataPath));
                    $harness->assertTrue(is_file(
                        $cache . DIRECTORY_SEPARATOR . 'v1-0'
                            . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR
                            . 'CompanyData-v3-7.xsd'
                    ));
                } finally {
                    @unlink($newVersionSource);
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\CompaniesHouseAccountsSchemaService::class,
            'blocks a pinned schema that is no longer Live before downloading the profile',
            static function () use ($harness): void {
                $calls = [];
                $html = '<html><table>'
                    . '<tr><td><a href="/v1-0/schema/forms/FormSubmission-v2-11.xsd">FormSubmission-v2-11.xsd</a></td><td>Deprecated</td></tr>'
                    . '<tr><td><a href="/v1-0/schema/forms/GetSubmissionStatus-v2-9.xsd">GetSubmissionStatus-v2-9.xsd</a></td><td>Live</td></tr>'
                    . '<tr><td><a href="/v1-0/schema/forms/GetStatusAck-v1-1.xsd">GetStatusAck-v1-1.xsd</a></td><td>Live</td></tr></table></html>';
                $fetcher = static function (string $url) use (&$calls, $html): array { $calls[]=$url; return ['status_code'=>200,'headers'=>[],'body'=>$html,'final_url'=>$url]; };
                $service = new \eel_accounts\Service\CompaniesHouseAccountsSchemaService(
                    $fetcher,
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'eel-ch-schema-' . bin2hex(random_bytes(5)),
                    test_tmp_directory() . DIRECTORY_SEPARATOR
                        . 'companies-house-schema-staging-' . bin2hex(random_bytes(5))
                );
                try { $service->refreshInstalledSchemas(); $harness->assertTrue(false, 'Deprecated profile should block.'); }
                catch (RuntimeException $exception) { $harness->assertTrue(str_contains($exception->getMessage(), 'software update is required')); }
                $harness->assertSame(1, count($calls));
            }
        );
    }
);
