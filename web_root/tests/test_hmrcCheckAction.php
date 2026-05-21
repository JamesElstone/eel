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

$harness->run(hmrcCheckAction::class, function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof hmrcCheckAction) {
        throw new RuntimeException('Unexpected hmrcCheckAction instance.');
    }

    $harness->check('hmrcCheckAction', 'implements shared action interface', function () use ($harness, $instance): void {
        $harness->assertSame(true, $instance instanceof ActionInterfaceFramework);
    });
});
