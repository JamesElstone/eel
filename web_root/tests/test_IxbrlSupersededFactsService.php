<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\IxbrlSupersededFactsService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\IxbrlSupersededFactsService $service
    ): void {
        $harness->check($service::class, 'maps the latest AAMD current facts with authoritative inline signs', static function () use ($harness, $service): void {
            ixbrlSupersededFactsRequireTables($harness);
            \InterfaceDB::beginTransaction();
            try {
                $fixture = ixbrlSupersededFactsSeed();
                $facts = $service->facts(
                    (int)$fixture['company_id'],
                    (int)$fixture['document_id'],
                    '2023-09-30'
                );
                $indexed = [];
                foreach ($facts as $fact) {
                    $indexed[(string)$fact['concept'] . '|' . (string)$fact['context_ref']] = $fact;
                    $harness->assertSame(
                        (int)$fixture['document_id'],
                        (int)$fact['source_document_id']
                    );
                }
                $harness->assertSame(
                    0.0,
                    (float)$indexed['core:FixedAssets|current_period_end_superseded']['value']
                );
                $harness->assertSame(
                    275.0,
                    (float)$indexed['core:CurrentAssets|current_period_end_superseded']['value']
                );
                $harness->assertSame(
                    64.0,
                    (float)$indexed[
                        'core:Creditors|current_period_end_superseded_creditors_within_one_year'
                    ]['value']
                );
                $harness->assertSame(
                    70.0,
                    (float)$indexed[
                        'core:Creditors|current_period_end_superseded_creditors_after_one_year'
                    ]['value']
                );
                $harness->assertSame(
                    -25.0,
                    (float)$indexed['core:NetCurrentAssetsLiabilities|current_period_end_superseded']['value']
                );
                $harness->assertSame(
                    -211.0,
                    (float)$indexed['core:NetAssetsLiabilities|current_period_end_superseded']['value']
                );
                $harness->assertSame(
                    -211.0,
                    (float)$indexed['core:Equity|current_period_end_superseded']['value']
                );
                $harness->assertSame(
                    'pure',
                    (string)$indexed[
                        'core:AverageNumberEmployeesDuringPeriod|current_period_duration_superseded'
                    ]['unit_ref']
                );
            } finally {
                \InterfaceDB::rollBack();
            }
        });

        $harness->check($service::class, 'rejects a filing belonging to a different company', static function () use ($harness, $service): void {
            $thrown = false;
            try {
                $service->facts(1, 90, '2023-09-30');
            } catch (RuntimeException) {
                $thrown = true;
            }
            $harness->assertTrue($thrown);
        });
    }
);

function ixbrlSupersededFactsRequireTables(GeneratedServiceClassTestHarness $harness): void
{
    foreach ([
        'companies',
        'companies_house_documents',
        'companies_house_document_contexts',
        'companies_house_document_facts',
        'companies_house_taxonomy_concepts',
    ] as $table) {
        if (!\InterfaceDB::tableExists($table)) {
            $harness->skip($table . ' table is not available.');
        }
    }
}

/** @return array{company_id:int,document_id:int} */
function ixbrlSupersededFactsSeed(): array
{
    $seed = random_int(610000000, 619000000);
    $companyId = $seed;
    $documentId = $seed + 1;
    $instantContextId = $seed + 2;
    $creditorContextId = $seed + 3;
    $durationContextId = $seed + 4;
    $supersededInstantContextId = $seed + 5;
    $creditorAfterContextId = $seed + 6;
    $supersededCreditorWithinContextId = $seed + 7;
    $supersededCreditorAfterContextId = $seed + 8;
    $companyNumber = 'SX' . substr((string)$seed, -6);

    \InterfaceDB::execute(
        'INSERT INTO companies (id, company_name, company_number, is_active)
         VALUES (:id, :name, :number, 1)',
        ['id' => $companyId, 'name' => 'Superseded Facts Fixture Limited', 'number' => $companyNumber]
    );
    \InterfaceDB::execute(
        'INSERT INTO companies_house_documents (
            id, company_id, company_number, transaction_id, filing_date, filing_type,
            document_id, metadata_url, classification, parse_status
         ) VALUES (
            :id, :company_id, :company_number, :transaction_id, :filing_date, :filing_type,
            :document_id, :metadata_url, :classification, :parse_status
         )',
        [
            'id' => $documentId,
            'company_id' => $companyId,
            'company_number' => $companyNumber,
            'transaction_id' => 'superseded-txn-' . $seed,
            'filing_date' => '2025-05-29',
            'filing_type' => 'AAMD',
            'document_id' => 'superseded-document-' . $seed,
            'metadata_url' => 'https://example.invalid/superseded',
            'classification' => 'accounts',
            'parse_status' => 'parsed_latest_year',
        ]
    );
    foreach ([
        [$instantContextId, 'original-instant', null, null, '2023-09-30', null],
        [
            $creditorContextId,
            'original-creditors-within',
            null,
            null,
            '2023-09-30',
            json_encode([[
                'dimension' => 'uk-core:MaturitiesOrExpirationPeriodsDimension',
                'member' => 'uk-core:WithinOneYear',
            ]], JSON_THROW_ON_ERROR),
        ],
        [$durationContextId, 'original-duration', '2022-09-05', '2023-09-30', null, null],
        [
            $supersededInstantContextId,
            'aaa-old-instant',
            null,
            null,
            '2023-09-30',
            json_encode([[
                'dimension' => 'bus:OriginalRevisedDataDimension',
                'member' => 'bus:Superseded',
            ]], JSON_THROW_ON_ERROR),
        ],
        [
            $creditorAfterContextId,
            'revision-current-creditors-after',
            null,
            null,
            '2023-09-30',
            json_encode([
                [
                    'dimension' => 'uk-core:MaturitiesOrExpirationPeriodsDimension',
                    'member' => 'uk-core:AfterMoreThanOneYear',
                ],
                [
                    'dimension' => 'bus:OriginalRevisedDataDimension',
                    'member' => 'bus:Revised',
                ],
            ], JSON_THROW_ON_ERROR),
        ],
        [
            $supersededCreditorWithinContextId,
            'aaa-old-creditors-within',
            null,
            null,
            '2023-09-30',
            json_encode([
                [
                    'dimension' => 'uk-core:MaturitiesOrExpirationPeriodsDimension',
                    'member' => 'uk-core:WithinOneYear',
                ],
                [
                    'dimension' => 'bus:OriginalRevisedDataDimension',
                    'member' => 'bus:Superseded',
                ],
            ], JSON_THROW_ON_ERROR),
        ],
        [
            $supersededCreditorAfterContextId,
            'aaa-old-creditors-after',
            null,
            null,
            '2023-09-30',
            json_encode([
                [
                    'dimension' => 'uk-core:MaturitiesOrExpirationPeriodsDimension',
                    'member' => 'uk-core:AfterOneYear',
                ],
                [
                    'dimension' => 'bus:OriginalRevisedDataDimension',
                    'member' => 'bus:Superseded',
                ],
            ], JSON_THROW_ON_ERROR),
        ],
    ] as [$id, $reference, $start, $end, $instant, $dimensions]) {
        \InterfaceDB::execute(
            'INSERT INTO companies_house_document_contexts (
                id, document_fk, context_ref, period_start, period_end, instant_date,
                is_latest_year_context, dimension_json
             ) VALUES (
                :id, :document_id, :context_ref, :period_start, :period_end, :instant_date,
                1, :dimensions
             )',
            [
                'id' => $id,
                'document_id' => $documentId,
                'context_ref' => $reference,
                'period_start' => $start,
                'period_end' => $end,
                'instant_date' => $instant,
                'dimensions' => $dimensions,
            ]
        );
    }

    $factDefinitions = [
        ['FixedAssets', 0.0, '-', $instantContextId, 'GBP', 'zero_dash'],
        ['CurrentAssets', 275.0, '275', $instantContextId, 'GBP', ''],
        ['Creditors', 64.0, '64', $creditorContextId, 'GBP', 'ix_sign+presentation_parentheses'],
        ['NetCurrentAssetsLiabilities', -25.0, '25', $instantContextId, 'GBP', 'IX_SIGN'],
        ['NetAssetsLiabilities', 211.0, '211', $instantContextId, 'GBP', 'ix_sign+inline_parentheses'],
        ['Equity', 211.0, '211', $instantContextId, 'GBP', 'ix_sign+presentation_parentheses'],
        ['AverageNumberEmployeesDuringPeriod', 1.0, '1', $durationContextId, 'GBP', ''],
        ['CurrentAssets', 100.0, '100', $supersededInstantContextId, 'GBP', ''],
        ['Creditors', 70.0, '70', $creditorAfterContextId, 'GBP', 'ix_sign+presentation_parentheses'],
        ['Creditors', 999.0, '999', $supersededCreditorWithinContextId, 'GBP', 'ix_sign+presentation_parentheses'],
        ['Creditors', 888.0, '888', $supersededCreditorAfterContextId, 'GBP', 'ix_sign+presentation_parentheses'],
    ];
    foreach ($factDefinitions as $index => [$shortName, $value, $raw, $contextId, $unit, $signHint]) {
        $conceptId = $seed + 20 + $index;
        $factId = $seed + 40 + $index;
        \InterfaceDB::execute(
            'INSERT INTO companies_house_taxonomy_concepts (
                id, concept_name, short_name, friendly_label, value_type
             ) VALUES (
                :id, :concept_name, :short_name, :friendly_label, :value_type
             )',
            [
                'id' => $conceptId,
                'concept_name' => 'fixture:' . $shortName . $seed . '_' . $index,
                'short_name' => $shortName,
                'friendly_label' => $shortName,
                'value_type' => 'monetary',
            ]
        );
        \InterfaceDB::execute(
            'INSERT INTO companies_house_document_facts (
                id, document_fk, context_fk, concept_fk, fact_name, raw_value,
                normalised_numeric, unit_ref, decimals_value, sign_hint,
                is_numeric, is_latest_year_fact
             ) VALUES (
                :id, :document_id, :context_id, :concept_id, :fact_name, :raw_value,
                :normalised_numeric, :unit_ref, :decimals, :sign_hint, 1, 1
             )',
            [
                'id' => $factId,
                'document_id' => $documentId,
                'context_id' => $contextId,
                'concept_id' => $conceptId,
                'fact_name' => $shortName,
                'raw_value' => $raw,
                'normalised_numeric' => $value,
                'unit_ref' => $unit,
                'decimals' => $shortName === 'AverageNumberEmployeesDuringPeriod' ? '0' : '2',
                'sign_hint' => $signHint !== '' ? $signHint : null,
            ]
        );
    }

    return ['company_id' => $companyId, 'document_id' => $documentId];
}
