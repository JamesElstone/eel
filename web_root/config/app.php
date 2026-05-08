<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

return array (
  'app_name' => 'eelKit Framework',
  'developer_options' => true,
  'db' => 
  array (
    'dsn' => 'odbc:eelkit',
    'user' => '',
    'pass' => '',

    // Optional SQL query logging.
    // Leave blank to disable.
    // Relative paths are resolved from APP_ROOT ("web_root");
    // Absolute paths are used as-is (UNC paths are supported on Windows).
    // Output is CSV-formatted.
    'logfile' => '',
  ),
  'navigation' => 
  array (
    'default_order' => 
    array (
    ),
    'developer_only_pages' => 
    array (
      0 => 'test',
    ),
  ),
  'antifraud' => 
  array (
    'vendor_license_ids' => '1234',
    'vendor_product_name' => 'eelKit',
    'vendor_public_ip' => '',
    'vendor_version' => 'dev',
  ),
);
