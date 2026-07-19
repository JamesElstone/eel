<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'PageServiceTestFactory.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();

$harness->run(YearEndAction::class, static function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof YearEndAction) {
        throw new RuntimeException('Unexpected YearEndAction instance.');
    }

    $harness->check('YearEndAction', 'posts confirmed director loan reclassification deltas and remains idempotent', static function () use ($harness, $instance): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness, $instance): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');

            $confirmation = $instance->handle(
                yearEndActionDirectorLoanTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'save_director_loan_year_end_review'
                ),
                createTestPageServiceFramework()
            );
            $request = yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $first = $instance->handle($request, createTestPageServiceFramework());
            $second = $instance->handle($request, createTestPageServiceFramework());

            $harness->assertSame(true, $confirmation->isSuccess());
            $harness->assertSame(true, $first->isSuccess());
            $harness->assertSame(true, $second->isSuccess());
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 200.00, 0.00, 'asset-increase');
            $third = $instance->handle($request, createTestPageServiceFramework());
            $harness->assertSame(false, $third->isSuccess());
            $reconfirmed = $instance->handle(
                yearEndActionDirectorLoanTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'save_director_loan_year_end_review'
                ),
                createTestPageServiceFramework()
            );
            $fourth = $instance->handle($request, createTestPageServiceFramework());
            $harness->assertSame(true, $reconfirmed->isSuccess());
            $harness->assertSame(true, $fourth->isSuccess());
            $harness->assertSame(2, (int)InterfaceDB::fetchColumn(
                'SELECT COUNT(*)
                 FROM journal_entry_metadata
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND journal_tag = :journal_tag',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                ]
            ));

            $offsetSigned = (float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                 FROM journal_entry_metadata jem
                 INNER JOIN journal_lines jl ON jl.journal_id = jem.journal_id
                 WHERE jem.company_id = :company_id
                   AND jem.accounting_period_id = :accounting_period_id
                   AND jem.journal_tag = :journal_tag
                   AND jl.nominal_account_id = :nominal_account_id',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                    'nominal_account_id' => (int)$fixture['liability_nominal_id'],
                ]
            );
            $harness->assertSame(1200.00, round($offsetSigned, 2));
        });
    });

    $harness->check('YearEndAction', 'locked period blocks director loan offset posting', static function () use ($harness, $instance): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness, $instance): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_reviews')) {
                $harness->skip('Year-end review table is not available on the default InterfaceDB connection.');
            }

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'locked_by' => 'test',
                ]
            );

            $result = $instance->handle(yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id']), createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'locked'));
        });
    });

    $harness->check('YearEndAction', 'locked period blocks notes changes', static function () use ($harness, $instance): void {
        foreach (['companies', 'accounting_periods', 'year_end_reviews'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            $marker = (string)random_int(100000, 999999);
            $companyId = (int)('91' . $marker);
            $accountingPeriodId = (int)('92' . $marker);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (id, company_name, company_number, is_active)
                 VALUES (:id, :company_name, :company_number, 1)',
                ['id' => $companyId, 'company_name' => 'Year End Locked Notes ' . $marker, 'company_number' => 'YLN' . substr($marker, 0, 5)]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                 VALUES (:id, :company_id, :label, :period_start, :period_end)',
                ['id' => $accountingPeriodId, 'company_id' => $companyId, 'label' => 'Locked Notes FY', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'locked_by' => 'test',
                ]
            );

            $result = $instance->handle(yearEndActionDirectorLoanTestRequest($companyId, $accountingPeriodId, 'save_notes'), createTestPageServiceFramework());

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'locked'));
            $harness->assertSame('', (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(review_notes, \'\')
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            ));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check('YearEndAction', 'saves the single director loan year-end confirmation without a note', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_review_acknowledgements') || !InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')) {
                $harness->skip('Director loan year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'save_director_loan_year_end_review',
                    ['director_loan_year_end_review' => '1']
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Year End Review saved'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(acknowledged_at, \'\')
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND check_code = :check_code
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'check_code' => 'director_loan_year_end_review',
                ]
            );

            $harness->assertSame(false, $acknowledgedAt === '');
            $harness->assertSame('', (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(note, \'\') FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code',
                ['company_id' => (int)$fixture['company_id'], 'accounting_period_id' => (int)$fixture['accounting_period_id'], 'check_code' => 'director_loan_year_end_review']
            ));
            $harness->assertSame(64, strlen((string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(basis_hash, \'\') FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code',
                ['company_id' => (int)$fixture['company_id'], 'accounting_period_id' => (int)$fixture['accounting_period_id'], 'check_code' => 'director_loan_year_end_review']
            )));
        });
    });

    $harness->check('YearEndAction', 'rejects tax readiness acknowledgement until the supported Year End profile is confirmed', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_review_acknowledgements') || !InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')) {
                $harness->skip('Tax readiness year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'save_tax_readiness_acknowledgement'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'blocking year-end check'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(acknowledged_at, \'\')
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND check_code = :check_code
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'check_code' => 'tax_readiness_acknowledgement',
                ]
            );

            $harness->assertSame(true, $acknowledgedAt === '');
            $harness->assertSame('', (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(note, \'\') FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code',
                ['company_id' => (int)$fixture['company_id'], 'accounting_period_id' => (int)$fixture['accounting_period_id'], 'check_code' => 'tax_readiness_acknowledgement']
            ));
        });
    });

    $harness->check('YearEndAction', 'saves expense position acknowledgement', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            // Expense confirmation is not director-dependent. A two-director
            // response would fail immediately if the action called Companies House.
            $instance = yearEndActionTestInstanceWithDirectorCount(2);
            if (!InterfaceDB::tableExists('year_end_review_acknowledgements') || !InterfaceDB::columnExists('year_end_review_acknowledgements', 'basis_hash')) {
                $harness->skip('Expense position year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'save_expense_position_acknowledgement'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(['year.end.expenses.confirmation'], $result->changedFacts());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Expense position approval saved'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(acknowledged_at, \'\')
                 FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND check_code = :check_code
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'check_code' => 'expense_position_acknowledgement',
                ]
            );

            $harness->assertSame(false, $acknowledgedAt === '');
            $harness->assertSame('Approval note from action test.', (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(note, \'\') FROM year_end_review_acknowledgements
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id AND check_code = :check_code',
                ['company_id' => (int)$fixture['company_id'], 'accounting_period_id' => (int)$fixture['accounting_period_id'], 'check_code' => 'expense_position_acknowledgement']
            ));
        });
    });

    $harness->check('YearEndAction', 'does not impose a single-director guard on year-end intents', static function () use ($harness): void {
        $method = new ReflectionMethod(YearEndAction::class, 'requiresSingleDirectorCheck');
        $method->setAccessible(true);
        $instance = yearEndActionTestInstanceWithDirectorCount(2);

        $harness->assertSame(false, (bool)$method->invoke($instance, 'recalculate'));
        $harness->assertSame(false, (bool)$method->invoke($instance, 'save_director_loan_year_end_review'));
    });

    $harness->check('YearEndAction', 'year-end intents are blocked after LIVE HMRC VAT confirmation', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            InterfaceDB::prepareExecute(
                'UPDATE companies
                    SET is_vat_registered = 1,
                        vat_validation_source = :validation_source,
                        vat_validation_mode = :validation_mode,
                        vat_validation_status = :validation_status
                  WHERE id = :company_id',
                [
                    'validation_source' => 'hmrc',
                    'validation_mode' => 'LIVE',
                    'validation_status' => 'valid',
                    'company_id' => (int)$fixture['company_id'],
                ]
            );

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'recalculate'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'LIVE HMRC VAT API'));

            $expenseResult = $instance->handle(
                yearEndActionDirectorLoanTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'save_expense_position_acknowledgement'
                ),
                createTestPageServiceFramework()
            );
            $harness->assertSame(false, $expenseResult->isSuccess());
            $harness->assertSame(true, str_contains((string)($expenseResult->flashMessages()[0]['message'] ?? ''), 'VAT registration'));

            if (InterfaceDB::tableExists('year_end_reviews')) {
                $harness->assertSame(0, InterfaceDB::countWhere('year_end_reviews', [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]));
            }
        });
    });

    $harness->check('YearEndAction', 'save_notes is not blocked by director-count guard', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(2);
            $actorUser = (new UserAuthenticationService())->createUser(
                'Alex Example',
                'year-end-notes-' . bin2hex(random_bytes(4)) . '@example.test',
                'Strong Password 1!'
            );
            $actorUserId = (int)(($actorUser['user'] ?? [])['id'] ?? 0);
            $harness->assertSame(true, $actorUserId > 0);
            authenticateTestSession($actorUserId);

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'save_notes'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'exactly 1 active director'));
            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(
                'Notes from director eligibility test.',
                (string)InterfaceDB::fetchColumn(
                    'SELECT review_notes
                       FROM year_end_reviews
                      WHERE company_id = :company_id
                        AND accounting_period_id = :accounting_period_id',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    ]
                )
            );
        });
    });

    $harness->check('YearEndAction', 'acknowledges and reopens review checks without director guard', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            if (!InterfaceDB::tableExists('year_end_review_acknowledgements')) {
                $harness->skip('Year-end review acknowledgement table is not available on the default InterfaceDB connection.');
            }

            $instance = yearEndActionTestInstanceWithDirectorCount(2);
            $acknowledge = $instance->handle(
                yearEndActionReviewCheckRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'acknowledge_review_check'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $acknowledge->isSuccess());
            $harness->assertSame(1, InterfaceDB::countWhere('year_end_review_acknowledgements', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'check_code' => 'cut_off_journals_review',
            ]));

            $reopen = $instance->handle(
                yearEndActionReviewCheckRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'reopen_review_check'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $reopen->isSuccess());
            $harness->assertSame(0, InterfaceDB::countWhere('year_end_review_acknowledgements', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'check_code' => 'cut_off_journals_review',
            ]));

            $prepaymentApproval = $instance->handle(
                yearEndActionReviewCheckRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'acknowledge_review_check', 'prepayment_approvals'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $prepaymentApproval->isSuccess());
            $harness->assertSame(true, in_array('year.end.checklist', $prepaymentApproval->changedFacts(), true));
            $harness->assertSame(true, in_array('year.end.audit.log', $prepaymentApproval->changedFacts(), true));
            $harness->assertSame(false, in_array('prepayments.state', $prepaymentApproval->changedFacts(), true));
            $harness->assertSame(false, in_array('year.end.state', $prepaymentApproval->changedFacts(), true));
            $harness->assertSame(1, InterfaceDB::countWhere('year_end_review_acknowledgements', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'check_code' => 'prepayment_approvals',
            ]));
        });
    });

    $harness->check('YearEndAction', 'rejects filing basis review acknowledgement', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(2);
            $result = $instance->handle(
                yearEndActionReviewCheckRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'acknowledge_review_check', 'filing_basis_reminder'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'cannot be cleared by acknowledgement'));
            if (InterfaceDB::tableExists('year_end_review_acknowledgements')) {
                $harness->assertSame(0, InterfaceDB::countWhere('year_end_review_acknowledgements', [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'check_code' => 'filing_basis_reminder',
                ]));
            }
        });
    });

    $harness->check('YearEndAction', 'confirms and revokes empty month confirmations', static function () use ($harness): void {
        yearEndActionEmptyMonthTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(2);
            $actorUser = (new UserAuthenticationService())->createUser(
                'Alex Example',
                'year-end-empty-month-' . bin2hex(random_bytes(4)) . '@example.test',
                'Strong Password 1!'
            );
            $actorUserId = (int)(($actorUser['user'] ?? [])['id'] ?? 0);
            $harness->assertSame(true, $actorUserId > 0);
            authenticateTestSession($actorUserId);

            $confirm = $instance->handle(
                yearEndActionEmptyMonthTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'confirm_empty_month'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $confirm->isSuccess());
            $harness->assertSame(true, in_array('year.end.empty.month.confirmations', $confirm->changedFacts(), true));
            $harness->assertSame(true, str_contains((string)($confirm->flashMessages()[0]['message'] ?? ''), 'saved'));
            $harness->assertSame(1, InterfaceDB::countWhere('accounting_period_month_confirmations', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'month_start' => '2022-09-01',
                'revoked_at' => null,
            ]));
            $confirmedBy = (string)InterfaceDB::fetchColumn(
                'SELECT confirmed_by
                   FROM accounting_period_month_confirmations
                  WHERE company_id = :company_id
                    AND accounting_period_id = :accounting_period_id
                    AND month_start = :month_start
                    AND revoked_at IS NULL
                  LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'month_start' => '2022-09-01',
                ]
            );
            $harness->assertSame(true, str_contains($confirmedBy, 'using the web_app'));
            $harness->assertSame(true, trim($confirmedBy) !== '');

            $revoke = $instance->handle(
                yearEndActionEmptyMonthTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'revoke_empty_month'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $revoke->isSuccess());
            $harness->assertSame(true, in_array('year.end.empty.month.confirmations', $revoke->changedFacts(), true));
            $harness->assertSame(0, InterfaceDB::countWhere('accounting_period_month_confirmations', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'month_start' => '2022-09-01',
                'revoked_at' => null,
            ]));
        });
    });

    $harness->check('YearEndAction', 'confirms multiple empty months from one approval', static function () use ($harness): void {
        yearEndActionEmptyMonthTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(2);

            $confirm = $instance->handle(
                yearEndActionEmptyMonthTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'confirm_empty_months',
                    ['2022-09-01', '2022-11-01']
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $confirm->isSuccess());
            $harness->assertSame(true, str_contains((string)($confirm->flashMessages()[0]['message'] ?? ''), 'confirmations saved'));
            $harness->assertSame(2, InterfaceDB::countWhere('accounting_period_month_confirmations', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'revoked_at' => null,
            ]));
            $harness->assertSame(1, InterfaceDB::countWhere('accounting_period_month_confirmations', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'month_start' => '2022-09-01',
                'revoked_at' => null,
            ]));
            $harness->assertSame(1, InterfaceDB::countWhere('accounting_period_month_confirmations', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'month_start' => '2022-11-01',
                'revoked_at' => null,
            ]));
        });
    });
});

function yearEndActionDirectorLoanTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journal_entry_metadata')) {
        $harness->skip('Ledger metadata tables are not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    try {
        StandardNominalTestFixture::ensureNominals(['1200', '2100']);
        $assetNominalId = StandardNominalTestFixture::id('1200');
        $liabilityNominalId = StandardNominalTestFixture::id('2100');

        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number, incorporation_date) VALUES (:company_name, :company_number, :incorporation_date)',
            ['company_name' => 'Year End Action DLO Fixture Limited', 'company_number' => 'YEA' . $marker, 'incorporation_date' => '2025-01-01']
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'YEA' . $marker]);
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'YEA ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'YEA ' . $marker]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO company_directors (
                company_id, source, external_key, full_name, officer_role, appointed_on, is_active
             ) VALUES (
                :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
             )',
            [
                'company_id' => $companyId,
                'source' => 'companies_house',
                'external_key' => 'year-end-action:' . $marker,
                'full_name' => 'Primary Director',
                'officer_role' => 'director',
                'appointed_on' => '2020-01-01',
            ]
        );
        $directorId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        if (InterfaceDB::tableExists('corporation_tax_periods')) {
            (new \eel_accounts\Service\CorporationTaxPeriodService())->syncForAccountingPeriod($companyId, $accountingPeriodId);
        }

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
            'director_id' => $directorId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function yearEndActionDirectorLoanTestInsertLineJournal(array $fixture, int $nominalId, float $debit, float $credit, string $key): void
{
    $sourceRef = 'test-year-end-action-dlo:' . $fixture['marker'] . ':' . $key;
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'Year end action DLO fixture ' . $key,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, director_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :director_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'director_id' => (int)$fixture['director_id'],
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Year end action DLO fixture',
        ]
    );
}

function yearEndActionDirectorLoanTestRequest(int $companyId, int $accountingPeriodId, string $intent = 'post_director_loan_offset', array $postOverrides = []): RequestFramework
{
    return new RequestFramework(
        [],
        array_merge([
            'card_action' => 'YearEnd',
            'intent' => $intent,
            'company_id' => (string)$companyId,
            'accounting_period_id' => (string)$accountingPeriodId,
            'review_notes' => 'Notes from director eligibility test.',
            'approval_note' => 'Approval note from action test.',
            'director_loan_year_end_review' => '1',
            'tax_readiness_acknowledgement' => '1',
            'expense_position_acknowledgement' => '1',
        ], $postOverrides),
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
        null
    );
}

function yearEndActionEmptyMonthTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    foreach (['companies', 'accounting_periods', 'company_accounts', 'statement_uploads', 'statement_import_rows', 'accounting_period_month_confirmations'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available on the default InterfaceDB connection.');
        }
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . 'empty-month-action' . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number, incorporation_date)
             VALUES (:company_name, :company_number, :incorporation_date)',
            [
                'company_name' => 'Year End Action Empty Month Fixture Limited',
                'company_number' => 'YEEM' . $marker,
                'incorporation_date' => '2022-09-14',
            ]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'YEEM' . $marker]);
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            [
                'company_id' => $companyId,
                'label' => 'YEEM ' . $marker,
                'period_start' => '2022-09-01',
                'period_end' => '2023-08-31',
            ]
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'YEEM ' . $marker]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO company_accounts (company_id, account_name, account_type, is_active)
             VALUES (:company_id, :account_name, :account_type, 1)',
            [
                'company_id' => $companyId,
                'account_name' => 'Action Fixture Current Account ' . $marker,
                'account_type' => 'bank',
            ]
        );
        $accountId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_accounts WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
            ['company_id' => $companyId]
        );

        yearEndActionEmptyMonthInsertStatement($marker, $companyId, $periodId, $accountId);

        $callback([
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function yearEndActionEmptyMonthInsertStatement(string $marker, int $companyId, int $periodId, int $accountId): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id,
            accounting_period_id,
            account_id,
            source_type,
            workflow_status,
            statement_month,
            original_filename,
            stored_filename,
            file_sha256,
            date_range_start,
            date_range_end,
            source_headers_json,
            rows_parsed,
            rows_ready_to_import
        ) VALUES (
            :company_id,
            :accounting_period_id,
            :account_id,
            :source_type,
            :workflow_status,
            :statement_month,
            :original_filename,
            :stored_filename,
            :file_sha256,
            :date_range_start,
            :date_range_end,
            :source_headers_json,
            1,
            1
        )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'account_id' => $accountId,
            'source_type' => 'bank_account',
            'workflow_status' => 'staged',
            'statement_month' => '2022-10-01',
            'original_filename' => 'year-end-empty-action-' . $marker . '.csv',
            'stored_filename' => 'year-end-empty-action-' . $marker . '.csv',
            'file_sha256' => hash('sha256', 'year-end-empty-action-' . $marker),
            'date_range_start' => '2022-10-01',
            'date_range_end' => '2022-10-31',
            'source_headers_json' => '[]',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id ORDER BY id DESC LIMIT 1',
        ['company_id' => $companyId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_import_rows (
            upload_id,
            row_number,
            raw_json,
            source_account,
            source_created,
            source_description,
            source_amount,
            source_balance,
            source_currency,
            accounting_period_id,
            chosen_txn_date,
            chosen_date_source,
            normalised_description,
            normalised_amount,
            normalised_balance,
            normalised_currency,
            row_hash,
            validation_status
        ) VALUES (
            :upload_id,
            1,
            :raw_json,
            :source_account,
            :source_created,
            :source_description,
            :source_amount,
            :source_balance,
            :source_currency,
            :accounting_period_id,
            :chosen_txn_date,
            :chosen_date_source,
            :normalised_description,
            :normalised_amount,
            :normalised_balance,
            :normalised_currency,
            :row_hash,
            :validation_status
        )',
        [
            'upload_id' => $uploadId,
            'raw_json' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'source_account' => 'Action Fixture Current Account',
            'source_created' => '2022-10-05',
            'source_description' => 'First later transaction',
            'source_amount' => '42.50',
            'source_balance' => '42.50',
            'source_currency' => 'GBP',
            'accounting_period_id' => $periodId,
            'chosen_txn_date' => '2022-10-05',
            'chosen_date_source' => 'created',
            'normalised_description' => 'First later transaction',
            'normalised_amount' => '42.50',
            'normalised_balance' => '42.50',
            'normalised_currency' => 'GBP',
            'row_hash' => hash('sha256', 'year-end-empty-action-row-' . $marker),
            'validation_status' => 'valid',
        ]
    );
}

function yearEndActionEmptyMonthTestRequest(int $companyId, int $accountingPeriodId, string $intent, string|array $monthStart = '2022-09-01'): RequestFramework
{
    return new RequestFramework(
        [],
        [
            'card_action' => 'YearEnd',
            'intent' => $intent,
            'company_id' => (string)$companyId,
            'accounting_period_id' => (string)$accountingPeriodId,
            'month_start' => $monthStart,
            'confirmation_notes' => 'No financial activity before bank account opening.',
        ],
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
        null
    );
}

function yearEndActionReviewCheckRequest(int $companyId, int $accountingPeriodId, string $intent, string $checkCode = 'cut_off_journals_review'): RequestFramework
{
    return new RequestFramework(
        [],
        [
            'card_action' => 'YearEnd',
            'intent' => $intent,
            'company_id' => (string)$companyId,
            'accounting_period_id' => (string)$accountingPeriodId,
            'check_code' => $checkCode,
            'review_acknowledgement_note' => 'Reviewed for test.',
        ],
        ['REQUEST_METHOD' => 'POST', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json'],
        [],
        ['X-AntiFraud-Client-Device-ID' => testCurrentAntiFraudDeviceId()],
        null
    );
}

function yearEndActionTestInstanceWithDirectorCount(int $directorCount): YearEndAction
{
    $service = new \eel_accounts\Service\CompaniesHouseService(
        'TEST',
        20,
        static function (array $request) use ($directorCount): array {
            $items = [];
            for ($index = 0; $index < $directorCount; $index++) {
                $items[] = ['officer_role' => 'director', 'name' => 'Director ' . ($index + 1)];
            }

            return [
                'status_code' => 200,
                'headers' => [],
                'body' => json_encode([
                    'items' => $items,
                    'items_per_page' => 100,
                    'start_index' => 0,
                    'total_results' => count($items),
                ], JSON_UNESCAPED_SLASHES),
                'url' => 'https://example.test' . (string)($request['path'] ?? ''),
            ];
        }
    );

    return new YearEndAction(new \eel_accounts\Service\CompanyDirectorEligibilityService($service));
}
