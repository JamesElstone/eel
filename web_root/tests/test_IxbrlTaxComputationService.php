<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\IxbrlTaxComputationService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\IxbrlTaxComputationService $service): void {
    $h->check($service::class, 'fails closed without a locked filing context', static function () use ($h, $service): void { $result = $service->generateFilingExport(0, 0, 0); $h->assertSame(false, $result['success']); });
    $h->check($service::class, 'orders DPL before adjustments and omits optional null facts', static function () use ($h, $service): void {
        $schemaRef = 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd';
        $mapping = static fn(int $id, string $key, string $localName, string $section, string $label): array => [
            'id' => $id, 'canonical_key' => $key, 'taxonomy_concept' => 'ct:Amount', 'namespace_uri' => 'urn:ct',
            'local_name' => $localName, 'value_type' => 'numeric', 'period_type' => 'duration',
            'context_profile' => \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
            'unit_ref' => 'GBP', 'decimals_value' => '2', 'dimensions_json' => null, 'sign_multiplier' => 1,
            'presentation_section' => $section, 'presentation_label' => $label, 'null_policy' => 'omit', 'is_required' => 0, 'sort_order' => 100,
        ];
        $mappings = [
            array_replace($mapping(2, 'capital', 'TotalCapitalAllowances', 'capital_allowances', 'Capital allowances'), ['source_value' => 20.0]),
            array_replace($mapping(1, 'profit', 'ProfitLossPerAccounts', 'detailed_profit_and_loss', 'Detailed profit'), ['source_value' => 100.0]),
            array_replace($mapping(3, 'taxable', 'ProfitsBeforeOtherDeductionsAndReliefs', 'tax_liability', 'Taxable profit'), [
                'source_value' => 80.0,
                'context_profile' => \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            ]),
        ];
        $method = new ReflectionMethod($service::class, 'renderMappedDocument'); $method->setAccessible(true);
        $rendered = $method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), [
            'facts' => ['profit' => 100.0, 'capital' => 20.0],
            'run' => ['period_start' => '2025-01-01', 'period_end' => '2025-12-31'],
            'model' => ['identity' => ['company_number' => '01234567', 'company_name' => 'Example Electricals Limited']],
        ], $mappings, $schemaRef);
        $xhtml = (string)$rendered['xhtml'];
        $h->assertTrue(strpos($xhtml, 'Detailed Profit And Loss') < strpos($xhtml, 'Capital Allowances'));
        $h->assertTrue(str_contains($xhtml, 'CT period 2025-01-01 to 2025-12-31'));
        $h->assertSame($schemaRef, $rendered['schema_ref']);
        $h->assertTrue(!str_contains($xhtml, '<main>') && !str_contains($xhtml, '<section>'));
        $h->assertTrue(str_contains($xhtml, '<div class="ct-report">'));
        $h->assertTrue(str_contains($xhtml, 'dimension="ct:BusinessTypeDimension">ct:Trade'));
        $h->assertTrue(str_contains($xhtml, 'dimension="ct:BusinessTypeDimension">ct:Company'));
        $h->assertTrue(str_contains($xhtml, '<ct:BusinessNameDomain>Example Electricals Limited</ct:BusinessNameDomain>'));
    });
    $h->check($service::class, 'supports only the reviewed legacy CT-period concept allow-list', static function () use ($h, $service): void {
        $method = new ReflectionMethod($service::class, 'contextProfile'); $method->setAccessible(true);
        $h->assertSame(
            \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE,
            $method->invoke($service, ['context_profile' => 'ct_period', 'local_name' => 'ProfitLossPerAccounts'])
        );
        $h->assertSame(
            \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            $method->invoke($service, ['context_profile' => 'ct_period', 'local_name' => 'NetTaxPayable'])
        );
        try {
            $method->invoke($service, ['context_profile' => 'ct_period', 'local_name' => 'UnreviewedConcept']);
            $h->assertTrue(false);
        } catch (ReflectionException|RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'unsupported HMRC context profile'));
        }
    });
    $h->check($service::class, 'requires both CT-period dates in the human-readable report model', static function () use ($h, $service): void {
        try {
            $service->buildReportModel(
                ['available' => true, 'run' => ['period_start' => '2025-01-01'], 'model' => ['identity' => ['company_number' => '01234567']]],
                [['id' => 1, 'canonical_key' => 'profit', 'presentation_section' => 'detailed_profit_and_loss', 'presentation_label' => 'Profit', 'sort_order' => 1, 'source_value' => 100.0]]
            );
            $h->assertTrue(false);
        } catch (RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'start and end dates'));
        }
    });
});
