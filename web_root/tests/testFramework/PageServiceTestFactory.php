<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

function testPageServiceUploadBasePath(): string
{
    $path = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';

    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create the shared test upload base path.');
    }

    return $path;
}

function createTestPageServiceFramework(): PageServiceFramework
{
    return new PageServiceFramework(new AppService(testPageServiceUploadBasePath()));
}
