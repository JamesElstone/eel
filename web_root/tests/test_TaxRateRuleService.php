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
    \eel_accounts\Service\TaxRateRuleService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TaxRateRuleService $service): void {
        $harness->check(\eel_accounts\Service\TaxRateRuleService::class, 'parses app-used HMRC tax and allowance rules', static function () use ($harness, $service): void {
            $ctHtml = '<html><body>
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
                        <tr><td>Special rate for unit trusts and open-ended, investment companies</td><td>20%</td><td>20%</td><td>20%</td><td>20%</td><td>20%</td></tr>
                    </tbody>
                </table>
                <table>
                    <thead><tr><th>Rate</th><th>2023 to 2026</th><th>2015 to 2022</th></tr></thead>
                    <tbody>
                        <tr><td>Small ring fence profits rate (companies with profits under GBP 50,000)</td><td>19%</td><td>-</td></tr>
                        <tr><td>Main ring fence profits rate (companies with profits over GBP 250,000)</td><td>30%</td><td>-</td></tr>
                        <tr><td>Small ring fence profits rate (companies with profits under GBP 300,000)</td><td>-</td><td>19%</td></tr>
                        <tr><td>Main rate ring fence (companies with profits over GBP 1,500,000)</td><td>-</td><td>30%</td></tr>
                        <tr><td>Ring fence fraction</td><td>11/400</td><td>11/400</td></tr>
                    </tbody>
                </table>
            </body></html>';

            $catalog = $service->parseCorporationTaxCatalogHtml($ctHtml, 'https://example.test/ct', '2026-07-07');
            $harness->assertSame(true, count($catalog) >= 11);
            $harness->assertSame(true, taxRateRuleTestHasRule($catalog, 'corporation_tax', 'special_unit_trust_oeic', 'special_rate', 0.2));
            $harness->assertSame(true, taxRateRuleTestHasRule($catalog, 'corporation_tax', 'ring_fence', 'main_rate', 0.3));
            $harness->assertSame(true, taxRateRuleTestHasRule($catalog, 'corporation_tax', 'ring_fence', 'ring_fence_fraction', 0.0275, 'fraction_value'));

            $wdaHtml = '<html><body>
                <p>The 3 types of pool are the: main pool with a rate of 14% from April 2026, and 18% before; special rate pool with a rate of 6%.</p>
                <p>The main rate of writing down allowance changed from 18% to 14% on: 1 April 2026 for Corporation Tax.</p>
            </body></html>';
            $wdaRules = $service->parseCapitalAllowanceWdaHtml($wdaHtml, 'https://example.test/wda', '2026-07-07');
            $harness->assertSame(true, taxRateRuleTestHasRule($wdaRules, 'capital_allowances', 'plant_machinery', 'main_pool_wda', 0.18));
            $harness->assertSame(true, taxRateRuleTestHasRule($wdaRules, 'capital_allowances', 'plant_machinery', 'main_pool_wda', 0.14));
            $harness->assertSame(true, taxRateRuleTestHasRule($wdaRules, 'capital_allowances', 'plant_machinery', 'special_rate_pool_wda', 0.06));

            $aiaHtml = '<html><body>
                <table>
                    <thead><tr><th>AIA</th><th>Sole traders/partnerships</th><th>Limited companies</th></tr></thead>
                    <tbody><tr><td>GBP 1 million</td><td>From 1 January 2019</td><td>From 1 January 2019</td></tr></tbody>
                </table>
            </body></html>';
            $aiaRules = $service->parseAnnualInvestmentAllowanceHtml($aiaHtml, 'https://example.test/aia', '2026-07-07');
            $harness->assertSame(true, taxRateRuleTestHasRule($aiaRules, 'capital_allowances', 'plant_machinery', 'aia_annual_limit', 1000000.0, 'amount_value'));

            $frsHtml = '<html><body><h3>For accounting periods that begin on or after 6 April 2025</h3><p>A micro-entity must meet at least 2 of the following conditions:</p><ul>
                <li>a turnover of £1 million or less</li>
                <li>£500,000 or less on its balance sheet</li>
                <li>10 employees or less</li>
            </ul><h3>For accounting periods beginning between 30 September 2013 and 5 April 2025</h3><p>A micro-entity must have met at least 2 of the following conditions:</p><ul>
                <li>an annual turnover no more than £632,000</li>
                <li>a balance sheet total no more than £316,000</li>
                <li>no more than 10 employees on average</li>
            </ul></body></html>';
            $frsRules = $service->parseFrs105ThresholdsHtml($frsHtml, 'https://example.test/frs105', '2026-07-17');
            $harness->assertSame(6, count($frsRules));
            $harness->assertSame(true, taxRateRuleTestHasRule($frsRules, 'company_size', 'frs105_micro_entity', 'turnover', 1000000.0, 'amount_value'));
            $harness->assertSame(true, taxRateRuleTestHasRule($frsRules, 'company_size', 'frs105_micro_entity', 'balance_sheet_total', 500000.0, 'amount_value'));
            $harness->assertSame(true, taxRateRuleTestHasRule($frsRules, 'company_size', 'frs105_micro_entity', 'employees', 10.0, 'amount_value'));
            $harness->assertSame(true, taxRateRuleTestHasRule($frsRules, 'company_size', 'frs105_micro_entity', 'turnover', 632000.0, 'amount_value'));
            $harness->assertSame(true, taxRateRuleTestHasRule($frsRules, 'company_size', 'frs105_micro_entity', 'balance_sheet_total', 316000.0, 'amount_value'));
            $harness->assertSame('2013-09-30', (string)($frsRules[0]['period_start'] ?? ''));
            $harness->assertSame('2025-04-05', (string)($frsRules[0]['period_end'] ?? ''));
            $harness->assertSame('2025-04-06', (string)($frsRules[3]['period_start'] ?? ''));
            $malformedFailed = false;
            try {
                $service->parseFrs105ThresholdsHtml('<p>broken source</p>');
            } catch (RuntimeException) {
                $malformedFailed = true;
            }
            $harness->assertSame(true, $malformedFailed);
        });

        $harness->check(\eel_accounts\Service\TaxRateRuleService::class, 'replaces active FRS 105 thresholds only with complete parsed bands', static function () use ($harness, $service): void {
            \InterfaceDB::beginTransaction();
            try {
                $service->ensureSchema();
                $legacyRuleVersion = 'legacy-' . bin2hex(random_bytes(4));
                \InterfaceDB::prepareExecute(
                    'INSERT INTO tax_rate_rules (
                        tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                        amount_value, source_url, source_checked_at, rule_version, is_active, notes
                     ) VALUES (
                        :domain, :regime, :rule_key, :rule_label, :period_start, :period_end, :value_type,
                        :amount_value, :source_url, :source_checked_at, :rule_version, 1, :notes
                     )',
                    [
                        'domain' => 'company_size',
                        'regime' => 'frs105_micro_entity',
                        'rule_key' => 'turnover',
                        'rule_label' => 'Legacy FRS 105 turnover threshold',
                        'period_start' => '1900-01-01',
                        'period_end' => '9999-12-31',
                        'value_type' => 'amount',
                        'amount_value' => 1.00,
                        'source_url' => 'https://example.test/legacy',
                        'source_checked_at' => '2026-01-01',
                        'rule_version' => $legacyRuleVersion,
                        'notes' => 'Legacy fixture.',
                    ]
                );
                $html = '<h3>For accounting periods beginning between 30 September 2013 and 5 April 2025</h3><ul><li>turnover no more than £632,000</li><li>£316,000 no more than on its balance sheet</li><li>10 employees on average</li></ul><h3>For accounting periods that begin on or after 6 April 2025</h3><ul><li>turnover no more than £1 million</li><li>£500,000 no more than on its balance sheet</li><li>10 employees on average</li></ul>';
                $rules = $service->parseFrs105ThresholdsHtml($html, 'https://example.test/frs105', '2026-07-17');
                $replacement = new ReflectionMethod($service, 'replaceActiveFrs105ThresholdRules');
                $incompleteFailed = false;
                try {
                    $replacement->invoke($service, array_slice($rules, 0, 3));
                } catch (ReflectionException|RuntimeException) {
                    $incompleteFailed = true;
                }
                $harness->assertSame(true, $incompleteFailed);
                $harness->assertSame(1, (int)\InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM tax_rate_rules WHERE rule_version = :rule_version AND is_active = 1',
                    ['rule_version' => $legacyRuleVersion]
                ));
                $replacement->invoke($service, $rules);

                $harness->assertSame(6, (int)\InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM tax_rate_rules WHERE tax_domain = :domain AND regime = :regime AND is_active = 1',
                    ['domain' => 'company_size', 'regime' => 'frs105_micro_entity']
                ));
                $harness->assertSame(0, (int)\InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM tax_rate_rules WHERE tax_domain = :domain AND regime = :regime AND period_start = :period_start AND is_active = 1',
                    ['domain' => 'company_size', 'regime' => 'frs105_micro_entity', 'period_start' => '1900-01-01']
                ));
            } finally {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(\eel_accounts\Service\TaxRateRuleService::class, 'weighted lookup reads active rule rows', static function () use ($harness, $service): void {
            \InterfaceDB::beginTransaction();
            try {
                taxRateRuleTestSeed($service);

                $harness->assertSame(0.14, $service->weightedRateForPeriod('capital_allowances', 'plant_machinery', 'main_pool_wda', '2026-04-01', '2026-04-30'));
                $harness->assertSame(1000000.0, $service->weightedAmountForPeriod('capital_allowances', 'plant_machinery', 'aia_annual_limit', '2026-04-01', '2026-04-30'));
                $hybrid = $service->weightedRateForPeriod('capital_allowances', 'plant_machinery', 'main_pool_wda', '2026-03-01', '2026-04-30');
                $harness->assertSame(0.160328, $hybrid);

                $weightedCache = new ReflectionProperty($service, 'weightedValueCache');
                $cacheCount = count((array)$weightedCache->getValue($service));
                $harness->assertSame(3, $cacheCount);
                $harness->assertSame(
                    $hybrid,
                    $service->weightedRateForPeriod('capital_allowances', 'plant_machinery', 'main_pool_wda', '2026-03-01', '2026-04-30')
                );
                $harness->assertSame($cacheCount, count((array)$weightedCache->getValue($service)));

                $service->clearRuntimeCaches();
                $harness->assertSame(0, count((array)$weightedCache->getValue($service)));
            } finally {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            }
        });
    }
);

function taxRateRuleTestHasRule(array $rules, string $domain, string $regime, string $key, float $value, string $valueColumn = 'rate_value'): bool
{
    foreach ($rules as $rule) {
        if (
            (string)($rule['tax_domain'] ?? '') === $domain
            && (string)($rule['regime'] ?? '') === $regime
            && (string)($rule['rule_key'] ?? '') === $key
            && round((float)($rule[$valueColumn] ?? -1), 6) === round($value, 6)
        ) {
            return true;
        }
    }

    return false;
}

function taxRateRuleTestSeed(\eel_accounts\Service\TaxRateRuleService $service): void
{
    $service->ensureSchema();
    \InterfaceDB::prepareExecute(
        'UPDATE tax_rate_rules SET is_active = 0 WHERE tax_domain = :domain AND regime = :regime AND rule_key IN (\'aia_annual_limit\', \'main_pool_wda\')',
        ['domain' => 'capital_allowances', 'regime' => 'plant_machinery']
    );
    foreach ([
        ['aia_annual_limit', 'amount', null, 1000000.0, '2019-01-01', '9999-12-31'],
        ['main_pool_wda', 'rate', 0.18, null, '1900-01-01', '2026-03-31'],
        ['main_pool_wda', 'rate', 0.14, null, '2026-04-01', '9999-12-31'],
    ] as $index => $row) {
        \InterfaceDB::prepareExecute(
            'INSERT INTO tax_rate_rules (
                tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                rate_value, amount_value, fraction_value, source_url, source_checked_at, rule_version, is_active, notes
             ) VALUES (
                :domain, :regime, :rule_key, :rule_label, :period_start, :period_end, :value_type,
                :rate_value, :amount_value, NULL, :source_url, :source_checked_at, :rule_version, 1, :notes
             )',
            [
                'domain' => 'capital_allowances',
                'regime' => 'plant_machinery',
                'rule_key' => (string)$row[0],
                'rule_label' => (string)$row[0],
                'period_start' => (string)$row[4],
                'period_end' => (string)$row[5],
                'value_type' => (string)$row[1],
                'rate_value' => $row[2],
                'amount_value' => $row[3],
                'source_url' => 'https://example.test/rates',
                'source_checked_at' => '2026-07-07',
                'rule_version' => 'fixture-' . (string)$index . '-' . random_int(100000, 999999),
                'notes' => 'Fixture sourced tax rate rule.',
            ]
        );
    }
}
