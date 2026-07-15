<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountsFixture.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

goldenTaxFixtureIntegrityCheck($harness, 'makes the completed CT snapshot consumable through TaxWorkingsService', static function () use ($harness): void {
    $workings = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings(
        GoldenAccountsFixture::COMPLETE_COMPANY_ID,
        9411,
        9540
    );

    if (empty($workings['available'])) {
        throw new RuntimeException(
            'Completed company 9400 has accepted CT evidence, but TaxWorkingsService returned unavailable: '
            . implode(' ', (array)($workings['errors'] ?? []))
        );
    }

    $harness->assertSame('locked_snapshot', (string)(($workings['summary'] ?? [])['summary_source'] ?? ''));
});

goldenTaxFixtureIntegrityCheck($harness, 'reconciles the completed CT summary to its capital allowance pools', static function () use ($harness): void {
    $run = InterfaceDB::fetchOne(
        'SELECT summary_json FROM corporation_tax_computation_runs WHERE id = :id',
        ['id' => 9541]
    );
    $summary = json_decode((string)($run['summary_json'] ?? ''), true);
    if (!is_array($summary)) {
        throw new RuntimeException('Completed company 9400 has no readable persisted CT summary.');
    }

    $poolNet = (float)InterfaceDB::fetchColumn(
        'SELECT COALESCE(SUM(aia_claimed + fya_claimed + wda_claimed + balancing_allowance - balancing_charge), 0)
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND ct_period_id = :ct_period_id',
        [
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => 9411,
            'ct_period_id' => 9540,
        ]
    );

    $harness->assertSame(
        number_format($poolNet, 2, '.', ''),
        number_format((float)($summary['capital_allowances'] ?? 0), 2, '.', '')
    );
});

goldenTaxFixtureIntegrityCheck($harness, 'uses production capital-allowance pool identifiers in completed evidence', static function () use ($harness): void {
    $invalidPoolTypes = InterfaceDB::fetchAll(
        'SELECT DISTINCT pool_type
         FROM capital_allowance_pool_runs
         WHERE company_id = :company_id
           AND pool_type NOT IN (:main_pool, :special_rate_pool)',
        [
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'main_pool' => 'main_pool',
            'special_rate_pool' => 'special_rate_pool',
        ]
    );
    $invalidAssetTypes = InterfaceDB::fetchAll(
        'SELECT DISTINCT pool_type
         FROM capital_allowance_asset_calculations
         WHERE company_id = :company_id
           AND pool_type NOT IN (:main_pool, :special_rate_pool, :unreviewed)',
        [
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'main_pool' => 'main_pool',
            'special_rate_pool' => 'special_rate_pool',
            'unreviewed' => 'unreviewed',
        ]
    );

    if ($invalidPoolTypes !== [] || $invalidAssetTypes !== []) {
        $poolTypes = implode(', ', array_map(static fn(array $row): string => (string)$row['pool_type'], $invalidPoolTypes));
        $assetTypes = implode(', ', array_map(static fn(array $row): string => (string)$row['pool_type'], $invalidAssetTypes));
        throw new RuntimeException(
            'Completed company 9400 uses unsupported pool identifiers: pool runs [' . $poolTypes
            . '], asset calculations [' . $assetTypes . '].'
        );
    }
});

goldenTaxFixtureIntegrityCheck($harness, 'uses a prior CT period as the source of completed-company brought-forward losses', static function () use ($harness): void {
    $history = InterfaceDB::fetchOne(
        'SELECT loss_brought_forward, loss_utilised
         FROM tax_loss_movement_history
         WHERE company_id = :company_id AND ct_period_id = :ct_period_id',
        ['company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID, 'ct_period_id' => 9540]
    );
    $source = InterfaceDB::fetchOne(
        'SELECT origin_period.period_end AS origin_period_end,
                target_period.period_start AS target_period_start
         FROM tax_loss_carryforwards losses
         INNER JOIN corporation_tax_periods origin_period ON origin_period.id = losses.origin_ct_period_id
         INNER JOIN corporation_tax_periods target_period ON target_period.id = :ct_period_id
         WHERE losses.company_id = :company_id
           AND losses.amount_used >= :loss_utilised
         ORDER BY origin_period.period_end ASC
         LIMIT 1',
        [
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'ct_period_id' => 9540,
            'loss_utilised' => (float)($history['loss_utilised'] ?? 0),
        ]
    );

    $harness->assertTrue((float)($history['loss_brought_forward'] ?? 0) > 0);
    $harness->assertTrue((float)($history['loss_utilised'] ?? 0) > 0);
    if ($source === []) {
        throw new RuntimeException(
            'Completed company 9400 consumes ' . number_format((float)($history['loss_utilised'] ?? 0), 2)
            . ' of brought-forward losses but has no tax_loss_carryforwards source row.'
        );
    }
    if ((string)$source['origin_period_end'] >= (string)$source['target_period_start']) {
        throw new RuntimeException(
            'Completed company 9400 sources brought-forward losses from a non-prior CT period ending '
            . (string)$source['origin_period_end'] . '.'
        );
    }
});

goldenTaxFixtureIntegrityCheck($harness, 'routes the completed used high-CO2 car through special-rate WDA', static function () use ($harness): void {
    (new \eel_accounts\Service\CapitalAllowanceService())
        ->rebuildForCompany(GoldenAccountsFixture::COMPLETE_COMPANY_ID);
    $calculation = InterfaceDB::fetchOne(
        'SELECT pool_type, allowance_type
         FROM capital_allowance_asset_calculations
         WHERE company_id = :company_id AND asset_id = :asset_id
         ORDER BY id ASC
         LIMIT 1',
        ['company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID, 'asset_id' => 9563]
    );

    $harness->assertSame('special_rate_pool', (string)($calculation['pool_type'] ?? ''));
    $harness->assertSame('wda', (string)($calculation['allowance_type'] ?? ''));
});

goldenTaxFixtureIntegrityCheck($harness, 'contains an actual vehicle tax-warning state', static function () use ($harness): void {
    $vehicleCount = (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM asset_vehicle_details WHERE company_id IN (:golden_company_id, :complete_company_id)',
        [
            'golden_company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'complete_company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
        ]
    );
    $warningCount = (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM asset_vehicle_details
         WHERE company_id IN (:golden_company_id, :complete_company_id)
           AND (
                COALESCE(tax_review_status, :unreviewed) <> :reviewed
                OR COALESCE(acquisition_condition, :blank_condition) = :blank_condition_match
                OR (vehicle_type = :car_type AND co2_emissions_g_km IS NULL)
           )',
        [
            'golden_company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
            'complete_company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'unreviewed' => 'unreviewed',
            'reviewed' => 'reviewed',
            'blank_condition' => '',
            'blank_condition_match' => '',
            'car_type' => 'car',
        ]
    );

    if ($warningCount === 0) {
        throw new RuntimeException(
            'All ' . $vehicleCount . ' golden vehicle records are reviewed and tax-complete; '
            . 'none exercises missing condition, missing CO2, or unreviewed-warning behaviour.'
        );
    }
});

goldenTaxFixtureIntegrityCheck($harness, 'activates Tax and Year End read-only mode for the LIVE VAT warning company', static function () use ($harness): void {
    $scope = (new \eel_accounts\Service\VatSupportScopeService())
        ->fetchForCompany(GoldenAccountsFixture::WARNING_COMPANY_ID);

    if (empty($scope['tax_year_end_read_only'])) {
        throw new RuntimeException(
            'Warning company 9300 is VAT registered and LIVE validated but did not activate read-only mode; '
            . 'validation source was ' . (string)($scope['validation_source'] ?? '(missing)') . '.'
        );
    }

    $harness->assertFalse((bool)($scope['supported'] ?? true));
});

function goldenTaxFixtureIntegrityCheck(
    GeneratedServiceClassTestHarness $harness,
    string $description,
    callable $callback
): void {
    $harness->check(GoldenAccountsFixture::class, $description, static function () use ($callback): void {
        InterfaceDB::beginTransaction();
        try {
            $callback();
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
}
