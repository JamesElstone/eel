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

$harness = new GeneratedServiceClassTestHarness();

$harness->run(YearEndAction::class, static function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof YearEndAction) {
        throw new RuntimeException('Unexpected YearEndAction instance.');
    }

    $harness->check('YearEndAction', 'posts director loan offset journal idempotently', static function () use ($harness, $instance): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness, $instance): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');

            $request = yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $first = $instance->handle($request, createTestPageServiceFramework());
            $second = $instance->handle($request, createTestPageServiceFramework());

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
            $harness->assertSame(true, $third->isSuccess());
            $harness->assertSame(1, InterfaceDB::countWhere('journal_entry_metadata', [
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_TAG,
                'journal_key' => \eel_accounts\Service\DirectorLoanReconciliationService::OFFSET_JOURNAL_KEY,
            ]));

            $offsetDebit = (float)InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(jl.debit), 0)
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
            $harness->assertSame(1200.00, round($offsetDebit, 2));
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
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, status, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, :status, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    'status' => 'locked',
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
                'INSERT INTO year_end_reviews (company_id, accounting_period_id, status, is_locked, locked_at, locked_by)
                 VALUES (:company_id, :accounting_period_id, :status, 1, CURRENT_TIMESTAMP, :locked_by)',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'status' => 'locked',
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

    $harness->check('YearEndAction', 'saves director loan offset acknowledgement', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_reviews') || !InterfaceDB::columnExists('year_end_reviews', 'director_loan_closing_acknowledged_at')) {
                $harness->skip('Director loan year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['asset_nominal_id'], 1000.00, 0.00, 'asset');
            yearEndActionDirectorLoanTestInsertLineJournal($fixture, $fixture['liability_nominal_id'], 0.00, 1500.00, 'liability');

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    'save_director_loan_offset_acknowledgement',
                    ['director_loan_offset_acknowledgement' => ['1', '1']]
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'approval saved'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(director_loan_closing_acknowledged_at, \'\')
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]
            );

            $harness->assertSame(false, $acknowledgedAt === '');
            if (InterfaceDB::columnExists('year_end_reviews', 'director_loan_closing_approval_note')) {
                $harness->assertSame('Approval note from action test.', (string)InterfaceDB::fetchColumn(
                    'SELECT COALESCE(director_loan_closing_approval_note, \'\')
                     FROM year_end_reviews
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    ]
                ));
            }
        });
    });

    $harness->check('YearEndAction', 'saves tax readiness acknowledgement', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_reviews') || !InterfaceDB::columnExists('year_end_reviews', 'tax_readiness_acknowledged_at')) {
                $harness->skip('Tax readiness year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'save_tax_readiness_acknowledgement'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Tax readiness approval saved'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(tax_readiness_acknowledged_at, \'\')
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]
            );

            $harness->assertSame(false, $acknowledgedAt === '');
            if (InterfaceDB::columnExists('year_end_reviews', 'tax_readiness_approval_note')) {
                $harness->assertSame('Approval note from action test.', (string)InterfaceDB::fetchColumn(
                    'SELECT COALESCE(tax_readiness_approval_note, \'\')
                     FROM year_end_reviews
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    ]
                ));
            }
        });
    });

    $harness->check('YearEndAction', 'saves expense position acknowledgement', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            if (!InterfaceDB::tableExists('year_end_reviews') || !InterfaceDB::columnExists('year_end_reviews', 'expense_position_acknowledged_at')) {
                $harness->skip('Expense position year-end acknowledgement schema is not available on the default InterfaceDB connection.');
            }

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'save_expense_position_acknowledgement'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'Expense position approval saved'));
            $acknowledgedAt = (string)InterfaceDB::fetchColumn(
                'SELECT COALESCE(expense_position_acknowledged_at, \'\')
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                 LIMIT 1',
                [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]
            );

            $harness->assertSame(false, $acknowledgedAt === '');
            if (InterfaceDB::columnExists('year_end_reviews', 'expense_position_approval_note')) {
                $harness->assertSame('Approval note from action test.', (string)InterfaceDB::fetchColumn(
                    'SELECT COALESCE(expense_position_approval_note, \'\')
                     FROM year_end_reviews
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'accounting_period_id' => (int)$fixture['accounting_period_id'],
                    ]
                ));
            }
        });
    });

    $harness->check('YearEndAction', 'guarded year-end intents are blocked when active director count is not one', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(2);

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'recalculate'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'exactly 1 active director'));
            if (InterfaceDB::tableExists('year_end_reviews')) {
                $harness->assertSame(0, InterfaceDB::countWhere('year_end_reviews', [
                    'company_id' => (int)$fixture['company_id'],
                    'accounting_period_id' => (int)$fixture['accounting_period_id'],
                ]));
            }
        });
    });

    $harness->check('YearEndAction', 'guarded year-end intents are blocked when company is VAT registered', static function () use ($harness): void {
        yearEndActionDirectorLoanTestWithFixture($harness, static function (array $fixture) use ($harness): void {
            $instance = yearEndActionTestInstanceWithDirectorCount(1);
            InterfaceDB::prepareExecute(
                'UPDATE companies SET is_vat_registered = 1 WHERE id = :company_id',
                ['company_id' => (int)$fixture['company_id']]
            );

            $result = $instance->handle(
                yearEndActionDirectorLoanTestRequest((int)$fixture['company_id'], (int)$fixture['accounting_period_id'], 'recalculate'),
                createTestPageServiceFramework()
            );

            $harness->assertSame(false, $result->isSuccess());
            $harness->assertSame(true, str_contains((string)($result->flashMessages()[0]['message'] ?? ''), 'VAT registered'));
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
            $harness->assertSame(
                'Alex Example using the web_app',
                (string)InterfaceDB::fetchColumn(
                    'SELECT action_by
                       FROM year_end_audit_log
                      WHERE company_id = :company_id
                        AND accounting_period_id = :accounting_period_id
                        AND action = :action
                      ORDER BY id DESC
                      LIMIT 1',
                    [
                        'company_id' => (int)$fixture['company_id'],
                        'accounting_period_id' => (int)$fixture['accounting_period_id'],
                        'action' => 'notes',
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
});

function yearEndActionDirectorLoanTestWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    if (!InterfaceDB::tableExists('nominal_accounts') || !InterfaceDB::tableExists('journal_entry_metadata')) {
        $harness->skip('Ledger metadata tables are not available on the default InterfaceDB connection.');
    }

    $assetNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => '1200']);
    $liabilityNominalId = (int)InterfaceDB::fetchColumn('SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1', ['code' => '2100']);
    if ($assetNominalId <= 0 || $liabilityNominalId <= 0) {
        $harness->skip('Director loan nominal accounts are not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Year End Action DLO Fixture Limited', 'company_number' => 'YEA' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn('SELECT id FROM companies WHERE company_number = :company_number', ['company_number' => 'YEA' . $marker]);
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

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
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
        'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_account_id, :debit, :credit, :line_description)',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
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
            'director_loan_offset_acknowledgement' => '1',
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

function yearEndActionEmptyMonthTestRequest(int $companyId, int $accountingPeriodId, string $intent): RequestFramework
{
    return new RequestFramework(
        [],
        [
            'card_action' => 'YearEnd',
            'intent' => $intent,
            'company_id' => (string)$companyId,
            'accounting_period_id' => (string)$accountingPeriodId,
            'month_start' => '2022-09-01',
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
