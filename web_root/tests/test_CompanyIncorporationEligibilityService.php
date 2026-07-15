<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(
    \eel_accounts\Service\CompanyIncorporationEligibilityService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompanyIncorporationEligibilityService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\CompanyIncorporationEligibilityService::class,
            'accepts 5 January 2011 as the first supported incorporation date',
            static function () use ($harness, $service): void {
                $result = $service->evaluate('2011-01-05');

                $harness->assertSame('supported', $result['status'] ?? null);
                $harness->assertSame(true, $result['is_supported'] ?? null);
                $harness->assertSame('2011-01-05', $result['earliest_supported_date'] ?? null);
            }
        );

        $harness->check(
            \eel_accounts\Service\CompanyIncorporationEligibilityService::class,
            'rejects 4 January 2011 as before the supported cutoff',
            static function () use ($harness, $service): void {
                $result = $service->evaluate('2011-01-04');

                $harness->assertSame('before_cutoff', $result['status'] ?? null);
                $harness->assertSame(false, $result['is_supported'] ?? null);
            }
        );

        $harness->check(
            \eel_accounts\Service\CompanyIncorporationEligibilityService::class,
            'distinguishes missing and invalid Companies House dates',
            static function () use ($harness, $service): void {
                $missing = $service->evaluate(null);
                $invalid = $service->evaluate('05/01/2011');
                $impossible = $service->evaluate('2011-02-29');

                $harness->assertSame('missing', $missing['status'] ?? null);
                $harness->assertSame(false, $missing['is_supported'] ?? null);
                $harness->assertSame('invalid', $invalid['status'] ?? null);
                $harness->assertSame(false, $invalid['is_supported'] ?? null);
                $harness->assertSame('invalid', $impossible['status'] ?? null);
            }
        );
    }
);
