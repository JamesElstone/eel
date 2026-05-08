<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

return [
    'app_name' => 'eelKit Framework Test',
    'app_strapline' => 'Test strapline',
    'db' => [
        'dsn' => 'sqlite::memory:',
        'user' => '',
        'pass' => '',
        'logfile' => '',
        'sqlite_schema' => '../db_schema/eelKit.schema.sql',
    ],
    'developer_options' => true,
    'navigation' => [
        'default_order' => [],
        'developer_only_pages' => [
            'test',
        ],
    ],
];
