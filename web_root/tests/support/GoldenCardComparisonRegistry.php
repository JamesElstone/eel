<?php
declare(strict_types=1);

final class GoldenCardComparisonRegistry
{
    /** @return array<string, list<string>> */
    public static function selectedPages(): array
    {
        return [
            'journal' => ['journals_list', 'journal_cut_offs', 'journal_cut_off_confirmation'],
            'prepayments' => ['prepayments_review', 'year_end_prepayment_approvals'],
            'assets' => ['asset_register', 'asset_create', 'asset_reconcile_manual', 'not_an_asset'],
            'vehicles' => ['vehicle_register'],
            'director_loans' => ['director_loan_state', 'year_end_director_loan_offset'],
            'dividends' => ['dividend_capacity', 'dividend_vouchers', 'dividend_reserve_review', 'dividend_declare', 'dividend_history'],
            'trial_balance' => ['trial_balance_state', 'trial_balance_validation', 'trial_balance_losses'],
            'profit_loss' => ['pl_summary', 'pl_monthly_trend', 'pl_income_breakdown', 'pl_expense_breakdown', 'pl_net_profit_bridge', 'pl_source_coverage', 'year_end_retained_earnings'],
            'tax' => ['tax_period_selector', 'tax_corporation_tax_summary', 'tax_taxable_profit_bridge', 'tax_prepayment_treatment', 'tax_disallowable_add_backs', 'tax_depreciation_add_back', 'tax_capital_allowances_summary', 'tax_aia_allocation', 'tax_main_rate_pool', 'tax_special_rate_pool', 'tax_car_co2_treatment', 'tax_disposals_balancing', 'tax_losses', 'tax_rate_bands', 'tax_warnings'],
            'tax_rates' => ['tax_rates_ct', 'tax_rates_vat', 'tax_thresholds_vat', 'tax_treatment_rules'],
            'vat' => ['vat_turnover_monitoring', 'vat_registration', 'vat_readiness'],
            'hmrc_obligations' => ['hmrc_obligations_summary', 'hmrc_obligations_action_panel', 'hmrc_obligations_timeline', 'hmrc_obligations_period_checklist', 'hmrc_fines_table'],
            'year_end' => ['year_end_checklist', 'year_end_tax_readiness', 'year_end_notes', 'year_end_state', 'year_end_audit_log'],
            'companies_house' => ['companies_house_snapshot', 'year_end_companies_house_comparison'],
            'ixbrl_builder' => ['ixbrl_readiness', 'ixbrl_trial_balance', 'ixbrl_accounts_mapping', 'ixbrl_facts_preview', 'ixbrl_generation'],
        ];
    }

    /** @return array<string, string> */
    public static function exclusions(): array
    {
        return [
            'asset_create' => 'Action-only asset creation form.',
            'asset_reconcile_manual' => 'Action-only manual reconciliation workflow.',
            'dividend_declare' => 'Action-only dividend declaration form.',
            'hmrc_obligations_action_panel' => 'Action-only obligation command panel.',
            'year_end_notes' => 'Action-only notes editor.',
            'ixbrl_generation' => 'Action-only generation and submission workflow.',
        ];
    }

    /** @return array<string, array{page: string, result: string}> */
    public static function semanticContracts(): array
    {
        return [
            'journals_list' => ['page' => 'journal', 'result' => 'journals'],
            'director_loan_state' => ['page' => 'director_loans', 'result' => 'director_loan'],
            'trial_balance_state' => ['page' => 'trial_balance', 'result' => 'trial_balance'],
            'trial_balance_validation' => ['page' => 'trial_balance', 'result' => 'trial_balance_validation'],
            'trial_balance_losses' => ['page' => 'trial_balance', 'result' => 'trial_balance'],
            'pl_summary' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_income_breakdown' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_expense_breakdown' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_net_profit_bridge' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'tax_corporation_tax_summary' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_taxable_profit_bridge' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_rate_bands' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_disallowable_add_backs' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_depreciation_add_back' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_capital_allowances_summary' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_aia_allocation' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_main_rate_pool' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_special_rate_pool' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_car_co2_treatment' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_disposals_balancing' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_losses' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'tax_warnings' => ['page' => 'tax', 'result' => 'corporation_tax'],
            'companies_house_snapshot' => ['page' => 'companies_house', 'result' => 'companies_house'],
            'year_end_companies_house_comparison' => ['page' => 'companies_house', 'result' => 'companies_house'],
        ];
    }

    /** @return array<string, string> */
    public static function stateContracts(): array
    {
        $contracts = [];
        $semantic = self::semanticContracts();
        $excluded = self::exclusions();
        foreach (self::selectedPages() as $page => $cards) {
            foreach ($cards as $card) {
                if (!isset($semantic[$card]) && !isset($excluded[$card])) {
                    $contracts[$card] = $page;
                }
            }
        }

        return $contracts;
    }
}
