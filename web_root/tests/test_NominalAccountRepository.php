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
$harness->run(\eel_accounts\Repository\NominalAccountRepository::class, function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Repository\NominalAccountRepository $repository): void {
    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'deletes unused nominal accounts', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Unused Delete Fixture');

        $harness->assertSame(0, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(true, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(true, $repository->deleteNominalAccountIfUnused($nominalId));
        $harness->assertSame(null, $repository->findById($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts assigned as company defaults', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Default Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Default Reference Fixture Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO company_settings (company_id, setting, type, value)
             VALUES (:company_id, :setting, :type, :value)',
            [
                'company_id' => $companyId,
                'setting' => 'default_bank_nominal_id',
                'type' => 'int',
                'value' => (string)$nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
        $harness->assertSame(true, $repository->findById($nominalId) !== null);
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts assigned as tools defaults', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Tools Default Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Tools Default Reference Fixture Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO company_settings (company_id, setting, type, value)
             VALUES (:company_id, :setting, :type, :value)',
            [
                'company_id' => $companyId,
                'setting' => 'tools_small_equipment_nominal_id',
                'type' => 'int',
                'value' => (string)$nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
        $harness->assertSame(true, $repository->findById($nominalId) !== null);
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts assigned to bank or trade accounts', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Account Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Account Reference Fixture Limited');

        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (company_id, account_name, account_type, nominal_account_id)
             VALUES (:company_id, :account_name, :account_type, :nominal_id)',
            [
                'company_id' => $companyId,
                'account_name' => 'Fixture Bank',
                'account_type' => 'bank',
                'nominal_id' => $nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts used by expense claim lines', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Expense Claim Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Expense Claim Reference Fixture Limited');
        $periodId = nominalAccountRepositoryInsertAccountingPeriod($companyId, 'Expense Claim Reference Fixture FY');
        $claimantId = nominalAccountRepositoryInsertExpenseClaimant($companyId, 'Expense Claim Reference Claimant');
        $claimId = nominalAccountRepositoryInsertExpenseClaim($companyId, $periodId, $claimantId, 'ECRF');

        InterfaceDB::prepareExecute(
            'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
             VALUES (:claim_id, 1, :expense_date, :description, :amount, :nominal_id)',
            [
                'claim_id' => $claimId,
                'expense_date' => '2024-03-15',
                'description' => 'Expense claim reference fixture',
                'amount' => '12.34',
                'nominal_id' => $nominalId,
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'blocks deleting nominal accounts used by transactions in any accounting period', function () use ($harness, $repository): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Any Period Transaction Reference Fixture');
        $companyId = nominalAccountRepositoryInsertCompany('Any Period Transaction Reference Fixture Limited');
        nominalAccountRepositoryInsertAccountingPeriod($companyId, 'Any Period Transaction Reference Current FY', '2025-01-01', '2025-12-31');
        $historicPeriodId = nominalAccountRepositoryInsertAccountingPeriod($companyId, 'Any Period Transaction Reference Historic FY', '2024-01-01', '2024-12-31');
        $uploadId = nominalAccountRepositoryInsertStatementUpload($companyId, $historicPeriodId);

        InterfaceDB::prepareExecute(
            'INSERT INTO transactions (
                company_id,
                accounting_period_id,
                statement_upload_id,
                txn_date,
                description,
                amount,
                dedupe_hash,
                nominal_account_id,
                category_status
             ) VALUES (
                :company_id,
                :accounting_period_id,
                :statement_upload_id,
                :txn_date,
                :description,
                :amount,
                :dedupe_hash,
                :nominal_id,
                :category_status
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $historicPeriodId,
                'statement_upload_id' => $uploadId,
                'txn_date' => '2024-07-02',
                'description' => 'Historic nominal reference fixture',
                'amount' => '25.00',
                'dedupe_hash' => hash('sha256', 'historic nominal reference fixture' . microtime(true)),
                'nominal_id' => $nominalId,
                'category_status' => 'manual',
            ]
        );

        $harness->assertSame(1, $repository->nominalReferenceCount($nominalId));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });

    $harness->check(\eel_accounts\Repository\NominalAccountRepository::class, 'catalog still renders when an optional reference table is absent', function () use ($harness): void {
        $nominalId = nominalAccountRepositoryInsertNominal('Missing Reference Table Fixture');

        InterfaceDB::execute('DROP TABLE IF EXISTS corporation_tax_treatment_rules');

        $repository = new \eel_accounts\Repository\NominalAccountRepository();
        $catalog = $repository->fetchNominalAccountCatalog();
        $fixtureRow = null;

        foreach ($catalog as $row) {
            if (is_array($row) && (int)($row['id'] ?? 0) === $nominalId) {
                $fixtureRow = $row;
                break;
            }
        }

        $harness->assertSame(true, $fixtureRow !== null);
        $harness->assertSame(0, (int)($fixtureRow['can_delete'] ?? 1));
        $harness->assertSame(false, $repository->canDeleteNominalAccount($nominalId));
        $harness->assertSame(false, $repository->deleteNominalAccountIfUnused($nominalId));
    });
});

function nominalAccountRepositoryInsertNominal(string $name): int
{
    $code = '9N' . strtoupper(substr(hash('sha256', $name . microtime(true)), 0, 6));

    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => 'asset',
            'tax_treatment' => 'other',
            'sort_order' => 9000,
        ]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $code]);
}

function nominalAccountRepositoryInsertCompany(string $companyName): int
{
    $number = 'NR' . strtoupper(substr(hash('sha256', $companyName . microtime(true)), 0, 8));
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:name, :number)',
        ['name' => $companyName, 'number' => $number]
    );

    return (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => $number]);
}

function nominalAccountRepositoryInsertAccountingPeriod(
    int $companyId,
    string $label,
    string $periodStart = '2024-01-01',
    string $periodEnd = '2024-12-31'
): int {
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        [
            'company_id' => $companyId,
            'label' => $label,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => $label]
    );
}

function nominalAccountRepositoryInsertExpenseClaimant(int $companyId, string $claimantName): int
{
    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claimants (company_id, claimant_name)
         VALUES (:company_id, :claimant_name)',
        ['company_id' => $companyId, 'claimant_name' => $claimantName]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM expense_claimants WHERE company_id = :company_id AND claimant_name = :claimant_name',
        ['company_id' => $companyId, 'claimant_name' => $claimantName]
    );
}

function nominalAccountRepositoryInsertExpenseClaim(int $companyId, int $periodId, int $claimantId, string $referencePrefix): int
{
    $referenceCode = $referencePrefix . strtoupper(substr(hash('sha256', $referencePrefix . microtime(true)), 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO expense_claims (
            company_id,
            accounting_period_id,
            claimant_id,
            claim_year,
            claim_month,
            period_start,
            period_end,
            claim_reference_code
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :claimant_id,
            :claim_year,
            :claim_month,
            :period_start,
            :period_end,
            :claim_reference_code
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'claimant_id' => $claimantId,
            'claim_year' => 2024,
            'claim_month' => 3,
            'period_start' => '2024-03-01',
            'period_end' => '2024-03-31',
            'claim_reference_code' => $referenceCode,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM expense_claims WHERE company_id = :company_id AND claim_reference_code = :reference_code',
        ['company_id' => $companyId, 'reference_code' => $referenceCode]
    );
}

function nominalAccountRepositoryInsertStatementUpload(int $companyId, int $periodId): int
{
    $hash = hash('sha256', 'nominal repository statement upload' . microtime(true));

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256
         ) VALUES (
            :company_id,
            :accounting_period_id,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'statement_month' => '2024-07-01',
            'original_filename' => 'nominal-reference-fixture.csv',
            'stored_filename' => $hash . '.csv',
            'file_sha256' => $hash,
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND file_sha256 = :file_sha256',
        ['company_id' => $companyId, 'file_sha256' => $hash]
    );
}
