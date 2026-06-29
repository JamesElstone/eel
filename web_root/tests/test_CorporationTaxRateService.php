<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\CorporationTaxRateService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CorporationTaxRateService $service): void {
        $rateService = new \eel_accounts\Service\CorporationTaxRateService([
            [
                'financial_year_start' => '2022-04-01',
                'financial_year_end' => '2023-03-31',
                'rule_version' => 'fixture',
                'main_rate' => 0.19,
                'source_url' => 'https://example.test/rates',
                'source_checked_at' => '2026-04-01',
                'is_active' => 1,
            ],
            [
                'financial_year_start' => '2026-04-01',
                'financial_year_end' => '2027-03-31',
                'rule_version' => 'fixture',
                'main_rate' => 0.25,
                'small_profits_rate' => 0.19,
                'lower_limit' => 50000.0,
                'upper_limit' => 250000.0,
                'marginal_relief_fraction' => 0.015,
                'source_url' => 'https://example.test/rates',
                'source_checked_at' => '2026-04-01',
                'is_active' => 1,
            ],
        ]);

        $harness->check(\eel_accounts\Service\CorporationTaxRateService::class, 'uses 19 percent flat rate before 1 April 2023', static function () use ($harness, $rateService): void {
            $result = $rateService->calculate('2022-04-01', '2023-03-31', 100000.0);

            $harness->assertSame(19000.0, $result['liability']);
            $harness->assertSame(0.19, $result['effective_rate']);
        });

        $harness->check(\eel_accounts\Service\CorporationTaxRateService::class, 'uses small profits rate from 1 April 2023', static function () use ($harness, $rateService): void {
            $result = $rateService->calculate('2026-04-01', '2027-03-31', 40000.0);

            $harness->assertSame(7600.0, $result['liability']);
            $harness->assertSame(0.19, $result['effective_rate']);
        });

        $harness->check(\eel_accounts\Service\CorporationTaxRateService::class, 'applies marginal relief from 1 April 2023', static function () use ($harness, $rateService): void {
            $result = $rateService->calculate('2026-04-01', '2027-03-31', 100000.0);

            $harness->assertSame(22750.0, $result['liability']);
            $harness->assertSame(0.2275, $result['effective_rate']);
            $harness->assertSame('fixture', $result['bands'][0]['rule_version']);
        });

        $harness->check(\eel_accounts\Service\CorporationTaxRateService::class, 'reduces thresholds for associated companies', static function () use ($harness, $rateService): void {
            $result = $rateService->calculate('2026-04-01', '2027-03-31', 60000.0, 3);

            $harness->assertSame(14962.5, $result['liability']);
            $harness->assertSame(3, $result['associated_company_count']);
        });
    }
);
