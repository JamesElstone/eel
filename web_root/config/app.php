<?php
declare(strict_types=1);

return [
    'db' => [
        'dsn' => 'odbc:platinum_mariadb_accounts',
        'user' => '',
        'pass' => '',
        'logfile' => '',
    ],
    'uploads' => [
        'upload_base_dir' => 'C:\Users\James\Documents\elstone electricals limited\hmrc_account_app\uploads',
        'statement_relative_path' => './statements/',
        'expense_receipts_relative_path' => './expense_receipts/',
        'transaction_receipts_relative_path' => './transaction_receipts/',
        'show_base_path_details' => true,
    ],
    'developer_options' => true,
    'hmrc' => [
        'vat' => [
            'lookup_path' => '/organisations/vat/check-vat-number/lookup/{vatNumber}',
            'oauth_path' => '/oauth/token',
            'accept_header' => 'application/vnd.hmrc.2.0+json',
            'token_scope' => 'read:vat',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'VAT_CHECK',
            'mode' => 'TEST',
            'test_base_url' => 'https://test-api.service.hmrc.gov.uk',
            'live_base_url' => 'https://api.service.hmrc.gov.uk',
            'timeout_seconds' => 10,
        ],
        'ct600' => [
            'submit_path' => '',
            'oauth_path' => '/oauth/token',
            'accept_header' => 'application/json',
            'token_scope' => '',
            'credential_provider' => 'HMRC',
            'credential_tag' => 'CT600',
            'mode' => 'TEST',
            'timeout_seconds' => 10,
        ],
    ],
    'api_keys' => [
        'path' => '../secure/api.keys',
    ],
    'antifraud' => [
        'vendor_license_ids' => '1234',
        'vendor_product_name' => 'EEL Accounts',
        'vendor_public_ip' => '80.235.222.101',
        'vendor_version' => 'dev',
    ],
];
