<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\IxbrlGeneratorService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\IxbrlGeneratorService $service): void {
    $h->check($service::class, 'renders escaped facts contexts dimensions and units', static function () use ($h, $service): void {
        $fact = $service->renderFact(['qname' => 'ct:Label', 'context_ref' => 'ct', 'value' => '<value>']);
        $h->assertTrue(str_contains($fact, '&lt;value&gt;'));
        $xhtml = $service->renderDocument(['namespaces' => ['ct' => 'urn:test'], 'schema_refs' => ['taxonomy.xsd'], 'contexts' => [['id' => 'ct', 'identifier' => '01234567', 'start_date' => '2025-01-01', 'end_date' => '2025-12-31', 'dimensions' => ['ct:Axis' => 'ct:Member']]], 'units' => [['id' => 'GBP', 'measure' => 'iso4217:GBP']], 'body' => $fact]);
        $h->assertSame([], $service->validateStructure($xhtml, ['taxonomy.xsd']));
    });
    $h->check($service::class, 'uses the company upload naming convention', static function () use ($h, $service): void { $h->assertSame('accounts_ixbrl_01234567_20250101_20251231_tax_9.xhtml', $service->artifactFilename('01234567', '20250101', '20251231', 'tax', 9)); });
});
