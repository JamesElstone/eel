<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(HelperFramework::class, function (GeneratedServiceClassTestHarness $harness): void {
    $harness->check(HelperFramework::class, 'builds consistent labels from underscored keys', function () use ($harness): void {
        $harness->assertSame('Parsed Latest Year', HelperFramework::labelFromKey('parsed_latest_year', '_'));
        $harness->assertSame('No Role Assigned', HelperFramework::titleCase('NO ROLE ASSIGNED'));
    });

    $harness->check(HelperFramework::class, 'parses shared date and time formats through one helper', function () use ($harness): void {
        $parsed = HelperFramework::parseDateTimeValue('19/04/2026 14:30');
        $harness->assertTrue($parsed instanceof DateTimeImmutable, 'Expected a parsed DateTimeImmutable instance.');
        $harness->assertSame('2026-04-19 14:30:00', $parsed->format('Y-m-d H:i:s'));
    });

    $harness->check(HelperFramework::class, 'normalises dates and UTC datetimes consistently', function () use ($harness): void {
        $harness->assertSame('2026-04-19', HelperFramework::normaliseDate('19/04/2026'));
        $harness->assertSame('2026-04-19 13:30:00', HelperFramework::normaliseUtcDateTime('2026-04-19T14:30:00+01:00'));
    });

    $harness->check(HelperFramework::class, 'uses display date defaults', function () use ($harness): void {
        $harness->assertSame('19/04/26', HelperFramework::displayDate('2026-04-19'));
        $harness->assertSame('19/04/26 to 30/04/26', HelperFramework::accountingPeriodLabel('2026-04-19', '2026-04-30'));
    });

    $harness->check(HelperFramework::class, 'formats http header labels from server keys consistently', function () use ($harness): void {
        $harness->assertSame('X-Forwarded-For', HelperFramework::httpHeaderLabelFromServerKey('HTTP_X_FORWARDED_FOR'));
        $harness->assertSame('Content-Type', HelperFramework::httpHeaderLabelFromServerKey('CONTENT_TYPE'));
    });

    $harness->check(HelperFramework::class, 'paginates arrays and reports navigation state', function () use ($harness): void {
        $pagination = HelperFramework::paginateArray(range(1, 30), 2, 10);

        $harness->assertSame(range(11, 20), $pagination['items']);
        $harness->assertSame(2, $pagination['page']);
        $harness->assertSame(10, $pagination['page_size']);
        $harness->assertSame(3, $pagination['page_count']);
        $harness->assertSame(30, $pagination['total']);
        $harness->assertSame(10, $pagination['offset']);
        $harness->assertSame(true, $pagination['has_previous_page']);
        $harness->assertSame(true, $pagination['has_next_page']);
    });

    $harness->check(HelperFramework::class, 'clamps array pagination to a real page', function () use ($harness): void {
        $pagination = HelperFramework::paginateArray(range(1, 12), 99, 5);

        $harness->assertSame([11, 12], $pagination['items']);
        $harness->assertSame(3, $pagination['page']);
        $harness->assertSame(3, $pagination['page_count']);
        $harness->assertSame(true, $pagination['has_previous_page']);
        $harness->assertSame(false, $pagination['has_next_page']);

        $emptyPagination = HelperFramework::paginateArray([], 5, 10);
        $harness->assertSame([], $emptyPagination['items']);
        $harness->assertSame(1, $emptyPagination['page']);
        $harness->assertSame(0, $emptyPagination['page_count']);
        $harness->assertSame(false, $emptyPagination['has_previous_page']);
        $harness->assertSame(false, $emptyPagination['has_next_page']);
    });

    $harness->check(HelperFramework::class, 'labels explicit pagination ranges and single items', function () use ($harness): void {
        $harness->assertSame('Rows 11-20 of 30', HelperFramework::paginationItemsLabel([
            'total_items' => 30,
            'first_item' => 11,
            'last_item' => 20,
        ], 'Rows'));
        $harness->assertSame('Rows 3 of 10', HelperFramework::paginationItemsLabel([
            'total_items' => 10,
            'first_item' => 3,
            'last_item' => 3,
        ], 'Rows'));
        $harness->assertSame('Rows 10 of 10', HelperFramework::paginationItemsLabel([
            'total_items' => 10,
            'first_item' => 50,
            'last_item' => 60,
        ], 'Rows'));
        $harness->assertSame('Rows 5 of 5', HelperFramework::paginationItemsLabel([
            'total' => 5,
            'page' => 2,
            'page_size' => 4,
            'items' => ['last'],
        ], 'Rows'));
    });
});
