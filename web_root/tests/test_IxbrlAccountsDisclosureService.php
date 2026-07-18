<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'IxbrlTestFixture.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\IxbrlAccountsDisclosureService::class, static function (
    GeneratedServiceClassTestHarness $harness,
    \eel_accounts\Service\IxbrlAccountsDisclosureService $service
): void {
    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'defines period disclosures without backfilling statutory assumptions',
        static function () use ($harness): void {
            $migration = (string)file_get_contents(
                dirname(__DIR__, 2)
                . DIRECTORY_SEPARATOR . 'db_schema'
                . DIRECTORY_SEPARATOR . 'migrations'
                . DIRECTORY_SEPARATOR . '2026_07_16_004_ixbrl_accounts_disclosures.sql'
            );

            $harness->assertTrue(str_contains($migration, 'CREATE TABLE IF NOT EXISTS ixbrl_accounts_disclosures'));
            $harness->assertTrue(str_contains($migration, 'average_number_employees INT UNSIGNED NULL'));
            $harness->assertTrue(str_contains($migration, 'entity_dormant TINYINT(1) NULL'));
            $harness->assertTrue(str_contains($migration, 'entity_trading_status VARCHAR(30) NULL'));
            $harness->assertTrue(str_contains($migration, 'micro_entity_eligibility_confirmed TINYINT(1) NULL'));
            $harness->assertTrue(str_contains($migration, 'going_concern_basis_appropriate TINYINT(1) NULL'));
            $harness->assertTrue(str_contains($migration, 'has_material_off_balance_sheet_arrangements TINYINT(1) NULL'));
            $harness->assertTrue(str_contains($migration, 'has_director_advances_credits_or_guarantees TINYINT(1) NULL'));
            $harness->assertTrue(str_contains($migration, 'has_financial_commitments_guarantees_or_contingencies TINYINT(1) NULL'));
            $harness->assertFalse(str_contains($migration, 'INSERT INTO ixbrl_accounts_disclosures'));
            $harness->assertFalse(str_contains($migration, 'UPDATE ixbrl_accounts_disclosures'));
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'requires explicit simple-note answers and treats only positive answers as unsupported',
        static function () use ($harness, $service): void {
            $period = ['period_end' => '2025-12-31'];
            $validate = new ReflectionMethod($service, 'validate');
            $unsupported = new ReflectionMethod($service, 'unsupportedProfileFields');
            $profileErrors = new ReflectionMethod($service, 'unsupportedProfileErrors');

            $noAnswers = ixbrlDisclosureInput();
            [$validated, $errors] = $validate->invoke($service, $noAnswers, $period);
            $harness->assertSame([], $errors);
            $harness->assertSame(0, (int)$validated['has_material_off_balance_sheet_arrangements']);
            $harness->assertSame(0, (int)$validated['has_director_advances_credits_or_guarantees']);
            $harness->assertSame(0, (int)$validated['has_financial_commitments_guarantees_or_contingencies']);
            $harness->assertSame(1, (int)$validated['micro_entity_eligibility_confirmed']);
            $harness->assertSame(1, (int)$validated['going_concern_basis_appropriate']);
            $harness->assertSame([], $unsupported->invoke($service, $validated));

            $missingAnswer = $noAnswers;
            unset($missingAnswer['has_director_advances_credits_or_guarantees']);
            [, $missingErrors] = $validate->invoke($service, $missingAnswer, $period);
            $harness->assertTrue(str_contains(
                implode(' ', $missingErrors),
                'Confirm director guarantees with Yes or No.'
            ));

            $positiveAnswer = $noAnswers;
            $positiveAnswer['has_material_off_balance_sheet_arrangements'] = 1;
            [$positiveValues, $positiveValidationErrors] = $validate->invoke($service, $positiveAnswer, $period);
            $harness->assertSame([], $positiveValidationErrors);
            $harness->assertSame(
                ['has_material_off_balance_sheet_arrangements'],
                $unsupported->invoke($service, $positiveValues)
            );
            $errors = $profileErrors->invoke($service, $positiveValues);
            $harness->assertTrue(str_contains((string)($errors[0] ?? ''), 'positive-note disclosures'));
            $harness->assertTrue(str_contains((string)($errors[0] ?? ''), 'Yes answer has been saved'));
            $harness->assertTrue(str_contains((string)($errors[0] ?? ''), 'material off-balance-sheet arrangements'));

            $ineligible = $noAnswers;
            $ineligible['micro_entity_eligibility_confirmed'] = 0;
            [$ineligibleValues, $ineligibleValidationErrors] = $validate->invoke($service, $ineligible, $period);
            $harness->assertSame([], $ineligibleValidationErrors);
            $harness->assertSame(
                ['micro_entity_eligibility_confirmed'],
                $unsupported->invoke($service, $ineligibleValues)
            );
            $ineligibleErrors = implode(' ', $profileErrors->invoke($service, $ineligibleValues));
            $harness->assertTrue(str_contains($ineligibleErrors, 'micro-entity eligibility'));
            $harness->assertTrue(str_contains($ineligibleErrors, 'No answer has been saved'));

            $notGoingConcern = $noAnswers;
            $notGoingConcern['going_concern_basis_appropriate'] = 0;
            [$notGoingConcernValues, $notGoingConcernValidationErrors] = $validate->invoke($service, $notGoingConcern, $period);
            $harness->assertSame([], $notGoingConcernValidationErrors);
            $notGoingConcernErrors = implode(' ', $profileErrors->invoke($service, $notGoingConcernValues));
            $harness->assertTrue(str_contains($notGoingConcernErrors, 'going-concern basis'));
            $harness->assertTrue(str_contains($notGoingConcernErrors, 'No answer has been saved'));
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'saves explicit locked-period facts, audits changes, and accepts false confirmations',
        static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')) {
                $harness->skip('The iXBRL accounts disclosures migration has not been applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDisclosureFixture(false);
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];

                $initial = $service->fetch($companyId, $periodId);
                $harness->assertSame(false, (bool)($initial['complete'] ?? true));
                $harness->assertSame(false, (bool)($initial['stored'] ?? true));
                $harness->assertTrue(in_array('average_number_employees', (array)($initial['missing_fields'] ?? []), true));

                $input = ixbrlDisclosureInput();
                $saved = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($saved['success'] ?? false));
                $harness->assertSame(true, (bool)($saved['changed'] ?? false));
                $harness->assertSame(true, (bool)($saved['complete'] ?? false));
                $harness->assertSame(1, (int)($saved['revision'] ?? 0));
            $harness->assertSame(1, (int)($saved['disclosures']['entity_dormant'] ?? -1));
            $harness->assertSame(0.0, (float)($saved['dormancy']['gross_sales'] ?? -1));

                $harness->assertSame(1, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM year_end_audit_log
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                       AND action = :action',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'action' => 'ixbrl_disclosures_changed',
                    ]
                ));

                $same = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($same['success'] ?? false));
                $harness->assertSame(false, (bool)($same['changed'] ?? true));
                $harness->assertSame(1, (int)($same['revision'] ?? 0));

                $input['average_number_employees'] = 2;
                $changed = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($changed['changed'] ?? false));
                $harness->assertSame(2, (int)($changed['revision'] ?? 0));

                $input['audit_exempt_section_477'] = 0;
                $unsupported = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($unsupported['success'] ?? false));
                $harness->assertSame(false, (bool)($unsupported['complete'] ?? true));
                $harness->assertSame(false, (bool)($unsupported['profile_supported'] ?? true));
                $harness->assertTrue(str_contains(
                    (string)(($unsupported['profile_errors'] ?? [])[0] ?? ''),
                    'FRS 105 unaudited micro-entity accounts only'
                ));

                $input['audit_exempt_section_477'] = 1;
                $input['has_material_off_balance_sheet_arrangements'] = 1;
                $positiveNote = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($positiveNote['success'] ?? false));
                $harness->assertSame(1, (int)($positiveNote['disclosures']['has_material_off_balance_sheet_arrangements'] ?? 0));
                $harness->assertSame(false, (bool)($positiveNote['complete'] ?? true));
                $harness->assertSame(false, (bool)($positiveNote['profile_supported'] ?? true));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($positiveNote['profile_errors'] ?? [])),
                    'positive-note disclosures'
                ));

                $input['has_material_off_balance_sheet_arrangements'] = 0;
                $input['micro_entity_eligibility_confirmed'] = 0;
                $ineligible = $service->save($companyId, $periodId, $input, 'test:disclosures');
                $harness->assertSame(true, (bool)($ineligible['success'] ?? false));
                $harness->assertSame(0, (int)($ineligible['disclosures']['micro_entity_eligibility_confirmed'] ?? 1));
                $harness->assertSame(false, (bool)($ineligible['complete'] ?? true));
                $harness->assertTrue(str_contains(
                    implode(' ', (array)($ineligible['profile_errors'] ?? [])),
                    'micro-entity eligibility'
                ));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'keeps matching Companies House facts as source-labelled suggestions until saved',
        static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')) {
                $harness->skip('The iXBRL accounts disclosures migration has not been applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDisclosureFixture(true);
                $result = $service->fetch((int)$fixture['company_id'], (int)$fixture['accounting_period_id']);
                $suggestions = (array)($result['suggested_disclosures'] ?? []);
                $sources = (array)($result['suggestion_sources'] ?? []);

                $harness->assertSame(false, (bool)($result['stored'] ?? true));
                $harness->assertSame(false, (bool)($result['complete'] ?? true));
                $harness->assertSame(1, (int)($suggestions['average_number_employees'] ?? 0));
                $harness->assertFalse(array_key_exists('entity_dormant', $suggestions));
                $harness->assertSame('trading', (string)($suggestions['entity_trading_status'] ?? ''));
                $harness->assertSame(true, (bool)(($result['trading_status_evidence'] ?? [])['has_previous_trading_evidence'] ?? false));
                $harness->assertSame(1, (int)(($result['trading_status_answers'] ?? [])['is_still_trading'] ?? -1));
                $harness->assertSame('2026-02-28', (string)($suggestions['accounts_approval_date'] ?? ''));
                $harness->assertSame('Companies House filed iXBRL suggestion', (string)($sources['average_number_employees']['label'] ?? ''));
                $harness->assertSame(null, $result['disclosures']['average_number_employees'] ?? null);
                $harness->assertSame(false, array_key_exists('has_material_off_balance_sheet_arrangements', $suggestions));
                $harness->assertSame(false, array_key_exists('has_director_advances_credits_or_guarantees', $suggestions));
                $harness->assertSame(false, array_key_exists('has_financial_commitments_guarantees_or_contingencies', $suggestions));
                $harness->assertSame(false, array_key_exists('micro_entity_eligibility_confirmed', $suggestions));
                $harness->assertSame(false, array_key_exists('going_concern_basis_appropriate', $suggestions));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'derives non-dormant status from posted Sales credits',
        static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')) {
                $harness->skip('The iXBRL accounts disclosures migration has not been applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDisclosureFixture(false);
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                $salesId = (int)$fixture['sales_nominal_id'];
                $bankId = (int)$fixture['bank_nominal_id'];
                $harness->assertTrue($salesId > 0);
                $harness->assertTrue($bankId > 0);

                $sourceRef = 'ixbrl-sales-' . bin2hex(random_bytes(4));
                InterfaceDB::prepareExecute(
                    'INSERT INTO journals (company_id, accounting_period_id, source_type, source_ref, journal_date, description, is_posted)
                     VALUES (:company_id, :accounting_period_id, :source_type, :source_ref, :journal_date, :description, 1)',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'source_type' => 'manual',
                        'source_ref' => $sourceRef,
                        'journal_date' => '2025-06-30',
                        'description' => 'Sales activity fixture',
                    ]
                );
                $journalId = (int)InterfaceDB::fetchColumn(
                    'SELECT id FROM journals
                     WHERE company_id = :company_id AND source_type = :source_type AND source_ref = :source_ref
                     LIMIT 1',
                    ['company_id' => $companyId, 'source_type' => 'manual', 'source_ref' => $sourceRef]
                );
                $harness->assertTrue($journalId > 0);
                InterfaceDB::prepareExecute(
                    'INSERT INTO journal_lines (journal_id, nominal_account_id, debit, credit, line_description)
                     VALUES (:journal_id, :nominal_account_id, 0, :credit, :line_description),
                            (:journal_id, :bank_id, :debit, 0, :bank_description)',
                    [
                        'journal_id' => $journalId,
                        'nominal_account_id' => $salesId,
                        'credit' => '125.00',
                        'line_description' => 'Sales',
                        'bank_id' => $bankId,
                        'debit' => '125.00',
                        'bank_description' => 'Receipt',
                    ]
                );

                $input = ixbrlDisclosureInput();
                $input['entity_dormant'] = 1;
                $input['is_still_trading'] = 0;
                $input['has_ever_traded'] = 0;
                $saved = $service->save($companyId, $periodId, $input, 'test:derived-dormancy');
                $harness->assertSame(true, (bool)($saved['success'] ?? false));
                $harness->assertSame(0, (int)($saved['disclosures']['entity_dormant'] ?? -1));
                $harness->assertSame(125.0, (float)($saved['dormancy']['gross_sales'] ?? -1));
                $harness->assertSame(false, (bool)($saved['dormancy']['entity_dormant'] ?? true));
                $harness->assertSame('no_longer_trading', (string)($saved['disclosures']['entity_trading_status'] ?? ''));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'derives no-longer and never-traded statuses from the adaptive answers',
        static function () use ($harness, $service): void {
            $derive = new ReflectionMethod($service, 'deriveTradingStatus');
            $derive->setAccessible(true);
            $noEvidence = ['has_previous_trading_evidence' => false, 'sources' => []];
            $evidence = ['has_previous_trading_evidence' => true, 'sources' => [['type' => 'ledger_sales']]];

            $harness->assertSame(['trading', []], $derive->invoke($service, ['is_still_trading' => 1], $noEvidence));
            $harness->assertSame(['no_longer_trading', []], $derive->invoke($service, ['is_still_trading' => 0, 'has_ever_traded' => 1], $noEvidence));
            $harness->assertSame(['never_traded', []], $derive->invoke($service, ['is_still_trading' => 0, 'has_ever_traded' => 0], $noEvidence));
            $harness->assertSame(['no_longer_trading', []], $derive->invoke($service, ['is_still_trading' => 0, 'has_ever_traded' => 0], $evidence));
            $missing = $derive->invoke($service, ['is_still_trading' => 0], $noEvidence);
            $harness->assertSame('', (string)($missing[0] ?? 'unexpected'));
            $harness->assertTrue(str_contains(implode(' ', (array)($missing[1] ?? [])), 'ever traded'));
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'rejects disclosure saves when Year End is unlocked without writing or auditing',
        static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')) {
                $harness->skip('The iXBRL accounts disclosures migration has not been applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDisclosureFixture(false);
                $companyId = (int)$fixture['company_id'];
                $periodId = (int)$fixture['accounting_period_id'];
                InterfaceDB::prepareExecute(
                    'UPDATE year_end_reviews SET is_locked = 0, locked_at = NULL, locked_by = NULL
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                );

                $result = $service->save($companyId, $periodId, ixbrlDisclosureInput(), 'test:unlocked');
                $harness->assertSame(false, (bool)($result['success'] ?? true));
                $harness->assertTrue(str_contains(implode(' ', (array)($result['errors'] ?? [])), 'Complete and lock Year End'));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM ixbrl_accounts_disclosures
                     WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                    ['company_id' => $companyId, 'accounting_period_id' => $periodId]
                ));
                $harness->assertSame(0, (int)InterfaceDB::fetchColumn(
                    'SELECT COUNT(*) FROM year_end_audit_log
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                       AND action = :action',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                        'action' => 'ixbrl_disclosures_changed',
                    ]
                ));
                $buildError = '';
                try {
                    (new \eel_accounts\Service\IxbrlAccountsReportService())->build($companyId, $periodId);
                } catch (\RuntimeException $exception) {
                    $buildError = $exception->getMessage();
                }
                $harness->assertTrue(str_contains($buildError, 'Complete and lock Year End'));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\IxbrlAccountsDisclosureService::class,
        'rejects incomplete and future disclosure input',
        static function () use ($harness, $service): void {
            if (!InterfaceDB::tableExists('ixbrl_accounts_disclosures')) {
                $harness->skip('The iXBRL accounts disclosures migration has not been applied.');
            }

            InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlDisclosureFixture(false);
                $invalid = ixbrlDisclosureInput();
                $invalid['accounts_approval_date'] = '2999-01-01';
                unset($invalid['members_have_not_required_audit']);
                unset($invalid['has_financial_commitments_guarantees_or_contingencies']);
                unset($invalid['going_concern_basis_appropriate']);

                $result = $service->save(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    $invalid,
                    'test:invalid'
                );
                $errors = implode(' ', (array)($result['errors'] ?? []));
                $harness->assertSame(false, (bool)($result['success'] ?? true));
                $harness->assertTrue(str_contains($errors, 'cannot be in the future'));
                $harness->assertTrue(str_contains($errors, 'members have not required an audit'));
                $harness->assertTrue(str_contains($errors, 'financial commitments, guarantees or contingencies'));
                $harness->assertTrue(str_contains($errors, 'going-concern basis'));
            } finally {
                if (InterfaceDB::inTransaction()) {
                    InterfaceDB::rollBack();
                }
            }
        }
    );
});

function ixbrlDisclosureInput(): array
{
    return [
        'accounting_standard' => 'FRS_105',
        'average_number_employees' => 1,
        'entity_dormant' => 0,
        'is_still_trading' => 1,
        'micro_entity_eligibility_confirmed' => 1,
        'going_concern_basis_appropriate' => 1,
        'has_material_off_balance_sheet_arrangements' => 0,
        'has_director_advances_credits_or_guarantees' => 0,
        'has_financial_commitments_guarantees_or_contingencies' => 0,
        'accounts_approval_date' => '2026-02-28',
        'approving_director_name' => 'Test Director',
        'prepared_under_small_companies_regime' => 1,
        'audit_exempt_section_477' => 1,
        'directors_acknowledge_responsibilities' => 1,
        'members_have_not_required_audit' => 1,
    ];
}

function ixbrlDisclosureFixture(bool $withFiledSuggestions): array
{
    ixbrl_test_ensure_frs105_thresholds();
    $marker = strtoupper(substr(hash('sha256', __FILE__ . microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8));
    $companyNumber = 'IX' . $marker;
    InterfaceDB::prepareExecute(
        'INSERT INTO companies (company_name, company_number) VALUES (:company_name, :company_number)',
        ['company_name' => 'iXBRL Disclosure Fixture ' . $marker, 'company_number' => $companyNumber]
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
            'label' => 'Disclosure AP ' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $periodId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM accounting_periods WHERE company_id = :company_id AND label = :label',
        ['company_id' => $companyId, 'label' => 'Disclosure AP ' . $marker]
    );
    $salesNominalId = ixbrl_test_assign_sales_nominal($companyId);
    StandardNominalTestFixture::ensureNominals(['1000']);
    $bankNominalId = StandardNominalTestFixture::id('1000');
    InterfaceDB::prepareExecute(
        'INSERT INTO year_end_reviews (company_id, accounting_period_id, is_locked, locked_at, locked_by)
         VALUES (:company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by)',
        ['company_id' => $companyId, 'accounting_period_id' => $periodId, 'locked_by' => 'test']
    );

    if ($withFiledSuggestions) {
        ixbrlDisclosureFiledSuggestions($companyId, $companyNumber, $marker);
    }

    return [
        'company_id' => $companyId,
        'accounting_period_id' => $periodId,
        'sales_nominal_id' => $salesNominalId,
        'bank_nominal_id' => $bankNominalId,
    ];
}

function ixbrlDisclosureFiledSuggestions(int $companyId, string $companyNumber, string $marker): void
{
    InterfaceDB::prepareExecute(
        'INSERT INTO companies_house_documents (
            company_id, company_number, transaction_id, filing_date, filing_type,
            document_id, metadata_url, parse_status
         ) VALUES (
            :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
            :document_id, :metadata_url, :parse_status
         )',
        [
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'transaction_id' => 'tx-' . $marker,
            'filing_date' => '2026-03-01',
            'filing_type' => 'AA',
            'document_id' => 'doc-' . $marker,
            'metadata_url' => 'https://example.test/' . $marker,
            'parse_status' => 'parsed',
        ]
    );
    $documentId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies_house_documents WHERE document_id = :document_id',
        ['document_id' => 'doc-' . $marker]
    );
    InterfaceDB::prepareExecute(
        'INSERT INTO companies_house_document_contexts (
            document_fk, context_ref, period_start, period_end, is_latest_year_context
         ) VALUES (
            :document_fk, :context_ref, :period_start, :period_end, 1
         )',
        [
            'document_fk' => $documentId,
            'context_ref' => 'duration-' . $marker,
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    $contextId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM companies_house_document_contexts WHERE document_fk = :document_fk',
        ['document_fk' => $documentId]
    );

    $facts = [
        ['EndDateForPeriodCoveredByReport', '2025-12-31', null, null, '2025-12-31'],
        ['AverageNumberEmployeesDuringPeriod', '1', '1.00', null, null],
        ['EntityDormantTruefalse', 'false', null, 'false', null],
        ['EntityTradingStatus', 'trading', null, 'trading', null],
        ['DateAuthorisationFinancialStatementsForIssue', '2026-02-28', null, null, '2026-02-28'],
    ];
    foreach ($facts as [$shortName, $raw, $numeric, $text, $date]) {
        $conceptName = 'test:' . $marker . ':' . $shortName;
        InterfaceDB::prepareExecute(
            'INSERT INTO companies_house_taxonomy_concepts (
                concept_name, short_name, friendly_label, value_type
             ) VALUES (
                :concept_name, :short_name, :friendly_label, :value_type
             )',
            [
                'concept_name' => $conceptName,
                'short_name' => $shortName,
                'friendly_label' => $shortName,
                'value_type' => $numeric !== null ? 'numeric' : ($date !== null ? 'date' : 'text'),
            ]
        );
        $conceptId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM companies_house_taxonomy_concepts WHERE concept_name = :concept_name',
            ['concept_name' => $conceptName]
        );
        InterfaceDB::prepareExecute(
            'INSERT INTO companies_house_document_facts (
                document_fk, context_fk, concept_fk, raw_value, normalised_numeric,
                normalised_text, normalised_date, is_numeric, is_latest_year_fact
             ) VALUES (
                :document_fk, :context_fk, :concept_fk, :raw_value, :normalised_numeric,
                :normalised_text, :normalised_date, :is_numeric, 1
             )',
            [
                'document_fk' => $documentId,
                'context_fk' => $contextId,
                'concept_fk' => $conceptId,
                'raw_value' => $raw,
                'normalised_numeric' => $numeric,
                'normalised_text' => $text,
                'normalised_date' => $date,
                'is_numeric' => $numeric !== null ? 1 : 0,
            ]
        );
    }
}
