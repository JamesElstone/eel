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
    \eel_accounts\Service\PrepaymentPostingService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\PrepaymentPostingService $posting): void {
        $harness->check(\eel_accounts\Service\PrepaymentPostingService::class, 'synchronises and posts idempotently while keeping the P and L nominal breakdown stable', static function () use ($harness, $posting): void {
            foreach ([
                'prepayment_schedules', 'prepayment_schedule_periods', 'prepayment_schedule_postings',
                'journal_entry_metadata', 'year_end_review_acknowledgements',
            ] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }

            InterfaceDB::beginTransaction();
            try {
                InterfaceDB::execute(
                    'INSERT INTO companies (company_name, company_number, is_active) VALUES (:name, :number, 1)',
                    ['name' => 'Prepayment Schedule Test Limited', 'number' => 'PP000001']
                );
                $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :number', ['number' => 'PP000001']);
                InterfaceDB::execute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    ['company_id' => $companyId, 'label' => 'AP79', 'period_start' => '2022-09-05', 'period_end' => '2023-09-30']
                );
                $ap79 = (int)InterfaceDB::fetchColumn('SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label', ['company_id' => $companyId, 'label' => 'AP79']);

                $prepaymentSubtype = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_account_subtypes WHERE code = 'prepayments'");
                $overheadSubtype = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_account_subtypes WHERE code = 'overhead'");
                InterfaceDB::execute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, prepayment_candidate, is_active, sort_order)
                     VALUES (:code, :name, :type, :subtype, :tax, :candidate, 1, :sort)',
                    ['code' => 'T1000', 'name' => 'Test Bank', 'type' => 'asset', 'subtype' => null, 'tax' => 'other', 'candidate' => 0, 'sort' => 1]
                );
                InterfaceDB::execute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, prepayment_candidate, is_active, sort_order)
                     VALUES (:code, :name, :type, :subtype, :tax, :candidate, 1, :sort)',
                    ['code' => 'T1150', 'name' => 'Test Prepayments', 'type' => 'asset', 'subtype' => $prepaymentSubtype, 'tax' => 'other', 'candidate' => 0, 'sort' => 2]
                );
                InterfaceDB::execute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, prepayment_candidate, is_active, sort_order)
                     VALUES (:code, :name, :type, :subtype, :tax, :candidate, 1, :sort)',
                    ['code' => 'T6010', 'name' => 'Test Insurance', 'type' => 'expense', 'subtype' => $overheadSubtype, 'tax' => 'allowable', 'candidate' => 1, 'sort' => 3]
                );
                $bankNominal = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_accounts WHERE code = 'T1000'");
                $assetNominal = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_accounts WHERE code = 'T1150'");
                $expenseNominal = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_accounts WHERE code = 'T6010'");
                InterfaceDB::execute(
                    'INSERT INTO company_settings (company_id, setting, type, value) VALUES (:company_id, :setting, :type, :value)',
                    ['company_id' => $companyId, 'setting' => 'prepayment_asset_nominal_id', 'type' => 'int', 'value' => (string)$assetNominal]
                );

                InterfaceDB::execute(
                    'INSERT INTO statement_uploads (
                        company_id, accounting_period_id, statement_month, original_filename,
                        stored_filename, file_sha256, workflow_status
                     ) VALUES (
                        :company_id, :accounting_period_id, :statement_month, :original_filename,
                        :stored_filename, :file_sha256, :workflow_status
                     )',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'statement_month' => '2022-12-01', 'original_filename' => 'synthetic-prepayment.csv',
                        'stored_filename' => 'synthetic-prepayment.csv', 'file_sha256' => hash('sha256', 'synthetic-prepayment.csv'),
                        'workflow_status' => 'committed',
                    ]
                );
                $uploadId = (int)InterfaceDB::fetchColumn('SELECT id FROM statement_uploads WHERE company_id = :company_id', ['company_id' => $companyId]);
                InterfaceDB::execute(
                    'INSERT INTO transactions (
                        company_id, accounting_period_id, statement_upload_id, txn_date,
                        description, amount, dedupe_hash, nominal_account_id, category_status
                     ) VALUES (
                        :company_id, :accounting_period_id, :statement_upload_id, :txn_date,
                        :description, :amount, :dedupe_hash, :nominal_account_id, :category_status
                     )',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'statement_upload_id' => $uploadId, 'txn_date' => '2022-12-30',
                        // Deliberately synthetic source fixture; no production transaction is represented.
                        'description' => 'Synthetic annual service contract', 'amount' => -730.00,
                        'dedupe_hash' => hash('sha256', 'synthetic-annual-service-contract'),
                        'nominal_account_id' => $expenseNominal, 'category_status' => 'manual',
                    ]
                );
                $transactionId = (int)InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE company_id = :company_id', ['company_id' => $companyId]);
                InterfaceDB::execute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79, 'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:' . $transactionId, 'journal_date' => '2022-12-30',
                        'description' => 'Synthetic annual service purchase',
                    ]
                );
                $sourceJournalId = (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref', ['company_id' => $companyId, 'source_ref' => 'transaction:' . $transactionId]);
                InterfaceDB::execute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal, 730.00, 0.00, :description)',
                    ['journal_id' => $sourceJournalId, 'nominal' => $expenseNominal, 'description' => 'Synthetic annual service contract']
                );
                InterfaceDB::execute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal, 0.00, 730.00, :description)',
                    ['journal_id' => $sourceJournalId, 'nominal' => $bankNominal, 'description' => 'Synthetic bank payment']
                );

                $reviewService = new \eel_accounts\Service\PrepaymentReviewService();

                // A split transaction is reviewed at line level and the parent is not duplicated.
                InterfaceDB::execute(
                    'INSERT INTO transactions (
                        company_id, accounting_period_id, statement_upload_id, txn_date,
                        description, amount, dedupe_hash, nominal_account_id, category_status
                     ) VALUES (
                        :company_id, :accounting_period_id, :statement_upload_id, :txn_date,
                        :description, :amount, :dedupe_hash, :nominal_account_id, :category_status
                     )',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'statement_upload_id' => $uploadId, 'txn_date' => '2023-01-15',
                        'description' => 'Split candidate', 'amount' => -300.00,
                        'dedupe_hash' => hash('sha256', 'split-candidate'),
                        'nominal_account_id' => $expenseNominal, 'category_status' => 'manual',
                    ]
                );
                $splitTransactionId = (int)InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE dedupe_hash = :hash', ['hash' => hash('sha256', 'split-candidate')]);
                InterfaceDB::execute('INSERT INTO transaction_splits (transaction_id) VALUES (:transaction_id)', ['transaction_id' => $splitTransactionId]);
                $splitId = (int)InterfaceDB::fetchColumn('SELECT id FROM transaction_splits WHERE transaction_id = :transaction_id', ['transaction_id' => $splitTransactionId]);
                InterfaceDB::execute(
                    'INSERT INTO transaction_split_lines (split_id, line_number, description, amount, nominal_account_id)
                     VALUES (:split_id, 1, :description, 300.00, :nominal)',
                    ['split_id' => $splitId, 'description' => 'Split insurance line', 'nominal' => $expenseNominal]
                );
                $splitLineId = (int)InterfaceDB::fetchColumn('SELECT id FROM transaction_split_lines WHERE split_id = :split_id', ['split_id' => $splitId]);
                InterfaceDB::execute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79, 'source_type' => 'bank_csv',
                        'source_ref' => 'transaction:' . $splitTransactionId, 'journal_date' => '2023-01-15',
                        'description' => 'Split candidate purchase',
                    ]
                );
                $splitJournalId = (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE source_ref = :source_ref', ['source_ref' => 'transaction:' . $splitTransactionId]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 300.00, 0.00)', ['journal' => $splitJournalId, 'nominal' => $expenseNominal]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 0.00, 300.00)', ['journal' => $splitJournalId, 'nominal' => $bankNominal]);

                $sourceService = new \eel_accounts\Service\PrepaymentSourceService();
                $harness->assertTrue(!empty($sourceService->verify($companyId, $ap79, 'transaction_split_line', $splitLineId)['success']));
                $candidateTypes = array_column($sourceService->listCandidates($companyId, $ap79), 'source_type');
                $harness->assertSame(1, count(array_filter($candidateTypes, static fn(string $type): bool => $type === 'transaction_split_line')));

                // Unposted candidates and posted credits remain visible as
                // diagnostics but cannot become pending Year End decisions.
                InterfaceDB::execute(
                    'INSERT INTO transactions (
                        company_id, accounting_period_id, statement_upload_id, txn_date,
                        description, amount, dedupe_hash, nominal_account_id, category_status
                     ) VALUES
                     (:company_id, :accounting_period_id, :statement_upload_id, :txn_date,
                      :unposted_description, -50.00, :unposted_hash, :nominal_account_id, :category_status),
                     (:company_id, :accounting_period_id, :statement_upload_id, :txn_date,
                      :refund_description, 50.00, :refund_hash, :nominal_account_id, :category_status)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'statement_upload_id' => $uploadId, 'txn_date' => '2023-03-01',
                        'unposted_description' => 'Unposted insurance candidate',
                        'unposted_hash' => hash('sha256', 'unposted-insurance-candidate'),
                        'refund_description' => 'Insurance refund credit',
                        'refund_hash' => hash('sha256', 'insurance-refund-credit'),
                        'nominal_account_id' => $expenseNominal, 'category_status' => 'manual',
                    ]
                );
                $unpostedCandidateId = (int)InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE dedupe_hash = :hash', ['hash' => hash('sha256', 'unposted-insurance-candidate')]);
                $refundCandidateId = (int)InterfaceDB::fetchColumn('SELECT id FROM transactions WHERE dedupe_hash = :hash', ['hash' => hash('sha256', 'insurance-refund-credit')]);
                InterfaceDB::execute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, \'bank_csv\', :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'source_ref' => 'transaction:' . $refundCandidateId, 'journal_date' => '2023-03-01',
                        'description' => 'Insurance refund credit',
                    ]
                );
                $refundJournalId = (int)InterfaceDB::fetchColumn('SELECT id FROM journals WHERE source_ref = :source_ref', ['source_ref' => 'transaction:' . $refundCandidateId]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 50.00, 0.00)', ['journal' => $refundJournalId, 'nominal' => $bankNominal]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 0.00, 50.00)', ['journal' => $refundJournalId, 'nominal' => $expenseNominal]);
                $candidateContext = $sourceService->fetchCandidateContext($companyId, $ap79);
                $excludedIds = array_map('intval', array_column((array)$candidateContext['excluded'], 'source_id'));
                $eligibleIds = array_map('intval', array_column((array)$candidateContext['eligible'], 'source_id'));
                if (!in_array($unpostedCandidateId, $excludedIds, true) || !in_array($refundCandidateId, $excludedIds, true)) {
                    throw new RuntimeException('Unposted/refund candidates were not both reported as excluded.');
                }
                if (in_array($unpostedCandidateId, $eligibleIds, true) || in_array($refundCandidateId, $eligibleIds, true)) {
                    throw new RuntimeException('An unposted/refund candidate remained eligible for review.');
                }

                // A newer manual journal with the same reference is not source
                // evidence; only the exact production bank journal is used.
                InterfaceDB::execute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, \'manual\', :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79,
                        'source_ref' => 'transaction:' . $transactionId, 'journal_date' => '2022-12-30',
                        'description' => 'Unrelated manual journal',
                    ]
                );
                $verifiedDirect = $sourceService->verify($companyId, $ap79, 'transaction', $transactionId);
                $harness->assertTrue(!empty($verifiedDirect['success']));
                $harness->assertSame($sourceJournalId, (int)$verifiedDirect['source']['source_journal_id']);

                // Posted expense-claim lines use the claim journal as their source evidence.
                InterfaceDB::execute('INSERT INTO expense_claimants (company_id, claimant_name, is_active) VALUES (:company_id, :name, 1)', ['company_id' => $companyId, 'name' => 'Test Claimant']);
                $claimantId = (int)InterfaceDB::fetchColumn('SELECT id FROM expense_claimants WHERE company_id = :company_id', ['company_id' => $companyId]);
                InterfaceDB::execute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79, 'source_type' => 'expense_register',
                        'source_ref' => 'TEST-CLAIM', 'journal_date' => '2023-02-28', 'description' => 'Test expense claim',
                    ]
                );
                $claimJournalId = (int)InterfaceDB::fetchColumn("SELECT id FROM journals WHERE source_ref = 'TEST-CLAIM'");
                InterfaceDB::execute(
                    'INSERT INTO expense_claims (
                        company_id, accounting_period_id, claimant_id, claim_year, claim_month,
                        period_start, period_end, claim_reference_code, status, posted_journal_id
                     ) VALUES (
                        :company_id, :accounting_period_id, :claimant_id, 2023, 2,
                        :period_start, :period_end, :reference, :status, :journal_id
                     )',
                    [
                        'company_id' => $companyId, 'accounting_period_id' => $ap79, 'claimant_id' => $claimantId,
                        'period_start' => '2023-02-01', 'period_end' => '2023-02-28', 'reference' => 'TEST-CLAIM',
                        'status' => 'posted', 'journal_id' => $claimJournalId,
                    ]
                );
                $claimId = (int)InterfaceDB::fetchColumn("SELECT id FROM expense_claims WHERE claim_reference_code = 'TEST-CLAIM'");
                InterfaceDB::execute(
                    'INSERT INTO expense_claim_lines (expense_claim_id, line_number, expense_date, description, amount, nominal_account_id)
                     VALUES (:claim_id, 1, :expense_date, :description, 120.00, :nominal)',
                    ['claim_id' => $claimId, 'expense_date' => '2023-02-15', 'description' => 'Claim insurance', 'nominal' => $expenseNominal]
                );
                $claimLineId = (int)InterfaceDB::fetchColumn('SELECT id FROM expense_claim_lines WHERE expense_claim_id = :claim_id', ['claim_id' => $claimId]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 120.00, 0.00)', ['journal' => $claimJournalId, 'nominal' => $expenseNominal]);
                InterfaceDB::execute('INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit) VALUES (:journal, :nominal, 0.00, 120.00)', ['journal' => $claimJournalId, 'nominal' => $bankNominal]);
                $harness->assertTrue(!empty($sourceService->verify($companyId, $ap79, 'expense_claim_line', $claimLineId)['success']));
                $candidateReviewContext = $reviewService->fetchContext($companyId, $ap79);
                $harness->assertSame(3, (int)$candidateReviewContext['total_count']);
                $harness->assertSame(3, (int)$candidateReviewContext['pending_count']);
                $harness->assertSame(2, (int)$candidateReviewContext['excluded_count']);

                $harness->assertTrue(!empty($reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'transaction_split_line', 'source_id' => $splitLineId,
                    'status' => 'not_prepaid', 'service_start_date' => '', 'service_end_date' => '', 'notes' => '',
                ], 'test')['success']));
                $harness->assertTrue(!empty($reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'expense_claim_line', 'source_id' => $claimLineId,
                    'status' => 'not_prepaid', 'service_start_date' => '', 'service_end_date' => '', 'notes' => '',
                ], 'test')['success']));

                $save = $reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'transaction',
                    'source_id' => $transactionId,
                    'status' => 'prepaid',
                    'service_start_date' => '2022-12-30',
                    'service_end_date' => '2023-12-29',
                    'notes' => 'Synthetic annual service fixture',
                ], 'test');
                $harness->assertTrue(!empty($save['success']));
                $firstSchedule = (array)$save['schedule'];
                $harness->assertCount(1, (array)$firstSchedule['allocations']);
                $harness->assertSame(18000, (int)$firstSchedule['unallocated_pence']);

                $periodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
                $ap80 = $periodRepository->createPeriod(
                    $companyId,
                    '2023-10-01',
                    '2024-09-30',
                    'AP80'
                );
                $currentScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]);
                $currentSchedule = (new \eel_accounts\Service\PrepaymentScheduleService())->fetchSchedule($currentScheduleId);
                $harness->assertTrue($currentScheduleId !== (int)$firstSchedule['id']);
                $harness->assertCount(2, (array)$currentSchedule['allocations']);
                $harness->assertSame(0, (int)$currentSchedule['unallocated_pence']);
                $harness->assertSame(18000, (int)$currentSchedule['allocations'][1]['expense_pence']);

                // Editing an AP away from a service span still resynchronises a
                // schedule which was formerly allocated to that AP.
                $periodRepository->updatePeriod($companyId, $ap80, 'AP80 moved', '2024-10-01', '2025-09-30');
                $movedScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]);
                $movedSchedule = (new \eel_accounts\Service\PrepaymentScheduleService())->fetchSchedule($movedScheduleId);
                $harness->assertCount(1, (array)$movedSchedule['allocations']);
                $periodRepository->updatePeriod($companyId, $ap80, 'AP80', '2023-10-01', '2024-09-30');
                $currentScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]);
                $currentSchedule = (new \eel_accounts\Service\PrepaymentScheduleService())->fetchSchedule($currentScheduleId);
                $harness->assertCount(2, (array)$currentSchedule['allocations']);

                // An unlocked, unposted nominal change supersedes the current
                // schedule and revokes affected prepayment approvals.
                InterfaceDB::execute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id, tax_treatment, prepayment_candidate, is_active, sort_order)
                     VALUES (:code, :name, \'asset\', :subtype, \'other\', 0, 1, :sort)',
                    ['code' => 'T1151', 'name' => 'Alternative Test Prepayments', 'subtype' => $prepaymentSubtype, 'sort' => 4]
                );
                $alternativeAssetNominal = (int)InterfaceDB::fetchColumn("SELECT id FROM nominal_accounts WHERE code = 'T1151'");
                $approvalBasisBeforeNominalChange = (new \eel_accounts\Service\PrepaymentApprovalContextService())
                    ->buildApprovalBasis($reviewService->fetchContext($companyId, $ap79));
                (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                    $companyId, $ap79, 'prepayment_approvals', $approvalBasisBeforeNominalChange, 'test'
                );
                $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
                $settings = $settingsStore->all();
                $settings['prepayment_asset_nominal_id'] = (string)$alternativeAssetNominal;
                (new \eel_accounts\Service\CompanySettingsService())->saveNominalsSection($settingsStore, $settings);
                $alternativeScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]);
                if ($alternativeScheduleId === $currentScheduleId) {
                    throw new RuntimeException('The unposted nominal change did not supersede the affected schedule.');
                }
                $harness->assertSame($alternativeAssetNominal, (int)InterfaceDB::fetchColumn('SELECT asset_nominal_id FROM prepayment_schedules WHERE id = :id', ['id' => $alternativeScheduleId]));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM year_end_review_acknowledgements WHERE company_id = :company_id AND accounting_period_id = :period_id AND check_code = :check_code', ['company_id' => $companyId, 'period_id' => $ap79, 'check_code' => 'prepayment_approvals']));

                $settingsStore = new \eel_accounts\Store\CompanySettingsStore($companyId);
                $settings = $settingsStore->all();
                $settings['prepayment_asset_nominal_id'] = (string)$assetNominal;
                (new \eel_accounts\Service\CompanySettingsService())->saveNominalsSection($settingsStore, $settings);
                $currentScheduleId = (int)InterfaceDB::fetchColumn('SELECT current_schedule_id FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]);
                $currentSchedule = (new \eel_accounts\Service\PrepaymentScheduleService())->fetchSchedule($currentScheduleId);
                $harness->assertSame($assetNominal, (int)$currentSchedule['asset_nominal_id']);

                // Existing version-1 hashes remain valid. An unposted legacy
                // schedule is then superseded into version 2 on synchronisation.
                $scheduleService = new \eel_accounts\Service\PrepaymentScheduleService();
                $basisFromSchedule = new ReflectionMethod($scheduleService, 'basisFromSchedule');
                $basisFromSchedule->setAccessible(true);
                $basisForVersion = new ReflectionMethod($scheduleService, 'basisForVersion');
                $basisForVersion->setAccessible(true);
                $hashBasis = new ReflectionMethod($scheduleService, 'hash');
                $hashBasis->setAccessible(true);
                $legacyBasis = $basisForVersion->invoke($scheduleService, $basisFromSchedule->invoke($scheduleService, $currentSchedule), 1, true);
                foreach ((array)$legacyBasis['allocations'] as $allocation) {
                    InterfaceDB::execute(
                        'UPDATE prepayment_schedule_periods SET allocation_hash = :hash WHERE schedule_id = :schedule_id AND accounting_period_id = :period_id',
                        ['hash' => (string)$allocation['allocation_hash'], 'schedule_id' => $currentScheduleId, 'period_id' => (int)$allocation['accounting_period_id']]
                    );
                }
                InterfaceDB::execute(
                    'UPDATE prepayment_schedules SET calculation_version = 1, calculation_hash = :hash WHERE id = :id',
                    ['hash' => (string)$hashBasis->invoke($scheduleService, $legacyBasis), 'id' => $currentScheduleId]
                );
                if (empty($scheduleService->validateStoredSnapshotIntegrity($currentScheduleId)['success'])) {
                    throw new RuntimeException('The converted version-1 schedule snapshot did not validate.');
                }
                $legacySync = $scheduleService->syncReviewSchedule((int)$save['review_id'], 'test');
                if (empty($legacySync['success'])) {
                    throw new RuntimeException('The valid unposted version-1 schedule could not be synchronised: ' . implode(' ', (array)($legacySync['errors'] ?? [])));
                }
                if (empty($legacySync['created'])) {
                    throw new RuntimeException('The unposted version-1 schedule was not superseded with version 2.');
                }
                $harness->assertSame(2, (int)$legacySync['schedule']['calculation_version']);
                $currentScheduleId = (int)$legacySync['schedule']['id'];
                $currentSchedule = (array)$legacySync['schedule'];

                $approve = static function (int $periodId) use ($companyId, $reviewService): void {
                    $review = $reviewService->fetchContext($companyId, $periodId);
                    $basis = (new \eel_accounts\Service\PrepaymentApprovalContextService())->buildApprovalBasis($review);
                    (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
                        $companyId,
                        $periodId,
                        'prepayment_approvals',
                        $basis,
                        'test'
                    );
                };

                $approve($ap79);
                $previewContext = $scheduleService->fetchPreviewAdjustmentContext($companyId, $ap79);
                $harness->assertTrue(!empty($previewContext['success']));
                $harness->assertCount(1, (array)($previewContext['adjustments'] ?? []));
                InterfaceDB::execute(
                    'UPDATE transactions SET amount = -571.00 WHERE id = :id',
                    ['id' => $transactionId]
                );
                $invalidPreviewContext = $scheduleService->fetchPreviewAdjustmentContext($companyId, $ap79);
                $harness->assertSame(false, (bool)($invalidPreviewContext['success'] ?? true));
                $harness->assertSame([], (array)($invalidPreviewContext['adjustments'] ?? []));
                $harness->assertTrue((array)($invalidPreviewContext['errors'] ?? []) !== []);
                $invalidCloseContext = (new \eel_accounts\Service\YearEndClosePreviewService())
                    ->pendingBalanceSheetAdjustmentContext($companyId, $ap79, '2023-09-30');
                $harness->assertSame(false, (bool)($invalidCloseContext['reliable'] ?? true));
                $ixbrlMetrics = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())
                    ->fetchClosingMetrics($companyId, $ap79, false, false);
                $harness->assertSame(
                    true,
                    (bool)(($ixbrlMetrics['pending_close_preview'] ?? [])['reliable'] ?? false)
                );
                $harness->assertSame(false, str_contains(
                    implode(' ', array_map('strval', (array)($ixbrlMetrics['warnings'] ?? []))),
                    'prepayment'
                ));
                $cardMetrics = (new \eel_accounts\Service\IxbrlBalanceSheetMetricsService())
                    ->fetchClosingMetrics($companyId, $ap79, true, true);
                $harness->assertSame(
                    false,
                    (bool)(($cardMetrics['pending_close_preview'] ?? [])['reliable'] ?? true)
                );
                $harness->assertTrue(str_contains(
                    implode(' ', array_map('strval', (array)($cardMetrics['warnings'] ?? []))),
                    'prepayment'
                ));
                $invalidProfit = (new \eel_accounts\Service\PreTaxProfitLossService())
                    ->calculate($companyId, $ap79, '2023-09-30', '2022-09-05');
                $harness->assertSame(false, (bool)($invalidProfit['prepayment_preview_reliable'] ?? true));
                $invalidTax = (new \eel_accounts\Service\CorporationTaxComputationService())
                    ->fetchCurrentPeriodEstimate($companyId, $ap79);
                $harness->assertSame(false, (bool)($invalidTax['prepayment_preview_reliable'] ?? true));
                $harness->assertSame('review_required', (string)($invalidTax['confidence_status'] ?? ''));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($invalidTax['warnings'] ?? [])),
                    'prepayment preview is unreliable'
                ));
                $invalidWorkings = (new \eel_accounts\Service\TaxWorkingsService())
                    ->fetchWorkings($companyId, $ap79);
                $harness->assertTrue(!empty($invalidWorkings['available']));
                $harness->assertSame(
                    'review_required',
                    (string)($invalidWorkings['summary']['confidence_status'] ?? '')
                );
                $harness->assertTrue(str_contains(
                    implode(' ', array_map(
                        static fn(array $warning): string => (string)($warning['message'] ?? ''),
                        (array)($invalidWorkings['warnings'] ?? [])
                    )),
                    'prepayment preview is unreliable'
                ));
                InterfaceDB::execute(
                    'UPDATE transactions SET amount = -730.00 WHERE id = :id',
                    ['id' => $transactionId]
                );
                $journalCountBeforeDirectLock = (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals');
                $directUnpostedLock = (new \eel_accounts\Service\YearEndLockService())->lockPeriod($companyId, $ap79, 'test');
                $harness->assertTrue(empty($directUnpostedLock['success']));
                $harness->assertTrue(!(new \eel_accounts\Service\YearEndLockService())->isLocked($companyId, $ap79));
                $harness->assertSame($journalCountBeforeDirectLock, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals'));
                $closePreview = new \eel_accounts\Service\YearEndClosePreviewService();
                $harness->assertSame(-180.00, $closePreview->prepaymentExpenseAdjustmentForPeriod($companyId, $ap79, '2022-09-05', '2023-09-30'));
                $pendingAssetRows = array_values(array_filter(
                    $closePreview->pendingBalanceSheetAdjustments($companyId, $ap79, '2023-09-30'),
                    static fn(array $row): bool => (string)($row['source'] ?? '') === 'pending_prepayment'
                ));
                $harness->assertCount(1, $pendingAssetRows);
                $harness->assertSame(180.00, (float)$pendingAssetRows[0]['debit']);
                $previewProfit79 = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate($companyId, $ap79, '2023-09-30', '2022-09-05');
                $profitLoss = new \eel_accounts\Service\ProfitLossService();
                $summaryBeforePost79 = $profitLoss->getProfitLossSummary($companyId, $ap79);
                $breakdownBeforePost79 = $profitLoss->getProfitLossBreakdown($companyId, $ap79);
                $harness->assertSame(
                    number_format((float)$summaryBeforePost79['operating_expense_total'], 2, '.', ''),
                    number_format(array_sum(array_column((array)$breakdownBeforePost79['expense'], 'amount')), 2, '.', '')
                );
                $harness->assertSame(
                    number_format((float)$summaryBeforePost79['cost_of_sales_total'], 2, '.', ''),
                    number_format(array_sum(array_column((array)$breakdownBeforePost79['cost_of_sales'], 'amount')), 2, '.', '')
                );
                $post79 = $posting->postForAccountingPeriod($companyId, $ap79, 'test');
                $harness->assertTrue(!empty($post79['success']));
                $harness->assertSame(1, (int)$post79['posted_count']);
                $harness->assertSame(18000, (new \eel_accounts\Service\PrepaymentScheduleService())->netPostedForReviewPeriod((int)$save['review_id'], $ap79, 'deferral'));
                $harness->assertSame(0.0, $closePreview->prepaymentExpenseAdjustmentForPeriod($companyId, $ap79, '2022-09-05', '2023-09-30'));
                $postedProfit79 = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate($companyId, $ap79, '2023-09-30', '2022-09-05');
                $harness->assertSame((float)$previewProfit79['profit_before_tax'], (float)$postedProfit79['profit_before_tax']);
                $profitLossAfterPosting = new \eel_accounts\Service\ProfitLossService();
                $summaryAfterPost79 = $profitLossAfterPosting->getProfitLossSummary($companyId, $ap79);
                $breakdownAfterPost79 = $profitLossAfterPosting->getProfitLossBreakdown($companyId, $ap79);
                $harness->assertSame(
                    number_format((float)$summaryBeforePost79['operating_expense_total'], 2, '.', ''),
                    number_format((float)$summaryAfterPost79['operating_expense_total'], 2, '.', '')
                );
                $harness->assertSame($breakdownBeforePost79['expense'], $breakdownAfterPost79['expense']);
                $harness->assertSame($breakdownBeforePost79['cost_of_sales'], $breakdownAfterPost79['cost_of_sales']);
                $harness->assertTrue(!empty((new \eel_accounts\Service\PrepaymentApprovalContextService())->fetchContext($companyId, $ap79)['approval']['current']));

                // A posted version-1 snapshot remains current and verifiable;
                // synchronisation does not rewrite its append-only evidence.
                $postedCurrentSchedule = $scheduleService->fetchSchedule($currentScheduleId);
                $postedLegacyBasis = $basisForVersion->invoke(
                    $scheduleService,
                    $basisFromSchedule->invoke($scheduleService, $postedCurrentSchedule),
                    1,
                    true
                );
                foreach ((array)$postedLegacyBasis['allocations'] as $allocation) {
                    InterfaceDB::execute(
                        'UPDATE prepayment_schedule_periods SET allocation_hash = :hash WHERE schedule_id = :schedule_id AND accounting_period_id = :period_id',
                        ['hash' => (string)$allocation['allocation_hash'], 'schedule_id' => $currentScheduleId, 'period_id' => (int)$allocation['accounting_period_id']]
                    );
                }
                $postedLegacyHash = (string)$hashBasis->invoke($scheduleService, $postedLegacyBasis);
                InterfaceDB::execute(
                    'UPDATE prepayment_schedules SET calculation_version = 1, calculation_hash = :hash WHERE id = :id',
                    ['hash' => $postedLegacyHash, 'id' => $currentScheduleId]
                );
                InterfaceDB::execute(
                    'UPDATE prepayment_schedule_postings SET calculation_hash = :hash WHERE schedule_id = :schedule_id',
                    ['hash' => $postedLegacyHash, 'schedule_id' => $currentScheduleId]
                );
                $harness->assertTrue(!empty($scheduleService->validateStoredSnapshotIntegrity($currentScheduleId)['success']));
                $postedLegacySync = $scheduleService->syncReviewSchedule((int)$save['review_id'], 'test');
                $harness->assertTrue(!empty($postedLegacySync['success']));
                $harness->assertTrue(empty($postedLegacySync['created']));
                $harness->assertSame(1, (int)$postedLegacySync['schedule']['calculation_version']);
                $sourceAllocationId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM prepayment_schedule_periods WHERE schedule_id = :schedule_id AND accounting_period_id = :period_id',
                    ['schedule_id' => $currentScheduleId, 'period_id' => $ap79]
                );
                $sourceAllocationHash = (string)InterfaceDB::fetchColumn('SELECT allocation_hash FROM prepayment_schedule_periods WHERE id = :id', ['id' => $sourceAllocationId]);
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET allocation_hash = :hash WHERE id = :id', ['hash' => str_repeat('a', 64), 'id' => $sourceAllocationId]);
                $tamperedLock = (new \eel_accounts\Service\YearEndLockService())->lockPeriod($companyId, $ap79, 'test');
                $harness->assertTrue(empty($tamperedLock['success']));
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET allocation_hash = :hash WHERE id = :id', ['hash' => $sourceAllocationHash, 'id' => $sourceAllocationId]);
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET is_source_period = 0 WHERE id = :id', ['id' => $sourceAllocationId]);
                $sourceFlagIntegrity = (new \eel_accounts\Service\PrepaymentScheduleService())->validateStoredSnapshotIntegrity($currentScheduleId);
                if (!empty($sourceFlagIntegrity['success'])) {
                    throw new RuntimeException('Source-period flag tampering passed snapshot validation.');
                }
                if (!str_contains(implode(' ', (array)$sourceFlagIntegrity['errors']), 'exactly one source-period allocation')) {
                    throw new RuntimeException('Source-period flag tampering did not report the structural invariant.');
                }
                $releaseAllocationId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM prepayment_schedule_periods WHERE schedule_id = :schedule_id AND accounting_period_id = :period_id',
                    ['schedule_id' => $currentScheduleId, 'period_id' => $ap80]
                );
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET is_source_period = 1 WHERE id = :id', ['id' => $releaseAllocationId]);
                $wrongSourceIntegrity = (new \eel_accounts\Service\PrepaymentScheduleService())->validateStoredSnapshotIntegrity($currentScheduleId);
                if (!str_contains(implode(' ', (array)$wrongSourceIntegrity['errors']), 'marks the wrong accounting period as its source period')) {
                    throw new RuntimeException('Wrong source-period allocation did not report the structural invariant.');
                }
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET is_source_period = 1 WHERE id = :id', ['id' => $sourceAllocationId]);
                $multipleSourceIntegrity = (new \eel_accounts\Service\PrepaymentScheduleService())->validateStoredSnapshotIntegrity($currentScheduleId);
                if (!str_contains(implode(' ', (array)$multipleSourceIntegrity['errors']), 'exactly one source-period allocation')) {
                    throw new RuntimeException('Multiple source-period allocations did not report the structural invariant.');
                }
                InterfaceDB::execute('UPDATE prepayment_schedule_periods SET is_source_period = 0 WHERE id = :id', ['id' => $releaseAllocationId]);
                $journalCountBeforeFinalLock = (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals');
                $finalLock = (new \eel_accounts\Service\YearEndLockService())->lockPeriod($companyId, $ap79, 'test');
                $harness->assertTrue(!empty($finalLock['success']));
                $harness->assertSame($journalCountBeforeFinalLock, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals'));
                (new \eel_accounts\Service\YearEndLockService())->unlockPeriod($companyId, $ap79, 'test');
                $retry79 = $posting->postForAccountingPeriod($companyId, $ap79, 'test');
                $harness->assertTrue(!empty($retry79['success']));
                $harness->assertSame(0, (int)$retry79['posted_count']);

                $approve($ap80);
                $harness->assertSame(180.00, $closePreview->prepaymentExpenseAdjustmentForPeriod($companyId, $ap80, '2023-10-01', '2024-09-30'));
                $previewProfit80 = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate($companyId, $ap80, '2024-09-30', '2023-10-01');
                $post80 = $posting->postForAccountingPeriod($companyId, $ap80, 'test');
                $harness->assertTrue(!empty($post80['success']));
                $harness->assertSame(1, (int)$post80['posted_count']);
                $harness->assertSame(18000, (new \eel_accounts\Service\PrepaymentScheduleService())->netPostedForReviewPeriod((int)$save['review_id'], $ap80, 'release'));
                $postedProfit80 = (new \eel_accounts\Service\PreTaxProfitLossService())->calculate($companyId, $ap80, '2024-09-30', '2023-10-01');
                $harness->assertSame((float)$previewProfit80['profit_before_tax'], (float)$postedProfit80['profit_before_tax']);

                $reopen = $posting->reopenSchedule($companyId, (int)$save['review_id'], 'test');
                $harness->assertTrue(!empty($reopen['success']));
                $harness->assertCount(2, (array)$reopen['journal_ids']);
                $harness->assertSame([], (new \eel_accounts\Service\PrepaymentScheduleService())->fetchPostingNetsForReview((int)$save['review_id']));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn('SELECT COALESCE(current_schedule_id, 0) FROM prepayment_reviews WHERE id = :id', ['id' => (int)$save['review_id']]));

                $resave = $reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'transaction',
                    'source_id' => $transactionId,
                    'status' => 'prepaid',
                    'service_start_date' => '2022-12-30',
                    'service_end_date' => '2023-12-29',
                    'notes' => 'Reopened synthetic annual service fixture',
                ], 'test');
                $harness->assertTrue(!empty($resave['success']));
                $newScheduleId = (int)$resave['schedule']['id'];
                $harness->assertSame(
                    $newScheduleId,
                    (int)InterfaceDB::fetchColumn('SELECT superseded_by_schedule_id FROM prepayment_schedules WHERE id = :id', ['id' => $currentScheduleId])
                );
                $blockedFutureStart = $reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'transaction',
                    'source_id' => $transactionId,
                    'status' => 'prepaid',
                    'service_start_date' => '2023-10-01',
                    'service_end_date' => '2024-09-30',
                    'notes' => 'Synthetic service starts after purchase period',
                ], 'test');
                $harness->assertTrue(empty($blockedFutureStart['success']));
                $harness->assertTrue(str_contains(implode(' ', (array)($blockedFutureStart['errors'] ?? [])), 'Reopen its schedule'));
                $secondReopen = $posting->reopenSchedule($companyId, (int)$save['review_id'], 'test');
                $harness->assertTrue(!empty($secondReopen['success']));
                $futureStart = $reviewService->saveReview($companyId, $ap79, [
                    'source_type' => 'transaction',
                    'source_id' => $transactionId,
                    'status' => 'prepaid',
                    'service_start_date' => '2023-10-01',
                    'service_end_date' => '2024-09-30',
                    'notes' => 'Synthetic service starts after purchase period after explicit reopen',
                ], 'test');
                $harness->assertTrue(!empty($futureStart['success']));
                $futureAllocations = (array)$futureStart['schedule']['allocations'];
                $harness->assertCount(2, $futureAllocations);
                $harness->assertSame(0, (int)$futureAllocations[0]['expense_pence']);
                $harness->assertSame(0, (int)$futureAllocations[0]['overlap_days']);
                $harness->assertSame(73000, (int)$futureAllocations[0]['closing_deferred_pence']);
                $harness->assertSame(73000, (int)$futureAllocations[1]['opening_deferred_pence']);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);
