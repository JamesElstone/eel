<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

/**
 * Adds isolated warning and completed-workflow variants around the core golden ledger.
 *
 * The rows in this class deliberately live outside company 9100 so the independent
 * four-period accounting oracle remains stable while data-bearing workflow cards can
 * exercise positive, incomplete, historical and locked states.
 */
final class GoldenWorkflowCoverageFixture
{
    public const WARNING_PERIOD_ID = 9311;
    public const COMPLETE_PRIOR_PERIOD_ID = 9410;
    public const COMPLETE_PERIOD_ID = 9411;
    public const COMPLETE_NEXT_PERIOD_ID = 9412;

    public static function seed(): void
    {
        self::seedAdditionalNominals();
        self::seedVatReferenceData();
        self::seedScenarioCompanyProfiles();
        self::seedScenarioAccountsAndSettings();
        self::seedWarningWorkflow();
        self::seedCompleteBankingAndTransactions();
        self::seedCompleteClaimsSharesAndDividends();
        self::seedCompleteTaxSubmissionAndIxbrl();
        self::seedCompleteAssetsAndHmrcEvidence();
        self::seedCompleteCompaniesHouseAndYearEnd();
    }

    /** @return array<string, array{pages: list<string>, evidence: list<array{label: string, sql: string, minimum: int}>}> */
    public static function coverageManifest(): array
    {
        return [
            'company_and_dashboard' => [
                'pages' => ['companies', 'dashboard'],
                'evidence' => [
                    self::evidence('scenario companies', 'SELECT COUNT(*) FROM companies WHERE id IN (9200, 9300, 9400)', 3),
                    self::evidence('scenario activity history', 'SELECT COUNT(*) FROM application_activity_flash_history WHERE page_id = \'year_end\' AND message_text LIKE \'GOLDEN-TEST%\'', 1),
                ],
            ],
            'banking_and_uploads' => [
                'pages' => ['source_accounts', 'uploads'],
                'evidence' => [
                    self::evidence('multi-account scenarios', 'SELECT COUNT(*) FROM company_accounts WHERE company_id IN (9300, 9400)', 3),
                    self::evidence('staged and completed uploads', 'SELECT COUNT(*) FROM statement_uploads WHERE company_id IN (9300, 9400)', 3),
                    self::evidence('statement mappings', 'SELECT COUNT(*) FROM statement_import_mappings WHERE upload_id IN (9340, 9440)', 2),
                    self::evidence('valid and invalid import rows', 'SELECT COUNT(*) FROM statement_import_rows WHERE upload_id IN (9340, 9440)', 3),
                ],
            ],
            'transaction_lifecycle' => [
                'pages' => ['transactions'],
                'evidence' => [
                    self::evidence('categorisation rules', 'SELECT COUNT(*) FROM categorisation_rules WHERE company_id = 9400', 1),
                    self::evidence('category audit entries', 'SELECT COUNT(*) FROM transaction_category_audit WHERE transaction_id BETWEEN 9460 AND 9470', 1),
                    self::evidence('auto-approval states', 'SELECT COUNT(*) FROM transaction_auto_approvals WHERE transaction_id BETWEEN 9460 AND 9470', 1),
                    self::evidence('transaction split lines', 'SELECT COUNT(*) FROM transaction_split_lines WHERE split_id = 9498', 2),
                    self::evidence('inter-account matches', 'SELECT COUNT(*) FROM transaction_inter_ac_marker WHERE company_id = 9400', 1),
                ],
            ],
            'expense_claims' => [
                'pages' => ['expense_claims'],
                'evidence' => [
                    self::evidence('draft and posted claims', 'SELECT COUNT(*) FROM expense_claims WHERE company_id IN (9300, 9400)', 2),
                    self::evidence('multi-line claim', 'SELECT COUNT(*) FROM expense_claim_lines WHERE expense_claim_id = 9510', 2),
                    self::evidence('claim payment link', 'SELECT COUNT(*) FROM expense_claim_payment_links WHERE expense_claim_id = 9510', 1),
                ],
            ],
            'assets_and_vehicles' => [
                'pages' => ['assets', 'vehicles'],
                'evidence' => [
                    self::evidence('transaction, claim, split and manual assets', 'SELECT COUNT(*) FROM asset_register WHERE company_id = 9400', 4),
                    self::evidence('asset disposal link', 'SELECT COUNT(*) FROM asset_disposal_transaction_links WHERE asset_id = 9560', 1),
                    self::evidence('expense-backed asset candidate', 'SELECT COUNT(*) FROM expense_claim_line_assets WHERE generated_asset_id = 9561', 1),
                    self::evidence('depreciation entries', 'SELECT COUNT(*) FROM asset_depreciation_entries WHERE asset_id = 9560', 1),
                    self::evidence('reviewed and warning vehicles', 'SELECT COUNT(*) FROM asset_vehicle_details WHERE company_id IN (9100, 9300, 9400)', 3),
                ],
            ],
            'journals_and_year_end' => [
                'pages' => ['journal', 'year_end'],
                'evidence' => [
                    self::evidence('tagged year-end adjustment pair', 'SELECT COUNT(*) FROM journal_entry_metadata WHERE company_id = 9400 AND journal_tag IN (\'year_end_adjustment\', \'year_end_adjustment_reversal\')', 2),
                    self::evidence('locked completed review', 'SELECT COUNT(*) FROM year_end_reviews WHERE company_id = 9400 AND accounting_period_id = 9411 AND is_locked = 1', 1),
                    self::evidence('review acknowledgements', 'SELECT COUNT(*) FROM year_end_review_acknowledgements WHERE company_id = 9400 AND accounting_period_id = 9411', 3),
                    self::evidence('year-end audit history', 'SELECT COUNT(*) FROM year_end_audit_log WHERE company_id = 9400 AND accounting_period_id = 9411', 1),
                    self::evidence('empty-month confirmation', 'SELECT COUNT(*) FROM accounting_period_month_confirmations WHERE company_id = 9400 AND accounting_period_id = 9411', 1),
                ],
            ],
            'prepayments' => [
                'pages' => ['prepayments'],
                'evidence' => [
                    self::evidence('prepayment reviews', 'SELECT COUNT(*) FROM prepayment_reviews WHERE company_id = 9100', 2),
                    self::evidence('prepayment postings', 'SELECT COUNT(*) FROM prepayment_schedule_postings psp INNER JOIN prepayment_schedules ps ON ps.id = psp.schedule_id INNER JOIN prepayment_reviews pr ON pr.id = ps.review_id WHERE pr.company_id = 9100', 1),
                ],
            ],
            'loans' => [
                'pages' => ['loans'],
                'evidence' => [
                    self::evidence('director-loan journal lines', 'SELECT COUNT(*) FROM journal_lines jl INNER JOIN journals j ON j.id = jl.journal_id WHERE j.company_id IN (9100, 9400) AND jl.nominal_account_id IN (91005, 91006)', 2),
                ],
            ],
            'dividends_incorporation_and_minutes' => [
                'pages' => ['dividends', 'incorporation', 'minutes'],
                'evidence' => [
                    self::evidence('dividend voucher and minutes', 'SELECT COUNT(*) FROM dividend_vouchers WHERE company_id = 9400', 1),
                    self::evidence('dividend reserve snapshot', 'SELECT COUNT(*) FROM dividend_reserve_review_snapshots WHERE company_id = 9400', 1),
                    self::evidence('share capital record', 'SELECT COUNT(*) FROM company_incorporation_share_classes WHERE company_id = 9400', 1),
                    self::evidence('share payment match', 'SELECT COUNT(*) FROM company_incorporation_share_payment_matches WHERE company_id = 9400', 1),
                ],
            ],
            'reporting_and_tax' => [
                'pages' => ['trial_balance', 'profit_loss', 'corporation_tax', 'tax_audit', 'tax_artifacts', 'ct_filing_mappings'],
                'evidence' => [
                    self::evidence('corporation-tax periods', 'SELECT COUNT(*) FROM corporation_tax_periods WHERE company_id IN (9100, 9400)', 2),
                    self::evidence('persisted computation runs', 'SELECT COUNT(*) FROM corporation_tax_computation_runs WHERE company_id = 9400', 2),
                    self::evidence('tax loss history', 'SELECT COUNT(*) FROM tax_loss_movement_history WHERE company_id = 9400', 2),
                    self::evidence('capital allowance pools', 'SELECT COUNT(*) FROM capital_allowance_pool_runs WHERE company_id = 9400', 2),
                    self::evidence('asset allowance calculations', 'SELECT COUNT(*) FROM capital_allowance_asset_calculations WHERE company_id = 9400', 5),
                ],
            ],
            'vat_boundary' => [
                'pages' => ['vat'],
                'evidence' => [
                    self::evidence('confirmed LIVE VAT scenario', 'SELECT COUNT(*) FROM companies WHERE id = 9300 AND is_vat_registered = 1 AND vat_validation_status = \'valid\' AND vat_validation_mode = \'LIVE\'', 1),
                    self::evidence('VAT rate reference rows', 'SELECT COUNT(*) FROM vat_rate_rules WHERE is_active = 1', 1),
                    self::evidence('VAT threshold reference rows', 'SELECT COUNT(*) FROM vat_threshold_rules WHERE is_active = 1', 1),
                ],
            ],
            'hmrc_obligations_and_submission' => [
                'pages' => ['HMRC'],
                'evidence' => [
                    self::evidence('open, overdue and paid obligations', 'SELECT COUNT(*) FROM hmrc_obligations WHERE company_id IN (9100, 9300, 9400)', 4),
                    self::evidence('obligation evidence', 'SELECT COUNT(*) FROM hmrc_obligation_evidence_links WHERE hmrc_obligation_id = 9580', 1),
                    self::evidence('CT600 submission history', 'SELECT COUNT(*) FROM hmrc_ct600_submissions WHERE company_id = 9400', 1),
                    self::evidence('submission events', 'SELECT COUNT(*) FROM hmrc_submission_events WHERE submission_id = 9544', 1),
                ],
            ],
            'companies_house_and_ixbrl' => [
                'pages' => ['companies_house', 'disclosures'],
                'evidence' => [
                    self::evidence('stored Companies House filing', 'SELECT COUNT(*) FROM companies_house_documents WHERE company_id = 9400', 1),
                    self::evidence('stored filing facts', 'SELECT COUNT(*) FROM companies_house_document_facts WHERE document_fk = 9590', 1),
                    self::evidence('iXBRL generation run', 'SELECT COUNT(*) FROM ixbrl_generation_runs WHERE company_id = 9400', 1),
                    self::evidence('iXBRL generated facts', 'SELECT COUNT(*) FROM ixbrl_generation_facts WHERE run_id = 9550', 1),
                ],
            ],
            'nominal_configuration' => [
                'pages' => ['nominals'],
                'evidence' => [
                    self::evidence('all primary account types', 'SELECT COUNT(DISTINCT account_type) FROM nominal_accounts WHERE id BETWEEN 91001 AND 91026', 6),
                    self::evidence('complete-company nominal settings', 'SELECT COUNT(*) FROM company_settings WHERE company_id = 9400', 10),
                ],
            ],
        ];
    }

    private static function seedAdditionalNominals(): void
    {
        $subtypeIds = [];
        foreach (['bank', 'capital_reserves', 'fixed_asset', 'overhead'] as $code) {
            $subtypeIds[$code] = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
                ['code' => $code]
            );
        }

        foreach ([
            [91020, 'G101', 'Golden Test Savings Bank', 'asset', 'other', $subtypeIds['bank']],
            [91021, '2100', 'Golden Test Trade Creditors', 'liability', 'other', null],
            [91022, '2150', 'Golden Test Dividends Payable', 'liability', 'other', null],
            [91023, '2200', 'Golden Test VAT Control', 'liability', 'other', null],
            [91024, '3100', 'Golden Test Share Capital', 'equity', 'other', $subtypeIds['capital_reserves']],
            [91025, '6250', 'Golden Test Asset Disposal Loss', 'expense', 'allowable', $subtypeIds['overhead']],
            [91026, '9999', 'Golden Test Uncategorised', 'expense', 'other', $subtypeIds['overhead']],
            [91027, '1321', 'Golden Test Motor Vehicles - Cars', 'asset', 'capital', $subtypeIds['fixed_asset']],
        ] as [$id, $code, $name, $accountType, $taxTreatment, $subtypeId]) {
            self::insert('nominal_accounts', [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'account_type' => $accountType,
                'account_subtype_id' => $subtypeId,
                'tax_treatment' => $taxTreatment,
                'is_active' => 1,
                'sort_order' => $id,
            ]);
        }
    }

    private static function seedVatReferenceData(): void
    {
        self::insert('vat_rate_rules', [
            'id' => 9700,
            'rate_type' => 'standard',
            'scope' => 'domestic',
            'effective_from' => '2025-04-01',
            'rate_percentage' => 20.000,
            'original_period_text' => 'From 1 April 2025',
            'source_url' => 'https://www.gov.uk/vat-rates',
            'source_content_id' => 'golden-test-vat-rates',
            'source_checked_at' => '2026-07-15 12:00:00',
            'rule_version' => 'golden-test-v1',
            'dataset_hash' => hash('sha256', 'GOLDEN-TEST-vat-rate-dataset'),
            'is_active' => 1,
            'notes' => 'GOLDEN-TEST deterministic standard VAT reference rate.',
        ]);
        self::insert('vat_threshold_rules', [
            'id' => 9701,
            'threshold_type' => 'registration',
            'jurisdiction' => 'GB',
            'effective_from' => '2024-04-01',
            'original_period_text' => 'From 1 April 2024',
            'registration_threshold' => 90000.00,
            'deregistration_threshold' => 88000.00,
            'source_url' => 'https://www.gov.uk/vat-registration-thresholds',
            'source_content_id' => '00000000-0000-4000-8000-000000009701',
            'source_checked_at' => '2026-07-15 12:00:00',
            'dataset_hash' => hash('sha256', 'GOLDEN-TEST-vat-threshold-dataset'),
            'row_hash' => hash('sha256', 'GOLDEN-TEST-vat-threshold-row'),
            'is_active' => 1,
            'audit_notes' => 'GOLDEN-TEST deterministic VAT registration threshold.',
        ]);
    }

    private static function seedScenarioCompanyProfiles(): void
    {
        InterfaceDB::execute(
            'UPDATE companies
             SET is_vat_registered = 1,
                 vat_country_code = :country,
                 vat_number = :vat_number,
                 vat_validation_status = :validation_status,
                 vat_validated_at = :validated_at,
                 vat_validation_source = :validation_source,
                 vat_validation_mode = :validation_mode,
                 vat_validation_name = :validation_name,
                 vat_validation_address_line1 = :address,
                 vat_validation_postcode = :postcode,
                 vat_validation_country_code = :validation_country
             WHERE id = :company_id',
            [
                'country' => 'GB',
                'vat_number' => 'GB999999973',
                'validation_status' => 'valid',
                'validated_at' => '2026-01-15 10:00:00',
                'validation_source' => 'hmrc',
                'validation_mode' => 'LIVE',
                'validation_name' => 'Warning Scenario Test Limited',
                'address' => '1 Synthetic Warning Way',
                'postcode' => 'TE5 7GB',
                'validation_country' => 'GB',
                'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            ]
        );

        InterfaceDB::execute(
            'UPDATE companies
             SET companies_house_type = :company_type,
                 companies_house_jurisdiction = :jurisdiction,
                 registered_office_address_line_1 = :address,
                 registered_office_locality = :locality,
                 registered_office_postal_code = :postcode,
                 registered_office_country = :country,
                 can_file = 1,
                 companies_house_environment = :environment,
                 companies_house_last_checked_at = :checked_at,
                 companies_house_active_director_count = 1
             WHERE id = :company_id',
            [
                'company_type' => 'ltd',
                'jurisdiction' => 'england-wales',
                'address' => '1 Golden Completion Close',
                'locality' => 'Testford',
                'postcode' => 'TE5 7OK',
                'country' => 'United Kingdom',
                'environment' => 'TEST',
                'checked_at' => '2026-09-30 12:00:00',
                'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            ]
        );

        self::insert('accounting_periods', [
            'id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'label' => '01/10/2024 to 30/09/2025',
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
        ]);
        self::insert('accounting_periods', [
            'id' => self::COMPLETE_NEXT_PERIOD_ID,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'label' => '01/10/2026 to 30/09/2027',
            'period_start' => '2026-10-01',
            'period_end' => '2027-09-30',
        ]);
    }

    private static function seedScenarioAccountsAndSettings(): void
    {
        self::insert('company_accounts', [
            'id' => 9320,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'account_name' => 'Warning Current Account',
            'account_type' => 'bank',
            'institution_name' => 'Synthetic Warning Bank',
            'account_identifier' => 'TEST-WARNING-0001',
            'nominal_account_id' => 91001,
            'is_active' => 1,
        ]);
        self::insert('company_accounts', [
            'id' => 9420,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'account_name' => 'Complete Current Account',
            'account_type' => 'bank',
            'institution_name' => 'Synthetic Complete Bank',
            'account_identifier' => 'TEST-COMPLETE-0001',
            'nominal_account_id' => 91001,
            'internal_transfer_marker' => 'GOLDEN-CURRENT',
            'is_active' => 1,
        ]);
        self::insert('company_accounts', [
            'id' => 9421,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'account_name' => 'Complete Savings Account',
            'account_type' => 'bank',
            'institution_name' => 'Synthetic Complete Bank',
            'account_identifier' => 'TEST-COMPLETE-0002',
            'nominal_account_id' => 91020,
            'internal_transfer_marker' => 'GOLDEN-SAVINGS',
            'is_active' => 1,
        ]);
        self::insert('expense_claimants', [
            'id' => 9330,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'claimant_name' => 'Warning Scenario Claimant',
            'is_active' => 1,
        ]);
        self::insert('expense_claimants', [
            'id' => 9430,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'claimant_name' => 'Complete Scenario Director',
            'is_active' => 1,
        ]);

        foreach ([
            'utr' => ['int', '1234567890'],
            'associated_company_count' => ['int', '0'],
            'default_currency' => ['char', 'GBP'],
            'default_bank_nominal_id' => ['int', '91001'],
            'default_sales_nominal_id' => ['int', '91002'],
            'default_trade_nominal_id' => ['int', '91021'],
            'default_expense_nominal_id' => ['int', '91004'],
            'tools_small_equipment_nominal_id' => ['int', '91004'],
            'prepayment_asset_nominal_id' => ['int', '91018'],
            'director_loan_nominal_id' => ['int', '91005'],
            'director_loan_asset_nominal_id' => ['int', '91006'],
            'director_loan_liability_nominal_id' => ['int', '91005'],
            'vat_nominal_id' => ['int', '91023'],
            'uncategorised_nominal_id' => ['int', '91026'],
            'corporation_tax_expense_nominal_id' => ['int', '91008'],
            'corporation_tax_liability_nominal_id' => ['int', '91009'],
            'dividends_payable_nominal_id' => ['int', '91022'],
            'plant_machinery_asset_cost_nominal_id' => ['int', '91013'],
            'plant_machinery_accum_dep_nominal_id' => ['int', '91014'],
            'hmrc_mode' => ['char', 'TEST'],
            'lock_posted_periods' => ['bool', '1'],
        ] as $setting => [$type, $value]) {
            self::insert('company_settings', [
                'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
                'setting' => $setting,
                'type' => $type,
                'value' => $value,
            ]);
        }
    }

    private static function seedWarningWorkflow(): void
    {
        self::insert('statement_uploads', [
            'id' => 9340,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'account_id' => 9320,
            'source_type' => 'bank_account',
            'workflow_status' => 'staged',
            'statement_month' => '2026-01-01',
            'original_filename' => 'GOLDEN-TEST-warning-staged.csv',
            'stored_filename' => 'golden-test-warning-staged.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-TEST-warning-staged'),
            'date_range_start' => '2026-01-01',
            'date_range_end' => '2026-01-31',
            'rows_parsed' => 2,
            'rows_inserted' => 2,
            'rows_valid' => 1,
            'rows_invalid' => 1,
            'rows_ready_to_import' => 1,
            'last_staged_at' => '2026-02-01 09:00:00',
        ]);
        self::insert('statement_import_mappings', [
            'id' => 9342,
            'upload_id' => 9340,
            'source_type' => 'bank_account',
            'mapping_origin' => 'manual',
            'original_headers_json' => json_encode(['Date', 'Description', 'Amount', 'Balance']),
            'mapping_json' => json_encode(['date' => 'Date', 'description' => 'Description', 'amount' => 'Amount', 'balance' => 'Balance']),
            'confirmed_at' => '2026-02-01 08:55:00',
        ]);
        self::insert('statement_import_rows', [
            'id' => 9343,
            'upload_id' => 9340,
            'row_number' => 1,
            'raw_json' => json_encode(['Date' => '2026-01-15', 'Description' => 'Valid warning row', 'Amount' => '-120.00']),
            'source_description' => 'Valid warning row',
            'source_amount' => '-120.00',
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'chosen_txn_date' => '2026-01-15',
            'chosen_date_source' => 'processed',
            'normalised_description' => 'Valid warning row',
            'normalised_amount' => -120.00,
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'GOLDEN-TEST-warning-row-1'),
            'validation_status' => 'valid',
        ]);
        self::insert('statement_import_rows', [
            'id' => 9344,
            'upload_id' => 9340,
            'row_number' => 2,
            'raw_json' => json_encode(['Date' => '', 'Description' => 'Missing date warning row', 'Amount' => '-10.00']),
            'source_description' => 'Missing date warning row',
            'source_amount' => '-10.00',
            'normalised_description' => 'Missing date warning row',
            'normalised_amount' => -10.00,
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'GOLDEN-TEST-warning-row-2'),
            'validation_status' => 'invalid',
            'validation_notes' => 'GOLDEN-TEST missing transaction date.',
        ]);
        self::insert('statement_uploads', [
            'id' => 9341,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'account_id' => 9320,
            'source_type' => 'bank_account',
            'workflow_status' => 'completed',
            'statement_month' => '2025-12-01',
            'original_filename' => 'GOLDEN-TEST-warning-completed.csv',
            'stored_filename' => 'golden-test-warning-completed.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-TEST-warning-completed'),
            'date_range_start' => '2025-12-01',
            'date_range_end' => '2025-12-31',
            'rows_parsed' => 1,
            'rows_inserted' => 1,
            'rows_valid' => 1,
            'rows_committed' => 1,
            'committed_at' => '2026-01-01 09:00:00',
        ]);
        self::transaction(9360, GoldenAccountsFixture::WARNING_COMPANY_ID, self::WARNING_PERIOD_ID, 9341, 9320, '2025-12-15', -120.00, null, 'uncategorised', [
            'description' => 'GOLDEN-TEST uncategorised supplier payment',
            'document_download_status' => 'failed',
            'document_error' => 'Synthetic missing receipt.',
        ]);
        self::insert('expense_claims', [
            'id' => 9350,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'claimant_id' => 9330,
            'claim_year' => 2026,
            'claim_month' => 2,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'claim_reference_code' => 'GOLDEN-WARNING-CLAIM',
            'claimed_amount' => 75.00,
            'carried_forward_amount' => 75.00,
            'status' => 'draft',
            'notes' => 'GOLDEN-TEST draft claim with missing receipt evidence.',
        ]);
        self::insert('expense_claim_lines', [
            'id' => 9351,
            'expense_claim_id' => 9350,
            'line_number' => 1,
            'expense_date' => '2026-02-15',
            'description' => 'GOLDEN-TEST warning expense without receipt',
            'amount' => 75.00,
            'nominal_account_id' => 91004,
        ]);
        self::insert('hmrc_obligations', [
            'id' => 9370,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'obligation_type' => 'ct_payment',
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'notice_date' => '2026-01-05',
            'due_date' => '2026-01-31',
            'amount_due' => 250.00,
            'amount_paid' => 0.00,
            'status' => 'overdue',
            'source' => 'hmrc_notice',
            'source_reference' => 'GOLDEN-WARNING-OVERDUE-CT',
            'notes' => 'GOLDEN-TEST overdue obligation.',
        ]);
        self::insert('asset_register', [
            'id' => 9361,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'asset_code' => 'GOLDEN-WARNING-CAR',
            'description' => 'GOLDEN-TEST vehicle awaiting tax review',
            'category' => 'car',
            'nominal_account_id' => 91027,
            'accum_dep_nominal_id' => 91016,
            'purchase_date' => '2026-02-01',
            'cost' => 500.00,
            'useful_life_years' => 4,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
            'manual_addition_reason' => 'opening_balance',
            'manual_offset_nominal_id' => 91007,
            'manual_evidence_path' => 'GOLDEN-TEST/evidence/warning-car.pdf',
            'manual_evidence_sha256' => hash('sha256', 'GOLDEN-TEST-warning-car-evidence'),
            'manual_evidence_original_filename' => 'golden-test-warning-car.pdf',
            'manual_evidence_content_type' => 'application/pdf',
            'manual_evidence_size_bytes' => 512,
            'manual_legal_warning_version' => 'golden-test-v1',
            'manual_legal_acknowledged_at' => '2026-02-01 09:00:00',
        ]);
        self::insert('asset_vehicle_details', [
            'asset_id' => 9361,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'vehicle_type' => 'car',
            'registration_mark' => 'WARN 9300',
            'make_model' => 'Synthetic Unreviewed Car',
            'colour' => 'Grey',
            'first_registered_date' => '2024-02-01',
            'acquisition_condition' => null,
            'is_zero_emission' => 0,
            'co2_emissions_g_km' => null,
            'contract_date' => '2026-02-01',
            'tax_review_status' => 'unreviewed',
            'notes' => 'GOLDEN-TEST deliberately incomplete vehicle tax evidence.',
        ]);
        self::insert('year_end_reviews', [
            'id' => 9380,
            'company_id' => GoldenAccountsFixture::WARNING_COMPANY_ID,
            'accounting_period_id' => self::WARNING_PERIOD_ID,
            'is_locked' => 0,
            'review_notes' => 'GOLDEN-TEST warning workflow remains incomplete.',
        ]);
    }

    private static function seedCompleteBankingAndTransactions(): void
    {
        self::insert('statement_uploads', [
            'id' => 9440,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'account_id' => 9420,
            'source_type' => 'bank_account',
            'workflow_status' => 'completed',
            'statement_month' => '2026-09-01',
            'original_filename' => 'GOLDEN-TEST-complete-current.csv',
            'stored_filename' => 'golden-test-complete-current.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-TEST-complete-current'),
            'date_range_start' => '2025-10-01',
            'date_range_end' => '2026-09-30',
            'rows_parsed' => 10,
            'rows_inserted' => 10,
            'rows_valid' => 10,
            'rows_committed' => 10,
            'committed_at' => '2026-09-30 12:00:00',
        ]);
        self::insert('statement_uploads', [
            'id' => 9441,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'account_id' => 9421,
            'source_type' => 'bank_account',
            'workflow_status' => 'completed',
            'statement_month' => '2026-09-01',
            'original_filename' => 'GOLDEN-TEST-complete-savings.csv',
            'stored_filename' => 'golden-test-complete-savings.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-TEST-complete-savings'),
            'date_range_start' => '2025-10-01',
            'date_range_end' => '2026-09-30',
            'rows_parsed' => 1,
            'rows_inserted' => 1,
            'rows_valid' => 1,
            'rows_committed' => 1,
            'committed_at' => '2026-09-30 12:00:00',
        ]);
        self::insert('statement_import_mappings', [
            'id' => 9442,
            'upload_id' => 9440,
            'source_type' => 'bank_account',
            'mapping_origin' => 'auto',
            'original_headers_json' => json_encode(['Date', 'Description', 'Reference', 'Amount', 'Balance']),
            'mapping_json' => json_encode(['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount', 'balance' => 'Balance']),
            'confirmed_at' => '2026-09-30 11:00:00',
        ]);
        self::insert('categorisation_rules', [
            'id' => 9495,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'priority' => 10,
            'match_field' => 'description',
            'desc_match_type' => 'contains',
            'desc_match_value' => 'materials',
            'ref_match_type' => 'none',
            'nominal_account_id' => 91003,
            'is_active' => 1,
        ]);

        self::transaction(9460, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2025-10-10', 2000.00, 91002, 'manual', ['description' => 'GOLDEN-TEST completed customer receipt', 'balance' => 2000.00]);
        self::transaction(9461, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2025-10-15', -500.00, 91003, 'auto', ['description' => 'GOLDEN-TEST materials purchase', 'auto_rule_id' => 9495, 'balance' => 1500.00]);
        self::transaction(9462, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2025-11-01', -250.00, 91020, 'manual', ['description' => 'GOLDEN-TEST transfer to savings', 'transfer_account_id' => 9421, 'is_internal_transfer' => 1, 'balance' => 1250.00]);
        self::transaction(9463, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9441, 9421, '2025-11-01', 250.00, 91020, 'manual', ['description' => 'GOLDEN-TEST transfer from current', 'transfer_account_id' => 9420, 'is_internal_transfer' => 1, 'balance' => 250.00]);
        self::transaction(9464, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2025-12-01', -300.00, null, 'manual', ['description' => 'GOLDEN-TEST split tools and overhead', 'balance' => 950.00]);
        self::transaction(9465, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-01-05', 100.00, 91024, 'manual', ['description' => 'GOLDEN-TEST incorporation share payment', 'balance' => 1050.00]);
        self::transaction(9466, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-02-01', -250.00, 91007, 'manual', ['description' => 'GOLDEN-TEST dividend payment', 'balance' => 800.00]);
        self::transaction(9467, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-03-01', -900.00, 91005, 'manual', ['description' => 'GOLDEN-TEST expense claim repayment', 'balance' => -100.00]);
        self::transaction(9468, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-04-01', -1000.00, 91013, 'manual', ['description' => 'GOLDEN-TEST asset acquisition', 'balance' => -1100.00]);
        self::transaction(9469, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-09-01', 400.00, 91013, 'manual', ['description' => 'GOLDEN-TEST asset disposal receipt', 'balance' => -700.00]);
        self::transaction(9470, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, 9440, 9420, '2026-09-15', -75.00, 91012, 'manual', ['description' => 'GOLDEN-TEST HMRC obligation payment', 'balance' => -775.00]);

        self::insert('statement_import_rows', [
            'id' => 9443,
            'upload_id' => 9440,
            'row_number' => 1,
            'raw_json' => json_encode(['Date' => '2025-10-10', 'Description' => 'Completed customer receipt', 'Amount' => '2000.00']),
            'source_description' => 'Completed customer receipt',
            'source_amount' => '2000.00',
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'chosen_txn_date' => '2025-10-10',
            'chosen_date_source' => 'processed',
            'normalised_description' => 'Completed customer receipt',
            'normalised_amount' => 2000.00,
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'GOLDEN-TEST-complete-row-1'),
            'validation_status' => 'valid',
            'committed_transaction_id' => 9460,
            'committed_at' => '2026-09-30 12:00:00',
        ]);

        self::journal(9480, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2025-10-10', 'bank_csv', 'transaction:9460', 'GOLDEN-TEST customer receipt', [[91001, 2000.00, 0.00], [91002, 0.00, 2000.00]]);
        self::journal(9481, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2025-10-15', 'bank_csv', 'transaction:9461', 'GOLDEN-TEST materials purchase', [[91003, 500.00, 0.00], [91001, 0.00, 500.00]]);
        self::journal(9482, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2025-11-01', 'bank_csv', 'transaction:9462', 'GOLDEN-TEST one-sided transfer journal', [[91020, 250.00, 0.00], [91001, 0.00, 250.00]]);
        self::journal(9483, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2025-12-01', 'bank_csv', 'transaction:9464', 'GOLDEN-TEST split transaction journal', [[91013, 100.00, 0.00], [91004, 200.00, 0.00], [91001, 0.00, 300.00]]);
        self::journal(9484, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-01-05', 'bank_csv', 'transaction:9465', 'GOLDEN-TEST share capital receipt', [[91001, 100.00, 0.00], [91024, 0.00, 100.00]]);
        self::journal(9485, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-02-01', 'bank_csv', 'transaction:9466', 'GOLDEN-TEST dividend payment', [[91007, 250.00, 0.00], [91001, 0.00, 250.00]]);
        self::journal(9487, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-03-01', 'bank_csv', 'transaction:9467', 'GOLDEN-TEST claim repayment', [[91005, 900.00, 0.00], [91001, 0.00, 900.00]]);
        self::journal(9488, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-04-01', 'bank_csv', 'transaction:9468', 'GOLDEN-TEST asset purchase', [[91013, 1000.00, 0.00], [91001, 0.00, 1000.00]]);
        self::journal(9489, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-09-01', 'asset_depreciation', 'asset:9560:depreciation', 'GOLDEN-TEST asset depreciation', [[91017, 200.00, 0.00], [91014, 0.00, 200.00]]);
        self::journal(9490, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-09-01', 'asset_disposal', 'asset:9560:disposal', 'GOLDEN-TEST balanced asset disposal', [[91001, 400.00, 0.00], [91014, 200.00, 0.00], [91025, 400.00, 0.00], [91013, 0.00, 1000.00]]);
        self::journal(9491, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-09-15', 'bank_csv', 'transaction:9470', 'GOLDEN-TEST HMRC payment', [[91012, 75.00, 0.00], [91001, 0.00, 75.00]]);
        self::journal(9492, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-09-30', 'manual', 'golden-year-end-adjustment', 'GOLDEN-TEST year-end accrual', [[91004, 50.00, 0.00], [91021, 0.00, 50.00]], [
            'journal_tag' => 'year_end_adjustment',
            'journal_key' => 'golden-accrual',
            'entry_mode' => 'manual',
        ]);
        self::journal(9493, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_NEXT_PERIOD_ID, '2026-10-01', 'manual', 'golden-year-end-adjustment-reversal', 'GOLDEN-TEST automatic accrual reversal', [[91021, 50.00, 0.00], [91004, 0.00, 50.00]], [
            'journal_tag' => 'year_end_adjustment_reversal',
            'journal_key' => 'reversal-of-9492',
            'entry_mode' => 'system_generated',
            'related_journal_id' => 9492,
        ]);

        self::insert('transaction_category_audit', [
            'id' => 9496,
            'transaction_id' => 9461,
            'new_nominal_account_id' => 91003,
            'old_category_status' => 'uncategorised',
            'new_category_status' => 'auto',
            'new_auto_rule_id' => 9495,
            'changed_by' => 'golden-test',
            'changed_at' => '2025-10-15 12:00:00',
            'reason' => 'GOLDEN-TEST deterministic auto categorisation.',
        ]);
        self::insert('transaction_auto_approvals', [
            'id' => 9497,
            'transaction_id' => 9461,
            'state' => 'confirmed',
            'state_change_at' => '2025-10-15 12:05:00',
            'confirmed_at' => '2025-10-15 12:06:00',
        ]);
        self::insert('transaction_splits', ['id' => 9498, 'transaction_id' => 9464]);
        self::insert('transaction_split_lines', ['id' => 9499, 'split_id' => 9498, 'line_number' => 1, 'description' => 'GOLDEN-TEST capital tool', 'amount' => 100.00, 'nominal_account_id' => 91013]);
        self::insert('transaction_split_lines', ['id' => 9500, 'split_id' => 9498, 'line_number' => 2, 'description' => 'GOLDEN-TEST consumable overhead', 'amount' => 200.00, 'nominal_account_id' => 91004]);
        self::insert('transaction_inter_ac_marker', [
            'id' => 9501,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'transaction_id' => 9462,
            'matched_transaction_id' => 9463,
            'created_by' => 'golden-test',
        ]);
    }

    private static function seedCompleteClaimsSharesAndDividends(): void
    {
        self::journal(9486, GoldenAccountsFixture::COMPLETE_COMPANY_ID, self::COMPLETE_PERIOD_ID, '2026-02-28', 'expense_register', 'golden-complete-claim', 'GOLDEN-TEST posted multi-line claim', [[91004, 300.00, 0.00], [91013, 600.00, 0.00], [91005, 0.00, 900.00]]);
        self::insert('expense_claims', [
            'id' => 9510,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'claimant_id' => 9430,
            'claim_year' => 2026,
            'claim_month' => 2,
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'claim_reference_code' => 'GOLDEN-COMPLETE-CLAIM',
            'claimed_amount' => 900.00,
            'payments_amount' => 900.00,
            'carried_forward_amount' => 0.00,
            'status' => 'posted',
            'posted_journal_id' => 9486,
            'notes' => 'GOLDEN-TEST posted, paid, multi-line claim.',
        ]);
        self::insert('expense_claim_lines', [
            'id' => 9511,
            'expense_claim_id' => 9510,
            'line_number' => 1,
            'expense_date' => '2026-02-10',
            'description' => 'GOLDEN-TEST subsistence with receipt',
            'amount' => 300.00,
            'nominal_account_id' => 91004,
            'receipt_reference' => 'GOLDEN-RECEIPT-COMPLETE-1',
        ]);
        self::insert('expense_claim_lines', [
            'id' => 9512,
            'expense_claim_id' => 9510,
            'line_number' => 2,
            'expense_date' => '2026-02-12',
            'description' => 'GOLDEN-TEST expense-backed test equipment',
            'amount' => 600.00,
            'nominal_account_id' => 91013,
            'receipt_reference' => 'GOLDEN-RECEIPT-COMPLETE-ASSET',
        ]);
        self::insert('expense_claim_payment_links', [
            'id' => 9513,
            'expense_claim_id' => 9510,
            'transaction_id' => 9467,
            'linked_amount' => 900.00,
        ]);

        self::insert('company_incorporation_share_classes', [
            'id' => 9520,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'share_class' => 'Ordinary',
            'currency' => 'GBP',
            'quantity' => 100,
            'nominal_value_per_share' => 1.00,
            'paid_value_per_share' => 1.00,
            'unpaid_value_per_share' => 0.00,
            'source_note' => 'GOLDEN-TEST fully paid incorporation shares.',
            'document_reference' => 'GOLDEN-IN05',
            'status' => 'paid',
        ]);
        self::insert('company_incorporation_share_payment_matches', [
            'id' => 9521,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'share_class_id' => 9520,
            'transaction_id' => 9465,
            'matched_amount' => 100.00,
            'match_status' => 'current',
            'matched_at' => '2026-01-05 12:00:00',
            'matched_by' => 'golden-test',
        ]);
        self::insert('dividend_reserve_classification_rules', [
            'id' => 9530,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'nominal_account_id' => 91007,
            'treatment' => 'distributable',
            'note' => 'GOLDEN-TEST reviewed distributable reserve.',
            'is_active' => 1,
        ]);
        self::insert('dividend_reserve_review_snapshots', [
            'id' => 9531,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'as_at_date' => '2026-01-31',
            'source_hash' => hash('sha256', 'GOLDEN-TEST-dividend-reserve-review'),
            'brought_forward_distributable_reserves' => 1000.00,
            'ledger_profit_loss' => 1500.00,
            'realised_profit_amount' => 1500.00,
            'distributable_current_profit' => 1500.00,
            'dividends_declared' => 250.00,
            'closing_distributable_reserves' => 2250.00,
            'reviewed_at' => '2026-01-31 12:00:00',
            'reviewed_by' => 'golden-test',
            'summary_json' => json_encode(['status' => 'reviewed', 'synthetic' => true]),
        ]);
        self::insert('dividend_vouchers', [
            'id' => 9532,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'journal_id' => 9485,
            'transaction_id' => 9466,
            'company_name' => 'Completed Scenario Test Limited',
            'shareholder_name' => 'Synthetic Shareholder',
            'director_name' => 'Synthetic Director',
            'declaration_date' => '2026-01-31',
            'payment_date' => '2026-02-01',
            'amount' => 250.00,
            'description' => 'GOLDEN-TEST final dividend',
            'voucher_text' => 'GOLDEN-TEST dividend voucher for GBP 250.00.',
            'minutes_text' => 'GOLDEN-TEST board minutes approving a final dividend of GBP 250.00.',
            'issued_at' => '2026-01-31 12:00:00',
            'issued_by' => 'golden-test',
        ]);
    }

    private static function seedCompleteTaxSubmissionAndIxbrl(): void
    {
        $priorComputationHash = hash('sha256', 'GOLDEN-TEST-prior-loss-ct-computation');
        self::insert('corporation_tax_periods', [
            'id' => 9538,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'sequence_no' => 1,
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
            'status' => 'computed',
        ]);
        test_confirm_ct_period_facts(
            GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            self::COMPLETE_PRIOR_PERIOD_ID
        );
        self::insert('corporation_tax_computation_runs', [
            'id' => 9539,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'ct_period_id' => 9538,
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
            'status' => 'generated',
            'computation_hash' => $priorComputationHash,
            'summary_json' => json_encode(self::priorLossCtSummary($priorComputationHash)),
            'generated_path' => 'GOLDEN-TEST/ct/computation-9539.json',
            'generated_at' => '2025-09-30 13:00:00',
        ]);
        InterfaceDB::execute(
            'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :period_id',
            ['run_id' => 9539, 'period_id' => 9538]
        );
        self::insert('year_end_reviews', [
            'id' => 9537,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'is_locked' => 1,
            'locked_at' => '2025-09-30 16:00:00',
            'locked_by' => 'golden-test',
            'review_notes' => 'GOLDEN-TEST prior loss period locked before the completed period.',
        ]);
        self::insert('tax_loss_movement_history', [
            'id' => 9536,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'ct_period_id' => 9538,
            'computation_hash' => $priorComputationHash,
            'loss_created' => 100.00,
            'loss_brought_forward' => 0.00,
            'loss_utilised' => 0.00,
            'loss_carried_forward' => 100.00,
            'taxable_before_losses' => -100.00,
            'taxable_profit' => 0.00,
            'computed_at' => '2025-09-30 13:00:00',
        ]);

        $currentComputationHash = hash('sha256', 'GOLDEN-TEST-complete-ct-computation');
        self::insert('corporation_tax_periods', [
            'id' => 9540,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'sequence_no' => 1,
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'status' => 'accepted',
        ]);
        test_confirm_ct_period_facts(
            GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            self::COMPLETE_PERIOD_ID
        );
        self::insert('corporation_tax_computation_runs', [
            'id' => 9541,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'status' => 'generated',
            'computation_hash' => $currentComputationHash,
            'summary_json' => json_encode(self::completedCtSummary($currentComputationHash)),
            'generated_path' => 'GOLDEN-TEST/ct/computation-9541.json',
            'generated_at' => '2026-09-30 13:00:00',
        ]);
        InterfaceDB::execute(
            'UPDATE corporation_tax_periods SET latest_computation_run_id = :run_id WHERE id = :period_id',
            ['run_id' => 9541, 'period_id' => 9540]
        );
        self::insert('tax_loss_carryforwards', [
            'id' => 9542,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'origin_accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'origin_ct_period_id' => 9538,
            'amount_originated' => 100.00,
            'amount_used' => 100.00,
            'amount_remaining' => 0.00,
            'status' => 'used',
        ]);
        self::insert('tax_loss_movement_history', [
            'id' => 9543,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'computation_hash' => $currentComputationHash,
            'loss_created' => 0.00,
            'loss_brought_forward' => 100.00,
            'loss_utilised' => 100.00,
            'loss_carried_forward' => 0.00,
            'taxable_before_losses' => 552.00,
            'taxable_profit' => 452.00,
            'computed_at' => '2026-09-30 13:00:00',
        ]);
        self::insert('hmrc_ct600_submissions', [
            'id' => 9544,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'mode' => 'TEST',
            'status' => 'accepted',
            'submission_type' => 'original',
            'ct600_xml_path' => 'GOLDEN-TEST/hmrc/ct600.xml',
            'accounts_ixbrl_path' => 'GOLDEN-TEST/hmrc/accounts.xhtml',
            'computations_ixbrl_path' => 'GOLDEN-TEST/hmrc/computations.xhtml',
            'package_hash' => hash('sha256', 'GOLDEN-TEST-hmrc-package'),
            'hmrc_submission_reference' => 'GOLDEN-TEST-HMRC-ACCEPTED',
            'hmrc_correlation_id' => 'GOLDEN-CORRELATION-9544',
            'hmrc_response_code' => 200,
            'hmrc_response_summary' => 'GOLDEN-TEST synthetic acceptance response.',
            'validation_json' => json_encode(['valid' => true, 'synthetic' => true]),
            'submitted_by' => 'golden-test',
            'submitted_at' => '2026-09-30 14:00:00',
        ]);
        self::insert('hmrc_submission_events', [
            'id' => 9545,
            'submission_id' => 9544,
            'event_level' => 'success',
            'event_message' => 'GOLDEN-TEST submission accepted.',
            'event_context_json' => json_encode(['response_code' => 200, 'synthetic' => true]),
            'created_at' => '2026-09-30 14:00:01',
        ]);
        InterfaceDB::execute(
            'UPDATE corporation_tax_periods SET latest_submission_id = :submission_id WHERE id = :period_id',
            ['submission_id' => 9544, 'period_id' => 9540]
        );

        self::insert('ixbrl_generation_runs', [
            'id' => 9550,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'status' => 'generated',
            'export_type' => 'accounts',
            'taxonomy_profile' => 'uk-frs-105',
            'validation_status' => 'passed',
            'external_validator' => 'GOLDEN-TEST-validator',
            'external_validation_status' => 'passed',
            'generated_filename' => 'golden-test-accounts.xhtml',
            'generated_path' => 'GOLDEN-TEST/ixbrl/golden-test-accounts.xhtml',
            'output_sha256' => hash('sha256', 'GOLDEN-TEST-ixbrl-output'),
            'generated_at' => '2026-09-30 13:30:00',
        ]);
        self::insert('ixbrl_generation_facts', [
            'id' => 9551,
            'run_id' => 9550,
            'fact_key' => 'fixed_assets',
            'taxonomy_concept' => 'core:FixedAssets',
            'label' => 'Fixed assets',
            'value_type' => 'numeric',
            'numeric_value' => 1500.00,
            'unit_ref' => 'GBP',
            'decimals_value' => '2',
            'context_ref' => 'golden-complete-2026',
            'source_json' => json_encode(['synthetic' => true, 'company_id' => 9400]),
        ]);
    }

    private static function seedCompleteAssetsAndHmrcEvidence(): void
    {
        self::insert('asset_register', [
            'id' => 9560,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'asset_code' => 'GOLDEN-COMPLETE-DISPOSAL',
            'description' => 'GOLDEN-TEST disposed workshop equipment',
            'category' => 'tools_equipment',
            'nominal_account_id' => 91013,
            'accum_dep_nominal_id' => 91014,
            'purchase_date' => '2026-04-01',
            'cost' => 1000.00,
            'useful_life_years' => 5,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'disposed',
            'linked_journal_id' => 9488,
            'linked_transaction_id' => 9468,
            'disposal_date' => '2026-09-01',
            'disposal_proceeds' => 400.00,
            'disposal_event_type' => 'sale',
            'disposal_reason' => 'GOLDEN-TEST deterministic sale.',
        ]);
        self::insert('asset_register', [
            'id' => 9561,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'asset_code' => 'GOLDEN-COMPLETE-CLAIM-ASSET',
            'description' => 'GOLDEN-TEST expense claim equipment',
            'category' => 'tools_equipment',
            'nominal_account_id' => 91013,
            'accum_dep_nominal_id' => 91014,
            'purchase_date' => '2026-02-12',
            'cost' => 600.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
            'linked_journal_id' => 9486,
            'linked_expense_claim_line_id' => 9512,
        ]);
        self::insert('asset_register', [
            'id' => 9562,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'asset_code' => 'GOLDEN-COMPLETE-SPLIT-ASSET',
            'description' => 'GOLDEN-TEST split-line capital tool',
            'category' => 'tools_equipment',
            'nominal_account_id' => 91013,
            'accum_dep_nominal_id' => 91014,
            'purchase_date' => '2025-12-01',
            'cost' => 100.00,
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
            'linked_journal_id' => 9483,
            'linked_transaction_split_line_id' => 9499,
        ]);
        self::insert('asset_register', [
            'id' => 9563,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'asset_code' => 'GOLDEN-COMPLETE-HIGH-CO2-CAR',
            'description' => 'GOLDEN-TEST evidenced used high-CO2 car',
            'category' => 'car',
            'nominal_account_id' => 91027,
            'accum_dep_nominal_id' => 91016,
            'purchase_date' => '2025-10-01',
            'cost' => 800.00,
            'useful_life_years' => 4,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'status' => 'active',
            'manual_addition_reason' => 'opening_balance',
            'manual_offset_nominal_id' => 91007,
            'manual_evidence_path' => 'GOLDEN-TEST/evidence/used-high-co2-car.pdf',
            'manual_evidence_sha256' => hash('sha256', 'GOLDEN-TEST-used-high-co2-car-evidence'),
            'manual_evidence_original_filename' => 'golden-test-used-high-co2-car.pdf',
            'manual_evidence_content_type' => 'application/pdf',
            'manual_evidence_size_bytes' => 1024,
            'manual_legal_warning_version' => 'golden-test-v1',
            'manual_legal_acknowledged_at' => '2025-10-01 09:00:00',
        ]);
        self::insert('asset_vehicle_details', [
            'asset_id' => 9563,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'vehicle_type' => 'car',
            'registration_mark' => 'G0LD CAR',
            'make_model' => 'Synthetic Pool Car',
            'colour' => 'Blue',
            'first_registered_date' => '2025-10-01',
            'acquisition_condition' => 'used',
            'is_zero_emission' => 0,
            'co2_emissions_g_km' => 120,
            'contract_date' => '2025-10-01',
            'tax_review_status' => 'reviewed',
            'reviewed_at' => '2025-10-01 10:00:00',
            'reviewed_by' => 'golden-test',
            'notes' => 'GOLDEN-TEST reviewed used high-CO2 special-rate pool car.',
        ]);
        self::insert('expense_claim_line_assets', [
            'id' => 9572,
            'expense_claim_line_id' => 9512,
            'category' => 'tools_equipment',
            'description' => 'GOLDEN-TEST expense-backed asset designation',
            'useful_life_years' => 3,
            'depreciation_method' => 'straight_line',
            'residual_value' => 0.00,
            'generated_asset_id' => 9561,
        ]);
        self::insert('asset_depreciation_entries', [
            'id' => 9570,
            'asset_id' => 9560,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'period_start' => '2026-04-01',
            'period_end' => '2026-09-01',
            'amount' => 200.00,
            'journal_id' => 9489,
        ]);
        self::insert('asset_disposal_transaction_links', [
            'id' => 9571,
            'asset_id' => 9560,
            'transaction_id' => 9469,
            'linked_amount' => 400.00,
        ]);
        self::insert('capital_allowance_pool_runs', [
            'id' => 9573,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'pool_type' => 'main_pool',
            'opening_wdv' => 0.00,
            'additions' => 0.00,
            'aia_claimed' => 1700.00,
            'disposal_value' => 400.00,
            'wda_claimed' => 0.00,
            'balancing_charge' => 400.00,
            'closing_wdv' => 0.00,
            'warnings_json' => json_encode([]),
            'run_hash' => hash('sha256', 'GOLDEN-TEST-main-capital-allowance-pool'),
            'computed_at' => '2026-09-30 13:00:00',
        ]);
        self::insert('capital_allowance_pool_runs', [
            'id' => 9574,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'pool_type' => 'special_rate_pool',
            'opening_wdv' => 0.00,
            'additions' => 800.00,
            'aia_claimed' => 0.00,
            'disposal_value' => 0.00,
            'wda_claimed' => 48.00,
            'closing_wdv' => 752.00,
            'warnings_json' => json_encode([]),
            'run_hash' => hash('sha256', 'GOLDEN-TEST-special-capital-allowance-pool'),
            'computed_at' => '2026-09-30 13:00:00',
        ]);
        self::insert('capital_allowance_asset_calculations', [
            'id' => 9575,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'asset_id' => 9560,
            'pool_type' => 'main_pool',
            'allowance_type' => 'aia',
            'addition_amount' => 1000.00,
            'allowance_amount' => 1000.00,
            'disposal_value' => 0.00,
        ]);
        self::insert('capital_allowance_asset_calculations', [
            'id' => 9576,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'asset_id' => 9560,
            'pool_type' => 'main_pool',
            'allowance_type' => 'disposal_value',
            'addition_amount' => 0.00,
            'allowance_amount' => 0.00,
            'disposal_value' => 400.00,
        ]);
        self::insert('capital_allowance_asset_calculations', [
            'id' => 9577,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'asset_id' => 9561,
            'pool_type' => 'main_pool',
            'allowance_type' => 'aia',
            'addition_amount' => 600.00,
            'allowance_amount' => 600.00,
            'disposal_value' => 0.00,
        ]);
        self::insert('capital_allowance_asset_calculations', [
            'id' => 9578,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'asset_id' => 9562,
            'pool_type' => 'main_pool',
            'allowance_type' => 'aia',
            'addition_amount' => 100.00,
            'allowance_amount' => 100.00,
            'disposal_value' => 0.00,
        ]);
        self::insert('capital_allowance_asset_calculations', [
            'id' => 9579,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_id' => 9540,
            'asset_id' => 9563,
            'pool_type' => 'special_rate_pool',
            'allowance_type' => 'special_rate_pool_addition',
            'addition_amount' => 800.00,
            'allowance_amount' => 0.00,
            'disposal_value' => 0.00,
        ]);

        self::insert('hmrc_obligations', [
            'id' => 9580,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'obligation_type' => 'other',
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'notice_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'amount_due' => 75.00,
            'amount_paid' => 75.00,
            'status' => 'paid',
            'source' => 'bank_match',
            'source_reference' => 'GOLDEN-COMPLETE-HMRC-EVIDENCE',
            'related_journal_id' => 9491,
            'checked_at' => '2026-09-15 12:00:00',
            'notes' => 'GOLDEN-TEST fully evidenced HMRC obligation.',
        ]);
        self::insert('hmrc_obligation_evidence_links', [
            'id' => 9581,
            'hmrc_obligation_id' => 9580,
            'transaction_id' => 9470,
            'allocated_amount' => 75.00,
        ]);
    }

    private static function seedCompleteCompaniesHouseAndYearEnd(): void
    {
        self::insert('companies_house_documents', [
            'id' => 9590,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'company_number' => 'T9400',
            'transaction_id' => 'GOLDEN-TEST-CH-TRANSACTION',
            'filing_date' => '2026-10-01',
            'filing_type' => 'AA',
            'filing_category' => 'accounts',
            'filing_description' => 'GOLDEN-TEST micro-entity accounts',
            'document_id' => 'GOLDEN-TEST-CH-DOCUMENT-9590',
            'metadata_url' => 'https://example.invalid/golden-test/metadata/9590',
            'content_url' => 'https://example.invalid/golden-test/content/9590',
            'final_content_url' => 'https://example.invalid/golden-test/content/9590.xhtml',
            'content_type' => 'application/xhtml+xml',
            'filename' => 'golden-test-accounts-2026.xhtml',
            'classification' => 'accounts',
            'significant_date' => '2026-09-30',
            'significant_date_type' => 'made-up-date',
            'pages' => 8,
            'created_at_utc' => '2026-10-01 09:00:00',
            'fetched_at_utc' => '2026-10-01 10:00:00',
            'raw_metadata_json' => json_encode(['synthetic' => true]),
            'raw_content_hash' => hash('sha256', 'GOLDEN-TEST-companies-house-content'),
            'parse_status' => 'parsed',
        ]);
        self::insert('companies_house_taxonomy_concepts', [
            'id' => 9591,
            'concept_name' => 'uk-core:FixedAssets',
            'short_name' => 'FixedAssets',
            'friendly_label' => 'Fixed assets',
            'value_type' => 'monetary',
            'created_at_utc' => '2026-10-01 10:00:00',
        ]);
        self::insert('companies_house_document_contexts', [
            'id' => 9592,
            'document_fk' => 9590,
            'context_ref' => 'golden-complete-period-2026',
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'is_latest_year_context' => 1,
            'dimension_json' => json_encode([]),
            'created_at_utc' => '2026-10-01 10:00:00',
        ]);
        self::insert('companies_house_document_facts', [
            'id' => 9593,
            'document_fk' => 9590,
            'context_fk' => 9592,
            'concept_fk' => 9591,
            'fact_name' => 'uk-core:FixedAssets',
            'raw_value' => '1500.00',
            'normalised_numeric' => 1500.00,
            'unit_ref' => 'GBP',
            'decimals_value' => '2',
            'is_numeric' => 1,
            'is_latest_year_fact' => 1,
            'created_at_utc' => '2026-10-01 10:00:00',
        ]);

        self::insert('year_end_reviews', [
            'id' => 9600,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'is_locked' => 1,
            'locked_at' => '2026-09-30 16:00:00',
            'locked_by' => 'golden-test',
            'review_notes' => 'GOLDEN-TEST completed and locked workflow.',
        ]);
        foreach ([
            9601 => 'cut_off_journals_review',
            9602 => 'prepayment_approvals',
            9603 => 'companies_house_mismatch_acknowledgement',
        ] as $id => $checkCode) {
            $basis = ['check_code' => $checkCode, 'synthetic' => true, 'company_id' => 9400, 'accounting_period_id' => 9411];
            self::insert('year_end_review_acknowledgements', [
                'id' => $id,
                'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
                'accounting_period_id' => self::COMPLETE_PERIOD_ID,
                'check_code' => $checkCode,
                'acknowledged_at' => '2026-09-30 15:00:00',
                'acknowledged_by' => 'golden-test',
                'note' => 'GOLDEN-TEST deterministic completed review.',
                'basis_version' => 'golden-v1',
                'basis_hash' => hash('sha256', json_encode($basis)),
                'basis_json' => json_encode($basis),
            ]);
        }
        self::insert('year_end_audit_log', [
            'id' => 9604,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'action' => 'lock',
            'action_by' => 'golden-test',
            'action_at' => '2026-09-30 16:00:00',
            'old_value_json' => json_encode(['is_locked' => false]),
            'new_value_json' => json_encode(['is_locked' => true]),
            'notes' => 'GOLDEN-TEST completed Year End lock.',
        ]);
        self::insert('accounting_period_month_confirmations', [
            'id' => 9605,
            'company_id' => GoldenAccountsFixture::COMPLETE_COMPANY_ID,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'month_start' => '2026-08-01',
            'confirmation_type' => 'no_financial_activity',
            'notes' => 'GOLDEN-TEST explicitly confirmed empty month.',
            'evidence_json' => json_encode(['transaction_count' => 0, 'synthetic' => true]),
            'confirmed_at' => '2026-09-01 09:00:00',
            'confirmed_by' => 'golden-test',
        ]);
        self::insert('application_activity_flash_history', [
            'id' => 9606,
            'page_id' => 'year_end',
            'action_name' => 'lock',
            'message_type' => 'success',
            'message_text' => 'GOLDEN-TEST completed Year End lock.',
            'request_method' => 'POST',
            'is_ajax' => 1,
            'device_id' => 'GOLDEN-TEST-DEVICE',
            'ip_address' => '192.0.2.40',
            'user_agent' => 'GOLDEN-TEST synthetic browser',
            'request_uri' => '/?page=year_end',
            'occurred_at' => '2026-09-30 16:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private static function completedCtSummary(string $computationHash): array
    {
        $capitalAllowanceBreakdown = [
            'available' => true,
            'rows' => [
                [
                    'pool_type' => 'main_pool',
                    'opening_wdv' => 0.00,
                    'additions' => 0.00,
                    'aia_claimed' => 1700.00,
                    'fya_claimed' => 0.00,
                    'disposal_value' => 400.00,
                    'wda_claimed' => 0.00,
                    'balancing_charge' => 400.00,
                    'balancing_allowance' => 0.00,
                    'closing_wdv' => 0.00,
                ],
                [
                    'pool_type' => 'special_rate_pool',
                    'opening_wdv' => 0.00,
                    'additions' => 800.00,
                    'aia_claimed' => 0.00,
                    'fya_claimed' => 0.00,
                    'disposal_value' => 0.00,
                    'wda_claimed' => 48.00,
                    'balancing_charge' => 0.00,
                    'balancing_allowance' => 0.00,
                    'closing_wdv' => 752.00,
                ],
            ],
            'warnings' => [],
        ];
        $rateBands = [
            [
                'financial_year' => 'FY2025',
                'taxable_profit' => 225.38,
                'augmented_profit' => 225.38,
                'lower_limit' => 24931.51,
                'upper_limit' => 249315.07,
                'main_rate' => 0.25,
                'small_profits_rate' => 0.19,
                'marginal_relief' => 0.00,
                'liability' => 42.82,
                'basis' => 'small_profits_rate',
                'rule_version' => 'golden-test-v1',
                'source_url' => 'https://www.gov.uk/corporation-tax-rates',
                'source_checked_at' => '2026-07-15 12:00:00',
            ],
            [
                'financial_year' => 'FY2026',
                'taxable_profit' => 226.62,
                'augmented_profit' => 226.62,
                'lower_limit' => 25068.49,
                'upper_limit' => 250684.93,
                'main_rate' => 0.25,
                'small_profits_rate' => 0.19,
                'marginal_relief' => 0.00,
                'liability' => 43.06,
                'basis' => 'small_profits_rate',
                'rule_version' => 'golden-test-v1',
                'source_url' => 'https://www.gov.uk/corporation-tax-rates',
                'source_checked_at' => '2026-07-15 12:00:00',
            ],
        ];

        return [
            'available' => true,
            'accounting_profit' => 1500.00,
            'disallowable_add_backs' => 200.00,
            'capital_add_backs' => 0.00,
            'depreciation_add_back' => 200.00,
            'capital_allowances' => 1348.00,
            'taxable_before_losses' => 552.00,
            'taxable_profit' => 452.00,
            'taxable_loss' => 0.00,
            'estimated_corporation_tax' => 85.88,
            'estimated_rate' => 0.19,
            'associated_company_count' => 0,
            'ct_rate_bands' => $rateBands,
            'loss_created_in_period' => 0.00,
            'losses_brought_forward' => 100.00,
            'losses_used' => 100.00,
            'losses_carried_forward' => 0.00,
            'other_treatment_count' => 0,
            'unknown_treatment_count' => 0,
            'warnings' => [],
            'calculation_status' => 'estimate',
            'confidence_status' => 'ready_for_review',
            'confidence_label' => 'Ready for review',
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => 1500.00],
                ['label' => 'Add back disallowable expenses', 'amount' => 200.00],
                ['label' => 'Add back capital expenditure', 'amount' => 0.00],
                ['label' => 'Add back depreciation', 'amount' => 200.00],
                ['label' => 'Deduct capital allowances', 'amount' => -1348.00],
                ['label' => 'Taxable result before losses', 'amount' => 552.00],
                ['label' => 'Less losses brought forward utilised', 'amount' => -100.00],
                ['label' => 'Taxable profit after losses', 'amount' => 452.00],
                ['label' => 'Estimated corporation tax', 'amount' => 85.88],
            ],
            'schedule' => [
                [
                    'accounting_period_id' => self::COMPLETE_PERIOD_ID,
                    'ct_period_id' => 9540,
                    'label' => 'CT Period 2',
                    'loss_created' => 0.00,
                    'loss_brought_forward' => 100.00,
                    'loss_utilised' => 100.00,
                    'loss_carried_forward' => 0.00,
                    'capital_add_backs' => 0.00,
                    'taxable_before_losses' => 552.00,
                    'taxable_profit' => 452.00,
                ],
            ],
            'ct_period_id' => 9540,
            'accounting_period_id' => self::COMPLETE_PERIOD_ID,
            'ct_period_sequence_no' => 1,
            'ct_period_display_sequence_no' => 2,
            'period_start' => '2025-10-01',
            'period_end' => '2026-09-30',
            'capital_allowance_breakdown' => $capitalAllowanceBreakdown,
            'accounting_allocation_basis' => [
                'method' => 'journal_date_within_single_ct_period',
                'time_apportioned' => false,
                'ct_period_days' => 365,
                'accounting_period_days' => 365,
                'rounding' => 'pennies_half_up',
            ],
            'computation_hash' => $computationHash,
        ];
    }

    /** @return array<string, mixed> */
    private static function priorLossCtSummary(string $computationHash): array
    {
        return [
            'available' => true,
            'accounting_profit' => -100.00,
            'disallowable_add_backs' => 0.00,
            'capital_add_backs' => 0.00,
            'depreciation_add_back' => 0.00,
            'capital_allowances' => 0.00,
            'taxable_before_losses' => -100.00,
            'taxable_profit' => 0.00,
            'taxable_loss' => 100.00,
            'estimated_corporation_tax' => 0.00,
            'estimated_rate' => 0.00,
            'associated_company_count' => 0,
            'ct_rate_bands' => [],
            'loss_created_in_period' => 100.00,
            'losses_brought_forward' => 0.00,
            'losses_used' => 0.00,
            'losses_carried_forward' => 100.00,
            'other_treatment_count' => 0,
            'unknown_treatment_count' => 0,
            'warnings' => [],
            'calculation_status' => 'estimate',
            'confidence_status' => 'ready_for_review',
            'confidence_label' => 'Ready for review',
            'steps' => [
                ['label' => 'Accounting profit or loss', 'amount' => -100.00],
                ['label' => 'Add back disallowable expenses', 'amount' => 0.00],
                ['label' => 'Add back capital expenditure', 'amount' => 0.00],
                ['label' => 'Add back depreciation', 'amount' => 0.00],
                ['label' => 'Deduct capital allowances', 'amount' => 0.00],
                ['label' => 'Taxable result before losses', 'amount' => -100.00],
                ['label' => 'Less losses brought forward utilised', 'amount' => 0.00],
                ['label' => 'Taxable profit after losses', 'amount' => 0.00],
                ['label' => 'Estimated corporation tax', 'amount' => 0.00],
            ],
            'schedule' => [
                [
                    'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
                    'ct_period_id' => 9538,
                    'label' => 'CT Period 1',
                    'loss_created' => 100.00,
                    'loss_brought_forward' => 0.00,
                    'loss_utilised' => 0.00,
                    'loss_carried_forward' => 100.00,
                    'capital_add_backs' => 0.00,
                    'taxable_before_losses' => -100.00,
                    'taxable_profit' => 0.00,
                ],
            ],
            'ct_period_id' => 9538,
            'accounting_period_id' => self::COMPLETE_PRIOR_PERIOD_ID,
            'ct_period_sequence_no' => 1,
            'ct_period_display_sequence_no' => 1,
            'period_start' => '2024-10-01',
            'period_end' => '2025-09-30',
            'capital_allowance_breakdown' => [
                'available' => true,
                'rows' => [
                    self::emptyCapitalAllowancePool('main_pool'),
                    self::emptyCapitalAllowancePool('special_rate_pool'),
                ],
                'warnings' => [],
            ],
            'accounting_allocation_basis' => [
                'method' => 'journal_date_within_single_ct_period',
                'time_apportioned' => false,
                'ct_period_days' => 365,
                'accounting_period_days' => 365,
                'rounding' => 'pennies_half_up',
            ],
            'computation_hash' => $computationHash,
        ];
    }

    /** @return array<string, float|string> */
    private static function emptyCapitalAllowancePool(string $poolType): array
    {
        return [
            'pool_type' => $poolType,
            'opening_wdv' => 0.00,
            'additions' => 0.00,
            'aia_claimed' => 0.00,
            'fya_claimed' => 0.00,
            'disposal_value' => 0.00,
            'wda_claimed' => 0.00,
            'balancing_charge' => 0.00,
            'balancing_allowance' => 0.00,
            'closing_wdv' => 0.00,
        ];
    }

    /** @param array<string, scalar|null> $overrides */
    private static function transaction(
        int $id,
        int $companyId,
        int $periodId,
        int $uploadId,
        int $accountId,
        string $date,
        float $amount,
        ?int $nominalId,
        string $categoryStatus,
        array $overrides = []
    ): void {
        self::insert('transactions', array_merge([
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'statement_upload_id' => $uploadId,
            'account_id' => $accountId,
            'txn_date' => $date,
            'txn_type' => 'Synthetic',
            'description' => 'GOLDEN-TEST scenario transaction ' . $id,
            'reference' => 'GOLDEN-TEST-' . $id,
            'amount' => $amount,
            'currency' => 'GBP',
            'source_type' => 'statement_csv',
            'source_account_label' => $accountId === 9421 ? 'Complete Savings Account' : 'Synthetic Scenario Account',
            'document_download_status' => 'skipped',
            'counterparty_name' => 'Synthetic Scenario Counterparty',
            'dedupe_hash' => hash('sha256', 'GOLDEN-TEST-scenario-transaction-' . $id),
            'nominal_account_id' => $nominalId,
            'category_status' => $categoryStatus,
        ], $overrides));
    }

    /**
     * @param list<array{0: int, 1: float, 2: float}> $lines
     * @param array<string, int|string|null> $metadata
     */
    private static function journal(
        int $id,
        int $companyId,
        int $periodId,
        string $date,
        string $sourceType,
        string $sourceRef,
        string $description,
        array $lines,
        array $metadata = []
    ): void {
        self::insert('journals', [
            'id' => $id,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $date,
            'description' => $description,
            'is_posted' => 1,
        ]);
        foreach ($lines as [$nominalId, $debit, $credit]) {
            self::insert('journal_lines', [
                'journal_id' => $id,
                'nominal_account_id' => $nominalId,
                'debit' => $debit,
                'credit' => $credit,
                'line_description' => $description,
            ]);
        }
        if ($metadata !== []) {
            self::insert('journal_entry_metadata', [
                'journal_id' => $id,
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'journal_tag' => (string)($metadata['journal_tag'] ?? ''),
                'journal_key' => (string)($metadata['journal_key'] ?? ''),
                'entry_mode' => (string)($metadata['entry_mode'] ?? 'manual'),
                'related_journal_id' => $metadata['related_journal_id'] ?? null,
                'notes' => $metadata['notes'] ?? null,
            ]);
        }
    }

    /** @return array{label: string, sql: string, minimum: int} */
    private static function evidence(string $label, string $sql, int $minimum): array
    {
        return ['label' => $label, 'sql' => $sql, 'minimum' => $minimum];
    }

    /** @param array<string, scalar|null> $values */
    private static function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
        InterfaceDB::execute(
            'INSERT INTO `' . $table . '` (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')',
            $values
        );
    }
}
