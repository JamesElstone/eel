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
$harness->run(\eel_accounts\Service\LoanReviewService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\LoanReviewService $service
): void {
    $harness->check(
        \eel_accounts\Service\LoanReviewService::class,
        'matches reconciliation when a relevant party has no saved terms',
        static function () use ($harness, $service): void {
            loanReviewStatusWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                loanReviewStatusInsertMovement($fixture, 'liability', 400.00, 'missing-terms-liability');

                $review = $service->fetch(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $reconciliation = (new \eel_accounts\Service\DirectorLoanReconciliationService())->fetchContext(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );

                $harness->assertSame(true, (bool)($review['available'] ?? false));
                $harness->assertSame(true, (bool)($reconciliation['available'] ?? false));
                $harness->assertSame('terms_required', (string)(($review['tax_review'] ?? [])['status'] ?? ''));
                $harness->assertSame('terms_required', (string)($review['tax_status_code'] ?? ''));
                $harness->assertSame('terms_required', (string)(($review['tax_review'] ?? [])['status_code'] ?? ''));
                $harness->assertSame('terms_required', (string)($reconciliation['tax_status_code'] ?? ''));
                $harness->assertSame(1, (int)($review['missing_terms_count'] ?? 0));
                $harness->assertSame(1, (int)($reconciliation['missing_terms_count'] ?? 0));

                $termsItems = array_values(array_filter(
                    (array)($review['items'] ?? []),
                    static fn(array $item): bool => (string)($item['kind'] ?? '') === 'party_terms'
                ));
                $harness->assertCount(1, $termsItems);
                $harness->assertSame(
                    (int)$fixture['party_id'],
                    (int)($termsItems[0]['party_id'] ?? 0)
                );
            });
        }
    );

    $harness->check(
        \eel_accounts\Service\LoanReviewService::class,
        'treats saved independent-settlement terms as complete when there is no exposure',
        static function () use ($harness, $service): void {
            loanReviewStatusWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                loanReviewStatusSaveIndependentTerms($harness, $fixture);
                loanReviewStatusInsertMovement($fixture, 'liability', 400.00, 'saved-gross-liability');

                $review = $service->fetch(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $reconciliation = (new \eel_accounts\Service\DirectorLoanReconciliationService())->fetchContext(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $kinds = array_map(
                    static fn(array $item): string => (string)($item['kind'] ?? ''),
                    (array)($review['items'] ?? [])
                );

                $harness->assertSame(false, in_array('party_terms', $kinds, true));
                $harness->assertSame(0, (int)($review['missing_terms_count'] ?? -1));
                $harness->assertSame(0, (int)($reconciliation['missing_terms_count'] ?? -1));
                $harness->assertSame('no_exposure', (string)(($review['tax_review'] ?? [])['status'] ?? ''));
                $harness->assertSame('no_exposure', (string)($review['tax_status_code'] ?? ''));
                $harness->assertSame('no_exposure', (string)($reconciliation['tax_status_code'] ?? ''));
                $harness->assertSame(false, (bool)(($review['tax_review'] ?? [])['review_required'] ?? true));
                $harness->assertSame(false, (bool)(($reconciliation['tax_review'] ?? [])['review_required'] ?? true));
            });
        }
    );

    $harness->check(
        \eel_accounts\Service\LoanReviewService::class,
        'matches reconciliation when exposure has incomplete tax evidence',
        static function () use ($harness, $service): void {
            loanReviewStatusWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
                loanReviewStatusSaveIndependentTerms($harness, $fixture);
                loanReviewStatusInsertMovement($fixture, 'asset', 250.00, 'unreviewed-exposure');

                $review = $service->fetch(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $reconciliation = (new \eel_accounts\Service\DirectorLoanReconciliationService())->fetchContext(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id']
                );
                $kinds = array_map(
                    static fn(array $item): string => (string)($item['kind'] ?? ''),
                    (array)($review['items'] ?? [])
                );

                $harness->assertSame(false, in_array('party_terms', $kinds, true));
                $harness->assertSame('review_required', (string)(($review['tax_review'] ?? [])['status'] ?? ''));
                $harness->assertSame('review_required', (string)($review['tax_status_code'] ?? ''));
                $harness->assertSame('review_required', (string)($reconciliation['tax_status_code'] ?? ''));
                $harness->assertSame(true, (bool)(($review['tax_review'] ?? [])['review_required'] ?? false));
                $harness->assertSame(true, (bool)(($reconciliation['tax_review'] ?? [])['review_required'] ?? false));
                $harness->assertTrue(
                    in_array('s455_evidence', $kinds, true)
                    || in_array('close_company_status', $kinds, true)
                    || in_array('section_464a_review', $kinds, true)
                    || in_array('ct600a_evidence', $kinds, true)
                );
            });
        }
    );
});

function loanReviewStatusWithFixture(
    GeneratedServiceClassTestHarness $harness,
    callable $callback
): void {
    foreach ([
        'companies',
        'accounting_periods',
        'company_settings',
        'company_directors',
        'company_parties',
        'company_party_roles',
        'journals',
        'journal_lines',
        'participator_loan_party_terms',
        'participator_loan_party_terms_audit',
        'participator_loan_party_term_snapshots',
        'corporation_tax_periods',
        'corporation_tax_s455_reviews',
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
        $companyNumber = 'LRS' . strtoupper(substr($marker, 0, 9));

        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number)
             VALUES (:company_name, :company_number)',
            [
                'company_name' => 'Loan Review Status Fixture Limited',
                'company_number' => $companyNumber,
            ]
        );
        $companyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number',
            ['company_number' => $companyNumber]
        );
        ParticipatorLoanTestFixture::configureNominals(
            $companyId,
            $assetNominalId,
            $liabilityNominalId
        );

        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (
                company_id, label, period_start, period_end
             ) VALUES (
                :company_id, :label, :period_start, :period_end
             )',
            [
                'company_id' => $companyId,
                'label' => 'Loan review ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods
             WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'Loan review ' . $marker]
        );

        InterfaceDB::prepareExecute(
            'INSERT INTO company_directors (
                company_id, source, external_key, full_name,
                officer_role, appointed_on, is_active
             ) VALUES (
                :company_id, :source, :external_key, :full_name,
                :officer_role, :appointed_on, 1
             )',
            [
                'company_id' => $companyId,
                'source' => 'companies_house',
                'external_key' => 'loan-review:' . $marker,
                'full_name' => 'Loan Review Director',
                'officer_role' => 'director',
                'appointed_on' => '2020-01-01',
            ]
        );
        $directorId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_directors
             WHERE company_id = :company_id AND external_key = :external_key',
            ['company_id' => $companyId, 'external_key' => 'loan-review:' . $marker]
        );
        $partyId = ParticipatorLoanTestFixture::createPartyForDirector(
            $companyId,
            $directorId,
            'Loan Review Director'
        );

        \eel_accounts\Support\RequestCache::clear();
        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'party_id' => $partyId,
            'counter_nominal_id' => $counterNominalId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
        \eel_accounts\Support\RequestCache::clear();
    }
}

function loanReviewStatusInsertMovement(
    array $fixture,
    string $role,
    float $amount,
    string $key
): void {
    $nominalId = $role === 'asset'
        ? (int)$fixture['asset_nominal_id']
        : (int)$fixture['liability_nominal_id'];
    $debit = $role === 'asset' ? $amount : 0.0;
    $credit = $role === 'liability' ? $amount : 0.0;
    $sourceRef = 'loan-review:' . (string)$fixture['marker'] . ':' . $key;

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
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => $sourceRef,
            'journal_date' => '2025-12-31',
            'description' => 'Loan Review status fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals
         WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => $sourceRef]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (
            journal_id, nominal_account_id, party_id, debit, credit, line_description
         ) VALUES (
            :journal_id, :nominal_account_id, :party_id, :debit, :credit, :line_description
         )',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => $nominalId,
            'party_id' => (int)$fixture['party_id'],
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'line_description' => 'Participator loan movement',
        ]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (
            journal_id, nominal_account_id, debit, credit, line_description
         ) VALUES (
            :journal_id, :nominal_account_id, :debit, :credit, :line_description
         )',
        [
            'journal_id' => $journalId,
            'nominal_account_id' => (int)$fixture['counter_nominal_id'],
            'debit' => number_format($credit, 2, '.', ''),
            'credit' => number_format($debit, 2, '.', ''),
            'line_description' => 'Participator loan movement counter-entry',
        ]
    );
    \eel_accounts\Support\RequestCache::clear();
}

function loanReviewStatusSaveIndependentTerms(
    GeneratedServiceClassTestHarness $harness,
    array $fixture
): void {
    $saved = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
        (int)$fixture['company_id'],
        (int)$fixture['party_id'],
        [
            'interest_rate_percent' => 0,
            'security_type' => 'unsecured',
            'repayable_on_demand' => true,
            'repayment_timing' => 'within_12_months',
            'deferment_right_confirmed' => false,
            'set_off_right_confirmed' => false,
            'settlement_intention' => 'independently',
        ],
        'test'
    );
    $harness->assertSame(true, (bool)($saved['success'] ?? false));
}
