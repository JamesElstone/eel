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
    \eel_accounts\Service\Frs105YearEndProfileService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\Frs105YearEndProfileService $service): void {
        $eligible = [
            'thresholds_available' => true,
            'qualifies' => true,
            'thresholds' => [],
            'base_thresholds' => [],
            'metrics' => [],
            'passes' => ['turnover' => true, 'balance_sheet_total' => true, 'employees' => true],
            'pass_count' => 3,
        ];
        $supportedDisclosures = [
            'complete' => true,
            'profile_supported' => true,
            'disclosures' => [
                'accounting_standard' => 'FRS_105',
                'entity_trading_status' => 'trading',
                'entity_dormant' => 0,
                'micro_entity_eligibility_confirmed' => 1,
            ],
        ];

        $harness->check(\eel_accounts\Service\Frs105YearEndProfileService::class, 'passes only the supported FRS 105 trading micro-entity profile', static function () use ($harness, $service, $eligible, $supportedDisclosures): void {
            $result = $service->evaluate($supportedDisclosures, $eligible, ['total_debit' => 0.0, 'total_credit' => 0.0]);
            $harness->assertSame(true, (bool)($result['pass'] ?? false));
            $harness->assertSame([], (array)($result['errors'] ?? []));
        });

        $harness->check(\eel_accounts\Service\Frs105YearEndProfileService::class, 'rejects another profile and posted deferred-tax value', static function () use ($harness, $service, $eligible, $supportedDisclosures): void {
            $unsupported = $supportedDisclosures;
            $unsupported['complete'] = false;
            $unsupported['profile_supported'] = false;
            $unsupported['profile_errors'] = ['The selected accounts profile is unsupported.'];
            $unsupported['disclosures']['accounting_standard'] = 'FRS_102';
            $unsupported['disclosures']['entity_trading_status'] = 'no_longer_trading';

            $result = $service->evaluate($unsupported, $eligible, [
                'total_debit' => 25.0,
                'total_credit' => 0.0,
                'detail' => 'FRS 105 prohibits recognising deferred tax.',
            ]);
            $codes = array_column(array_filter((array)($result['checks'] ?? []), static fn(array $check): bool => empty($check['pass'])), 'code');

            $harness->assertSame(false, (bool)($result['pass'] ?? true));
            $harness->assertTrue(in_array('frs105_accounting_standard', $codes, true));
            $harness->assertTrue(in_array('ordinary_uk_trading_profile', $codes, true));
            $harness->assertTrue(in_array('frs105_disclosures_supported', $codes, true));
            $harness->assertTrue(in_array('frs105_deferred_tax_journal_value', $codes, true));
        });
    }
);
