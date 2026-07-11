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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenLedgerSpecification.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenComparisonReporter.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check('GoldenYearEndLifecycle', 'performs close tasks and preserves reporting semantics when completed periods are locked', static function () use ($harness): void {
    $companyId = GoldenAccountsFixture::GOLDEN_COMPANY_ID;
    $periods = [9111, 9112, 9113];

    foreach ($periods as $periodId) {
        $expected = GoldenLedgerSpecification::yearEndAssetExpectations()[$periodId];
        $depreciation = (new \eel_accounts\Service\AssetService())->runDepreciation($companyId, $periodId);
        $harness->assertTrue(!empty($depreciation['success']));
        $harness->assertSame((int)$expected['depreciation_entries'], (int)($depreciation['created'] ?? 0));
        $postedDepreciation = (float)InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(ade.amount), 0)
             FROM asset_depreciation_entries ade
             INNER JOIN asset_register ar ON ar.id = ade.asset_id
             WHERE ar.company_id = :company_id AND ade.accounting_period_id = :period_id',
            ['company_id' => $companyId, 'period_id' => $periodId]
        );
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format($postedDepreciation, 2, '.', ''));

        $provision = (new \eel_accounts\Service\CorporationTaxProvisionService())
            ->postProvisionsForAccountingPeriod($companyId, $periodId, 'golden_year_end_test');
        $harness->assertTrue(!empty($provision['success']));

        $checklist = new \eel_accounts\Service\YearEndChecklistService();
        $acknowledgement = $checklist->saveRetainedEarningsCloseAcknowledgement(
            $companyId,
            $periodId,
            true,
            'golden_year_end_test',
            'Golden lifecycle figures agreed after close-task calculations.'
        );
        $harness->assertTrue(!empty($acknowledgement['success']));

        $retainedEarnings = (new \eel_accounts\Service\RetainedEarningsCloseService())
            ->postClose($companyId, $periodId, 'golden_year_end_test');
        $harness->assertTrue(!empty($retainedEarnings['success']));

        $taxPersistence = (new \eel_accounts\Service\CorporationTaxComputationService())
            ->persistSummariesForAccountingPeriod($companyId, $periodId);
        $harness->assertTrue(!empty($taxPersistence['success']));

        $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
        $harness->assertSame(number_format((float)$expected['depreciation'], 2, '.', ''), number_format((float)($profitLoss['depreciation_expense'] ?? 0), 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['profit_before_tax'], 2, '.', ''), number_format((float)($profitLoss['profit_before_tax'] ?? 0), 2, '.', ''));

        $ctTax = 0.0;
        $ctTaxableProfit = 0.0;
        $ctCapitalAllowances = 0.0;
        $ctPeriods = (new \eel_accounts\Service\CorporationTaxPeriodService())->fetchForAccountingPeriod($companyId, $periodId);
        foreach ($ctPeriods as $ctPeriod) {
            $summary = (new \eel_accounts\Service\CorporationTaxComputationService())->fetchSummaryForCtPeriodId($companyId, (int)$ctPeriod['id']);
            $ctTax += (float)($summary['estimated_corporation_tax'] ?? 0);
            $ctTaxableProfit += (float)($summary['taxable_profit'] ?? 0);
            $ctCapitalAllowances += (float)($summary['capital_allowances'] ?? 0);
        }
        $harness->assertSame(number_format((float)$expected['capital_allowances'], 2, '.', ''), number_format($ctCapitalAllowances, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['taxable_profit'], 2, '.', ''), number_format($ctTaxableProfit, 2, '.', ''));
        $harness->assertSame(number_format((float)$expected['corporation_tax'], 2, '.', ''), number_format($ctTax, 2, '.', ''));

        $beforeLock = goldenYearEndReportingSnapshot($companyId, $periodId);
        $lock = (new \eel_accounts\Service\YearEndLockService())
            ->lockPeriod($companyId, $periodId, 'golden_year_end_test');
        $harness->assertTrue(!empty($lock['success']));
        $harness->assertTrue((new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $periodId));
        $afterLock = goldenYearEndReportingSnapshot($companyId, $periodId);

        if ($beforeLock !== $afterLock) {
            throw new RuntimeException(GoldenComparisonReporter::report([[
                'page' => 'year_end',
                'card' => 'locked_reporting_invariants',
                'period' => $periodId,
                'field' => 'tax_companies_house_dividends_profit_loss',
                'expected' => $beforeLock,
                'actual' => $afterLock,
            ]]));
        }
    }
});

/** @return array<string, mixed> */
function goldenYearEndReportingSnapshot(int $companyId, int $periodId): array
{
    $tax = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings($companyId, $periodId, 0);
    $companiesHouse = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot($companyId, $periodId);
    $dividends = (new \eel_accounts\Service\DividendViewDataService())->fetchCapacityContext($companyId, $periodId);
    $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary($companyId, $periodId);
    $companiesHouseFields = goldenYearEndFieldsByKey((array)($companiesHouse['fields'] ?? []));
    $capacity = (array)($dividends['capacity'] ?? []);

    return [
        'tax' => goldenYearEndSelect((array)($tax['summary'] ?? []), [
            'accounting_profit', 'disallowable_add_backs', 'depreciation_add_back', 'capital_allowances',
            'taxable_before_losses', 'taxable_profit', 'taxable_loss', 'estimated_corporation_tax',
            'losses_brought_forward', 'losses_used', 'losses_carried_forward',
        ]),
        'companies_house' => [
            'fixed_assets' => $companiesHouseFields['fixed_assets'] ?? null,
            'current_assets' => $companiesHouseFields['current_assets'] ?? null,
            'creditors_within_one_year' => $companiesHouseFields['creditors_within_one_year'] ?? null,
            'creditors_after_more_than_one_year' => $companiesHouseFields['creditors_after_more_than_one_year'] ?? null,
            'net_assets_liabilities' => $companiesHouseFields['net_assets_liabilities'] ?? null,
            'equity_capital_reserves' => $companiesHouseFields['equity_capital_reserves'] ?? null,
            'balance_equation_difference' => $companiesHouse['balance_equation_difference'] ?? null,
            'is_balance_sheet_balanced' => $companiesHouse['is_balance_sheet_balanced'] ?? null,
        ],
        'dividends' => goldenYearEndSelect($capacity, [
            'retained_earnings_brought_forward', 'distributable_reserves_brought_forward',
            'ledger_current_year_profit_loss', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'unposted_corporation_tax_adjustment',
            'current_year_profit_loss_after_tax', 'dividends_declared', 'available_distributable_reserves',
        ]),
        'profit_loss' => goldenYearEndSelect($profitLoss, [
            'income_total', 'cost_of_sales_total', 'gross_profit', 'operating_expense_total',
            'depreciation_expense', 'profit_before_tax', 'posted_corporation_tax_charge',
            'estimated_corporation_tax', 'profit_after_posted_tax', 'profit_after_estimated_tax', 'net_profit',
        ]),
    ];
}

/** @param array<string, mixed> $source @param list<string> $keys @return array<string, mixed> */
function goldenYearEndSelect(array $source, array $keys): array
{
    $selected = [];
    foreach ($keys as $key) {
        $selected[$key] = $source[$key] ?? null;
    }
    return $selected;
}

/** @param list<array<string, mixed>> $fields @return array<string, mixed> */
function goldenYearEndFieldsByKey(array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $values[(string)($field['key'] ?? '')] = $field['value'] ?? null;
    }
    return $values;
}
