<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimSchemaService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimSchemaService $service): void {
        $harness->check($service::class, 'accepts only real ISO calendar dates', static function () use ($harness, $service): void {
            $method = new ReflectionMethod($service, 'isDate');
            $method->setAccessible(true);
            $harness->assertTrue((bool)$method->invoke($service, '2026-04-01'));
            $harness->assertFalse((bool)$method->invoke($service, '2026-02-30'));
        });
        $harness->check($service::class, 'catalogues full nested paths and effective leaf metadata', static function () use ($harness, $service): void {
            $directory = test_tmp_directory() . DIRECTORY_SEPARATOR . 'rim-schema-inspection';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create the RIM schema fixture directory.');
            }
            $path = $directory . DIRECTORY_SEPARATOR . 'ct-rim.xsd';
            file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:ct="urn:test:ct" targetNamespace="urn:test:ct" elementFormDefault="qualified">
  <xs:complexType name="MoneyType"><xs:simpleContent><xs:extension base="xs:decimal"/></xs:simpleContent></xs:complexType>
  <xs:element name="IRenvelope">
    <xs:complexType><xs:sequence>
      <xs:element name="CompanyTaxReturn"><xs:complexType><xs:sequence>
        <xs:element name="CompanyInformation"><xs:complexType><xs:sequence>
          <xs:element name="CompanyName"><xs:simpleType><xs:restriction base="xs:string"/></xs:simpleType></xs:element>
        </xs:sequence></xs:complexType></xs:element>
        <xs:element minOccurs="0" name="CompanyTaxCalculation"><xs:complexType><xs:sequence>
          <xs:element name="CorporationTax" type="ct:MoneyType"/>
        </xs:sequence></xs:complexType></xs:element>
      </xs:sequence></xs:complexType></xs:element>
    </xs:sequence></xs:complexType>
  </xs:element>
</xs:schema>
XML);
            $rows = $service->inspectSchemaFile($path);
            $byPath = [];
            foreach ($rows as $row) { $byPath[(string)$row['component_path']] = $row; }
            $namePath = 'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName';
            $taxPath = 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/CorporationTax';
            $harness->assertSame('xs:string', $byPath[$namePath]['data_type']);
            $harness->assertSame('ct:MoneyType', $byPath[$taxPath]['data_type']);
            $harness->assertSame(null, $byPath['IRenvelope/CompanyTaxReturn/CompanyTaxCalculation']['data_type']);
            $harness->assertSame('IRenvelope/CompanyTaxReturn/CompanyTaxCalculation', $byPath[$taxPath]['parent_path']);
            $harness->assertSame(1, $byPath[$taxPath]['is_leaf']);
            $harness->assertSame(0, $byPath[$taxPath]['is_required']);
            $harness->assertTrue((int)$byPath[$taxPath]['sequence_order'] > (int)$byPath[$namePath]['sequence_order']);
        });
    }
);
