<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UserSessionService
{
    public function __construct(
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
    ) {
    }

    public function buildRequestMetadata(?string $deviceId = null): array
    {
        $resolvedDeviceId = trim((string)$deviceId);
        if ($resolvedDeviceId === '') {
            $resolvedDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));
        }

        $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (mb_strlen($userAgent) > 1000) {
            $userAgent = mb_substr($userAgent, 0, 1000);
        }

        return [
            'device_id' => $resolvedDeviceId,
            'ip_address' => $this->currentIpAddress(),
            'user_agent' => $userAgent,
            'browser_label' => $this->browserLabelFromUserAgent($userAgent),
        ];
    }

    public function startAuthenticatedSession(int $userId, string $deviceId, ?string $attemptedEmailAddress = null): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('A valid user id is required to start an authenticated session.');
        }

        $metadata = $this->buildRequestMetadata($deviceId);
        $existingSession = $this->loadCurrentSessionRow($userId);
        $sessionTokenHash = hash('sha256', bin2hex(random_bytes(32)));

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET current_session_token_hash = :current_session_token_hash,
                 current_session_started_at = CURRENT_TIMESTAMP,
                 current_session_last_seen_at = CURRENT_TIMESTAMP,
                 current_session_device_id = :current_session_device_id,
                 current_session_ip_address = :current_session_ip_address,
                 current_session_user_agent = :current_session_user_agent,
                 current_session_browser_label = :current_session_browser_label,
                 last_login_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $userId,
                'current_session_token_hash' => $sessionTokenHash,
                'current_session_device_id' => $metadata['device_id'] !== '' ? $metadata['device_id'] : null,
                'current_session_ip_address' => $metadata['ip_address'] !== '' ? $metadata['ip_address'] : null,
                'current_session_user_agent' => $metadata['user_agent'] !== '' ? $metadata['user_agent'] : null,
                'current_session_browser_label' => $metadata['browser_label'] !== '' ? $metadata['browser_label'] : null,
            ]
        );

        if (is_array($existingSession) && trim((string)($existingSession['current_session_token_hash'] ?? '')) !== '') {
            $this->userHistoryStore->recordLogonEvent(
                $userId,
                $attemptedEmailAddress,
                'session_replaced',
                true,
                'A newer sign-in replaced this session.',
                (string)$existingSession['current_session_token_hash'],
                $this->metadataFromUserRow($existingSession)
            );
        }

        return [
            'session_token_hash' => $sessionTokenHash,
            'metadata' => $metadata,
            'replaced_session' => $this->replacementSessionDetails($existingSession),
        ];
    }

    public function validateAuthenticatedSession(int $userId, string $sessionTokenHash, string $deviceId): array
    {
        if ($userId <= 0 || trim($sessionTokenHash) === '') {
            return [
                'valid' => false,
                'logout_notice' => $this->buildLogoutNotice(null),
            ];
        }

        $row = $this->loadCurrentSessionRow($userId);

        if (!is_array($row) || (int)($row['is_active'] ?? 0) !== 1) {
            return [
                'valid' => false,
                'logout_notice' => $this->buildLogoutNotice(null),
            ];
        }

        $currentHash = trim((string)($row['current_session_token_hash'] ?? ''));
        $currentDeviceId = trim((string)($row['current_session_device_id'] ?? ''));

        if ($currentHash === '' || !hash_equals($currentHash, $sessionTokenHash) || $currentDeviceId !== trim($deviceId)) {
            return [
                'valid' => false,
                'logout_notice' => $this->buildLogoutNotice($row),
            ];
        }

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET current_session_last_seen_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND current_session_token_hash = :current_session_token_hash',
            [
                'id' => $userId,
                'current_session_token_hash' => $sessionTokenHash,
            ]
        );

        $row['current_session_last_seen_at'] = date('Y-m-d H:i:s');
        UserAuthenticationService::primeUserByIdCache($row);

        return [
            'valid' => true,
            'logout_notice' => null,
        ];
    }

    public function clearAuthenticatedSession(
        int $userId,
        string $sessionTokenHash,
        string $eventType = 'logout',
        ?string $reason = null
    ): void {
        if ($userId <= 0 || trim($sessionTokenHash) === '') {
            return;
        }

        $row = $this->loadCurrentSessionRow($userId);
        if (!is_array($row)) {
            return;
        }

        $currentHash = trim((string)($row['current_session_token_hash'] ?? ''));
        if ($currentHash === '' || !hash_equals($currentHash, $sessionTokenHash)) {
            return;
        }

        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($row['email_address'] ?? ''),
            $eventType,
            true,
            $reason ?? ($eventType === 'forced_logout'
                ? 'The session was ended because a newer sign-in became active.'
                : 'The user signed out.'),
            $sessionTokenHash,
            $this->metadataFromUserRow($row)
        );

        InterfaceDB::prepareExecute(
            'UPDATE users
             SET current_session_token_hash = NULL,
                 current_session_started_at = NULL,
                 current_session_last_seen_at = NULL,
                 current_session_device_id = NULL,
                 current_session_ip_address = NULL,
                 current_session_user_agent = NULL,
                 current_session_browser_label = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND current_session_token_hash = :current_session_token_hash',
            [
                'id' => $userId,
                'current_session_token_hash' => $sessionTokenHash,
            ]
        );
    }

    private function buildLogoutNotice(?array $currentSessionRow): array
    {
        if (is_array($currentSessionRow) && trim((string)($currentSessionRow['current_session_token_hash'] ?? '')) !== '') {
            $browser = trim((string)($currentSessionRow['current_session_browser_label'] ?? ''));
            $ipAddress = trim((string)($currentSessionRow['current_session_ip_address'] ?? ''));
            $description = 'another browser session';

            if ($browser !== '' && $ipAddress !== '') {
                $description = $browser . ' with remote IP ' . $ipAddress;
            } elseif ($browser !== '') {
                $description = $browser;
            } elseif ($ipAddress !== '') {
                $description = 'remote IP ' . $ipAddress;
            }

            return [
                'type' => 'error',
                'message' => 'You have been logged out due to a new logon session on '
                    . $description
                    . '. If this was not you, sign in again, review your account history and change your password.',
            ];
        }

        return [
            'type' => 'error',
            'message' => 'Your signed-in session is no longer active. Please sign in again.',
        ];
    }

    private function replacementSessionDetails(?array $row): ?array
    {
        if (!is_array($row) || trim((string)($row['current_session_token_hash'] ?? '')) === '') {
            return null;
        }

        return [
            'browser_label' => trim((string)($row['current_session_browser_label'] ?? '')),
            'ip_address' => trim((string)($row['current_session_ip_address'] ?? '')),
            'device_id' => trim((string)($row['current_session_device_id'] ?? '')),
        ];
    }

    private function loadCurrentSessionRow(int $userId): ?array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT
                id,
                display_name,
                email_address,
                password_hash,
                confirmed_director,
                is_active,
                role_id,
                current_session_token_hash,
                current_session_started_at,
                current_session_last_seen_at,
                current_session_device_id,
                current_session_ip_address,
                current_session_user_agent,
                current_session_browser_label,
                last_login_at,
                password_changed_at,
                created_at,
                updated_at
             FROM users
             WHERE id = :id
             LIMIT 1',
            ['id' => $userId]
        );

        return is_array($row) ? $row : null;
    }

    private function metadataFromUserRow(array $row): array
    {
        return [
            'device_id' => trim((string)($row['current_session_device_id'] ?? '')),
            'ip_address' => trim((string)($row['current_session_ip_address'] ?? '')),
            'user_agent' => trim((string)($row['current_session_user_agent'] ?? '')),
            'browser_label' => trim((string)($row['current_session_browser_label'] ?? '')),
        ];
    }

    private function currentIpAddress(): string
    {
        $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwardedFor !== '') {
            $parts = preg_split('/\s*,\s*/', $forwardedFor) ?: [];
            $first = trim((string)($parts[0] ?? ''));
            if ($first !== '') {
                return mb_substr($first, 0, 45);
            }
        }

        return mb_substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45);
    }

    private function browserLabelFromUserAgent(string $userAgent): string
    {
        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return '';
        }

        foreach ([
            'Edg/' => 'Microsoft Edge',
            'OPR/' => 'Opera',
            'Firefox/' => 'Firefox',
            'Chrome/' => 'Chrome',
            'Version/' => str_contains($userAgent, 'Safari/') ? 'Safari' : null,
        ] as $needle => $label) {
            if ($label !== null && str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        if (preg_match('/^([A-Za-z0-9\-_]+)/', $userAgent, $matches) === 1) {
            return mb_substr($matches[1], 0, 255);
        }

        return mb_substr($userAgent, 0, 255);
    }
}
