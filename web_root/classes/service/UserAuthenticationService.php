<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UserAuthenticationService
{
    private const DEFAULT_PASSWORD_OPTIONS = [
        'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
        'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
        'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
    ];
    private const PASSWORD_MIN_LENGTH = 12;
    private const LOGIN_THROTTLE_THRESHOLD = 3;
    private const LOGIN_THROTTLE_SECONDS = 30;
    private const LOGIN_LOCK_THRESHOLD = 20;
    private const LOGIN_LOCK_SECONDS = 900;
    private const LOGIN_IP_LOCK_THRESHOLD = 10;
    private const LOGIN_DEVICE_LOCK_THRESHOLD = 10;
    private const RATE_LIMIT_SCOPE_EMAIL = 'email';
    private const RATE_LIMIT_SCOPE_IP = 'ip';
    private const RATE_LIMIT_SCOPE_DEVICE = 'device';

    /** @var array<int, array|null> */
    private static array $userByIdCache = [];
    private static ?bool $singleEmailRateLimitRowEnforced = null;
    private ?string $pepper = null;

    public function __construct(
        private readonly ?string $securityKeysPath = null,
        private readonly array $passwordOptions = self::DEFAULT_PASSWORD_OPTIONS,
    ) {
    }

    public function hashPassword(string $password): string
    {
        $this->assertPasswordProvided($password);
        $this->assertPasswordMeetsPolicy($password);

        $hash = password_hash(
            $this->pepperedPassword($password),
            PASSWORD_ARGON2ID,
            $this->resolvedPasswordOptions()
        );

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Password hash could not be generated.');
        }

        return $hash;
    }

    public function verifyPassword(string $password, string $storedHash): bool
    {
        $storedHash = trim($storedHash);

        if ($storedHash === '') {
            return false;
        }

        return password_verify($this->pepperedPassword($password), $storedHash);
    }

    public function passwordNeedsRehash(string $storedHash): bool
    {
        $storedHash = trim($storedHash);

        if ($storedHash === '') {
            return true;
        }

        return password_needs_rehash(
            $storedHash,
            PASSWORD_ARGON2ID,
            $this->resolvedPasswordOptions()
        );
    }

    public static function passwordPolicyDescription(): string
    {
        return 'At least 12 characters, with at least 1 uppercase letter, 1 lowercase letter, 1 number, and 1 symbol.';
    }

    public function updatePasswordHash(int $userId, string $password): string
    {
        $user = $this->loadUserById($userId);

        if ($user === null) {
            throw new RuntimeException('Active user was not found.');
        }

        $hash = $this->hashPassword($password);

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET password_hash = :password_hash,
                 password_changed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $userId,
                'password_hash' => $hash,
            ]
        );

        self::invalidateUserByIdCache($userId);

        return $hash;
    }

    public function createUser(
        string $displayName,
        string $emailAddress,
        string $password,
        bool $confirmedDirector = false,
        bool $isActive = true
    ): array {
        $input = $this->normaliseUserInput($displayName, $emailAddress, $confirmedDirector, $isActive);
        $errors = $this->validateCreateInput($input, $password);

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'user_id' => 0,
                'user' => null,
            ];
        }

        $hash = $this->hashPassword($password);

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
                :confirmed_director,
                :is_active
            )',
            [
                'display_name' => $input['display_name'],
                'email_address' => $input['email_address'],
                'password_hash' => $hash,
                'confirmed_director' => $input['confirmed_director'],
                'is_active' => $input['is_active'],
            ]
        );

        $user = $this->loadUserByEmailAddress($input['email_address']);

        if ($user === null) {
            return [
                'success' => false,
                'errors' => ['The user was created but could not be reloaded.'],
                'user_id' => 0,
                'user' => null,
            ];
        }

        unset($user['password_hash']);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => (int)$user['id'],
            'user' => $user,
        ];
    }

    public function hasAnyUsers(): bool
    {
        return InterfaceDB::tableRowCount('users') > 0;
    }

    public function createInitialUser(
        string $displayName,
        string $emailAddress,
        string $password
    ): array {
        if ($this->hasAnyUsers()) {
            return [
                'success' => false,
                'errors' => ['Initial account setup is no longer available. Please sign in.'],
                'user_id' => 0,
                'user' => null,
            ];
        }

        return $this->createUser($displayName, $emailAddress, $password, false, true);
    }

    public function updateUser(
        int $userId,
        string $displayName,
        string $emailAddress,
        ?string $password = null,
        ?bool $confirmedDirector = null,
        ?bool $isActive = null
    ): array {
        $existingUser = $this->loadUserById($userId);

        if ($existingUser === null) {
            return [
                'success' => false,
                'errors' => ['The selected user could not be found.'],
                'user_id' => $userId,
                'user' => null,
            ];
        }

        $input = $this->normaliseUserInput(
            $displayName,
            $emailAddress,
            $confirmedDirector ?? ((int)($existingUser['confirmed_director'] ?? 0) === 1),
            $isActive ?? ((int)($existingUser['is_active'] ?? 0) === 1)
        );
        $errors = $this->validateUpdateInput($userId, $input, $password);

        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => $errors,
                'user_id' => $userId,
                'user' => null,
            ];
        }

        $params = [
            'id' => $userId,
            'display_name' => $input['display_name'],
            'email_address' => $input['email_address'],
            'confirmed_director' => $input['confirmed_director'],
            'is_active' => $input['is_active'],
        ];
        $passwordSql = '';

        if ($password !== null && $password !== '') {
            $params['password_hash'] = $this->hashPassword($password);
            $passwordSql = ',
                 password_hash = :password_hash,
                 password_changed_at = CURRENT_TIMESTAMP';
        }

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET display_name = :display_name,
                 email_address = :email_address,
                 confirmed_director = :confirmed_director,
                 is_active = :is_active' . $passwordSql . ',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            $params
        );

        self::invalidateUserByIdCache($userId);

        $user = $this->loadUserById($userId);

        if ($user === null) {
            return [
                'success' => false,
                'errors' => ['The user was updated but could not be reloaded.'],
                'user_id' => $userId,
                'user' => null,
            ];
        }

        unset($user['password_hash']);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
            'user' => $user,
        ];
    }

    public function removeUser(int $userId): array {
        $user = $this->loadUserById($userId);

        if ($user === null) {
            return [
                'success' => false,
                'errors' => ['The selected user could not be found.'],
                'user_id' => $userId,
            ];
        }

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET is_active = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['id' => $userId]
        );

        self::invalidateUserByIdCache($userId);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
        ];
    }

    public function authenticateByUserId(int $userId, string $password): array|false
    {
        $user = $this->loadActiveUserById($userId);

        if ($user === null) {
            return false;
        }

        return $this->authenticateLoadedUser($user, $password);
    }

    public function authenticateByEmailAddress(string $emailAddress, string $password): array|false
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);

        if ($emailAddress === '') {
            return false;
        }

        $users = InterfaceDB::fetchAll(
            'SELECT ' . $this->userSelectColumns() . '
             FROM users
             WHERE is_active = 1
               AND email_address = :email_address
             ORDER BY id ASC',
            ['email_address' => $emailAddress]
        );

        if ($users === []) {
            return false;
        }

        if (count($users) > 1) {
            throw new RuntimeException('Multiple active users share the same email address.');
        }

        return $this->authenticateLoadedUser($users[0], $password);
    }

    public function loginRateLimitStatus(string $emailAddress, ?string $deviceId = null): array
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);
        if ($emailAddress === '') {
            return $this->emptyLoginRateLimitStatus($emailAddress);
        }

        $scopes = $this->rateLimitScopes($emailAddress, $deviceId);
        $statuses = [];

        foreach ($scopes as $scope) {
            $this->expireScopedLoginRateLimit((string)$scope['scope_type'], (string)$scope['scope_key']);
            $row = $this->loadScopedLoginRateLimitRow((string)$scope['scope_type'], (string)$scope['scope_key']);
            if ($row !== null) {
                $statuses[] = $this->buildStatusFromRow($emailAddress, $row, (string)$scope['scope_type']);
            }
        }

        return $this->mergeRateLimitStatuses($emailAddress, $statuses);
    }

    public function clearLoginRateLimit(string $emailAddress, ?string $deviceId = null): void
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);
        if ($emailAddress === '') {
            return;
        }

        foreach ($this->rateLimitScopes($emailAddress, $deviceId) as $scope) {
            InterfaceDB::prepareExecute(
                'DELETE FROM user_login_rate_limits
                 WHERE scope_type = :scope_type
                   AND scope_key = :scope_key',
                [
                    'scope_type' => (string)$scope['scope_type'],
                    'scope_key' => (string)$scope['scope_key'],
                ]
            );
        }
    }

    public function recordFailedPasswordAttempt(string $emailAddress, ?string $deviceId = null): array
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);
        if ($emailAddress === '') {
            return $this->emptyLoginRateLimitStatus($emailAddress);
        }

        return $this->recordFailedScopedPasswordAttempt($emailAddress, $deviceId);
    }

    public function clearExpiredLoginRateLimit(string $emailAddress, ?string $deviceId = null): void
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);
        if ($emailAddress === '') {
            return;
        }

        foreach ($this->rateLimitScopes($emailAddress, $deviceId) as $scope) {
            $this->expireScopedLoginRateLimit((string)$scope['scope_type'], (string)$scope['scope_key']);
        }
    }

    private function recordFailedScopedPasswordAttempt(string $emailAddress, ?string $deviceId = null): array
    {
        $now = new DateTimeImmutable('now');
        $user = $this->loadUserByEmailAddress($emailAddress);
        $userId = $user === null ? null : (int)($user['id'] ?? 0);

        foreach ($this->rateLimitScopes($emailAddress, $deviceId) as $scope) {
            $scopeType = (string)$scope['scope_type'];
            $scopeKey = (string)$scope['scope_key'];
            $this->expireScopedLoginRateLimit($scopeType, $scopeKey);
            $existing = $this->loadScopedLoginRateLimitRow($scopeType, $scopeKey);
            $attempts = max(0, (int)($existing['consecutive_failed_password_attempts'] ?? 0)) + 1;
            $windowStartedAt = trim((string)($existing['failed_attempt_window_started_at'] ?? ''));
            if ($windowStartedAt === '') {
                $windowStartedAt = $now->format('Y-m-d H:i:s');
            }

            $nextAllowedLoginAt = null;
            if ($attempts >= self::LOGIN_THROTTLE_THRESHOLD) {
                $nextAllowedLoginAt = $now->modify('+' . self::LOGIN_THROTTLE_SECONDS . ' seconds')->format('Y-m-d H:i:s');
            }

            $lockedAt = null;
            $lockReason = null;
            $lockExpiresAt = null;
            $lockThreshold = $this->lockThresholdForScope($scopeType);
            if ($attempts >= $lockThreshold) {
                $lockedAt = $now->format('Y-m-d H:i:s');
                $lockExpiresAt = $now->modify('+' . self::LOGIN_LOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s');
                $lockReason = 'password_failures_' . $scopeType;
                $nextAllowedLoginAt = null;
            }

            $params = [
                'email_address' => $emailAddress,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'user_id' => $userId !== null && $userId > 0 ? $userId : null,
                'consecutive_failed_password_attempts' => $attempts,
                'failed_attempt_window_started_at' => $windowStartedAt,
                'last_failed_password_attempt_at' => $now->format('Y-m-d H:i:s'),
                'next_allowed_login_at' => $nextAllowedLoginAt,
                'locked_at' => $lockedAt,
                'lock_reason' => $lockReason,
                'lock_expires_at' => $lockExpiresAt,
            ];

            if ($existing === null) {
                InterfaceDB::prepareExecute(
                    'INSERT INTO user_login_rate_limits (
                        email_address,
                        scope_type,
                        scope_key,
                        user_id,
                        consecutive_failed_password_attempts,
                        failed_attempt_window_started_at,
                        last_failed_password_attempt_at,
                        next_allowed_login_at,
                        locked_at,
                        lock_reason,
                        lock_expires_at
                    ) VALUES (
                        :email_address,
                        :scope_type,
                        :scope_key,
                        :user_id,
                        :consecutive_failed_password_attempts,
                        :failed_attempt_window_started_at,
                        :last_failed_password_attempt_at,
                        :next_allowed_login_at,
                        :locked_at,
                        :lock_reason,
                        :lock_expires_at
                    )',
                    $params
                );
            } else {
                InterfaceDB::prepareExecute(
                    'UPDATE user_login_rate_limits
                     SET email_address = :email_address,
                         user_id = :user_id,
                         consecutive_failed_password_attempts = :consecutive_failed_password_attempts,
                         failed_attempt_window_started_at = :failed_attempt_window_started_at,
                         last_failed_password_attempt_at = :last_failed_password_attempt_at,
                         next_allowed_login_at = :next_allowed_login_at,
                         locked_at = :locked_at,
                         lock_reason = :lock_reason,
                         lock_expires_at = :lock_expires_at
                     WHERE scope_type = :scope_type
                       AND scope_key = :scope_key',
                    $params
                );
            }
        }

        return $this->loginRateLimitStatus($emailAddress, $deviceId);
    }

    public function userById(int $userId): ?array
    {
        $user = $this->loadUserById($userId);

        if ($user === null) {
            return null;
        }

        unset($user['password_hash']);

        return $user;
    }

    public static function primeUserByIdCache(array $user): void
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        self::$userByIdCache[$userId] = $user;
    }

    public static function forgetUserByIdCache(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        self::invalidateUserByIdCache($userId);
    }

    public function listUsers(): array
    {
        $users = InterfaceDB::fetchAll(
            'SELECT ' . $this->userSelectColumns() . '
             FROM users
             ORDER BY display_name ASC, id ASC'
        );

        foreach ($users as &$user) {
            unset($user['password_hash']);
        }
        unset($user);

        return $users;
    }

    public function setUserActive(int $userId, bool $isActive): array
    {
        $existingUser = $this->loadUserById($userId);

        if ($existingUser === null) {
            return [
                'success' => false,
                'errors' => ['The selected user could not be found.'],
                'user_id' => $userId,
                'user' => null,
            ];
        }

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET is_active = :is_active,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $userId,
                'is_active' => $isActive ? 1 : 0,
            ]
        );

        self::invalidateUserByIdCache($userId);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
            'user' => $this->userById($userId),
        ];
    }

    public function setPasswordDirectly(int $userId, string $password): array
    {
        $existingUser = $this->loadUserById($userId);

        if ($existingUser === null) {
            return [
                'success' => false,
                'errors' => ['The selected user could not be found.'],
                'user_id' => $userId,
                'user' => null,
            ];
        }

        if (trim($password) === '') {
            return [
                'success' => false,
                'errors' => ['Password is required.'],
                'user_id' => $userId,
                'user' => null,
            ];
        }

        $passwordErrors = $this->validatePasswordPolicy($password);
        if ($passwordErrors !== []) {
            return [
                'success' => false,
                'errors' => $passwordErrors,
                'user_id' => $userId,
                'user' => null,
            ];
        }

        $this->updatePasswordHash($userId, $password);

        return [
            'success' => true,
            'errors' => [],
            'user_id' => $userId,
            'user' => $this->userById($userId),
        ];
    }

    private function authenticateLoadedUser(array $user, string $password): array|false
    {
        $storedHash = (string)($user['password_hash'] ?? '');

        if (!$this->verifyPassword($password, $storedHash)) {
            return false;
        }

        if ($this->passwordNeedsRehash($storedHash)) {
            $user['password_hash'] = $this->updatePasswordHash((int)$user['id'], $password);
        }

        unset($user['password_hash']);

        return $user;
    }

    private function loadActiveUserById(int $userId): ?array
    {
        $user = $this->loadUserById($userId);

        return is_array($user) && (int)($user['is_active'] ?? 0) === 1 ? $user : null;
    }

    private function loadUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, self::$userByIdCache)) {
            return self::$userByIdCache[$userId];
        }

        $user = InterfaceDB::fetchOne(
            'SELECT ' . $this->userSelectColumns() . '
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        );

        self::$userByIdCache[$userId] = is_array($user) ? $user : null;

        return self::$userByIdCache[$userId];
    }

    private function loadUserByEmailAddress(string $emailAddress): ?array
    {
        $emailAddress = $this->normaliseEmailAddress($emailAddress);

        if ($emailAddress === '') {
            return null;
        }

        $user = InterfaceDB::fetchOne(
            'SELECT ' . $this->userSelectColumns() . '
             FROM users
             WHERE email_address = :email_address
             ORDER BY id DESC
             LIMIT 1',
            ['email_address' => $emailAddress]
        );

        return is_array($user) ? $user : null;
    }

    private function loadScopedLoginRateLimitRow(string $scopeType, string $scopeKey): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id,
                    email_address,
                    scope_type,
                    scope_key,
                    user_id,
                    consecutive_failed_password_attempts,
                    failed_attempt_window_started_at,
                    last_failed_password_attempt_at,
                    next_allowed_login_at,
                    locked_at,
                    lock_reason,
                    lock_expires_at
             FROM user_login_rate_limits
             WHERE scope_type = :scope_type
               AND scope_key = :scope_key
             LIMIT 1',
            [
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function userSelectColumns(): string
    {
        return implode(', ', [
            'id',
            'display_name',
            'email_address',
            'password_hash',
            'confirmed_director',
            'is_active',
            'role_id',
            'current_session_token_hash',
            'current_session_started_at',
            'current_session_last_seen_at',
            'current_session_device_id',
            'current_session_ip_address',
            'current_session_user_agent',
            'current_session_browser_label',
            'last_login_at',
            'password_changed_at',
            'created_at',
            'updated_at',
        ]);
    }

    private function pepperedPassword(string $password): string
    {
        return hash_hmac('sha256', $password, $this->pepper());
    }

    private function pepper(): string
    {
        if ($this->pepper !== null) {
            return $this->pepper;
        }

        $this->pepper = SecurityStore::ensureFact('pepper', $this->securityKeysPath);

        return $this->pepper;
    }

    private function resolvedPasswordOptions(): array
    {
        return array_replace(self::DEFAULT_PASSWORD_OPTIONS, $this->passwordOptions);
    }

    private function normaliseEmailAddress(string $emailAddress): string
    {
        return strtolower(trim($emailAddress));
    }

    private function emptyLoginRateLimitStatus(string $emailAddress): array
    {
        return [
            'email_address' => $emailAddress,
            'user_id' => 0,
            'consecutive_failed_password_attempts' => 0,
            'retry_after_seconds' => 0,
            'throttle_threshold' => self::LOGIN_THROTTLE_THRESHOLD,
            'throttle_seconds' => self::LOGIN_THROTTLE_SECONDS,
            'lock_threshold' => self::LOGIN_LOCK_THRESHOLD,
            'is_throttled' => false,
            'is_locked' => false,
            'locked_at' => '',
            'lock_reason' => '',
            'lock_expires_at' => '',
            'triggered_scope' => '',
        ];
    }

    private function buildStatusFromRow(string $emailAddress, array $row, string $scopeType): array
    {
        $now = new DateTimeImmutable('now');
        $retryAfter = $this->secondsUntil((string)($row['next_allowed_login_at'] ?? ''), $now);
        $lockedAt = trim((string)($row['locked_at'] ?? ''));
        $lockExpiresAt = trim((string)($row['lock_expires_at'] ?? ''));

        return [
            'email_address' => $emailAddress,
            'user_id' => max(0, (int)($row['user_id'] ?? 0)),
            'consecutive_failed_password_attempts' => max(0, (int)($row['consecutive_failed_password_attempts'] ?? 0)),
            'retry_after_seconds' => $retryAfter,
            'throttle_threshold' => self::LOGIN_THROTTLE_THRESHOLD,
            'throttle_seconds' => self::LOGIN_THROTTLE_SECONDS,
            'lock_threshold' => $this->lockThresholdForScope($scopeType),
            'is_throttled' => $retryAfter > 0,
            'is_locked' => $lockedAt !== '',
            'locked_at' => $lockedAt,
            'lock_reason' => trim((string)($row['lock_reason'] ?? '')),
            'lock_expires_at' => $lockExpiresAt,
            'triggered_scope' => $scopeType,
        ];
    }

    private function mergeRateLimitStatuses(string $emailAddress, array $statuses): array
    {
        if ($statuses === []) {
            return $this->emptyLoginRateLimitStatus($emailAddress);
        }

        usort($statuses, static function (array $left, array $right): int {
            return self::statusSeverity($right) <=> self::statusSeverity($left);
        });

        $primary = $statuses[0];
        $primary['consecutive_failed_password_attempts'] = max(array_map(
            static fn(array $status): int => (int)($status['consecutive_failed_password_attempts'] ?? 0),
            $statuses
        ));

        return $primary;
    }

    private static function statusSeverity(array $status): int
    {
        if (!empty($status['is_locked'])) {
            return 3;
        }
        if (!empty($status['is_throttled'])) {
            return 2;
        }
        if ((int)($status['consecutive_failed_password_attempts'] ?? 0) > 0) {
            return 1;
        }

        return 0;
    }

    private function rateLimitScopes(string $emailAddress, ?string $deviceId = null): array
    {
        if ($this->usesSingleEmailRateLimitRow()) {
            return [[
                'scope_type' => self::RATE_LIMIT_SCOPE_EMAIL,
                'scope_key' => $emailAddress,
            ]];
        }

        $metadata = (new UserSessionService())->buildRequestMetadata($deviceId);
        $scopes = [[
            'scope_type' => self::RATE_LIMIT_SCOPE_EMAIL,
            'scope_key' => $emailAddress,
        ]];

        $ipAddress = trim((string)($metadata['ip_address'] ?? ''));
        if ($ipAddress !== '') {
            $scopes[] = [
                'scope_type' => self::RATE_LIMIT_SCOPE_IP,
                'scope_key' => $ipAddress,
            ];
        }

        $resolvedDeviceId = trim((string)($metadata['device_id'] ?? ''));
        if ($resolvedDeviceId !== '') {
            $scopes[] = [
                'scope_type' => self::RATE_LIMIT_SCOPE_DEVICE,
                'scope_key' => $resolvedDeviceId,
            ];
        }

        return $scopes;
    }

    private function usesSingleEmailRateLimitRow(): bool
    {
        if (self::$singleEmailRateLimitRowEnforced !== null) {
            return self::$singleEmailRateLimitRowEnforced;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT s.index_name
             FROM information_schema.statistics s
             WHERE s.table_schema = DATABASE()
               AND s.table_name = :table_name
               AND s.non_unique = 0
             GROUP BY s.index_name
             HAVING COUNT(*) = 1
                AND SUM(CASE WHEN s.column_name = :column_name THEN 1 ELSE 0 END) = 1
             LIMIT 1',
            [
                'table_name' => 'user_login_rate_limits',
                'column_name' => 'email_address',
            ]
        );

        self::$singleEmailRateLimitRowEnforced = is_array($row);

        return self::$singleEmailRateLimitRowEnforced;
    }

    private function lockThresholdForScope(string $scopeType): int
    {
        return match ($scopeType) {
            self::RATE_LIMIT_SCOPE_IP => self::LOGIN_IP_LOCK_THRESHOLD,
            self::RATE_LIMIT_SCOPE_DEVICE => self::LOGIN_DEVICE_LOCK_THRESHOLD,
            default => self::LOGIN_LOCK_THRESHOLD,
        };
    }


    private function expireScopedLoginRateLimit(string $scopeType, string $scopeKey): void
    {
        $row = $this->loadScopedLoginRateLimitRow($scopeType, $scopeKey);
        if ($row === null) {
            return;
        }

        $lockExpiresAt = trim((string)($row['lock_expires_at'] ?? ''));
        if ($lockExpiresAt !== '' && $this->secondsUntil($lockExpiresAt, new DateTimeImmutable('now')) === 0) {
            InterfaceDB::prepareExecute(
                'DELETE FROM user_login_rate_limits
                 WHERE scope_type = :scope_type
                   AND scope_key = :scope_key',
                [
                    'scope_type' => $scopeType,
                    'scope_key' => $scopeKey,
                ]
            );
        }
    }

    private function secondsUntil(string $dateTime, DateTimeImmutable $now): int
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '') {
            return 0;
        }

        try {
            $target = new DateTimeImmutable($dateTime);
        } catch (Throwable) {
            return 0;
        }

        return max(0, $target->getTimestamp() - $now->getTimestamp());
    }

    private function normaliseUserInput(
        string $displayName,
        string $emailAddress,
        bool $confirmedDirector,
        bool $isActive
    ): array {
        return [
            'display_name' => trim($displayName),
            'email_address' => $this->normaliseEmailAddress($emailAddress),
            'confirmed_director' => $confirmedDirector ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
        ];
    }

    private function validateCreateInput(array $input, string $password): array
    {
        $errors = $this->validateSharedUserInput($input);

        if ($password === '') {
            $errors[] = 'Password is required.';
        } else {
            $errors = array_merge($errors, $this->validatePasswordPolicy($password));
        }

        if ($this->loadUserByEmailAddress((string)$input['email_address']) !== null) {
            $errors[] = 'A user with that email address already exists.';
        }

        return $errors;
    }

    private function validateUpdateInput(int $userId, array $input, ?string $password): array
    {
        $errors = $this->validateSharedUserInput($input);
        $existingUser = $this->loadUserByEmailAddress((string)$input['email_address']);

        if ($existingUser !== null && (int)$existingUser['id'] !== $userId) {
            $errors[] = 'A user with that email address already exists.';
        }

        if ($password !== null && $password === '') {
            $errors[] = 'Password cannot be blank when updating a user.';
        } elseif ($password !== null) {
            $errors = array_merge($errors, $this->validatePasswordPolicy($password));
        }

        return $errors;
    }

    private function validateSharedUserInput(array $input): array
    {
        $errors = [];

        if (trim((string)$input['display_name']) === '') {
            $errors[] = 'Display name is required.';
        }

        if ((string)$input['email_address'] === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var((string)$input['email_address'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address must be valid.';
        }

        return $errors;
    }

    private function assertPasswordProvided(string $password): void
    {
        if ($password === '') {
            throw new RuntimeException('Password is required.');
        }
    }

    private function assertPasswordMeetsPolicy(string $password): void
    {
        $errors = $this->validatePasswordPolicy($password);

        if ($errors !== []) {
            throw new RuntimeException($errors[0]);
        }
    }

    private function validatePasswordPolicy(string $password): array
    {
        $errors = [];

        if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters long.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }

        if (!preg_match('/\d/', $password)) {
            $errors[] = 'Password must include at least one number.';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must include at least one symbol.';
        }

        return $errors;
    }

    private static function invalidateUserByIdCache(int $userId): void
    {
        unset(self::$userByIdCache[$userId]);
    }
}
