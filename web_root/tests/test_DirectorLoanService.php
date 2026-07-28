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
$harness->run(\eel_accounts\Service\DirectorLoanService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\DirectorLoanService $service
): void {
    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'calculates the primary director position while preserving the external counterparty', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $primaryPartyId = (int)$fixture['primary_party_id'];
            $otherPartyId = (int)$fixture['other_party_id'];

            directorLoanStatementInsertTransactionJournal(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                253.00,
                $primaryPartyId,
                'External Counterparty'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                1288.63,
                $primaryPartyId,
                'Primary director funds introduced'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                100.00,
                0.00,
                $otherPartyId,
                'Other director advance'
            );

            $grossStatement = $service->fetchStatement(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $grossDisclosure = $service->fetchDisclosureSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame('0.00', directorLoanStatementMoney($grossStatement['desired_reclassification'] ?? 0));
            $harness->assertSame('353.00', directorLoanStatementMoney($grossStatement['potential_s455_exposure'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($grossDisclosure['total_amounts_legally_set_off'] ?? 0));
            $harness->assertSame('353.00', directorLoanStatementMoney($grossDisclosure['closing_company_to_director_balance'] ?? 0));

            $savedEvidence = (new \eel_accounts\Service\ParticipatorLoanPartyTermsService())->save(
                (int)$fixture['company_id'],
                $primaryPartyId,
                [
                    'interest_rate_percent' => 0,
                    'security_type' => 'unsecured',
                    'repayable_on_demand' => false,
                    'repayment_timing' => 'after_12_months',
                    'deferment_right_confirmed' => false,
                    'set_off_right_confirmed' => true,
                    'settlement_intention' => 'simultaneous',
                ],
                'test'
            );
            $harness->assertSame(true, (bool)($savedEvidence['success'] ?? false));
            $statement = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $disclosure = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $taxReview = $service->fetchTaxReviewSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $positions = [];
            foreach ((array)($statement['per_director'] ?? []) as $position) {
                $positions[(int)($position['director_id'] ?? 0)] = $position;
            }
            $primaryDirector = (array)($positions[$primaryPartyId] ?? []);
            $other = (array)($positions[$otherPartyId] ?? []);
            $externalCounterpartyEntry = array_values(array_filter(
                (array)($statement['attribution_entries'] ?? []),
                static fn(array $entry): bool => (string)($entry['counterparty_name'] ?? '') === 'External Counterparty'
            ));

            $harness->assertSame(true, (bool)($statement['success'] ?? false));
            $harness->assertSame(true, (bool)($disclosure['has_company_to_director_exposure'] ?? false));
            $harness->assertSame('353.00', directorLoanStatementMoney($disclosure['total_advances'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($disclosure['total_cash_repayments'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($disclosure['total_amounts_legally_set_off'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($disclosure['total_repayments'] ?? 0));
            $harness->assertSame('1288.63', directorLoanStatementMoney($disclosure['total_director_funding'] ?? 0));
            $statementText = (new \eel_accounts\Service\IxbrlTaxonomyProfileService())->directorLoanStatementText($disclosure);
            $harness->assertTrue(str_contains($statementText, 'advanced £253.00 to Primary Director'));
            $harness->assertTrue(str_contains($statementText, 'advanced £100.00 to Other Director'));
            $harness->assertTrue(str_contains(
                $statementText,
                'No amounts were legally set off, written off or waived.'
            ));
            $harness->assertTrue(str_contains(
                $statementText,
                'Main terms: Unsecured. Interest rate: 0%. Repayment conditions: Repayable after more than 12 months.'
            ));
            $harness->assertSame('253.00', directorLoanStatementMoney($primaryDirector['gross_asset'] ?? 0));
            $harness->assertSame('1288.63', directorLoanStatementMoney($primaryDirector['gross_liability'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($primaryDirector['desired_reclassification'] ?? 0));
            $harness->assertSame('1035.63', directorLoanStatementMoney($primaryDirector['net_closing_position'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($primaryDirector['potential_s455_exposure'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($other['desired_reclassification'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($other['potential_s455_exposure'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($statement['desired_reclassification'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($statement['potential_s455_exposure'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($taxReview['exposure_amount'] ?? 0));
            $harness->assertCount(1, $externalCounterpartyEntry);
            $harness->assertSame($primaryPartyId, (int)($externalCounterpartyEntry[0]['director_id'] ?? 0));
            $harness->assertSame(true, str_contains((string)($externalCounterpartyEntry[0]['source_url'] ?? ''), 'transaction_id='));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'keeps cash repayments, write-offs and waivers as distinct disclosure outcomes', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            $assetNominalId = (int)$fixture['asset_nominal_id'];
            directorLoanStatementInsertManualLine($fixture, $assetNominalId, 500.00, 0.00, $partyId, 'Advance made');
            directorLoanStatementInsertManualLine(
                $fixture,
                $assetNominalId,
                0.00,
                100.00,
                $partyId,
                'Cash repayment',
                \eel_accounts\Service\DirectorLoanService::CASH_REPAYMENT_JOURNAL_TAG
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                $assetNominalId,
                0.00,
                25.00,
                $partyId,
                'Amount written off',
                \eel_accounts\Service\DirectorLoanService::WRITE_OFF_JOURNAL_TAG
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                $assetNominalId,
                0.00,
                10.00,
                $partyId,
                'Amount waived',
                \eel_accounts\Service\DirectorLoanService::WAIVER_JOURNAL_TAG
            );

            $summary = $service->fetchDisclosureSummary(
                (int)$fixture['company_id'],
                (int)$fixture['accounting_period_id']
            );
            $harness->assertSame('500.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('100.00', directorLoanStatementMoney($summary['total_cash_repayments'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_amounts_legally_set_off'] ?? 0));
            $harness->assertSame('25.00', directorLoanStatementMoney($summary['total_amounts_written_off'] ?? 0));
            $harness->assertSame('10.00', directorLoanStatementMoney($summary['total_amounts_waived'] ?? 0));
            $harness->assertSame('365.00', directorLoanStatementMoney($summary['closing_company_to_director_balance'] ?? 0));
            $harness->assertSame(false, (bool)($summary['has_unclassified_reductions'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'lists unattributed entries and never offsets balances between different directors', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['asset_nominal_id'],
                500.00,
                0.00,
                (int)$fixture['primary_party_id'],
                'Primary director receivable'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                500.00,
                (int)$fixture['other_party_id'],
                'Other director payable'
            );
            directorLoanStatementInsertManualLine(
                $fixture,
                (int)$fixture['liability_nominal_id'],
                0.00,
                25.00,
                null,
                'Unattributed legacy line'
            );

            $statement = $service->fetchStatement((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);

            $harness->assertSame('0.00', directorLoanStatementMoney($statement['desired_reclassification'] ?? 0));
            $harness->assertSame('500.00', directorLoanStatementMoney($statement['potential_s455_exposure'] ?? 0));
            $harness->assertSame(1, (int)($statement['unattributed_count'] ?? 0));
            $harness->assertCount(1, (array)($statement['unattributed_entries'] ?? []));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'derives statutory advances and repayments from the legal running balance', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            $asset = (int)$fixture['asset_nominal_id'];
            $liability = (int)$fixture['liability_nominal_id'];

            directorLoanStatementInsertManualLine($fixture, $asset, 100.00, 0.00, $partyId, 'Company payment 100', '', '2025-01-02', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, $liability, 0.00, 200.00, $partyId, 'Director payment 200', '', '2025-01-03', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, $asset, 300.00, 0.00, $partyId, 'Company payment 300', '', '2025-01-04', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, $liability, 0.00, 50.00, $partyId, 'Director payment 50', '', '2025-01-05', 'bank_csv');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $row = (array)($summary['disclosures'][0] ?? []);
            $harness->assertSame('300.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('150.00', directorLoanStatementMoney($summary['total_cash_repayments'] ?? 0));
            $harness->assertSame('150.00', directorLoanStatementMoney($summary['total_repayments'] ?? 0));
            $harness->assertSame('150.00', directorLoanStatementMoney($summary['closing_company_to_director_balance'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['closing_company_liability'] ?? 0));
            $harness->assertSame(true, (bool)($summary['has_company_to_director_exposure'] ?? false));
            $harness->assertSame('0.00', directorLoanStatementMoney($row['amounts_legally_set_off'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'does not disclose debit movements that remain within an opening company creditor', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            $asset = (int)$fixture['asset_nominal_id'];
            $liability = (int)$fixture['liability_nominal_id'];
            directorLoanStatementInsertManualLine($fixture, $liability, 0.00, 1035.63, $partyId, 'Opening creditor', '', '2024-12-31', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, $liability, 0.00, 10873.46, $partyId, 'Director funding in period', '', '2025-01-02', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, $asset, 4620.83, 0.00, $partyId, 'Asset-control debits in period', '', '2025-01-03', 'bank_csv');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $evidence = (array)($summary['director_evidence'][0] ?? []);
            $harness->assertSame(false, (bool)($summary['has_company_to_director_exposure'] ?? true));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_repayments'] ?? 0));
            $harness->assertSame('7288.26', directorLoanStatementMoney($summary['closing_company_liability'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($evidence['advances'] ?? 1));
            $harness->assertSame(false, (bool)($evidence['section_413_required'] ?? true));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'excludes tagged year-end offsets and retains zero-advance director evidence', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            $asset = (int)$fixture['asset_nominal_id'];
            $liability = (int)$fixture['liability_nominal_id'];
            directorLoanStatementInsertManualLine($fixture, $asset, 0.00, 250.00, $partyId, 'Year-end asset offset', 'director_loan_offset');
            directorLoanStatementInsertManualLine($fixture, $liability, 250.00, 0.00, $partyId, 'Year-end liability offset', 'director_loan_offset');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $evidenceByName = [];
            foreach ((array)($summary['director_evidence'] ?? []) as $row) {
                $evidenceByName[(string)($row['director_name'] ?? '')] = $row;
            }
            $harness->assertSame(false, (bool)($summary['has_company_to_director_exposure'] ?? true));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_repayments'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_amounts_legally_set_off'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($evidenceByName['Other Director']['advances'] ?? 1));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'retains a disclosure for an advance fully repaid during the period', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['asset_nominal_id'], 253.00, 0.00, $partyId, 'Advance fully repaid', '', '2025-01-02', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 253.00, $partyId, 'Repayment in full', '', '2025-01-03', 'bank_csv');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(true, (bool)($summary['has_company_to_director_exposure'] ?? false));
            $harness->assertSame('253.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($summary['total_cash_repayments'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['closing_company_to_director_balance'] ?? 1));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'matches the frozen AP79 legal running-account result', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['asset_nominal_id'], 253.00, 0.00, $partyId, 'AP79 advances', '', '2025-01-02', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 1288.63, $partyId, 'AP79 director funding', '', '2025-01-03', 'bank_csv');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame('253.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('253.00', directorLoanStatementMoney($summary['total_cash_repayments'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['closing_company_to_director_balance'] ?? 0));
            $harness->assertSame('1035.63', directorLoanStatementMoney($summary['closing_company_liability'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_amounts_legally_set_off'] ?? 0));
            $harness->assertSame(true, (bool)($summary['has_company_to_director_exposure'] ?? false));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'matches the frozen AP81 creditor-only result', static function () use ($harness, $service): void {
        directorLoanStatementWithFixture($harness, static function (array $fixture) use ($harness, $service): void {
            $partyId = (int)$fixture['primary_party_id'];
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 7288.26, $partyId, 'AP81 opening creditor', '', '2024-12-31', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['liability_nominal_id'], 0.00, 350.52, $partyId, 'AP81 director funding', '', '2025-01-02', 'bank_csv');
            directorLoanStatementInsertManualLine($fixture, (int)$fixture['asset_nominal_id'], 185.82, 0.00, $partyId, 'AP81 asset-control debits', '', '2025-01-03', 'bank_csv');

            $summary = $service->fetchDisclosureSummary((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
            $harness->assertSame(false, (bool)($summary['has_company_to_director_exposure'] ?? true));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_advances'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_repayments'] ?? 0));
            $harness->assertSame('7452.96', directorLoanStatementMoney($summary['closing_company_liability'] ?? 0));
            $harness->assertSame('0.00', directorLoanStatementMoney($summary['total_amounts_legally_set_off'] ?? 0));
        });
    });

    $harness->check(\eel_accounts\Service\DirectorLoanService::class, 'uses compact shared interest-rate labels', static function () use ($harness): void {
        $format = [\eel_accounts\Service\DirectorLoanReportingPresentationService::class, 'formatInterestRate'];
        $harness->assertSame('0%', $format(0.0));
        $harness->assertSame('5%', $format(5.0));
        $harness->assertSame('5.25%', $format(5.25));
        $harness->assertSame('5.1235%', $format(5.12345));
    });
});

function directorLoanStatementWithFixture(GeneratedServiceClassTestHarness $harness, callable $callback): void
{
    foreach (['company_directors', 'company_parties', 'journal_lines', 'statement_uploads', 'transactions'] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' schema is not available.');
        }
    }

    InterfaceDB::beginTransaction();
    try {
        StandardNominalTestFixture::ensureNominals(['1200', '2100']);
        $assetNominalId = StandardNominalTestFixture::id('1200');
        $liabilityNominalId = StandardNominalTestFixture::id('2100');
        $marker = substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 12);
        InterfaceDB::prepareExecute(
            'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
            ['company_name' => 'Director Loan Subledger Fixture Limited', 'company_number' => 'DLS' . $marker]
        );
        $companyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies WHERE company_number = :company_number',
            ['company_number' => 'DLS' . $marker]
        );
        $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
        $settings->set('participator_loan_asset_nominal_id', $assetNominalId, 'int');
        $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
        $settings->flush();
        InterfaceDB::prepareExecute(
            'INSERT INTO accounting_periods (company_id, label, period_start, period_end)
             VALUES (:company_id, :label, :period_start, :period_end)',
            ['company_id' => $companyId, 'label' => '2025', 'period_start' => '2025-01-01', 'period_end' => '2025-12-31']
        );
        $accountingPeriodId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM accounting_periods WHERE company_id = :company_id',
            ['company_id' => $companyId]
        );
        foreach ([
            ['key' => 'primary-director:' . $marker, 'name' => 'Primary Director', 'appointed' => '2020-01-01'],
            ['key' => 'other:' . $marker, 'name' => 'Other Director', 'appointed' => '2021-01-01'],
        ] as $director) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_directors (
                    company_id, source, external_key, full_name, officer_role, appointed_on, is_active
                 ) VALUES (
                    :company_id, :source, :external_key, :full_name, :officer_role, :appointed_on, 1
                 )',
                [
                    'company_id' => $companyId,
                    'source' => 'companies_house',
                    'external_key' => $director['key'],
                    'full_name' => $director['name'],
                    'officer_role' => 'director',
                    'appointed_on' => $director['appointed'],
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
        foreach ([
            ['director_id' => $primaryDirectorId, 'name' => 'Primary Director'],
            ['director_id' => $otherDirectorId, 'name' => 'Other Director'],
        ] as $party) {
            InterfaceDB::prepareExecute(
                'INSERT INTO company_parties (company_id, party_type, legal_name, linked_director_id, source_note)
                 VALUES (:company_id, :party_type, :legal_name, :linked_director_id, :source_note)',
                [
                    'company_id' => $companyId,
                    'party_type' => 'individual',
                    'legal_name' => $party['name'],
                    'linked_director_id' => $party['director_id'],
                    'source_note' => 'Director Loan Statement test fixture',
                ]
            );
        }
        $primaryPartyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_parties WHERE company_id = :company_id AND linked_director_id = :director_id',
            ['company_id' => $companyId, 'director_id' => $primaryDirectorId]
        );
        $otherPartyId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM company_parties WHERE company_id = :company_id AND linked_director_id = :director_id',
            ['company_id' => $companyId, 'director_id' => $otherDirectorId]
        );

        $callback([
            'marker' => $marker,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'asset_nominal_id' => $assetNominalId,
            'liability_nominal_id' => $liabilityNominalId,
            'primary_party_id' => $primaryPartyId,
            'other_party_id' => $otherPartyId,
        ]);
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
}

function directorLoanStatementInsertManualLine(
    array $fixture,
    int $nominalId,
    float $debit,
    float $credit,
    ?int $partyId,
    string $description,
    string $journalTag = '',
    string $journalDate = '2025-12-31',
    string $sourceType = 'manual'
): int {
    $sourceRef = 'dla-manual:' . $fixture['marker'] . ':' . hash('sha256', $description . microtime(true));
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'journal_date' => $journalDate,
            'description' => $description,
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => $sourceRef]
    );
    if ($journalTag !== '') {
        InterfaceDB::prepareExecute(
            'INSERT INTO journal_entry_metadata (
                journal_id, company_id, accounting_period_id,
                journal_tag, journal_key, entry_mode, notes
             ) VALUES (
                :journal_id, :company_id, :accounting_period_id,
                :journal_tag, :journal_key, :entry_mode, :notes
             )',
            [
                'journal_id' => $journalId,
                'company_id' => (int)$fixture['company_id'],
                'accounting_period_id' => (int)$fixture['accounting_period_id'],
                'journal_tag' => $journalTag,
                'journal_key' => 'test:' . hash('sha256', $description),
                'entry_mode' => 'manual',
                'notes' => 'Director Loan statutory disclosure outcome fixture.',
            ]
        );
    }
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, party_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :party_id, :debit, :credit, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => $nominalId,
            'party_id' => $partyId,
            'debit' => number_format($debit, 2, '.', ''),
            'credit' => number_format($credit, 2, '.', ''),
            'description' => $description,
        ]
    );
    return (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journal_lines WHERE journal_id = :journal_id',
        ['journal_id' => $journalId]
    );
}

function directorLoanStatementInsertTransactionJournal(
    array $fixture,
    int $nominalId,
    float $amount,
    int $partyId,
    string $counterparty
): void {
    $hash = hash('sha256', 'dla-upload:' . $fixture['marker']);
    InterfaceDB::prepareExecute(
        'INSERT INTO statement_uploads (
            company_id, accounting_period_id, statement_month, original_filename, stored_filename, file_sha256
         ) VALUES (
            :company_id, :period_id, :statement_month, :original_filename, :stored_filename, :file_sha256
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'statement_month' => '2025-12-01',
            'original_filename' => 'dla.csv',
            'stored_filename' => 'dla-' . $fixture['marker'] . '.csv',
            'file_sha256' => $hash,
        ]
    );
    $uploadId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM statement_uploads WHERE company_id = :company_id AND file_sha256 = :hash',
        ['company_id' => (int)$fixture['company_id'], 'hash' => $hash]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO transactions (
            company_id, accounting_period_id, statement_upload_id, txn_date, description,
            amount, counterparty_name, dedupe_hash, nominal_account_id, party_id, category_status
         ) VALUES (
            :company_id, :period_id, :upload_id, :txn_date, :description,
            :amount, :counterparty_name, :dedupe_hash, :nominal_id, :party_id, :category_status
         )',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'upload_id' => $uploadId,
            'txn_date' => '2025-06-30',
            'description' => 'Funds advanced on the primary director account',
            'amount' => number_format($amount, 2, '.', ''),
            'counterparty_name' => $counterparty,
            'dedupe_hash' => hash('sha256', 'dla-transaction:' . $fixture['marker']),
            'nominal_id' => $nominalId,
            'party_id' => $partyId,
            'category_status' => 'manual',
        ]
    );
    $transactionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM transactions WHERE company_id = :company_id AND statement_upload_id = :upload_id',
        ['company_id' => (int)$fixture['company_id'], 'upload_id' => $uploadId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
         VALUES (:company_id, :period_id, :source_type, :source_ref, :journal_date, :description, 1)',
        [
            'company_id' => (int)$fixture['company_id'],
            'period_id' => (int)$fixture['accounting_period_id'],
            'source_type' => 'bank_csv',
            'source_ref' => 'transaction:' . $transactionId,
            'journal_date' => '2025-06-30',
            'description' => 'Funds advanced on the primary director account',
        ]
    );
    $journalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM journals WHERE company_id = :company_id AND source_ref = :source_ref',
        ['company_id' => (int)$fixture['company_id'], 'source_ref' => 'transaction:' . $transactionId]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO journal_lines (journal_id, nominal_account_id, party_id, debit, credit, line_description)
         VALUES (:journal_id, :nominal_id, :party_id, :debit, 0.00, :description)',
        [
            'journal_id' => $journalId,
            'nominal_id' => $nominalId,
            'party_id' => $partyId,
            'debit' => number_format($amount, 2, '.', ''),
            'description' => 'Funds advanced on the primary director account',
        ]
    );
}

function directorLoanStatementMoney(mixed $amount): string
{
    return number_format(round((float)$amount, 2), 2, '.', '');
}
