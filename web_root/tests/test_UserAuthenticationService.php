<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(UserAuthenticationService::class, static function (GeneratedServiceClassTestHarness $harness): void {
    $tempDirectory = APP_ROOT . 'tests' . DIRECTORY_SEPARATOR . 'tmp';
    $securityPath = $tempDirectory . DIRECTORY_SEPARATOR . 'user-auth-security.keys';

    if (!is_dir($tempDirectory)) {
        mkdir($tempDirectory, 0777, true);
    }

    if (is_file($securityPath)) {
        unlink($securityPath);
    }

    $service = new UserAuthenticationService($securityPath);

    $harness->check(UserAuthenticationService::class, 'hashes and verifies passwords with a generated pepper', static function () use ($harness, $service, $securityPath): void {
        $password = 'Correct Horse Battery Staple 123!';
        $hash = $service->hashPassword($password);

        $harness->assertTrue($hash !== '');
        $harness->assertTrue($service->verifyPassword($password, $hash));
        $harness->assertTrue(!$service->verifyPassword('wrong password', $hash));
        $harness->assertTrue(is_file($securityPath));

        $contents = file_get_contents($securityPath);

        if ($contents === false) {
            throw new RuntimeException('Failed to read generated security key file.');
        }

        $harness->assertTrue(str_contains($contents, '# keys,fact'));
        $harness->assertTrue(str_contains($contents, '"pepper","'));
    });

    $harness->check(UserAuthenticationService::class, 'creates a user with a hashed password and reloads the saved row', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('users')) {
            $harness->skip('users table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = bin2hex(random_bytes(8));
            $result = $service->createUser(
                'Created User ' . $marker,
                'created-' . $marker . '@example.test',
                'Create User Password 1!',
                true,
                true
            );

            $harness->assertTrue($result['success'] === true);
            $harness->assertSame([], $result['errors']);
            $harness->assertTrue((int)$result['user_id'] > 0);
            $harness->assertTrue(is_array($result['user']));
            $harness->assertTrue(!array_key_exists('password_hash', $result['user']));

            $storedHash = (string)InterfaceDB::fetchColumn(
                'SELECT password_hash
                 FROM users
                 WHERE id = :id',
                ['id' => (int)$result['user_id']]
            );

            $harness->assertTrue($storedHash !== '');
            $harness->assertTrue($service->verifyPassword('Create User Password 1!', $storedHash));
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserAuthenticationService::class, 'rejects weak passwords when creating a user', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('users')) {
            $harness->skip('users table is not available on the default InterfaceDB connection.');
        }

        $result = $service->createUser(
            'Weak Password User',
            'weak-password-user@example.test',
            'weakpass',
            false,
            true
        );

        $harness->assertTrue($result['success'] === false);
        $harness->assertTrue(in_array('Password must be at least 12 characters long.', (array)$result['errors'], true));
        $harness->assertTrue(in_array('Password must include at least one uppercase letter.', (array)$result['errors'], true));
        $harness->assertTrue(in_array('Password must include at least one number.', (array)$result['errors'], true));
        $harness->assertTrue(in_array('Password must include at least one symbol.', (array)$result['errors'], true));
    });

    $harness->check(UserAuthenticationService::class, 'reports that users exist after creating one', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('users')) {
            $harness->skip('users table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = bin2hex(random_bytes(8));
            $result = $service->createUser(
                'Existing User ' . $marker,
                'existing-' . $marker . '@example.test',
                'Existing User Password 1!',
                false,
                true
            );

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue($service->hasAnyUsers());
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserAuthenticationService::class, 'blocks initial-user creation once any user already exists', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('users')) {
            $harness->skip('users table is not available on the default InterfaceDB connection.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = bin2hex(random_bytes(8));
            $created = $service->createUser(
                'Seed User ' . $marker,
                'seed-' . $marker . '@example.test',
                'Seed User Password 1!',
                false,
                true
            );

            $harness->assertTrue($created['success'] === true);

            $result = $service->createInitialUser(
                'Initial User ' . $marker,
                'initial-' . $marker . '@example.test',
                'Initial User Password 1!'
            );

            $harness->assertTrue($result['success'] === false);
            $harness->assertSame('Initial account setup is no longer available. Please sign in.', (string)$result['errors'][0]);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    $harness->check(UserAuthenticationService::class, 'creates the initial user when the users table is empty', static function () use ($harness, $service): void {
        if (!InterfaceDB::tableExists('users')) {
            $harness->skip('users table is not available on the default InterfaceDB connection.');
        }

        $existingCount = (int)(InterfaceDB::fetchColumn('SELECT COUNT(*) FROM users') ?: 0);
        if ($existingCount !== 0) {
            $harness->skip('users table is not empty, so initial-user bootstrap cannot be exercised safely here.');
        }

        InterfaceDB::beginTransaction();

        try {
            $marker = bin2hex(random_bytes(8));
            $result = $service->createInitialUser(
                'Bootstrap User ' . $marker,
                'bootstrap-' . $marker . '@example.test',
                'Bootstrap Password 1!'
            );

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue((int)$result['user_id'] > 0);
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    });

    if (!InterfaceDB::tableExists('users')) {
        $harness->skip('users table is not available on the default InterfaceDB connection.');
    }

    $withTemporaryUser = static function (
        UserAuthenticationService $authService,
        string $password,
        callable $callback
    ): void {
        InterfaceDB::beginTransaction();

        try {
            $marker = 'auth-test-' . bin2hex(random_bytes(8));
            $hash = $authService->hashPassword($password);

            InterfaceDB::prepareExecute(
                'INSERT INTO users (
                    display_name,
                    email_address,
                    password_hash,
                    confirmed_director,
                    is_active
                ) VALUES (
                    :display_name,
                    :email_address,
                    :password_hash,
                    0,
                    1
                )',
                [
                    'display_name' => 'Auth Test User ' . $marker,
                    'email_address' => 'auth-' . $marker . '@example.test',
                    'password_hash' => $hash,
                ]
            );

            $user = InterfaceDB::fetchOne(
                'SELECT id, display_name, email_address
                 FROM users
                 WHERE password_hash = :password_hash
                 ORDER BY id DESC
                 LIMIT 1',
                ['password_hash' => $hash]
            );

            if (!is_array($user)) {
                throw new RuntimeException('Temporary authentication test user could not be reloaded.');
            }

            $callback(
                (int)$user['id'],
                (string)$user['display_name'],
                (string)($user['email_address'] ?? '')
            );
        } finally {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
        }
    };

    $harness->check(UserAuthenticationService::class, 'authenticates an active user by id and omits the password hash from the result', static function () use ($harness, $service, $withTemporaryUser): void {
        $password = 'Auth Test Password 1!';

        $withTemporaryUser($service, $password, static function (int $userId) use ($harness, $service, $password): void {
            $authenticated = $service->authenticateByUserId($userId, $password);

            $harness->assertTrue(is_array($authenticated));
            $harness->assertSame($userId, (int)$authenticated['id']);
            $harness->assertTrue(!array_key_exists('password_hash', $authenticated));
        });
    });

    $harness->check(UserAuthenticationService::class, 'rehashes a valid password when stronger options are configured', static function () use ($harness, $securityPath, $withTemporaryUser): void {
        $password = 'Auth Test Password 2!';
        $weakService = new UserAuthenticationService($securityPath, [
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => 1,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ]);
        $strongService = new UserAuthenticationService($securityPath, [
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => 2,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ]);

        $withTemporaryUser($weakService, $password, static function (int $userId) use ($harness, $strongService, $password): void {
            $beforeHash = (string)InterfaceDB::fetchColumn(
                'SELECT password_hash
                 FROM users
                 WHERE id = :id',
                ['id' => $userId]
            );

            $authenticated = $strongService->authenticateByUserId($userId, $password);
            $afterHash = (string)InterfaceDB::fetchColumn(
                'SELECT password_hash
                 FROM users
                 WHERE id = :id',
                ['id' => $userId]
            );

            $harness->assertTrue(is_array($authenticated));
            $harness->assertTrue($beforeHash !== '');
            $harness->assertTrue($afterHash !== '');
            $harness->assertTrue($beforeHash !== $afterHash);
        });
    });

    $harness->check(UserAuthenticationService::class, 'authenticates an active user by email address', static function () use ($harness, $service, $withTemporaryUser): void {
        $password = 'Auth Test Password 3!';

        $withTemporaryUser($service, $password, static function (int $userId, string $_displayName, string $emailAddress) use ($harness, $service, $password): void {
            $authenticated = $service->authenticateByEmailAddress($emailAddress, $password);

            $harness->assertTrue(is_array($authenticated));
            $harness->assertSame($userId, (int)$authenticated['id']);
            $harness->assertSame($emailAddress, (string)($authenticated['email_address'] ?? ''));
        });
    });

    $harness->check(UserAuthenticationService::class, 'returns false for an unknown email address', static function () use ($harness, $service): void {
        $harness->assertSame(false, $service->authenticateByEmailAddress('missing@example.test', 'Password 1!'));
    });

    $harness->check(UserAuthenticationService::class, 'updates a user profile and password', static function () use ($harness, $service, $withTemporaryUser): void {
        $withTemporaryUser($service, 'Original Password 1!', static function (int $userId) use ($harness, $service): void {
            $result = $service->updateUser(
                $userId,
                'Updated Display Name',
                'updated-user@example.test',
                'Updated Password 1!',
                true,
                false
            );

            $harness->assertTrue($result['success'] === true);
            $harness->assertTrue(is_array($result['user']));
            $harness->assertSame('Updated Display Name', (string)$result['user']['display_name']);
            $harness->assertSame('updated-user@example.test', (string)$result['user']['email_address']);
            $harness->assertSame(1, (int)$result['user']['confirmed_director']);
            $harness->assertSame(0, (int)$result['user']['is_active']);

            $storedHash = (string)InterfaceDB::fetchColumn(
                'SELECT password_hash
                 FROM users
                 WHERE id = :id',
                ['id' => $userId]
            );

            $harness->assertTrue($service->verifyPassword('Updated Password 1!', $storedHash));
            $harness->assertTrue(!$service->verifyPassword('Original Password 1!', $storedHash));
        });
    });

    $harness->check(UserAuthenticationService::class, 'removeUser soft-deactivates the selected user', static function () use ($harness, $service, $withTemporaryUser): void {
        $withTemporaryUser($service, 'Remove Password 1!', static function (int $userId, string $_displayName, string $emailAddress) use ($harness, $service): void {
            $result = $service->removeUser($userId);

            $harness->assertTrue($result['success'] === true);
            $harness->assertSame($userId, (int)$result['user_id']);
            $harness->assertSame(
                0,
                (int)InterfaceDB::fetchColumn(
                    'SELECT is_active
                     FROM users
                     WHERE id = :id',
                    ['id' => $userId]
                )
            );
            $harness->assertSame(false, $service->authenticateByUserId($userId, 'Remove Password 1!'));
            $harness->assertSame(false, $service->authenticateByEmailAddress($emailAddress, 'Remove Password 1!'));
        });
    });
});
