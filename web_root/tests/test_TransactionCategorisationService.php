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

$harness->run(\eel_accounts\Service\TransactionCategorisationService::class, function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\TransactionCategorisationService $service
): void {
    $createCompanyWithNominal = static function (string $marker): array {
        $companyId = (int)('71' . $marker);
        $nominalAccountId = (int)('72' . $marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Reference Rule Test ' . $marker,
                'company_number' => 'RR' . $marker,
            ]
        );
        \InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
             VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
            [
                'id' => $nominalAccountId,
                'code' => 'R' . substr($marker, 0, 4),
                'name' => 'Reference Rule Nominal ' . $marker,
                'account_type' => 'expense',
                'tax_treatment' => 'allowable',
            ]
        );

        return [$companyId, $nominalAccountId];
    };

    $harness->check(\eel_accounts\Service\TransactionCategorisationService::class, 'keeps migrated description-only rules matching without reference', function () use ($harness, $service, $createCompanyWithNominal): void {
        $marker = (string)random_int(100000, 999999);
        [$companyId, $nominalAccountId] = $createCompanyWithNominal($marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (
                company_id,
                priority,
                match_field,
                desc_match_type,
                desc_match_value,
                nominal_account_id,
                is_active
             ) VALUES (
                :company_id,
                100,
                :match_field,
                :desc_match_type,
                :desc_match_value,
                :nominal_account_id,
                1
             )',
            [
                'company_id' => $companyId,
                'match_field' => 'description',
                'desc_match_type' => 'contains',
                'desc_match_value' => 'Acme Electrical',
                'nominal_account_id' => $nominalAccountId,
            ]
        );

        $rule = $service->findMatchingRule($companyId, [
            'description' => 'CARD PAYMENT ACME ELECTRICAL 1234',
            'reference' => 'UNRELATED-REF',
            'source_category' => 'Materials',
            'source_account_label' => 'Main account',
        ]);

        $harness->assertSame(true, is_array($rule));
        $harness->assertSame('none', (string)($rule['ref_match_type'] ?? 'none'));
        $harness->assertSame($nominalAccountId, (int)($rule['nominal_account_id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\TransactionCategorisationService::class, 'keeps legacy non-description match fields working after column rename', function () use ($harness, $service, $createCompanyWithNominal): void {
        $marker = (string)random_int(100000, 999999);
        [$companyId, $nominalAccountId] = $createCompanyWithNominal($marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (
                company_id,
                priority,
                match_field,
                desc_match_type,
                desc_match_value,
                nominal_account_id,
                is_active
             ) VALUES (
                :company_id,
                100,
                :match_field,
                :desc_match_type,
                :desc_match_value,
                :nominal_account_id,
                1
             )',
            [
                'company_id' => $companyId,
                'match_field' => 'type',
                'desc_match_type' => 'equals',
                'desc_match_value' => 'P2P',
                'nominal_account_id' => $nominalAccountId,
            ]
        );

        $rule = $service->findMatchingRule($companyId, [
            'description' => 'Transfer between pots',
            'txn_type' => 'P2P',
            'reference' => 'UNRELATED-REF',
        ]);

        $harness->assertSame(true, is_array($rule));
        $harness->assertSame('type', (string)($rule['match_field'] ?? ''));
        $harness->assertSame($nominalAccountId, (int)($rule['nominal_account_id'] ?? 0));
    });

    $harness->check(\eel_accounts\Service\TransactionCategorisationService::class, 'requires description and reference when reference matching is enabled', function () use ($harness, $service, $createCompanyWithNominal): void {
        $marker = (string)random_int(100000, 999999);
        [$companyId, $nominalAccountId] = $createCompanyWithNominal($marker);

        \InterfaceDB::prepareExecute(
            'INSERT INTO categorisation_rules (
                company_id,
                priority,
                match_field,
                desc_match_type,
                desc_match_value,
                ref_match_type,
                ref_match_value,
                nominal_account_id,
                is_active
             ) VALUES (
                :company_id,
                100,
                :match_field,
                :desc_match_type,
                :desc_match_value,
                :ref_match_type,
                :ref_match_value,
                :nominal_account_id,
                1
             )',
            [
                'company_id' => $companyId,
                'match_field' => 'description',
                'desc_match_type' => 'contains',
                'desc_match_value' => 'Acme Electrical',
                'ref_match_type' => 'contains',
                'ref_match_value' => 'INV-42',
                'nominal_account_id' => $nominalAccountId,
            ]
        );

        $harness->assertSame(null, $service->findMatchingRule($companyId, [
            'description' => 'CARD PAYMENT ACME ELECTRICAL',
            'reference' => 'PO-99',
        ]));
        $harness->assertSame(null, $service->findMatchingRule($companyId, [
            'description' => 'CARD PAYMENT OTHER SUPPLIER',
            'reference' => 'INV-42',
        ]));

        $rule = $service->findMatchingRule($companyId, [
            'description' => 'CARD PAYMENT ACME ELECTRICAL',
            'reference' => 'BACS INV-42',
        ]);

        $harness->assertSame(true, is_array($rule));
        $harness->assertSame('contains', (string)($rule['ref_match_type'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\TransactionCategorisationService::class, 'supports each reference match type', function () use ($harness, $service, $createCompanyWithNominal): void {
        foreach ([
            'contains' => ['INV-42', 'PAYMENT INV-42 RECEIVED'],
            'equals' => ['INV-42', 'INV-42'],
            'starts_with' => ['INV-', 'INV-42'],
        ] as $refMatchType => [$refMatchValue, $reference]) {
            $marker = (string)random_int(100000, 999999);
            [$companyId, $nominalAccountId] = $createCompanyWithNominal($marker);

            \InterfaceDB::prepareExecute(
                'INSERT INTO categorisation_rules (
                    company_id,
                    priority,
                    match_field,
                    desc_match_type,
                    desc_match_value,
                    ref_match_type,
                    ref_match_value,
                    nominal_account_id,
                    is_active
                 ) VALUES (
                    :company_id,
                    100,
                    :match_field,
                    :desc_match_type,
                    :desc_match_value,
                    :ref_match_type,
                    :ref_match_value,
                    :nominal_account_id,
                    1
                 )',
                [
                    'company_id' => $companyId,
                    'match_field' => 'description',
                    'desc_match_type' => 'contains',
                    'desc_match_value' => 'Acme Electrical',
                    'ref_match_type' => $refMatchType,
                    'ref_match_value' => $refMatchValue,
                    'nominal_account_id' => $nominalAccountId,
                ]
            );

            $rule = $service->findMatchingRule($companyId, [
                'description' => 'ACME ELECTRICAL',
                'reference' => $reference,
            ]);

            $harness->assertSame(true, is_array($rule));
            $harness->assertSame($refMatchType, (string)($rule['ref_match_type'] ?? ''));
        }
    });

    $harness->check(\eel_accounts\Service\TransactionCategorisationService::class, 'approves auto categorisations as manual review without changing nominal', function () use ($harness, $service): void {
        foreach (['companies', 'accounting_periods', 'statement_uploads', 'transactions', 'transaction_category_audit', 'nominal_accounts'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $fixture = transactionCategorisationServiceCreateAutoApprovalFixture();
            $result = $service->approveAutoCategorisationsBatch(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                '2026-03-01',
                'test'
            );

            $row = InterfaceDB::fetchOne(
                'SELECT nominal_account_id, category_status, auto_rule_id
                 FROM transactions
                 WHERE id = :id',
                ['id' => (int)$fixture['transaction_id']]
            );

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame(1, (int)($result['changed'] ?? 0));
            $harness->assertSame((int)$fixture['nominal_account_id'], (int)($row['nominal_account_id'] ?? 0));
            $harness->assertSame('manual', (string)($row['category_status'] ?? ''));
            $harness->assertSame(null, $row['auto_rule_id'] ?? null);
            $harness->assertSame(1, InterfaceDB::countWhere('transaction_category_audit', [
                'transaction_id' => (int)$fixture['transaction_id'],
                'old_category_status' => 'auto',
                'new_category_status' => 'manual',
            ]));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});

function transactionCategorisationServiceCreateAutoApprovalFixture(): array
{
    $marker = (string)random_int(100000, 999999);
    $companyId = (int)('81' . $marker);
    $accountingPeriodId = (int)('82' . $marker);
    $uploadId = (int)('83' . $marker);
    $transactionId = (int)('84' . $marker);
    $nominalAccountId = (int)('85' . $marker);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $companyId,
            'company_name' => 'Auto Approval Fixture ' . $marker,
            'company_number' => 'AAF' . substr($marker, 0, 5),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $accountingPeriodId,
            'company_id' => $companyId,
            'label' => 'AAF FY ' . $marker,
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 100)',
        [
            'id' => $nominalAccountId,
            'code' => 'AA' . substr($marker, 0, 4),
            'name' => 'Auto Approval Nominal ' . $marker,
            'account_type' => 'expense',
            'tax_treatment' => 'allowable',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (id, company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256)
         VALUES (:id, :company_id, :accounting_period_id, :statement_month, :original_filename, :stored_filename, :file_sha256)',
        [
            'id' => $uploadId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_month' => '2026-03-01',
            'original_filename' => 'auto-approval-' . $marker . '.csv',
            'stored_filename' => 'auto-approval-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'auto-approval-upload-' . $marker),
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            id,
            company_id,
            accounting_period_id,
            statement_upload_id,
            txn_date,
            description,
            reference,
            amount,
            source_account_label,
            source_category,
            dedupe_hash,
            nominal_account_id,
            category_status,
            auto_rule_id
         ) VALUES (
            :id,
            :company_id,
            :accounting_period_id,
            :statement_upload_id,
            :txn_date,
            :description,
            :reference,
            :amount,
            :source_account_label,
            :source_category,
            :dedupe_hash,
            :nominal_account_id,
            :category_status,
            :auto_rule_id
         )',
        [
            'id' => $transactionId,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2026-03-15',
            'description' => 'AUTO APPROVAL TEST ' . $marker,
            'reference' => 'AA-' . $marker,
            'amount' => '-42.50',
            'source_account_label' => 'Main account',
            'source_category' => 'Materials',
            'dedupe_hash' => hash('sha256', 'auto-approval-transaction-' . $marker),
            'nominal_account_id' => $nominalAccountId,
            'category_status' => 'auto',
            'auto_rule_id' => null,
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'transaction_id' => $transactionId,
        'nominal_account_id' => $nominalAccountId,
    ];
}
