<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

if (!function_exists('test_tmp_directory')) {
    throw new RuntimeException('The downstream test bootstrap did not provide test_tmp_directory().');
}

$harnessSource = (new ReflectionClass(GeneratedServiceClassTestHarness::class))->getFileName();
if (!is_string($harnessSource) || realpath($harnessSource) !== realpath(__DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php')) {
    throw new RuntimeException('The downstream test harness was not loaded before framework tests.');
}

test_output_line('ProjectTestBootstrap: loads downstream test helpers before mixed framework and project tests.');
