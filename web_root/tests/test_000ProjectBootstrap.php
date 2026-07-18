<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'TestBootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'TestOutput.php';

if (!function_exists('test_tmp_directory')) {
    throw new RuntimeException('The downstream test bootstrap did not provide test_tmp_directory().');
}

test_output_line('ProjectTestBootstrap: loads downstream test helpers before mixed framework and project tests.');
