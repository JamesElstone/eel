<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

return array (
  'db' => 
  array (
    'dsn' => 'odbc:platinum_mariadb_accounts',
    'user' => '',
    'pass' => '',
    'logfile' => '',
  ),
  'uploads' => 
  array (
    'upload_base_dir' => 'C:\\Users\\James\\Documents\\elstone electricals limited\\eel_accounts\\uploads',
    'statement_relative_path' => './statements/',
    'expense_receipts_relative_path' => './expense_receipts/',
    'transaction_receipts_relative_path' => './transaction_receipts/',
    'show_base_path_details' => true,
    'export_key' => 'hON9iT1u8hTALiXzgU9fl4M2qB7yP6Qs',
  ),
  'developer_options' => true,
  'navigation' => 
  array (
    'default_order' => 
    array (
      'dashboard' => 10,
      'companies' => 20,
      'bank_accounts' => 30,
      'uploads' => 40,
      'transactions' => 50,
      'trial_balance' => 60,
      'director_loan' => 70,
      'expenses' => 80,
      'assets' => 90,
      'vat' => 100,
      'year_end' => 110,
      'nominals' => 120,
      'journals' => 130,
      'settings' => 140,
      'users' => 150,
      'roles' => 160,
      'logs' => 170,
      'test' => 180,
    ),
  ),
  'hmrc' => 
  array (
    'vat' => 
    array (
      'lookup_path' => '/organisations/vat/check-vat-number/lookup/{vatNumber}',
      'oauth_path' => '/oauth/token',
      'accept_header' => 'application/vnd.hmrc.2.0+json',
      'token_scope' => 'read:vat',
      'credential_provider' => 'HMRC',
      'credential_tag' => 'VAT_CHECK',
      'mode' => 'TEST',
      'timeout_seconds' => 10,
    ),
    'ct600' => 
    array (
      'submit_path' => '',
      'oauth_path' => '/oauth/token',
      'accept_header' => 'application/json',
      'token_scope' => '',
      'credential_provider' => 'HMRC',
      'credential_tag' => 'CT600',
      'mode' => 'TEST',
      'timeout_seconds' => 10,
    ),
  ),
  'api_keys' => 
  array (
    'path' => '../secure/api.keys',
  ),
  'antifraud' => 
  array (
    'vendor_license_ids' => '1234',
    'vendor_product_name' => 'EEL Accounts',
    'vendor_public_ip' => '80.235.222.101',
    'vendor_version' => 'dev',
  ),
  'runtime' => 
  array (
    'hmrc_mode' => 'TEST',
    'ch_mode' => 'LIVE',
  ),
);
