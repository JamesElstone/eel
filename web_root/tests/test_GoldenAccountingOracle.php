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
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenAccountingOracle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenHmrcCorporationTaxOracle.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenCardComparisonRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'GoldenComparisonReporter.php';

$harness = new GeneratedServiceClassTestHarness();
GoldenAccountsFixture::build();

$harness->check(GoldenAccountingOracle::class, 'is independent from application services and database access', static function () use ($harness): void {
    foreach ([GoldenLedgerSpecification::class, GoldenAccountingOracle::class, GoldenHmrcCorporationTaxOracle::class] as $className) {
        $reflection = new ReflectionClass($className);
        $source = (string)file_get_contents((string)$reflection->getFileName());
        $harness->assertFalse(str_contains($source, 'InterfaceDB::'));
        $harness->assertFalse(str_contains($source, '\\eel_accounts\\'));
    }
});

$harness->check(GoldenHmrcCorporationTaxOracle::class, 'independently applies HMRC add-backs, capital allowances, losses, and corporation-tax rates', static function () use ($harness): void {
    $expected = [
        9111 => ['taxable_before_losses' => 1200.00, 'losses_used' => 0.00, 'taxable_profit' => 1200.00, 'losses_carried_forward' => 0.00, 'corporation_tax' => 228.00],
        9112 => ['taxable_before_losses' => -1500.00, 'losses_used' => 0.00, 'taxable_profit' => 0.00, 'losses_carried_forward' => 1500.00, 'corporation_tax' => 0.00],
        9113 => ['taxable_before_losses' => 7410.00, 'losses_used' => 1500.00, 'taxable_profit' => 5910.00, 'losses_carried_forward' => 0.00, 'corporation_tax' => 1122.90],
        9114 => ['taxable_before_losses' => 7500.00, 'losses_used' => 0.00, 'taxable_profit' => 7500.00, 'losses_carried_forward' => 0.00, 'corporation_tax' => 1425.00],
    ];
    $actual = GoldenHmrcCorporationTaxOracle::calculateSequence(GoldenLedgerSpecification::hmrcTaxFacts());
    foreach ($expected as $periodId => $fields) {
        foreach ($fields as $field => $value) {
            $harness->assertSame(number_format($value, 2, '.', ''), number_format((float)($actual[$periodId][$field] ?? 0), 2, '.', ''));
        }
    }

    $ambiguousFacts = GoldenLedgerSpecification::hmrcTaxFacts();
    $ambiguousFacts[9113]['hmrc_interest_type'] = 'generic_hmrc_interest';
    $rejected = false;
    try {
        GoldenHmrcCorporationTaxOracle::calculateSequence($ambiguousFacts);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $harness->assertTrue($rejected);
});

$harness->check(GoldenCardComparisonRegistry::class, 'classifies every selected card and explicitly excludes action-only cards', static function () use ($harness): void {
    $semantic = GoldenCardComparisonRegistry::semanticContracts();
    $states = GoldenCardComparisonRegistry::stateContracts();
    $excluded = GoldenCardComparisonRegistry::exclusions();
    foreach (GoldenCardComparisonRegistry::selectedPages() as $page => $cards) {
        $pageClass = '_' . $page;
        $harness->assertTrue(class_exists($pageClass));
        $pageInstance = new $pageClass();
        $actualCards = [];
        $layout = method_exists($pageInstance, 'cardLayout')
            ? $pageInstance->cardLayout()
            : [['cards' => $pageInstance->cards()]];
        foreach ($layout as $group) {
            foreach ((array)($group['cards'] ?? []) as $cardKey) {
                $actualCards[] = (string)$cardKey;
            }
        }
        $harness->assertSame($cards, array_values(array_unique($actualCards)));
        foreach ($cards as $card) {
            $classificationCount = (int)isset($semantic[$card]) + (int)isset($states[$card]) + (int)isset($excluded[$card]);
            $harness->assertSame(1, $classificationCount);
        }
    }
    foreach (['asset_create', 'asset_reconcile_manual', 'dividend_declare', 'hmrc_obligations_action_panel', 'year_end_notes', 'ixbrl_generation'] as $card) {
        $harness->assertTrue(isset($excluded[$card]));
    }
});

$harness->check(GoldenAccountingOracle::class, 'applies the year-two HMRC penalty, year-three interest, and year-four payment to the correct P and L and tax periods', static function () use ($harness): void {
    $expected = [
        9112 => ['profit_before_tax' => 6900.00, 'add_back' => 600.00, 'taxable_profit' => 7500.00, 'tax' => 1425.00],
        9113 => ['profit_before_tax' => 7410.00, 'add_back' => 0.00, 'taxable_profit' => 7410.00, 'tax' => 1407.90],
        9114 => ['profit_before_tax' => 7500.00, 'add_back' => 0.00, 'taxable_profit' => 7500.00, 'tax' => 1425.00],
    ];
    foreach ($expected as $periodId => $values) {
        $oracle = GoldenAccountingOracle::expected($periodId);
        $profitLoss = (new \eel_accounts\Service\ProfitLossService())
            ->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $tax = (new \eel_accounts\Service\TaxWorkingsService())
            ->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId, 0);
        $harness->assertSame($values['profit_before_tax'], (float)$oracle['profit_loss']['profit_before_tax']);
        $harness->assertSame($values['profit_before_tax'], (float)($profitLoss['profit_before_tax'] ?? 0));
        $harness->assertSame($values['add_back'], (float)$oracle['corporation_tax']['disallowable_add_backs']);
        $harness->assertSame($values['add_back'], (float)($tax['summary']['disallowable_add_backs'] ?? 0));
        $harness->assertSame($values['taxable_profit'], (float)($tax['summary']['taxable_profit'] ?? 0));
        $harness->assertSame($values['tax'], (float)($tax['summary']['estimated_corporation_tax'] ?? 0));
    }

    $obligations = InterfaceDB::fetchAll(
        'SELECT obligation_type, accounting_period_id, amount_due, amount_paid, status, related_fine_id, related_journal_id
         FROM hmrc_obligations
         WHERE company_id = :company_id AND obligation_type IN (:penalty, :interest)
         ORDER BY accounting_period_id',
        ['company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID, 'penalty' => 'hmrc_penalty', 'interest' => 'hmrc_interest']
    );
    $harness->assertSame(2, count($obligations));
    $harness->assertSame('paid', (string)$obligations[0]['status']);
    $harness->assertSame('paid', (string)$obligations[1]['status']);
    $harness->assertSame(600.00, (float)$obligations[0]['amount_paid']);
    $harness->assertSame(90.00, (float)$obligations[1]['amount_paid']);
    $harness->assertSame(0, (int)($obligations[1]['related_fine_id'] ?? 0));
    $harness->assertSame(true, (int)$obligations[0]['related_journal_id'] > 0 && (int)$obligations[1]['related_journal_id'] > 0);
});

$harness->check(GoldenAccountingOracle::class, 'matches real journal, trial balance, profit and loss, director loan, tax, and Companies House services for all periods', static function () use ($harness): void {
    $failures = [];
    foreach (array_keys(GoldenLedgerSpecification::periods()) as $periodId) {
        $expected = GoldenAccountingOracle::expected($periodId);
        $journals = (new \eel_accounts\Service\TransactionJournalService())->fetchJournals(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $trialBalance = (new \eel_accounts\Service\TrialBalanceService())->fetchTrialBalance(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $validation = (new \eel_accounts\Service\TrialBalanceValidationService())->fetchValidation(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $profitLoss = (new \eel_accounts\Service\ProfitLossService())->getProfitLossSummary(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $directorLoan = (new \eel_accounts\Service\DirectorLoanService())->fetchStatement(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $tax = (new \eel_accounts\Service\TaxWorkingsService())->fetchWorkings(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId, 0);

        goldenCompare($failures, 'journal', 'journals_list', $periodId, 'journal_count', $expected['journal_count'], count($journals));
        goldenCompare($failures, 'journal', 'journals_list', $periodId, 'total_debits', $expected['journals']['total_debits'], array_sum(array_column($journals, 'total_debit')));
        goldenCompare($failures, 'trial_balance', 'trial_balance_state', $periodId, 'total_debits', $expected['trial_balance']['total_debits'], $trialBalance['totals']['total_debits'] ?? null);
        goldenCompare($failures, 'trial_balance', 'trial_balance_state', $periodId, 'total_credits', $expected['trial_balance']['total_credits'], $trialBalance['totals']['total_credits'] ?? null);
        goldenCompare($failures, 'trial_balance', 'trial_balance_validation', $periodId, 'is_balanced', true, goldenValidationPassed($validation, 'trial_balance_equality'));
        goldenCompare($failures, 'profit_loss', 'pl_summary', $periodId, 'income_total', $expected['profit_loss']['income'], $profitLoss['income_total'] ?? null);
        goldenCompare($failures, 'profit_loss', 'pl_summary', $periodId, 'cost_of_sales_total', $expected['profit_loss']['cost_of_sales'], $profitLoss['cost_of_sales_total'] ?? null);
        goldenCompare($failures, 'profit_loss', 'pl_summary', $periodId, 'operating_expense_total', $expected['profit_loss']['operating_expenses'], $profitLoss['operating_expense_total'] ?? null);
        goldenCompare($failures, 'profit_loss', 'pl_summary', $periodId, 'profit_before_tax', $expected['profit_loss']['profit_before_tax'], $profitLoss['profit_before_tax'] ?? null);
        goldenCompare($failures, 'profit_loss', 'pl_summary', $periodId, 'estimated_corporation_tax', $expected['profit_loss']['estimated_corporation_tax'], $profitLoss['estimated_corporation_tax'] ?? null);
        goldenCompare($failures, 'director_loans', 'director_loan_state', $periodId, 'opening_balance', $expected['director_loan']['opening'], $directorLoan['opening_balance'] ?? null);
        goldenCompare($failures, 'director_loans', 'director_loan_state', $periodId, 'movement_in_period', $expected['director_loan']['movement'], $directorLoan['movement_in_period'] ?? null);
        goldenCompare($failures, 'director_loans', 'director_loan_state', $periodId, 'closing_balance', $expected['director_loan']['closing'], $directorLoan['closing_balance'] ?? null);
        goldenCompare($failures, 'tax', 'tax_corporation_tax_summary', $periodId, 'taxable_profit', $expected['corporation_tax']['taxable_profit'], $tax['summary']['taxable_profit'] ?? null);
        goldenCompare($failures, 'tax', 'tax_corporation_tax_summary', $periodId, 'estimated_corporation_tax', $expected['profit_loss']['estimated_corporation_tax'], $tax['summary']['estimated_corporation_tax'] ?? null);
        foreach (['accounting_profit', 'disallowable_add_backs', 'depreciation_add_back', 'capital_allowances', 'taxable_before_losses', 'taxable_profit', 'taxable_loss', 'estimated_corporation_tax', 'estimated_rate', 'losses_used'] as $field) {
            goldenCompare($failures, 'tax', 'tax_taxable_profit_bridge', $periodId, $field, $expected['corporation_tax'][$field], $tax['summary'][$field] ?? null);
        }
        goldenCompare($failures, 'tax', 'tax_disallowable_add_backs', $periodId, 'row_count', $expected['corporation_tax']['disallowable_add_backs'] > 0 ? 1 : 0, count((array)($tax['disallowable_add_backs'] ?? [])));
        goldenCompare($failures, 'tax', 'tax_depreciation_add_back', $periodId, 'row_count', 0, count((array)($tax['depreciation_add_back'] ?? [])));
        goldenCompare($failures, 'tax', 'tax_capital_allowances_summary', $periodId, 'net_capital_allowances', 0.00, $tax['capital_allowances_summary']['net_capital_allowances'] ?? null);
        foreach (['tax_aia_allocation' => 'aia_allocation', 'tax_main_rate_pool' => 'main_rate_pool', 'tax_special_rate_pool' => 'special_rate_pool', 'tax_car_co2_treatment' => 'car_co2_treatment', 'tax_disposals_balancing' => 'disposals_balancing'] as $card => $key) {
            goldenCompare($failures, 'tax', $card, $periodId, 'row_count', 0, count((array)($tax[$key] ?? [])));
        }
        goldenCompare($failures, 'tax', 'tax_losses', $periodId, 'losses_used', 0.00, $tax['summary']['losses_used'] ?? null);
        goldenCompare($failures, 'tax', 'tax_rate_bands', $periodId, 'total_liability', $expected['corporation_tax']['estimated_corporation_tax'], array_sum(array_column((array)($tax['rate_bands'] ?? []), 'liability')));
        goldenCompare($failures, 'tax', 'tax_warnings', $periodId, 'warning_count', $expected['corporation_tax']['warning_count'], count((array)($tax['warnings'] ?? [])));

        $snapshot = (new \eel_accounts\Service\CompaniesHouseSnapshotService())->fetchSnapshot(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        $snapshotFields = goldenFieldsByKey((array)($snapshot['fields'] ?? []));
        foreach (['company_name', 'company_number', 'fixed_assets', 'current_assets', 'creditors_within_one_year', 'creditors_after_more_than_one_year', 'net_current_assets_liabilities', 'total_assets_less_current_liabilities', 'net_assets_liabilities', 'equity_capital_reserves'] as $field) {
            goldenCompare($failures, 'companies_house', 'companies_house_snapshot', $periodId, $field, $expected['companies_house'][$field], $snapshotFields[$field] ?? null);
        }
        goldenCompare($failures, 'companies_house', 'companies_house_snapshot', $periodId, 'is_balance_sheet_balanced', $expected['companies_house']['is_balance_sheet_balanced'], $snapshot['is_balance_sheet_balanced'] ?? null);
        goldenCompare($failures, 'companies_house', 'companies_house_snapshot', $periodId, 'balance_equation_difference', $expected['companies_house']['balance_equation_difference'], $snapshot['balance_equation_difference'] ?? null);
        $comparison = (new \eel_accounts\Service\YearEndCompaniesHouseComparisonService())->fetchComparison(GoldenAccountsFixture::GOLDEN_COMPANY_ID, $periodId);
        goldenCompare($failures, 'companies_house', 'year_end_companies_house_comparison', $periodId, 'available', $expected['companies_house']['stored_filing_available'], $comparison['available'] ?? null);
        goldenCompare($failures, 'companies_house', 'year_end_companies_house_comparison', $periodId, 'missing_filing_error', true, in_array('No stored Companies House accounts filings were found for this company.', (array)($comparison['errors'] ?? []), true));
    }
    if ($failures !== []) {
        throw new RuntimeException(GoldenComparisonReporter::report($failures));
    }
});

/** @param list<array<string, mixed>> $failures */
function goldenCompare(array &$failures, string $page, string $card, int $period, string $field, mixed $expected, mixed $actual): void
{
    $matches = is_float($expected) || is_float($actual)
        ? abs((float)$expected - (float)$actual) < 0.005
        : $expected === $actual;
    if (!$matches) {
        $failures[] = compact('page', 'card', 'period', 'field', 'expected', 'actual');
    }
}

function goldenValidationPassed(array $validation, string $code): bool
{
    foreach ((array)($validation['checks'] ?? []) as $check) {
        if (($check['code'] ?? '') === $code) {
            return ($check['status'] ?? '') === 'pass';
        }
    }
    return false;
}

/** @param list<array<string, mixed>> $fields @return array<string, mixed> */
function goldenFieldsByKey(array $fields): array
{
    $values = [];
    foreach ($fields as $field) {
        $values[(string)($field['key'] ?? '')] = $field['value'] ?? null;
    }
    return $values;
}
