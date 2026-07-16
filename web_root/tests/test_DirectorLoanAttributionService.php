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
$harness->run(\eel_accounts\Service\DirectorLoanAttributionService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanAttributionService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanAttributionService::class, 'assigns the journal source of truth and records an audit without changing accounting values', static function () use ($harness, $service): void {
        foreach (['company_directors', 'director_loan_attribution_audit'] as $table) {
            if (!InterfaceDB::tableExists($table)) {
                $harness->skip($table . ' schema is not available.');
            }
        }

        InterfaceDB::beginTransaction();
        try {
            StandardNominalTestFixture::ensureNominals(['1200', '2100']);
            $assetNominalId = StandardNominalTestFixture::id('1200');
            $liabilityNominalId = StandardNominalTestFixture::id('2100');
            $marker = substr(hash('sha256', __FILE__ . microtime(true)), 0, 10);
            InterfaceDB::prepareExecute(
                'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
                ['company_name' => 'DLA Attribution Fixture Limited', 'company_number' => 'DAF' . $marker]
            );
            $companyId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM companies WHERE company_number = :company_number',
                ['company_number' => 'DAF' . $marker]
            );
            $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
            $settings->set('director_loan_asset_nominal_id', $assetNominalId, 'int');
            $settings->set('director_loan_liability_nominal_id', $liabilityNominalId, 'int');
            $settings->flush();
            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $companyId,
                    'source' => 'companies_house',
                    'external_key' => 'test:' . $marker,
                    'full_name' => 'James Example',
                    'officer_role' => 'director',
                    'appointed_on' => '2020-01-01',
                ]
            );
            $directorId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM company_directors WHERE company_id = :company_id',
                ['company_id' => $companyId]
            );
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
                'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                 VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                [
                    'company_id' => $companyId,
                    'period_id' => $periodId,
                    'source_type' => 'manual',
                    'source_ref' => 'dla-attribution:' . $marker,
                    'journal_date' => '2025-12-31',
                    'description' => 'DLA attribution fixture',
                ]
            );
            $journalId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
                ['company_id' => $companyId, 'source_ref' => 'dla-attribution:' . $marker]
            );
            InterfaceDB::prepareExecute(
                'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                 VALUES (:journal_id, :nominal_id, 253.00, 0.00, :description)',
                ['journal_id' => $journalId, 'nominal_id' => $assetNominalId, 'description' => 'Brian advanced funds for James']
            );
            $lineId = (int)InterfaceDB::fetchColumn(
                'SELECT id FROM journal_lines WHERE journal_id = :journal_id',
                ['journal_id' => $journalId]
            );

            $result = $service->assignJournalLine($companyId, $lineId, $directorId, 'test', 'Test attribution.');

            $harness->assertSame(true, (bool)($result['success'] ?? false));
            $harness->assertSame($directorId, (int)InterfaceDB::fetchColumn(
                'SELECT director_id FROM journal_lines WHERE id = :id',
                ['id' => $lineId]
            ));
            $harness->assertSame('253.00', number_format((float)InterfaceDB::fetchColumn(
                'SELECT debit FROM journal_lines WHERE id = :id',
                ['id' => $lineId]
            ), 2, '.', ''));
            $harness->assertSame(1, InterfaceDB::countWhere('director_loan_attribution_audit', [
                'company_id' => $companyId,
                'source_type' => 'journal_line',
                'source_id' => $lineId,
                'new_director_id' => $directorId,
            ]));

            $missing = $service->assignJournalLine($companyId, $lineId, null, 'test');
            $harness->assertSame(false, (bool)($missing['success'] ?? true));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });
});
