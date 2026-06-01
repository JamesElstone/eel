<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'testFramework' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(UserAuthenticationService::class);

$userAuthTempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($userAuthTempDirectory)) {
    mkdir($userAuthTempDirectory, 0777, true);
}

$userAuthSecurityPath = static function () use ($userAuthTempDirectory): string {
    return $userAuthTempDirectory . DIRECTORY_SEPARATOR . 'user-auth-' . bin2hex(random_bytes(8)) . '.keys';
};

$withTemporaryAuthUser = function (callable $callback) use ($harness): void {
    if (!InterfaceDB::tableExists('users')) {
        $harness->skip('users table is not available on the default InterfaceDB connection.');
    }

    InterfaceDB::beginTransaction();

    try {
        $callback();
    } finally {
        if (InterfaceDB::inTransaction()) {
            InterfaceDB::rollBack();
        }
    }
};

$harness->check(UserAuthenticationService::class, 'hashes and verifies peppered passwords', function () use ($harness, $userAuthSecurityPath): void {
    $path = $userAuthSecurityPath();

    try {
        $service = new UserAuthenticationService($path, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);
        $hash = $service->hashPassword('Correct Horse 1!');

        $harness->assertTrue($hash !== 'Correct Horse 1!');
        $harness->assertTrue($service->verifyPassword('Correct Horse 1!', $hash));
        $harness->assertTrue(!$service->verifyPassword('wrong password', $hash));
        $harness->assertTrue(!$service->verifyPassword('Correct Horse 1!', ''));
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

$harness->check(UserAuthenticationService::class, 'enforces password policy', function () use ($harness, $userAuthSecurityPath): void {
    $path = $userAuthSecurityPath();
    $service = new UserAuthenticationService($path, [
        'memory_cost' => 8192,
        'time_cost' => 1,
        'threads' => 1,
    ]);

    try {
        $service->hashPassword('short');
    } catch (RuntimeException $exception) {
        if (is_file($path)) {
            unlink($path);
        }

        $harness->assertTrue(str_contains($exception->getMessage(), 'at least 12'));
        return;
    }

    if (is_file($path)) {
        unlink($path);
    }

    throw new RuntimeException('Weak password was accepted.');
});

$harness->check(UserAuthenticationService::class, 'creates users and rejects duplicate email addresses', function () use ($harness, $withTemporaryAuthUser, $userAuthSecurityPath): void {
    $withTemporaryAuthUser(function () use ($harness, $userAuthSecurityPath): void {
        $path = $userAuthSecurityPath();
        $service = new UserAuthenticationService($path, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        try {
            $created = $service->createUser('Test User', 'USER@example.test', 'Strong Password 1!', true, '+447123456789');
            $duplicate = $service->createUser('Duplicate User', 'user@example.test', 'Strong Password 1!');
            $authenticated = $service->authenticateByEmailAddress(' user@example.test ', 'Strong Password 1!');

            $harness->assertTrue(!empty($created['success']));
            $harness->assertTrue(empty($duplicate['success']));
            $harness->assertTrue(is_array($authenticated));
            $harness->assertTrue(!array_key_exists('password_hash', $created['user'] ?? []));
            $harness->assertSame('+447123456789', (string)(($created['user'] ?? [])['mobile_number'] ?? ''));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
});

$harness->check(UserAuthenticationService::class, 'validates user updates and inactive authentication', function () use ($harness, $withTemporaryAuthUser, $userAuthSecurityPath): void {
    $withTemporaryAuthUser(function () use ($harness, $userAuthSecurityPath): void {
        $path = $userAuthSecurityPath();
        $service = new UserAuthenticationService($path, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        try {
            $created = $service->createUser('Update User', 'update@example.test', 'Strong Password 1!');
            $userId = (int)($created['user_id'] ?? 0);

            $blankPassword = $service->updateUser($userId, 'Update User', 'update@example.test', '');
            $updatedMobile = $service->updateUser($userId, 'Update User', 'update@example.test', null, null, '+447987654321');
            $inactive = $service->setUserActive($userId, false);

            $harness->assertTrue(empty($blankPassword['success']));
            $harness->assertSame('+447987654321', (string)(($updatedMobile['user'] ?? [])['mobile_number'] ?? ''));
            $harness->assertTrue(!empty($inactive['success']));
            $harness->assertTrue($service->authenticateByEmailAddress('update@example.test', 'Strong Password 1!') === false);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
});

$harness->check(UserAuthenticationService::class, 'throttles repeated failed password attempts', function () use ($harness, $withTemporaryAuthUser, $userAuthSecurityPath): void {
    $withTemporaryAuthUser(function () use ($harness, $userAuthSecurityPath): void {
        $path = $userAuthSecurityPath();
        $service = new UserAuthenticationService($path, [
            'memory_cost' => 8192,
            'time_cost' => 1,
            'threads' => 1,
        ]);

        try {
            $service->createUser('Rate Limited User', 'rate-limit@example.test', 'Strong Password 1!');
            $service->recordFailedPasswordAttempt('rate-limit@example.test', 'device-a');
            $service->recordFailedPasswordAttempt('RATE-LIMIT@example.test', 'device-a');
            $status = $service->recordFailedPasswordAttempt('rate-limit@example.test', 'device-a');

            $harness->assertTrue(!empty($status['is_throttled']));
            $harness->assertSame(3, (int)($status['consecutive_failed_password_attempts'] ?? 0));

            $service->clearLoginRateLimit('rate-limit@example.test', 'device-a');
            $cleared = $service->loginRateLimitStatus('rate-limit@example.test', 'device-a');
            $harness->assertTrue(empty($cleared['is_throttled']));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
});
