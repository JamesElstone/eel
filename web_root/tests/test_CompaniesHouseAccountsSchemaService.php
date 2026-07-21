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
                ];
                $rows = '';
                foreach (array_slice($urls, 1) as $url) {
                    $rows .= '<tr><td><a href="' . $url . '">' . basename($url) . '</a></td><td>Live</td><td>01/01/2026</td><td>02/01/2026</td></tr>';
                }
                $schemas = [
                    $urls[0] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://www.govtalk.gov.uk/CM/envelope" elementFormDefault="qualified"><xs:element name="GovTalkMessage"><xs:complexType><xs:sequence><xs:any minOccurs="0" maxOccurs="unbounded" processContents="skip"/></xs:sequence></xs:complexType></xs:element></xs:schema>',
                    $urls[1] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk/Header" elementFormDefault="qualified"><xs:include schemaLocation="FormCommon-v1-0.xsd"/><xs:element name="FormSubmission"><xs:complexType><xs:sequence><xs:any minOccurs="0" maxOccurs="unbounded" processContents="skip"/></xs:sequence></xs:complexType></xs:element></xs:schema>',
                    $urls[2] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="GetSubmissionStatus" type="xs:string"/></xs:schema>',
                    $urls[3] => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="http://xmlgw.companieshouse.gov.uk"><xs:element name="GetStatusAck" type="xs:string"/></xs:schema>',
                    "$host/v1-0/schema/forms/FormCommon-v1-0.xsd" => '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:simpleType name="Unused"><xs:restriction base="xs:string"/></xs:simpleType></xs:schema>',
                ];
                $calls = [];
                $fetcher = static function (string $url) use (&$calls, $rows, $schemas): array {
                    $calls[] = $url;
                    $body = $url === \eel_accounts\Service\CompaniesHouseAccountsSchemaService::SOURCE_URL
                        ? '<html><table>' . $rows . '</table></html>'
                        : ($schemas[$url] ?? null);
                    if (!is_string($body)) { return ['status_code'=>404,'headers'=>[],'body'=>'','final_url'=>$url]; }
                    return ['status_code'=>200,'headers'=>['ETag'=>'fixture'],'body'=>$body,'final_url'=>$url];
                };
                $cache = sys_get_temp_dir() . '/eel-ch-schema-' . bin2hex(random_bytes(5));
                $service = new \eel_accounts\Service\CompaniesHouseAccountsSchemaService($fetcher, $cache);
                $first = $service->ensureCurrent();
                $second = $service->ensureCurrent();
                $harness->assertSame(true, $first['success']);
                $harness->assertSame(true, $first['changed']);
                $harness->assertSame(false, $second['changed']);
                $harness->assertTrue(in_array("$host/v1-0/schema/forms/FormCommon-v1-0.xsd", $calls, true));
                $harness->assertSame(5, (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM companies_house_schema_files WHERE snapshot_id = :id', ['id'=>$first['snapshot_id']]));

                $xml = '<?xml version="1.0"?><GovTalkMessage xmlns="http://www.govtalk.gov.uk/CM/envelope"><Body><FormSubmission xmlns="http://xmlgw.companieshouse.gov.uk/Header"/></Body></GovTalkMessage>';
                $validated = (new \eel_accounts\Service\CompaniesHouseAccountsSchemaValidator())->validateAccountsRequest($xml, $first['manifest_sha256']);
                $harness->assertSame($first['snapshot_id'], $validated['snapshot_id']);
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
                $service = new \eel_accounts\Service\CompaniesHouseAccountsSchemaService($fetcher, sys_get_temp_dir() . '/eel-ch-schema-' . bin2hex(random_bytes(5)));
                try { $service->ensureCurrent(); $harness->assertTrue(false, 'Deprecated profile should block.'); }
                catch (RuntimeException $exception) { $harness->assertTrue(str_contains($exception->getMessage(), 'software update is required')); }
                $harness->assertSame(1, count($calls));
            }
        );
    }
);
