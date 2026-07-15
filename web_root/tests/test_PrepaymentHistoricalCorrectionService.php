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

$harness = new GeneratedServiceClassTestHarness();
$harness->run(
    \eel_accounts\Service\PrepaymentHistoricalCorrectionService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentHistoricalCorrectionService $service): void {
        GoldenAccountsFixture::build();

        $harness->check(
            \eel_accounts\Service\PrepaymentHistoricalCorrectionService::class,
            'previews and explicitly reconstructs a legacy schedule without posting journals',
            static function () use ($harness): void {
                InterfaceDB::beginTransaction();
                try {
                    InterfaceDB::execute(
                        'INSERT INTO transactions (
                            id, company_id, accounting_period_id, statement_upload_id, account_id, txn_date, description,
                            amount, dedupe_hash, nominal_account_id, category_status
                         ) VALUES (
                            :id, :company_id, :accounting_period_id, 9140, 9120, :txn_date, :description,
                            :amount, :dedupe_hash, :nominal_account_id, :category_status
                         )',
                        [
                            'id' => 999901,
                            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                            'accounting_period_id' => 9111,
                            'txn_date' => '2022-12-30',
                            'description' => 'Legacy prepaid service',
                            'amount' => -730.00,
                            'dedupe_hash' => hash('sha256', 'legacy-prepaid-service'),
                            'nominal_account_id' => 91019,
                            'category_status' => 'manual',
                        ]
                    );
                    InterfaceDB::execute(
                        'INSERT INTO journals (
                            company_id, accounting_period_id, source_type, source_ref,
                            journal_date, description, is_posted
                         ) VALUES (
                            :company_id, :accounting_period_id, :source_type, :source_ref,
                            :journal_date, :description, 1
                         )',
                        [
                            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                            'accounting_period_id' => 9111,
                            'source_type' => 'bank_csv',
                            'source_ref' => 'transaction:999901',
                            'journal_date' => '2022-12-30',
                            'description' => 'Legacy prepaid service purchase',
                        ]
                    );
                    $journalId = (int)InterfaceDB::fetchColumn("SELECT id FROM journals WHERE source_ref = 'transaction:999901'");
                    InterfaceDB::execute(
                        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                         VALUES (:journal_id, 91019, 730.00, 0.00, :description)',
                        ['journal_id' => $journalId, 'description' => 'Legacy prepaid service']
                    );
                    InterfaceDB::execute(
                        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                         VALUES (:journal_id, 91001, 0.00, 730.00, :description)',
                        ['journal_id' => $journalId, 'description' => 'Bank payment']
                    );
                    InterfaceDB::execute(
                        'INSERT INTO prepayment_reviews (
                            id, company_id, accounting_period_id, source_type, source_id,
                            status, service_start_date, service_end_date, reviewed_at, reviewed_by
                         ) VALUES (
                            999902, :company_id, 9111, :source_type, 999901,
                            :status, :service_start, :service_end, CURRENT_TIMESTAMP, :reviewed_by
                         )',
                        [
                            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                            'source_type' => 'transaction',
                            'status' => 'prepaid',
                            'service_start' => '2022-12-30',
                            'service_end' => '2023-12-29',
                            'reviewed_by' => 'test',
                        ]
                    );

                    $schedules = new \eel_accounts\Service\PrepaymentScheduleService();
                    $preview = $schedules->fetchRepairContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
                    $harness->assertSame(1, (int)$preview['missing_count']);
                    $harness->assertSame(999902, (int)$preview['missing_reviews'][0]['review_id']);
                    $harness->assertSame(55000, (int)$preview['missing_reviews'][0]['selected_allocation']['expense_pence']);
                    $harness->assertSame(18000, (int)$preview['missing_reviews'][0]['selected_allocation']['closing_deferred_pence']);
                    $journalCount = (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals');

                    $result = $schedules->syncMissingSchedulesForPeriod(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111, 'test');
                    $harness->assertTrue(!empty($result['success']));
                    $harness->assertSame(1, (int)$result['created_count']);
                    $harness->assertSame($journalCount, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals'));
                    $scheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = 999902');
                    $harness->assertTrue($scheduleId > 0);
                    $harness->assertSame(2, (int)InterfaceDB::fetchColumn('SELECT calculation_version FROM prepayment_schedules WHERE id = :id', ['id' => $scheduleId]));
                    $retry = $schedules->syncMissingSchedulesForPeriod(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111, 'test');
                    $harness->assertTrue(!empty($retry['success']));
                    $harness->assertSame(0, (int)$retry['created_count']);
                } finally {
                    InterfaceDB::rollBack();
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\PrepaymentHistoricalCorrectionService::class,
            'blocks filed-period posting until HMRC evidence and the hashed correction acknowledgement are current',
            static function () use ($harness, $service): void {
                InterfaceDB::beginTransaction();
                try {
                    InterfaceDB::execute(
                        'INSERT INTO companies_house_documents (
                            company_id, company_number, transaction_id, filing_date,
                            filing_type, filing_category, filing_description, document_id,
                            metadata_url, significant_date, significant_date_type,
                            raw_content_hash, parse_status
                         ) VALUES (
                            :company_id, :company_number, :transaction_id, :filing_date,
                            :filing_type, :filing_category, :filing_description, :document_id,
                            :metadata_url, :significant_date, :significant_date_type,
                            :raw_content_hash, :parse_status
                         )',
                        [
                            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                            'company_number' => 'T9100',
                            'transaction_id' => 'GOLD-HISTORIC-AA',
                            'filing_date' => '2025-05-29',
                            'filing_type' => 'AA',
                            'filing_category' => 'accounts',
                            'filing_description' => 'accounts-with-accounts-type-micro-entity',
                            'document_id' => 'GOLD-HISTORIC-DOCUMENT',
                            'metadata_url' => 'https://example.test/metadata',
                            'significant_date' => '2023-09-30',
                            'significant_date_type' => 'made-up-date',
                            'raw_content_hash' => hash('sha256', 'gold-historic-document'),
                            'parse_status' => 'parsed_latest_year',
                        ]
                    );
                    InterfaceDB::execute(
                        'INSERT INTO hmrc_obligations (
                            company_id, accounting_period_id, obligation_type,
                            period_start, period_end, due_date, status, source
                         ) VALUES (
                            :company_id, 9111, :obligation_type,
                            :period_start, :period_end, :due_date, :status, :source
                         )',
                        [
                            'company_id' => GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                            'obligation_type' => 'ct600_filing',
                            'period_start' => '2022-09-05',
                            'period_end' => '2023-09-30',
                            'due_date' => '2024-09-30',
                            'status' => 'not_started',
                            'source' => 'calculated',
                        ]
                    );

                    $context = $service->fetchContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
                    $harness->assertTrue(!empty($context['companies_house_filed']));
                    $harness->assertSame('unknown', (string)$context['hmrc_filing']['state']);
                    $harness->assertSame(false, !empty($context['posting_permitted']));
                    $blocked = false;
                    try {
                        $service->assertPostingPermitted(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
                    } catch (RuntimeException $exception) {
                        $blocked = str_contains($exception->getMessage(), 'HMRC Corporation Tax return');
                    }
                    $harness->assertTrue($blocked);

                    $hmrc = $service->confirmHmrcFilingStatus(
                        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                        9111,
                        'not_filed',
                        'HMRC account checked 15 July 2026',
                        'No return submitted.',
                        'test'
                    );
                    $harness->assertTrue(!empty($hmrc['success']));
                    $harness->assertSame('not_filed', (string)$hmrc['hmrc_filing']['state']);
                    $acknowledged = $service->acknowledge(
                        GoldenAccountsFixture::GOLDEN_COMPANY_ID,
                        9111,
                        'test',
                        'Figures will be corrected through Companies House WebFiling.'
                    );
                    $harness->assertTrue(!empty($acknowledged['success']));
                    $service->assertPostingPermitted(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);

                    InterfaceDB::execute(
                        'UPDATE companies_house_documents SET raw_content_hash = :hash WHERE document_id = :document_id',
                        ['hash' => hash('sha256', 'changed-document'), 'document_id' => 'GOLD-HISTORIC-DOCUMENT']
                    );
                    $stale = $service->fetchContext(GoldenAccountsFixture::GOLDEN_COMPANY_ID, 9111);
                    $harness->assertSame('stale', (string)$stale['acknowledgement']['state']);
                } finally {
                    InterfaceDB::rollBack();
                }
            }
        );

        $harness->check(
            \eel_accounts\Service\PrepaymentHistoricalCorrectionService::class,
            'keeps the prepayment domain independent from VAT services and schema',
            static function () use ($harness): void {
                foreach ([
                    'PrepaymentReviewService.php',
                    'PrepaymentScheduleService.php',
                    'PrepaymentPostingService.php',
                    'PrepaymentAssetNominalService.php',
                    'PrepaymentHistoricalCorrectionService.php',
                ] as $file) {
                    $source = file_get_contents(APP_CLASSES . 'eel_accounts' . DIRECTORY_SEPARATOR . 'service' . DIRECTORY_SEPARATOR . $file);
                    if (!is_string($source)) {
                        throw new RuntimeException('Unable to inspect ' . $file . '.');
                    }
                    $harness->assertSame(false, str_contains($source, 'VatSupportScopeService'));
                    $harness->assertSame(false, str_contains(strtolower($source), 'vat_validation'));
                }
            }
        );
    }
);
