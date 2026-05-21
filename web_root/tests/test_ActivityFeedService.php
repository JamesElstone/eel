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
$harness->run(ActivityFeedService::class, function (GeneratedServiceClassTestHarness $harness, ActivityFeedService $service): void {
    $harness->check(ActivityFeedService::class, 'formats transaction audit rows for the activity feed', function () use ($harness, $service): void {
        $method = new ReflectionMethod(ActivityFeedService::class, 'transactionAuditItem');
        $method->setAccessible(true);

        $item = $method->invoke($service, [
            'transaction_id' => 42,
            'transaction_description' => 'Bank charge',
            'old_nominal_name' => '',
            'old_category_status' => 'uncategorised',
            'old_is_auto_excluded' => 0,
            'new_nominal_name' => 'Bank fees',
            'new_category_status' => 'manual',
            'new_is_auto_excluded' => 0,
            'changed_by' => 'James',
            'changed_at' => '2026-04-29 12:00:00',
        ]);

        $harness->assertSame('transaction_category', $item['type'] ?? '');
        $harness->assertSame('Transaction categorised', $item['title'] ?? '');
        $harness->assertSame('Bank charge: uncategorised to Bank fees | manual', $item['detail'] ?? '');
        $harness->assertSame('James', $item['meta'] ?? '');
    });

    $harness->check(ActivityFeedService::class, 'formats year-end audit rows for the activity feed', function () use ($harness, $service): void {
        $method = new ReflectionMethod(ActivityFeedService::class, 'yearEndAuditItem');
        $method->setAccessible(true);

        $item = $method->invoke($service, [
            'action' => 'lock',
            'action_by' => 'James',
            'action_at' => '2026-04-29 13:00:00',
            'tax_year_start' => '2025-01-01',
            'tax_year_end' => '2025-12-31',
            'notes' => 'Reviewed',
        ]);

        $harness->assertSame('year_end', $item['type'] ?? '');
        $harness->assertSame('Year-end lock', $item['title'] ?? '');
        $harness->assertSame('2025-01-01 to 2025-12-31: Reviewed', $item['detail'] ?? '');
    });

    $harness->check(ActivityFeedService::class, 'filters activity rows by selected window', function () use ($harness, $service): void {
        $method = new ReflectionMethod(ActivityFeedService::class, 'filterByWindow');
        $method->setAccessible(true);
        $now = new DateTimeImmutable('now');

        $items = [
            [
                'occurred_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s'),
                'title' => 'Recent',
            ],
            [
                'occurred_at' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
                'title' => 'Older',
            ],
        ];

        $filtered = $method->invoke($service, $items, '1_day');

        $harness->assertCount(1, $filtered);
        $harness->assertSame('Recent', $filtered[0]['title'] ?? '');
    });
});
