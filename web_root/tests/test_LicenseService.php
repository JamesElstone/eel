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

$harness->run('LicenseService', function (GeneratedServiceClassTestHarness $harness, object $instance): void {
    if (!$instance instanceof LicenseService) {
        throw new RuntimeException('Unexpected LicenseService instance.');
    }

    $harness->check('LicenseService', 'lists the three project license areas', function () use ($harness, $instance): void {
        $licenses = $instance->licenseIndex();

        $harness->assertSame(['bsd_3_clause', 'agpl_3_0', 'fonts'], array_keys($licenses));
    });

    $harness->check('LicenseService', 'reads bundled license text from project files', function () use ($harness, $instance): void {
        $harness->assertTrue(str_contains($instance->licenseText('bsd_3_clause'), 'BSD 3-Clause License'));
        $harness->assertTrue(str_contains($instance->licenseText('agpl_3_0'), 'GNU AFFERO GENERAL PUBLIC LICENSE'));
        $harness->assertTrue(str_contains($instance->licenseText('fonts'), 'SIL OPEN FONT LICENSE'));
    });

    $harness->check('LicenseService', 'rejects unknown license keys', function () use ($harness, $instance): void {
        $thrown = false;

        try {
            $instance->licenseText('../LICENSE');
        } catch (InvalidArgumentException) {
            $thrown = true;
        }

        $harness->assertTrue($thrown);
    });
});
