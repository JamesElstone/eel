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
    CorporationTaxRateRuleService::class,
    static function (GeneratedServiceClassTestHarness $harness, CorporationTaxRateRuleService $service): void {
        $harness->check(CorporationTaxRateRuleService::class, 'parses GOV.UK Corporation Tax rates table', static function () use ($harness, $service): void {
            $html = '<html><body>
                <p>Updated 1 April 2026</p>
                <table>
                    <thead><tr><th>Rate</th><th>2026</th><th>2025</th><th>2024</th><th>2023</th><th>2022</th></tr></thead>
                    <tbody>
                        <tr><td>Small profits rate (companies with profits under GBP 50,000)</td><td>19%</td><td>19%</td><td>19%</td><td>19%</td><td>-</td></tr>
                        <tr><td>Main rate (companies with profits over GBP 250,000)</td><td>25%</td><td>25%</td><td>25%</td><td>25%</td><td>-</td></tr>
                        <tr><td>Main rate (all profits except ring fence profits)</td><td>-</td><td>-</td><td>-</td><td>-</td><td>19%</td></tr>
                        <tr><td>Marginal Relief lower limit</td><td>GBP 50,000</td><td>GBP 50,000</td><td>GBP 50,000</td><td>GBP 50,000</td><td>-</td></tr>
                        <tr><td>Marginal Relief upper limit</td><td>GBP 250,000</td><td>GBP 250,000</td><td>GBP 250,000</td><td>GBP 250,000</td><td>-</td></tr>
                        <tr><td>Standard fraction</td><td>3/200</td><td>3/200</td><td>3/200</td><td>3/200</td><td>-</td></tr>
                    </tbody>
                </table>
            </body></html>';

            $result = $service->parseGovUkHtml($html, 'https://example.test/rates', '2026-05-26');
            $rules = (array)$result['rules'];

            $harness->assertCount(5, $rules);
            $harness->assertSame('2026-04-01', $rules[0]['financial_year_start']);
            $harness->assertSame(0.25, $rules[0]['main_rate']);
            $harness->assertSame(0.19, $rules[0]['small_profits_rate']);
            $harness->assertSame(50000.0, $rules[0]['lower_limit']);
            $harness->assertSame(250000.0, $rules[0]['upper_limit']);
            $harness->assertSame(0.015, $rules[0]['marginal_relief_fraction']);
            $harness->assertSame('2022-04-01', $rules[4]['financial_year_start']);
            $harness->assertSame(0.19, $rules[4]['main_rate']);
            $harness->assertSame(null, $rules[4]['small_profits_rate']);
            $harness->assertSame('2026-04-01', $result['source_updated_at']);
        });

        $harness->check(CorporationTaxRateRuleService::class, 'parses shifted GOV.UK year window without requiring dropped years', static function () use ($harness, $service): void {
            $html = '<html><body>
                <p>Updated 1 April 2027</p>
                <table>
                    <thead><tr><th>Rate</th><th>2027</th><th>2026</th><th>2025</th><th>2024</th><th>2023</th></tr></thead>
                    <tbody>
                        <tr><td>Small profits rate (companies with profits under GBP 50,000)</td><td>19%</td><td>19%</td><td>19%</td><td>19%</td><td>19%</td></tr>
                        <tr><td>Main rate (companies with profits over GBP 250,000)</td><td>25%</td><td>25%</td><td>25%</td><td>25%</td><td>25%</td></tr>
                        <tr><td>Main rate (all profits except ring fence profits)</td><td>-</td><td>-</td><td>-</td><td>-</td><td>-</td></tr>
                        <tr><td>Marginal Relief lower limit</td><td>GBP 50,000</td><td>GBP 50,000</td><td>GBP 50,000</td><td>GBP 50,000</td><td>GBP 50,000</td></tr>
                        <tr><td>Marginal Relief upper limit</td><td>GBP 250,000</td><td>GBP 250,000</td><td>GBP 250,000</td><td>GBP 250,000</td><td>GBP 250,000</td></tr>
                        <tr><td>Standard fraction</td><td>3/200</td><td>3/200</td><td>3/200</td><td>3/200</td><td>3/200</td></tr>
                    </tbody>
                </table>
            </body></html>';

            $result = $service->parseGovUkHtml($html, 'https://example.test/rates', '2027-05-26');
            $rules = (array)$result['rules'];

            $harness->assertCount(5, $rules);
            $harness->assertSame('2027-04-01', $rules[0]['financial_year_start']);
            $harness->assertSame('2023-04-01', $rules[4]['financial_year_start']);
            $harness->assertSame(true, str_starts_with((string)$rules[0]['rule_version'], 'govuk-fy2027-'));
            $harness->assertSame('2027-04-01', $result['source_updated_at']);
        });
    }
);
