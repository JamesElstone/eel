<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

/** @return array<string,mixed> */
function ct600_return_model_test_filing(): array
{
    return [
        'available' => true,
        'basis_version' => 'ct-period-filing-model-test-v1',
        'basis_hash' => str_repeat('a', 64),
        'blocking_diagnostics' => [],
        'warning_diagnostics' => [],
        'facts' => [
            'identity.company_name' => 'Example Trading Limited',
            'identity.company_number' => '01234567',
            'filing_identity.utr' => '0123456789',
        ],
        'model' => [
            'supported_return_profile' => [
                'supported' => true,
                'ordinary_trading_company_confirmed' => true,
                'failed_checks' => [],
            ],
            'identity' => [
                'company_id' => 49,
                'company_name' => 'Example Trading Limited',
                'company_number' => '01234567',
            ],
            'filing_identity' => ['utr' => '0123456789'],
            'accounting_period' => [
                'id' => 79,
                'start_date' => '2022-09-05',
                'end_date' => '2023-09-30',
            ],
            'ct_period' => [
                'id' => 6,
                'start_date' => '2022-09-05',
                'end_date' => '2023-09-04',
            ],
            'accounts_facts' => [
                'presentation_currency' => 'GBP',
                'turnover' => 250000.25,
            ],
            'accounts_report' => [
                'basis_version' => 'accounts-report-test-v1',
                'basis_hash' => str_repeat('b', 64),
            ],
            'approval' => [
                'id' => 91,
                'basis_hash' => str_repeat('c', 64),
            ],
            'computation' => [
                'run_id' => 101,
                'hash' => str_repeat('d', 64),
                'summary' => [
                    'accounting_profit' => 80000.0,
                    'capital_allowances' => 5000.0,
                    'taxable_before_losses' => 75000.0,
                    'taxable_profit' => 70000.0,
                    'taxable_loss' => 0.0,
                    'losses_brought_forward' => 5000.0,
                    'losses_used' => 5000.0,
                    'losses_carried_forward' => 0.0,
                    'loss_created_in_period' => 0.0,
                    'ordinary_corporation_tax' => 13300.0,
                    'estimated_corporation_tax' => 13300.0,
                    's455_tax' => 0.0,
                    'associated_company_count' => 0,
                ],
            ],
            'filing_decisions' => [
                'return_type' => 'new',
                'company_type' => 0,
                'this_period_return' => true,
                'multiple_returns' => true,
                'accounts_attached' => true,
                'accounts_same_period' => false,
                'computations_attached' => true,
                'computations_same_period' => true,
                'supplementary_pages' => [],
                'loss_relief_treatment' => 'trading_brought_forward_against_same_trade_profit',
                'trading_profit_before_losses' => 75000.0,
                'trading_losses_brought_forward_used' => 5000.0,
                'net_trading_profits' => 70000.0,
                'profits_before_other_deductions' => 70000.0,
                'profits_before_donations_group_relief' => 70000.0,
                'associated_company_count' => 0,
                'tax_calculation_bands' => [[
                    'financial_year' => '2022',
                    'profit' => 70000.0,
                    'tax_rate_percent' => 19.0,
                    'gross_tax' => 13300.0,
                    'marginal_relief' => 0.0,
                    'net_tax' => 13300.0,
                    'basis' => 'flat_main_rate',
                ]],
                'aia_claimed_in_trade' => 5000.0,
                'main_pool_capital_allowances' => 5000.0,
                'main_pool_balancing_charges' => 0.0,
                'special_rate_pool_capital_allowances' => 0.0,
                'special_rate_pool_balancing_charges' => 0.0,
                'qualifying_expenditure_other_machinery_plant' => 5000.0,
            ],
        ],
    ];
}

/** @return \eel_accounts\Service\Ct600ReturnModelService */
function ct600_return_model_test_service(array $filing): \eel_accounts\Service\Ct600ReturnModelService
{
    return new \eel_accounts\Service\Ct600ReturnModelService(
        static fn(int $companyId, int $accountingPeriodId, int $ctPeriodId): array => $filing,
        static fn(string $periodStart, string $periodEnd): array => [
            'ok' => true,
            'package_id' => 21,
            'form_version' => 'V3',
            'artifact_version' => 'V1.994',
            'sha256' => str_repeat('e', 64),
            'warnings' => [],
        ],
        static fn(int $packageId): array => [
            'id' => 31,
            'revision_no' => 4,
            'content_hash' => str_repeat('f', 64),
            'rim_package_id' => $packageId,
            'status' => 'active',
            'compatibility_status' => 'compatible',
        ],
        static function (array $mappingInput, array $profile): array {
            if (($mappingInput['facts']['ct600.identity.utr'] ?? null) !== '0123456789') {
                throw new RuntimeException('The derived CT600 aliases were not supplied to the mapper.');
            }
            return [
                'success' => true,
                'errors' => [],
                'monetary_policy_version' => 'test-monetary-policy-v1',
                'mappings' => [
                    [
                        'canonical_key' => 'identity.company_name',
                        'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName',
                        'source_value' => 'Example Trading Limited',
                    ],
                    [
                        'canonical_key' => 'computation.summary.ordinary_corporation_tax',
                        'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable',
                        'source_value' => 13300.0,
                    ],
                ],
                'profile_id' => (int)$profile['id'],
            ];
        }
    );
}

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\Ct600ReturnModelService::class,
    static function (GeneratedServiceClassTestHarness $h): void {
        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'builds the same return and source manifest from the same frozen basis',
            static function () use ($h): void {
                $service = ct600_return_model_test_service(ct600_return_model_test_filing());
                $first = $service->build(49, 79, 6);
                $second = $service->build(49, 79, 6);

                $h->assertSame(true, (bool)$first['ok']);
                $h->assertSame($first['model_json'], $second['model_json']);
                $h->assertSame($first['model_sha256'], $second['model_sha256']);
                $h->assertSame($first['source_manifest_sha256'], $second['source_manifest_sha256']);
                $h->assertSame('0123456789', $first['model']['identity']['utr']);
                $h->assertSame(250000.25, $first['model']['amounts']['turnover']);
                $h->assertSame(21, $first['source_manifest']['rim_package_id']);
                $h->assertSame(31, $first['source_manifest']['mapping_profile_id']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'fails closed when the frozen Corporation Tax UTR is invalid',
            static function () use ($h): void {
                $filing = ct600_return_model_test_filing();
                $filing['model']['filing_identity']['utr'] = '12345';
                $result = ct600_return_model_test_service($filing)->build(49, 79, 6);

                $h->assertSame(false, (bool)$result['ok']);
                $h->assertTrue(str_contains(implode(' ', (array)$result['errors']), '10-digit Corporation Tax UTR'));
                $h->assertSame([], $result['model']);
                $h->assertSame('', $result['source_manifest_sha256']);
            }
        );

        $h->check(
            \eel_accounts\Service\Ct600ReturnModelService::class,
            'fails closed when the frozen facts require a supplementary CT600A page',
            static function () use ($h): void {
                $filing = ct600_return_model_test_filing();
                $filing['model']['computation']['summary']['s455_tax'] = 25.0;
                $result = ct600_return_model_test_service($filing)->build(49, 79, 6);

                $h->assertSame(false, (bool)$result['ok']);
                $errors = implode(' ', (array)$result['errors']);
                $h->assertTrue(str_contains($errors, 'supplementary CT600 page'));
                $h->assertTrue(str_contains($errors, 'CT600A'));
            }
        );
    }
);
