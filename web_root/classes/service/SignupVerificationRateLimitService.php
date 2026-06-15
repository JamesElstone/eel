<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SignupVerificationRateLimitService
{
    private const TABLE_NAME = 'signup_verification_rate_limits';
    private const FAILURE_THRESHOLD = 5;
    private const WINDOW_SECONDS = 120;
    private const BLOCK_SECONDS = 900;

    public function isBlocked(RequestFramework $request, SessionAuthenticationService $session): bool
    {
        if (!InterfaceDB::tableExists(self::TABLE_NAME)) {
            return false;
        }

        foreach ($this->scopes($request, $session) as $scope) {
            $this->expireScopeBlock((string)$scope['scope_type'], (string)$scope['scope_key']);
            $row = $this->rowForScope((string)$scope['scope_type'], (string)$scope['scope_key']);
            if ($row === null) {
                continue;
            }

            $blockExpiresAt = trim((string)($row['block_expires_at'] ?? ''));
            if ($blockExpiresAt !== '' && $this->secondsUntil($blockExpiresAt, new DateTimeImmutable('now')) > 0) {
                return true;
            }
        }

        return false;
    }

    public function recordFailedVerification(RequestFramework $request, SessionAuthenticationService $session): array
    {
        if (!InterfaceDB::tableExists(self::TABLE_NAME)) {
            return [];
        }

        $statuses = [];
        foreach ($this->scopes($request, $session) as $scope) {
            $statuses[] = $this->recordFailedScope(
                (string)$scope['scope_type'],
                (string)$scope['scope_key'],
                (string)$scope['scope_label']
            );
        }

        return $statuses;
    }

    public function clearCurrent(RequestFramework $request, SessionAuthenticationService $session): int
    {
        if (!InterfaceDB::tableExists(self::TABLE_NAME)) {
            return 0;
        }

        $clearedRows = 0;
        foreach ($this->scopes($request, $session) as $scope) {
            $clearedRows += $this->clearBlock((string)$scope['scope_type'], (string)$scope['scope_key']);
        }

        return $clearedRows;
    }

    public function activeBlocks(): array
    {
        if (!InterfaceDB::tableExists(self::TABLE_NAME)) {
            return [];
        }

        $this->expireBlocks();

        return InterfaceDB::fetchAll(
            'SELECT scope_type,
                    scope_key,
                    scope_label,
                    failed_attempts,
                    window_started_at,
                    last_failed_at,
                    blocked_at,
                    block_expires_at
             FROM signup_verification_rate_limits
             WHERE block_expires_at IS NOT NULL
               AND block_expires_at > CURRENT_TIMESTAMP
             ORDER BY block_expires_at DESC, last_failed_at DESC'
        );
    }

    public function clearBlock(string $scopeType, string $scopeKey): int
    {
        $scopeType = $this->normaliseScopeType($scopeType);
        $scopeKey = trim($scopeKey);
        if ($scopeType === '' || $scopeKey === '' || !InterfaceDB::tableExists(self::TABLE_NAME)) {
            return 0;
        }

        return InterfaceDB::execute(
            'DELETE FROM signup_verification_rate_limits
             WHERE scope_type = :scope_type
               AND scope_key = :scope_key',
            [
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
            ]
        );
    }

    public function clientIp(RequestFramework $request): string
    {
        return (new ReverseProxyService())->clientIpAddress($request);
    }

    public function sessionScopeKey(SessionAuthenticationService $session): string
    {
        $session->startSession();
        $sessionId = session_id();

        return $sessionId !== '' ? hash('sha256', $sessionId) : '';
    }

    public function scopes(RequestFramework $request, SessionAuthenticationService $session): array
    {
        $scopes = [];

        $clientIp = $this->clientIp($request);
        if ($clientIp !== '') {
            $scopes[] = [
                'scope_type' => 'ip',
                'scope_key' => $clientIp,
                'scope_label' => $clientIp,
            ];
        }

        $sessionKey = $this->sessionScopeKey($session);
        if ($sessionKey !== '') {
            $scopes[] = [
                'scope_type' => 'session',
                'scope_key' => $sessionKey,
                'scope_label' => 'session:' . substr($sessionKey, 0, 12),
            ];
        }

        return $scopes;
    }

    private function recordFailedScope(string $scopeType, string $scopeKey, string $scopeLabel): array
    {
        $scopeType = $this->normaliseScopeType($scopeType);
        $scopeKey = trim($scopeKey);
        $scopeLabel = mb_substr(trim($scopeLabel), 0, 80);
        if ($scopeType === '' || $scopeKey === '') {
            return $this->emptyStatus($scopeType, $scopeKey);
        }

        $now = new DateTimeImmutable('now');
        $this->expireScopeBlock($scopeType, $scopeKey);
        $existing = $this->rowForScope($scopeType, $scopeKey);
        $windowStartedAt = $this->currentWindowStartedAt($existing, $now);
        $attempts = $this->withinWindow($windowStartedAt, $now)
            ? max(0, (int)($existing['failed_attempts'] ?? 0)) + 1
            : 1;

        if (!$this->withinWindow($windowStartedAt, $now)) {
            $windowStartedAt = $now->format('Y-m-d H:i:s');
        }

        $blockedAt = null;
        $blockExpiresAt = null;
        if ($attempts >= self::FAILURE_THRESHOLD) {
            $blockedAt = $now->format('Y-m-d H:i:s');
            $blockExpiresAt = $now->modify('+' . self::BLOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s');
        }

        $params = [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'scope_label' => $scopeLabel !== '' ? $scopeLabel : $scopeType,
            'failed_attempts' => $attempts,
            'window_started_at' => $windowStartedAt,
            'last_failed_at' => $now->format('Y-m-d H:i:s'),
            'blocked_at' => $blockedAt,
            'block_expires_at' => $blockExpiresAt,
        ];

        if ($existing === null) {
            $idColumnsSql = '';
            $idValuesSql = '';
            if (InterfaceDB::driverName() === 'sqlite') {
                $params['id'] = $this->nextId();
                $idColumnsSql = 'id,';
                $idValuesSql = ':id,';
            }

            InterfaceDB::prepareExecute(
                'INSERT INTO signup_verification_rate_limits (
                    ' . $idColumnsSql . '
                    scope_type,
                    scope_key,
                    scope_label,
                    failed_attempts,
                    window_started_at,
                    last_failed_at,
                    blocked_at,
                    block_expires_at
                ) VALUES (
                    ' . $idValuesSql . '
                    :scope_type,
                    :scope_key,
                    :scope_label,
                    :failed_attempts,
                    :window_started_at,
                    :last_failed_at,
                    :blocked_at,
                    :block_expires_at
                )',
                $params
            );
        } else {
            InterfaceDB::prepareExecute(
                'UPDATE signup_verification_rate_limits
                 SET scope_label = :scope_label,
                     failed_attempts = :failed_attempts,
                     window_started_at = :window_started_at,
                     last_failed_at = :last_failed_at,
                     blocked_at = :blocked_at,
                     block_expires_at = :block_expires_at
                 WHERE scope_type = :scope_type
                   AND scope_key = :scope_key',
                $params
            );
        }

        return $this->statusForScope($scopeType, $scopeKey);
    }

    private function currentWindowStartedAt(?array $row, DateTimeImmutable $now): string
    {
        $windowStartedAt = trim((string)($row['window_started_at'] ?? ''));

        return $windowStartedAt !== '' ? $windowStartedAt : $now->format('Y-m-d H:i:s');
    }

    private function withinWindow(string $windowStartedAt, DateTimeImmutable $now): bool
    {
        $startedAt = HelperFramework::parseDateTimeValue($windowStartedAt);
        if (!$startedAt instanceof DateTimeImmutable) {
            return false;
        }

        return $startedAt->getTimestamp() >= $now->modify('-' . self::WINDOW_SECONDS . ' seconds')->getTimestamp();
    }

    private function statusForScope(string $scopeType, string $scopeKey): array
    {
        $row = $this->rowForScope($scopeType, $scopeKey);
        if ($row === null) {
            return $this->emptyStatus($scopeType, $scopeKey);
        }

        return [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
            'is_blocked' => trim((string)($row['block_expires_at'] ?? '')) !== ''
                && $this->secondsUntil((string)$row['block_expires_at'], new DateTimeImmutable('now')) > 0,
            'block_expires_at' => (string)($row['block_expires_at'] ?? ''),
        ];
    }

    private function emptyStatus(string $scopeType, string $scopeKey): array
    {
        return [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'failed_attempts' => 0,
            'is_blocked' => false,
            'block_expires_at' => '',
        ];
    }

    private function rowForScope(string $scopeType, string $scopeKey): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT *
             FROM signup_verification_rate_limits
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

    private function expireScopeBlock(string $scopeType, string $scopeKey): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM signup_verification_rate_limits
             WHERE scope_type = :scope_type
               AND scope_key = :scope_key
               AND block_expires_at IS NOT NULL
               AND block_expires_at <= CURRENT_TIMESTAMP',
            [
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
            ]
        );
    }

    private function expireBlocks(): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM signup_verification_rate_limits
             WHERE block_expires_at IS NOT NULL
               AND block_expires_at <= CURRENT_TIMESTAMP'
        );
    }

    private function secondsUntil(string $dateTime, DateTimeImmutable $now): int
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '') {
            return 0;
        }

        $target = HelperFramework::parseDateTimeValue($dateTime);
        if (!$target instanceof DateTimeImmutable) {
            return 0;
        }

        return max(0, $target->getTimestamp() - $now->getTimestamp());
    }

    private function normaliseScopeType(string $scopeType): string
    {
        $scopeType = strtolower(trim($scopeType));

        return in_array($scopeType, ['ip', 'session'], true) ? $scopeType : '';
    }

    private function nextId(): int
    {
        return max(1, (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) + 1 FROM signup_verification_rate_limits'));
    }
}
