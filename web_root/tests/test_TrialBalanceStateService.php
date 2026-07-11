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
    \eel_accounts\Service\TrialBalanceStateService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TrialBalanceStateService $service): void {
        $harness->check(\eel_accounts\Service\TrialBalanceStateService::class, 'preserves summary and validation accuracy without CT enrichment', static function () use ($harness, $service): void {
            $fixture = trialBalanceStateFixture();
            $companyId = (int)$fixture['company_id'];
            $accountingPeriodId = (int)$fixture['accounting_period_id'];

            $legacyTrialBalance = (new \eel_accounts\Service\TrialBalanceService())->fetchTrialBalance($companyId, $accountingPeriodId, true, false);
            $legacyValidation = (new \eel_accounts\Service\TrialBalanceValidationService())->fetchValidation($companyId, $accountingPeriodId);
            $state = $service->fetchState($companyId, $accountingPeriodId);
            $stateTrialBalance = (array)($state['trial_balance'] ?? []);
            $stateSummary = (array)($stateTrialBalance['summary'] ?? []);
            $legacySummary = (array)($legacyTrialBalance['summary'] ?? []);

            $coreKeys = [
                'trial_balance_status',
                'profit_before_tax',
                'net_assets',
                'bank_balance_total',
                'director_loan_balance',
                'vat_control_balance',
                'uncategorised_exposure',
                'corporation_tax_balance',
            ];
            foreach ($coreKeys as $key) {
                $harness->assertSame($legacySummary[$key] ?? null, $stateSummary[$key] ?? null);
            }

            $harness->assertSame((array)($legacyTrialBalance['totals'] ?? []), (array)($stateTrialBalance['totals'] ?? []));
            $harness->assertSame((bool)($legacyTrialBalance['has_rows'] ?? false), (bool)($stateTrialBalance['has_rows'] ?? false));
            $harness->assertSame($legacyValidation, (array)($state['validation'] ?? []));
            $harness->assertSame(false, array_key_exists('tax_computation', $stateSummary));
            $harness->assertSame(true, array_key_exists('tax_computation', $legacySummary));
        });

        $harness->check(\eel_accounts\Service\TrialBalanceStateService::class, 'is the single declared service for the summary card', static function () use ($harness): void {
            $services = (new _trial_balance_stateCard())->services();

            $harness->assertCount(1, $services);
            $harness->assertSame('trialBalanceState', (string)($services[0]['key'] ?? ''));
            $harness->assertSame(\eel_accounts\Service\TrialBalanceStateService::class, (string)($services[0]['service'] ?? ''));
            $harness->assertSame('fetchState', (string)($services[0]['method'] ?? ''));
        });
    }
);

function trialBalanceStateFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'TS' . strtoupper(substr($suffix, 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'Trial Balance State Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $companyNumber]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'Trial Balance State FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'Trial Balance State FY']
    );

    return ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId];
}
