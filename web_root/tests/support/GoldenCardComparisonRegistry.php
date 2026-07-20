<?php
declare(strict_types=1);

final class GoldenCardComparisonRegistry
{
    /** @return array<string, list<string>> */
    public static function selectedPages(): array
    {
        return [
            'assets' => ['asset_register', 'asset_create', 'asset_reconcile_manual', 'not_an_asset'],
            'companies' => ['companies_company_settings', 'settings_setup_health', 'companies_stored_detail', 'companies_search', 'accounting_periods', 'companies_nominals', 'companies_danger'],
            'companies_house' => ['companies_house_snapshot', 'year_end_companies_house_comparison'],
            'dashboard' => ['overview', 'dashboard_action_queue', 'dashboard_year_end_readiness', 'dashboard_recent_transactions', 'activity'],
            'loans' => ['director_loan_state', 'director_loan_directors', 'director_loan_s455', 'year_end_director_loan_offset'],
            'dividends' => ['dividend_capacity', 'dividend_vouchers', 'dividend_declare', 'dividend_history'],
            'expense_claims' => ['expense_statistics', 'expense_claimants', 'expense_add_claimant', 'expenses_state', 'expense_claim_create', 'expense_claim_editor', 'expense_search', 'year_end_expenses_confirmation'],
            'HMRC' => ['hmrc_obligations_summary', 'hmrc_obligations_action_panel', 'hmrc_obligations_timeline', 'hmrc_obligations_period_checklist', 'hmrc_fines_table', 'hmrc_submission_unavailable'],
            'incorporation' => ['incorporation_status', 'incorporation_share_capital', 'incorporation_payment_matching', 'incorporation_ownership_parties', 'incorporation_share_allocation', 'incorporation_relationships'],
            'disclosures' => ['ixbrl_readiness', 'ixbrl_accounts_disclosures', 'ixbrl_accounts_mapping', 'ixbrl_facts_preview', 'ixbrl_generation'],
            'journal' => ['journals_list', 'journal_cut_offs', 'journal_cut_off_confirmation'],
            'minutes' => ['company_minutes'],
            'nominals' => ['nominals_accounts', 'nominals_add_account', 'nominals_categories', 'nominals_add_category', 'nominals_account_types', 'nominals_import_export', 'nominal_opening_balances', 'nominal_closing_balances'],
            'prepayments' => ['prepayments_review', 'year_end_prepayment_approvals'],
            'profit_loss' => ['pl_summary', 'pl_monthly_trend', 'pl_income_breakdown', 'pl_expense_breakdown', 'pl_net_profit_bridge', 'pl_source_coverage', 'reserve_review', 'year_end_profit_loss_confirm'],
            'source_accounts' => ['banking_accounts', 'banking_reconciliation', 'banking_account_form', 'statement_field_mapping'],
            'corporation_tax' => ['tax_period_selector', 'tax_corporation_tax_summary', 'tax_taxable_profit_bridge', 'tax_prepayment_treatment', 'tax_disallowable_add_backs', 'tax_capital_add_backs', 'tax_depreciation_add_back', 'tax_capital_allowances_summary', 'tax_aia_allocation', 'tax_main_rate_pool', 'tax_special_rate_pool', 'tax_car_co2_treatment', 'tax_disposals_balancing', 'tax_losses', 'tax_rate_bands', 'tax_warnings', 'tax_ct_period_facts', 'year_end_tax_readiness'],
            'tax_audit' => ['tax_audit_areas', 'tax_audit_detail'],
            'tax_artifacts' => ['tax_rates_ct', 'tax_rates_ct600_rim', 'tax_rates_ct_computation_taxonomy', 'tax_rates_vat', 'tax_thresholds_vat', 'tax_treatment_rules'],
            'ct_filing_mappings' => ['tax_ct600_rim_mappings', 'tax_ct_computation_mappings'],
            'transactions' => ['transactions_monthly_status', 'transaction_category_audit_log', 'transactions_imported', 'transaction_search', 'transactions_rules', 'transactions_rule_form', 'nominals_add_account', 'year_end_empty_month_confirmations', 'year_end_transaction_tail'],
            'trial_balance' => ['trial_balance_state', 'trial_balance_validation', 'trial_balance_losses'],
            'uploads' => ['uploads_statement_coverage', 'uploads_bank_transactions', 'transactions_monthly_status', 'uploads_details', 'statement_field_mapping', 'uploads_validate_commit', 'csv_export'],
            'vat' => ['vat_turnover_monitoring', 'vat_registration', 'vat_readiness'],
            'vehicles' => ['vehicle_register'],
            'year_end' => ['year_end_checklist', 'year_end_notes', 'year_end_state', 'year_end_audit_log'],
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
            'tax_rates_ct_computation_taxonomy' => 'Artifact catalogue administration workflow.',
            'tax_ct600_rim_mappings' => 'Mapping lifecycle administration workflow.',
            'tax_ct_computation_mappings' => 'Mapping lifecycle administration workflow.',
        ];
    }

    /** @return array<string, array{page: string, result: string}> */
    public static function semanticContracts(): array
    {
        return [
            'journals_list' => ['page' => 'journal', 'result' => 'journals'],
            'director_loan_state' => ['page' => 'loans', 'result' => 'director_loan'],
            'trial_balance_state' => ['page' => 'trial_balance', 'result' => 'trial_balance'],
            'trial_balance_validation' => ['page' => 'trial_balance', 'result' => 'trial_balance_validation'],
            'trial_balance_losses' => ['page' => 'trial_balance', 'result' => 'trial_balance'],
            'pl_summary' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_income_breakdown' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_expense_breakdown' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'pl_net_profit_bridge' => ['page' => 'profit_loss', 'result' => 'profit_loss'],
            'tax_corporation_tax_summary' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_taxable_profit_bridge' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_rate_bands' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_disallowable_add_backs' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_capital_add_backs' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_depreciation_add_back' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_capital_allowances_summary' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_aia_allocation' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_main_rate_pool' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_special_rate_pool' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_car_co2_treatment' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_disposals_balancing' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_losses' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
            'tax_warnings' => ['page' => 'corporation_tax', 'result' => 'corporation_tax'],
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
