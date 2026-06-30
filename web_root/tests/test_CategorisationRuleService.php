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

$harness->run(\eel_accounts\Service\CategorisationRuleService::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\CategorisationRuleService $service): void {
    $harness->check(\eel_accounts\Service\CategorisationRuleService::class, 'fetches distinct source category and account options', function () use ($harness, $service): void {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('81' . $marker);
        $otherCompanyId = (int)('82' . $marker);
        $accountingPeriodId = (int)('83' . $marker);
        $otherAccountingPeriodId = (int)('84' . $marker);
        $uploadId = (int)('85' . $marker);
        $otherUploadId = (int)('86' . $marker);

        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Rule Options Test ' . $marker,
                'company_number' => 'RO' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $otherCompanyId,
                'company_name' => 'Rule Options Other ' . $marker,
                'company_number' => 'RX' . $marker,
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
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $otherAccountingPeriodId,
                'company_id' => $otherCompanyId,
                'label' => 'FY Other ' . $marker,
                'period_start' => '2026-01-01',
                'period_end' => '2026-12-31',
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                id,
                company_id,
                accounting_period_id,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256
             )',
            [
                'id' => $uploadId,
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'statement_month' => '2026-03-01',
                'original_filename' => 'rule-options-' . $marker . '.csv',
                'stored_filename' => 'rule-options-' . $marker . '.csv',
                'file_sha256' => hash('sha256', 'rule-options-upload-' . $marker),
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO statement_uploads (
                id,
                company_id,
                accounting_period_id,
                statement_month,
                original_filename,
                stored_filename,
                file_sha256
             ) VALUES (
                :id,
                :company_id,
                :accounting_period_id,
                :statement_month,
                :original_filename,
                :stored_filename,
                :file_sha256
             )',
            [
                'id' => $otherUploadId,
                'company_id' => $otherCompanyId,
                'accounting_period_id' => $otherAccountingPeriodId,
                'statement_month' => '2026-03-01',
                'original_filename' => 'rule-options-other-' . $marker . '.csv',
                'stored_filename' => 'rule-options-other-' . $marker . '.csv',
                'file_sha256' => hash('sha256', 'rule-options-other-upload-' . $marker),
            ]
        );

        $insertTransaction = static function (
            int $id,
            int $fixtureCompanyId,
            int $fixtureAccountingPeriodId,
            int $fixtureUploadId,
            string $description,
            string $category,
            string $account
        ) use ($marker): void {
            InterfaceDB::prepareExecute(
                'INSERT INTO transactions (
                    id,
                    company_id,
                    accounting_period_id,
                    statement_upload_id,
                    txn_date,
                    description,
                    amount,
                    source_account_label,
                    source_category,
                    dedupe_hash
                 ) VALUES (
                    :id,
                    :company_id,
                    :accounting_period_id,
                    :statement_upload_id,
                    :txn_date,
                    :description,
                    :amount,
                    :source_account_label,
                    :source_category,
                    :dedupe_hash
                 )',
                [
                    'id' => $id,
                    'company_id' => $fixtureCompanyId,
                    'accounting_period_id' => $fixtureAccountingPeriodId,
                    'statement_upload_id' => $fixtureUploadId,
                    'txn_date' => '2026-03-15',
                    'description' => $description,
                    'amount' => '-1.00',
                    'source_account_label' => $account,
                    'source_category' => $category,
                    'dedupe_hash' => hash('sha256', $marker . '-' . $id),
                ]
            );
        };

        $insertTransaction((int)('871' . $marker), $companyId, $accountingPeriodId, $uploadId, 'Alpha', ' Materials ', ' Main account ');
        $insertTransaction((int)('872' . $marker), $companyId, $accountingPeriodId, $uploadId, 'Duplicate', 'Materials', 'Main account');
        $insertTransaction((int)('873' . $marker), $companyId, $accountingPeriodId, $uploadId, 'Beta', 'Travel', 'Savings');
        $insertTransaction((int)('874' . $marker), $companyId, $accountingPeriodId, $uploadId, 'Blank', ' ', '');
        $insertTransaction((int)('875' . $marker), $otherCompanyId, $otherAccountingPeriodId, $otherUploadId, 'Other', 'Other Category', 'Other Account');

        $harness->assertSame(['Materials', 'Travel'], $service->fetchSourceCategoryOptions($companyId));
        $harness->assertSame(['Main account', 'Savings'], $service->fetchSourceAccountOptions($companyId));
    });

    $harness->check(\eel_accounts\Service\CategorisationRuleService::class, 'saves ajax checkbox rule active value', function () use ($harness, $service): void {
        $marker = (string)random_int(100000, 999999);
        $companyId = (int)('81' . $marker);
        $nominalAccountId = (int)('82' . $marker);

        InterfaceDB::prepareExecute(
            'INSERT INTO companies (id, company_name, company_number, is_active)
             VALUES (:id, :company_name, :company_number, 1)',
            [
                'id' => $companyId,
                'company_name' => 'Rule Checkbox Test ' . $marker,
                'company_number' => 'RC' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (id, code, name, account_type, tax_treatment, is_active, sort_order)
             VALUES (:id, :code, :name, :account_type, :tax_treatment, 1, 1)',
            [
                'id' => $nominalAccountId,
                'code' => '4' . substr($marker, 0, 3),
                'name' => 'Checkbox nominal ' . $marker,
                'account_type' => 'expense',
                'tax_treatment' => 'allowable',
            ]
        );

        $result = $service->saveRule($companyId, [
            'priority' => '100',
            'match_type' => 'contains',
            'match_value' => 'Checkbox payload ' . $marker,
            'nominal_account_id' => (string)$nominalAccountId,
            'is_active' => ['0', '1'],
        ]);

        $harness->assertSame(true, (bool)($result['success'] ?? false));

        $rule = $service->fetchRule($companyId, (int)($result['rule_id'] ?? 0));
        $harness->assertSame(1, (int)($rule['is_active'] ?? 0));
    });
});
