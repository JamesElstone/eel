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
$harness->run(
    \eel_accounts\Service\CompaniesHouseComparisonReviewService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHouseComparisonReviewService $service
    ): void {
        $harness->check(
            \eel_accounts\Service\CompaniesHouseComparisonReviewService::class,
            'returns comparison, acknowledgement freshness, access, and mismatch count',
            static function () use ($harness, $service): void {
                companiesHouseComparisonReviewRequireTables($harness);
                $fixture = companiesHouseComparisonReviewFixture();

                InterfaceDB::beginTransaction();
                try {
                    companiesHouseComparisonReviewSeed($fixture);

                    $initial = $service->fetchContext($fixture['company_id'], $fixture['accounting_period_id']);
                    $harness->assertSame(true, !empty($initial['comparison']['available']));
                    $harness->assertSame(null, $initial['acknowledgement']);
                    $harness->assertSame(false, !empty($initial['access']['is_locked']));
                    $harness->assertSame(1, (int)$initial['mismatch_count']);

                    $acknowledgements = new \eel_accounts\Service\YearEndAcknowledgementService();
                    $basis = $acknowledgements->buildBasis(
                        'companies_house_mismatch_acknowledgement',
                        (array)$initial['comparison']
                    );
                    $saved = $acknowledgements->save(
                        $fixture['company_id'],
                        $fixture['accounting_period_id'],
                        'companies_house_mismatch_acknowledgement',
                        $basis,
                        'comparison_review_test',
                        'Reviewed fixture.'
                    );
                    $harness->assertSame(true, !empty($saved['success']));

                    $current = $service->fetchContext($fixture['company_id'], $fixture['accounting_period_id']);
                    $harness->assertSame('current', (string)($current['acknowledgement']['state'] ?? ''));
                    $harness->assertSame(true, !empty($current['acknowledgement']['current']));

                    InterfaceDB::execute(
                        'UPDATE companies_house_document_facts
                         SET normalised_numeric = :normalised_numeric,
                             raw_value = :raw_value
                         WHERE id = :id',
                        [
                            'normalised_numeric' => 300.00,
                            'raw_value' => '300.00',
                            'id' => $fixture['metric_fact_id'],
                        ]
                    );
                    $stale = $service->fetchContext($fixture['company_id'], $fixture['accounting_period_id']);
                    $harness->assertSame('stale', (string)($stale['acknowledgement']['state'] ?? ''));
                    $harness->assertSame(false, !empty($stale['acknowledgement']['current']));

                    InterfaceDB::execute(
                        'INSERT INTO year_end_reviews (
                            company_id, accounting_period_id, is_locked, locked_at, locked_by
                         ) VALUES (
                            :company_id, :accounting_period_id, 1, CURRENT_TIMESTAMP, :locked_by
                         )',
                        [
                            'company_id' => $fixture['company_id'],
                            'accounting_period_id' => $fixture['accounting_period_id'],
                            'locked_by' => 'comparison_review_test',
                        ]
                    );
                    $locked = $service->fetchContext($fixture['company_id'], $fixture['accounting_period_id']);
                    $harness->assertSame(true, !empty($locked['access']['is_locked']));
                    $harness->assertSame('current', (string)($locked['acknowledgement']['state'] ?? ''));
                    $harness->assertSame(true, !empty($locked['acknowledgement']['current']));

                    InterfaceDB::execute(
                        'UPDATE year_end_reviews SET is_locked = 0 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                        [
                            'company_id' => $fixture['company_id'],
                            'accounting_period_id' => $fixture['accounting_period_id'],
                        ]
                    );
                    InterfaceDB::execute(
                        'DELETE FROM companies_house_documents WHERE id = :id',
                        ['id' => $fixture['document_id']]
                    );
                    $unavailable = $service->fetchContext($fixture['company_id'], $fixture['accounting_period_id']);
                    $harness->assertSame(false, !empty($unavailable['comparison']['available']));
                    $harness->assertSame('unverifiable', (string)($unavailable['acknowledgement']['state'] ?? ''));
                    $harness->assertSame(false, !empty($unavailable['acknowledgement']['current']));
                    $harness->assertSame(0, (int)$unavailable['mismatch_count']);
                } finally {
                    InterfaceDB::rollBack();
                }
            }
        );
    }
);

function companiesHouseComparisonReviewRequireTables(GeneratedServiceClassTestHarness $harness): void
{
    foreach ([
        'companies',
        'accounting_periods',
        'companies_house_documents',
        'companies_house_document_contexts',
        'companies_house_document_facts',
        'companies_house_taxonomy_concepts',
        'year_end_review_acknowledgements',
        'year_end_reviews',
    ] as $table) {
        if (!InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

/** @return array<string, int|string> */
function companiesHouseComparisonReviewFixture(): array
{
    $seed = random_int(500000000, 599999999);

    return [
        'company_id' => $seed,
        'accounting_period_id' => $seed + 1,
        'document_id' => $seed + 2,
        'context_id' => $seed + 3,
        'period_concept_id' => $seed + 4,
        'metric_concept_id' => $seed + 5,
        'period_fact_id' => $seed + 6,
        'metric_fact_id' => $seed + 7,
        'company_number' => 'CR' . substr((string)$seed, -6),
    ];
}

/** @param array<string, int|string> $fixture */
function companiesHouseComparisonReviewSeed(array $fixture): void
{
    InterfaceDB::execute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $fixture['company_id'],
            'company_name' => 'Comparison Review Fixture Limited',
            'company_number' => $fixture['company_number'],
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
         VALUES (:id, :company_id, :label, :period_start, :period_end)',
        [
            'id' => $fixture['accounting_period_id'],
            'company_id' => $fixture['company_id'],
            'label' => 'Comparison review fixture',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_documents (
            id, company_id, company_number, transaction_id, filing_date, filing_type,
            document_id, metadata_url, classification, parse_status
         ) VALUES (
            :id, :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
            :document_id, :metadata_url, :classification, :parse_status
         )',
        [
            'id' => $fixture['document_id'],
            'company_id' => $fixture['company_id'],
            'company_number' => $fixture['company_number'],
            'transaction_id' => 'txn-' . $fixture['document_id'],
            'filing_date' => '2026-03-01',
            'filing_type' => 'AA',
            'document_id' => 'document-' . $fixture['document_id'],
            'metadata_url' => 'https://example.invalid/metadata',
            'classification' => 'accounts',
            'parse_status' => 'parsed',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_contexts (
            id, document_fk, context_ref, period_start, period_end, is_latest_year_context
         ) VALUES (
            :id, :document_fk, :context_ref, :period_start, :period_end, 1
         )',
        [
            'id' => $fixture['context_id'],
            'document_fk' => $fixture['document_id'],
            'context_ref' => 'comparison-review-context',
            'period_start' => '2025-01-01',
            'period_end' => '2025-12-31',
        ]
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
    InterfaceDB::execute(
        'INSERT INTO companies_house_taxonomy_concepts (id, concept_name, short_name, friendly_label, value_type)
         VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
        [
            'id' => $fixture['metric_concept_id'],
            'concept_name' => 'fixture:CurrentAssets' . $fixture['metric_concept_id'],
            'short_name' => 'CurrentAssets',
            'friendly_label' => 'Current assets',
            'value_type' => 'monetary',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_facts (
            id, document_fk, context_fk, concept_fk, fact_name, raw_value,
            normalised_date, is_numeric, is_latest_year_fact
         ) VALUES (
            :id, :document_fk, :context_fk, :concept_fk, :fact_name, :raw_value,
            :normalised_date, 0, 1
         )',
        [
            'id' => $fixture['period_fact_id'],
            'document_fk' => $fixture['document_id'],
            'context_fk' => $fixture['context_id'],
            'concept_fk' => $fixture['period_concept_id'],
            'fact_name' => 'Period end',
            'raw_value' => '2025-12-31',
            'normalised_date' => '2025-12-31',
        ]
    );
    InterfaceDB::execute(
        'INSERT INTO companies_house_document_facts (
            id, document_fk, context_fk, concept_fk, fact_name, raw_value,
            normalised_numeric, is_numeric, is_latest_year_fact
         ) VALUES (
            :id, :document_fk, :context_fk, :concept_fk, :fact_name, :raw_value,
            :normalised_numeric, 1, 1
         )',
        [
            'id' => $fixture['metric_fact_id'],
            'document_fk' => $fixture['document_id'],
            'context_fk' => $fixture['context_id'],
            'concept_fk' => $fixture['metric_concept_id'],
            'fact_name' => 'Current assets',
            'raw_value' => '275.00',
            'normalised_numeric' => 275.00,
        ]
    );
}
