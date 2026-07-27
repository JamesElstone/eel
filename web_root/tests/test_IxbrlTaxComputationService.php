<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function ixbrlTaxComputationModel(array $overrides = []): array
{
    $summary = array_replace([
        'accounting_profit' => -118.66,
        'disallowable_add_backs' => 0.00,
        'capital_add_backs' => 0.00,
        'depreciation_add_back' => 184.28,
        'capital_allowances' => 628.84,
        'taxable_before_losses' => -563.21,
        'loss_created_in_period' => 563.21,
        'losses_brought_forward' => 0.00,
        'losses_used' => 0.00,
        'losses_carried_forward' => 563.21,
        'taxable_profit' => 0.00,
        'ordinary_corporation_tax' => 0.00,
        'capital_allowance_breakdown' => ['asset_calculations' => []],
        'accounting_allocation_basis' => [
            'method' => 'whole_accounting_period_inclusive_days',
            'allocation_method' => 'adjusted_result_first',
            'time_apportioned' => true,
            'accounting_period_days' => 391,
            'ct_period_days' => 365,
            'ct_period_count' => 2,
            'apportionment_rounding_adjustment' => 0.01,
            'allocated_values' => ['adjusted_result_before_capital_allowances' => 65.63],
        ],
    ], (array)($overrides['summary'] ?? []));
    $model = [
        'available' => true,
        'facts' => [],
        'run' => [
            'period_start' => (string)($overrides['period_start'] ?? '2022-09-05'),
            'period_end' => (string)($overrides['period_end'] ?? '2023-09-04'),
        ],
        'model' => [
            'identity' => [
                'company_number' => '14337285',
                'company_name' => 'ELSTONE ELECTRICALS LIMITED',
            ],
            'filing_identity' => ['utr' => '2794616478'],
            'accounting_period' => ['start_date' => '2022-09-05', 'end_date' => '2023-09-30'],
            'ct_period' => [
                'start_date' => (string)($overrides['period_start'] ?? '2022-09-05'),
                'end_date' => (string)($overrides['period_end'] ?? '2023-09-04'),
            ],
            'supported_return_profile' => [
                'profile_code' => 'ordinary-uk-trading-frs105',
                'supported' => true,
                'ordinary_trading_company_confirmed' => true,
            ],
            'filing_decisions' => ['aia_claimed_in_trade' => 0.00],
            'computation' => ['summary' => $summary],
            'audit' => ['capital_allowances' => ['rows' => []]],
            'ct600a' => ['required' => false],
        ],
    ];
    return $model;
}

function ixbrlTaxComputationValue(array $model, string $key): mixed
{
    if ($key === 'return_position.ct600a_a80' || $key === 'return_position.tax_payable') {
        return 0.00;
    }
    $value = $model['model'];
    foreach (explode('.', $key) as $part) {
        $value = is_array($value) && array_key_exists($part, $value) ? $value[$part] : null;
    }
    return $value;
}

function ixbrlTaxComputationMappings(array $model): array
{
    $specs = [
        ['identity.company_name', 'CompanyName', 'text', 'instant', 'identity'],
        ['filing_identity.utr', 'TaxReference', 'text', 'instant', 'identity'],
        ['ct_period.start_date', 'StartOfPeriodCoveredByReturn', 'date', 'instant', 'identity'],
        ['ct_period.end_date', 'EndOfPeriodCoveredByReturn', 'date', 'instant', 'identity'],
        ['computation.summary.accounting_profit', 'ProfitLossPerAccounts', 'numeric', 'duration', 'detailed_profit_and_loss'],
        ['computation.summary.disallowable_add_backs', 'AdjustmentsMiscellaneousExpensesPerAccounts', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.capital_add_backs', 'AdjustmentsCapitalExpenditure', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.depreciation_add_back', 'AdjustmentsDepreciation', 'numeric', 'duration', 'accounts_adjustments'],
        ['computation.summary.capital_allowances', 'TotalCapitalAllowances', 'numeric', 'duration', 'capital_allowances'],
        ['computation.summary.taxable_before_losses', 'ProfitsBeforeOtherDeductionsAndReliefs', 'numeric', 'duration', 'losses'],
        ['computation.summary.losses_brought_forward', 'TradingLossesBroughtForward', 'numeric', 'duration', 'losses'],
        ['computation.summary.losses_used', 'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits', 'numeric', 'duration', 'losses'],
        ['computation.summary.taxable_profit', 'TotalProfitsChargeableToCorporationTax', 'numeric', 'duration', 'tax_liability'],
        ['computation.summary.ordinary_corporation_tax', 'CorporationTaxChargeable', 'numeric', 'duration', 'tax_liability'],
        ['return_position.ct600a_a80', 'TaxPayableOnLoansToParticipators', 'numeric', 'duration', 'tax_liability'],
        ['return_position.tax_payable', 'NetTaxPayable', 'numeric', 'duration', 'tax_liability'],
    ];
    $mappings = [];
    foreach ($specs as $index => [$key, $localName, $type, $periodType, $section]) {
        $trade = in_array($localName, [
            'ProfitLossPerAccounts', 'AdjustmentsMiscellaneousExpensesPerAccounts',
            'AdjustmentsCapitalExpenditure', 'AdjustmentsDepreciation', 'TotalCapitalAllowances',
        ], true);
        $mappings[] = [
            'id' => $index + 1,
            'canonical_key' => $key,
            'taxonomy_concept' => 'ct:' . $localName,
            'namespace_uri' => 'urn:ct',
            'local_name' => $localName,
            'value_type' => $type,
            'period_type' => $periodType,
            'context_profile' => $trade
                ? \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE
                : \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY,
            'unit_ref' => $type === 'numeric' ? 'GBP' : null,
            'decimals_value' => $type === 'numeric' ? '2' : null,
            'dimensions_json' => null,
            'sign_multiplier' => 1,
            'presentation_section' => $section,
            'presentation_label' => $key,
            'null_policy' => 'error',
            'is_required' => 1,
            'sort_order' => ($index + 1) * 10,
            'source_value' => ixbrlTaxComputationValue($model, $key),
        ];
    }
    return $mappings;
}

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\IxbrlTaxComputationService::class, static function (GeneratedServiceClassTestHarness $h, \eel_accounts\Service\IxbrlTaxComputationService $service): void {
    $h->check($service::class, 'fails closed without a locked filing context', static function () use ($h, $service): void {
        $result = $service->generateFilingExport(0, 0, 0);
        $h->assertSame(false, $result['success']);
    });
    $h->check($service::class, 'renders a human-readable A4 computation without changing tagged facts', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel();
        $method = new ReflectionMethod($service::class, 'renderMappedDocument');
        $method->setAccessible(true);
        $evidenceId = 'EEL-AR-0123-4567-89AB-CDEF-0123-4567-89AB-CDEF';
        $rendered = $method->invoke(
            $service,
            new \eel_accounts\Service\IxbrlGeneratorService(),
            $model,
            ixbrlTaxComputationMappings($model),
            'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd',
            $evidenceId
        );
        $xhtml = (string)$rendered['xhtml'];
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
        $h->assertSame(
            'ELSTONE ELECTRICALS LIMITED: Corporation Tax computation for the period ended 4 September 2023',
            trim((string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="head"]/*[local-name()="title"])'))
        );
        $body = (string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="body"])');
        $h->assertTrue(str_contains($body, 'FRS 105 micro-entity'));
        $h->assertTrue(str_contains($body, '365 / 391 days'));
        $h->assertTrue(str_contains($body, 'Apportionment rounding adjustment'));
        $h->assertTrue(str_contains($body, 'Adjusted profit or loss before capital allowances'));
        $h->assertTrue(str_contains($body, '65.63'));
        $h->assertTrue(str_contains($body, 'Loss carried forward'));
        $h->assertTrue(str_contains($body, '563.21'));
        $h->assertFalse(str_contains($body, 'identity.company_name'));
        $h->assertFalse(str_contains($body, 'EEL filing evidence artifact'));
        $h->assertFalse(str_contains($body, $evidenceId));
        $h->assertTrue(str_contains($xhtml, 'name="eel-evidence-artifact-id" content="' . $evidenceId . '"'));
        $h->assertTrue(str_contains($xhtml, '@page { size: A4 portrait;'));
        $h->assertTrue(str_contains($xhtml, 'break-inside: avoid'));
        $h->assertTrue(str_contains($xhtml, 'page-break-inside: avoid'));
        $profit = $xpath->query('//ix:nonFraction[@name="ct:ProfitLossPerAccounts"]')->item(0);
        $h->assertTrue($profit instanceof DOMElement);
        $h->assertSame('-', $profit?->getAttribute('sign'));
        $h->assertSame('118.66', $profit?->textContent);
        $h->assertTrue(str_contains((string)$profit?->parentNode?->textContent, '(118.66)'));
        $allowances = $xpath->query('//ix:nonFraction[@name="ct:TotalCapitalAllowances"]')->item(0);
        $h->assertTrue($allowances instanceof DOMElement);
        $h->assertSame('', $allowances?->getAttribute('sign'));
        $h->assertTrue(str_contains((string)$allowances?->parentNode?->textContent, '(628.84)'));
        $h->assertSame([], (new \eel_accounts\Service\IxbrlGeneratorService())->validateStructure(
            $xhtml,
            [(string)$rendered['schema_ref']]
        ));
    });
    $h->check($service::class, 'preserves legacy frozen loss pennies in the visible movement', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel([
            'period_start' => '2023-09-05',
            'period_end' => '2023-09-30',
            'summary' => [
                'accounting_profit' => -8.45,
                'depreciation_add_back' => 13.13,
                'capital_allowances' => 0.00,
                'taxable_before_losses' => 4.68,
                'loss_created_in_period' => 0.00,
                'losses_brought_forward' => 563.22,
                'losses_used' => 4.68,
                'losses_carried_forward' => 558.54,
                'accounting_allocation_basis' => [
                    'method' => 'whole_accounting_period_inclusive_days',
                    'time_apportioned' => true,
                    'accounting_period_days' => 391,
                    'ct_period_days' => 26,
                    'ct_period_count' => 2,
                    'allocated_values' => ['adjusted_result_before_capital_allowances' => 4.68],
                ],
            ],
        ]);
        $method = new ReflectionMethod($service::class, 'renderMappedDocument');
        $method->setAccessible(true);
        $xhtml = (string)($method->invoke(
            $service,
            new \eel_accounts\Service\IxbrlGeneratorService(),
            $model,
            ixbrlTaxComputationMappings($model),
            'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
        ))['xhtml'];
        $h->assertTrue(str_contains($xhtml, '>563.22</ix:nonFraction>'));
        $h->assertTrue(str_contains($xhtml, '(<ix:nonFraction name="ct:TradingLossesBroughtForwardAmountUsedAgainstTotalProfits"'));
        $h->assertTrue(str_contains($xhtml, '>4.68</ix:nonFraction>)'));
        $h->assertTrue(str_contains($xhtml, '>558.54</span>') || str_contains($xhtml, '>558.54</td>'));
    });
    $h->check($service::class, 'reconciles AIA rows only to frozen audit descriptions and dates', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel()['model'];
        $model['filing_decisions']['aia_claimed_in_trade'] = 94.99;
        $model['computation']['summary']['capital_allowance_breakdown']['asset_calculations'] = [[
            'asset_id' => 1, 'allowance_type' => 'aia', 'addition_amount' => 94.99, 'allowance_amount' => 94.99,
        ]];
        $auditRow = [
            'source_date' => '2022-10-05',
            'tax_adjustment_amount' => 94.99,
            'metadata' => [
                'asset_id' => 1, 'description' => 'ElectricFix, Wall Chaser', 'purchase_date' => '2022-10-05',
                'allowance_type' => 'aia', 'addition_amount' => 94.99, 'allowance_amount' => 94.99,
            ],
        ];
        $model['audit']['capital_allowances']['rows'] = [$auditRow];
        $method = new ReflectionMethod($service::class, 'renderAiaSchedule');
        $method->setAccessible(true);
        $html = (string)$method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), $model);
        $h->assertTrue(str_contains($html, 'ElectricFix, Wall Chaser'));
        $h->assertTrue(str_contains($html, '5 October 2022'));
        $h->assertFalse(str_contains($html, '>1<'));
        $model['audit']['capital_allowances']['rows'][] = array_replace_recursive($auditRow, [
            'source_date' => '2022-10-06',
            'tax_adjustment_amount' => 0.00,
            'metadata' => [
                'description' => 'Disposal evidence that must not supply the AIA schedule',
                'allowance_type' => 'aia, disposal_value',
                'audit_component' => 'disposal_balancing',
            ],
        ]);
        $html = (string)$method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), $model);
        $h->assertTrue(str_contains($html, 'ElectricFix, Wall Chaser'));
        $h->assertFalse(str_contains($html, 'Disposal evidence that must not supply the AIA schedule'));
        $model['audit']['capital_allowances']['rows'][] = $auditRow;
        try {
            $method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), $model);
            $h->assertTrue(false);
        } catch (ReflectionException|RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'uniquely'));
        }
        $model['audit']['capital_allowances']['rows'] = [$auditRow];
        $model['audit']['capital_allowances']['rows'][0]['metadata']['description'] = '';
        try {
            $method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), $model);
            $h->assertTrue(false);
        } catch (ReflectionException|RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'description'));
        }
        $model['audit']['capital_allowances']['rows'] = [$auditRow];
        $model['audit']['capital_allowances']['rows'][0]['tax_adjustment_amount'] = 94.98;
        try {
            $method->invoke($service, new \eel_accounts\Service\IxbrlGeneratorService(), $model);
            $h->assertTrue(false);
        } catch (ReflectionException|RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'does not agree'));
        }
    });
    $h->check($service::class, 'fails closed for unknown fact labels and profiles', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel();
        $mappings = ixbrlTaxComputationMappings($model);
        $mappings[] = array_replace($mappings[0], [
            'id' => 99, 'canonical_key' => 'internal.unlabelled_fact', 'source_value' => 'secret',
        ]);
        try {
            $service->buildReportModel($model, $mappings);
            $h->assertTrue(false);
        } catch (RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'no recognised human-readable label'));
        }
        $model['model']['supported_return_profile']['profile_code'] = 'unknown-profile';
        try {
            $service->buildReportModel($model, ixbrlTaxComputationMappings($model));
            $h->assertTrue(false);
        } catch (RuntimeException $exception) {
            $h->assertTrue(str_contains($exception->getMessage(), 'accounting-framework label'));
        }
    });
    $h->check($service::class, 'supports only the reviewed legacy CT-period concept allow-list', static function () use ($h, $service): void {
        $method = new ReflectionMethod($service::class, 'contextProfile');
        $method->setAccessible(true);
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
});
