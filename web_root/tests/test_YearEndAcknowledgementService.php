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
$harness->run(\eel_accounts\Service\YearEndAcknowledgementService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\YearEndAcknowledgementService $service
): void {
    $harness->check(\eel_accounts\Service\YearEndAcknowledgementService::class, 'normalizes accounting facts and ignores presentation changes', static function () use ($harness, $service): void {
        $first = $service->buildBasis('expense_position_acknowledgement', [
            'available' => true,
            'accounting_period' => ['id' => '80', 'label' => '2023 / 2024', 'period_start' => ' 2023-10-01 ', 'period_end' => '2024-09-30'],
            'totals' => ['carried_forward' => '12.5'],
            'claimants' => [
                ['claimant_id' => '9', 'claimant_name' => 'Director', 'carried_forward' => 2.50, 'last_item_desc' => 'Hotel'],
                ['claimant_id' => 3, 'claimant_name' => 'Employee', 'carried_forward' => '10.00', 'last_item_desc' => 'Mileage'],
            ],
            'action_url' => '?page=expense_claims',
        ]);
        $second = $service->buildBasis('expense_position_acknowledgement', [
            'available' => true,
            'accounting_period' => ['id' => 80, 'label' => 'A different card label', 'period_start' => '2023-10-01', 'period_end' => '2024-09-30'],
            'totals' => ['carried_forward' => 12.50],
            'claimants' => [
                ['claimant_id' => 3, 'claimant_name' => 'Renamed for display', 'carried_forward' => 10.0, 'last_item_desc' => 'Changed wording'],
                ['claimant_id' => 9, 'claimant_name' => 'Another display name', 'carried_forward' => '2.50', 'last_item_desc' => 'Different wording'],
            ],
            'action_url' => '?page=elsewhere',
        ]);

        $harness->assertSame($service->hashBasis($first), $service->hashBasis($second));
        $json = json_encode($service->normalizedBasis($first));
        $harness->assertSame(false, str_contains((string)$json, 'Director'));
        $harness->assertSame(false, str_contains((string)$json, 'Hotel'));
        $harness->assertSame(false, str_contains((string)$json, '?page='));
        $harness->assertSame(true, str_contains((string)$json, '"carried_forward":"12.50"'));
    });

    $harness->check(\eel_accounts\Service\YearEndAcknowledgementService::class, 'self-invalidates and becomes current when the exact position is restored', static function () use ($harness, $service): void {
        $approvedBasis = $service->buildBasis('tax_readiness_acknowledgement', [
            'periods' => [
                ['ct_period_id' => 6, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30', 'taxable_profit' => 1000.0, 'estimated_corporation_tax' => 190.0],
            ],
        ]);
        $acknowledgement = [
            'basis_version' => \eel_accounts\Service\YearEndAcknowledgementService::BASIS_VERSION,
            'basis_hash' => $service->hashBasis($approvedBasis),
            'acknowledged_at' => '2026-07-13 12:00:00',
            'acknowledged_by' => 'test',
        ];

        $harness->assertSame('current', (string)$service->evaluate($acknowledgement, $approvedBasis)['state']);

        $changedBasis = $service->buildBasis('tax_readiness_acknowledgement', [
            'periods' => [
                ['ct_period_id' => 6, 'period_start' => '2023-10-01', 'period_end' => '2024-09-30', 'taxable_profit' => 1200.0, 'estimated_corporation_tax' => 228.0],
            ],
        ]);
        $harness->assertSame('stale', (string)$service->evaluate($acknowledgement, $changedBasis)['state']);
        $harness->assertSame('current', (string)$service->evaluate($acknowledgement, $approvedBasis)['state']);
        $lockedEvaluation = $service->evaluate($acknowledgement, $changedBasis, true);
        $harness->assertSame('current', (string)$lockedEvaluation['state']);
        $harness->assertSame(true, !empty($lockedEvaluation['approved_pre_close_position']));
    });

    $harness->check(\eel_accounts\Service\YearEndAcknowledgementService::class, 'treats legacy, version-mismatched, missing, and unavailable bases as ineffective', static function () use ($harness, $service): void {
        $basis = $service->buildBasis('transaction_tail_review', ['account_count' => 1]);
        $legacy = ['basis_version' => null, 'basis_hash' => null];
        $versionMismatch = ['basis_version' => 'old_version', 'basis_hash' => $service->hashBasis($basis)];
        $valid = ['basis_version' => \eel_accounts\Service\YearEndAcknowledgementService::BASIS_VERSION, 'basis_hash' => $service->hashBasis($basis)];

        $harness->assertSame('absent', (string)$service->evaluate(null, $basis)['state']);
        $harness->assertSame('stale', (string)$service->evaluate($legacy, $basis)['state']);
        $harness->assertSame('stale', (string)$service->evaluate($versionMismatch, $basis)['state']);
        $harness->assertSame('unverifiable', (string)$service->evaluate($valid, null)['state']);
        $harness->assertSame('stale', (string)$service->evaluate($versionMismatch, null)['state']);
        $harness->assertSame('current', (string)$service->evaluate($valid, null, true)['state']);
        $harness->assertSame('stale', (string)$service->evaluate($legacy, null, true)['state']);
    });

    $harness->check(\eel_accounts\Service\YearEndAcknowledgementService::class, 'builds compact evidence for every consolidated acknowledgement code', static function () use ($harness, $service): void {
        foreach ([
            'director_loan_closing_balance',
            'director_loan_tax_review',
            'expense_position_acknowledgement',
            'tax_readiness_acknowledgement',
            'retained_earnings_close_confirmation',
            'transaction_tail_review',
            'fixed_asset_review_placeholder',
            'cut_off_journals_review',
            'prepayment_approvals',
            'companies_house_mismatch_acknowledgement',
        ] as $checkCode) {
            $basis = $service->buildBasis($checkCode, [
                'available' => true,
                'source_id' => 7,
                'period_end' => '2024-09-30',
                'status' => ' Review_Required ',
                'amount' => '12.5',
                'label' => 'Presentation only',
                'url' => '?page=year_end',
            ]);
            $normalized = $service->normalizedBasis($basis);
            $harness->assertSame($checkCode, (string)($normalized['check_code'] ?? ''));
            $harness->assertSame(7, (int)($normalized['facts']['source_id'] ?? 0));
            $harness->assertSame('review_required', (string)($normalized['facts']['status'] ?? ''));
            $harness->assertSame('12.50', (string)($normalized['facts']['amount'] ?? ''));
            $harness->assertSame(false, array_key_exists('label', (array)($normalized['facts'] ?? [])));
            $harness->assertSame(false, array_key_exists('url', (array)($normalized['facts'] ?? [])));
        }
    });

    $harness->check(\eel_accounts\Service\YearEndAcknowledgementService::class, 'fresh and migrated schemas remove cached results and legacy approval columns', static function () use ($harness): void {
        $root = dirname(__DIR__, 2);
        $schema = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql');
        $migration = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'db_schema' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '2026_07_13_001_live_year_end_acknowledgements.sql');

        $harness->assertSame(false, str_contains($schema, 'CREATE TABLE `year_end_check_results`'));
        $harness->assertSame(false, str_contains($schema, '`last_recalculated_at`'));
        $harness->assertSame(false, str_contains($schema, '`tax_readiness_acknowledged_at`'));
        $harness->assertSame(true, str_contains($schema, '`basis_version` varchar(50)'));
        $harness->assertSame(true, str_contains($schema, '`basis_hash` char(64)'));
        $harness->assertSame(true, str_contains($schema, '`basis_json` longtext'));

        $harness->assertSame(true, str_contains($migration, 'DROP TABLE IF EXISTS year_end_check_results'));
        $harness->assertSame(true, str_contains($migration, 'DROP COLUMN IF EXISTS status'));
        $harness->assertSame(true, str_contains($migration, 'DROP COLUMN IF EXISTS last_recalculated_at'));
        $harness->assertSame(true, str_contains($migration, "'retained_earnings_close_confirmation'"));
        $harness->assertSame(true, str_contains($migration, 'NULL, NULL'));
    });
});
