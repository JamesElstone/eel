<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AssetAction::class, static function (GeneratedServiceClassTestHarness $harness, AssetAction $action): void {
    $harness->check(AssetAction::class, 'implements the action interface', static function () use ($harness, $action): void {
        $harness->assertSame(true, $action instanceof ActionInterfaceFramework);
    });

    $harness->check(AssetAction::class, 'search action preserves disposal search context', static function () use ($harness, $action): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'intent' => 'search_asset_disposal_receipts',
                'company_id' => '49',
                'asset_id' => '12',
                'disposal_search_date' => '2026-07-01',
                'asset_disposal_method_asset_id' => '12',
                'asset_disposal_method' => 'sell_asset',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(['asset.create', 'asset.reconcile_manual', 'asset.register', 'asset.tax', 'asset.not_an_asset', 'expense.claim.editor', 'expenses.state', 'transactions.imported', 'page.context', 'year.end.checklist'], $result->changedFacts());
        $harness->assertSame('2026-07-01', (string)($result->context()['asset_disposal_search_date'] ?? ''));
        $harness->assertSame(12, (int)($result->context()['asset_disposal_search_asset_id'] ?? 0));
        $harness->assertSame(12, (int)($result->context()['asset_disposal_method_asset_id'] ?? 0));
        $harness->assertSame('sell_asset', (string)($result->context()['asset_disposal_method'] ?? ''));
    });

    $harness->check(AssetAction::class, 'method toggle preserves disposal method context without search state', static function () use ($harness, $action): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'intent' => 'set_asset_disposal_method',
                'company_id' => '49',
                'asset_id' => '12',
                'asset_disposal_method_asset_id' => '12',
                'asset_disposal_method' => 'at_nil_value',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame(12, (int)($result->context()['asset_disposal_method_asset_id'] ?? 0));
        $harness->assertSame('at_nil_value', (string)($result->context()['asset_disposal_method'] ?? ''));
        $harness->assertSame(false, array_key_exists('asset_disposal_search_date', $result->context()));
        $harness->assertSame(false, array_key_exists('asset_disposal_search_asset_id', $result->context()));
    });

    $harness->check(AssetAction::class, 'empty disposal form submission preserves method context without error', static function () use ($harness, $action): void {
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'company_id' => '49',
                'asset_id' => '12',
                'asset_disposal_method_asset_id' => '12',
                'asset_disposal_method' => 'at_nil_value',
                'disposal_event_type' => 'stolen_no_compensation',
                'disposal_reason' => '',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame([], $result->flashMessages());
        $harness->assertSame(12, (int)($result->context()['asset_disposal_method_asset_id'] ?? 0));
        $harness->assertSame('at_nil_value', (string)($result->context()['asset_disposal_method'] ?? ''));
        $harness->assertSame(false, array_key_exists('asset_disposal_search_date', $result->context()));
        $harness->assertSame(false, array_key_exists('asset_disposal_search_asset_id', $result->context()));
    });

    $harness->check(AssetAction::class, 'potential asset threshold changes require an unlocked accounting period', static function () use ($harness, $action): void {
        if (!InterfaceDB::tableExists('year_end_reviews')) {
            $harness->skip('year_end_reviews table is not available.');
        }

        $companyId = random_int(700000000, 799999999);
        $accountingPeriodId = random_int(800000000, 899999999);
        InterfaceDB::beginTransaction();
        try {
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'locked_by' => 'asset_action_test',
                ]
            );

            $request = new RequestFramework(
                [],
                [
                    'card_action' => 'Asset',
                    'intent' => 'save_potential_asset_threshold',
                    'company_id' => (string)$companyId,
                    'accounting_period_id' => (string)$accountingPeriodId,
                    'potential_asset_threshold' => '500',
                ],
                ['REQUEST_METHOD' => 'POST'],
                [],
                [],
                null
            );

            $result = $action->handle($request, createTestPageServiceFramework());
            $harness->assertSame(false, $result->isSuccess());
            $harness->assertTrue(str_contains(
                (string)(($result->flashMessages()[0] ?? [])['message'] ?? ''),
                'locked'
            ));
        } finally {
            InterfaceDB::rollBack();
        }

        $missingPeriodRequest = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'intent' => 'save_potential_asset_threshold',
                'company_id' => (string)$companyId,
                'potential_asset_threshold' => '500',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );
        $missingPeriodResult = $action->handle($missingPeriodRequest, createTestPageServiceFramework());
        $harness->assertSame(false, $missingPeriodResult->isSuccess());
        $harness->assertTrue(str_contains(
            (string)(($missingPeriodResult->flashMessages()[0] ?? [])['message'] ?? ''),
            'Select a company and accounting period'
        ));
    });

    $harness->check(AssetAction::class, 'nil disposal action posts stolen metadata in SQLite fixture', static function () use ($harness, $action): void {
        assetActionTestRequireDisposalSchema($harness);
        $fixture = assetActionTestCreateNilDisposalFixture();
        $request = new RequestFramework(
            [],
            [
                'card_action' => 'Asset',
                'intent' => 'dispose_asset_nil',
                'company_id' => (string)$fixture['company_id'],
                'accounting_period_id' => (string)$fixture['accounting_period_id'],
                'asset_id' => (string)$fixture['asset_id'],
                'asset_disposal_method_asset_id' => (string)$fixture['asset_id'],
                'asset_disposal_method' => 'at_nil_value',
                'disposal_date' => '2026-07-03',
                'disposal_event_type' => 'stolen_no_compensation',
                'disposal_reason' => '',
            ],
            ['REQUEST_METHOD' => 'POST'],
            [],
            [],
            null
        );

        $result = $action->handle($request, createTestPageServiceFramework());

        $harness->assertSame(true, $result->isSuccess());
        $asset = InterfaceDB::fetchOne(
            'SELECT status, disposal_date, disposal_proceeds, disposal_event_type, disposal_reason
             FROM asset_register
             WHERE id = :id',
            ['id' => $fixture['asset_id']]
        );
        $harness->assertSame('disposed', (string)($asset['status'] ?? ''));
        $harness->assertSame('2026-07-03', (string)($asset['disposal_date'] ?? ''));
        $harness->assertSame(0.0, round((float)($asset['disposal_proceeds'] ?? 0), 2));
        $harness->assertSame('stolen_no_compensation', (string)($asset['disposal_event_type'] ?? ''));
        $harness->assertSame('Stolen', (string)($asset['disposal_reason'] ?? ''));
        $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM asset_disposal_transaction_links
             WHERE asset_id = :asset_id',
            ['asset_id' => $fixture['asset_id']]
        ));
    });
});

function assetActionTestRequireDisposalSchema(GeneratedServiceClassTestHarness $harness): void
{
    if (InterfaceDB::driverName() !== 'sqlite') {
        $harness->skip('AssetAction disposal proof must run against the SQLite test fixture.');
    }

    foreach (['companies', 'accounting_periods', 'asset_register', 'asset_depreciation_entries', 'asset_disposal_transaction_links', 'journals', 'journal_lines', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['disposal_event_type', 'disposal_reason'] as $column) {
        if (!InterfaceDB::columnExists('asset_register', $column)) {
            $harness->skip('asset_register.' . $column . ' column is not available.');
        }
    }

    assetActionTestEnsureNominalId('1300', 'Fixture Plant and Machinery', 'asset', 'capital');
    assetActionTestEnsureNominalId('1330', 'Fixture Accumulated Depreciation', 'asset', 'capital');
    assetActionTestEnsureNominalId('6210', 'Fixture Asset Disposal Loss', 'expense', 'allowable');
}

function assetActionTestCreateNilDisposalFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('91' . $marker);
    $accountingPeriodId = (int)('92' . $marker);
    $assetId = (int)('93' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Asset Action Nil Disposal ' . $marker,
            'company_number' => 'AA' . substr($marker, 0, 6),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO asset_register (
            id,
            company_id,
            asset_code,
            description,
            category,
            nominal_account_id,
            accum_dep_nominal_id,
            purchase_date,
            cost,
            useful_life_years,
            depreciation_method,
            residual_value,
            status
         ) VALUES (
            :id,
            :company_id,
            :asset_code,
            :description,
            :category,
            :nominal_account_id,
            :accum_dep_nominal_id,
            :purchase_date,
            :cost,
            :useful_life_years,
            :depreciation_method,
            :residual_value,
            :status
         )',
        [
            'id' => $assetId,
            'company_id' => $companyId,
            'asset_code' => 'FA-A-' . $marker,
            'description' => 'Asset action nil disposal fixture ' . $marker,
            'category' => 'tools_equipment',
            'nominal_account_id' => assetActionTestNominalId('1300'),
            'accum_dep_nominal_id' => assetActionTestNominalId('1330'),
            'purchase_date' => '2026-01-10',
            'cost' => 1000.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'none',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function assetActionTestNominalId(string $code): int
{
    return (int)(InterfaceDB::fetchColumn(
        'SELECT id
         FROM nominal_accounts
         WHERE code = :code
         LIMIT 1',
        ['code' => $code]
    ) ?: 0);
}

function assetActionTestEnsureNominalId(string $code, string $name, string $accountType, string $taxTreatment): int
{
    $id = assetActionTestNominalId($code);
    if ($id > 0) {
        return $id;
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
            'sort_order' => (int)$code,
        ]
    );

    return assetActionTestNominalId($code);
}
