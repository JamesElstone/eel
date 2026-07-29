<?php
declare(strict_types=1);
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

function ixbrlTaxComputationModel(array $overrides = []): array
{
    $periodStart = (string)($overrides['period_start'] ?? '2022-09-05');
    $periodEnd = (string)($overrides['period_end'] ?? '2023-09-04');
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
            'whole_period_values' => [
                'accounting_profit' => -127.11,
                'disallowable_add_backs' => 0.00,
                'capital_add_backs' => 0.00,
                'depreciation_add_back' => 197.41,
                'adjusted_result_before_capital_allowances' => 70.30,
            ],
            'allocated_values' => ['adjusted_result_before_capital_allowances' => 65.63],
        ],
    ], (array)($overrides['summary'] ?? []));
    if (!isset($summary['loss_restriction'])) {
        $days = (int)(new DateTimeImmutable($periodStart))->diff(new DateTimeImmutable($periodEnd))->days + 1;
        $summary['loss_restriction'] = [
            'post_2017_trading_losses' => [
                'brought_forward' => (float)$summary['losses_brought_forward'],
                'arising' => (float)$summary['loss_created_in_period'],
                'used' => (float)$summary['losses_used'],
                'carried_forward' => (float)$summary['losses_carried_forward'],
            ],
            'pre_2017_trading_losses' => ['brought_forward' => 0.00, 'arising' => 0.00, 'used' => 0.00, 'carried_forward' => 0.00],
            'deduction_allowance' => ['basis' => 'non_group', 'period_days' => $days, 'days_in_year' => 365, 'amount' => round(5000000 * $days / 365, 2)],
            'qualifying_profits' => max(0.00, (float)$summary['taxable_before_losses']),
            'carried_forward_loss_relief_claimed' => (float)$summary['losses_used'],
            'calculated_loss_restriction' => 0.00,
            'loss_restriction' => 'none',
        ];
    }
    $model = [
        'available' => true,
        'facts' => [],
        'run' => [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ],
        'model' => [
            'identity' => [
                'company_number' => '14337285',
                'company_name' => 'ELSTONE ELECTRICALS LIMITED',
            ],
            'filing_identity' => ['utr' => '2794616478'],
            'accounting_period' => ['start_date' => '2022-09-05', 'end_date' => '2023-09-30'],
            'ct_period' => [
                'start_date' => $periodStart,
                'end_date' => $periodEnd,
            ],
            'supported_return_profile' => [
                'profile_code' => 'ordinary-uk-trading-frs105',
                'supported' => true,
                'ordinary_trading_company_confirmed' => true,
            ],
            'filing_decisions' => ['aia_claimed_in_trade' => 0.00],
            'computation' => ['summary' => $summary],
            'audit' => ['capital_allowances' => ['rows' => []]],
            'ct600a' => array_replace(['required' => false], (array)($overrides['ct600a'] ?? [])),
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
        ['computation.summary.loss_restriction.post_2017_trading_losses.brought_forward', 'TradingLossesBroughtForward', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.post_2017_trading_losses.used', 'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.post_2017_trading_losses.carried_forward', 'BalanceOfLossesBroughtForwardCarriedForward', 'numeric', 'instant', 'losses'],
        ['computation.summary.loss_restriction.deduction_allowance.amount', 'DeductionAllowance', 'numeric', 'duration', 'losses'],
        ['computation.summary.loss_restriction.calculated_loss_restriction', 'CalculatedLossRestriction', 'numeric', 'duration', 'losses'],
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
            'TradingLossesBroughtForward', 'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits',
            'BalanceOfLossesBroughtForwardCarriedForward',
        ], true);
        $mappings[] = [
            'id' => $index + 1,
            'canonical_key' => $key,
            'taxonomy_concept' => 'ct:' . $localName,
            'namespace_uri' => 'urn:ct',
            'local_name' => $localName,
            'value_type' => $type,
            'period_type' => $periodType,
            'context_profile' => in_array($localName, ['DeductionAllowance', 'CalculatedLossRestriction'], true)
                ? \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_LOSS_RESTRICTION
                : ($trade ? \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_UK_TRADE : \eel_accounts\Service\CtFilingMappingService::CONTEXT_HMRC_CT_COMPANY),
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
    $h->check($service::class, 'keeps the print layout within the A4 content box', static function () use ($h, $service): void {
        $method = new ReflectionMethod($service::class, 'stylesheet');
        $method->setAccessible(true);
        $stylesheet = (string)$method->invoke($service);

        $h->assertTrue(str_contains($stylesheet, '@page { size: A4 portrait; margin: 18mm 16mm 18mm 16mm; }'));
        $h->assertTrue(str_contains($stylesheet, 'html, body { width: auto; max-width: 100%; min-height: 0; margin: 0; padding: 0; }'));
        $h->assertTrue(str_contains($stylesheet, '.ct-report { box-sizing: border-box; width: 100%; max-width: 100%; margin: 0; }'));
        $h->assertTrue(str_contains($stylesheet, '.ct-report table, .ct-report th, .ct-report td { box-sizing: border-box; max-width: 100%; }'));
        $h->assertTrue(str_contains($stylesheet, '.ct-report th.amount { white-space: normal; }'));
        $h->assertTrue(str_contains($stylesheet, 'td.amount { white-space: nowrap; }'));
        $h->assertFalse(str_contains($stylesheet, 'width: 210mm'));
        $h->assertFalse(str_contains($stylesheet, '.ct-report { max-width: none; }'));
    });
    $h->check($service::class, 'renders deductions allowance only for a relevant loss claim or restriction', static function () use ($h, $service): void {
        $method = new ReflectionMethod($service::class, 'renderDeductionsAllowance');
        $method->setAccessible(true);
        $render = static function (float $used, float $reliefClaimed, float $restriction) use ($method, $service): string {
            return (string)$method->invoke(
                $service,
                new \eel_accounts\Service\IxbrlGeneratorService(),
                [],
                [
                    'post_2017_trading_losses' => ['used' => $used],
                    'carried_forward_loss_relief_claimed' => $reliefClaimed,
                    'calculated_loss_restriction' => $restriction,
                    'loss_restriction' => $restriction === 0.0 ? 'none' : 'applies',
                    'deduction_allowance' => ['amount' => 5000000.00, 'period_days' => 365, 'days_in_year' => 365],
                ]
            );
        };
        $h->assertSame('', $render(0.00, 0.00, 0.00));
        $h->assertTrue(str_contains($render(1.00, 0.00, 0.00), 'Deductions allowance'));
        $h->assertTrue(str_contains($render(0.00, 1.00, 0.00), 'Deductions allowance'));
        $h->assertTrue(str_contains($render(1.00, 1.00, 0.00), 'Deductions allowance'));
        $h->assertTrue(str_contains($render(0.00, 0.00, 1.00), 'Deductions allowance'));
    });
    $h->check($service::class, 'uses Format 1.1 whole-period accounts facts for a split accounting period', static function () use ($h, $service): void {
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
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $h->assertSame(
            'ELSTONE ELECTRICALS LIMITED: Corporation Tax computation for the period ended 4 September 2023',
            trim((string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="head"]/*[local-name()="title"])'))
        );
        $body = (string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="body"])');
        $h->assertTrue(str_contains($body, 'FRS 105 micro-entity'));
        $h->assertTrue(str_contains($body, '365 / 391 days'));
        $h->assertFalse(str_contains($body, 'Apportionment rounding adjustment'));
        $h->assertTrue(str_contains($body, 'Profit/(loss) before tax per statutory accounts'));
        $h->assertTrue(str_contains($body, 'Accounting adjustment for depreciation'));
        $h->assertTrue(str_contains($body, 'Revised figure before tax'));
        $h->assertTrue(str_contains($body, 'Time apportionment figure (365 / 391 days)'));
        $h->assertTrue(str_contains($body, '65.63'));
        $h->assertTrue(str_contains($body, 'Post-1 April 2017 trading losses'));
        $h->assertSame(0, $xpath->query('//*[local-name()="div" and contains(concat(" ", normalize-space(@class), " "), " deductions-allowance ")]')->length);
        $h->assertSame(1, $xpath->query('//*[local-name()="h2" and normalize-space(.)="Trading losses"]')->length);
        $h->assertSame(1, $xpath->query('//*[local-name()="h2" and normalize-space(.)="Tax liability"]')->length);
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
        $h->assertSame('127.11', $profit?->textContent);
        $wholePeriodProfit = (float)$model['model']['computation']['summary']['accounting_allocation_basis']['whole_period_values']['accounting_profit'];
        $expectedProfitDisplay = '(' . number_format(abs($wholePeriodProfit), 2, '.', ',') . ')';
        $h->assertSame($expectedProfitDisplay, trim((string)$profit?->parentNode?->textContent));
        $visibleText = trim((string)preg_replace('/\s+/', ' ', (string)$document->textContent));
        $h->assertTrue(str_contains($visibleText, $expectedProfitDisplay));
        $h->assertSame(
            $wholePeriodProfit,
            $profit?->getAttribute('sign') === '-'
                ? -(float)$profit->textContent
                : (float)$profit?->textContent
        );
        $profitContext = (string)$profit?->getAttribute('contextRef');
        $h->assertSame('2022-09-05', $xpath->evaluate('string(//xbrli:context[@id="' . $profitContext . '"]/xbrli:period/xbrli:startDate)'));
        $h->assertSame('2023-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $profitContext . '"]/xbrli:period/xbrli:endDate)'));
        $depreciation = $xpath->query('//ix:nonFraction[@name="ct:AdjustmentsDepreciation"]')->item(0);
        $h->assertSame('197.41', $depreciation?->textContent);
        $h->assertSame('', $depreciation?->getAttribute('sign'));
        $h->assertSame('197.41', trim((string)$depreciation?->parentNode?->textContent));
        $revised = $xpath->query('//ix:nonFraction[@name="ct:AdjustedProfitOrLossBeforeAccountingPeriodAdjustments"]')->item(0);
        $h->assertSame('70.30', $revised?->textContent);
        $loss = $xpath->query('//ix:nonFraction[@name="ct:AdjustedLossOfPeriod"]')->item(0);
        $h->assertSame('563.21', $loss?->textContent);
        $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:AdjustedProfitForThePeriod"]')->length);
        $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:ProfitsBeforeOtherDeductionsAndReliefs"]')->length);
        $allowances = $xpath->query('//ix:nonFraction[@name="ct:TotalCapitalAllowances"]')->item(0);
        $h->assertTrue($allowances instanceof DOMElement);
        $h->assertSame('', $allowances?->getAttribute('sign'));
        $h->assertTrue(str_contains((string)$allowances?->parentNode?->textContent, '(628.84)'));
        foreach ([
            'TradingLossesBroughtForward' => '0.00',
            'TradingLossesOfThisOrLaterAP' => '563.21',
            'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits' => '0.00',
        ] as $name => $value) {
            $fact = $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]');
            $h->assertSame(1, $fact->length);
            $element = $fact->item(0);
            $h->assertSame($value, $element?->textContent);
            $h->assertSame('GBP', $element?->getAttribute('unitRef'));
            $h->assertSame('2', $element?->getAttribute('decimals'));
            $context = (string)$element?->getAttribute('contextRef');
            $h->assertSame('2022-09-05', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:startDate)'));
            $h->assertSame('2023-09-04', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:endDate)'));
        }
        $carriedForward = $xpath->query('//ix:nonFraction[@name="ct:BalanceOfLossesBroughtForwardCarriedForward"]')->item(0);
        $h->assertSame('563.21', $carriedForward?->textContent);
        $carriedForwardContext = (string)$carriedForward?->getAttribute('contextRef');
        $h->assertSame('2023-09-04', $xpath->evaluate('string(//xbrli:context[@id="' . $carriedForwardContext . '"]/xbrli:period/xbrli:instant)'));
        foreach ([
            'DeductionAllowance',
            'ProfitsThatCanBeCoveredByBroughtForwardLosses',
            'TradingLossesBroughtForwardValueClaimedAgainstTotalProfits',
            'CalculatedLossRestriction',
        ] as $name) {
            $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]')->length);
        }
        $zeroFact = $xpath->query('//ix:nonFraction[@name="ct:TradingLossesBroughtForward"]')->item(0);
        $h->assertSame('', $zeroFact?->getAttribute('sign'));
        $h->assertSame('0.00', trim((string)$zeroFact?->parentNode?->textContent));
        foreach (['CompanyName', 'TaxReference', 'StartOfPeriodCoveredByReturn', 'EndOfPeriodCoveredByReturn'] as $identityFact) {
            $h->assertSame(1, $xpath->query('//ix:nonNumeric[@name="ct:' . $identityFact . '"]')->length);
        }
        $report = $service->buildReportModel($model, ixbrlTaxComputationMappings($model));
        $lossRows = array_column((array)$report['loss_schedule_rows'], 'taxonomy_concept', 'id');
        $h->assertSame('ct:TradingLossesOfThisOrLaterAP', $lossRows['post_2017_trading_losses_arising'] ?? null);
        $h->assertSame('ct:ProfitsThatCanBeCoveredByBroughtForwardLosses', $lossRows['qualifying_profits'] ?? null);
        $h->assertSame('ct:TradingLossesBroughtForwardValueClaimedAgainstTotalProfits', $lossRows['carried_forward_relief_claimed'] ?? null);
        $h->assertSame('HMRC CT Computation 2024', $report['untagged_row_allowlist']['loss_restriction_result']['taxonomy_version'] ?? null);
        $h->assertSame([], (new \eel_accounts\Service\IxbrlGeneratorService())->validateStructure(
            $xhtml,
            [(string)$rendered['schema_ref']]
        ));
    });
    $h->check($service::class, 'uses brackets for a negative untagged time-apportionment working', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel([
            'summary' => [
                'capital_allowances' => 0.00,
                'taxable_before_losses' => -65.63,
                'loss_created_in_period' => 65.63,
                'losses_carried_forward' => 65.63,
                'accounting_allocation_basis' => [
                    'method' => 'whole_accounting_period_inclusive_days',
                    'time_apportioned' => true,
                    'accounting_period_days' => 391,
                    'ct_period_days' => 365,
                    'ct_period_count' => 2,
                    'whole_period_values' => [
                        'accounting_profit' => -127.11,
                        'disallowable_add_backs' => 0.00,
                        'capital_add_backs' => 0.00,
                        'depreciation_add_back' => 197.41,
                        'adjusted_result_before_capital_allowances' => -70.30,
                    ],
                    'allocated_values' => ['adjusted_result_before_capital_allowances' => -65.63],
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
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $visibleText = trim((string)preg_replace('/\s+/', ' ', (string)$document->textContent));
        $working = (float)$model['model']['computation']['summary']['accounting_allocation_basis']['allocated_values']['adjusted_result_before_capital_allowances'];
        $h->assertTrue(str_contains($visibleText, '(' . number_format(abs($working), 2, '.', ',') . ')'));
    });
    $h->check($service::class, 'uses the prescribed adjusted profit fact for the second split CT period', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel([
            'period_start' => '2023-09-05',
            'period_end' => '2023-09-30',
            'summary' => [
                'accounting_profit' => -8.45,
                'depreciation_add_back' => 13.13,
                'capital_allowances' => 0.00,
                'taxable_before_losses' => 4.67,
                'loss_created_in_period' => 0.00,
                'losses_brought_forward' => 563.21,
                'losses_used' => 4.67,
                'losses_carried_forward' => 558.54,
                'accounting_allocation_basis' => [
                    'method' => 'whole_accounting_period_inclusive_days',
                    'time_apportioned' => true,
                    'accounting_period_days' => 391,
                    'ct_period_days' => 26,
                    'ct_period_count' => 2,
                    'whole_period_values' => [
                        'accounting_profit' => -127.11,
                        'disallowable_add_backs' => 0.00,
                        'capital_add_backs' => 0.00,
                        'depreciation_add_back' => 197.41,
                        'adjusted_result_before_capital_allowances' => 70.30,
                    ],
                    'allocated_values' => ['adjusted_result_before_capital_allowances' => 4.67],
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
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $broughtForward = $xpath->query('//ix:nonFraction[@name="ct:TradingLossesBroughtForward"]')->item(0);
        $used = $xpath->query('//ix:nonFraction[@name="ct:TradingLossesBroughtForwardAmountUsedAgainstTotalProfits"]')->item(0);
        $carriedForward = $xpath->query('//ix:nonFraction[@name="ct:BalanceOfLossesBroughtForwardCarriedForward"]')->item(0);
        $h->assertSame('563.21', $broughtForward?->textContent);
        $h->assertSame('4.67', $used?->textContent);
        $h->assertSame('558.54', $carriedForward?->textContent);
        foreach ([
            'TradingLossesBroughtForward' => '563.21',
            'TradingLossesOfThisOrLaterAP' => '0.00',
            'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits' => '4.67',
            'DeductionAllowance' => '356,164.38',
            'ProfitsThatCanBeCoveredByBroughtForwardLosses' => '4.67',
            'TradingLossesBroughtForwardValueClaimedAgainstTotalProfits' => '4.67',
            'CalculatedLossRestriction' => '0.00',
        ] as $name => $value) {
            $fact = $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]');
            $h->assertSame(1, $fact->length);
            $element = $fact->item(0);
            $h->assertSame($value, $element?->textContent);
            $h->assertSame('GBP', $element?->getAttribute('unitRef'));
            $h->assertSame('2', $element?->getAttribute('decimals'));
            $context = (string)$element?->getAttribute('contextRef');
            $h->assertSame('2023-09-05', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:startDate)'));
            $h->assertSame('2023-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:endDate)'));
        }
        $carriedForward = $xpath->query('//ix:nonFraction[@name="ct:BalanceOfLossesBroughtForwardCarriedForward"]')->item(0);
        $h->assertSame('558.54', $carriedForward?->textContent);
        $carriedForwardContext = (string)$carriedForward?->getAttribute('contextRef');
        $h->assertSame('2023-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $carriedForwardContext . '"]/xbrli:period/xbrli:instant)'));
        $body = (string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="body"])');
        $h->assertTrue(str_contains($body, '26 / 391 days'));
        $h->assertTrue(str_contains($body, 'Time apportionment figure (26 / 391 days)'));
        $h->assertFalse(str_contains($body, 'Apportionment rounding adjustment'));
        $profit = $xpath->query('//ix:nonFraction[@name="ct:ProfitLossPerAccounts"]')->item(0);
        $h->assertSame('127.11', $profit?->textContent);
        $depreciation = $xpath->query('//ix:nonFraction[@name="ct:AdjustmentsDepreciation"]')->item(0);
        $h->assertSame('197.41', $depreciation?->textContent);
        $revised = $xpath->query('//ix:nonFraction[@name="ct:AdjustedProfitOrLossBeforeAccountingPeriodAdjustments"]')->item(0);
        $h->assertSame('70.30', $revised?->textContent);
        $adjustedProfit = $xpath->query('//ix:nonFraction[@name="ct:AdjustedProfitForThePeriod"]')->item(0);
        $h->assertSame('4.67', $adjustedProfit?->textContent);
        $profitContext = (string)$adjustedProfit?->getAttribute('contextRef');
        $h->assertSame('2023-09-05', $xpath->evaluate('string(//xbrli:context[@id="' . $profitContext . '"]/xbrli:period/xbrli:startDate)'));
        $h->assertSame('2023-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $profitContext . '"]/xbrli:period/xbrli:endDate)'));
        $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:AdjustedLossOfPeriod"]')->length);
        $deductionsAllowance = $xpath->query('//*[local-name()="div" and contains(concat(" ", normalize-space(@class), " "), " deductions-allowance ")]');
        $h->assertSame(1, $deductionsAllowance->length);
        $deductionsAllowanceText = (string)$deductionsAllowance->item(0)?->textContent;
        $h->assertTrue(str_contains($deductionsAllowanceText, '356,164.38'));
        $h->assertTrue(str_contains($deductionsAllowanceText, 'Qualifying profits'));
        $h->assertTrue(str_contains($deductionsAllowanceText, 'Carried-forward loss relief claimed against total profits'));
        $h->assertTrue(str_contains($deductionsAllowanceText, 'Calculated loss restriction'));
        $h->assertTrue(str_contains($deductionsAllowanceText, '0.00'));
        $h->assertSame(1, $xpath->query('//*[local-name()="h2" and normalize-space(.)="Trading losses"]')->length);
        $h->assertSame(1, $xpath->query('//*[local-name()="h2" and normalize-space(.)="Tax liability"]')->length);
        $h->assertFalse(str_contains($body, 'Main pool'));
    });
    $h->check($service::class, 'uses the complete loss tagging profile for a single-period loss computation', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel([
            'period_start' => '2023-10-01',
            'period_end' => '2024-09-30',
            'summary' => [
                'accounting_profit' => -4903.62,
                'disallowable_add_backs' => 1056.14,
                'capital_add_backs' => 112.57,
                'depreciation_add_back' => 1087.39,
                'capital_allowances' => 4375.29,
                'taxable_before_losses' => -7022.81,
                'loss_created_in_period' => 7022.81,
                'losses_brought_forward' => 595.61,
                'losses_used' => 0.00,
                'losses_carried_forward' => 7618.42,
                'accounting_allocation_basis' => [
                    'method' => 'journal_date_within_single_ct_period',
                    'time_apportioned' => false,
                    'ct_period_days' => 366,
                    'accounting_period_days' => 366,
                    'rounding' => 'pennies_half_up',
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
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');

        $revised = $xpath->query('//ix:nonFraction[@name="ct:AdjustedProfitOrLossBeforeAccountingPeriodAdjustments"]')->item(0);
        $h->assertSame('2,647.52', $revised?->textContent);
        $h->assertSame('-', $revised?->getAttribute('sign'));
        foreach ([
            'AdjustedLossOfPeriod' => '7,022.81',
            'TradingLossesOfThisOrLaterAP' => '7,022.81',
        ] as $name => $value) {
            $fact = $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]')->item(0);
            $h->assertSame($value, $fact?->textContent);
            $h->assertSame('GBP', $fact?->getAttribute('unitRef'));
            $h->assertSame('2', $fact?->getAttribute('decimals'));
            $context = (string)$fact?->getAttribute('contextRef');
            $h->assertSame('2023-10-01', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:startDate)'));
            $h->assertSame('2024-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:endDate)'));
        }
        $carriedForward = $xpath->query('//ix:nonFraction[@name="ct:BalanceOfLossesBroughtForwardCarriedForward"]')->item(0);
        $h->assertSame('7,618.42', $carriedForward?->textContent);
        $carriedForwardContext = (string)$carriedForward?->getAttribute('contextRef');
        $h->assertSame('2024-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $carriedForwardContext . '"]/xbrli:period/xbrli:instant)'));
        $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:ProfitsBeforeOtherDeductionsAndReliefs"]')->length);
    });
    $h->check($service::class, 'uses the complete loss tagging profile for a single-period profit computation', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel([
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
            'summary' => [
                'accounting_profit' => 1749.32,
                'disallowable_add_backs' => 0.00,
                'capital_add_backs' => 0.00,
                'depreciation_add_back' => 0.00,
                'capital_allowances' => 0.00,
                'taxable_before_losses' => 1749.32,
                'loss_created_in_period' => 0.00,
                'losses_brought_forward' => 7618.42,
                'losses_used' => 1749.32,
                'losses_carried_forward' => 5869.10,
                'accounting_allocation_basis' => [
                    'method' => 'journal_date_within_single_ct_period',
                    'time_apportioned' => false,
                    'ct_period_days' => 365,
                    'accounting_period_days' => 365,
                    'rounding' => 'pennies_half_up',
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
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');

        foreach ([
            'AdjustedProfitOrLossBeforeAccountingPeriodAdjustments' => '1,749.32',
            'AdjustedProfitForThePeriod' => '1,749.32',
            'ProfitsThatCanBeCoveredByBroughtForwardLosses' => '1,749.32',
            'TradingLossesBroughtForwardValueClaimedAgainstTotalProfits' => '1,749.32',
        ] as $name => $value) {
            $fact = $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]')->item(0);
            $h->assertSame($value, $fact?->textContent);
            $h->assertSame('GBP', $fact?->getAttribute('unitRef'));
            $h->assertSame('2', $fact?->getAttribute('decimals'));
            $context = (string)$fact?->getAttribute('contextRef');
            $h->assertSame('2024-10-01', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:startDate)'));
            $h->assertSame('2025-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $context . '"]/xbrli:period/xbrli:endDate)'));
        }
        $carriedForward = $xpath->query('//ix:nonFraction[@name="ct:BalanceOfLossesBroughtForwardCarriedForward"]')->item(0);
        $h->assertSame('5,869.10', $carriedForward?->textContent);
        $carriedForwardContext = (string)$carriedForward?->getAttribute('contextRef');
        $h->assertSame('2025-09-30', $xpath->evaluate('string(//xbrli:context[@id="' . $carriedForwardContext . '"]/xbrli:period/xbrli:instant)'));
        $h->assertSame(0, $xpath->query('//ix:nonFraction[@name="ct:ProfitsBeforeOtherDeductionsAndReliefs"]')->length);
    });
    $h->check($service::class, 'renders the CT period 1 main-pool bridge with distinct WDV instants', static function () use ($h, $service): void {
        $model = ixbrlTaxComputationModel();
        $assets = [
            [1, 'Wall chaser', '2022-10-05', 94.99],
            [4, 'VDE pliers', '2022-10-06', 116.24],
            [2, 'Heat gun, battery and Wiring Regulations', '2022-10-07', 205.68],
            [24, 'Area light', '2022-10-08', 71.94],
            [3, 'Angled drill', '2022-10-09', 139.99],
        ];
        $model['model']['filing_decisions']['aia_claimed_in_trade'] = 628.84;
        $model['model']['computation']['summary']['capital_allowance_breakdown'] = [
            'rows' => [[
                'pool_type' => 'main_pool', 'opening_wdv' => 0.00, 'additions' => 0.00,
                'aia_claimed' => 628.84, 'fya_claimed' => 0.00, 'disposal_value' => 0.00,
                'wda_claimed' => 0.00, 'balancing_charge' => 0.00, 'balancing_allowance' => 0.00,
                'closing_wdv' => 0.00,
            ]],
            'asset_calculations' => array_map(static fn(array $asset): array => [
                'asset_id' => $asset[0], 'pool_type' => 'main_pool', 'allowance_type' => 'aia',
                'addition_amount' => $asset[3], 'allowance_amount' => $asset[3], 'disposal_value' => 0.00,
            ], $assets),
        ];
        $model['model']['audit']['capital_allowances']['rows'] = array_map(static fn(array $asset): array => [
            'source_date' => $asset[2], 'tax_adjustment_amount' => $asset[3],
            'metadata' => [
                'asset_id' => $asset[0], 'description' => $asset[1], 'purchase_date' => $asset[2],
                'allowance_type' => 'aia', 'addition_amount' => $asset[3], 'allowance_amount' => $asset[3],
            ],
        ], $assets);
        $method = new ReflectionMethod($service::class, 'renderMappedDocument');
        $method->setAccessible(true);
        $xhtml = (string)($method->invoke(
            $service,
            new \eel_accounts\Service\IxbrlGeneratorService(),
            $model,
            ixbrlTaxComputationMappings($model),
            'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
        ))['xhtml'];
        $document = new DOMDocument();
        $h->assertTrue($document->loadXML($xhtml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', \eel_accounts\Service\IxbrlGeneratorService::IX_NAMESPACE);
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $body = (string)$xpath->evaluate('string(/*[local-name()="html"]/*[local-name()="body"])');
        $h->assertSame(1, $xpath->query('//*[local-name()="h2" and text()="Main pool"]')->length);
        $h->assertTrue(str_contains($body, 'Annual Investment Allowance schedule'));
        $h->assertTrue(str_contains($body, 'Total capital allowances for the main pool'));
        $h->assertTrue(str_contains($body, '1,000,000.00'));
        $h->assertTrue(str_contains($body, '18.00%'));
        foreach ([
            'MainPoolExpenditureQualifyingForAnnualInvestmentAllowance' => '628.84',
            'MainPoolAnnualInvestmentAllowance' => '628.84',
            'MainPoolTotalAllowances' => '628.84',
            'MainPoolTotalDisposalReceipts' => '0.00',
            'MainPoolWritingDownAllowances' => '0.00',
            'MainPoolTotalFYAAndWDA' => '0.00',
        ] as $name => $value) {
            $fact = $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]')->item(0);
            $h->assertSame($value, $fact?->textContent);
            $h->assertSame(1, $xpath->query('//ix:nonFraction[@name="ct:' . $name . '"]')->length);
        }
        $wdv = $xpath->query('//ix:nonFraction[@name="ct:MainPoolWrittenDownValue"]');
        $h->assertSame(2, $wdv->length);
        $openingContext = (string)$wdv->item(0)?->getAttribute('contextRef');
        $closingContext = (string)$wdv->item(1)?->getAttribute('contextRef');
        $h->assertSame('2022-09-05', $xpath->evaluate('string(//xbrli:context[@id="' . $openingContext . '"]/xbrli:period/xbrli:instant)'));
        $h->assertSame('2023-09-04', $xpath->evaluate('string(//xbrli:context[@id="' . $closingContext . '"]/xbrli:period/xbrli:instant)'));
    });
    $h->check($service::class, 'renders the Section 455 repayment narrative in its qualifying CT period only', static function () use ($h, $service): void {
        $firstPeriod = ixbrlTaxComputationModel([
            'ct600a' => [
                'required' => false,
                'section_455_narrative' => 'repaid_within_period',
                'total_loans_outstanding' => 0.0,
                'tax_payable' => 0.0,
            ],
        ]);
        $secondPeriod = ixbrlTaxComputationModel([
            'period_start' => '2023-09-05',
            'period_end' => '2023-09-30',
            'ct600a' => [
                'required' => false,
                'total_loans_outstanding' => 0.0,
                'tax_payable' => 0.0,
            ],
        ]);
        $method = new ReflectionMethod($service::class, 'renderMappedDocument');
        $method->setAccessible(true);
        $firstXhtml = (string)($method->invoke(
            $service,
            new \eel_accounts\Service\IxbrlGeneratorService(),
            $firstPeriod,
            ixbrlTaxComputationMappings($firstPeriod),
            'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
        ))['xhtml'];
        $secondXhtml = (string)($method->invoke(
            $service,
            new \eel_accounts\Service\IxbrlGeneratorService(),
            $secondPeriod,
            ixbrlTaxComputationMappings($secondPeriod),
            'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd'
        ))['xhtml'];
        $visibleText = static function (string $xhtml): string {
            $document = new DOMDocument();
            if (!$document->loadXML($xhtml)) {
                throw new RuntimeException('The generated iXBRL could not be parsed as XHTML.');
            }
            return trim((string)preg_replace('/\s+/', ' ', (string)$document->textContent));
        };
        $statement = 'Repaid within the accounting period; no amount reportable and no Section 455 tax payable.';
        $firstText = $visibleText($firstXhtml);
        $secondText = $visibleText($secondXhtml);

        $h->assertSame(1, substr_count($firstText, $statement));
        $h->assertFalse(str_contains($firstText, 'No exposure'));
        $h->assertSame(0, substr_count($secondText, $statement));
        $h->assertFalse(str_contains($secondText, 'Section 455'));
        $h->assertFalse(str_contains($secondText, 'No reportable participator loan'));
        $h->assertSame([], (new \eel_accounts\Service\IxbrlGeneratorService())->validateStructure(
            $firstXhtml,
            ['http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd']
        ));
        $h->assertSame([], (new \eel_accounts\Service\IxbrlGeneratorService())->validateStructure(
            $secondXhtml,
            ['http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd']
        ));
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
