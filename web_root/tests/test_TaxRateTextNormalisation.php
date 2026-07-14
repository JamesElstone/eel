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
    static function (GeneratedServiceClassTestHarness $harness): void {
        $harness->check('Tax rate text normalisation', 'normalises sterling symbols and importer mojibake in both HMRC parsers', static function () use ($harness): void {
            foreach ([
                new \eel_accounts\Service\TaxRateRuleService(),
                new \eel_accounts\Service\CorporationTaxRateRuleService(),
            ] as $service) {
                $method = new ReflectionMethod($service, 'normaliseText');
                $method->setAccessible(true);

                $harness->assertSame(
                    'Small ring fence profits rate (companies with profits under GBP 50,000)',
                    $method->invoke($service, "Small ring fence profits rate(companies with profits under \xc3\x82\xc2\xa350,000)")
                );
                $harness->assertSame(
                    'Main ring fence profits rate (companies with profits over GBP 250,000)',
                    $method->invoke($service, "Main ring fence profits rate\xc2\xa0(companies with profits over \xc2\xa3250,000)")
                );
                $harness->assertSame(
                    'Main rate ring fence (companies with profits over GBP 1,500,000)',
                    $method->invoke($service, "Main rate ring fence\xc3\x82\xc2\xa0(companies with profits over \xc3\x82\xc2\xa31,500,000)")
                );
            }
        });
    }
);
