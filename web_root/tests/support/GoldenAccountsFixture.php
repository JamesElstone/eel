<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldenLedgerSpecification.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldenCardComparisonRegistry.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'GoldenWorkflowCoverageFixture.php';

final class GoldenAccountsFixture
{
    public const GOLDEN_COMPANY_ID = 9100;
    public const EMPTY_COMPANY_ID = 9200;
    public const WARNING_COMPANY_ID = 9300;
    public const COMPLETE_COMPANY_ID = 9400;

    /** @var array<int, array<string, mixed>> */
    private static array $fourPeriodPrepaymentEvidence = [];

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
            GoldenWorkflowCoverageFixture::seed();

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
                9111 => ['transaction_count' => 7, 'expenses' => 1775.00, 'net_profit' => 7225.00, 'bank' => 404.00, 'journal_total' => 24717.00],
                9112 => ['transaction_count' => 4, 'expenses' => 2466.00, 'net_profit' => 6534.00, 'bank' => -1200.00, 'journal_total' => 26466.00],
                9113 => ['transaction_count' => 4, 'expenses' => 2047.00, 'net_profit' => 6953.00, 'bank' => 7435.00, 'journal_total' => 17593.00],
                9114 => ['transaction_count' => 4, 'expenses' => 1863.00, 'net_profit' => 7137.00, 'bank' => 7110.00, 'journal_total' => 17553.00],
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
        $cardExpectations['pl_summary']['metrics'] = ['income' => 12000.00, 'expenses' => 1863.00, 'net_profit' => 7137.00];
        $cardExpectations['transactions_imported']['metrics'] = ['transaction_count' => 4, 'uncategorised_count' => 0];
        $cardExpectations['expense_statistics']['metrics'] = ['claim_count' => 1, 'claimed_amount' => 300.00];
        $cardExpectations['journals_list']['metrics'] = ['journal_count' => 7, 'debits' => 17553.00, 'credits' => 17553.00];

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
                'prepayments' => 91018,
                'annual_subscriptions' => 91019,
            ],
            'privacy' => [
                'live_rows_copied' => false,
                'synthetic_marker' => 'GOLDEN-TEST-',
            ],
            'card_expectations' => $cardExpectations,
            'feature_coverage' => GoldenWorkflowCoverageFixture::coverageManifest(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function fourPeriodPrepaymentEvidence(): array
    {
        self::build();
        return self::$fourPeriodPrepaymentEvidence;
    }

    /** @return list<string> */
    public static function accountingCardKeys(): array
    {
        $keys = [];
        foreach (GoldenCardComparisonRegistry::selectedPages() as $cards) {
            foreach ($cards as $cardKey) {
                $keys[$cardKey] = true;
            }
        }

        // These downstream accounting cards are intentionally available outside a current page layout.
        foreach (['asset_tax', 'tax_vat_threshold', 'vat_support_scope'] as $cardKey) {
            $keys[$cardKey] = true;
        }

        return array_keys($keys);
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
        $subtypeId = static function (?string $code): ?int {
            if ($code === null) {
                return null;
            }
            $id = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
                ['code' => $code]
            );
            if ($id <= 0) {
                throw new RuntimeException('Golden nominal subtype is missing: ' . $code);
            }
            return $id;
        };
        $rows = [
            [91001, 'G100', 'Golden Test Bank', 'asset', 'other', 'bank'],
            [91002, 'G400', 'Golden Test Sales', 'income', 'allowable', null],
            [91003, 'G500', 'Golden Test Materials', 'cost_of_sales', 'allowable', null],
            [91004, 'G600', 'Golden Test Overheads', 'expense', 'allowable', 'overhead'],
            [91005, 'G800', 'Golden Test Director Loan', 'liability', 'other', 'director_loan_liability'],
            [91006, 'G801', 'Golden Test Director Loan Asset', 'asset', 'other', 'director_loan_asset'],
            [91007, '3000', 'Golden Test Retained Earnings', 'equity', 'other', 'capital_reserves'],
            [91008, 'G700', 'Golden Test Corporation Tax Expense', 'expense', 'disallowable', 'corp_tax_expense'],
            [91009, 'G810', 'Golden Test Corporation Tax Liability', 'liability', 'other', 'corp_tax'],
            [91010, '6230', 'Golden Test HMRC Penalties', 'expense', 'disallowable', 'overhead'],
            [91011, '6231', 'Golden Test HMRC Interest', 'expense', 'allowable', 'overhead'],
            [91012, '2210', 'Golden Test HMRC Penalties & Interest Payable', 'liability', 'other', 'hmrc_payable'],
            [91013, 'G1300', 'Golden Test Plant and Machinery', 'asset', 'capital', 'fixed_asset'],
            [91014, '1309', 'Golden Test Plant and Machinery Accumulated Depreciation', 'asset', 'capital', 'fixed_asset'],
            [91015, '1322', 'Golden Test Motor Vehicles - Vans', 'asset', 'capital', 'fixed_asset'],
            [91016, '1329', 'Golden Test Motor Vehicles Accumulated Depreciation', 'asset', 'capital', 'fixed_asset'],
            [91017, '6200', 'Golden Test Depreciation Expense', 'expense', 'disallowable', 'depreciation_expense'],
            [91018, 'GOLD-PREPAY-ASSET', 'Golden Test Prepayments', 'asset', 'other', 'prepayments'],
            [91019, 'GOLD-PREPAY-EXP', 'Golden Test Annual Subscriptions', 'expense', 'allowable', 'overhead'],
        ];
        foreach ($rows as [$id, $code, $name, $type, $tax, $subtypeCode]) {
            self::insert('nominal_accounts', [
                'id' => $id, 'code' => $code, 'name' => $name, 'account_type' => $type,
                'account_subtype_id' => $subtypeId($subtypeCode), 'tax_treatment' => $tax,
                'prepayment_candidate' => $id === 91019 ? 1 : 0, 'is_active' => 1, 'sort_order' => $id,
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
            'id' => 9197, 'company_id' => self::GOLDEN_COMPANY_ID,
            'setting' => 'prepayment_asset_nominal_id', 'type' => 'int', 'value' => '91018',
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
        self::seedFourPeriodPrepayment();
        self::seedCrossPeriodPrepayment();
    }

    private static function seedFourPeriodPrepayment(): void
    {
        $variant = GoldenLedgerSpecification::fourPeriodPrepaymentVariant();
        self::insert('transactions', [
            'id' => 9290, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9111,
            'statement_upload_id' => 9140, 'account_id' => 9120, 'txn_date' => '2022-12-30',
            'txn_type' => 'Synthetic', 'description' => 'Three-year software service 30 December 2022 to 29 December 2025',
            'reference' => 'GOLDEN-PREPAYMENT-FOUR-AP', 'amount' => -1096.00, 'currency' => 'GBP',
            'source_type' => 'statement_csv', 'source_account_label' => 'Golden Current Account',
            'balance' => 404.00, 'counterparty_name' => 'Synthetic Long-Term Software Supplier',
            'dedupe_hash' => hash('sha256', 'GOLDEN-PREPAYMENT-FOUR-AP'), 'nominal_account_id' => 91019,
            'category_status' => 'manual', 'document_download_status' => 'skipped',
        ]);
        self::journal(9292, 9111, '2022-12-30', 'bank_csv', 'transaction:9290', 91019, 91001, 1096.00);
        self::insert('prepayment_reviews', [
            'id' => 9291,
            'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9111,
            'source_type' => 'transaction', 'source_id' => 9290, 'status' => 'prepaid',
            'service_start_date' => (string)$variant['service_start_date'],
            'service_end_date' => (string)$variant['service_end_date'],
            'notes' => 'Synthetic three-year service spanning four accounting periods.',
            'reviewed_at' => '2023-09-30 11:00:00', 'reviewed_by' => 'golden-test',
        ]);

        $scheduleResult = (new \eel_accounts\Service\PrepaymentScheduleService())
            ->syncReviewSchedule(9291, 'golden-test');
        if (empty($scheduleResult['success'])) {
            throw new RuntimeException('Unable to calculate the four-period golden prepayment schedule: '
                . implode(' ', (array)($scheduleResult['errors'] ?? [])));
        }

        $reviewService = new \eel_accounts\Service\PrepaymentReviewService();
        $approvalService = new \eel_accounts\Service\PrepaymentApprovalContextService();
        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $postingService = new \eel_accounts\Service\PrepaymentPostingService();
        $scheduleService = new \eel_accounts\Service\PrepaymentScheduleService();
        foreach (array_keys((array)$variant['expected']) as $periodId) {
            $periodId = (int)$periodId;
            $reviewContext = $reviewService->fetchContext(self::GOLDEN_COMPANY_ID, $periodId);
            $approval = $acknowledgements->save(
                self::GOLDEN_COMPANY_ID,
                $periodId,
                'prepayment_approvals',
                $approvalService->buildApprovalBasis($reviewContext),
                'golden-test'
            );
            if (empty($approval['success'])) {
                throw new RuntimeException('Unable to approve the four-period golden prepayment schedule for AP '
                    . $periodId . ': ' . implode(' ', (array)($approval['errors'] ?? [])));
            }

            $periodContext = $scheduleService->fetchPeriodContext(self::GOLDEN_COMPANY_ID, $periodId);
            $selectedSchedule = null;
            foreach ((array)($periodContext['schedules'] ?? []) as $schedule) {
                if ((int)($schedule['review_id'] ?? 0) === 9291) {
                    $selectedSchedule = $schedule;
                    break;
                }
            }
            if (!is_array($selectedSchedule)) {
                throw new RuntimeException('The four-period golden schedule is missing from AP ' . $periodId . '.');
            }
            $allocation = (array)($selectedSchedule['selected_allocation'] ?? []);
            $previewProfit = (new \eel_accounts\Service\PreTaxProfitLossService())
                ->calculate(self::GOLDEN_COMPANY_ID, $periodId);
            $posting = $postingService->postForAccountingPeriod(self::GOLDEN_COMPANY_ID, $periodId, 'golden-test');
            if (empty($posting['success'])) {
                throw new RuntimeException('Unable to post the four-period golden prepayment schedule for AP '
                    . $periodId . ': ' . implode(' ', (array)($posting['errors'] ?? [])));
            }
            $retry = $postingService->postForAccountingPeriod(self::GOLDEN_COMPANY_ID, $periodId, 'golden-test');
            if (empty($retry['success'])) {
                throw new RuntimeException('Unable to retry the four-period golden prepayment schedule for AP ' . $periodId . '.');
            }
            $postedProfit = (new \eel_accounts\Service\PreTaxProfitLossService())
                ->calculate(self::GOLDEN_COMPANY_ID, $periodId);
            $currentScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = 9291');
            $currentSchedule = $scheduleService->fetchSchedule($currentScheduleId);
            self::$fourPeriodPrepaymentEvidence[$periodId] = [
                'schedule_id' => $currentScheduleId,
                'allocation' => $allocation,
                'preview_profit_before_tax' => (float)($previewProfit['profit_before_tax'] ?? 0),
                'posted_profit_before_tax' => (float)($postedProfit['profit_before_tax'] ?? 0),
                'posted_count' => (int)($posting['posted_count'] ?? 0),
                'retry_posted_count' => (int)($retry['posted_count'] ?? 0),
                'journal_ids' => array_map('intval', (array)($posting['journal_ids'] ?? [])),
                'schedule_status' => (string)($currentSchedule['status'] ?? ''),
            ];
        }
    }

    private static function seedCrossPeriodPrepayment(): void
    {
        self::insert('transactions', [
            'id' => 9194, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9113,
            'statement_upload_id' => 9142, 'account_id' => 9120, 'txn_date' => '2025-07-01',
            'txn_type' => 'Synthetic', 'description' => 'Annual software subscription 1 July 2025 to 30 June 2026',
            'reference' => 'GOLDEN-PREPAYMENT-Y3-Y4', 'amount' => -365.00, 'currency' => 'GBP',
            'source_type' => 'statement_csv', 'source_account_label' => 'Golden Current Account',
            'balance' => 7435.00, 'counterparty_name' => 'Synthetic Software Supplier',
            'dedupe_hash' => hash('sha256', 'GOLDEN-PREPAYMENT-Y3-Y4'), 'nominal_account_id' => 91019,
            'category_status' => 'manual', 'document_download_status' => 'skipped',
        ]);
        self::journal(9241, 9113, '2025-07-01', 'bank_csv', 'transaction:9194', 91019, 91001, 365.00);

        // Inclusive daily apportionment: 92 days in AP 9113 and 273 days in AP 9114.
        self::insert('prepayment_reviews', [
            'id' => 9195,
            'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => 9113,
            'source_type' => 'transaction', 'source_id' => 9194, 'status' => 'prepaid',
            'service_start_date' => '2025-07-01', 'service_end_date' => '2026-06-30',
            'notes' => 'Synthetic 365-day subscription spanning AP 9113 and AP 9114.',
            'reviewed_at' => '2025-09-30 12:00:00', 'reviewed_by' => 'golden-test',
        ]);

        $schedule = (new \eel_accounts\Service\PrepaymentScheduleService())
            ->syncReviewSchedule(9195, 'golden-test');
        if (empty($schedule['success'])) {
            throw new RuntimeException('Unable to calculate the golden prepayment schedule: '
                . implode(' ', (array)($schedule['errors'] ?? [])));
        }

        $reviewService = new \eel_accounts\Service\PrepaymentReviewService();
        $approvalService = new \eel_accounts\Service\PrepaymentApprovalContextService();
        $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
        $postingService = new \eel_accounts\Service\PrepaymentPostingService();
        foreach ([9113, 9114] as $periodId) {
            $review = $reviewService->fetchContext(self::GOLDEN_COMPANY_ID, $periodId);
            $approval = $acknowledgements->save(
                self::GOLDEN_COMPANY_ID,
                $periodId,
                'prepayment_approvals',
                $approvalService->buildApprovalBasis($review),
                'golden-test'
            );
            if (empty($approval['success'])) {
                throw new RuntimeException('Unable to approve the golden prepayment schedule for AP '
                    . $periodId . ': ' . implode(' ', (array)($approval['errors'] ?? [])));
            }

            $posting = $postingService->postForAccountingPeriod(
                self::GOLDEN_COMPANY_ID,
                $periodId,
                'golden-test'
            );
            if (empty($posting['success'])) {
                throw new RuntimeException('Unable to post the golden prepayment schedule for AP '
                    . $periodId . ': ' . implode(' ', (array)($posting['errors'] ?? [])));
            }
        }
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
        self::journal($baseJournalId + 3, $periodId, $activityDate, 'expense_register', 'GOLDEN-' . $periodId, 91004, 91005, 300.00);

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
            'source_reference' => 'GOLDEN-HMRC-CT-INTEREST-Y3',
            'notes' => 'Synthetic late-payment interest charged on overdue Corporation Tax.',
        ]);
        if (empty($interest['success'])) {
            throw new RuntimeException(implode(' ', (array)($interest['errors'] ?? ['Unable to seed HMRC interest.'])));
        }
        $interestId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_obligations WHERE company_id = :company_id AND source_reference = :reference LIMIT 1',
            ['company_id' => self::GOLDEN_COMPANY_ID, 'reference' => 'GOLDEN-HMRC-CT-INTEREST-Y3']
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

    /** @param array<string, int|string|null> $metadata */
    private static function journal(
        int $id,
        int $periodId,
        string $date,
        string $sourceType,
        string $sourceRef,
        int $debitNominal,
        int $creditNominal,
        float $amount,
        array $metadata = []
    ): void
    {
        self::insert('journals', [
            'id' => $id, 'company_id' => self::GOLDEN_COMPANY_ID, 'accounting_period_id' => $periodId,
            'source_type' => $sourceType, 'source_ref' => $sourceRef, 'journal_date' => $date,
            'description' => 'GOLDEN-TEST journal ' . $sourceRef, 'is_posted' => 1,
        ]);
        self::insert('journal_lines', ['journal_id' => $id, 'nominal_account_id' => $debitNominal, 'debit' => $amount, 'credit' => 0]);
        self::insert('journal_lines', ['journal_id' => $id, 'nominal_account_id' => $creditNominal, 'debit' => 0, 'credit' => $amount]);
        if ($metadata !== []) {
            self::insert('journal_entry_metadata', [
                'journal_id' => $id,
                'company_id' => self::GOLDEN_COMPANY_ID,
                'accounting_period_id' => $periodId,
                'journal_tag' => (string)($metadata['journal_tag'] ?? ''),
                'journal_key' => (string)($metadata['journal_key'] ?? ''),
                'entry_mode' => (string)($metadata['entry_mode'] ?? 'manual'),
                'related_journal_id' => $metadata['related_journal_id'] ?? null,
                'notes' => $metadata['notes'] ?? null,
            ]);
        }
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
