<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return list<array<string,mixed>> */
function ct600_builder_test_mappings(array $amounts): array
{
    $money = static fn(mixed $value): string => number_format((float)$value, 2, '.', '');
    $values = [
        'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName' => 'Example Trading Limited',
        'IRenvelope/CompanyTaxReturn/CompanyInformation/RegistrationNumber' => '01234567',
        'IRenvelope/CompanyTaxReturn/CompanyInformation/Reference' => '0123456789',
        'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/From' => '2023-01-01',
        'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/To' => '2023-12-31',
        'IRenvelope/CompanyTaxReturn/Turnover/Total' => '250000.00',
        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits'
            => $money($amounts['chargeable_profits'] ?? 0),
        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable'
            => $money($amounts['net_corporation_tax'] ?? 0),
        'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxPayable'
            => $money($amounts['tax_payable'] ?? 0),
    ];

    $optional = [
        'trading_profit' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/Profits',
        'losses_brought_forward'
            => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward',
        'net_trading_profits'
            => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/NetProfits',
        'profits_before_other_deductions'
            => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ProfitsBeforeOtherDeductions',
        'profits_before_donations'
            => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargesAndReliefs/'
                . 'ProfitsBeforeDonationsAndGroupRelief',
        'net_corporation_tax_liability'
            => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/'
                . 'NetCorporationTaxLiability',
        'tax_chargeable'
            => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable',
        'loans_to_participators'
            => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/LoansToParticipators',
        'aia' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/AIACapitalAllowancesInc',
        'main_pool_allowance'
            => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantMainPool/'
                . 'CapitalAllowances',
        'main_pool_charge'
            => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantMainPool/'
                . 'BalancingCharges',
        'special_pool_allowance'
            => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantSpecialRatePool/'
                . 'CapitalAllowances',
        'special_pool_charge'
            => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantSpecialRatePool/'
                . 'BalancingCharges',
        'qualifying_expenditure'
            => 'IRenvelope/CompanyTaxReturn/QualifyingExpenditure/OtherMachineryAndPlant',
        'loss_arising'
            => 'IRenvelope/CompanyTaxReturn/LossesDeficitsAndExcess/AmountArising/'
                . 'LossesOfTradesUK/Arising',
    ];
    foreach ($optional as $key => $path) {
        if (array_key_exists($key, $amounts)) {
            $values[$path] = $money($amounts[$key]);
        }
    }

    $mappings = [];
    $position = 1;
    foreach ($values as $path => $value) {
        $mappings[] = [
            'canonical_key' => 'test.fact.' . $position,
            'target_xpath' => $path,
            'serialized_value' => $value,
        ];
        $position++;
    }
    return $mappings;
}

/** @return array<string,mixed> */
function ct600_builder_test_return(array $amounts, array $taxBands, string $artifactVersion = 'V1.994'): array
{
    $grossTax = round(array_sum(array_map(
        static fn(array $band): float => (float)($band['gross_tax'] ?? 0),
        $taxBands
    )), 2);
    return [
        'ok' => true,
        'rim' => [
            'package_id' => 21,
            'form_version' => 'V3',
            'artifact_version' => $artifactVersion,
        ],
        'mapping' => ['mappings' => ct600_builder_test_mappings($amounts)],
        'model' => [
            'identity' => [
                'company_name' => 'Example Trading Limited',
                'company_number' => '01234567',
                'utr' => '0123456789',
                'company_type' => 0,
            ],
            'period' => [
                'start_date' => '2023-01-01',
                'end_date' => '2023-12-31',
            ],
            'return' => [
                'type' => 'new',
                'this_period' => true,
                'multiple_returns' => false,
            ],
            'attachments' => [
                'accounts_same_period' => true,
                'computations_same_period' => true,
            ],
            'calculation' => [
                'associated_company_count' => 0,
                'tax_bands' => $taxBands,
                'gross_corporation_tax' => $grossTax,
                'marginal_relief' => 0.0,
            ],
        ],
        'source_manifest' => [
            'ct_period_filing_basis_sha256' => str_repeat('a', 64),
            'rim_package_sha256' => str_repeat('b', 64),
            'mapping_profile_sha256' => str_repeat('c', 64),
        ],
        'source_manifest_sha256' => str_repeat('d', 64),
        'warnings' => [],
        'errors' => [],
    ];
}

/** @return array<string,mixed> */
function ct600_builder_test_build(array $return, int $ctPeriodId): array
{
    $service = new \eel_accounts\Service\Ct600BuilderService(
        static fn(int $companyId, int $accountingPeriodId, int $periodId): array => $return
    );
    return $service->buildForIds(997001, 997002, $ctPeriodId, [
        'declaration_confirmed' => true,
        'declarant_name' => 'Jamie Example',
        'declarant_status' => 'Director',
    ]);
}

function ct600_builder_test_xpath(string $xml): DOMXPath
{
    $document = new DOMDocument();
    if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        throw new RuntimeException('The generated CT600 XML could not be parsed by the test.');
    }
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('ct', \eel_accounts\Service\Ct600BuilderService::CT_NAMESPACE);
    return $xpath;
}

function ct600_builder_test_official_schema_path(): string
{
    $root = defined('PROJECT_ROOT') ? (string)PROJECT_ROOT : dirname(__DIR__, 2);
    return rtrim($root, '\\/') . DIRECTORY_SEPARATOR . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc'
        . DIRECTORY_SEPARATOR . 'ct600-rim' . DIRECTORY_SEPARATOR . 'ct600-v3-artefacts-v1.994'
        . DIRECTORY_SEPARATOR . 'CT-2014-v1-994.xsd';
}

function ct600_builder_test_assert_official_schema(
    GeneratedServiceClassTestHarness $harness,
    string $xml
): void {
    $schema = ct600_builder_test_official_schema_path();
    if (!is_file($schema)) {
        $harness->skip('The locally extracted official CT600 V3 primary XSD is not installed.');
    }

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    try {
        $document = new DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('The generated CT600 XML is not well formed.');
        }
        if (!$document->schemaValidate($schema)) {
            $messages = array_map(
                static fn(LibXMLError $error): string => trim($error->message),
                libxml_get_errors()
            );
            throw new RuntimeException(
                'Official CT600 V3 XSD validation failed: ' . implode(' | ', array_filter($messages))
            );
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600BuilderService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\Ct600BuilderService $service): void {
        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'missing company fails cleanly',
            static function () use ($harness, $service): void {
                $result = $service->buildCt600Xml(0, 0);
                $harness->assertSame(false, $result['ok']);
                $harness->assertTrue(count($result['errors']) > 0);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'is deterministic and accepts an uppercase V artifact version',
            static function () use ($harness): void {
                $return = ct600_builder_test_return(
                    [
                        'chargeable_profits' => '70000',
                        'net_corporation_tax' => '13300.00',
                        'net_corporation_tax_liability' => '13300.00',
                        'tax_chargeable' => '13300.00',
                        'tax_payable' => '13300.00',
                        'trading_profit' => '70000',
                        'net_trading_profits' => '70000',
                        'profits_before_other_deductions' => '70000',
                        'profits_before_donations' => '70000',
                    ],
                    [[
                        'financial_year' => '2023',
                        'profit' => 70000.0,
                        'tax_rate_percent' => 19.0,
                        'gross_tax' => 13300.0,
                        'basis' => 'flat_main_rate',
                    ]],
                    'V1.994'
                );
                $first = ct600_builder_test_build($return, 997011);
                $second = ct600_builder_test_build($return, 997011);

                $harness->assertSame(true, (bool)($first['ok'] ?? false));
                $harness->assertSame($first['xml'], $second['xml']);
                $harness->assertSame($first['body_sha256'], $second['body_sha256']);
                $harness->assertSame($first['path'], $second['path']);
                $xpath = ct600_builder_test_xpath((string)$first['xml']);
                $harness->assertSame(
                    '2014-v1.994',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:IRheader/ct:Manifest/ct:Contains/'
                        . 'ct:Reference/ct:SchemaVersion)')
                );
                $harness->assertSame(
                    '13300.00',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:CompanyTaxCalculation/ct:CorporationTax)')
                );
                ct600_builder_test_assert_official_schema($harness, (string)$first['xml']);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'serializes an explicit brought-forward same-trade loss path',
            static function () use ($harness): void {
                $return = ct600_builder_test_return([
                    'chargeable_profits' => '0',
                    'net_corporation_tax' => '0.00',
                    'tax_payable' => '0.00',
                    'trading_profit' => '5',
                    'losses_brought_forward' => '5',
                    'net_trading_profits' => '0',
                ], []);
                $return['model']['filing_decisions'] = [
                    'loss_relief_treatment' => 'trading_brought_forward_against_same_trade_profit',
                    'trading_losses_brought_forward_used' => 5.0,
                ];
                $result = ct600_builder_test_build($return, 997012);

                $harness->assertSame(true, (bool)($result['ok'] ?? false));
                $xpath = ct600_builder_test_xpath((string)$result['xml']);
                $harness->assertSame(
                    '5.00',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:CompanyTaxCalculation/ct:Income/ct:Trading/ct:Profits)')
                );
                $harness->assertSame(
                    '5.00',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:CompanyTaxCalculation/ct:Income/ct:Trading/ct:LossesBroughtForward)')
                );
                $harness->assertSame(
                    '0.00',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:CompanyTaxCalculation/ct:Income/ct:Trading/ct:NetProfits)')
                );
                ct600_builder_test_assert_official_schema($harness, (string)$result['xml']);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'emits loss-created box 780 and suppresses the empty loss wrapper',
            static function () use ($harness): void {
                $lossReturn = ct600_builder_test_return([
                    'chargeable_profits' => '0',
                    'net_corporation_tax' => '0.00',
                    'tax_payable' => '0.00',
                    'aia' => '500',
                    'main_pool_allowance' => '128',
                    'qualifying_expenditure' => '1000',
                    'loss_arising' => '563',
                ], []);
                $loss = ct600_builder_test_build($lossReturn, 997013);
                $harness->assertSame(true, (bool)($loss['ok'] ?? false));
                $lossXpath = ct600_builder_test_xpath((string)$loss['xml']);
                $harness->assertSame(
                    '563.00',
                    $lossXpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:LossesDeficitsAndExcess/ct:AmountArising/ct:LossesOfTradesUK/ct:Arising)')
                );
                $harness->assertSame(
                    1,
                    $lossXpath->query('/ct:IRenvelope/ct:CompanyTaxReturn/ct:LossesDeficitsAndExcess')?->length
                );
                ct600_builder_test_assert_official_schema($harness, (string)$loss['xml']);

                $zeroReturn = ct600_builder_test_return([
                    'chargeable_profits' => '0',
                    'net_corporation_tax' => '0.00',
                    'tax_payable' => '0.00',
                    'loss_arising' => '0',
                ], []);
                $zero = ct600_builder_test_build($zeroReturn, 997014);
                $harness->assertSame(true, (bool)($zero['ok'] ?? false));
                $zeroXpath = ct600_builder_test_xpath((string)$zero['xml']);
                $harness->assertSame(
                    0,
                    $zeroXpath->query('/ct:IRenvelope/ct:CompanyTaxReturn/ct:LossesDeficitsAndExcess')?->length
                );
                ct600_builder_test_assert_official_schema($harness, (string)$zero['xml']);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'serializes a full CT600A page and validates it against the official schema',
            static function () use ($harness): void {
                $return = ct600_builder_test_return([
                    'chargeable_profits' => '1000',
                    'net_corporation_tax' => '190.00',
                    'net_corporation_tax_liability' => '190.00',
                    'loans_to_participators' => '303.75',
                    'tax_chargeable' => '493.75',
                    'tax_payable' => '493.75',
                    'trading_profit' => '1000',
                    'net_trading_profits' => '1000',
                    'profits_before_other_deductions' => '1000',
                    'profits_before_donations' => '1000',
                ], [[
                    'financial_year' => '2023', 'profit' => 1000.0, 'tax_rate_percent' => 19.0,
                    'gross_tax' => 190.0, 'basis' => 'flat_main_rate',
                ]]);
                $return['model']['attachments']['supplementary_pages'] = ['CT600A'];
                $return['model']['ct600a'] = [
                    'required' => true,
                    'before_end_period' => false,
                    'part1' => ['rows' => [['name' => 'Jamie Example', 'amount' => 1000.0, 'tax' => 337.5]], 'total_loans' => 1000.0, 'tax_chargeable' => 337.5],
                    'part2' => ['rows' => [['name' => 'Jamie Example', 'amount_repaid' => 100.0, 'amount_released_or_written_off' => 0.0, 'date' => '2024-03-01']], 'total_repaid' => 100.0, 'total_released_or_written_off' => 0.0, 'total' => 100.0, 'relief_due' => 33.75],
                    'part3' => ['rows' => [], 'total_repaid' => 0.0, 'total_released_or_written_off' => 0.0, 'total' => 0.0, 'relief_due' => 0.0],
                    'total_loans_outstanding' => 1000.0,
                    'tax_payable' => 303.75,
                    'relief_due' => false,
                ];
                $result = ct600_builder_test_build($return, 997016);
                $harness->assertSame(true, (bool)($result['ok'] ?? false));
                $xpath = ct600_builder_test_xpath((string)$result['xml']);
                $harness->assertSame('yes', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:ReturnInfoSummary/ct:SupplementaryPages/ct:CT600A)'));
                $harness->assertSame('303.75', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:LoansByCloseCompanies/ct:TaxPayable)'));
                $harness->assertSame('1000.00', $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/ct:LoansByCloseCompanies/ct:TotalLoansOutstanding)'));
                ct600_builder_test_assert_official_schema($harness, (string)$result['xml']);
            }
        );

        $harness->check(
            \eel_accounts\Service\Ct600BuilderService::class,
            'serializes and validates a profitable two-financial-year calculation',
            static function () use ($harness): void {
                $return = ct600_builder_test_return(
                    [
                        'chargeable_profits' => '30000',
                        'net_corporation_tax' => '6900.00',
                        'net_corporation_tax_liability' => '6900.00',
                        'tax_chargeable' => '6900.00',
                        'tax_payable' => '6900.00',
                        'trading_profit' => '30000',
                        'net_trading_profits' => '30000',
                        'profits_before_other_deductions' => '30000',
                        'profits_before_donations' => '30000',
                    ],
                    [
                        [
                            'financial_year' => '2022',
                            'profit' => 10000.0,
                            'tax_rate_percent' => 19.0,
                            'gross_tax' => 1900.0,
                            'basis' => 'flat_main_rate',
                        ],
                        [
                            'financial_year' => '2023',
                            'profit' => 20000.0,
                            'tax_rate_percent' => 25.0,
                            'gross_tax' => 5000.0,
                            'basis' => 'flat_main_rate',
                        ],
                    ]
                );
                $result = ct600_builder_test_build($return, 997015);

                $harness->assertSame(true, (bool)($result['ok'] ?? false));
                $xpath = ct600_builder_test_xpath((string)$result['xml']);
                $harness->assertSame(
                    2,
                    $xpath->query('/ct:IRenvelope/ct:CompanyTaxReturn/ct:CompanyTaxCalculation/'
                        . 'ct:CorporationTaxChargeable/*[self::ct:FinancialYearOne or self::ct:FinancialYearTwo]')?->length
                );
                $harness->assertSame(
                    '6900.00',
                    $xpath->evaluate('string(/ct:IRenvelope/ct:CompanyTaxReturn/'
                        . 'ct:CompanyTaxCalculation/ct:CorporationTax)')
                );
                ct600_builder_test_assert_official_schema($harness, (string)$result['xml']);
            }
        );
    }
);
