<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class SignupTokenRateLimitService
{
    private const TABLE_NAME = 'signup_token_rate_limits';
    private const FAILURE_THRESHOLD = 5;
    private const WINDOW_SECONDS = 120;
    private const BLOCK_SECONDS = 900;

    public function isBlocked(RequestFramework $request): bool
    {
        $clientIp = $this->clientIp($request);
        if ($clientIp === '' || !InterfaceDB::tableExists(self::TABLE_NAME)) {
            return false;
        }

        $this->expireClientBlock($clientIp);
        $row = $this->rowForClientIp($clientIp);
        if ($row === null) {
            return false;
        }

        $blockExpiresAt = trim((string)($row['block_expires_at'] ?? ''));

        return $blockExpiresAt !== '' && $this->secondsUntil($blockExpiresAt, new DateTimeImmutable('now')) > 0;
    }

    public function recordFailedToken(RequestFramework $request): array
    {
        $clientIp = $this->clientIp($request);
        if ($clientIp === '' || !InterfaceDB::tableExists(self::TABLE_NAME)) {
            return $this->emptyStatus($clientIp);
        }

        $now = new DateTimeImmutable('now');
        $this->expireClientBlock($clientIp);
        $existing = $this->rowForClientIp($clientIp);
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
            'client_ip' => $clientIp,
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
                'INSERT INTO signup_token_rate_limits (
                    ' . $idColumnsSql . '
                    client_ip,
                    failed_attempts,
                    window_started_at,
                    last_failed_at,
                    blocked_at,
                    block_expires_at
                ) VALUES (
                    ' . $idValuesSql . '
                    :client_ip,
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
                'UPDATE signup_token_rate_limits
                 SET failed_attempts = :failed_attempts,
                     window_started_at = :window_started_at,
                     last_failed_at = :last_failed_at,
                     blocked_at = :blocked_at,
                     block_expires_at = :block_expires_at
                 WHERE client_ip = :client_ip',
                $params
            );
        }

        return $this->statusForClientIp($clientIp);
    }

    public function activeBlocks(): array
    {
        if (!InterfaceDB::tableExists(self::TABLE_NAME)) {
            return [];
        }

        $this->expireBlocks();

        return InterfaceDB::fetchAll(
            'SELECT client_ip,
                    failed_attempts,
                    window_started_at,
                    last_failed_at,
                    blocked_at,
                    block_expires_at
             FROM signup_token_rate_limits
             WHERE block_expires_at IS NOT NULL
               AND block_expires_at > CURRENT_TIMESTAMP
             ORDER BY block_expires_at DESC, last_failed_at DESC'
        );
    }

    public function clearBlock(string $clientIp): int
    {
        $clientIp = trim($clientIp);
        if ($clientIp === '' || !InterfaceDB::tableExists(self::TABLE_NAME)) {
            return 0;
        }

        return InterfaceDB::execute(
            'DELETE FROM signup_token_rate_limits
             WHERE client_ip = :client_ip',
            ['client_ip' => $clientIp]
        );
    }

    public function clientIp(RequestFramework $request): string
    {
        return (new ReverseProxyService())->clientIpAddress($request);
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

    private function statusForClientIp(string $clientIp): array
    {
        $row = $this->rowForClientIp($clientIp);
        if ($row === null) {
            return $this->emptyStatus($clientIp);
        }

        return [
            'client_ip' => $clientIp,
            'failed_attempts' => (int)($row['failed_attempts'] ?? 0),
            'is_blocked' => trim((string)($row['block_expires_at'] ?? '')) !== ''
                && $this->secondsUntil((string)$row['block_expires_at'], new DateTimeImmutable('now')) > 0,
            'block_expires_at' => (string)($row['block_expires_at'] ?? ''),
        ];
    }

    private function emptyStatus(string $clientIp): array
    {
        return [
            'client_ip' => $clientIp,
            'failed_attempts' => 0,
            'is_blocked' => false,
            'block_expires_at' => '',
        ];
    }

    private function rowForClientIp(string $clientIp): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT *
             FROM signup_token_rate_limits
             WHERE client_ip = :client_ip
             LIMIT 1',
            ['client_ip' => $clientIp]
        );

        return is_array($row) ? $row : null;
    }

    private function expireClientBlock(string $clientIp): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM signup_token_rate_limits
             WHERE client_ip = :client_ip
               AND block_expires_at IS NOT NULL
               AND block_expires_at <= CURRENT_TIMESTAMP',
            ['client_ip' => $clientIp]
        );
    }

    private function expireBlocks(): void
    {
        InterfaceDB::prepareExecute(
            'DELETE FROM signup_token_rate_limits
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

    private function nextId(): int
    {
        return max(1, (int)InterfaceDB::fetchColumn('SELECT COALESCE(MAX(id), 0) + 1 FROM signup_token_rate_limits'));
    }
}
