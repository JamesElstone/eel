<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\TrialBalanceValidationService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TrialBalanceValidationService $service): void {
    $harness->check(\eel_accounts\Service\TrialBalanceValidationService::class, 'warns when an active deferred tax nominal exists under FRS 105', static function () use ($harness, $service): void {
        $fixture = trialBalanceValidationDeferredTaxFixture();

        try {
            $validation = $service->fetchValidation((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $check = trialBalanceValidationFindCheck((array)($validation['checks'] ?? []), 'frs105_deferred_tax_nominal');

            $harness->assertSame('warning', (string)($check['status'] ?? ''));
            $harness->assertTrue((int)(($check['metric_value'] ?? [])['deferred_tax_nominal_count'] ?? 0) >= 1);
            $harness->assertTrue(str_contains((string)($check['detail'] ?? ''), 'FRS 105 prohibits recognising deferred tax'));
        } finally {
            trialBalanceValidationDeactivateNominal((int)$fixture['nominal_id']);
        }
    });

    $harness->check(\eel_accounts\Service\TrialBalanceValidationService::class, 'passes the deferred tax check when the fixture nominal is inactive', static function () use ($harness, $service): void {
        $fixture = trialBalanceValidationDeferredTaxFixture();
        trialBalanceValidationDeactivateNominal((int)$fixture['nominal_id']);
        if (trialBalanceValidationActiveDeferredTaxNominalCount() > 0) {
            $harness->skip('An existing active deferred tax nominal is present in the local database.');
        }

        $validation = $service->fetchValidation((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
        $check = trialBalanceValidationFindCheck((array)($validation['checks'] ?? []), 'frs105_deferred_tax_nominal');

        $harness->assertSame('pass', (string)($check['status'] ?? ''));
    });
});

function trialBalanceValidationDeferredTaxFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'TD' . strtoupper(substr($suffix, 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'Trial Balance Deferred Tax Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $companyNumber]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'Deferred Tax Fixture FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );
    $periodId = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'Deferred Tax Fixture FY']);

    $nominalCode = '9DT' . strtoupper(substr($suffix, 0, 5));
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
         VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
        [
            'code' => $nominalCode,
            'name' => 'Deferred Tax Fixture ' . $suffix,
            'account_type' => 'liability',
            'tax_treatment' => 'allowable',
            'sort_order' => 9900,
        ]
    );
    $nominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code', ['code' => $nominalCode]);

    return ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'nominal_id' => $nominalId];
}

function trialBalanceValidationDeactivateNominal(int $nominalId): void
{
    InterfaceDB::prepareExecute(
        'UPDATE nominal_accounts SET is_active = 0 WHERE id = :id',
        ['id' => $nominalId]
    );
}

function trialBalanceValidationFindCheck(array $checks, string $code): array
{
    foreach ($checks as $check) {
        if ((string)($check['code'] ?? '') === $code) {
            return (array)$check;
        }
    }

    throw new RuntimeException('Check was not found: ' . $code);
}

function trialBalanceValidationActiveDeferredTaxNominalCount(): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM nominal_accounts
         WHERE COALESCE(is_active, 0) = 1
           AND (
                LOWER(COALESCE(name, \'\')) LIKE :name_pattern
                OR LOWER(COALESCE(name, \'\')) LIKE :reverse_name_pattern
                OR LOWER(COALESCE(code, \'\')) LIKE :code_pattern
                OR LOWER(COALESCE(code, \'\')) LIKE :reverse_code_pattern
           )',
        [
            'name_pattern' => '%deferred%tax%',
            'reverse_name_pattern' => '%tax%deferred%',
            'code_pattern' => '%deferred%tax%',
            'reverse_code_pattern' => '%tax%deferred%',
        ]
    );
}
