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

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\DirectorLoanReportingPresentationService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanReportingPresentationService $service
): void {
    $harness->check(
        \eel_accounts\Service\DirectorLoanReportingPresentationService::class,
        'defines report-only schema without backfilling locked accounting data',
        static function () use ($harness): void {
            foreach ([
                'director_loan_reporting_presentations',
                'director_loan_reporting_presentation_audit',
            ] as $table) {
                $harness->assertTrue(InterfaceDB::tableExists($table));
            }
            foreach ([
                'liability_nominal_account_id',
                'classification',
                'revision',
                'updated_by',
            ] as $column) {
                $harness->assertTrue(InterfaceDB::columnExists('director_loan_reporting_presentations', $column));
            }

            $migration = (string)file_get_contents(
                dirname(__DIR__, 2)
                . DIRECTORY_SEPARATOR . 'db_schema'
                . DIRECTORY_SEPARATOR . 'migrations'
                . DIRECTORY_SEPARATOR . '2026_07_16_002_director_loan_reporting_presentation.sql'
            );
            $harness->assertFalse(str_contains($migration, 'INSERT INTO director_loan_reporting_presentations'));
            $harness->assertFalse(str_contains($migration, 'UPDATE director_loan_reporting_presentations'));
            $harness->assertFalse(str_contains($migration, 'UPDATE journals'));
            $harness->assertFalse(str_contains($migration, 'UPDATE accounting_periods'));
        }
    );

    $harness->check(
        \eel_accounts\Service\DirectorLoanReportingPresentationService::class,
        'saves an audited period presentation while a period is locked without changing accounting data',
        static function () use ($harness, $service): void {
            InterfaceDB::beginTransaction();
            try {
                StandardNominalTestFixture::ensureNominals(['1000', '2100']);
                $bankNominalId = StandardNominalTestFixture::id('1000');
                $liabilityNominalId = StandardNominalTestFixture::id('2100');
                $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
                $companyNumber = 'DRP' . strtoupper(substr($marker, 0, 7));
                $liabilitySubtypeId = (int)InterfaceDB::fetchColumn(
                    'SELECT account_subtype_id FROM nominal_accounts WHERE id = :id',
                    ['id' => $liabilityNominalId]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO nominal_accounts (code, name, account_type, account_subtype_id)
                     VALUES (:code, :name, :account_type, :account_subtype_id)',
                    [
                        'code' => 'DR' . strtoupper(substr($marker, 0, 8)),
                        'name' => 'Replacement Director Loan Liability',
                        'account_type' => 'liability',
                        'account_subtype_id' => $liabilitySubtypeId,
                    ]
                );
                $replacementLiabilityNominalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM nominal_accounts WHERE code = :code',
                    ['code' => 'DR' . strtoupper(substr($marker, 0, 8))]
                );

                InterfaceDB::prepareExecute(
                    'INSERT INTO companies (company_name, company_number)
                     VALUES (:company_name, :company_number)',
                    ['company_name' => 'DLA Reporting Fixture Limited', 'company_number' => $companyNumber]
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
                        'label' => 'Locked AP',
                        'period_start' => '2025-01-01',
                        'period_end' => '2025-12-31',
                    ]
                );
                $periodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                    ['company_id' => $companyId, 'label' => 'Locked AP']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
                     VALUES (:company_id, :label, :period_start, :period_end)',
                    [
                        'company_id' => $companyId,
                        'label' => 'Other AP',
                        'period_start' => '2026-01-01',
                        'period_end' => '2026-12-31',
                    ]
                );
                $otherPeriodId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
                    ['company_id' => $companyId, 'label' => 'Other AP']
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO year_end_reviews (
                        company_id, accounting_period_id, is_locked, locked_at, locked_by, review_notes
                     ) VALUES (
                        :company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by, :review_notes
                     )',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'locked_by' => 'test',
                        'review_notes' => 'Must remain locked.',
                    ]
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
                        'accounting_period_id' => $periodId,
                        'source_type' => 'manual',
                        'source_ref' => 'dla-reporting:' . $marker,
                        'journal_date' => '2025-12-31',
                        'description' => 'Director lent money to company',
                    ]
                );
                $journalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
                    ['company_id' => $companyId, 'source_ref' => 'dla-reporting:' . $marker]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, :debit, :credit, :description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $bankNominalId,
                        'debit' => 1288.63,
                        'credit' => 0,
                        'description' => 'Cash received',
                    ]
                );
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, :debit, :credit, :description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $liabilityNominalId,
                        'debit' => 0,
                        'credit' => 1288.63,
                        'description' => 'Director Loan Liability',
                    ]
                );

                $before = directorLoanReportingUnderlyingSnapshot(
                    $companyId,
                    $periodId,
                    $liabilityNominalId
                );
                $default = $service->fetchPresentation($companyId, $periodId);
                $harness->assertSame(true, (bool)($default['success'] ?? false));
                $harness->assertSame('within_one_year', (string)($default['classification'] ?? ''));
                $harness->assertSame(0, (int)($default['revision'] ?? -1));
                $harness->assertSame(false, (bool)($default['explicit'] ?? true));
                $harness->assertSame(true, (bool)($default['is_locked'] ?? false));
                $harness->assertSame(0, InterfaceDB::countWhere(
                    'director_loan_reporting_presentations',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                ));

                $saved = $service->save(
                    $companyId,
                    $periodId,
                    'after_more_than_one_year',
                    'test:user'
                );
                $harness->assertSame(true, (bool)($saved['success'] ?? false));
                $harness->assertSame(true, (bool)($saved['changed'] ?? false));
                $harness->assertSame('after_more_than_one_year', (string)($saved['classification'] ?? ''));
                $harness->assertSame(1, (int)($saved['revision'] ?? 0));
                $harness->assertSame(true, (bool)($saved['is_locked'] ?? false));
                $harness->assertSame(1, InterfaceDB::countWhere(
                    'director_loan_reporting_presentation_audit',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                ));
                $firstAudit = InterfaceDB::fetchOne(
                    'SELECT *
                     FROM director_loan_reporting_presentation_audit
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     ORDER BY id
                     LIMIT 1',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                );
                $harness->assertSame($liabilityNominalId, (int)($firstAudit['old_liability_nominal_account_id'] ?? 0));
                $harness->assertSame($liabilityNominalId, (int)($firstAudit['new_liability_nominal_account_id'] ?? 0));
                $harness->assertSame('within_one_year', (string)($firstAudit['old_classification'] ?? ''));
                $harness->assertSame('after_more_than_one_year', (string)($firstAudit['new_classification'] ?? ''));
                $harness->assertSame(0, (int)($firstAudit['old_revision'] ?? -1));
                $harness->assertSame(1, (int)($firstAudit['new_revision'] ?? 0));
                $harness->assertSame('test:user', (string)($firstAudit['changed_by'] ?? ''));
                $harness->assertSame(
                    'Director Loan statutory repayment presentation changed.',
                    (string)($firstAudit['reason'] ?? '')
                );

                $settings->set(
                    'director_loan_liability_nominal_id',
                    $replacementLiabilityNominalId,
                    'int'
                );
                $settings->flush();
                $afterNominalRemap = $service->fetchPresentation($companyId, $periodId);
                $harness->assertSame($liabilityNominalId, (int)($afterNominalRemap['liability_nominal_account_id'] ?? 0));
                $harness->assertSame(true, (bool)($afterNominalRemap['nominal_mapping_changed'] ?? false));
                $harness->assertSame(
                    $replacementLiabilityNominalId,
                    (int)($afterNominalRemap['current_liability_nominal_account_id'] ?? 0)
                );

                $idempotent = $service->save(
                    $companyId,
                    $periodId,
                    'after_more_than_one_year',
                    'test:user'
                );
                $harness->assertSame(true, (bool)($idempotent['success'] ?? false));
                $harness->assertSame(false, (bool)($idempotent['changed'] ?? true));
                $harness->assertSame(1, (int)($idempotent['revision'] ?? 0));
                $harness->assertSame(1, InterfaceDB::countWhere(
                    'director_loan_reporting_presentation_audit',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                ));

                $reverted = $service->save($companyId, $periodId, 'within_one_year', 'test:user');
                $harness->assertSame(true, (bool)($reverted['success'] ?? false));
                $harness->assertSame(true, (bool)($reverted['changed'] ?? false));
                $harness->assertSame(2, (int)($reverted['revision'] ?? 0));
                $harness->assertSame(2, InterfaceDB::countWhere(
                    'director_loan_reporting_presentation_audit',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                ));
                $auditRows = InterfaceDB::fetchAll(
                    'SELECT old_liability_nominal_account_id, new_liability_nominal_account_id,
                            old_classification, new_classification,
                            old_revision, new_revision, changed_by, reason
                     FROM director_loan_reporting_presentation_audit
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     ORDER BY id',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                );
                $harness->assertSame($liabilityNominalId, (int)($auditRows[1]['old_liability_nominal_account_id'] ?? 0));
                $harness->assertSame($liabilityNominalId, (int)($auditRows[1]['new_liability_nominal_account_id'] ?? 0));
                $harness->assertSame('after_more_than_one_year', (string)($auditRows[1]['old_classification'] ?? ''));
                $harness->assertSame('within_one_year', (string)($auditRows[1]['new_classification'] ?? ''));
                $harness->assertSame(1, (int)($auditRows[1]['old_revision'] ?? 0));
                $harness->assertSame(2, (int)($auditRows[1]['new_revision'] ?? 0));

                $otherPeriod = $service->fetchPresentation($companyId, $otherPeriodId);
                $harness->assertSame('within_one_year', (string)($otherPeriod['classification'] ?? ''));
                $harness->assertSame(0, (int)($otherPeriod['revision'] ?? -1));
                $harness->assertSame(false, (bool)($otherPeriod['explicit'] ?? true));

                $invalid = $service->save($companyId, $periodId, 'sometime_later', 'test:user');
                $harness->assertSame(false, (bool)($invalid['success'] ?? true));
                $wrongCompany = $service->save($companyId + 999999, $periodId, 'within_one_year', 'test:user');
                $harness->assertSame(false, (bool)($wrongCompany['success'] ?? true));

                $after = directorLoanReportingUnderlyingSnapshot(
                    $companyId,
                    $periodId,
                    $liabilityNominalId
                );
                $harness->assertSame($before, $after);
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );
});

function directorLoanReportingUnderlyingSnapshot(
    int $companyId,
    int $accountingPeriodId,
    int $liabilityNominalId
): array {
    return [
        'journals' => InterfaceDB::fetchAll(
            'SELECT id, company_id, accounting_period_id, source_type, source_ref,
                    journal_date, description, is_posted
             FROM journals
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ),
        'journal_lines' => InterfaceDB::fetchAll(
            'SELECT jl.id, jl.journal_id, jl.nominal_account_id, jl.debit, jl.credit, jl.line_description
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
             ORDER BY jl.id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ),
        'transactions' => InterfaceDB::fetchAll(
            'SELECT *
             FROM transactions
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ),
        'nominal' => InterfaceDB::fetchOne(
            'SELECT id, code, name, account_type, account_subtype_id, is_active
             FROM nominal_accounts
             WHERE id = :id',
            ['id' => $liabilityNominalId]
        ),
        'lock' => InterfaceDB::fetchOne(
            'SELECT company_id, accounting_period_id, is_locked, locked_at, locked_by, review_notes
             FROM year_end_reviews
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        ),
    ];
}
