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
    \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\ParticipatorLoanPartyTermsService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
            'snapshots a party whose period movements net to zero and preserves the live revision',
            static function () use ($harness, $service): void {
                participatorLoanTermsSnapshotTestWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $service): void {
                        participatorLoanTermsSnapshotTestInsertNetZeroMovement($fixture);
                        participatorLoanTermsSnapshotTestSave($harness, $service, $fixture, 1.25);
                        participatorLoanTermsSnapshotTestSave($harness, $service, $fixture, 2.75);

                        $result = $service->snapshotPeriod(
                            (int)$fixture['company_id'],
                            (int)$fixture['accounting_period_id'],
                            'snapshot-test'
                        );

                        $harness->assertSame(true, (bool)($result['success'] ?? false));
                        $harness->assertSame(1, (int)($result['snapshotted_party_count'] ?? 0));
                        $harness->assertSame([(int)$fixture['party_id']], array_map(
                            'intval',
                            (array)($result['party_ids'] ?? [])
                        ));

                        $row = participatorLoanTermsSnapshotTestSnapshotRow($fixture);
                        $terms = json_decode((string)($row['terms_json'] ?? ''), true);
                        $harness->assertSame(2, (int)($terms['revision'] ?? 0));
                        $harness->assertSame(2.75, (float)($terms['interest_rate_percent'] ?? 0));
                    }
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
            'fails closed when a locked period has no party terms snapshot',
            static function () use ($harness, $service): void {
                participatorLoanTermsSnapshotTestWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $service): void {
                        participatorLoanTermsSnapshotTestInsertNetZeroMovement($fixture);
                        participatorLoanTermsSnapshotTestSave($harness, $service, $fixture, 3.5);
                        InterfaceDB::prepareExecute(
                            'UPDATE year_end_reviews
                             SET is_locked = 1, locked_at = CURRENT_TIMESTAMP, locked_by = :locked_by
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id',
                            [
                                'locked_by' => 'snapshot-test',
                                'company_id' => (int)$fixture['company_id'],
                                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                            ]
                        );
                        \eel_accounts\Support\RequestCache::clear();

                        $exception = null;
                        try {
                            $service->resolveForReporting(
                                (int)$fixture['company_id'],
                                (int)$fixture['accounting_period_id'],
                                (int)$fixture['party_id']
                            );
                        } catch (\RuntimeException $caught) {
                            $exception = $caught;
                        }

                        $harness->assertTrue($exception instanceof \RuntimeException);
                        $harness->assertTrue(str_contains(
                            strtolower((string)$exception?->getMessage()),
                            'no participator loan terms snapshot'
                        ));
                    }
                );
            }
        );

        $harness->check(
            \eel_accounts\Service\ParticipatorLoanPartyTermsService::class,
            'atomically replaces an existing period snapshot with current live terms on relock',
            static function () use ($harness, $service): void {
                participatorLoanTermsSnapshotTestWithFixture(
                    $harness,
                    static function (array $fixture) use ($harness, $service): void {
                        participatorLoanTermsSnapshotTestInsertNetZeroMovement($fixture);
                        participatorLoanTermsSnapshotTestSave($harness, $service, $fixture, 1.0);
                        $first = $service->snapshotPeriod(
                            (int)$fixture['company_id'],
                            (int)$fixture['accounting_period_id'],
                            'first-lock'
                        );
                        $harness->assertSame(true, (bool)($first['success'] ?? false));

                        participatorLoanTermsSnapshotTestSave($harness, $service, $fixture, 4.5);
                        $replacement = $service->snapshotPeriod(
                            (int)$fixture['company_id'],
                            (int)$fixture['accounting_period_id'],
                            'relock'
                        );
                        $harness->assertSame(true, (bool)($replacement['success'] ?? false));

                        $count = (int)InterfaceDB::fetchColumn(
                            'SELECT COUNT(*)
                             FROM participator_loan_party_term_snapshots
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id
                               AND party_id = :party_id',
                            [
                                'company_id' => (int)$fixture['company_id'],
                                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                                'party_id' => (int)$fixture['party_id'],
                            ]
                        );
                        $harness->assertSame(1, $count);

                        $row = participatorLoanTermsSnapshotTestSnapshotRow($fixture);
                        $terms = json_decode((string)($row['terms_json'] ?? ''), true);
                        $harness->assertSame('relock', (string)($row['created_by'] ?? ''));
                        $harness->assertSame(2, (int)($terms['revision'] ?? 0));
                        $harness->assertSame(4.5, (float)($terms['interest_rate_percent'] ?? 0));

                        InterfaceDB::prepareExecute(
                            'UPDATE year_end_reviews
                             SET is_locked = 1, locked_at = CURRENT_TIMESTAMP, locked_by = :locked_by
                             WHERE company_id = :company_id
                               AND accounting_period_id = :accounting_period_id',
                            [
                                'locked_by' => 'relock',
                                'company_id' => (int)$fixture['company_id'],
                                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                            ]
                        );
                        \eel_accounts\Support\RequestCache::clear();
                        $resolved = $service->resolveForReporting(
                            (int)$fixture['company_id'],
                            (int)$fixture['accounting_period_id'],
                            (int)$fixture['party_id']
                        );
                        $harness->assertSame('locked_snapshot', (string)($resolved['terms_source'] ?? ''));
                        $harness->assertSame(2, (int)($resolved['revision'] ?? 0));
                        $harness->assertSame(4.5, (float)($resolved['interest_rate_percent'] ?? 0));
                    }
                );
            }
        );
    }
);

function participatorLoanTermsSnapshotTestWithFixture(
    GeneratedServiceClassTestHarness $harness,
    callable $callback
): void {
    $required = [
        'companies',
        'accounting_periods',
        'company_settings',
        'company_parties',
        'company_party_roles',
        'nominal_account_subtypes',
        'nominal_accounts',
        'journals',
        'journal_lines',
        'year_end_reviews',
        'participator_loan_party_terms',
        'participator_loan_party_terms_audit',
        'participator_loan_party_term_snapshots',
    ];
    foreach ($required as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip('Required table is not available on the default InterfaceDB connection: ' . $table);
        }
    }

    InterfaceDB::beginTransaction();
    try {
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        $companyNumber = 'PLT' . strtoupper(substr($marker, 0, 8));
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number)
             VALUES (:company_name, :company_number)',
            [
                'company_name' => 'Party Terms Snapshot Fixture Limited',
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
                'label' => 'Party terms ' . $marker,
                'period_start' => '2025-01-01',
                'period_end' => '2025-12-31',
            ]
        );
        $periodId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id AND label = :label',
            ['company_id' => $companyId, 'label' => 'Party terms ' . $marker]
        );

        $assetNominalId = participatorLoanTermsSnapshotTestNominal(
            $marker,
            'director_loan_asset',
            'asset',
            'Party Terms Loan Asset'
        );
        $liabilityNominalId = participatorLoanTermsSnapshotTestNominal(
            $marker,
            'director_loan_liability',
            'liability',
            'Party Terms Loan Liability'
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
                'legal_name' => 'Net Zero Movement Participator ' . $marker,
            ]
        );
        $partyId = (int)InterfaceDB::fetchColumn(
            'SELECT id
             FROM company_parties
             WHERE company_id = :company_id AND legal_name = :legal_name',
            [
                'company_id' => $companyId,
                'legal_name' => 'Net Zero Movement Participator ' . $marker,
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO company_party_roles (company_id, party_id, role_type, effective_from)
             VALUES (:company_id, :party_id, :role_type, :effective_from)',
            [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'role_type' => 'participator',
                'effective_from' => '2025-01-01',
            ]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked)
             VALUES (:company_id, :accounting_period_id, 0)',
            ['company_id' => $companyId, 'accounting_period_id' => $periodId]
        );

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $periodId,
            'party_id' => $partyId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function participatorLoanTermsSnapshotTestNominal(
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

    $code = 'PT' . substr(hash('sha256', $marker . $subtypeCode), 0, 10);
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

function participatorLoanTermsSnapshotTestInsertNetZeroMovement(array $fixture): void
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
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'manual',
            'source_ref' => 'party-terms-net-zero:' . (string)$fixture['marker'],
            'journal_date' => '2025-06-30',
            'description' => 'Participator loan advance and repayment netting to zero',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id
         FROM journals
         WHERE company_id = :company_id AND source_ref = :source_ref',
        [
            'company_id' => (int)$fixture['company_id'],
            'source_ref' => 'party-terms-net-zero:' . (string)$fixture['marker'],
        ]
    );
    foreach ([[100.0, 0.0], [0.0, 100.0]] as $amounts) {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_lines (
                journal_id, nominal_account_id, party_id, debit, credit, line_description
             ) VALUES (
                :journal_id, :nominal_account_id, :party_id, :debit, :credit, :line_description
             )',
            [
                'journal_id' => $journalId,
                'nominal_account_id' => (int)$fixture['asset_nominal_id'],
                'party_id' => (int)$fixture['party_id'],
                'debit' => number_format((float)$amounts[0], 2, '.', ''),
                'credit' => number_format((float)$amounts[1], 2, '.', ''),
                'line_description' => 'Net-zero movement fixture',
            ]
        );
    }
}

function participatorLoanTermsSnapshotTestSave(
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\ParticipatorLoanPartyTermsService $service,
    array $fixture,
    float $interestRate
): void {
    $saved = $service->save(
        (int)$fixture['company_id'],
        (int)$fixture['party_id'],
        [
            'interest_rate_percent' => $interestRate,
            'security_type' => 'unsecured',
            'repayable_on_demand' => 0,
            'repayment_timing' => 'after_12_months',
            'deferment_right_confirmed' => 1,
            'set_off_right_confirmed' => 1,
            'settlement_intention' => 'simultaneous',
        ],
        'snapshot-test'
    );
    $harness->assertSame(true, (bool)($saved['success'] ?? false));
    $harness->assertSame(true, (bool)($saved['changed'] ?? false));
}

function participatorLoanTermsSnapshotTestSnapshotRow(array $fixture): array
{
    $row = InterfaceDB::fetchOne(
        'SELECT terms_json, created_by, liability_nominal_account_id
         FROM participator_loan_party_term_snapshots
         WHERE company_id = :company_id
           AND accounting_period_id = :accounting_period_id
           AND party_id = :party_id
         LIMIT 1',
        [
            'company_id' => (int)$fixture['company_id'],
            'accounting_period_id' => (int)$fixture['accounting_period_id'],
            'party_id' => (int)$fixture['party_id'],
        ]
    );
    if (!is_array($row)) {
        throw new RuntimeException('Expected a party terms snapshot row.');
    }
    return $row;
}
