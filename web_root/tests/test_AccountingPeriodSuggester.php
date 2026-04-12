<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(AccountingPeriodSuggester::class, function (GeneratedServiceClassTestHarness $harness, AccountingPeriodSuggester $suggester): void {
    $harness->check(AccountingPeriodSuggester::class, 'suggests the first accounting period', function () use ($harness, $suggester): void {
        $first = $suggester->suggestFirstPeriod(new DateTimeImmutable('2024-01-15'));

        $harness->assertSame('2024-01-15', $first['start']);
        $harness->assertSame('2025-01-31', $first['end']);
        $harness->assertSame('suggested_first_period', $first['source']);
    });

    $harness->check(AccountingPeriodSuggester::class, 'filters out existing suggested periods', function () use ($harness, $suggester): void {
        $first = $suggester->suggestFirstPeriod(new DateTimeImmutable('2024-01-15'));
        $missing = $suggester->missingSuggestedPeriods(
            [
                [
                    'period_start' => '2024-01-15',
                    'period_end' => '2025-01-31',
                ],
            ],
            [
                $first,
                [
                    'start' => '2025-02-01',
                    'end' => '2026-01-31',
                    'label' => '01/02/2025 to 31/01/2026',
                    'source' => 'suggested_follow_on_period',
                ],
            ]
        );

        $harness->assertCount(1, $missing);
        $harness->assertSame('2025-02-01', $missing[0]['start']);
    });
});
