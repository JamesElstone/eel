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
    \eel_accounts\Service\TrialBalanceComparisonService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\TrialBalanceComparisonService $service
    ): void {
        $harness->check(
            $service::class,
            'keeps the historical AA when a later exact-period AAMD is stored',
            static function () use ($harness, $service): void {
                foreach ([
                    'companies',
                    'accounting_periods',
                    'companies_house_documents',
                    'companies_house_document_contexts',
                    'companies_house_document_facts',
                    'companies_house_taxonomy_concepts',
                ] as $table) {
                    if (!\InterfaceDB::tableExists($table)) {
                        $harness->skip($table . ' table is not available.');
                    }
                }

                \InterfaceDB::beginTransaction();
                try {
                    $fixture = trialBalanceComparisonSeedOriginal();
                    $ledger = [
                        'current_assets' => 100.0,
                        'net_assets_liabilities' => -40.0,
                    ];
                    $before = $service->fetchComparison(
                        $fixture['company_id'],
                        $fixture['accounting_period_id'],
                        $ledger
                    );
                    $harness->assertSame(true, !empty($before['available']));
                    $harness->assertSame($fixture['original_document_id'], (int)($before['filing']['id'] ?? 0));
                    $harness->assertSame('AA', (string)($before['filing']['filing_type'] ?? ''));

                    trialBalanceComparisonSeedRevision($fixture);
                    $after = $service->fetchComparison(
                        $fixture['company_id'],
                        $fixture['accounting_period_id'],
                        $ledger
                    );
                    $harness->assertSame($fixture['original_document_id'], (int)($after['filing']['id'] ?? 0));
                    $harness->assertSame('AA', (string)($after['filing']['filing_type'] ?? ''));
                    $harness->assertSame($before['rows'], $after['rows']);

                    $nearestFallback = $service->fetchComparison(
                        $fixture['company_id'],
                        $fixture['nearby_accounting_period_id'],
                        $ledger
                    );
                    $harness->assertSame(
                        $fixture['original_document_id'],
                        (int)($nearestFallback['filing']['id'] ?? 0)
                    );
                    $harness->assertSame('AA', (string)($nearestFallback['filing']['filing_type'] ?? ''));
                    $harness->assertSame($before['rows'], $nearestFallback['rows']);
                } finally {
                    \InterfaceDB::rollBack();
                }
            }
        );
    }
);

/** @return array<string,int|string> */
function trialBalanceComparisonSeedOriginal(): array
{
    $seed = random_int(820000000, 829999000);
    $fixture = [
        'company_id' => $seed,
        'accounting_period_id' => $seed + 1,
        'nearby_accounting_period_id' => $seed + 2,
        'original_document_id' => $seed + 10,
        'revision_document_id' => $seed + 11,
        'original_context_id' => $seed + 20,
        'revision_context_id' => $seed + 21,
        'superseded_context_id' => $seed + 22,
        'period_concept_id' => $seed + 30,
        'current_assets_concept_id' => $seed + 31,
        'net_assets_concept_id' => $seed + 32,
        'revision_marker_concept_id' => $seed + 33,
        'company_number' => 'TB' . substr((string)$seed, -6),
    ];

    \InterfaceDB::execute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :company_name, :company_number, 1)',
        [
            'id' => $fixture['company_id'],
            'company_name' => 'Trial Balance Comparison Fixture Limited',
            'company_number' => $fixture['company_number'],
        ]
    );
    foreach ([
        [$fixture['accounting_period_id'], 'Exact period', '2023-10-01', '2024-09-30'],
        [$fixture['nearby_accounting_period_id'], 'Nearest fallback', '2023-09-30', '2024-09-29'],
    ] as [$periodId, $label, $start, $end]) {
        \InterfaceDB::execute(
            'INSERT INTO accounting_periods (id, company_id, label, period_start, period_end)
             VALUES (:id, :company_id, :label, :period_start, :period_end)',
            [
                'id' => $periodId,
                'company_id' => $fixture['company_id'],
                'label' => $label,
                'period_start' => $start,
                'period_end' => $end,
            ]
        );
    }
    foreach ([
        [$fixture['period_concept_id'], 'EndDateForPeriodCoveredByReport', 'date'],
        [$fixture['current_assets_concept_id'], 'CurrentAssets', 'monetary'],
        [$fixture['net_assets_concept_id'], 'NetAssetsLiabilities', 'monetary'],
        [
            $fixture['revision_marker_concept_id'],
            'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse',
            'boolean',
        ],
    ] as [$conceptId, $shortName, $valueType]) {
        \InterfaceDB::execute(
            'INSERT INTO companies_house_taxonomy_concepts (
                id, concept_name, short_name, friendly_label, value_type
             ) VALUES (:id, :concept_name, :short_name, :friendly_label, :value_type)',
            [
                'id' => $conceptId,
                'concept_name' => 'fixture:' . $shortName . $seed,
                'short_name' => $shortName,
                'friendly_label' => $shortName,
                'value_type' => $valueType,
            ]
        );
    }
    trialBalanceComparisonInsertDocument(
        $fixture,
        (int)$fixture['original_document_id'],
        '2025-05-01',
        'AA',
        'accounts-with-accounts-type-micro-entity'
    );
    trialBalanceComparisonInsertContext(
        (int)$fixture['original_context_id'],
        (int)$fixture['original_document_id'],
        'aa-current',
        null
    );
    trialBalanceComparisonInsertFact(
        $fixture,
        (int)$fixture['original_document_id'],
        (int)$fixture['original_context_id'],
        (int)$fixture['period_concept_id'],
        1,
        null,
        null,
        '2024-09-30'
    );
    trialBalanceComparisonInsertFact(
        $fixture,
        (int)$fixture['original_document_id'],
        (int)$fixture['original_context_id'],
        (int)$fixture['current_assets_concept_id'],
        2,
        100.0
    );
    trialBalanceComparisonInsertFact(
        $fixture,
        (int)$fixture['original_document_id'],
        (int)$fixture['original_context_id'],
        (int)$fixture['net_assets_concept_id'],
        3,
        -40.0
    );

    return $fixture;
}

/** @param array<string,int|string> $fixture */
function trialBalanceComparisonSeedRevision(array $fixture): void
{
    trialBalanceComparisonInsertDocument(
        $fixture,
        (int)$fixture['revision_document_id'],
        '2026-08-10',
        'AAMD',
        'accounts-amended-with-accounts-type-micro-entity'
    );
    trialBalanceComparisonInsertContext(
        (int)$fixture['revision_context_id'],
        (int)$fixture['revision_document_id'],
        'aamd-current',
        '[{"dimension":"bus:OriginalRevisedDataDimension","member":"bus:Revised"}]'
    );
    trialBalanceComparisonInsertContext(
        (int)$fixture['superseded_context_id'],
        (int)$fixture['revision_document_id'],
        'aamd-superseded',
        '[{"dimension":"bus:OriginalRevisedDataDimension","member":"bus:Superseded"}]'
    );
    trialBalanceComparisonInsertFact(
        $fixture,
        (int)$fixture['revision_document_id'],
        (int)$fixture['revision_context_id'],
        (int)$fixture['period_concept_id'],
        10,
        null,
        null,
        '2024-09-30'
    );
    trialBalanceComparisonInsertFact(
        $fixture,
        (int)$fixture['revision_document_id'],
        (int)$fixture['revision_context_id'],
        (int)$fixture['revision_marker_concept_id'],
        11,
        null,
        null,
        null,
        'true'
    );
    foreach ([
        [$fixture['revision_context_id'], $fixture['current_assets_concept_id'], 12, 999.0, null],
        [
            $fixture['revision_context_id'],
            $fixture['net_assets_concept_id'],
            13,
            999.0,
            'ix_sign+presentation_parentheses',
        ],
        [$fixture['superseded_context_id'], $fixture['current_assets_concept_id'], 14, 100.0, null],
        [
            $fixture['superseded_context_id'],
            $fixture['net_assets_concept_id'],
            15,
            40.0,
            'ix_sign+presentation_parentheses',
        ],
    ] as [$contextId, $conceptId, $offset, $value, $signHint]) {
        trialBalanceComparisonInsertFact(
            $fixture,
            (int)$fixture['revision_document_id'],
            (int)$contextId,
            (int)$conceptId,
            (int)$offset,
            (float)$value,
            (string)($signHint ?? '') ?: null
        );
    }
}

/** @param array<string,int|string> $fixture */
function trialBalanceComparisonInsertDocument(
    array $fixture,
    int $documentId,
    string $filingDate,
    string $filingType,
    string $description
): void {
    \InterfaceDB::execute(
        'INSERT INTO companies_house_documents (
            id, company_id, company_number, transaction_id, filing_date, filing_type,
            filing_category, filing_description, document_id, metadata_url,
            classification, significant_date, raw_metadata_json, parse_status
         ) VALUES (
            :id, :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
            :filing_category, :filing_description, :document_id, :metadata_url,
            :classification, :significant_date, :raw_metadata_json, :parse_status
         )',
        [
            'id' => $documentId,
            'company_id' => $fixture['company_id'],
            'company_number' => $fixture['company_number'],
            'transaction_id' => 'trial-balance-' . $documentId,
            'filing_date' => $filingDate,
            'filing_type' => $filingType,
            'filing_category' => 'accounts',
            'filing_description' => $description,
            'document_id' => 'trial-balance-document-' . $documentId,
            'metadata_url' => 'https://example.invalid/trial-balance/' . $documentId,
            'classification' => 'digital_xhtml',
            'significant_date' => '2024-09-30',
            'raw_metadata_json' => json_encode(['created_at' => $filingDate . 'T12:00:00Z']),
            'parse_status' => 'parsed_latest_year',
        ]
    );
}

function trialBalanceComparisonInsertContext(
    int $contextId,
    int $documentId,
    string $contextRef,
    ?string $dimensions
): void {
    \InterfaceDB::execute(
        'INSERT INTO companies_house_document_contexts (
            id, document_fk, context_ref, instant_date, is_latest_year_context, dimension_json
         ) VALUES (:id, :document_fk, :context_ref, :instant_date, 1, :dimension_json)',
        [
            'id' => $contextId,
            'document_fk' => $documentId,
            'context_ref' => $contextRef,
            'instant_date' => '2024-09-30',
            'dimension_json' => $dimensions,
        ]
    );
}

/** @param array<string,int|string> $fixture */
function trialBalanceComparisonInsertFact(
    array $fixture,
    int $documentId,
    int $contextId,
    int $conceptId,
    int $offset,
    ?float $numeric = null,
    ?string $signHint = null,
    ?string $date = null,
    ?string $text = null
): void {
    \InterfaceDB::execute(
        'INSERT INTO companies_house_document_facts (
            id, document_fk, context_fk, concept_fk, fact_name, raw_value,
            normalised_numeric, normalised_text, normalised_date, sign_hint,
            is_numeric, is_latest_year_fact
         ) VALUES (
            :id, :document_fk, :context_fk, :concept_fk, :fact_name, :raw_value,
            :normalised_numeric, :normalised_text, :normalised_date, :sign_hint,
            :is_numeric, 1
         )',
        [
            'id' => (int)$fixture['company_id'] + 100 + $offset,
            'document_fk' => $documentId,
            'context_fk' => $contextId,
            'concept_fk' => $conceptId,
            'fact_name' => 'Trial balance comparison fixture fact',
            'raw_value' => $date ?? $text ?? ($numeric === null ? '' : (string)abs($numeric)),
            'normalised_numeric' => $numeric,
            'normalised_text' => $text,
            'normalised_date' => $date,
            'sign_hint' => $signHint,
            'is_numeric' => $numeric !== null ? 1 : 0,
        ]
    );
}
