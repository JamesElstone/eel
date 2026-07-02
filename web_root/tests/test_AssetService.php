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
    \eel_accounts\Service\AssetService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\AssetService $service): void {
        $harness->check(\eel_accounts\Service\AssetService::class, 'fixed asset schema is available', static function () use ($harness, $service): void {
            $harness->assertTrue(InterfaceDB::tableExists('asset_register'));
            $harness->assertTrue(InterfaceDB::tableExists('asset_depreciation_entries'));

            $pageData = $service->fetchPageData(0, 0);
            $harness->assertSame(true, $pageData['schema_ready'] ?? false);
            $harness->assertSame(true, $pageData['manual_schema_ready'] ?? false);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'normalises blank default bank nominal from settings context', static function () use ($harness, $service): void {
            $pageData = $service->fetchPageData(0, 0, '');

            $harness->assertSame(0, $pageData['default_bank_nominal_id'] ?? null);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'tax view uses corporation tax computation and replaces stale asset adjustments', static function () use ($harness, $service): void {
            assetServiceTestRequireTaxViewSchema($harness);
            $fixture = assetServiceTestCreateTaxViewFixture();

            $taxView = $service->fetchTaxView($fixture['company_id'], $fixture['accounting_period_id']);

            $harness->assertSame(1800.0, round((float)($taxView['accounting_profit'] ?? 0), 2));
            $harness->assertSame(200.0, round((float)($taxView['disallowable_add_backs'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['capital_allowances'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['taxable_before_losses'] ?? 0), 2));
            $harness->assertSame(1000.0, round((float)($taxView['taxable_profit'] ?? 0), 2));

            $manualRows = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM accounting_period_adjustments
                 WHERE company_id = :company_id
                   AND type = :type
                   AND source_asset_id IS NULL',
                [
                    'company_id' => $fixture['company_id'],
                    'type' => 'manual_review_marker',
                ]
            );
            $assetAllowance = round((float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(amount), 0)
                 FROM accounting_period_adjustments
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND type = :type
                   AND source_asset_id = :asset_id',
                [
                    'company_id' => $fixture['company_id'],
                    'accounting_period_id' => $fixture['accounting_period_id'],
                    'type' => 'capital_allowances',
                    'asset_id' => $fixture['asset_id'],
                ]
            ), 2);

            $harness->assertSame(1, $manualRows);
            $harness->assertSame(1000.0, $assetAllowance);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'journal source enum supports asset postings', static function () use ($harness): void {
            if (InterfaceDB::driverName() === 'sqlite') {
                $schemaPath = PROJECT_ROOT . 'db_schema' . DIRECTORY_SEPARATOR . 'eel_accounts.schema.sql';
                $columnType = is_file($schemaPath) ? (string)file_get_contents($schemaPath) : '';
            } else {
                $columnType = (string)InterfaceDB::fetchColumn(
                    'SELECT COLUMN_TYPE
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table_name
                       AND COLUMN_NAME = :column_name
                     LIMIT 1',
                    ['table_name' => 'journals', 'column_name' => 'source_type']
                );
            }

            foreach (['asset_register', 'asset_depreciation', 'asset_disposal'] as $sourceType) {
                $harness->assertTrue(str_contains($columnType, "'" . $sourceType . "'"));
            }
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'none depreciation method posts no depreciation', static function () use ($harness, $service): void {
            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'calculateDepreciationAmount');
            $method->setAccessible(true);

            $amount = $method->invoke($service, [
                'id' => 0,
                'depreciation_method' => 'none',
                'cost' => 1200,
                'residual_value' => 100,
                'useful_life_years' => 4,
            ], '2026-01-01', '2026-12-31');

            $harness->assertSame(0.0, $amount);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual asset offset nominal must use a funding subtype', static function () use ($harness, $service): void {
            foreach (['nominal_accounts', 'nominal_account_subtypes'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
                }
            }

            $bankNominalId = assetServiceTestInsertNominal('BNK', 'Bank Offset Candidate', 'asset', 'bank');
            $fixedAssetNominalId = assetServiceTestInsertNominal('FIX', 'Fixed Asset Offset Candidate', 'asset', 'fixed_asset');
            $expenseNominalId = assetServiceTestInsertNominal('EXP', 'Expense Offset Candidate', 'expense', 'overhead');

            $method = new ReflectionMethod(\eel_accounts\Service\AssetService::class, 'isManualAssetOffsetNominal');
            $method->setAccessible(true);

            $harness->assertSame(true, $method->invoke($service, $bankNominalId));
            $harness->assertSame(false, $method->invoke($service, $fixedAssetNominalId));
            $harness->assertSame(false, $method->invoke($service, $expenseNominalId));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual asset creation stores reason and offset nominal', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-store');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');

            $result = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Manual drill ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'delayed_bank_csv',
                ],
                $offsetNominalId
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT manual_addition_reason, manual_offset_nominal_id, linked_transaction_id, linked_journal_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );
            $harness->assertSame('delayed_bank_csv', (string)($asset['manual_addition_reason'] ?? ''));
            $harness->assertSame($offsetNominalId, (int)($asset['manual_offset_nominal_id'] ?? 0));
            $harness->assertSame(0, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertTrue((int)($asset['linked_journal_id'] ?? 0) > 0);
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual reconciliation list excludes opening assets', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-list');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');

            $pending = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Pending supplier asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'supplier_invoice_pending_payment',
                ],
                $offsetNominalId
            );
            $opening = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Opening asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '150.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'opening_or_historical_asset',
                ],
                $offsetNominalId
            );

            $harness->assertSame(true, (bool)($pending['success'] ?? false));
            $harness->assertSame(true, (bool)($opening['success'] ?? false));

            $assets = $service->fetchManualAssetsNeedingReconciliation($fixture['company_id']);
            $ids = array_map(static fn(array $asset): int => (int)$asset['id'], $assets);

            $harness->assertTrue(in_array((int)($pending['asset']['id'] ?? 0), $ids, true));
            $harness->assertSame(false, in_array((int)($opening['asset']['id'] ?? 0), $ids, true));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'manual reconciliation categorises transaction and links asset', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('manual-reconcile');
            $offsetNominalId = assetServiceTestInsertNominal('CLR', 'Manual Asset Clearing', 'liability', 'trade_creditor');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -240.00, 'Manual asset payment');
            $created = $service->createManualAsset(
                $fixture['company_id'],
                $fixture['accounting_period_id'],
                [
                    'description' => 'Reconcilable asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-01',
                    'cost' => '240.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                    'manual_addition_reason' => 'delayed_bank_csv',
                ],
                $offsetNominalId
            );

            $harness->assertSame(true, (bool)($created['success'] ?? false));
            $candidateData = $service->fetchManualAssetReconciliationData($fixture['company_id']);
            $candidateIds = [];
            foreach ((array)($candidateData['assets'] ?? []) as $asset) {
                foreach ((array)($asset['candidates'] ?? []) as $candidate) {
                    $candidateIds[] = (int)($candidate['id'] ?? 0);
                }
            }
            $harness->assertTrue(in_array($transactionId, $candidateIds, true));

            $result = $service->reconcileManualAssetWithTransaction(
                $fixture['company_id'],
                (int)($created['asset']['id'] ?? 0),
                $transactionId,
                $fixture['bank_nominal_id'],
                true
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $transaction = InterfaceDB::fetchOne(
                'SELECT nominal_account_id, category_status
                 FROM transactions
                 WHERE id = :id',
                ['id' => $transactionId]
            );
            $asset = InterfaceDB::fetchOne(
                'SELECT linked_transaction_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($created['asset']['id'] ?? 0)]
            );
            $bankJournalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);

            $harness->assertSame($offsetNominalId, (int)($transaction['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($transaction['category_status'] ?? ''));
            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
            $harness->assertTrue($bankJournalId > 0);
            $harness->assertSame(240.0, assetServiceTestJournalLineAmount($bankJournalId, $offsetNominalId, 'debit'));
            $harness->assertSame(240.0, assetServiceTestJournalLineAmount($bankJournalId, $fixture['bank_nominal_id'], 'credit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'transaction-created assets do not store manual reason', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('transaction-create');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-03', -180.00, 'Imported asset payment');

            $result = $service->createAssetFromTransaction(
                $fixture['company_id'],
                $transactionId,
                [
                    'description' => 'Imported asset ' . $fixture['marker'],
                    'category' => 'tools_equipment',
                    'purchase_date' => '2026-07-03',
                    'cost' => '180.00',
                    'useful_life_years' => '3',
                    'depreciation_method' => 'straight_line',
                    'residual_value' => '0.00',
                ],
                $fixture['bank_nominal_id']
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT manual_addition_reason, manual_offset_nominal_id, linked_transaction_id
                 FROM asset_register
                 WHERE id = :id',
                ['id' => (int)($result['asset']['id'] ?? 0)]
            );

            $harness->assertSame('', (string)($asset['manual_addition_reason'] ?? ''));
            $harness->assertSame(0, (int)($asset['manual_offset_nominal_id'] ?? 0));
            $harness->assertSame($transactionId, (int)($asset['linked_transaction_id'] ?? 0));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'disposal receipt search uses one day before and three days after', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('search');
            $insideBeforeId = assetServiceTestInsertTransaction($fixture, 1, '2026-06-30', 100.00, 'Inside before');
            $insideAfterId = assetServiceTestInsertTransaction($fixture, 2, '2026-07-04', 110.00, 'Inside after');
            assetServiceTestInsertTransaction($fixture, 3, '2026-06-29', 120.00, 'Too early');
            assetServiceTestInsertTransaction($fixture, 4, '2026-07-05', 130.00, 'Too late');
            assetServiceTestInsertTransaction($fixture, 5, '2026-07-01', -50.00, 'Outgoing');
            assetServiceTestInsertTransaction($fixture, 6, '2026-07-01', 60.00, 'Transfer', 1);
            $linkedTransactionId = assetServiceTestInsertTransaction($fixture, 7, '2026-07-01', 70.00, 'Already linked');
            InterfaceDB::prepareExecute(
                'INSERT INTO asset_disposal_transaction_links (asset_id, transaction_id, linked_amount)
                 VALUES (:asset_id, :transaction_id, :linked_amount)',
                [
                    'asset_id' => $fixture['asset_id'],
                    'transaction_id' => $linkedTransactionId,
                    'linked_amount' => 70.00,
                ]
            );

            $search = $service->fetchDisposalSearch($fixture['company_id'], '2026-07-01', $fixture['asset_id']);
            $ids = array_map(static fn(array $row): int => (int)$row['id'], (array)($search['candidates'] ?? []));

            $harness->assertSame('2026-06-30', (string)($search['window_start'] ?? ''));
            $harness->assertSame('2026-07-04', (string)($search['window_end'] ?? ''));
            $harness->assertTrue(in_array($insideBeforeId, $ids, true));
            $harness->assertTrue(in_array($insideAfterId, $ids, true));
            $harness->assertSame(2, count($ids));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'linked disposal uses receipt transaction date and amount', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('linked');
            $transactionId = assetServiceTestInsertTransaction($fixture, 1, '2026-07-02', 300.00, 'Asset sale receipt');

            $result = $service->disposeAssetWithTransaction($fixture['company_id'], $fixture['asset_id'], $transactionId, $fixture['bank_nominal_id']);

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT status, disposal_date, disposal_proceeds
                 FROM asset_register
                 WHERE id = :id',
                ['id' => $fixture['asset_id']]
            );
            $harness->assertSame('disposed', (string)($asset['status'] ?? ''));
            $harness->assertSame('2026-07-02', (string)($asset['disposal_date'] ?? ''));
            $harness->assertSame(300.0, round((float)($asset['disposal_proceeds'] ?? 0), 2));

            $linkCount = (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM asset_disposal_transaction_links
                 WHERE asset_id = :asset_id
                   AND transaction_id = :transaction_id',
                ['asset_id' => $fixture['asset_id'], 'transaction_id' => $transactionId]
            );
            $harness->assertSame(1, $linkCount);

            $clearingNominalId = assetServiceTestNominalId('1490');
            $bankJournalId = assetServiceTestJournalId($fixture['company_id'], 'bank_csv', 'transaction:' . $transactionId);
            $assetJournalId = assetServiceTestJournalId($fixture['company_id'], 'asset_disposal', 'asset:' . $fixture['asset_id'] . ':disposal');
            $harness->assertTrue($bankJournalId > 0);
            $harness->assertTrue($assetJournalId > 0);
            $harness->assertSame(300.0, assetServiceTestJournalLineAmount($bankJournalId, $clearingNominalId, 'credit'));
            $harness->assertSame(300.0, assetServiceTestJournalLineAmount($assetJournalId, $clearingNominalId, 'debit'));
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, $fixture['bank_nominal_id'], 'debit'));
        });

        $harness->check(\eel_accounts\Service\AssetService::class, 'nil disposal posts without transaction link', static function () use ($harness, $service): void {
            assetServiceTestRequireDisposalSchema($harness);
            $fixture = assetServiceTestCreateDisposalFixture('nil');

            $result = $service->disposeAssetAtNilValue($fixture['company_id'], $fixture['asset_id'], '2026-07-03');

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $asset = InterfaceDB::fetchOne(
                'SELECT status, disposal_date, disposal_proceeds
                 FROM asset_register
                 WHERE id = :id',
                ['id' => $fixture['asset_id']]
            );
            $harness->assertSame('disposed', (string)($asset['status'] ?? ''));
            $harness->assertSame('2026-07-03', (string)($asset['disposal_date'] ?? ''));
            $harness->assertSame(0.0, round((float)($asset['disposal_proceeds'] ?? 0), 2));
            $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM asset_disposal_transaction_links
                 WHERE asset_id = :asset_id',
                ['asset_id' => $fixture['asset_id']]
            ));

            $assetJournalId = assetServiceTestJournalId($fixture['company_id'], 'asset_disposal', 'asset:' . $fixture['asset_id'] . ':disposal');
            $harness->assertTrue($assetJournalId > 0);
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, assetServiceTestNominalId('1490'), 'debit'));
            $harness->assertSame(0.0, assetServiceTestJournalLineAmount($assetJournalId, $fixture['bank_nominal_id'], 'debit'));
        });
    }
);

function assetServiceTestInsertNominal(string $prefix, string $name, string $accountType, string $subtypeCode = ''): int
{
    $code = $prefix . strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));
    $subtypeId = null;
    if ($subtypeCode !== '') {
        $subtypeId = assetServiceTestSubtypeId($subtypeCode);
    }

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :account_subtype_id, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name . ' ' . $code,
            'account_type' => $accountType,
            'account_subtype_id' => $subtypeId,
            'tax_treatment' => 'other',
            'sort_order' => 9900,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestSubtypeId(string $code): int
{
    $id = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
    if ($id <= 0) {
        throw new RuntimeException('Nominal subtype ' . $code . ' is not available.');
    }

    return $id;
}

function assetServiceTestInsertNominalWithTreatment(string $prefix, string $name, string $accountType, string $taxTreatment): int
{
    $code = $prefix . strtoupper(substr(str_replace('.', '', uniqid('', true)), -5));
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name . ' ' . $code,
            'account_type' => $accountType,
            'tax_treatment' => $taxTreatment,
            'sort_order' => 9900,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestRequireTaxViewSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['companies', 'accounting_periods', 'nominal_accounts', 'journals', 'journal_lines', 'asset_register', 'asset_depreciation_entries', 'asset_disposal_transaction_links', 'accounting_period_adjustments', 'tax_loss_carryforwards'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

function assetServiceTestCreateTaxViewFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('91' . $marker);
    $accountingPeriodId = (int)('92' . $marker);
    $assetId = (int)('93' . $marker);
    $incomeNominalId = assetServiceTestInsertNominalWithTreatment('ATI', 'Asset Tax Income', 'income', 'allowable');
    $disallowableNominalId = assetServiceTestInsertNominalWithTreatment('ATD', 'Asset Tax Disallowable', 'expense', 'disallowable');
    $assetNominalId = assetServiceTestInsertNominalWithTreatment('ATA', 'Asset Tax Asset', 'asset', 'capital');
    $accumNominalId = assetServiceTestInsertNominalWithTreatment('ATC', 'Asset Tax Accumulated Depreciation', 'asset', 'capital');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Asset Tax Fixture ' . $marker,
            'company_number' => 'AT' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'Asset Tax FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id,
            accounting_period_id,
            source_type,
            source_ref,
            journal_date,
            description,
            is_posted
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :source_type,
            :source_ref,
            :journal_date,
            :description,
            1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'manual',
            'source_ref' => 'asset-tax-fixture-' . $marker,
            'journal_date' => '2026-12-31',
            'description' => 'Asset tax fixture ' . $marker,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_ref' => 'asset-tax-fixture-' . $marker,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 0.00, 2000.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $incomeNominalId,
            'line_description' => 'Fixture income',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, 200.00, 0.00, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $disallowableNominalId,
            'line_description' => 'Fixture disallowable expense',
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
            'asset_code' => 'AT-FIX-' . $marker,
            'description' => 'Asset tax fixture asset ' . $marker,
            'category' => 'tools_equipment',
            'nominal_account_id' => $assetNominalId,
            'accum_dep_nominal_id' => $accumNominalId,
            'purchase_date' => '2026-02-01',
            'cost' => 1000.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'none',
            'residual_value' => 0.00,
            'status' => 'active',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_period_adjustments (
            company_id,
            accounting_period_id,
            type,
            direction,
            amount,
            source_asset_id
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :type,
            :direction,
            :amount,
            :source_asset_id
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'type' => 'capital_allowances',
            'direction' => 'deduct',
            'amount' => 999.00,
            'source_asset_id' => $assetId,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_period_adjustments (
            company_id,
            accounting_period_id,
            type,
            direction,
            amount,
            source_asset_id
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :type,
            :direction,
            :amount,
            NULL
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'type' => 'manual_review_marker',
            'direction' => 'add',
            'amount' => 123.00,
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'asset_id' => $assetId,
    ];
}

function assetServiceTestRequireDisposalSchema(GeneratedServiceClassTestHarness $harness): void
{
    foreach (['asset_register', 'asset_disposal_transaction_links', 'transactions', 'journals', 'journal_lines', 'nominal_account_subtypes'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }

    foreach (['manual_addition_reason', 'manual_offset_nominal_id'] as $column) {
        if (!InterfaceDB::columnExists('asset_register', $column)) {
            $harness->skip('asset_register.' . $column . ' column is not available.');
        }
    }

    foreach (['1000', '1300', '1330', '1490', '4200', '6210'] as $code) {
        if (assetServiceTestNominalId($code) <= 0) {
            $harness->skip('Nominal ' . $code . ' is not available.');
        }
    }

    foreach (['trade_creditor'] as $code) {
        if ((int)InterfaceDB::fetchColumn('SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1', ['code' => $code]) <= 0) {
            $harness->skip('Nominal subtype ' . $code . ' is not available.');
        }
    }
}

function assetServiceTestCreateDisposalFixture(string $suffix): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('81' . $marker);
    $accountingPeriodId = (int)('82' . $marker);
    $accountId = (int)('83' . $marker);
    $uploadId = (int)('84' . $marker);
    $assetId = (int)('85' . $marker);
    $bankNominalId = assetServiceTestNominalId('1000');

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Asset Disposal ' . $suffix . ' ' . $marker,
            'company_number' => 'AD' . substr($marker, 0, 6),
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
        'INSERT INTO company_accounts (id, company_id, account_name, account_type, nominal_account_id, is_active)
         VALUES (:id, :company_id, :account_name, :account_type, :nominal_account_id, 1)',
        [
            'id' => $accountId,
            'company_id' => $companyId,
            'account_name' => 'Test Bank ' . $marker,
            'account_type' => 'bank',
            'nominal_account_id' => $bankNominalId,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            id,
            company_id,
            accounting_period_id,
            account_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :account_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256
         )',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'account_id' => $accountId,
            'statement_month' => '2026-07-01',
            'original_filename' => 'asset-disposal-' . $marker . '.csv',
            'stored_filename' => 'asset-disposal-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'asset-disposal-upload-' . $marker),
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
            'asset_code' => 'FA-T-' . $marker,
            'description' => 'Disposal fixture asset ' . $marker,
            'category' => 'tools_equipment',
            'nominal_account_id' => assetServiceTestNominalId('1300'),
            'accum_dep_nominal_id' => assetServiceTestNominalId('1330'),
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
        'account_id' => $accountId,
        'upload_id' => $uploadId,
        'asset_id' => $assetId,
        'bank_nominal_id' => $bankNominalId,
        'marker' => $marker,
    ];
}

function assetServiceTestInsertTransaction(array $fixture, int $offset, string $date, float $amount, string $description, int $isInternalTransfer = 0): int
{
    $transactionId = (int)('86' . substr((string)$fixture['marker'], 0, 5) . $offset);
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            account_id,
            txn_date,
            description,
            amount,
            currency,
            dedupe_hash,
            is_internal_transfer,
            category_status
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :account_id,
            :txn_date,
            :description,
            :amount,
            :currency,
            :dedupe_hash,
            :is_internal_transfer,
            :category_status
         )',
        [
            'id' => $transactionId,
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'statement_upload_id' => $fixture['upload_id'],
            'account_id' => $fixture['account_id'],
            'txn_date' => $date,
            'description' => $description . ' ' . $fixture['marker'] . ' ' . $offset,
            'amount' => $amount,
            'currency' => 'GBP',
            'dedupe_hash' => hash('sha256', 'asset-disposal-transaction-' . $fixture['marker'] . '-' . $offset),
            'is_internal_transfer' => $isInternalTransfer,
            'category_status' => 'uncategorised',
        ]
    );

    return $transactionId;
}

function assetServiceTestNominalId(string $code): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM nominal_accounts
         WHERE code = :code
         LIMIT 1',
        ['code' => $code]
    );
}

function assetServiceTestJournalId(int $companyId, string $sourceType, string $sourceRef): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_type = :source_type
           AND source_ref = :source_ref
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
        ]
    );
}

function assetServiceTestJournalLineAmount(int $journalId, int $nominalAccountId, string $side): float
{
    $side = $side === 'credit' ? 'credit' : 'debit';

    return round((float)InterfaceDB::fetchColumn(
        'SELECT COALESCE(SUM(' . $side . '), 0)
         FROM journal_lines
         WHERE journal_id = :journal_id
           AND nominal_account_id = :nominal_account_id',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalAccountId,
        ]
    ), 2);
}
