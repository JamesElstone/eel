<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
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

function testCurrentAntiFraudDeviceId(): string
{
    $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

    return $deviceId !== '' ? $deviceId : 'test-device';
}

function authenticateTestSession(int $userId = 1): void
{
    $sessionAuthenticationService = new SessionAuthenticationService();
    $sessionAuthenticationService->completeAuthentication($userId, testCurrentAntiFraudDeviceId());
}

function clearAuthenticatedTestSession(): void
{
    $sessionAuthenticationService = new SessionAuthenticationService();
    $sessionAuthenticationService->startSession();
    $_SESSION = [];
}

function createTestPageServiceFramework(): PageServiceFramework
{
    return new PageServiceFramework(
        new AppService(testPageServiceUploadBasePath()),
        new SiteContextCoordinatorFramework(new \eel_accounts\Service\AccountingContextService(), true)
    );
}
