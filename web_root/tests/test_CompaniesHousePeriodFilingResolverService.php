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
    \eel_accounts\Service\CompaniesHousePeriodFilingResolverService::class,
    static function (
        GeneratedServiceClassTestHarness $harness,
        \eel_accounts\Service\CompaniesHousePeriodFilingResolverService $service
    ): void {
        $harness->check(
            $service::class,
            'preserves a pinned original and retains newest-first revised filing history',
            static function () use ($harness, $service): void {
                $summaries = [
                    companiesHouseResolverSummary(10, '2025-06-01', 'AA', '2024-09-30'),
                    companiesHouseResolverSummary(11, '2025-07-01', 'AA', '2024-09-30'),
                    companiesHouseResolverSummary(20, '2026-08-09', 'AAMD', '2024-09-30'),
                    companiesHouseResolverSummary(
                        21,
                        '2026-08-10',
                        'AAMD',
                        '2024-09-30',
                        'metadata_fetch_failed',
                        'Latest metadata could not be read.'
                    ),
                    companiesHouseResolverSummary(30, '2026-08-10', 'AA', '2025-09-30'),
                ];

                $result = $service->resolveSummaries($summaries, '2024-09-30', [[
                    'document_id' => 11,
                    'source' => 'year_end_approval',
                ]]);

                $harness->assertSame(11, (int)($result['original']['id'] ?? 0));
                $harness->assertSame(11, (int)($result['pinned_original_document_id'] ?? 0));
                $harness->assertSame('year_end_approval', (string)($result['pin_source'] ?? ''));
                $harness->assertSame([21, 20], array_map(
                    static fn(array $document): int => (int)$document['id'],
                    (array)$result['revisions']
                ));
                $harness->assertSame(21, (int)($result['latest_revision']['id'] ?? 0));
                $harness->assertSame(21, (int)($result['latest_filed']['id'] ?? 0));
                $harness->assertSame('metadata_fetch_failed', (string)($result['effective']['parse_status'] ?? ''));
            }
        );

        $harness->check(
            $service::class,
            'uses the earliest exact non-revised filing and recognises the iXBRL revision marker',
            static function () use ($harness, $service): void {
                $first = companiesHouseResolverSummary(40, '2025-06-01', 'AA', '2024-09-30');
                $later = companiesHouseResolverSummary(41, '2025-06-02', 'AA', '2024-09-30');
                $markedRevision = companiesHouseResolverSummary(42, '2026-08-10', 'AA', '2024-09-30');
                $markedRevision['revision_marker'] = 'true';

                $result = $service->resolveSummaries([$later, $markedRevision, $first], '2024-09-30');

                $harness->assertSame(40, (int)($result['original']['id'] ?? 0));
                $harness->assertSame(42, (int)($result['latest_revision']['id'] ?? 0));
                $harness->assertSame(true, !empty($result['latest_revision']['is_revision']));
            }
        );

        $harness->check(
            $service::class,
            'uses the earliest metadata creation time for same-day original AA filings',
            static function () use ($harness, $service): void {
                $earlier = companiesHouseResolverSummary(
                    111,
                    '2025-06-01',
                    'AA',
                    '2024-09-30',
                    metadataCreatedAt: '2025-06-01T10:00:00Z'
                );
                $later = companiesHouseResolverSummary(
                    110,
                    '2025-06-01',
                    'AA',
                    '2024-09-30',
                    metadataCreatedAt: '2025-06-01T11:00:00Z'
                );

                foreach ([[$later, $earlier], [$earlier, $later]] as $summaries) {
                    $result = $service->resolveSummaries($summaries, '2024-09-30');
                    $harness->assertSame(111, (int)($result['original']['id'] ?? 0));
                }
            }
        );

        $harness->check(
            $service::class,
            'uses metadata creation time and significant date for a factless failed amendment',
            static function () use ($harness, $service): void {
                $original = companiesHouseResolverSummary(50, '2025-06-01', 'AA', '2024-09-30');
                $parsed = companiesHouseResolverSummary(
                    60,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    'parsed_latest_year',
                    '',
                    '2026-08-10T10:00:00.000000000Z'
                );
                $failed = companiesHouseResolverSummary(
                    59,
                    '2026-08-10',
                    'AAMD',
                    '',
                    'parse_failed',
                    'Latest metadata could not be read.',
                    '2026-08-10T11:00:00.000000000Z',
                    '2024-09-30T00:00:00Z'
                );

                $result = $service->resolveSummaries([$original, $parsed, $failed], '2024-09-30');

                $harness->assertSame([59, 60], array_map(
                    static fn(array $document): int => (int)$document['id'],
                    (array)$result['revisions']
                ));
                $harness->assertSame(59, (int)($result['latest_revision']['id'] ?? 0));
                $harness->assertSame('2024-09-30', (string)($result['latest_revision']['period_end'] ?? ''));
                $harness->assertSame('parse_failed', (string)($result['latest_revision']['parse_status'] ?? ''));
            }
        );

        $harness->check(
            $service::class,
            'falls back to filing date when either revision lacks metadata creation time',
            static function () use ($harness, $service): void {
                $olderWithMetadata = companiesHouseResolverSummary(
                    70,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    metadataCreatedAt: '2026-08-10T12:00:00Z'
                );
                $newerWithoutMetadata = companiesHouseResolverSummary(
                    71,
                    '2026-08-11',
                    'AAMD',
                    '2024-09-30'
                );

                $result = $service->resolveSummaries(
                    [
                        companiesHouseResolverSummary(69, '2025-06-01', 'AA', '2024-09-30'),
                        $olderWithMetadata,
                        $newerWithoutMetadata,
                    ],
                    '2024-09-30'
                );

                $harness->assertSame(71, (int)($result['latest_revision']['id'] ?? 0));
            }
        );

        $harness->check(
            $service::class,
            'keeps a same-day metadata-failed amendment latest and unverifiable',
            static function () use ($harness, $service): void {
                $original = companiesHouseResolverSummary(99, '2025-06-01', 'AA', '2024-09-30');
                $parsed = companiesHouseResolverSummary(
                    100,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    metadataCreatedAt: '2026-08-10T10:00:00Z'
                );
                $failed = companiesHouseResolverSummary(
                    101,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    'metadata_fetch_failed',
                    'Metadata is not available yet.'
                );

                foreach ([[$parsed, $failed], [$failed, $parsed]] as $revisions) {
                    $result = $service->resolveSummaries(
                        array_merge([$original], $revisions),
                        '2024-09-30'
                    );
                    $harness->assertSame([101, 100], array_map(
                        static fn(array $document): int => (int)$document['id'],
                        (array)$result['revisions']
                    ));
                    $harness->assertSame(101, (int)($result['latest_revision']['id'] ?? 0));
                    $harness->assertSame(
                        'metadata_fetch_failed',
                        (string)($result['latest_revision']['parse_status'] ?? '')
                    );
                }
            }
        );

        $harness->check(
            $service::class,
            'uses filing-history order before row id for a same-day metadata failure',
            static function () use ($harness, $service): void {
                $original = companiesHouseResolverSummary(119, '2025-06-01', 'AA', '2024-09-30');
                $newerFailed = companiesHouseResolverSummary(
                    120,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    'metadata_fetch_failed',
                    'Metadata unavailable.'
                );
                $newerFailed['transaction_id'] = 'a-newest';
                $newerFailed['filing_history_order'] = 0;
                $olderParsed = companiesHouseResolverSummary(
                    121,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    metadataCreatedAt: '2026-08-10T09:00:00Z'
                );
                $olderParsed['transaction_id'] = 'z-older';
                $olderParsed['filing_history_order'] = 1;

                foreach ([[$newerFailed, $olderParsed], [$olderParsed, $newerFailed]] as $revisions) {
                    $result = $service->resolveSummaries(
                        array_merge([$original], $revisions),
                        '2024-09-30'
                    );
                    $harness->assertSame([120, 121], array_map(
                        static fn(array $document): int => (int)$document['id'],
                        (array)$result['revisions']
                    ));
                    $harness->assertSame(120, (int)($result['latest_revision']['id'] ?? 0));
                }
            }
        );

        $harness->check(
            $service::class,
            'uses one effective timestamp per revision to give every input permutation the same order',
            static function () use ($harness, $service): void {
                $original = companiesHouseResolverSummary(79, '2025-06-01', 'AA', '2024-09-30');
                $a = companiesHouseResolverSummary(
                    80,
                    '2026-08-10',
                    'AAMD',
                    '2024-09-30',
                    metadataCreatedAt: '2026-08-12T12:00:00Z'
                );
                $b = companiesHouseResolverSummary(
                    81,
                    '2026-08-12',
                    'AAMD',
                    '2024-09-30',
                    metadataCreatedAt: '2026-08-11T12:00:00Z'
                );
                $c = companiesHouseResolverSummary(82, '2026-08-11', 'AAMD', '2024-09-30');

                foreach ([
                    [$a, $b, $c], [$a, $c, $b], [$b, $a, $c],
                    [$b, $c, $a], [$c, $a, $b], [$c, $b, $a],
                ] as $permutation) {
                    $result = $service->resolveSummaries(
                        array_merge([$original], $permutation),
                        '2024-09-30'
                    );
                    $harness->assertSame([80, 81, 82], array_map(
                        static fn(array $document): int => (int)$document['id'],
                        (array)$result['revisions']
                    ));
                }
            }
        );

        $harness->check(
            $service::class,
            'uses only an exact-period AA as the unpinned original',
            static function () use ($harness, $service): void {
                $nonAa = companiesHouseResolverSummary(90, '2025-05-01', 'AC', '2024-09-30');
                $aa = companiesHouseResolverSummary(91, '2025-06-01', 'AA', '2024-09-30');

                $withAa = $service->resolveSummaries([$nonAa, $aa], '2024-09-30');
                $harness->assertSame(91, (int)($withAa['original']['id'] ?? 0));

                $withoutAa = $service->resolveSummaries([$nonAa], '2024-09-30');
                $harness->assertSame(null, $withoutAa['original'] ?? null);
            }
        );

        $harness->check(
            $service::class,
            'accepts the legacy filing id field from an approval basis',
            static function () use ($harness, $service): void {
                $method = new ReflectionMethod($service, 'approvalDocumentId');
                $harness->assertSame(123, (int)$method->invoke($service, [
                    'facts' => ['filing' => ['id' => 123]],
                ]));
                $harness->assertSame(456, (int)$method->invoke($service, [
                    'facts' => ['filing_evidence' => ['id' => 456]],
                ]));
            }
        );
    }
);

/** @return array<string,mixed> */
function companiesHouseResolverSummary(
    int $id,
    string $filingDate,
    string $filingType,
    string $periodEnd,
    string $parseStatus = 'parsed_latest_year',
    string $parseError = '',
    string $metadataCreatedAt = '',
    string $significantDate = ''
): array {
    return [
        'id' => $id,
        'document_id' => 'document-' . $id,
        'transaction_id' => 'transaction-' . $id,
        'filing_date' => $filingDate,
        'filing_type' => $filingType,
        'filing_category' => 'accounts',
        'filing_description' => $filingType === 'AAMD'
            ? 'accounts-amended-with-accounts-type-micro-entity'
            : 'accounts-with-accounts-type-micro-entity',
        'latest_year_period_start' => '2023-10-01',
        'latest_year_period_end' => $periodEnd,
        'parse_status' => $parseStatus,
        'parse_error' => $parseError,
        'raw_metadata_json' => json_encode([
            'created_at' => $metadataCreatedAt,
            'significant_date' => $significantDate,
        ], JSON_UNESCAPED_SLASHES),
    ];
}
