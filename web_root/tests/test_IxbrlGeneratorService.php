<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\IxbrlGeneratorService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\IxbrlGeneratorService $service): void {
    $h->check($service::class, 'renders escaped facts contexts dimensions and units', static function () use ($h, $service): void {
        $fact = $service->renderFact(['qname' => 'ct:Label', 'context_ref' => 'ct', 'value' => '<value>']);
        $h->assertTrue(str_contains($fact, '&lt;value&gt;'));
        $xhtml = $service->renderDocument(['namespaces' => ['ct' => 'urn:test'], 'schema_refs' => ['taxonomy.xsd'], 'contexts' => [['id' => 'ct', 'identifier' => '01234567', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'dimensions' => ['ct:Axis' => 'ct:Member']]], 'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']], 'body' => $fact]);
        $h->assertTrue(str_starts_with(
            $xhtml,
            '<?xml version="1.0"?>' . "\n"
        ));
        $h->assertFalse(str_contains($xhtml, ' lang="en"'));
        $h->assertTrue(str_contains($xhtml, ' xml:lang="en"'));
        $h->assertFalse(str_contains($xhtml, '<!DOCTYPE'));
        $h->assertSame([], $service->validateStructure($xhtml, ['taxonomy.xsd']));
    });
    $h->check($service::class, 'renders negative numeric facts with the Inline XBRL sign attribute', static function () use ($h, $service): void {
        $negative = $service->renderFact(['qname' => 'ct:Amount', 'context_ref' => 'ct', 'value' => -118.66, 'numeric' => true, 'unit_ref' => 'GBP', 'decimals' => '2']);
        $positive = $service->renderFact(['qname' => 'ct:Amount', 'context_ref' => 'ct', 'value' => 118.66, 'numeric' => true, 'unit_ref' => 'GBP', 'decimals' => '2']);
        $zero = $service->renderFact(['qname' => 'ct:Amount', 'context_ref' => 'ct', 'value' => 0, 'numeric' => true, 'unit_ref' => 'GBP', 'decimals' => '2']);
        $h->assertTrue(str_contains($negative, ' sign="-"'));
        $h->assertTrue(str_contains($negative, '>118.66</ix:nonFraction>'));
        $h->assertTrue(!str_contains($positive, ' sign="-"'));
        $h->assertTrue(!str_contains($zero, ' sign="-"'));
    });
    $h->check($service::class, 'serializes boolean false and true using XBRL lexical values', static function () use ($h, $service): void {
        $falseFact = $service->renderFact([
            'qname' => 'ct:CompanyIsAPartnerInAFirm',
            'context_ref' => 'ct',
            'value' => false,
        ]);
        $trueFact = $service->renderFact([
            'qname' => 'ct:CompanyIsAPartnerInAFirm',
            'context_ref' => 'ct',
            'value' => true,
        ]);
        $h->assertTrue(str_contains($falseFact, '>false</ix:nonNumeric>'));
        $h->assertFalse(str_contains($falseFact, 'xsi:nil'));
        $h->assertTrue(str_contains($trueFact, '>true</ix:nonNumeric>'));
    });
    $h->check($service::class, 'recovers legacy Windows-1252 narrative facts as valid UTF-8', static function () use ($h, $service): void {
        $fact = $service->renderFact([
            'qname' => 'ct:Label',
            'context_ref' => 'ct',
            'value' => 'Citro' . chr(0xEB) . 'n & Sons',
        ]);
        $h->assertTrue(str_contains($fact, '>Citroën &amp; Sons</ix:nonNumeric>'));
        $h->assertFalse(str_contains($fact, '><'));
    });
    $h->check($service::class, 'supports safe styles and transformed human-readable values', static function () use ($h, $service): void {
        $amount = $service->renderFact([
            'qname' => 'ct:Amount', 'context_ref' => 'ct', 'value' => -1234.5,
            'numeric' => true, 'unit_ref' => 'GBP', 'decimals' => '2',
            'format' => 'ixt:numdotdecimal', 'display_value' => '1,234.50',
        ]);
        $date = $service->renderFact([
            'qname' => 'ct:PeriodEnd', 'context_ref' => 'ct', 'value' => '2025-12-31',
            'format' => 'ixt:datedaymonthyearen', 'display_value' => '31 December 2025',
        ]);
        $xhtml = $service->renderDocument([
            'namespaces' => ['ct' => 'urn:test', 'ixt' => 'http://www.xbrl.org/inlineXBRL/transformation/2015-02-26'],
            'contexts' => [['id' => 'ct', 'identifier' => '01234567', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']],
            'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']],
            'stylesheet' => '@page { size: A4 portrait; } .amount { text-align: right; }',
            'body' => '<p>' . $amount . '</p><p>' . $date . '</p>',
        ]);
        $h->assertTrue(str_contains($amount, 'format="ixt:numdotdecimal"'));
        $h->assertTrue(str_contains($amount, ' sign="-"'));
        $h->assertTrue(str_contains($amount, '>1,234.50</ix:nonFraction>'));
        $h->assertTrue(str_contains($date, 'format="ixt:datedaymonthyearen"'));
        $h->assertTrue(str_contains($date, '>31 December 2025</ix:nonNumeric>'));
        $h->assertTrue(str_contains($xhtml, '<style type="text/css">'));
        $h->assertTrue(str_contains($xhtml, '@page { size: A4 portrait; }'));
        $h->assertSame([], $service->validateStructure($xhtml));
        try {
            $service->renderDocument(['stylesheet' => '</style><script>alert(1)</script>']);
            $h->assertTrue(false);
        } catch (InvalidArgumentException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'cannot contain markup'));
        }
    });
    $h->check($service::class, 'renders explicit and typed dimensions in an entity segment', static function () use ($h, $service): void {
        $xhtml = $service->renderDocument([
            'namespaces' => ['ct' => 'urn:test'],
            'schema_refs' => ['taxonomy.xsd'],
            'contexts' => [[
                'id' => 'ct_trade',
                'identifier' => '01234567',
                'start_date' => '2025-01-01',
                'end_date' => '2025-12-31',
                'dimension_container' => 'segment',
                'dimensions' => ['ct:BusinessTypeDimension' => 'ct:Trade'],
                'typed_dimensions' => [[
                    'dimension' => 'ct:BusinessNameDimension',
                    'domain' => 'ct:BusinessNameDomain',
                    'value' => 'Example & Company',
                ]],
            ]],
            'body' => '<p>Trade computation</p>',
        ]);
        $h->assertTrue(str_contains($xhtml, '<xbrli:segment>'));
        $h->assertTrue(str_contains($xhtml, 'dimension="ct:BusinessTypeDimension">ct:Trade'));
        $h->assertTrue(str_contains($xhtml, '<xbrldi:typedMember dimension="ct:BusinessNameDimension">'));
        $h->assertTrue(str_contains($xhtml, '<ct:BusinessNameDomain>Example &amp; Company</ct:BusinessNameDomain>'));
        $h->assertSame([], $service->validateStructure($xhtml, ['taxonomy.xsd']));
    });
    $h->check($service::class, 'embeds a schema-compatible EEL artifact metadata identifier', static function () use ($h, $service): void {
        $xhtml = $service->renderDocument([
            'contexts' => [['id' => 'ct', 'identifier' => '01234567', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']],
            'metadata' => ['eel-evidence-artifact-id' => 'EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF'],
            'body' => '<p>Evidence</p>',
        ]);
        $h->assertTrue(str_contains($xhtml, 'name="eel-evidence-artifact-id"'));
        $h->assertTrue(str_contains($xhtml, 'EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF'));
        $h->assertSame([], $service->validateStructure($xhtml));
    });
});
