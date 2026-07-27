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

    $harness->check(DirectorLoanAction::class, 'saves party terms for an open period and refreshes statutory reports', static function () use ($harness, $action): void {
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
            $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
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
                'INSERT INTO company_parties (company_id, party_type, legal_name)
                 VALUES (:company_id, :party_type, :legal_name)',
                [
                    'company_id' => $companyId,
                    'party_type' => 'individual',
                    'legal_name' => 'Action Participator',
                ]
            );
            $partyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_parties
                 WHERE company_id = :company_id AND legal_name = :legal_name',
                ['company_id' => $companyId, 'legal_name' => 'Action Participator']
            );

            $baseInput = [
                'card_action' => 'DirectorLoan',
                'intent' => 'save_participator_loan_party_terms',
                'company_id' => (string)$companyId,
                'accounting_period_id' => (string)$periodId,
                'party_id' => (string)$partyId,
                'interest_rate_percent' => '2.5',
                'security_type' => 'secured',
                'set_off_right_confirmed' => '1',
                'settlement_intention' => 'simultaneous',
            ];
            foreach ([null, 'not_a_basis'] as $invalidBasis) {
                $invalidInput = $baseInput;
                if ($invalidBasis !== null) {
                    $invalidInput['repayment_basis'] = $invalidBasis;
                }
                $invalidBasisResult = $action->handle(
                    new RequestFramework(
                        [],
                        $invalidInput,
                        ['REQUEST_METHOD' => 'POST'],
                        [],
                        [],
                        null
                    ),
                    createTestPageServiceFramework()
                );
                $harness->assertSame(false, $invalidBasisResult->isSuccess());
                $harness->assertTrue(str_contains(
                    strtolower((string)($invalidBasisResult->flashMessages()[0]['message'] ?? '')),
                    'repayment basis'
                ));
            }
            $harness->assertSame(0, InterfaceDB::countWhere(
                'participator_loan_party_terms',
                ['company_id' => $companyId, 'party_id' => $partyId]
            ));

            $repaymentMappings = [
                'on_demand' => [1, 'within_12_months', 0],
                'within_12_months' => [0, 'within_12_months', 0],
                'after_12_months' => [0, 'after_12_months', 1],
            ];
            $result = null;
            foreach ($repaymentMappings as $basis => $expected) {
                $result = $action->handle(
                    new RequestFramework(
                        [],
                        $baseInput + ['repayment_basis' => $basis],
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
                $stored = InterfaceDB::fetchOne(
                    'SELECT repayable_on_demand, repayment_timing, deferment_right_confirmed
                     FROM participator_loan_party_terms
                     WHERE company_id = :company_id AND party_id = :party_id',
                    ['company_id' => $companyId, 'party_id' => $partyId]
                );
                $harness->assertSame((int)$expected[0], (int)($stored['repayable_on_demand'] ?? -1));
                $harness->assertSame((string)$expected[1], (string)($stored['repayment_timing'] ?? ''));
                $harness->assertSame((int)$expected[2], (int)($stored['deferment_right_confirmed'] ?? -1));
            }

            $harness->assertTrue($result instanceof ActionResultFramework);
            $harness->assertSame(true, $result->isSuccess());
            foreach ([
                'director.loan.state',
                'year.end.director.loan.offset',
                'companies.house.snapshot',
                'year.end.companies.house.comparison',
                'year.end.state',
                'ixbrl.readiness',
                'ixbrl.accounts.mapping',
                'ixbrl.facts.preview',
                'ixbrl.generation',
            ] as $fact) {
                $harness->assertTrue(in_array($fact, $result->changedFacts(), true));
            }
            $harness->assertTrue(str_contains(
                (string)($result->flashMessages()[0]['message'] ?? ''),
                'party terms saved'
            ));
            $harness->assertSame(1, InterfaceDB::countWhere(
                'participator_loan_party_terms',
                ['company_id' => $companyId, 'party_id' => $partyId]
            ));
            $harness->assertSame('simultaneous', (string)InterfaceDB::fetchColumn(
                'SELECT settlement_intention
                 FROM participator_loan_party_terms
                 WHERE company_id = :company_id AND party_id = :party_id',
                ['company_id' => $companyId, 'party_id' => $partyId]
            ));
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
            \eel_accounts\Support\RequestCache::clear();
            $locked = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'DirectorLoan',
                        'intent' => 'save_participator_loan_party_terms',
                        'company_id' => (string)$companyId,
                        'accounting_period_id' => (string)$periodId,
                        'party_id' => (string)$partyId,
                        'interest_rate_percent' => '9.5',
                        'repayment_basis' => 'on_demand',
                    ],
                    ['REQUEST_METHOD' => 'POST'],
                    [],
                    [],
                    null
                ),
                createTestPageServiceFramework()
            );
            $harness->assertSame(false, $locked->isSuccess());
            $lockedTerms = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())
                ->fetchTerms($companyId, $partyId);
            $harness->assertSame(
                '2.5000',
                number_format((float)($lockedTerms['terms']['interest_rate_percent'] ?? -1), 4, '.', '')
            );

            $invalid = $action->handle(
                new RequestFramework(
                    [],
                    [
                        'card_action' => 'DirectorLoan',
                        'intent' => 'save_participator_loan_party_terms',
                        'company_id' => (string)$companyId,
                        'accounting_period_id' => (string)$periodId,
                        'party_id' => '0',
                        'repayment_basis' => 'within_12_months',
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
