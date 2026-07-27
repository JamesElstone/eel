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
    \eel_accounts\Service\YearEndLockService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\YearEndLockService $lockService
    ): void {
        $harness->check(
            \eel_accounts\Service\YearEndLockService::class,
            'reverses a carried prior-period party offset and supports repeated unlock and relock cycles',
            static function () use ($harness, $lockService): void {
                yearEndLockPartyLoanWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $lockService): void {
                        $companyId = (int)$fixture['company_id'];
                        $periodId = (int)$fixture['accounting_period_id'];
                        $priorPeriodId = (int)$fixture['prior_accounting_period_id'];
                        $partyId = (int)$fixture['party_id'];
                        $reconciliation = new \eel_accounts\Service\DirectorLoanReconciliationService();

                        yearEndLockPartyLoanApprove($harness, $reconciliation, $fixture, $priorPeriodId);
                        $originalPosting = $reconciliation->postOffset($companyId, $priorPeriodId, 'prior-lock');
                        $harness->assertSame(true, (bool)($originalPosting['success'] ?? false));
                        $originalJournalId = (int)(($originalPosting['journal'] ?? [])['id'] ?? 0);
                        $harness->assertTrue($originalJournalId > 0);

                        $priorLock = $lockService->lockPeriod($companyId, $priorPeriodId, 'prior-lock');
                        $harness->assertSame(true, (bool)($priorLock['success'] ?? false));
                        $firstLock = $lockService->lockPeriod($companyId, $periodId, 'first-lock');
                        $harness->assertSame(true, (bool)($firstLock['success'] ?? false));
                        $harness->assertSame(1, yearEndLockPartyLoanSnapshotCount($fixture));

                        $sectionReview = (new \eel_accounts\Service\YearEndSectionApprovalService())
                            ->fetchReview($companyId, $periodId, 'director_loan_year_end_review');
                        $harness->assertSame(true, (bool)($sectionReview['available'] ?? false));
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_current
                             FROM year_end_section_review_bundles
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND check_code = :check_code',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'check_code' => 'director_loan_year_end_review',
                            ]
                        ));

                        $unlock = $lockService->unlockPeriod(
                            $companyId,
                            $periodId,
                            'unlock-test',
                            'Reopen party terms and recalculate the offset.'
                        );
                        $harness->assertSame(true, (bool)($unlock['success'] ?? false));
                        $harness->assertTrue(
                            (int)(($unlock['section_review_cache'] ?? [])['refreshed_count'] ?? 0) >= 1
                        );
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_current
                             FROM year_end_section_review_bundles
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND check_code = :check_code',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'check_code' => 'director_loan_year_end_review',
                            ]
                        ));
                        $harness->assertSame(
                            1,
                            (int)(($unlock['director_loan_offset_reversal'] ?? [])['reversed_party_count'] ?? 0)
                        );
                        $harness->assertSame(0, yearEndLockPartyLoanSnapshotCount($fixture));

                        $original = InterfaceDB::fetchOne(
                            'SELECT j.id, j.is_posted, jem.journal_key
                             FROM journals j
                             INNER JOIN journal_entry_metadata jem ON jem.journal_id = j.id
                             WHERE j.id = :journal_id',
                            ['journal_id' => $originalJournalId]
                        );
                        $harness->assertSame($originalJournalId, (int)($original['id'] ?? 0));
                        $harness->assertSame(1, (int)($original['is_posted'] ?? 0));
                        $harness->assertSame(
                            \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
                            (string)($original['journal_key'] ?? '')
                        );

                        $reversal = InterfaceDB::fetchOne(
                            'SELECT j.id
                             FROM journals j
                             INNER JOIN journal_entry_metadata jem ON jem.journal_id = j.id
                             WHERE jem.company_id = :company_id
                               AND jem.accounting_period_id = :accounting_period_id
                               AND jem.journal_tag = :journal_tag
                               AND jem.journal_key LIKE :journal_key_prefix
                             ORDER BY j.id DESC
                             LIMIT 1',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                                'journal_key_prefix' => \eel_accounts\Service\DirectorLoanReconciliationService::UNLOCK_REVERSAL_JOURNAL_KEY_PREFIX . '%',
                            ]
                        );
                        $reversalJournalId = (int)($reversal['id'] ?? 0);
                        $harness->assertTrue($reversalJournalId > 0);
                        $harness->assertSame(
                            [
                                (int)$fixture['asset_nominal_id'] => ['debit' => 100.0, 'credit' => 0.0],
                                (int)$fixture['liability_nominal_id'] => ['debit' => 0.0, 'credit' => 100.0],
                            ],
                            yearEndLockPartyLoanJournalAmounts($reversalJournalId, $partyId)
                        );
                        $harness->assertSame(
                            0.0,
                            yearEndLockPartyLoanNetPostedOffset($fixture)
                        );

                        $updatedTerms = yearEndLockPartyLoanSaveTerms($fixture, 4.25, 'relock-terms');
                        $harness->assertSame(true, (bool)($updatedTerms['success'] ?? false));
                        $harness->assertSame(2, (int)(($updatedTerms['terms'] ?? [])['revision'] ?? 0));
                        yearEndLockPartyLoanApprove($harness, $reconciliation, $fixture);

                        $reposted = $reconciliation->postOffset($companyId, $periodId, 'relock');
                        $harness->assertSame(true, (bool)($reposted['success'] ?? false));
                        $harness->assertTrue((int)(($reposted['journal'] ?? [])['id'] ?? 0) > 0);
                        $harness->assertSame(100.0, yearEndLockPartyLoanNetPostedOffset($fixture));

                        $relock = $lockService->lockPeriod($companyId, $periodId, 'relock');
                        $harness->assertSame(true, (bool)($relock['success'] ?? false));
                        $harness->assertSame(1, yearEndLockPartyLoanSnapshotCount($fixture));
                        $firstRelockSnapshot = InterfaceDB::fetchOne(
                            'SELECT terms_json
                             FROM participator_loan_party_term_snapshots
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND party_id = :party_id
                             LIMIT 1',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'party_id' => $partyId,
                            ]
                        );
                        $firstRelockTerms = json_decode(
                            (string)($firstRelockSnapshot['terms_json'] ?? ''),
                            true
                        );
                        $harness->assertSame(2, (int)($firstRelockTerms['revision'] ?? 0));
                        $harness->assertSame(4.25, (float)($firstRelockTerms['interest_rate_percent'] ?? 0));

                        $secondUnlock = $lockService->unlockPeriod(
                            $companyId,
                            $periodId,
                            'second-unlock-test',
                            'Exercise a second complete unlock and relock cycle.'
                        );
                        $harness->assertSame(true, (bool)($secondUnlock['success'] ?? false));
                        $harness->assertSame(
                            1,
                            (int)(($secondUnlock['director_loan_offset_reversal'] ?? [])['reversed_party_count'] ?? 0)
                        );
                        $harness->assertSame(0, yearEndLockPartyLoanSnapshotCount($fixture));
                        $harness->assertSame(0.0, yearEndLockPartyLoanNetPostedOffset($fixture));
                        $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                            'SELECT COUNT(*)
                             FROM journal_entry_metadata
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND journal_tag = :journal_tag
                               AND journal_key LIKE :journal_key_prefix',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                                'journal_key_prefix' => \eel_accounts\Service\DirectorLoanReconciliationService::UNLOCK_REVERSAL_JOURNAL_KEY_PREFIX . '%',
                            ]
                        ));

                        $secondUpdatedTerms = yearEndLockPartyLoanSaveTerms($fixture, 6.75, 'second-relock-terms');
                        $harness->assertSame(true, (bool)($secondUpdatedTerms['success'] ?? false));
                        $harness->assertSame(3, (int)(($secondUpdatedTerms['terms'] ?? [])['revision'] ?? 0));
                        yearEndLockPartyLoanApprove($harness, $reconciliation, $fixture);
                        $secondReposting = $reconciliation->postOffset(
                            $companyId,
                            $periodId,
                            'second-relock'
                        );
                        $harness->assertSame(true, (bool)($secondReposting['success'] ?? false));
                        $harness->assertSame(100.0, yearEndLockPartyLoanNetPostedOffset($fixture));
                        $secondRelock = $lockService->lockPeriod(
                            $companyId,
                            $periodId,
                            'second-relock'
                        );
                        $harness->assertSame(true, (bool)($secondRelock['success'] ?? false));
                        $harness->assertSame(1, yearEndLockPartyLoanSnapshotCount($fixture));
                        $secondRelockSnapshot = InterfaceDB::fetchOne(
                            'SELECT terms_json
                             FROM participator_loan_party_term_snapshots
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND party_id = :party_id
                             LIMIT 1',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'party_id' => $partyId,
                            ]
                        );
                        $secondRelockTerms = json_decode(
                            (string)($secondRelockSnapshot['terms_json'] ?? ''),
                            true
                        );
                        $harness->assertSame(3, (int)($secondRelockTerms['revision'] ?? 0));
                        $harness->assertSame(6.75, (float)($secondRelockTerms['interest_rate_percent'] ?? 0));

                        $originalAfterSecondCycle = InterfaceDB::fetchOne(
                            'SELECT is_posted FROM journals WHERE id = :journal_id',
                            ['journal_id' => $originalJournalId]
                        );
                        $harness->assertSame(1, (int)($originalAfterSecondCycle['is_posted'] ?? 0));
                        $harness->assertSame(4, (int)InterfaceDB::fetchColumn(
                            'SELECT COUNT(*)
                             FROM journal_entry_metadata
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND journal_tag = :journal_tag',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                            ]
                        ));
                        $harness->assertSame(5, (int)InterfaceDB::fetchColumn(
                            'SELECT COUNT(*)
                             FROM journal_entry_metadata
                             WHERE company_id = :company_id
                               AND journal_tag = :journal_tag',
                            [
                                'company_id' => $companyId,
                                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                            ]
                        ));
                    }
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\YearEndLockService::class,
            'rolls back the complete unlock when frozen party nominal mappings are inconsistent',
            static function () use ($harness, $lockService): void {
                yearEndLockPartyLoanWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $lockService): void {
                        $companyId = (int)$fixture['company_id'];
                        $periodId = (int)$fixture['accounting_period_id'];
                        $priorPeriodId = (int)$fixture['prior_accounting_period_id'];
                        $reconciliation = new \eel_accounts\Service\DirectorLoanReconciliationService();

                        yearEndLockPartyLoanApprove($harness, $reconciliation, $fixture, $priorPeriodId);
                        $posted = $reconciliation->postOffset($companyId, $priorPeriodId, 'rollback-fixture');
                        $harness->assertSame(true, (bool)($posted['success'] ?? false));
                        $originalJournalId = (int)(($posted['journal'] ?? [])['id'] ?? 0);
                        $harness->assertTrue($originalJournalId > 0);
                        $harness->assertSame(
                            true,
                            (bool)($lockService->lockPeriod(
                                $companyId,
                                $priorPeriodId,
                                'rollback-fixture'
                            )['success'] ?? false)
                        );
                        $harness->assertSame(
                            true,
                            (bool)($lockService->lockPeriod(
                                $companyId,
                                $periodId,
                                'rollback-fixture'
                            )['success'] ?? false)
                        );

                        $sourceSnapshot = InterfaceDB::fetchOne(
                            'SELECT terms_json
                             FROM participator_loan_party_term_snapshots
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                             LIMIT 1',
                            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                        );
                        $harness->assertTrue(is_array($sourceSnapshot));
                        InterfaceDB::prepareExecute(
                            'INSERT INTO company_parties (company_id, party_type, legal_name)
                             VALUES (:company_id, :party_type, :legal_name)',
                            [
                                'company_id' => $companyId,
                                'party_type' => 'individual',
                                'legal_name' => 'Inconsistent Snapshot Party ' . (string)$fixture['marker'],
                            ]
                        );
                        $inconsistentPartyId = (int)InterfaceDB::fetchColumn(
                            'SELECT id
                             FROM company_parties
                             WHERE company_id = :company_id AND legal_name = :legal_name',
                            [
                                'company_id' => $companyId,
                                'legal_name' => 'Inconsistent Snapshot Party ' . (string)$fixture['marker'],
                            ]
                        );
                        InterfaceDB::prepareExecute(
                            'INSERT INTO participator_loan_party_term_snapshots (
                                company_id, accounting_period_id, party_id,
                                liability_nominal_account_id, terms_json, created_by
                             ) VALUES (
                                :company_id, :accounting_period_id, :party_id,
                                :liability_nominal_account_id, :terms_json, :created_by
                             )',
                            [
                                'company_id' => $companyId,
                                'accounting_period_id' => $periodId,
                                'party_id' => $inconsistentPartyId,
                                'liability_nominal_account_id' => (int)$fixture['asset_nominal_id'],
                                'terms_json' => (string)($sourceSnapshot['terms_json'] ?? ''),
                                'created_by' => 'corrupt-snapshot-fixture',
                            ]
                        );

                        $snapshotCountBefore = yearEndLockPartyLoanSnapshotCount($fixture);
                        $offsetJournalCountBefore = yearEndLockPartyLoanOffsetJournalCount($companyId);
                        $auditCountBefore = yearEndLockPartyLoanAuditCount($companyId, $periodId);
                        $harness->assertSame(2, $snapshotCountBefore);
                        $harness->assertSame(0, yearEndLockPartyLoanUnlockReversalCount($companyId, $periodId));

                        $failedUnlock = (new \eel_accounts\Service\YearEndChecklistService())
                            ->unlockPeriod(
                                $companyId,
                                $periodId,
                                'rollback-test',
                                'This unlock must roll back.'
                            );

                        $harness->assertSame(false, (bool)($failedUnlock['success'] ?? true));
                        $harness->assertTrue(str_contains(
                            strtolower(implode(' ', (array)($failedUnlock['errors'] ?? []))),
                            'inconsistent participator loan liability nominal mappings'
                        ));
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_locked
                             FROM year_end_reviews
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                             LIMIT 1',
                            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                        ));
                        $harness->assertSame($snapshotCountBefore, yearEndLockPartyLoanSnapshotCount($fixture));
                        $harness->assertSame(
                            $offsetJournalCountBefore,
                            yearEndLockPartyLoanOffsetJournalCount($companyId)
                        );
                        $harness->assertSame(0, yearEndLockPartyLoanUnlockReversalCount($companyId, $periodId));
                        $harness->assertSame(
                            $auditCountBefore,
                            yearEndLockPartyLoanAuditCount($companyId, $periodId)
                        );
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_posted FROM journals WHERE id = :journal_id',
                            ['journal_id' => $originalJournalId]
                        ));
                    }
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\YearEndLockService::class,
            'fails closed when an effective party offset has no frozen liability nominal mapping',
            static function () use ($harness, $lockService): void {
                yearEndLockPartyLoanWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $lockService): void {
                        $companyId = (int)$fixture['company_id'];
                        $periodId = (int)$fixture['accounting_period_id'];
                        $priorPeriodId = (int)$fixture['prior_accounting_period_id'];
                        $reconciliation = new \eel_accounts\Service\DirectorLoanReconciliationService();

                        yearEndLockPartyLoanApprove($harness, $reconciliation, $fixture, $priorPeriodId);
                        $posted = $reconciliation->postOffset(
                            $companyId,
                            $priorPeriodId,
                            'missing-snapshot-fixture'
                        );
                        $harness->assertSame(true, (bool)($posted['success'] ?? false));
                        $originalJournalId = (int)(($posted['journal'] ?? [])['id'] ?? 0);
                        $harness->assertTrue($originalJournalId > 0);
                        $harness->assertSame(
                            true,
                            (bool)($lockService->lockPeriod(
                                $companyId,
                                $priorPeriodId,
                                'missing-snapshot-fixture'
                            )['success'] ?? false)
                        );
                        $harness->assertSame(
                            true,
                            (bool)($lockService->lockPeriod(
                                $companyId,
                                $periodId,
                                'missing-snapshot-fixture'
                            )['success'] ?? false)
                        );
                        $harness->assertSame(1, yearEndLockPartyLoanSnapshotCount($fixture));
                        InterfaceDB::prepareExecute(
                            'DELETE FROM participator_loan_party_term_snapshots
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id',
                            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                        );

                        $offsetJournalCountBefore = yearEndLockPartyLoanOffsetJournalCount($companyId);
                        $auditCountBefore = yearEndLockPartyLoanAuditCount($companyId, $periodId);
                        $failedUnlock = (new \eel_accounts\Service\YearEndChecklistService())
                            ->unlockPeriod(
                                $companyId,
                                $periodId,
                                'missing-snapshot-test',
                                'Frozen liability mapping is intentionally absent.'
                            );

                        $harness->assertSame(false, (bool)($failedUnlock['success'] ?? true));
                        $harness->assertTrue(str_contains(
                            strtolower(implode(' ', (array)($failedUnlock['errors'] ?? []))),
                            'frozen participator loan liability nominal mapping is missing'
                        ));
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_locked
                             FROM year_end_reviews
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                             LIMIT 1',
                            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                        ));
                        $harness->assertSame(0, yearEndLockPartyLoanSnapshotCount($fixture));
                        $harness->assertSame(
                            $offsetJournalCountBefore,
                            yearEndLockPartyLoanOffsetJournalCount($companyId)
                        );
                        $harness->assertSame(0, yearEndLockPartyLoanUnlockReversalCount($companyId, $periodId));
                        $harness->assertSame(
                            $auditCountBefore,
                            yearEndLockPartyLoanAuditCount($companyId, $periodId)
                        );
                        $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                            'SELECT is_posted FROM journals WHERE id = :journal_id',
                            ['journal_id' => $originalJournalId]
                        ));

                        // Missing frozen mapping is harmless when there is no
                        // effective posted party offset to reverse.
                        InterfaceDB::prepareExecute(
                            'UPDATE journals SET is_posted = 0 WHERE id = :journal_id',
                            ['journal_id' => $originalJournalId]
                        );
                        $noOpUnlock = (new \eel_accounts\Service\YearEndChecklistService())
                            ->unlockPeriod(
                                $companyId,
                                $periodId,
                                'missing-snapshot-no-op',
                                'No effective party offset remains.'
                            );
                        $harness->assertSame(true, (bool)($noOpUnlock['success'] ?? false));
                        $harness->assertSame(
                            0,
                            (int)(($noOpUnlock['director_loan_offset_reversal'] ?? [])['reversed_party_count'] ?? -1)
                        );
                        $harness->assertSame(0, yearEndLockPartyLoanUnlockReversalCount($companyId, $periodId));
                    }
                );
            }
        );
    }
);

function yearEndLockPartyLoanWithFixture(
    GeneratedServiceClassTestHarness $harness,
    callable $callback
): void {
    foreach ([
        'companies',
        'accounting_periods',
        'company_settings',
        'company_parties',
        'company_party_roles',
        'nominal_account_subtypes',
        'nominal_accounts',
        'journals',
        'journal_lines',
        'journal_entry_metadata',
        'year_end_reviews',
        'year_end_audit_log',
        'year_end_review_acknowledgements',
        'participator_loan_party_terms',
        'participator_loan_party_terms_audit',
        'participator_loan_party_term_snapshots',
    ] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip('Required table is not available: ' . $table);
        }
    }
    if (!(new \eel_accounts\Service\PrepaymentScheduleService())->hasSchema()) {
        $harness->skip('The automated prepayment schedule schema is not available.');
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        $companyNumber = 'YEL' . strtoupper(substr($marker, 0, 8));
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number)
             VALUES (:company_name, :company_number)',
            [
                'company_name' => 'Year End Party Loan Lifecycle Limited',
                'company_number' => $companyNumber,
            ]
        );
        $companyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number',
            ['company_number' => $companyNumber]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'Prior party loan lifecycle ' . $marker,
                'period_start' => '2024-01-01',
                'period_end' => '2024-12-31',
            ]
        );
        $priorPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'Prior party loan lifecycle ' . $marker]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'Party loan lifecycle ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'Party loan lifecycle ' . $marker]
        );

        $assetNominalId = yearEndLockPartyLoanNominal(
            $marker,
            'director_loan_asset',
            'asset',
            'Lifecycle Participator Loan Asset'
        );
        $liabilityNominalId = yearEndLockPartyLoanNominal(
            $marker,
            'director_loan_liability',
            'liability',
            'Lifecycle Participator Loan Liability'
        );
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('participator_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();

        InterfaceDB::prepareExecute(
            'INSERT INTO company_parties (company_id, party_type, legal_name)
             VALUES (:company_id, :party_type, :legal_name)',
            [
                'company_id' => $companyId,
                'party_type' => 'individual',
                'legal_name' => 'Lifecycle Participator ' . $marker,
            ]
        );
        $partyId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM company_parties
             WHERE company_id = :company_id AND legal_name = :legal_name',
            [
                'company_id' => $companyId,
                'legal_name' => 'Lifecycle Participator ' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
             VALUES (:company_id, :party_id, :role_type, :effective_from)',
            [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'role_type' => 'participator',
                'effective_from' => '2024-01-01',
            ]
        );

        $fixture = [
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'prior_accounting_period_id' => $priorPeriodId,
            'party_id' => $partyId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
        ];
        $saved = yearEndLockPartyLoanSaveTerms($fixture, 1.5, 'first-lock');
        if (empty($saved['success'])) {
            throw new RuntimeException(implode(' ', (array)($saved['errors'] ?? ['Unable to save party terms.'])));
        }
        yearEndLockPartyLoanInsertGrossBalances($fixture);

        $callback($fixture);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function yearEndLockPartyLoanNominal(
    string $marker,
    string $subtypeCode,
    string $accountType,
    string $name
): int {
    $subtypeId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
        ['code' => $subtypeCode]
    );
    if ($subtypeId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_account_subtypes (code, name, parent_account_type, sort_order, is_active)
             VALUES (:code, :name, :parent_account_type, 900, 1)',
            [
                'code' => $subtypeCode,
                'name' => $name,
                'parent_account_type' => $accountType,
            ]
        );
        $subtypeId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_account_subtypes WHERE code = :code LIMIT 1',
            ['code' => $subtypeCode]
        );
    }

    $code = 'YL' . substr(hash('sha256', $marker . $subtypeCode), 0, 10);
    InterfaceDB::prepareExecute(
        'INSERT INTO nominal_accounts (
            code, name, account_type, account_subtype_id, tax_treatment, is_active, sort_order
         ) VALUES (
            :code, :name, :account_type, :account_subtype_id, :tax_treatment, 1, 900
         )',
        [
            'code' => $code,
            'name' => $name,
            'account_type' => $accountType,
            'account_subtype_id' => $subtypeId,
            'tax_treatment' => 'allowable',
        ]
    );

    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => $code]
    );
}

function yearEndLockPartyLoanSaveTerms(
    array $fixture,
    float $interestRate,
    string $actor
): array {
    return (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
        (int)$fixture['company_id'],
        (int)$fixture['party_id'],
        [
            'interest_rate_percent' => $interestRate,
            'security_type' => 'unsecured',
            'repayable_on_demand' => 1,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => 0,
            'set_off_right_confirmed' => 1,
            'settlement_intention' => 'simultaneous',
        ],
        $actor
    );
}

function yearEndLockPartyLoanInsertGrossBalances(array $fixture): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['prior_accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => 'year-end-party-loan:' . (string)$fixture['marker'],
            'journal_date' => '2024-12-31',
            'description' => 'Equal gross Participator Loan asset and liability',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_ref' => 'year-end-party-loan:' . (string)$fixture['marker'],
        ]
    );
    foreach ([
        [(int)$fixture['asset_nominal_id'], 100.0, 0.0],
        [(int)$fixture['liability_nominal_id'], 0.0, 100.0],
    ] as $line) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (
                journal_id, nominal_account_id, party_id, debit, credit, line_description
             ) VALUES (
                :journal_id, :nominal_account_id, :party_id, :debit, :credit, :line_description
             )',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$line[0],
                'party_id' => (int)$fixture['party_id'],
                'debit' => number_format((float)$line[1], 2, '.', ''),
                'credit' => number_format((float)$line[2], 2, '.', ''),
                'line_description' => 'Party-specific gross balance fixture',
            ]
        );
    }
}

function yearEndLockPartyLoanApprove(
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanReconciliationService $service,
    array $fixture,
    ?int $accountingPeriodId = null
): void {
    $approval = $service->saveYearEndReview(
        (int)$fixture['company_id'],
        $accountingPeriodId ?? (int)$fixture['accounting_period_id'],
        true,
        'lifecycle-test'
    );
    $harness->assertSame(true, (bool)($approval['success'] ?? false));
}

function yearEndLockPartyLoanSnapshotCount(array $fixture): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM participator_loan_party_term_snapshots
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
        ]
    );
}

/** @return array<int,array{debit: float, credit: float}> */
function yearEndLockPartyLoanJournalAmounts(int $journalId, int $partyId): array
{
    $rows = InterfaceDB::fetchAll(
        'SELECT nominal_account_id, SUM(debit) AS debit, SUM(credit) AS credit
         FROM journal_lines
         WHERE journal_id = :journal_id AND party_id = :party_id
         GROUP BY nominal_account_id
         ORDER BY nominal_account_id',
        ['journal_id' => $journalId, 'party_id' => $partyId]
    );
    $result = [];
    foreach ($rows as $row) {
        $result[(int)$row['nominal_account_id']] = [
            'debit' => round((float)$row['debit'], 2),
            'credit' => round((float)$row['credit'], 2),
        ];
    }
    return $result;
}

function yearEndLockPartyLoanNetPostedOffset(array $fixture): float
{
    return round((float)InterfaceDB::fetchColumn(
        'SELECT COALESCE(SUM(CASE
                    WHEN jl.nominal_account_id = :asset_nominal_id
                        THEN jl.credit - jl.debit
                    WHEN jl.nominal_account_id = :liability_nominal_id
                        THEN jl.debit - jl.credit
                    ELSE 0
                END) / 2, 0)
         FROM journal_entry_metadata jem
         INNER JOIN journals j ON j.id = jem.journal_id
         INNER JOIN journal_lines jl ON jl.journal_id = j.id
         WHERE jem.company_id = :company_id
           AND jem.journal_tag = :journal_tag
           AND j.is_posted = 1
           AND j.journal_date <= (
               SELECT period_end
               FROM accounting_periods
               WHERE id = :accounting_period_id
                 AND company_id = :period_company_id
               LIMIT 1
           )
           AND jl.party_id = :party_id',
        [
            'asset_nominal_id' => (int)$fixture['asset_nominal_id'],
            'liability_nominal_id' => (int)$fixture['liability_nominal_id'],
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'period_company_id' => (int)$fixture['company_id'],
            'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
            'party_id' => (int)$fixture['party_id'],
        ]
    ), 2);
}

function yearEndLockPartyLoanOffsetJournalCount(int $companyId): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journal_entry_metadata
         WHERE company_id = :company_id AND journal_tag = :journal_tag',
        [
            'company_id' => $companyId,
            'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
        ]
    );
}

function yearEndLockPartyLoanUnlockReversalCount(int $companyId, int $accountingPeriodId): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM journal_entry_metadata
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
           AND journal_tag = :journal_tag
           AND journal_key LIKE :journal_key_prefix',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
            'journal_key_prefix' => \eel_accounts\Service\DirectorLoanReconciliationService::UNLOCK_REVERSAL_JOURNAL_KEY_PREFIX . '%',
        ]
    );
}

function yearEndLockPartyLoanAuditCount(int $companyId, int $accountingPeriodId): int
{
    return (int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM year_end_audit_log
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
    );
}
