<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(\eel_accounts\Service\TrialBalanceService::class, static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\TrialBalanceService $service): void {
    $harness->check(\eel_accounts\Service\TrialBalanceService::class, 'uses CT-period summary scope for tax computation', static function () use ($harness, $service): void {
        $fixture = trialBalanceCtPeriodSummaryFixture();
        $summary = $service->fetchSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
        $taxComputation = (array)(($summary['summary'] ?? [])['tax_computation'] ?? []);

        $harness->assertSame(true, (bool)($summary['available'] ?? false));
        $harness->assertSame(true, (bool)($taxComputation['available'] ?? false));
        $harness->assertSame('accounting_period_ct_periods', (string)($taxComputation['summary_scope'] ?? ''));
    });

    $harness->check(\eel_accounts\Service\TrialBalanceService::class, 'source workflow routing keeps context out of URLs', static function () use ($harness, $service): void {
        $sourceLink = new ReflectionMethod($service, 'sourceLink');
        $sourceLink->setAccessible(true);
        $sourceWorkflowFields = new ReflectionMethod($service, 'sourceWorkflowFields');
        $sourceWorkflowFields->setAccessible(true);

        $url = (string)$sourceLink->invoke($service, 'bank_csv', 'transaction:55', 12, 34, '2026-02-14');
        $fields = (array)$sourceWorkflowFields->invoke($service, 'bank_csv', 'transaction:55', 12, 34, '2026-02-14');

        $harness->assertSame('?page=transactions', $url);
        $harness->assertSame(false, str_contains($url, 'company_id='));
        $harness->assertSame(12, (int)($fields['company_id'] ?? 0));
        $harness->assertSame(34, (int)($fields['accounting_period_id'] ?? 0));
        $harness->assertSame('2026-02-01', (string)($fields['month_key'] ?? ''));
        $harness->assertSame(55, (int)($fields['transaction_id'] ?? 0));
    });
});

function trialBalanceCtPeriodSummaryFixture(): array
{
    $suffix = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
    $companyNumber = 'TB' . strtoupper(substr($suffix, 0, 8));

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'Trial Balance CT Period Fixture Limited', 'company_number' => $companyNumber]
    );
    $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => $companyNumber]);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :period_start, :period_end)',
        ['company_id' => $companyId, 'label' => 'Trial Balance CT Fixture FY', 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'Trial Balance CT Fixture FY']
    );

    return ['company_id' => $companyId, 'accounting_period_id' => $periodId];
}
