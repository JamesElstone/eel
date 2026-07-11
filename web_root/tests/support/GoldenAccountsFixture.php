<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class GoldenAccountsFixture
{
    public const GOLDEN_COMPANY_ID = 9100;
    public const EMPTY_COMPANY_ID = 9200;
    public const WARNING_COMPANY_ID = 9300;
    public const COMPLETE_COMPANY_ID = 9400;

    /** @var list<array{id: int, label: string, start: string, end: string}> */
    private const PERIODS = [
        ['id' => 9111, 'label' => '05/09/2022 to 30/09/2023', 'start' => '2022-09-05', 'end' => '2023-09-30'],
        ['id' => 9112, 'label' => '01/10/2023 to 30/09/2024', 'start' => '2023-10-01', 'end' => '2024-09-30'],
        ['id' => 9113, 'label' => '01/10/2024 to 30/09/2025', 'start' => '2024-10-01', 'end' => '2025-09-30'],
        ['id' => 9114, 'label' => '01/10/2025 to 30/09/2026', 'start' => '2025-10-01', 'end' => '2026-09-30'],
    ];

    /** @return array<string, mixed> */
    public static function build(): array
    {
        static $built = false;
        self::assertSqlite();
        if ($built) {
            return self::manifest();
        }

        $manifest = InterfaceDB::transaction(static function (): array {
            self::ensureSqliteCompatibilityColumns();
            self::seedNominals();
            self::seedCompanies();
            self::seedGoldenCompany();
            self::seedScenarioPeriods();

            return self::manifest();
        });
        $built = true;

        return $manifest;
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $periods = [];
        foreach (self::PERIODS as $period) {
            $adjustments = match ($period['id']) {
                9111 => ['transaction_count' => 6, 'expenses' => 1500.00, 'net_profit' => 7500.00, 'bank' => 1500.00, 'journal_total' => 22800.00],
                9112 => ['transaction_count' => 4, 'expenses' => 2100.00, 'net_profit' => 6900.00, 'bank' => -1200.00, 'journal_total' => 26100.00],
                9113 => ['transaction_count' => 3, 'expenses' => 1590.00, 'net_profit' => 7410.00, 'bank' => 7800.00, 'journal_total' => 16590.00],
                9114 => ['transaction_count' => 4, 'expenses' => 1500.00, 'net_profit' => 7500.00, 'bank' => 7110.00, 'journal_total' => 17190.00],
                default => ['transaction_count' => 3, 'expenses' => 1500.00, 'net_profit' => 7500.00, 'bank' => 7800.00, 'journal_total' => 16500.00],
            };
            $periods[(string)$period['id']] = [
                'label' => $period['label'],
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'transaction_count' => $adjustments['transaction_count'],
                'income' => 12000.00,
                'cost_of_sales' => 3000.00,
                'expenses' => $adjustments['expenses'],
                'net_profit' => $adjustments['net_profit'],
                'bank_closing_balance' => $adjustments['bank'],
                'expense_claim_total' => 300.00,
                'journal_debits' => $adjustments['journal_total'],
                'journal_credits' => $adjustments['journal_total'],
            ];
        }

        $cardExpectations = [];
        foreach (self::accountingCardKeys() as $cardKey) {
            $cardExpectations[$cardKey] = ['scenario' => 'golden', 'renders' => true];
        }
        $cardExpectations['pl_summary']['metrics'] = ['income' => 12000.00, 'expenses' => 1500.00, 'net_profit' => 7500.00];
        $cardExpectations['transactions_imported']['metrics'] = ['transaction_count' => 3, 'uncategorised_count' => 0];
        $cardExpectations['expense_statistics']['metrics'] = ['claim_count' => 1, 'claimed_amount' => 300.00];
        $cardExpectations['journals_list']['metrics'] = ['journal_count' => 4, 'debits' => 16500.00, 'credits' => 16500.00];

        return [
            'companies' => [
                'golden' => self::GOLDEN_COMPANY_ID,
                'empty' => self::EMPTY_COMPANY_ID,
                'warning' => self::WARNING_COMPANY_ID,
                'complete' => self::COMPLETE_COMPANY_ID,
            ],
            'periods' => $periods,
            'nominals' => [
                'bank' => 91001,
                'sales' => 91002,
                'materials' => 91003,
                'overheads' => 91004,
                'director_loan' => 91005,
                'director_loan_asset' => 91006,
                'retained_earnings' => 91007,
                'corporation_tax_expense' => 91008,
                'corporation_tax_liability' => 91009,
                'hmrc_penalty' => 91010,
                'hmrc_interest' => 91011,
                'hmrc_payable' => 91012,
                'plant_machinery' => 91013,
                'plant_machinery_accumulated_depreciation' => 91014,
                'motor_vehicles_vans' => 91015,
                'motor_vehicles_accumulated_depreciation' => 91016,
                'depreciation_expense' => 91017,
            ],
            'privacy' => [
                'live_rows_copied' => false,
                'synthetic_marker' => 'GOLDEN-TEST-',
            ],
            'card_expectations' => $cardExpectations,
        ];
    }

    /** @return list<string> */
    public static function accountingCardKeys(): array
    {
        return [
            'accounting_periods', 'asset_create', 'asset_reconcile_manual', 'asset_register', 'asset_tax',
            'banking_account_form', 'banking_accounts', 'banking_reconciliation', 'companies_company_settings',
            'companies_house_snapshot', 'companies_nominals', 'companies_stored_detail', 'company_minutes',
            'csv_export', 'dashboard_recent_transactions', 'dashboard_year_end_readiness', 'director_loan_state',
            'dividend_capacity', 'dividend_declare', 'dividend_history', 'dividend_reserve_review', 'dividend_vouchers',
            'expense_claim_create', 'expense_claim_editor', 'expense_claimants', 'expense_search',
            'expense_statistics', 'expenses_state', 'hmrc_fines_table', 'hmrc_obligations_action_panel',
            'hmrc_obligations_summary', 'hmrc_obligations_timeline', 'incorporation_add_shares',
            'incorporation_payment_matching', 'incorporation_share_capital', 'incorporation_status',
            'ixbrl_accounts_mapping', 'ixbrl_facts_preview', 'ixbrl_readiness', 'ixbrl_trial_balance',
            'journal_cut_off_confirmation', 'journal_cut_offs', 'journals_list', 'nominal_closing_balances',
            'nominal_opening_balances', 'nominals_accounts', 'nominals_add_account', 'not_an_asset',
            'pl_expense_breakdown', 'pl_income_breakdown', 'pl_monthly_trend', 'pl_net_profit_bridge',
            'pl_source_coverage', 'pl_summary', 'prepayments_review', 'statement_field_mapping',
            'tax_treatment_rules', 'transaction_search', 'transactions_imported', 'transactions_monthly_status',
            'transactions_rule_form', 'transactions_rules', 'trial_balance_losses', 'trial_balance_state',
            'trial_balance_validation', 'uploads_bank_transactions', 'uploads_details',
            'uploads_statement_coverage', 'uploads_validate_commit', 'vat_readiness', 'vat_registration',
            'vehicle_register', 'year_end_companies_house_comparison', 'year_end_director_loan_offset',
            'year_end_empty_month_confirmations', 'year_end_expenses_confirmation', 'year_end_notes',
            'year_end_prepayment_approvals', 'year_end_retained_earnings', 'year_end_state',
            'year_end_tax_readiness', 'year_end_transaction_tail',
        ];
    }

    private static function assertSqlite(): void
    {
        if (InterfaceDB::driverName() !== 'sqlite') {
            throw new RuntimeException('Golden accounts may only be built in the isolated SQLite test database.');
        }
    }

    private static function ensureSqliteCompatibilityColumns(): void
    {
        if (!InterfaceDB::columnExists('company_accounts', 'internal_transfer_marker')) {
            InterfaceDB::execute('ALTER TABLE company_accounts ADD COLUMN internal_transfer_marker TEXT NULL');
        }
    }

    private static function seedNominals(): void
    {
        $rows = [
            [91001, 'G100', 'Golden Test Bank', 'asset', 'other', 1],
            [91002, 'G400', 'Golden Test Sales', 'income', 'allowable', null],
            [91003, 'G500', 'Golden Test Materials', 'cost_of_sales', 'allowable', null],
            [91004, 'G600', 'Golden Test Overheads', 'expense', 'allowable', 11],
            [91005, 'G800', 'Golden Test Director Loan', 'liability', 'other', 6],
            [91006, 'G801', 'Golden Test Director Loan Asset', 'asset', 'other', 3],
            [91007, '3000', 'Golden Test Retained Earnings', 'equity', 'other', 9],
            [91008, 'G700', 'Golden Test Corporation Tax Expense', 'expense', 'disallowable', 14],
            [91009, 'G810', 'Golden Test Corporation Tax Liability', 'liability', 'other', 13],
            [91010, '6230', 'Golden Test HMRC Penalties', 'expense', 'disallowable', 11],
            [91011, '6231', 'Golden Test HMRC Interest', 'expense', 'allowable', 11],
            [91012, '2210', 'Golden Test HMRC Penalties & Interest Payable', 'liability', 'other', 12],
            [91013, '1300', 'Golden Test Plant and Machinery', 'asset', 'capital', 2],
            [91014, '1309', 'Golden Test Plant and Machinery Accumulated Depreciation', 'asset', 'capital', 2],
            [91015, '1322', 'Golden Test Motor Vehicles - Vans', 'asset', 'capital', 2],
            [91016, '1329', 'Golden Test Motor Vehicles Accumulated Depreciation', 'asset', 'capital', 2],
            [91017, '6200', 'Golden Test Depreciation Expense', 'expense', 'disallowable', 11],
        ];
        foreach ($rows as [$id, $code, $name, $type, $tax, $subtypeId]) {
            self::insert('nominal_accounts', [
                'id' => $id, 'code' => $code, 'name' => $name, 'account_type' => $type,
                'account_subtype_id' => $subtypeId, 'tax_treatment' => $tax, 'is_active' => 1, 'sort_order' => $id,
            ]);
        }
    }

    private static function seedCompanies(): void
    {
        foreach ([
            self::GOLDEN_COMPANY_ID => 'Golden Electrical Test Limited',
            self::EMPTY_COMPANY_ID => 'Empty Scenario Test Limited',
            self::WARNING_COMPANY_ID => 'Warning Scenario Test Limited',
            self::COMPLETE_COMPANY_ID => 'Completed Scenario Test Limited',
        ] as $id => $name) {
            self::insert('companies', [
                'id' => $id, 'company_name' => $name, 'company_number' => 'T' . $id,
                'incorporation_date' => '2022-09-05', 'company_status' => 'active', 'is_active' => 1,
            ]);
        }
    }

    private static function seedGoldenCompany(): void
    {
        self::insert('company_accounts', [
            'id' => 9120, 'company_id' => self::GOLDEN_COMPANY_ID, 'account_name' => 'Golden Current Account',
            'account_type' => 'bank', 'institution_name' => 'Synthetic Test Bank',
            'account_identifier' => 'TEST-00-00-00-00000000', 'nominal_account_id' => 91001,
            'is_active' => 1,
        ]);
        self::insert('expense_claimants', [
            'id' => 9130, 'company_id' => self::GOLDEN_COMPANY_ID,
            'claimant_name' => 'Synthetic Claimant', 'is_active' => 1,
        ]);
        self::insert('company_settings', [
            'id' => 9198, 'company_id' => self::GOLDEN_COMPANY_ID,
            'setting' => 'corporation_tax_expense_nominal_id', 'type' => 'int', 'value' => '91008',
        ]);
        self::insert('company_settings', [
            'id' => 9199, 'company_id' => self::GOLDEN_COMPANY_ID,
            'setting' => 'corporation_tax_liability_nominal_id', 'type' => 'int', 'value' => '91009',
        ]);

        foreach (self::PERIODS as $index => $period) {
            self::insertPeriod(self::GOLDEN_COMPANY_ID, $period);
            self::seedPeriodActivity($index, $period);
        }
        self::seedFixedAssets();
        self::seedHmrcPenaltyLifecycle();
    }

    /** @param array{id: int, label: string, start: string, end: string} $period */
    private static function seedPeriodActivity(int $index, array $period): void
    {
        $periodId = $period['id'];
        $uploadId = 9140 + $index;
        $claimId = 9150 + $index;
        $baseTransactionId = 9160 + ($index * 10);
        $baseJournalId = 9200 + ($index * 10);
        $activityDate = (new DateTimeImmutable($period['start']))->modify('+10 days')->format('Y-m-d');

        self::insert('statement_uploads', [
            'id' => $uploadId, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
            'account_id' => 9120, 'source_type' => 'bank_account', 'workflow_status' => 'completed',
            'statement_month' => substr($activityDate, 0, 7) . '-01',
            'original_filename' => 'GOLDEN-TEST-' . $periodId . '.csv',
            'stored_filename' => 'golden-test-' . $periodId . '.csv',
            'file_sha256' => hash('sha256', 'GOLDEN-TEST-' . $periodId),
            'date_range_start' => $period['start'], 'date_range_end' => $period['end'],
            'rows_parsed' => $index === 0 ? 6 : ($index === 1 ? 4 : 3),
            'rows_inserted' => $index === 0 ? 6 : ($index === 1 ? 4 : 3),
            'rows_valid' => $index === 0 ? 6 : ($index === 1 ? 4 : 3),
            'rows_committed' => $index === 0 ? 6 : ($index === 1 ? 4 : 3),
            'committed_at' => $period['end'] . ' 12:00:00',
        ]);

        $transactions = [
            [$baseTransactionId, 12000.00, 91002, 'Synthetic customer receipt', 12000.00],
            [$baseTransactionId + 1, -3000.00, 91003, 'Synthetic materials purchase', 9000.00],
            [$baseTransactionId + 2, -1200.00, 91004, 'Synthetic overhead payment', 7800.00],
        ];
        foreach ($transactions as [$id, $amount, $nominalId, $description, $balance]) {
            self::insert('transactions', [
                'id' => $id, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
                'statement_upload_id' => $uploadId, 'account_id' => 9120, 'txn_date' => $activityDate,
                'txn_type' => 'Synthetic', 'description' => $description,
                'reference' => 'GOLDEN-TEST-' . $id, 'amount' => $amount, 'currency' => 'GBP',
                'source_type' => 'statement_csv', 'source_account_label' => 'Golden Current Account',
                'balance' => $balance, 'counterparty_name' => 'Synthetic Counterparty ' . $id,
                'dedupe_hash' => hash('sha256', 'GOLDEN-TEST-' . $id), 'nominal_account_id' => $nominalId,
                'category_status' => 'manual', 'document_download_status' => 'skipped',
            ]);
        }

        self::journal($baseJournalId, $periodId, $activityDate, 'bank_csv', 'transaction:' . $baseTransactionId, 91001, 91002, 12000.00);
        self::journal($baseJournalId + 1, $periodId, $activityDate, 'bank_csv', 'transaction:' . ($baseTransactionId + 1), 91003, 91001, 3000.00);
        self::journal($baseJournalId + 2, $periodId, $activityDate, 'bank_csv', 'transaction:' . ($baseTransactionId + 2), 91004, 91001, 1200.00);
        self::journal($baseJournalId + 3, $periodId, $activityDate, 'expense_register', 'golden-claim-' . $periodId, 91004, 91005, 300.00);

        self::insert('expense_claims', [
            'id' => $claimId, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
            'claimant_id' => 9130, 'claim_year' => (int)substr($activityDate, 0, 4),
            'claim_month' => (int)substr($activityDate, 5, 2), 'period_start' => $activityDate,
            'period_end' => $activityDate, 'claim_reference_code' => 'GOLDEN-' . $periodId,
            'claimed_amount' => 300.00, 'carried_forward_amount' => 300.00,
            'status' => 'posted', 'posted_journal_id' => $baseJournalId + 3,
        ]);
        self::insert('expense_claim_lines', [
            'id' => 9180 + $index, 'expense_claim_id' => $claimId, 'line_number' => 1,
            'expense_date' => $activityDate, 'description' => 'Synthetic mileage and subsistence',
            'amount' => 300.00, 'nominal_account_id' => 91004,
            'receipt_reference' => 'GOLDEN-RECEIPT-' . $periodId,
        ]);
    }

    private static function seedFixedAssets(): void
    {
        $yearOneAssets = [
            [9251, 9163, 9204, 'GOLDEN-FA-001', 'Workshop test equipment', 3600.00],
            [9252, 9164, 9205, 'GOLDEN-FA-002', 'Electrical installation tools', 1800.00],
            [9253, 9165, 9206, 'GOLDEN-FA-003', 'Office computer equipment', 900.00],
        ];
        $balance = 7800.00;
        foreach ($yearOneAssets as [$assetId, $transactionId, $journalId, $assetCode, $description, $cost]) {
            $balance -= $cost;
            self::seedAssetPurchaseTransaction($transactionId, 9111, 9140, '2022-09-15', $description, $cost, 91013, $balance);
            self::journal($journalId, 9111, '2022-09-15', 'bank_csv', 'transaction:' . $transactionId, 91013, 91001, $cost);
            self::seedAssetRegisterRow($assetId, $assetCode, $description, 'tools_equipment', 91013, 91014, '2022-09-15', $cost, $journalId, $transactionId);
        }

        self::seedAssetPurchaseTransaction(9173, 9112, 9141, '2023-10-11', 'Electric service van', 9000.00, 91015, -1200.00);
        self::journal(9214, 9112, '2023-10-11', 'bank_csv', 'transaction:9173', 91015, 91001, 9000.00);
        self::seedAssetRegisterRow(9254, 'GOLDEN-VAN-001', 'Electric service van', 'van', 91015, 91016, '2023-10-11', 9000.00, 9214, 9173);
        self::insert('asset_vehicle_details', [
            'asset_id' => 9254,
            'company_id' => self::GOLDEN_COMPANY_ID,
            'vehicle_type' => 'van',
            'registration_mark' => 'G0LD VAN',
            'make_model' => 'Synthetic Electric Service Van',
            'colour' => 'White',
            'first_registered_date' => '2023-10-01',
            'acquisition_condition' => 'new_unused',
            'is_zero_emission' => 1,
            'co2_emissions_g_km' => 0,
            'payload_kg' => 850.00,
            'contract_date' => '2023-10-11',
            'tax_review_status' => 'reviewed',
            'reviewed_at' => '2023-10-11 12:00:00',
            'reviewed_by' => 'golden-test',
            'notes' => 'Synthetic vehicle for deterministic golden testing.',
        ]);
    }

    private static function seedAssetPurchaseTransaction(int $id, int $periodId, int $uploadId, string $date, string $description, float $cost, int $nominalId, float $balance): void
    {
        self::insert('transactions', [
            'id' => $id, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
            'statement_upload_id' => $uploadId, 'account_id' => 9120, 'txn_date' => $date,
            'txn_type' => 'Synthetic', 'description' => $description,
            'reference' => 'GOLDEN-TEST-ASSET-' . $id, 'amount' => -$cost, 'currency' => 'GBP',
            'source_type' => 'statement_csv', 'source_account_label' => 'Golden Current Account',
            'balance' => $balance, 'counterparty_name' => 'Synthetic Asset Supplier ' . $id,
            'dedupe_hash' => hash('sha256', 'GOLDEN-TEST-ASSET-' . $id), 'nominal_account_id' => $nominalId,
            'category_status' => 'manual', 'document_download_status' => 'skipped',
        ]);
    }

    private static function seedAssetRegisterRow(int $id, string $code, string $description, string $category, int $nominalId, int $accumulatedDepreciationNominalId, string $purchaseDate, float $cost, int $journalId, int $transactionId): void
    {
        self::insert('asset_register', [
            'id' => $id, 'company_id' => self::GOLDEN_COMPANY_ID, 'asset_code' => $code,
            'description' => $description, 'category' => $category,
            'nominal_account_id' => $nominalId, 'accum_dep_nominal_id' => $accumulatedDepreciationNominalId,
            'purchase_date' => $purchaseDate, 'cost' => $cost, 'useful_life_years' => 3,
            'depreciation_method' => 'straight_line', 'residual_value' => 0.00, 'status' => 'active',
            'linked_journal_id' => $journalId, 'linked_transaction_id' => $transactionId,
        ]);
    }

    private static function seedScenarioPeriods(): void
    {
        $template = self::PERIODS[3];
        foreach ([self::EMPTY_COMPANY_ID => 9211, self::WARNING_COMPANY_ID => 9311, self::COMPLETE_COMPANY_ID => 9411] as $companyId => $periodId) {
            self::insertPeriod($companyId, [
                'id' => $periodId, 'label' => $template['label'], 'start' => $template['start'], 'end' => $template['end'],
            ]);
        }
    }

    private static function seedHmrcPenaltyLifecycle(): void
    {
        $service = new \eel_accounts\Service\HmrcObligationService();
        $penalty = $service->createManualObligation([
            'company_id' => self::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9112,
            'obligation_type' => 'hmrc_penalty',
            'notice_date' => '2024-06-15',
            'due_date' => '2024-07-15',
            'amount_due' => '600.00',
            'source_reference' => 'GOLDEN-HMRC-PENALTY-Y2',
            'notes' => 'Synthetic HMRC penalty assessed in golden year two.',
        ]);
        if (empty($penalty['success'])) {
            throw new RuntimeException(implode(' ', (array)($penalty['errors'] ?? ['Unable to seed HMRC penalty.'])));
        }
        $penaltyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_obligations WHERE company_id = :company_id AND source_reference = :reference LIMIT 1',
            ['company_id' => self::GOLDEN_COMPANY_ID, 'reference' => 'GOLDEN-HMRC-PENALTY-Y2']
        );

        $interest = $service->createManualObligation([
            'company_id' => self::GOLDEN_COMPANY_ID,
            'accounting_period_id' => 9113,
            'obligation_type' => 'hmrc_interest',
            'notice_date' => '2025-06-30',
            'due_date' => '2025-07-30',
            'amount_due' => '90.00',
            'source_reference' => 'GOLDEN-HMRC-INTEREST-Y3',
            'notes' => 'Synthetic late-payment interest related to the year-two penalty.',
        ]);
        if (empty($interest['success'])) {
            throw new RuntimeException(implode(' ', (array)($interest['errors'] ?? ['Unable to seed HMRC interest.'])));
        }
        $interestId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_obligations WHERE company_id = :company_id AND source_reference = :reference LIMIT 1',
            ['company_id' => self::GOLDEN_COMPANY_ID, 'reference' => 'GOLDEN-HMRC-INTEREST-Y3']
        );
        InterfaceDB::execute(
            'UPDATE hmrc_obligations SET related_fine_id = :penalty_id WHERE id = :interest_id',
            ['penalty_id' => $penaltyId, 'interest_id' => $interestId]
        );

        self::insert('transactions', [
            'id' => 9193, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9114,
            'statement_upload_id' => 9143, 'account_id' => 9120, 'txn_date' => '2026-02-15',
            'txn_type' => 'Synthetic', 'description' => 'Synthetic HMRC penalty and interest payment',
            'reference' => 'GOLDEN-HMRC-PAYMENT-Y4', 'amount' => -690.00, 'currency' => 'GBP',
            'source_type' => 'statement_csv', 'source_account_label' => 'Golden Current Account',
            'balance' => 7110.00, 'counterparty_name' => 'HMRC Synthetic',
            'dedupe_hash' => hash('sha256', 'GOLDEN-HMRC-PAYMENT-Y4'), 'nominal_account_id' => 91012,
            'category_status' => 'manual', 'document_download_status' => 'skipped',
        ]);
        self::journal(9240, 9114, '2026-02-15', 'bank_csv', 'transaction:9193', 91012, 91001, 690.00);
        $service->markPaid($penaltyId, 600.00, 'GOLDEN-HMRC-PAYMENT-Y4', 'Paid in golden year four.');
        $service->markPaid($interestId, 90.00, 'GOLDEN-HMRC-PAYMENT-Y4', 'Paid in golden year four.');
    }

    /** @param array{id: int, label: string, start: string, end: string} $period */
    private static function insertPeriod(int $companyId, array $period): void
    {
        self::insert('accounting_periods', [
            'id' => $period['id'], 'company_id' => $companyId, 'label' => $period['label'],
            'period_start' => $period['start'], 'period_end' => $period['end'],
        ]);
    }

    private static function journal(int $id, int $periodId, string $date, string $sourceType, string $sourceRef, int $debitNominal, int $creditNominal, float $amount): void
    {
        self::insert('journals', [
            'id' => $id, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
            'source_type' => $sourceType, 'source_ref' => $sourceRef, 'journal_date' => $date,
            'description' => 'GOLDEN-TEST journal ' . $sourceRef, 'is_posted' => 1,
        ]);
        self::insert('journal_lines', ['journal_id' => $id, 'nominal_account_id' => $debitNominal, 'debit' => $amount, 'credit' => 0]);
        self::insert('journal_lines', ['journal_id' => $id, 'nominal_account_id' => $creditNominal, 'debit' => 0, 'credit' => $amount]);
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
