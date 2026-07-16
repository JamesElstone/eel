<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(
    \eel_accounts\Service\JournalSourceEvidenceService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\JournalSourceEvidenceService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\JournalSourceEvidenceService::class,
            'does not treat an arbitrary balanced manual reference as verified evidence',
            static function () use ($harness, $service): void {
                $results = $service->verify([
                    [
                        'id' => 1,
                        'source_type' => 'manual',
                        'source_ref' => 'board-approved-adjustment-1',
                        'debit_total' => 100.00,
                        'credit_total' => 100.00,
                    ],
                    [
                        'id' => 2,
                        'source_type' => 'manual',
                        'source_ref' => '',
                        'debit_total' => 100.00,
                        'credit_total' => 100.00,
                    ],
                    [
                        'id' => 3,
                        'source_type' => 'unsupported_source',
                        'source_ref' => 'external-123',
                        'debit_total' => 50.00,
                        'credit_total' => 50.00,
                    ],
                ], 1, 1);

                $harness->assertSame(false, (bool)($results[1]['verified'] ?? true));
                $harness->assertSame(false, (bool)($results[2]['verified'] ?? true));
                $harness->assertSame(false, (bool)($results[3]['verified'] ?? true));
                $harness->assertSame(
                    true,
                    str_contains((string)($results[3]['reason'] ?? ''), 'no supported evidence check')
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\JournalSourceEvidenceService::class,
            'reconciles linked sources but does not treat matching metadata as independent accounting evidence',
            static function () use ($harness, $service): void {
                InterfaceDB::beginTransaction();
                try {
                    StandardNominalTestFixture::ensureNominals(['1000']);
                    $nominalId = StandardNominalTestFixture::id('1000');
                    $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (company_name, company_number, is_active)
                         VALUES (:company_name, :company_number, 1)',
                        [
                            'company_name' => 'Journal evidence fixture ' . $marker,
                            'company_number' => 'JE' . $marker,
                        ]
                    );
                    $companyId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM companies WHERE company_number = :company_number',
                        ['company_number' => 'JE' . $marker]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                         VALUES (:company_id, :label, :period_start, :period_end)',
                        [
                            'company_id' => $companyId,
                            'label' => 'Journal evidence ' . $marker,
                            'period_start' => '2025-01-01',
                            'period_end' => '2025-12-31',
                        ]
                    );
                    $periodId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM accounting_periods WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );

                    InterfaceDB::prepareExecute(
                        'INSERT INTO statement_uploads (
                            company_id, accounting_period_id, statement_month,
                            original_filename, stored_filename, file_sha256, workflow_status
                         ) VALUES (
                            :company_id, :accounting_period_id, :statement_month,
                            :original_filename, :stored_filename, :file_sha256, :workflow_status
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $periodId,
                            'statement_month' => '2025-03-01',
                            'original_filename' => 'journal-evidence.csv',
                            'stored_filename' => 'journal-evidence.csv',
                            'file_sha256' => hash('sha256', $marker),
                            'workflow_status' => 'committed',
                        ]
                    );
                    $uploadId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM statement_uploads WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO transactions (
                            company_id, accounting_period_id, statement_upload_id,
                            txn_date, description, amount, dedupe_hash, category_status
                         ) VALUES (
                            :company_id, :accounting_period_id, :statement_upload_id,
                            :txn_date, :description, :amount, :dedupe_hash, :category_status
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $periodId,
                            'statement_upload_id' => $uploadId,
                            'txn_date' => '2025-03-15',
                            'description' => 'Journal evidence transaction',
                            'amount' => -100.00,
                            'dedupe_hash' => hash('sha256', 'journal-evidence-transaction-' . $marker),
                            'category_status' => 'manual',
                        ]
                    );
                    $transactionId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM transactions WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    $transactionJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        $nominalId,
                        'bank_csv',
                        'transaction:' . $transactionId,
                        '2025-03-15',
                        90.00
                    );

                    $manualSourceRef = 'meta:journal_evidence:' . $periodId . ':primary';
                    $manualJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        $nominalId,
                        'system_generated',
                        $manualSourceRef,
                        '2025-03-31',
                        50.00
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO journal_entry_metadata (
                            journal_id, company_id, accounting_period_id,
                            journal_tag, journal_key, entry_mode
                         ) VALUES (
                            :journal_id, :company_id, :accounting_period_id,
                            :journal_tag, :journal_key, :entry_mode
                         )',
                        [
                            'journal_id' => $manualJournalId,
                            'company_id' => $companyId,
                            'accounting_period_id' => $periodId,
                            'journal_tag' => 'journal_evidence',
                            'journal_key' => 'primary',
                            'entry_mode' => 'system',
                        ]
                    );

                    InterfaceDB::prepareExecute(
                        'INSERT INTO expense_claimants (company_id, claimant_name, is_active)
                         VALUES (:company_id, :claimant_name, 1)',
                        [
                            'company_id' => $companyId,
                            'claimant_name' => 'Journal Evidence Claimant ' . $marker,
                        ]
                    );
                    $claimantId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM expense_claimants WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );
                    $claimReference = 'JE-' . $marker;
                    $claimJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        $nominalId,
                        'expense_register',
                        $claimReference,
                        '2025-04-30',
                        80.00
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO expense_claims (
                            company_id, accounting_period_id, claimant_id,
                            claim_year, claim_month, period_start, period_end,
                            claim_reference_code, claimed_amount, status, posted_journal_id
                         ) VALUES (
                            :company_id, :accounting_period_id, :claimant_id,
                            :claim_year, :claim_month, :period_start, :period_end,
                            :claim_reference_code, :claimed_amount, :status, :posted_journal_id
                         )',
                        [
                            'company_id' => $companyId,
                            'accounting_period_id' => $periodId,
                            'claimant_id' => $claimantId,
                            'claim_year' => 2025,
                            'claim_month' => 4,
                            'period_start' => '2025-04-01',
                            'period_end' => '2025-04-30',
                            'claim_reference_code' => $claimReference,
                            'claimed_amount' => 100.00,
                            'status' => 'posted',
                            'posted_journal_id' => $claimJournalId,
                        ]
                    );

                    $results = $service->verify([
                        [
                            'id' => $transactionJournalId,
                            'source_type' => 'bank_csv',
                            'source_ref' => 'transaction:' . $transactionId,
                            'journal_date' => '2025-03-15',
                            'debit_total' => 90.00,
                            'credit_total' => 90.00,
                        ],
                        [
                            'id' => $manualJournalId,
                            'source_type' => 'system_generated',
                            'source_ref' => $manualSourceRef,
                            'journal_date' => '2025-03-31',
                            'debit_total' => 50.00,
                            'credit_total' => 50.00,
                        ],
                        [
                            'id' => $claimJournalId,
                            'source_type' => 'expense_register',
                            'source_ref' => $claimReference,
                            'journal_date' => '2025-04-30',
                            'debit_total' => 80.00,
                            'credit_total' => 80.00,
                        ],
                    ], $companyId, $periodId);

                    $harness->assertSame(false, (bool)($results[$transactionJournalId]['verified'] ?? true));
                    $harness->assertTrue(str_contains(
                        (string)($results[$transactionJournalId]['reason'] ?? ''),
                        'amount does not reconcile'
                    ));
                    $harness->assertSame(false, (bool)($results[$manualJournalId]['verified'] ?? true));
                    $harness->assertTrue(str_contains(
                        (string)($results[$manualJournalId]['reason'] ?? ''),
                        'no independent content verifier'
                    ));
                    $harness->assertSame(false, (bool)($results[$claimJournalId]['verified'] ?? true));
                    $harness->assertTrue(str_contains(
                        (string)($results[$claimJournalId]['reason'] ?? ''),
                        'amount does not reconcile'
                    ));

                } finally {
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\JournalSourceEvidenceService::class,
            'rejects linked asset journals when their dates or totals do not reconcile',
            static function () use ($harness, $service): void {
                InterfaceDB::beginTransaction();
                try {
                    StandardNominalTestFixture::ensureNominals(['1300', '1330', '6200']);
                    $assetNominalId = StandardNominalTestFixture::id('1300');
                    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);
                    InterfaceDB::prepareExecute(
                        'INSERT INTO companies (company_name, company_number, is_active)
                         VALUES (:company_name, :company_number, 1)',
                        [
                            'company_name' => 'Asset journal evidence fixture ' . $marker,
                            'company_number' => 'JA' . $marker,
                        ]
                    );
                    $companyId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM companies WHERE company_number = :company_number',
                        ['company_number' => 'JA' . $marker]
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                         VALUES (:company_id, :label, :period_start, :period_end)',
                        [
                            'company_id' => $companyId,
                            'label' => 'Asset journal evidence ' . $marker,
                            'period_start' => '2025-01-01',
                            'period_end' => '2025-12-31',
                        ]
                    );
                    $periodId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM accounting_periods WHERE company_id = :company_id',
                        ['company_id' => $companyId]
                    );

                    $purchaseJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        $assetNominalId,
                        'asset_register',
                        'asset:' . $marker . ':opening',
                        '2025-01-01',
                        90.00
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO asset_register (
                            company_id, asset_code, description, category,
                            nominal_account_id, accum_dep_nominal_id, purchase_date,
                            cost, useful_life_years, depreciation_method,
                            residual_value, status, linked_journal_id
                         ) VALUES (
                            :company_id, :asset_code, :description, :category,
                            :nominal_account_id, :accum_dep_nominal_id, :purchase_date,
                            :cost, :useful_life_years, :depreciation_method,
                            :residual_value, :status, :linked_journal_id
                         )',
                        [
                            'company_id' => $companyId,
                            'asset_code' => 'JE-A-' . $marker,
                            'description' => 'Journal evidence active asset',
                            'category' => 'tools_equipment',
                            'nominal_account_id' => $assetNominalId,
                            'accum_dep_nominal_id' => StandardNominalTestFixture::id('1330'),
                            'purchase_date' => '2025-01-01',
                            'cost' => 100.00,
                            'useful_life_years' => 2,
                            'depreciation_method' => 'straight_line',
                            'residual_value' => 0.00,
                            'status' => 'active',
                            'linked_journal_id' => $purchaseJournalId,
                        ]
                    );
                    $activeAssetId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM asset_register WHERE company_id = :company_id AND asset_code = :asset_code',
                        ['company_id' => $companyId, 'asset_code' => 'JE-A-' . $marker]
                    );
                    $depreciationJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        StandardNominalTestFixture::id('6200'),
                        'asset_depreciation',
                        'asset:' . $activeAssetId . ':depreciation:' . $periodId . ':2025-01-01:2025-12-31',
                        '2025-12-31',
                        90.00
                    );
                    InterfaceDB::prepareExecute(
                        'INSERT INTO asset_depreciation_entries (
                            asset_id, accounting_period_id, period_start, period_end, amount, journal_id
                         ) VALUES (
                            :asset_id, :accounting_period_id, :period_start, :period_end, :amount, :journal_id
                         )',
                        [
                            'asset_id' => $activeAssetId,
                            'accounting_period_id' => $periodId,
                            'period_start' => '2025-01-01',
                            'period_end' => '2025-12-31',
                            'amount' => 100.00,
                            'journal_id' => $depreciationJournalId,
                        ]
                    );

                    InterfaceDB::prepareExecute(
                        'INSERT INTO asset_register (
                            company_id, asset_code, description, category,
                            nominal_account_id, accum_dep_nominal_id, purchase_date,
                            cost, useful_life_years, depreciation_method,
                            residual_value, status, disposal_date, disposal_proceeds
                         ) VALUES (
                            :company_id, :asset_code, :description, :category,
                            :nominal_account_id, :accum_dep_nominal_id, :purchase_date,
                            :cost, :useful_life_years, :depreciation_method,
                            :residual_value, :status, :disposal_date, :disposal_proceeds
                         )',
                        [
                            'company_id' => $companyId,
                            'asset_code' => 'JE-D-' . $marker,
                            'description' => 'Journal evidence disposed asset',
                            'category' => 'tools_equipment',
                            'nominal_account_id' => $assetNominalId,
                            'accum_dep_nominal_id' => StandardNominalTestFixture::id('1330'),
                            'purchase_date' => '2025-01-01',
                            'cost' => 100.00,
                            'useful_life_years' => 2,
                            'depreciation_method' => 'none',
                            'residual_value' => 0.00,
                            'status' => 'disposed',
                            'disposal_date' => '2025-09-30',
                            'disposal_proceeds' => 80.00,
                        ]
                    );
                    $disposedAssetId = (int)InterfaceDB::fetchColumn(
                        'SELECT id FROM asset_register WHERE company_id = :company_id AND asset_code = :asset_code',
                        ['company_id' => $companyId, 'asset_code' => 'JE-D-' . $marker]
                    );
                    $disposalJournalId = journalSourceEvidenceTestJournal(
                        $companyId,
                        $periodId,
                        $assetNominalId,
                        'asset_disposal',
                        'asset:' . $disposedAssetId . ':disposal',
                        '2025-09-30',
                        90.00
                    );

                    $results = $service->verify([
                        [
                            'id' => $purchaseJournalId,
                            'source_type' => 'asset_register',
                            'source_ref' => 'asset:' . $marker . ':opening',
                            'journal_date' => '2025-01-01',
                            'debit_total' => 90.00,
                            'credit_total' => 90.00,
                        ],
                        [
                            'id' => $depreciationJournalId,
                            'source_type' => 'asset_depreciation',
                            'source_ref' => 'asset:' . $activeAssetId . ':depreciation:' . $periodId . ':2025-01-01:2025-12-31',
                            'journal_date' => '2025-12-31',
                            'debit_total' => 90.00,
                            'credit_total' => 90.00,
                        ],
                        [
                            'id' => $disposalJournalId,
                            'source_type' => 'asset_disposal',
                            'source_ref' => 'asset:' . $disposedAssetId . ':disposal',
                            'journal_date' => '2025-09-30',
                            'debit_total' => 90.00,
                            'credit_total' => 90.00,
                        ],
                    ], $companyId, $periodId);

                    foreach ([$purchaseJournalId, $depreciationJournalId, $disposalJournalId] as $journalId) {
                        $harness->assertSame(false, (bool)($results[$journalId]['verified'] ?? true));
                        $harness->assertTrue(str_contains(
                            (string)($results[$journalId]['reason'] ?? ''),
                            'does not reconcile'
                        ));
                    }
                } finally {
                    if (InterfaceDB::inTransaction()) {
                        InterfaceDB::rollBack();
                    }
                }
            }
        );
    }
);

function journalSourceEvidenceTestJournal(
    int $companyId,
    int $periodId,
    int $nominalId,
    string $sourceType,
    string $sourceRef,
    string $journalDate,
    float $amount
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => 'Journal source evidence fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id
           AND source_ref = :source_ref
         ORDER BY id DESC
         LIMIT 1',
        ['company_id' => $companyId, 'source_ref' => $sourceRef]
    );
    foreach ([[number_format($amount, 2, '.', ''), '0.00'], ['0.00', number_format($amount, 2, '.', '')]] as [$debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit)
             VALUES (:journal_id, :nominal_account_id, :debit, :credit)',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => $nominalId,
                'debit' => $debit,
                'credit' => $credit,
            ]
        );
    }

    return $journalId;
}
