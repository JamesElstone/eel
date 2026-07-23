<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ParticipatorLoanTestFixture.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\S455ReviewService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\S455ReviewService $service): void {
        $harness->check(get_class($service), 'rejects an invalid accounting-period context', static function () use ($harness, $service): void {
            $result = $service->fetchForAccountingPeriod(0, 0);
            $harness->assertSame(false, (bool)($result['available'] ?? true));
            $harness->assertSame([], $result['periods'] ?? null);
        });

        $harness->check(get_class($service), 'uses attributed replacement journals and still rejects manual loan movements', static function () use ($harness, $service): void {
            foreach (['journal_reversals', 'company_parties', 'company_party_roles', 'corporation_tax_periods', 's455_rate_rules'] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' schema is not available.');
                }
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = s455CorrectionAwareFixture();
                $attribution = (new \eel_accounts\Service\DirectorLoanAttributionService())->assignJournalLine(
                    $fixture['company_id'],
                    $fixture['loan_line_id'],
                    $fixture['party_id'],
                    'test-suite',
                    's455 correction-aware evidence fixture.'
                );
                $harness->assertSame(true, (bool)($attribution['success'] ?? false));

                $replacementJournalId = (int)($attribution['replacement_journal_id'] ?? 0);
                $harness->assertTrue($replacementJournalId > 0);
                $harness->assertSame(1, InterfaceDB::countWhere('journal_reversals', [
                    'source_journal_id' => $fixture['source_journal_id'],
                    'replacement_journal_id' => $replacementJournalId,
                ]));
                $harness->assertSame(
                    'transaction:' . $fixture['transaction_id'] . ':revision-of:' . $fixture['source_journal_id'],
                    (string)InterfaceDB::fetchColumn(
                        'SELECT source_ref FROM journals WHERE id = :id',
                        ['id' => $replacementJournalId]
                    )
                );
                $harness->assertSame($fixture['party_id'], (int)InterfaceDB::fetchColumn(
                    'SELECT party_id FROM journal_lines
                     WHERE journal_id = :journal_id AND nominal_account_id = :nominal_account_id',
                    ['journal_id' => $replacementJournalId, 'nominal_account_id' => $fixture['asset_nominal_id']]
                ));

                // Prove that s455 uses replacement-line attribution rather than
                // falling back to attribution propagated onto the transaction.
                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET party_id = NULL, director_id = NULL WHERE id = :id',
                    ['id' => $fixture['transaction_id']]
                );

                $result = $service->calculate(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $fixture['ct_period_id'],
                    '2099-12-31 23:59:59'
                );
                $harness->assertSame(true, (bool)($result['available'] ?? false));
                $harness->assertCount(1, (array)($result['movements'] ?? []));
                $harness->assertSame($fixture['transaction_id'], (int)($result['movements'][0]['transaction_id'] ?? 0));
                $harness->assertSame($fixture['party_id'], (int)($result['movements'][0]['party_id'] ?? 0));
                $harness->assertSame('100.00', number_format((float)($result['gross_principal'] ?? 0), 2, '.', ''));
                $harness->assertSame('33.75', number_format((float)($result['gross_tax'] ?? 0), 2, '.', ''));
                $harness->assertSame([], (array)($result['errors'] ?? []));
                $harness->assertSame([], (array)($result['unattributed_movements'] ?? []));
                $harness->assertSame([], (array)($result['unsupported_movements'] ?? []));

                s455CorrectionAwareFutureUnattributedMovement($fixture);
                $withFutureOpportunity = $service->calculate(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $fixture['ct_period_id'],
                    '2099-12-31 23:59:59'
                );
                $harness->assertSame([], (array)($withFutureOpportunity['errors'] ?? []));
                $harness->assertSame([], (array)($withFutureOpportunity['unattributed_movements'] ?? []));
                $harness->assertCount(1, (array)($withFutureOpportunity['future_unattributed_movements'] ?? []));
                $harness->assertSame((string)$result['basis_hash'], (string)$withFutureOpportunity['basis_hash']);
                $harness->assertSame('100.00', number_format((float)$withFutureOpportunity['gross_principal'], 2, '.', ''));
                $harness->assertSame('0.00', number_format((float)$withFutureOpportunity['qualifying_repayments'], 2, '.', ''));
                $loanReviewService = new \eel_accounts\Service\LoanReviewService();
                $loanReviewBefore = $loanReviewService->fetch($fixture['company_id'], $fixture['accounting_period_id']);
                $harness->assertSame(1, (int)($loanReviewBefore['future_attribution_warning']['count'] ?? 0));
                $harness->assertSame(false, (bool)($loanReviewBefore['future_attribution_warning']['acknowledged'] ?? false));
                $harness->assertSame(false, in_array(
                    'section_464a_review',
                    array_column((array)($loanReviewBefore['items'] ?? []), 'kind'),
                    true
                ));
                $acknowledged = $loanReviewService->acknowledgeFutureAttributionWarning(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    'test-suite'
                );
                $harness->assertSame(true, (bool)($acknowledged['success'] ?? false));
                $loanReviewAfter = $loanReviewService->fetch($fixture['company_id'], $fixture['accounting_period_id']);
                $harness->assertSame(true, (bool)($loanReviewAfter['future_attribution_warning']['acknowledged'] ?? false));

                s455CorrectionAwareManualMovement($fixture);
                $withManualMovement = $service->calculate(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $fixture['ct_period_id'],
                    '2099-12-31 23:59:59'
                );
                $harness->assertCount(1, (array)($withManualMovement['movements'] ?? []));
                $harness->assertSame(
                    ['1 non-cash or unsupported loan movement(s) cannot be used in the v1 s455 calculation.'],
                    (array)($withManualMovement['errors'] ?? [])
                );
                $harness->assertCount(1, (array)($withManualMovement['unsupported_movements'] ?? []));
                $harness->assertTrue(str_contains(
                    (string)($withManualMovement['unsupported_movements'][0]['source_url'] ?? ''),
                    'page=journal'
                ));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });

        $harness->check(get_class($service), 'memoizes only within a request and refreshes after invalidation', static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            $scope = null;
            try {
                $fixture = s455CorrectionAwareFixture();
                $request = new stdClass();
                $scope = \eel_accounts\Support\RequestCache::beginFor($request);
                $beforeAttribution = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertCount(1, (array)($beforeAttribution['unattributed_movements'] ?? []));
                $attribution = (new \eel_accounts\Service\DirectorLoanAttributionService())->assignJournalLine(
                    $fixture['company_id'],
                    $fixture['loan_line_id'],
                    $fixture['party_id'],
                    'test-suite',
                    'Request cache fixture.'
                );
                $harness->assertSame(true, (bool)($attribution['success'] ?? false));
                $afterAttribution = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertCount(1, (array)($afterAttribution['movements'] ?? []));
                $harness->assertSame($fixture['party_id'], (int)($afterAttribution['movements'][0]['party_id'] ?? 0));
                unset($scope, $request);
                \eel_accounts\Support\RequestCache::reset();
                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET party_id = NULL, director_id = NULL WHERE id = :id',
                    ['id' => $fixture['transaction_id']]
                );

                $direct = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertSame('100.00', number_format((float)$direct['gross_principal'], 2, '.', ''));
                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET amount = -125.00 WHERE id = :id',
                    ['id' => $fixture['transaction_id']]
                );
                $directAgain = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertSame('125.00', number_format((float)$directAgain['gross_principal'], 2, '.', ''));

                $request = new stdClass();
                $scope = \eel_accounts\Support\RequestCache::beginFor($request);
                $cached = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                InterfaceDB::prepareExecute(
                    'UPDATE transactions SET amount = -150.00 WHERE id = :id',
                    ['id' => $fixture['transaction_id']]
                );
                $cachedAgain = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertSame((string)$cached['basis_hash'], (string)$cachedAgain['basis_hash']);
                $harness->assertSame('125.00', number_format((float)$cachedAgain['gross_principal'], 2, '.', ''));

                \eel_accounts\Support\RequestCache::clear();
                $refreshed = $service->calculate(
                    $fixture['company_id'], $fixture['accounting_period_id'], $fixture['ct_period_id'], '2099-12-31 23:59:59'
                );
                $harness->assertSame('150.00', number_format((float)$refreshed['gross_principal'], 2, '.', ''));
                $harness->assertSame(false, (string)$cached['basis_hash'] === (string)$refreshed['basis_hash']);
            } finally {
                unset($scope);
                \eel_accounts\Support\RequestCache::reset();
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        });
    }
);

/** @return array<string,int> */
function s455CorrectionAwareFixture(): array
{
    StandardNominalTestFixture::ensureNominals(['1000', '1200', '2100']);
    $bankNominalId = StandardNominalTestFixture::id('1000');
    $assetNominalId = StandardNominalTestFixture::id('1200');
    $liabilityNominalId = StandardNominalTestFixture::id('2100');
    $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 10);

    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number, is_active)
         VALUES (:name, :number, 1)',
        ['name' => 's455 correction fixture ' . $marker, 'number' => 'S4C' . $marker]
    );
    $companyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies WHERE company_number = :number',
        ['number' => 'S4C' . $marker]
    );
    ParticipatorLoanTestFixture::configureNominals($companyId, $assetNominalId, $liabilityNominalId);

    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :start, :end)',
        ['company_id' => $companyId, 'label' => '2024', 'start' => '2024-01-01', 'end' => '2024-12-31']
    );
    $accountingPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO corporation_tax_periods (
            company_id, accounting_period_id, sequence_no, period_start, period_end, status
         ) VALUES (
            :company_id, :accounting_period_id, 1, :start, :end, :status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'start' => '2024-01-01',
            'end' => '2024-12-31',
            'status' => 'pending',
        ]
    );
    $ctPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM corporation_tax_periods
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO company_parties (company_id, party_type, legal_name, source_note)
         VALUES (:company_id, :party_type, :name, :note)',
        [
            'company_id' => $companyId,
            'party_type' => 'individual',
            'name' => 'Fixture Participator',
            'note' => 's455 correction-aware fixture',
        ]
    );
    $partyId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM company_parties WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO company_party_roles (
            company_id, party_id, role_type, effective_from, source_note
         ) VALUES (
            :company_id, :party_id, :role_type, :effective_from, :source_note
         )',
        [
            'company_id' => $companyId,
            'party_id' => $partyId,
            'role_type' => 'participator',
            'effective_from' => '2020-01-01',
            'source_note' => 's455 correction-aware fixture',
        ]
    );

    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, statement_month, original_filename,
            stored_filename, file_sha256, workflow_status
         ) VALUES (
            :company_id, :accounting_period_id, :month, :original,
            :stored, :sha, :status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'month' => '2024-06-01',
            'original' => 's455-' . $marker . '.csv',
            'stored' => 's455-' . $marker . '.csv',
            'sha' => hash('sha256', $marker),
            'status' => 'committed',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id, accounting_period_id, statement_upload_id, txn_date,
            description, amount, dedupe_hash, category_status
         ) VALUES (
            :company_id, :accounting_period_id, :statement_upload_id, :txn_date,
            :description, :amount, :dedupe_hash, :category_status
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'statement_upload_id' => $uploadId,
            'txn_date' => '2024-06-15',
            'description' => 'Fixture participator advance',
            'amount' => '-100.00',
            'dedupe_hash' => hash('sha256', 's455-transaction-' . $marker),
            'category_status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id',
        ['company_id' => $companyId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $transactionId,
            'journal_date' => '2024-06-15',
            'description' => 'Fixture participator advance',
        ]
    );
    $sourceJournalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => $companyId, 'source_ref' => 'transaction:' . $transactionId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (
            journal_id, nominal_account_id, debit, credit, line_description
         ) VALUES (
            :journal_id, :nominal_account_id, 100.00, 0.00, :description
         )',
        [
            'journal_id' => $sourceJournalId,
            'nominal_account_id' => $assetNominalId,
            'description' => 'Fixture participator advance',
        ]
    );
    $loanLineId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journal_lines
         WHERE journal_id = :journal_id AND nominal_account_id = :nominal_account_id',
        ['journal_id' => $sourceJournalId, 'nominal_account_id' => $assetNominalId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (
            journal_id, nominal_account_id, debit, credit, line_description
         ) VALUES (
            :journal_id, :nominal_account_id, 0.00, 100.00, :description
         )',
        [
            'journal_id' => $sourceJournalId,
            'nominal_account_id' => $bankNominalId,
            'description' => 'Fixture bank counter-entry',
        ]
    );

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $accountingPeriodId,
        'ct_period_id' => $ctPeriodId,
        'party_id' => $partyId,
        'transaction_id' => $transactionId,
        'source_journal_id' => $sourceJournalId,
        'loan_line_id' => $loanLineId,
        'bank_nominal_id' => $bankNominalId,
        'asset_nominal_id' => $assetNominalId,
        'liability_nominal_id' => $liabilityNominalId,
    ];
}

/** @param array<string,int> $fixture */
function s455CorrectionAwareManualMovement(array $fixture): void
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
            'company_id' => $fixture['company_id'],
            'accounting_period_id' => $fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => 's455-manual-guard:' . $fixture['transaction_id'],
            'journal_date' => '2024-07-01',
            'description' => 'Unsupported manual loan movement fixture',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        [
            'company_id' => $fixture['company_id'],
            'source_ref' => 's455-manual-guard:' . $fixture['transaction_id'],
        ]
    );
    foreach ([
        [$fixture['asset_nominal_id'], '10.00', '0.00'],
        [$fixture['bank_nominal_id'], '0.00', '10.00'],
    ] as [$nominalAccountId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (
                journal_id, nominal_account_id, debit, credit, line_description
             ) VALUES (
                :journal_id, :nominal_account_id, :debit, :credit, :description
             )',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => $nominalAccountId,
                'debit' => $debit,
                'credit' => $credit,
                'description' => 'Unsupported manual loan movement fixture',
            ]
        );
    }
}

/** @param array<string,int> $fixture */
function s455CorrectionAwareFutureUnattributedMovement(array $fixture): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
         VALUES (:company_id, :label, :start, :end)',
        ['company_id' => $fixture['company_id'], 'label' => '2025', 'start' => '2025-01-01', 'end' => '2025-12-31']
    );
    $nextPeriodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND period_start = :start',
        ['company_id' => $fixture['company_id'], 'start' => '2025-01-01']
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, statement_month, original_filename,
            stored_filename, file_sha256, workflow_status
         ) VALUES (
            :company_id, :accounting_period_id, :month, :original,
            :stored, :sha, :status
         )',
        [
            'company_id' => $fixture['company_id'], 'accounting_period_id' => $nextPeriodId,
            'month' => '2025-02-01', 'original' => 'future-s455.csv', 'stored' => 'future-s455.csv',
            'sha' => hash('sha256', 'future-s455-' . $fixture['transaction_id']), 'status' => 'committed',
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND accounting_period_id = :period_id',
        ['company_id' => $fixture['company_id'], 'period_id' => $nextPeriodId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id, accounting_period_id, statement_upload_id, txn_date,
            description, amount, dedupe_hash, category_status
         ) VALUES (
            :company_id, :accounting_period_id, :upload_id, :txn_date,
            :description, :amount, :dedupe_hash, :category_status
         )',
        [
            'company_id' => $fixture['company_id'], 'accounting_period_id' => $nextPeriodId,
            'upload_id' => $uploadId, 'txn_date' => '2025-02-01',
            'description' => 'Optional future repayment', 'amount' => '25.00',
            'dedupe_hash' => hash('sha256', 'future-repayment-' . $fixture['transaction_id']),
            'category_status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id AND accounting_period_id = :period_id',
        ['company_id' => $fixture['company_id'], 'period_id' => $nextPeriodId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (
            company_id, accounting_period_id, source_type, source_ref,
            journal_date, description, is_posted
         ) VALUES (
            :company_id, :accounting_period_id, :source_type, :source_ref,
            :journal_date, :description, 1
         )',
        [
            'company_id' => $fixture['company_id'], 'accounting_period_id' => $nextPeriodId,
            'source_type' => 'bank_csv', 'source_ref' => 'transaction:' . $transactionId,
            'journal_date' => '2025-02-01', 'description' => 'Optional future repayment',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => $fixture['company_id'], 'source_ref' => 'transaction:' . $transactionId]
    );
    foreach ([
        [$fixture['bank_nominal_id'], '25.00', '0.00'],
        [$fixture['asset_nominal_id'], '0.00', '25.00'],
    ] as [$nominalId, $debit, $credit]) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
             VALUES (:journal_id, :nominal_id, :debit, :credit, :description)',
            [
                'journal_id' => $journalId, 'nominal_id' => $nominalId,
                'debit' => $debit, 'credit' => $credit, 'description' => 'Optional future repayment',
            ]
        );
    }
}
