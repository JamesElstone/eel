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
$harness->run(DirectorLoanAction::class, static function (
    GeneratedServiceClassTestHarness $harness,
    DirectorLoanAction $action
): void {
    $harness->check(DirectorLoanAction::class, 'implements the action interface', static function () use ($harness, $action): void {
        $harness->assertTrue($action instanceof ActionInterfaceFramework);
    });

    $harness->check(DirectorLoanAction::class, 'ignores unrelated intents', static function () use ($harness, $action): void {
        $result = $action->handle(
            new RequestFramework(
                [],
                ['card_action' => 'DirectorLoan', 'intent' => 'unrelated'],
                ['REQUEST_METHOD' => 'POST'],
                [],
                [],
                null
            ),
            createTestPageServiceFramework()
        );

        $harness->assertSame(true, $result->isSuccess());
        $harness->assertSame([], $result->changedFacts());
        $harness->assertSame([], $result->flashMessages());
    });

    $harness->check(DirectorLoanAction::class, 'saves reporting metadata on a locked period and refreshes statutory reports', static function () use ($harness, $action): void {
        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['2100']);
            $liabilityNominalId = StandardNominalTestFixture::id('2100');
            $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
            $companyNumber = 'DRA' . strtoupper(substr($marker, 0, 7));
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number)
                 VALUES (:company_name, :company_number)',
                ['company_name' => 'DLA Action Fixture Limited', 'company_number' => $companyNumber]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => $companyNumber]
            );
            $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
            $settings->flush();
            InterfaceDB::prepareExecute(
                'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                 VALUES (:company_id, :label, :period_start, :period_end)',
                [
                    'company_id' => $companyId,
                    'label' => 'Locked Action AP',
                    'period_start' => '2025-01-01',
                    'period_end' => '2025-12-31',
                ]
            );
            $periodId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                ['company_id' => $companyId, 'label' => 'Locked Action AP']
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO year_end_reviews (
                    company_id, accounting_period_id, is_locked, locked_at, locked_by
                 ) VALUES (
                    :company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $periodId,
                    'locked_by' => 'test',
                ]
            );

            $result = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'DirectorLoan',
                        'intent' => 'save_director_loan_reporting_presentation',
                        'company_id' => (string)$companyId,
                        'accounting_period_id' => (string)$periodId,
                        'classification' => 'after_more_than_one_year',
                    ],
                    [
                        'REQUEST_METHOD' => 'POST',
                        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
                        'HTTP_ACCEPT' => 'application/json',
                    ],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework()
            );

            $harness->assertSame(true, $result->isSuccess());
            foreach ([
                'director.loan.state',
                'companies.house.snapshot',
                'year.end.companies.house.comparison',
                'ixbrl.readiness',
                'ixbrl.accounts.mapping',
                'ixbrl.facts.preview',
                'ixbrl.generation',
            ] as $fact) {
                $harness->assertTrue(in_array($fact, $result->changedFacts(), true));
            }
            $harness->assertTrue(str_contains(
                (string)($result->flashMessages()[0]['message'] ?? ''),
                'Companies House and iXBRL'
            ));
            $harness->assertSame(1, InterfaceDB::countWhere(
                'director_loan_reporting_presentations',
                ['company_id' => $companyId, 'accounting_period_id' => $periodId]
            ));
            $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                'SELECT is_locked
                 FROM year_end_reviews
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $periodId]
            ));

            $invalid = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'DirectorLoan',
                        'intent' => 'save_director_loan_reporting_presentation',
                        'company_id' => (string)$companyId,
                        'accounting_period_id' => (string)$periodId,
                        'classification' => 'invalid',
                    ],
                    ['REQUEST_METHOD' => 'POST'],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework()
            );
            $harness->assertSame(false, $invalid->isSuccess());
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
