<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

return array (
  'app_name' => 'eelKit Framework Test',
  'brand-mark' => 'T',
  'app_strapline' => 'Test strapline',
  'db' => 
  array (
    'dsn' => 'sqlite::memory:',
    'user' => '',
    'pass' => '',
    'logfile' => '',
    'sqlite_schema' => '../db_schema/eel_accounts.schema.sql',
  ),
  'developer_options' => true,
  'navigation' => 
  array (
    'default_order' => 
    array (
    ),
    'developer_only_pages' => 
    array (
      0 => 'test',
    ),
    'hide_collapsed_link_initials' => false,
  ),
  'uploads' => 
  array (
    'export_key' => '3KwECjQFlWmpTztcBAuviitPhE8uAJFL',
  ),
  'site_context' => 
  array (
    'service' => 'AccountingContextService',
  ),
);

