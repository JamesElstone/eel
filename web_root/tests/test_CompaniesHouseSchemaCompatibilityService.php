<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support'
    . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CompaniesHouseSchemaCompatibilityService::class,
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseSchemaCompatibilityService::class,
            'builds and compiles a checksum-approved compatibility tree without changing sources',
            static function () use ($harness): void {
                $testRoot = test_tmp_directory() . DIRECTORY_SEPARATOR
                    . 'ch-schema-compat-' . bin2hex(random_bytes(5));
                $official = $testRoot . DIRECTORY_SEPARATOR . 'official';
                $validation = $testRoot . DIRECTORY_SEPARATOR . 'validation';
                $baseRelative = 'v1-0/schema/baseTypes-v-test.xsd';
                $companyRelative = 'v1-0/schema/CompanyData-v-test.xsd';
                $envelopeRelative = 'v1-0/schema/Egov_ch-v-test.xsd';
                foreach ([$baseRelative, $companyRelative, $envelopeRelative] as $relative) {
                    $directory = dirname(
                        $official . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $relative)
                    );
                    if (!is_dir($directory)) {
                        mkdir($directory, 0777, true);
                    }
                }
                $base = '<?xml version="1.0"?>'
                    . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" '
                    . 'targetNamespace="http://xmlgw.companieshouse.gov.uk">'
                    . '<xs:simpleType name="LegacyLargeDecimal"><xs:restriction base="xs:decimal">'
                    . '<xs:maxInclusive value="99999999999999999999.999999"/>'
                    . '</xs:restriction></xs:simpleType></xs:schema>';
                $company = '<?xml version="1.0"?>'
                    . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" '
                    . 'targetNamespace="http://xmlgw.companieshouse.gov.uk" '
                    . 'xmlns="http://xmlgw.companieshouse.gov.uk" elementFormDefault="qualified">'
                    . '<xs:include schemaLocation="baseTypes-v-test.xsd"/>'
                    . '<xs:element name="CompanyDataRequest" type="xs:string"/>'
                    . '</xs:schema>';
                $envelope = '<?xml version="1.0"?>'
                    . '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" '
                    . 'targetNamespace="http://www.govtalk.gov.uk/CM/envelope" '
                    . 'xmlns="http://www.govtalk.gov.uk/CM/envelope" elementFormDefault="qualified">'
                    . '<xs:element name="GovTalkMessage" type="xs:string"/>'
                    . '</xs:schema>';
                $bodies = [
                    $baseRelative => $base,
                    $companyRelative => $company,
                    $envelopeRelative => $envelope,
                ];
                foreach ($bodies as $relative => $body) {
                    file_put_contents(
                        $official . DIRECTORY_SEPARATOR
                            . str_replace('/', DIRECTORY_SEPARATOR, $relative),
                        $body
                    );
                }
                $baseHash = hash('sha256', $base);
                $service = new \eel_accounts\Service\CompaniesHouseSchemaCompatibilityService([
                    $baseRelative => [
                        'sha256' => $baseHash,
                        'transform' => 'large_decimal',
                        'count' => 1,
                    ],
                ]);
                $host = 'https://xmlgw.companieshouse.gov.uk/';
                $files = [];
                foreach ($bodies as $relative => $body) {
                    $url = $host . $relative;
                    $files[$url] = [
                        'source_url' => $url,
                        'relative_path' => $relative,
                        'sha256' => hash('sha256', $body),
                    ];
                }
                $prepared = $service->prepareAndCompile(
                    $official,
                    $validation,
                    $files,
                    [
                        'envelope' => $host . $envelopeRelative,
                        'company_data' => $host . $companyRelative,
                    ]
                );
                $harness->assertSame($base, file_get_contents(
                    $official . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $baseRelative)
                ));
                $compatibleBase = (string)file_get_contents(
                    $validation . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $baseRelative)
                );
                $harness->assertFalse(str_contains(
                    $compatibleBase,
                    '99999999999999999999.999999'
                ));
                $harness->assertSame(
                    \eel_accounts\Service\CompaniesHouseSchemaCompatibilityService::PROFILE,
                    (string)$prepared[$host . $baseRelative]['validation_profile']
                );
                $harness->assertSame(
                    hash('sha256', $compatibleBase),
                    (string)$prepared[$host . $baseRelative]['validation_sha256']
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\CompaniesHouseXmlSchemaValidationService::class,
            'captures invalid-schema warnings without writing into the response stream',
            static function () use ($harness): void {
                $schema = tempnam(test_tmp_directory(), 'ch-invalid-xsd-');
                if (!is_string($schema)) {
                    $harness->skip('Could not create the invalid schema fixture.');
                }
                file_put_contents(
                    $schema,
                    '<?xml version="1.0"?><xs:schema '
                        . 'xmlns:xs="http://www.w3.org/2001/XMLSchema">'
                        . '<xs:simpleType name="Broken"><xs:restriction base="xs:decimal">'
                        . '<xs:maxInclusive value="99999999999999999999.999999"/>'
                        . '</xs:restriction></xs:simpleType></xs:schema>'
                );
                $document = new DOMDocument();
                $document->loadXML('<Root/>');
                ob_start();
                try {
                    try {
                        (new \eel_accounts\Service\CompaniesHouseXmlSchemaValidationService())
                            ->validateDocument($document, $schema, 'fixture');
                        $harness->assertTrue(false, 'The invalid schema must fail validation.');
                    } catch (RuntimeException $exception) {
                        $harness->assertTrue(str_contains(
                            $exception->getMessage(),
                            'Invalid Schema'
                        ));
                    }
                    $harness->assertSame('', (string)ob_get_contents());
                } finally {
                    ob_end_clean();
                    @unlink($schema);
                }
            }
        );
    }
);
