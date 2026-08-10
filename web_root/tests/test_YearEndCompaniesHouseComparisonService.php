<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(\eel_accounts\Service\YearEndCompaniesHouseComparisonService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(\eel_accounts\Service\YearEndCompaniesHouseComparisonService::class, 'selects only an exact reporting-period filing for numeric comparison', static function () use ($harness): void {
        $service = new \eel_accounts\Service\YearEndCompaniesHouseComparisonService();
        $findExact = new ReflectionMethod($service, 'findExactSummary');
        $findExact->setAccessible(true);

        $summaries = [
            ['id' => 1, 'filing_date' => '2025-06-28', 'latest_year_period_start' => '2023-10-01', 'latest_year_period_end' => '2024-09-30'],
            ['id' => 2, 'filing_date' => '2026-06-28', 'latest_year_period_start' => '2024-10-01', 'latest_year_period_end' => '2025-09-30'],
        ];

        $harness->assertSame(null, $findExact->invoke($service, [$summaries[0]], '2025-09-30'));
        $exact = $findExact->invoke($service, $summaries, '2025-09-30');
        $harness->assertSame(2, (int)($exact['id'] ?? 0));
        $harness->assertSame('2025-09-30', (string)($exact['period_end'] ?? ''));
    });

    $harness->check(
        \eel_accounts\Service\YearEndCompaniesHouseComparisonService::class,
        'treats an unavailable revised observation as requiring attention',
        static function () use ($harness): void {
            $result = (new \eel_accounts\Service\YearEndCompaniesHouseComparisonService())
                ->fetchRevisedObservation(0, 0);

            $harness->assertSame(false, !empty($result['available']));
            $harness->assertSame('unverifiable', (string)($result['reconciliation_state'] ?? ''));
            $harness->assertSame(true, !empty($result['filing_outstanding']));
            $harness->assertSame(true, !empty($result['action_required']));
            $harness->assertSame(false, !empty($result['coverage_complete']));
        }
    );

    $harness->check(
        \eel_accounts\Service\YearEndCompaniesHouseComparisonService::class,
        'keeps amendments out of the original nearest-filing reference',
        static function () use ($harness): void {
            $service = new \eel_accounts\Service\YearEndCompaniesHouseComparisonService();
            $method = new ReflectionMethod($service, 'findNearestSummary');
            $aamd = [
                'id' => 10,
                'filing_type' => 'AAMD',
                'filing_description' => 'accounts-amended-with-accounts-type-micro-entity',
                'filing_date' => '2026-08-10',
                'latest_year_period_end' => '2024-09-30',
            ];
            $marked = [
                'id' => 11,
                'filing_type' => 'AA',
                'filing_description' => 'accounts-with-accounts-type-micro-entity',
                'filing_date' => '2026-08-11',
                'latest_year_period_end' => '2024-09-30',
                'revision_marker' => true,
            ];
            $originalReference = [
                'id' => 12,
                'filing_type' => 'AA',
                'filing_description' => 'accounts-with-accounts-type-micro-entity',
                'filing_date' => '2025-06-01',
                'latest_year_period_start' => '2022-10-01',
                'latest_year_period_end' => '2023-09-30',
                'parse_status' => 'parsed_latest_year',
            ];

            $nearest = (array)$method->invoke(
                $service,
                [$aamd, $marked, $originalReference],
                '2024-09-30'
            );
            $harness->assertSame(12, (int)($nearest['id'] ?? 0));
            $harness->assertSame(null, $method->invoke($service, [$aamd, $marked], '2024-09-30'));
        }
    );

    $harness->check(
        \eel_accounts\Service\YearEndCompaniesHouseComparisonService::class,
        'keeps a no-original comparison hash stable when the first stored document is AAMD',
        static function () use ($harness): void {
            foreach ([
                'companies',
                'accounting_periods',
                'companies_house_documents',
                'companies_house_document_contexts',
                'companies_house_document_facts',
                'companies_house_taxonomy_concepts',
            ] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }

            $seed = random_int(800000000, 809999000);
            $fixture = [
                'company_id' => $seed,
                'accounting_period_id' => $seed + 1,
                'company_number' => 'NO' . substr((string)$seed, -6),
            ];
            InterfaceDB::beginTransaction();
            try {
                InterfaceDB::execute(
                    'INSERT INTO companies (id, company_name, company_number, is_active)
                     VALUES (:id, :name, :number, 1)',
                    [
                        'id' => $fixture['company_id'],
                        'name' => 'No Original Fixture Limited',
                        'number' => $fixture['company_number'],
                    ]
                );
                InterfaceDB::execute(
                    'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
                     VALUES (:id, :company_id, :label, :period_start, :period_end)',
                    [
                        'id' => $fixture['accounting_period_id'],
                        'company_id' => $fixture['company_id'],
                        'label' => 'No original fixture',
                        'period_start' => '2023-10-01',
                        'period_end' => '2024-09-30',
                    ]
                );
                $service = new \eel_accounts\Service\YearEndCompaniesHouseComparisonService();
                $period = [
                    'id' => $fixture['accounting_period_id'],
                    'period_start' => '2023-10-01',
                    'period_end' => '2024-09-30',
                ];
                $appMetrics = ['current_assets' => 25.00, 'reliable_closing_balance' => true];
                $before = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );

                yearEndCompaniesHouseInsertDocument(
                    $fixture,
                    $seed + 2,
                    '2026-08-10',
                    'AAMD',
                    'metadata_fetch_failed',
                    'Document metadata unavailable.',
                    '2026-08-10T10:00:00.000000000Z'
                );
                $after = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );

                $harness->assertSame(
                    hash('sha256', (string)json_encode($before, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                    hash('sha256', (string)json_encode($after, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))
                );
                $harness->assertSame('no_exact_filing', (string)($after['comparison_scope'] ?? ''));
                $harness->assertSame(null, $after['nearest_filing'] ?? null);
                $harness->assertSame(false, !empty($after['has_exact_filing']));

                yearEndCompaniesHouseInsertDocument(
                    $fixture,
                    $seed + 3,
                    '2025-06-01',
                    'AA',
                    'metadata_fetch_failed',
                    'Original document metadata is temporarily unavailable.'
                );
                $failedOriginal = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame(true, !empty($failedOriginal['has_exact_filing']));
                $harness->assertSame(
                    'exact_filing_unverifiable',
                    (string)($failedOriginal['comparison_scope'] ?? '')
                );
                $harness->assertSame(0, (int)($failedOriginal['comparable_count'] ?? -1));
                $harness->assertSame(false, !empty($failedOriginal['can_acknowledge']));
                $harness->assertTrue(str_contains(
                    (string)($failedOriginal['comparison_note'] ?? ''),
                    'cannot be approved'
                ));
            } finally {
                InterfaceDB::rollBack();
            }
        }
    );

    $harness->check(
        \eel_accounts\Service\YearEndCompaniesHouseComparisonService::class,
        'observes the latest revision without replacing original approval facts or reading superseded values',
        static function () use ($harness): void {
            foreach ([
                'companies',
                'accounting_periods',
                'companies_house_documents',
                'companies_house_document_contexts',
                'companies_house_document_facts',
                'companies_house_taxonomy_concepts',
                'year_end_review_acknowledgements',
            ] as $table) {
                if (!InterfaceDB::tableExists($table)) {
                    $harness->skip($table . ' table is not available.');
                }
            }

            $fixture = yearEndCompaniesHouseRevisionFixture();
            InterfaceDB::beginTransaction();
            try {
                yearEndCompaniesHouseRevisionSeed($fixture);
                $service = new \eel_accounts\Service\YearEndCompaniesHouseComparisonService();
                $period = [
                    'id' => $fixture['accounting_period_id'],
                    'period_start' => '2023-10-01',
                    'period_end' => '2024-09-30',
                ];
                $appMetrics = [
                    'fixed_assets' => 0.00,
                    'current_assets' => 200.00,
                    'prepayments_accrued_income' => 30.00,
                    'creditors_within_one_year' => 50.00,
                    'net_current_assets_liabilities' => -10.00,
                    'net_assets_liabilities' => -20.00,
                    'equity_capital_reserves' => -20.00,
                    'reliable_closing_balance' => true,
                ];

                $comparisonWithoutAlias = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                yearEndCompaniesHouseInsertNumericFact(
                    $fixture,
                    $fixture['original_document_id'],
                    $fixture['original_context_id'],
                    $fixture['revised_prepayments_concept_id'],
                    999.00,
                    8
                );
                $beforeRevision = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame(
                    hash('sha256', (string)json_encode(
                        $comparisonWithoutAlias,
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                    )),
                    hash('sha256', (string)json_encode(
                        $beforeRevision,
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                    ))
                );
                $comparisonHashBefore = hash(
                    'sha256',
                    (string)json_encode($beforeRevision, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                );
                $checkCode = \eel_accounts\Service\CompaniesHouseComparisonReviewService::MISMATCH_CHECK_CODE;
                $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
                $sectionApprovals = new \eel_accounts\Service\YearEndSectionApprovalService();
                $bundleMethod = new ReflectionMethod($sectionApprovals, 'companiesHouseBundle');
                $basisMethod = new ReflectionMethod($sectionApprovals, 'approvalBasis');
                $answers = [
                    'companies_house.xml_eligibility' => 'eligible',
                    'companies_house.variance_explanation' => 'The original filing requires correction.',
                ];
                $beforeBundle = (array)$bundleMethod->invoke(
                    $sectionApprovals,
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $checkCode,
                    [
                        'comparison' => $beforeRevision,
                        'mismatch_count' => (int)($beforeRevision['mismatch_count'] ?? 0),
                        'eligibility' => ['decision' => 'eligible'],
                    ]
                );
                $basisBefore = (array)$basisMethod->invoke($sectionApprovals, $beforeBundle, $answers);
                $saved = $acknowledgements->save(
                    (int)$fixture['company_id'],
                    (int)$fixture['accounting_period_id'],
                    $checkCode,
                    $basisBefore,
                    'revision_observation_test',
                    '',
                    true,
                    \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
                );
                $harness->assertSame(true, !empty($saved['success']));
                $storedApproval = (array)($saved['acknowledgement'] ?? []);
                $basisHashBefore = $acknowledgements->hashBasis($basisBefore);
                $harness->assertSame($basisHashBefore, (string)($storedApproval['basis_hash'] ?? ''));

                $preservedRefresh = (new \eel_accounts\Service\CompaniesHousePersistenceService())
                    ->persistDocument([
                        'document_id' => 'document-' . $fixture['original_document_id'],
                        'parse_status' => 'content_fetch_failed',
                        'parse_error' => 'Simulated transient refresh failure.',
                    ]);
                $harness->assertSame(true, !empty($preservedRefresh['preserved_existing_parse']));
                $afterFailedOriginalRefresh = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame($comparisonHashBefore, hash(
                    'sha256',
                    (string)json_encode(
                        $afterFailedOriginalRefresh,
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                    )
                ));

                yearEndCompaniesHouseRevisionSeedParsedRevision($fixture);
                $original = $service->fetchComparison(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame($comparisonHashBefore, hash(
                    'sha256',
                    (string)json_encode($original, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                ));
                $afterBundle = (array)$bundleMethod->invoke(
                    $sectionApprovals,
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $checkCode,
                    [
                        'comparison' => $original,
                        'mismatch_count' => (int)($original['mismatch_count'] ?? 0),
                        'eligibility' => ['decision' => 'eligible'],
                    ]
                );
                $basisAfter = (array)$basisMethod->invoke($sectionApprovals, $afterBundle, $answers);
                $harness->assertSame($basisHashBefore, $acknowledgements->hashBasis($basisAfter));
                $evaluation = $acknowledgements->evaluate(
                    $storedApproval,
                    $basisAfter,
                    false,
                    \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
                );
                $harness->assertSame('current', (string)($evaluation['state'] ?? ''));
                $harness->assertSame(true, !empty($evaluation['current']));
                $harness->assertSame(
                    ['id', 'filing_date', 'filing_type', 'period_start', 'period_end', 'parse_status'],
                    array_keys((array)($original['nearest_filing'] ?? []))
                );
                $harness->assertSame($fixture['original_document_id'], (int)($original['filing']['document_row_id'] ?? 0));
                $harness->assertSame($fixture['original_document_id'], (int)($original['nearest_filing']['id'] ?? 0));
                $originalCurrentAssets = array_values(array_filter(
                    (array)$original['rows'],
                    static fn(array $row): bool => (string)$row['metric_key'] === 'current_assets'
                ))[0] ?? [];
                $harness->assertSame(100.0, (float)($originalCurrentAssets['filed_value'] ?? 0));
                $harness->assertSame(false, array_key_exists('revised_filed_value', $originalCurrentAssets));
                $originalPrepayments = array_values(array_filter(
                    (array)$original['rows'],
                    static fn(array $row): bool => (string)$row['metric_key'] === 'prepayments_accrued_income'
                ))[0] ?? [];
                $harness->assertSame(null, $originalPrepayments['filed_value'] ?? null);
                $harness->assertSame('', (string)($originalPrepayments['source_concept'] ?? ''));

                InterfaceDB::execute(
                    'DELETE FROM companies_house_document_facts
                     WHERE document_fk = :document_id AND concept_fk = :concept_id',
                    [
                        'document_id' => $fixture['revision_document_id'],
                        'concept_id' => $fixture['creditors_concept_id'],
                    ]
                );
                $partial = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame('unverifiable', (string)($partial['reconciliation_state'] ?? ''));
                $harness->assertSame(1, (int)($partial['missing_non_zero_count'] ?? 0));
                $harness->assertSame(false, !empty($partial['coverage_complete']));
                $harness->assertSame(true, !empty($partial['filing_outstanding']));
                $partialRows = [];
                foreach ((array)$partial['rows'] as $row) {
                    $partialRows[(string)$row['metric_key']] = $row;
                }
                $harness->assertSame('missing', (string)($partialRows['creditors_within_one_year']['status'] ?? ''));
                $harness->assertSame(true, !empty($partialRows['fixed_assets']['implicit_zero']));
                $harness->assertSame('pass', (string)($partialRows['fixed_assets']['status'] ?? ''));

                yearEndCompaniesHouseInsertNumericFact(
                    $fixture,
                    $fixture['revision_document_id'],
                    $fixture['creditor_context_id'],
                    $fixture['creditors_concept_id'],
                    50.00,
                    6,
                    'ix_sign+presentation_parentheses'
                );
                yearEndCompaniesHouseInsertNumericFact(
                    $fixture,
                    $fixture['revision_document_id'],
                    $fixture['superseded_creditor_context_id'],
                    $fixture['creditors_concept_id'],
                    60.00,
                    7,
                    'ix_sign+presentation_parentheses'
                );
                $observation = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame(1, (int)($observation['revision_count'] ?? 0));
                $harness->assertSame($fixture['revision_document_id'], (int)($observation['filing']['id'] ?? 0));
                $harness->assertSame('verified', (string)($observation['reconciliation_state'] ?? ''));
                $harness->assertSame(true, !empty($observation['revision_reconciled']));
                $harness->assertSame(false, !empty($observation['filing_outstanding']));
                $harness->assertSame(0, (int)($observation['mismatch_count'] ?? -1));
                $harness->assertSame(0, (int)($observation['missing_non_zero_count'] ?? -1));
                $harness->assertSame(true, !empty($observation['coverage_complete']));
                $harness->assertSame(7, (int)($observation['comparable_count'] ?? 0));

                $rows = [];
                foreach ((array)$observation['rows'] as $row) {
                    $rows[(string)$row['metric_key']] = $row;
                }
                $harness->assertSame(200.0, (float)($rows['current_assets']['revised_filed_value'] ?? 0));
                $harness->assertSame(30.0, (float)($rows['prepayments_accrued_income']['revised_filed_value'] ?? 0));
                $harness->assertSame(
                    'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
                    (string)($rows['prepayments_accrued_income']['source_concept'] ?? '')
                );
                $harness->assertSame(50.0, (float)($rows['creditors_within_one_year']['revised_filed_value'] ?? 0));
                $harness->assertSame(-10.0, (float)($rows['net_current_assets_liabilities']['revised_filed_value'] ?? 0));
                $harness->assertSame(-20.0, (float)($rows['net_assets_liabilities']['revised_filed_value'] ?? 0));
                $harness->assertSame(-20.0, (float)($rows['equity_capital_reserves']['revised_filed_value'] ?? 0));
                $harness->assertSame(0.0, (float)($rows['fixed_assets']['revised_filed_value'] ?? 1));
                $harness->assertSame(true, !empty($rows['fixed_assets']['implicit_zero']));

                yearEndCompaniesHouseInsertNumericFact(
                    $fixture,
                    $fixture['revision_document_id'],
                    $fixture['creditor_context_id'],
                    $fixture['creditors_within_concept_id'],
                    -55.00,
                    15
                );
                $conflictingFacts = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame('unverifiable', (string)($conflictingFacts['reconciliation_state'] ?? ''));
                $harness->assertSame(true, !empty($conflictingFacts['filing_outstanding']));
                $harness->assertTrue(str_contains(
                    (string)($conflictingFacts['evidence_error'] ?? ''),
                    'conflicting current facts'
                ));
                InterfaceDB::execute(
                    'DELETE FROM companies_house_document_facts
                     WHERE document_fk = :document_id AND concept_fk = :concept_id',
                    [
                        'document_id' => $fixture['revision_document_id'],
                        'concept_id' => $fixture['creditors_within_concept_id'],
                    ]
                );

                InterfaceDB::execute(
                    'UPDATE companies_house_documents SET parse_status = :parse_status WHERE id = :id',
                    [
                        'parse_status' => 'parsed',
                        'id' => $fixture['revision_document_id'],
                    ]
                );
                $legacyParsed = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame('verified', (string)($legacyParsed['reconciliation_state'] ?? ''));
                $harness->assertSame(true, !empty($legacyParsed['revision_reconciled']));

                InterfaceDB::execute(
                    'DELETE FROM companies_house_document_facts WHERE document_fk = :document_id',
                    ['document_id' => $fixture['revision_document_id']]
                );
                $allZeroMetrics = [
                    'fixed_assets' => 0.0,
                    'current_assets' => 0.0,
                    'prepayments_accrued_income' => 0.0,
                    'creditors_within_one_year' => 0.0,
                    'creditors_after_more_than_one_year' => 0.0,
                    'net_current_assets_liabilities' => 0.0,
                    'total_assets_less_current_liabilities' => 0.0,
                    'net_assets_liabilities' => 0.0,
                    'equity_capital_reserves' => 0.0,
                    'reliable_closing_balance' => true,
                ];
                $emptyFacts = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $allZeroMetrics
                );
                $harness->assertSame(0, (int)($emptyFacts['extracted_fact_count'] ?? -1));
                $harness->assertSame('unverifiable', (string)($emptyFacts['reconciliation_state'] ?? ''));
                $harness->assertSame(false, !empty($emptyFacts['coverage_complete']));
                $harness->assertSame(true, !empty($emptyFacts['filing_outstanding']));

                yearEndCompaniesHouseInsertNumericFact(
                    $fixture,
                    $fixture['revision_document_id'],
                    $fixture['revision_context_id'],
                    $fixture['current_assets_concept_id'],
                    0.00,
                    4
                );
                $oneActualZero = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $allZeroMetrics
                );
                $harness->assertSame(1, (int)($oneActualZero['extracted_fact_count'] ?? 0));
                $harness->assertSame('verified', (string)($oneActualZero['reconciliation_state'] ?? ''));
                $harness->assertSame(true, !empty($oneActualZero['coverage_complete']));
                $harness->assertSame(false, !empty($oneActualZero['filing_outstanding']));

                yearEndCompaniesHouseRevisionSeedFailedLatest($fixture);
                $failed = $service->fetchRevisedObservation(
                    $fixture['company_id'],
                    $fixture['accounting_period_id'],
                    $period,
                    $appMetrics
                );
                $harness->assertSame(2, (int)($failed['revision_count'] ?? 0));
                $harness->assertSame($fixture['failed_revision_document_id'], (int)($failed['filing']['id'] ?? 0));
                $harness->assertSame('unverifiable', (string)($failed['reconciliation_state'] ?? ''));
                $harness->assertSame('Latest revised filing parse failed.', (string)($failed['parse_error'] ?? ''));
                $harness->assertSame(true, !empty($failed['filing_outstanding']));
            } finally {
                InterfaceDB::rollBack();
            }
        }
    );
});

/** @return array<string,int|string> */
function yearEndCompaniesHouseRevisionFixture(): array
{
    $seed = random_int(700000000, 799999000);
    return [
        'company_id' => $seed,
        'accounting_period_id' => $seed + 1,
        'original_document_id' => $seed + 2,
        'revision_document_id' => $seed + 3,
        'failed_revision_document_id' => $seed + 4,
        'original_context_id' => $seed + 10,
        'revision_context_id' => $seed + 11,
        'superseded_context_id' => $seed + 12,
        'creditor_context_id' => $seed + 13,
        'superseded_creditor_context_id' => $seed + 14,
        'period_concept_id' => $seed + 20,
        'current_assets_concept_id' => $seed + 21,
        'creditors_concept_id' => $seed + 22,
        'revised_prepayments_concept_id' => $seed + 23,
        'creditors_within_concept_id' => $seed + 24,
        'net_current_assets_concept_id' => $seed + 25,
        'net_assets_concept_id' => $seed + 26,
        'equity_concept_id' => $seed + 27,
        'company_number' => 'RV' . substr((string)$seed, -6),
    ];
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseRevisionSeed(array $fixture): void
{
    InterfaceDB::execute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $fixture['company_id'],
            'company_name' => 'Revised Observation Fixture Limited',
            'company_number' => $fixture['company_number'],
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $fixture['accounting_period_id'],
            'company_id' => $fixture['company_id'],
            'label' => 'Revised observation fixture',
            'period_start' => '2023-10-01',
            'period_end' => '2024-09-30',
        ]
    );

    yearEndCompaniesHouseInsertDocument(
        $fixture,
        (int)$fixture['original_document_id'],
        '2025-06-01',
        'AA',
        'parsed_latest_year',
        '',
        '2025-06-01T09:00:00.000000000Z'
    );

    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['period_concept_id'],
            'concept_name' => 'fixture:EndDateForPeriodCoveredByReport' . $fixture['period_concept_id'],
            'short_name' => 'EndDateForPeriodCoveredByReport',
            'friendly_label' => 'Period end',
            'value_type' => 'date',
        ]
    );
    foreach ([
        [$fixture['net_current_assets_concept_id'], 'NetCurrentAssetsLiabilities'],
        [$fixture['net_assets_concept_id'], 'NetAssetsLiabilities'],
        [$fixture['equity_concept_id'], 'Equity'],
    ] as [$conceptId, $shortName]) {
        InterfaceDB::execute(
            'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
             VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
            [
                'id' => $conceptId,
                'concept_name' => 'fixture:' . $shortName . $conceptId,
                'short_name' => $shortName,
                'friendly_label' => $shortName,
                'value_type' => 'monetary',
            ]
        );
    }
    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['creditors_within_concept_id'],
            'concept_name' => 'fixture:CreditorsDueWithinOneYear' . $fixture['creditors_within_concept_id'],
            'short_name' => 'CreditorsDueWithinOneYear',
            'friendly_label' => 'Creditors within one year',
            'value_type' => 'monetary',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['current_assets_concept_id'],
            'concept_name' => 'fixture:CurrentAssets' . $fixture['current_assets_concept_id'],
            'short_name' => 'CurrentAssets',
            'friendly_label' => 'Current assets',
            'value_type' => 'monetary',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['revised_prepayments_concept_id'],
            'concept_name' => 'fixture:PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal'
                . $fixture['revised_prepayments_concept_id'],
            'short_name' => 'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal',
            'friendly_label' => 'Revised prepayments',
            'value_type' => 'monetary',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['creditors_concept_id'],
            'concept_name' => 'fixture:Creditors' . $fixture['creditors_concept_id'],
            'short_name' => 'Creditors',
            'friendly_label' => 'Creditors',
            'value_type' => 'monetary',
        ]
    );

    yearEndCompaniesHouseInsertContext($fixture['original_context_id'], $fixture['original_document_id'], 'original-current', null);

    yearEndCompaniesHouseInsertPeriodFact($fixture, $fixture['original_document_id'], $fixture['original_context_id'], 1);
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['original_document_id'], $fixture['original_context_id'], $fixture['current_assets_concept_id'], 100.00, 3);
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseRevisionSeedParsedRevision(array $fixture): void
{
    yearEndCompaniesHouseInsertDocument(
        $fixture,
        (int)$fixture['revision_document_id'],
        '2026-08-10',
        'AAMD',
        'parsed_latest_year',
        '',
        '2026-08-10T10:00:00.000000000Z'
    );
    yearEndCompaniesHouseInsertContext($fixture['revision_context_id'], $fixture['revision_document_id'], 'revision-current', null);
    yearEndCompaniesHouseInsertContext(
        $fixture['superseded_context_id'],
        $fixture['revision_document_id'],
        'current_period_end_superseded',
        '[{"dimension":"bus:OriginalRevisedDataDimension","member":"bus:Superseded"}]'
    );
    yearEndCompaniesHouseInsertContext(
        $fixture['creditor_context_id'],
        $fixture['revision_document_id'],
        'current-period-creditors-within',
        '[{"dimension":"core:MaturitiesOrExpirationPeriodsDimension","member":"core:WithinOneYear"}]'
    );
    yearEndCompaniesHouseInsertContext(
        $fixture['superseded_creditor_context_id'],
        $fixture['revision_document_id'],
        'current-period-creditors-within-superseded',
        '[{"dimension":"bus:OriginalRevisedDataDimension","member":"bus:Superseded"},{"dimension":"core:MaturitiesOrExpirationPeriodsDimension","member":"core:WithinOneYear"}]'
    );

    yearEndCompaniesHouseInsertPeriodFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], 2);
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], $fixture['current_assets_concept_id'], 200.00, 4);
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['superseded_context_id'], $fixture['current_assets_concept_id'], 100.00, 5);
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['creditor_context_id'], $fixture['creditors_concept_id'], 50.00, 6, 'ix_sign+presentation_parentheses');
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['superseded_creditor_context_id'], $fixture['creditors_concept_id'], 60.00, 7, 'ix_sign+presentation_parentheses');
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], $fixture['revised_prepayments_concept_id'], 30.00, 9);
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], $fixture['net_current_assets_concept_id'], -10.00, 16, 'IX_SIGN');
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], $fixture['net_assets_concept_id'], 20.00, 17, 'ix_sign+inline_parentheses');
    yearEndCompaniesHouseInsertNumericFact($fixture, $fixture['revision_document_id'], $fixture['revision_context_id'], $fixture['equity_concept_id'], 20.00, 18, 'ix_sign+presentation_parentheses');
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseRevisionSeedFailedLatest(array $fixture): void
{
    yearEndCompaniesHouseInsertDocument(
        $fixture,
        (int)$fixture['failed_revision_document_id'],
        '2026-08-11',
        'AAMD',
        'parse_failed',
        'Latest revised filing parse failed.',
        '2026-08-11T10:00:00.000000000Z'
    );
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseInsertDocument(
    array $fixture,
    int $documentId,
    string $filingDate,
    string $filingType,
    string $parseStatus,
    string $parseError = '',
    string $metadataCreatedAt = ''
): void {
    $metadataCreatedAt = $metadataCreatedAt !== ''
        ? $metadataCreatedAt
        : $filingDate . 'T00:00:00.000000000Z';
    $rawMetadata = json_encode([
        'created_at' => $metadataCreatedAt,
        'significant_date' => '2024-09-30T00:00:00Z',
    ], JSON_UNESCAPED_SLASHES);
    InterfaceDB::execute(
        'INSERT INTO companies_house_documents (
            id, company_id, company_number, transaction_id, filing_date, filing_type,
            filing_category, filing_description, document_id, metadata_url,
            classification, significant_date, raw_metadata_json, parse_status, parse_error
         ) VALUES (
            :id, :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
            :filing_category, :filing_description, :document_id, :metadata_url,
            :classification, :significant_date, :raw_metadata_json, :parse_status, :parse_error
         )',
        [
            'id' => $documentId,
            'company_id' => $fixture['company_id'],
            'company_number' => $fixture['company_number'],
            'transaction_id' => 'transaction-' . $documentId,
            'filing_date' => $filingDate,
            'filing_type' => $filingType,
            'filing_category' => 'accounts',
            'filing_description' => $filingType === 'AAMD'
                ? 'accounts-amended-with-accounts-type-micro-entity'
                : 'accounts-with-accounts-type-micro-entity',
            'document_id' => 'document-' . $documentId,
            'metadata_url' => 'https://example.invalid/document/' . $documentId,
            'classification' => 'digital_xhtml',
            'significant_date' => '2024-09-30',
            'raw_metadata_json' => $rawMetadata,
            'parse_status' => $parseStatus,
            'parse_error' => $parseError !== '' ? $parseError : null,
        ]
    );
}

function yearEndCompaniesHouseInsertContext(
    int|string $contextId,
    int|string $documentId,
    string $contextRef,
    ?string $dimensionJson
): void {
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_contexts (
            id, document_fk, context_ref, instant_date, is_latest_year_context, dimension_json
         ) VALUES (:id, :document_fk, :context_ref, :instant_date, 1, :dimension_json)',
        [
            'id' => $contextId,
            'document_fk' => $documentId,
            'context_ref' => $contextRef,
            'instant_date' => '2024-09-30',
            'dimension_json' => $dimensionJson,
        ]
    );
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseInsertPeriodFact(
    array $fixture,
    int|string $documentId,
    int|string $contextId,
    int $offset
): void {
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_facts (
            id, document_fk, context_fk, concept_fk, fact_name, raw_value,
            normalised_date, is_numeric, is_latest_year_fact
         ) VALUES (
            :id, :document_fk, :context_fk, :concept_fk, :fact_name, :raw_value,
            :normalised_date, 0, 1
         )',
        [
            'id' => (int)$fixture['company_id'] + 100 + $offset,
            'document_fk' => $documentId,
            'context_fk' => $contextId,
            'concept_fk' => $fixture['period_concept_id'],
            'fact_name' => 'Period end',
            'raw_value' => '2024-09-30',
            'normalised_date' => '2024-09-30',
        ]
    );
}

/** @param array<string,int|string> $fixture */
function yearEndCompaniesHouseInsertNumericFact(
    array $fixture,
    int|string $documentId,
    int|string $contextId,
    int|string $conceptId,
    float $value,
    int $offset,
    ?string $signHint = null
): void {
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_facts (
            id, document_fk, context_fk, concept_fk, fact_name, raw_value,
            normalised_numeric, sign_hint, is_numeric, is_latest_year_fact
         ) VALUES (
            :id, :document_fk, :context_fk, :concept_fk, :fact_name, :raw_value,
            :normalised_numeric, :sign_hint, 1, 1
         )',
        [
            'id' => (int)$fixture['company_id'] + 100 + $offset,
            'document_fk' => $documentId,
            'context_fk' => $contextId,
            'concept_fk' => $conceptId,
            'fact_name' => 'Fixture fact',
            'raw_value' => number_format($value, 2, '.', ''),
            'normalised_numeric' => $value,
            'sign_hint' => $signHint,
        ]
    );
}
