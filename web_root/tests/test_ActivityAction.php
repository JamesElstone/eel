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
$harness->run(ActivityAction::class, function (GeneratedServiceClassTestHarness $harness, ActivityAction $action): void {
    $harness->check(ActivityAction::class, 'returns dashboard feed invalidation for activity window changes', function () use ($harness, $action): void {
        $request = new RequestFramework(
            [],
            ['activity_window' => 'this_month'],
            ['REQUEST_METHOD' => 'POST'],
            [],
            []
        );
        $services = new PageServiceFramework(new AppService(test_tmp_directory()));
        $result = $action->handle($request, $services);

        $harness->assertSame(['dashboard.feed'], $result->changedFacts());
        $harness->assertSame('this_month', $result->query()['activity_window'] ?? '');
        $harness->assertSame('this_month', $result->context()['activity_window'] ?? '');
    });
});
