<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ParticipatorLoanTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\DirectorLoanReconciliationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanReconciliationService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'requires one factual confirmation and posts an attributed same-director reclassification', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 253.00, 0.00, (int)$fixture['primary_party_id'], 'asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 1288.63, (int)$fixture['primary_party_id'], 'liability');

            $before = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)(($before['ct600a'] ?? [])['available'] ?? false));
            $harness->assertTrue(isset(($before['ct600a'] ?? [])['questions']));
            $harness->assertTrue(isset(($before['ct600a'] ?? [])['review']));
            $harness->assertSame(false, array_key_exists('periods', (array)($before['ct600a'] ?? [])));
            $harness->assertSame('253.00', directorLoanReclassificationMoney($before['desired_reclassification_amount'] ?? 0));
            $harness->assertSame('253.00', directorLoanReclassificationMoney($before['pending_adjustment_amount'] ?? 0));
            $harness->assertSame('0.00', directorLoanReclassificationMoney($before['potential_s455_exposure'] ?? 0));
            $harness->assertSame(false, (bool)($before['can_post'] ?? true));
            $harness->assertCount(2, (array)($before['proposed_lines'] ?? []));
            foreach ((array)$before['proposed_lines'] as $line) {
                $harness->assertSame((int)$fixture['primary_party_id'], (int)($line['party_id'] ?? 0));
            }

            $confirmation = $service->saveYearEndReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                'test'
            );
            $harness->assertSame(true, (bool)($confirmation['success'] ?? false));

            $current = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($current['acknowledgement_current'] ?? false));
            $harness->assertSame(true, (bool)($current['can_post'] ?? false));

            $posted = $service->postOffset(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            $harness->assertSame(true, (bool)($posted['success'] ?? false));
            $harness->assertSame(
                \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_SOURCE_TYPE,
                (string)InterfaceDB::fetchColumn(
                    'SELECT j.source_type
                     FROM journals j
                     INNER JOIN journal_entry_metadata jem ON jem.journal_id = j.id
                     WHERE jem.company_id = :company_id
                       AND jem.accounting_period_id = :period_id
                       AND jem.journal_tag = :journal_tag
                     ORDER BY j.id DESC
                     LIMIT 1',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'period_id' => (int)$fixture['accounting_period_id'],
                        'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                    ]
                )
            );
            $after = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('253.00', directorLoanReclassificationMoney($after['posted_reclassification_amount'] ?? 0));
            $harness->assertSame('0.00', directorLoanReclassificationMoney($after['pending_adjustment_amount'] ?? 0));
            $harness->assertSame(true, (bool)($after['acknowledgement_current'] ?? false));
            $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journal_lines jl
                 INNER JOIN journal_entry_metadata jem ON jem.journal_id = jl.journal_id
                 WHERE jem.company_id = :company_id
                   AND jem.accounting_period_id = :period_id
                   AND jem.journal_tag = :journal_tag
                   AND jl.party_id = :party_id',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                    'party_id' => (int)$fixture['primary_party_id'],
                ]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'never offsets balances belonging to different directors', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 500.00, 0.00, (int)$fixture['primary_party_id'], 'primary-asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 500.00, (int)$fixture['other_party_id'], 'other-liability');

            $context = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame('0.00', directorLoanReclassificationMoney($context['desired_reclassification_amount'] ?? 0));
            $harness->assertSame('500.00', directorLoanReclassificationMoney($context['potential_s455_exposure'] ?? 0));
            $harness->assertCount(0, (array)($context['proposed_lines'] ?? []));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'evaluates set-off independently for each party', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $grossTerms = directorLoanReclassificationSavePartyTerms(
                (int)$fixture['company_id'],
                (int)$fixture['other_party_id'],
                false
            );
            $harness->assertSame(true, (bool)($grossTerms['success'] ?? false));

            directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 300.00, 0.00, (int)$fixture['primary_party_id'], 'primary-mixed-asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 500.00, (int)$fixture['primary_party_id'], 'primary-mixed-liability');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 200.00, 0.00, (int)$fixture['other_party_id'], 'other-mixed-asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 400.00, (int)$fixture['other_party_id'], 'other-mixed-liability');

            $context = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $facts = [];
            foreach ((array)($context['party_facts'] ?? []) as $fact) {
                $facts[(int)($fact['party_id'] ?? 0)] = (array)$fact;
            }

            $harness->assertSame('300.00', directorLoanReclassificationMoney($context['desired_reclassification_amount'] ?? 0));
            $harness->assertSame('300.00', directorLoanReclassificationMoney(
                $facts[(int)$fixture['primary_party_id']]['desired_reclassification'] ?? 0
            ));
            $harness->assertSame(true, (bool)(
                $facts[(int)$fixture['primary_party_id']]['set_off_permitted'] ?? false
            ));
            $harness->assertSame('0.00', directorLoanReclassificationMoney(
                $facts[(int)$fixture['other_party_id']]['desired_reclassification'] ?? 0
            ));
            $harness->assertSame(false, (bool)(
                $facts[(int)$fixture['other_party_id']]['set_off_permitted'] ?? true
            ));
            $harness->assertSame('200.00', directorLoanReclassificationMoney(
                $facts[(int)$fixture['other_party_id']]['reportable_asset'] ?? 0
            ));
            $harness->assertSame('400.00', directorLoanReclassificationMoney(
                $facts[(int)$fixture['other_party_id']]['reportable_liability'] ?? 0
            ));

            $warnings = implode(' ', (array)($context['warnings'] ?? []));
            $harness->assertTrue(str_contains($warnings, 'Other Director:'));
            $harness->assertTrue(str_contains($warnings, 'remain gross'));
            $harness->assertSame(false, str_contains($warnings, 'Primary Director:'));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'repairs the combined legacy unattributed offset once and preserves its source journal', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $legacy = (new \eel_accounts\Service\ManualJournalService())->saveTaggedJournal(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'legacy-source',
                '2025-12-31',
                'Legacy Director Loan offset',
                [
                    [
                        'nominal_account_id' => (int)$fixture['liability_nominal_id'],
                        'debit' => '125.00',
                        'credit' => '0.00',
                        'line_description' => 'Legacy offset',
                    ],
                    [
                        'nominal_account_id' => (int)$fixture['asset_nominal_id'],
                        'debit' => '0.00',
                        'credit' => '125.00',
                        'line_description' => 'Legacy offset',
                    ],
                ],
                'system_generated',
                null,
                null,
                'Legacy fixture',
                'test'
            );
            $harness->assertSame(true, (bool)($legacy['success'] ?? false));
            $legacyJournalId = (int)(($legacy['journal'] ?? [])['id'] ?? 0);

            $before = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('125.00', directorLoanReclassificationMoney($before['legacy_unresolved_reclassification_amount'] ?? 0));
            $harness->assertSame('125.00', directorLoanReclassificationMoney($before['legacy_unresolved_reclassification_net_amount'] ?? 0));
            $harness->assertSame([$legacyJournalId], (array)($before['legacy_unresolved_source_journal_ids'] ?? []));
            $blocked = $service->saveYearEndReview((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $harness->assertSame(false, (bool)($blocked['success'] ?? true));

            $repaired = $service->repairLegacyOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($repaired['success'] ?? false));
            $harness->assertSame(true, (bool)($repaired['repaired'] ?? false));
            $harness->assertSame(true, (int)((($repaired['journal'] ?? [])['id'] ?? 0)) > 0);
            $harness->assertSame(false, (int)((($repaired['journal'] ?? [])['id'] ?? 0)) === $legacyJournalId);
            $after = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('0.00', directorLoanReclassificationMoney($after['legacy_unresolved_reclassification_amount'] ?? 0));
            $harness->assertSame(0, count((array)($after['legacy_unresolved_source_journal_ids'] ?? [])));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn('SELECT COUNT(*) FROM journals WHERE id = :journal_id', ['journal_id' => $legacyJournalId]));

            $again = $service->repairLegacyOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($again['success'] ?? false));
            $harness->assertSame(true, (bool)($again['already_current'] ?? false));
            $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM journal_entry_metadata
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
                   AND journal_tag = :journal_tag',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                ]
            ));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'makes the confirmation stale when attribution changes but not when its journal posts', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $assetLineId = directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 100.00, 0.00, (int)$fixture['primary_party_id'], 'asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 150.00, (int)$fixture['primary_party_id'], 'liability');
            $service->saveYearEndReview((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');

            $afterPost = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($afterPost['acknowledgement_current'] ?? false));

            $changed = (new \eel_accounts\Service\DirectorLoanAttributionService())->assignJournalLine(
                (int)$fixture['company_id'],
                $assetLineId,
                (int)$fixture['other_party_id'],
                'test',
                'Move to the correct director.'
            );
            $harness->assertSame(true, (bool)($changed['success'] ?? false));
            $stale = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(false, (bool)($stale['acknowledgement_current'] ?? true));
            $harness->assertSame('stale', (string)($stale['acknowledgement_state'] ?? ''));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'keeps an approved basis current when live party terms become lock snapshots', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 253.00, 0.00, (int)$fixture['primary_party_id'], 'snapshot-basis-asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 1288.63, (int)$fixture['primary_party_id'], 'snapshot-basis-liability');

            $approved = $service->saveYearEndReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                'test'
            );
            $harness->assertSame([], (array)($approved['errors'] ?? []));
            $harness->assertSame(true, (bool)($approved['success'] ?? false));
            $posted = $service->postOffset(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            $harness->assertSame(true, (bool)($posted['success'] ?? false));

            $sectionService = new \eel_accounts\Service\YearEndSectionApprovalService();
            $before = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $beforeSection = $sectionService->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE
            );
            $beforeFact = (array)(($before['party_facts'] ?? [])[0] ?? []);
            $harness->assertSame('live', (string)($beforeFact['terms_source'] ?? ''));
            $harness->assertSame(false, (bool)($beforeFact['terms_snapshot'] ?? true));
            $harness->assertSame(true, (bool)($before['acknowledgement_current'] ?? false));
            $harness->assertSame(true, (bool)($beforeSection['acknowledgement_current'] ?? false));

            $snapshotted = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->snapshotPeriod(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            $harness->assertSame(true, (bool)($snapshotted['success'] ?? false));
            directorLoanReclassificationSetLocked($fixture);

            $after = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $afterSection = $sectionService->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE
            );
            $afterFact = (array)(($after['party_facts'] ?? [])[0] ?? []);
            $harness->assertSame('locked_snapshot', (string)($afterFact['terms_source'] ?? ''));
            $harness->assertSame(true, (bool)($afterFact['terms_snapshot'] ?? false));
            $harness->assertSame((int)($beforeFact['terms_revision'] ?? 0), (int)($afterFact['terms_revision'] ?? -1));
            $harness->assertSame(true, (bool)($after['acknowledgement_current'] ?? false));
            $harness->assertSame(true, (bool)($afterSection['acknowledgement_current'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'makes an open-period approval stale when live party terms change', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 250.00, (int)$fixture['primary_party_id'], 'terms-stale-liability');
            $approved = $service->saveYearEndReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                'test'
            );
            $harness->assertSame([], (array)($approved['errors'] ?? []));
            $harness->assertSame(true, (bool)($approved['success'] ?? false));
            $before = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame(true, (bool)($before['acknowledgement_current'] ?? false));

            $changed = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
                (int)$fixture['company_id'],
                (int)$fixture['primary_party_id'],
                [
                    'interest_rate_percent' => 3.5,
                    'security_type' => 'unsecured',
                    'repayable_on_demand' => true,
                    'repayment_timing' => 'within_12_months',
                    'deferment_right_confirmed' => false,
                    'set_off_right_confirmed' => true,
                    'settlement_intention' => 'simultaneous',
                ],
                'test'
            );
            $harness->assertSame(true, (bool)($changed['success'] ?? false));
            $harness->assertSame(true, (bool)($changed['changed'] ?? false));

            $after = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $section = (new \eel_accounts\Service\YearEndSectionApprovalService())->fetchReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                \eel_accounts\Service\DirectorLoanReconciliationService::YEAR_END_ACKNOWLEDGEMENT_CODE
            );
            $harness->assertSame(false, (bool)($after['acknowledgement_current'] ?? true));
            $harness->assertSame('stale', (string)($after['acknowledgement_state'] ?? ''));
            $harness->assertSame(false, (bool)($section['acknowledgement_current'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'reverses only the reduction in a previously posted cumulative reclassification', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $assetLineId = directorLoanReclassificationInsertLine($fixture, (int)$fixture['asset_nominal_id'], 253.00, 0.00, (int)$fixture['primary_party_id'], 'asset');
            directorLoanReclassificationInsertLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 1288.63, (int)$fixture['primary_party_id'], 'liability');
            $service->saveYearEndReview((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');

            $assetJournalId = (int)InterfaceDB::fetchColumn(
                'SELECT journal_id FROM journal_lines WHERE id = :id',
                ['id' => $assetLineId]
            );
            $correction = (new \eel_accounts\Service\JournalCorrectionService())->reverseAndReplaceJournal(
                (int)$fixture['company_id'],
                $assetJournalId,
                (int)$fixture['accounting_period_id'],
                '2025-12-31',
                'Correct the source balance used by the DLA reconciliation test.',
                [
                    'journal_tag' => 'dla_reconciliation_source_correction',
                    'journal_key' => 'journal:' . $assetJournalId,
                    'journal_date' => '2025-12-31',
                    'description' => 'Corrected DLA source balance',
                    'lines' => [
                        ['nominal_account_id' => (int)$fixture['asset_nominal_id'], 'party_id' => (int)$fixture['primary_party_id'], 'debit' => 100.00, 'credit' => 0.00],
                        ['nominal_account_id' => (int)$fixture['counter_nominal_id'], 'debit' => 0.00, 'credit' => 100.00],
                    ],
                ],
                'test',
                'dla-reconciliation-source:' . $assetJournalId
            );
            $harness->assertSame(true, (bool)($correction['success'] ?? false));
            $stale = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('153.00', directorLoanReclassificationMoney($stale['pending_adjustment_amount'] ?? 0));
            $harness->assertSame(false, (bool)($stale['can_post'] ?? true));

            $ct600a = new \eel_accounts\Service\Ct600aService();
            $ct600a->saveReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                array_fill_keys(array_keys($ct600a->reviewQuestions()), 'no'),
                'director',
                'Test Director',
                'Re-approved after the corrected loan evidence.'
            );
            $service->saveYearEndReview((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], true, 'test');
            $reversed = $service->postOffset((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'test');
            $harness->assertSame(true, (bool)($reversed['success'] ?? false));
            $after = $service->fetchContext((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('100.00', directorLoanReclassificationMoney($after['posted_reclassification_amount'] ?? 0));
            $harness->assertSame('0.00', directorLoanReclassificationMoney($after['pending_adjustment_amount'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'does not propose a same-party set-off when either legal confirmation is absent', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $cleared = directorLoanReclassificationSavePartyTerms(
                (int)$fixture['company_id'],
                (int)$fixture['primary_party_id'],
                false
            );
            $harness->assertSame(true, (bool)($cleared['success'] ?? false));
            directorLoanReclassificationInsertLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                250.00,
                0.00,
                (int)$fixture['primary_party_id'],
                'unsupported-asset'
            );
            directorLoanReclassificationInsertLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                400.00,
                (int)$fixture['primary_party_id'],
                'unsupported-liability'
            );

            $context = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame(false, (bool)($context['set_off_permitted'] ?? true));
            $harness->assertSame('0.00', directorLoanReclassificationMoney($context['desired_reclassification_amount'] ?? 0));
            $harness->assertCount(0, (array)($context['proposed_lines'] ?? []));
            $harness->assertTrue(str_contains(
                implode(' ', (array)($context['warnings'] ?? [])),
                'remain gross'
            ));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanReconciliationService::class, 'reverses a prior automatic set-off when its legal confirmations are removed', static function () use ($harness, $service): void {
        directorLoanReclassificationWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanReclassificationInsertLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                253.00,
                0.00,
                (int)$fixture['primary_party_id'],
                'legacy-auto-asset'
            );
            directorLoanReclassificationInsertLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                1288.63,
                (int)$fixture['primary_party_id'],
                'legacy-auto-liability'
            );
            $service->saveYearEndReview(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                true,
                'test'
            );
            $posted = $service->postOffset(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            $harness->assertSame(true, (bool)($posted['success'] ?? false));

            $cleared = directorLoanReclassificationSavePartyTerms(
                (int)$fixture['company_id'],
                (int)$fixture['primary_party_id'],
                false
            );
            $harness->assertSame(true, (bool)($cleared['success'] ?? false));
            $stale = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame(true, (bool)($stale['requires_set_off_reversal'] ?? false));
            $harness->assertSame('253.00', directorLoanReclassificationMoney($stale['unsupported_posted_set_off_amount'] ?? 0));
            $harness->assertSame(true, (bool)($stale['can_post'] ?? false));
            $harness->assertCount(2, (array)($stale['proposed_lines'] ?? []));

            $reversed = $service->postOffset(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id'],
                'test'
            );
            $harness->assertSame(true, (bool)($reversed['success'] ?? false));
            $after = $service->fetchContext(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame('0.00', directorLoanReclassificationMoney($after['posted_reclassification_amount'] ?? 0));
            $harness->assertSame('0.00', directorLoanReclassificationMoney($after['pending_adjustment_amount'] ?? 0));
            $harness->assertSame('253.00', directorLoanReclassificationMoney(
                (($after['statement'] ?? [])['reportable_asset_receivable'] ?? 0)
            ));
            $harness->assertSame('1288.63', directorLoanReclassificationMoney(
                (($after['statement'] ?? [])['reportable_liability_payable'] ?? 0)
            ));
        });
    });
});

function directorLoanReclassificationWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    foreach ([
        'company_directors',
        'company_parties',
        'company_party_roles',
        'journal_entry_metadata',
        'year_end_review_acknowledgements',
        'year_end_reviews',
        'year_end_section_review_bundles',
        'participator_loan_party_terms',
        'participator_loan_party_terms_audit',
        'participator_loan_party_term_snapshots',
    ] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' schema is not available.');
        }
    }
    InterfaceDB::beginTransaction();
    try {
        StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100']);
        $counterNominalId = StandardNominalTestFixture::id('1000');
        $assetNominalId = StandardNominalTestFixture::id('1200');
        $liabilityNominalId = StandardNominalTestFixture::id('2100');
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'DLA Reclassification Fixture Limited', 'company_number' => 'DRF' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number',
            ['company_number' => 'DRF' . $marker]
        );
        ParticipatorLoanTestFixture::configureNominals($companyId, $assetNominalId, $liabilityNominalId);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            ['company_id' => $companyId, 'label' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_periods (
                company_id, accounting_period_id, sequence_no, period_start, period_end, status
             ) VALUES (
                :company_id, :accounting_period_id, 1, :period_start, :period_end, :status
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $periodId,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
                'status' => 'open',
            ]
        );
        foreach (['Primary Director', 'Other Director'] as $index => $name) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $companyId,
                    'source' => 'companies_house',
                    'external_key' => 'reclass:' . $marker . ':' . $index,
                    'full_name' => $name,
                    'officer_role' => 'director',
                    'appointed_on' => '2020-01-01',
                ]
            );
        }

        $primaryDirectorId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors WHERE company_id = :company_id AND full_name = :name',
            ['company_id' => $companyId, 'name' => 'Primary Director']
        );
        $otherDirectorId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors WHERE company_id = :company_id AND full_name = :name',
            ['company_id' => $companyId, 'name' => 'Other Director']
        );
        $primaryPartyId = ParticipatorLoanTestFixture::createPartyForDirector(
            $companyId,
            $primaryDirectorId,
            'Primary Director'
        );
        $otherPartyId = ParticipatorLoanTestFixture::createPartyForDirector(
            $companyId,
            $otherDirectorId,
            'Other Director'
        );
        foreach ([$primaryPartyId, $otherPartyId] as $partyId) {
            $terms = directorLoanReclassificationSavePartyTerms($companyId, $partyId, true);
            $harness->assertSame(true, (bool)($terms['success'] ?? false));
        }

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
            'counter_nominal_id' => $counterNominalId,
            'primary_party_id' => $primaryPartyId,
            'other_party_id' => $otherPartyId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function directorLoanReclassificationInsertLine(
    array $fixture,
    int $nominalId,
    float $debit,
    float $credit,
    int $partyId,
    string $key
): int {
    $sourceRef = 'dla-reclass:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'DLA reclassification fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => $sourceRef]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, party_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :party_id, :debit, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => $nominalId,
            'party_id' => $partyId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'description' => 'DLA reclassification fixture',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => (int)$fixture['counter_nominal_id'],
            'debit' => number_format($credit, 2, '.', ''),
            'credit' => number_format($debit, 2, '.', ''),
            'description' => 'DLA reclassification fixture counter-entry',
        ]
    );
    $ct600a = new \eel_accounts\Service\Ct600aService();
    $ct600a->saveReview(
        (int)$fixture['company_id'],
        (int)$fixture['accounting_period_id'],
        array_fill_keys(array_keys($ct600a->reviewQuestions()), 'no'),
        'director',
        'Test Director',
        'Director Loan reconciliation fixture.'
    );
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journal_lines WHERE journal_id = :journal_id',
        ['journal_id' => $journalId]
    );
}

function directorLoanReclassificationMoney(mixed $amount): string
{
    return number_format(round((float)$amount, 2), 2, '.', '');
}

function directorLoanReclassificationSavePartyTerms(
    int $companyId,
    int $partyId,
    bool $setOffPermitted
): array {
    return (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
        $companyId,
        $partyId,
        [
            'interest_rate_percent' => 0,
            'security_type' => 'unsecured',
            'repayable_on_demand' => true,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => false,
            'set_off_right_confirmed' => $setOffPermitted,
            'settlement_intention' => $setOffPermitted ? 'simultaneous' : 'independently',
            'advance_interest_rate_percent' => 0,
            'advance_security_type' => 'unsecured',
            'advance_repayment_basis' => 'no_fixed_date',
        ],
        'test'
    );
}

function directorLoanReclassificationSetLocked(array $fixture): void
{
    $params = [
        'company_id' => (int)$fixture['company_id'],
        'accounting_period_id' => (int)$fixture['accounting_period_id'],
        'locked_by' => 'test',
    ];
    if ((int)\InterfaceDB::fetchColumn(
        'SELECT COUNT(*)
         FROM year_end_reviews
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
        $params
    ) > 0) {
        \InterfaceDB::prepareExecute(
            'UPDATE year_end_reviews
             SET is_locked = 1, locked_at = CURRENT_TIMESTAMP, locked_by = :locked_by
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            $params
        );
    } else {
        \InterfaceDB::prepareExecute(
            'INSERT INTO year_end_reviews (
                company_id, accounting_period_id, is_locked, locked_at, locked_by
             ) VALUES (
                :company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by
             )',
            $params
        );
    }
    \eel_accounts\Support\RequestCache::clear();
}
