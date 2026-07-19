<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\IxbrlTaxComputationService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\IxbrlTaxComputationService $service): void {
    $h->check($service::class, 'fails closed without a locked filing context', static function () use ($h, $service): void { $result = $service->generateFilingExport(0, 0, 0); $h->assertSame(false, $result['success']); });
    $h->check($service::class, 'orders DPL before adjustments and omits optional null facts', static function () use ($h, $service): void {
        $schema = test_tmp_directory() . DIRECTORY_SEPARATOR . 'ct-computation-entry-point.xsd';
        file_put_contents($schema, '<?xml version="1.0"?><xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:ct"/>');
        $mapping = static fn(int $id, string $key, string $section, string $label): array => [
            'id' => $id, 'canonical_key' => $key, 'taxonomy_concept' => 'ct:Amount', 'namespace_uri' => 'urn:ct',
            'local_name' => 'Amount', 'value_type' => 'numeric', 'period_type' => 'duration', 'context_profile' => 'ct_period',
            'unit_ref' => 'GBP', 'decimals_value' => '2', 'dimensions_json' => null, 'sign_multiplier' => 1,
            'presentation_section' => $section, 'presentation_label' => $label, 'null_policy' => 'omit', 'is_required' => 0, 'sort_order' => 100,
        ];
        $mappings = [
            array_replace($mapping(2, 'capital', 'capital_allowances', 'Capital allowances'), ['source_value' => 20.0]),
            array_replace($mapping(1, 'profit', 'detailed_profit_and_loss', 'Detailed profit'), ['source_value' => 100.0]),
        ];
        $method = new ReflectionMethod($service::class, 'renderMappedDocument'); $method->setAccessible(true);
        $rendered = $method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), [
            'facts' => ['profit' => 100.0, 'capital' => 20.0],
            'run' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
            'model' => ['identity' => ['company_number' => '01234567']],
        ], ['combined_dpl_entry_point_path' => $schema, 'entry_point_path' => null], $mappings);
        $xhtml = (string)$rendered['xhtml'];
        $h->assertTrue(strpos($xhtml, 'Detailed Profit And Loss') < strpos($xhtml, 'Capital Allowances'));
    });
});
