<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class UserHistoryStore
{
    public function recordLogonEvent(
        ?int $userId,
        ?string $attemptedEmailAddress,
        string $eventType,
        bool $success = true,
        ?string $reason = null,
        ?string $sessionTokenHash = null,
        array $metadata = []
    ): void {
        InterfaceDB::prepareExecute(
            'INSERT INTO user_logon_history (
                user_id,
                attempted_email_address,
                event_type,
                success,
                reason,
                session_token_hash,
                device_id,
                ip_address,
                user_agent,
                browser_label
            ) VALUES (
                :user_id,
                :attempted_email_address,
                :event_type,
                :success,
                :reason,
                :session_token_hash,
                :device_id,
                :ip_address,
                :user_agent,
                :browser_label
            )',
            [
                'user_id' => $userId !== null && $userId > 0 ? $userId : null,
                'attempted_email_address' => $this->normaliseOptionalString($attemptedEmailAddress, 255),
                'event_type' => trim($eventType),
                'success' => $success ? 1 : 0,
                'reason' => $this->normaliseOptionalString($reason, 255),
                'session_token_hash' => $this->normaliseOptionalString($sessionTokenHash, 64),
                'device_id' => $this->metadataValue($metadata, 'device_id', 64),
                'ip_address' => $this->metadataValue($metadata, 'ip_address', 45),
                'user_agent' => $this->metadataValue($metadata, 'user_agent', 1000),
                'browser_label' => $this->metadataValue($metadata, 'browser_label', 255),
            ]
        );
    }

    public function attachSessionTokenHashToLatestLogonEvent(
        int $userId,
        string $eventType,
        string $sessionTokenHash
    ): void {
        if ($userId <= 0) {
            return;
        }

        $eventType = trim($eventType);
        $sessionTokenHash = $this->normaliseOptionalString($sessionTokenHash, 64) ?? '';

        if ($eventType === '' || $sessionTokenHash === '') {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_logon_history
             SET session_token_hash = :session_token_hash
             WHERE user_id = :user_id
               AND event_type = :event_type
               AND (session_token_hash IS NULL OR session_token_hash = \'\')
             ORDER BY occurred_at DESC, id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'event_type' => $eventType,
                'session_token_hash' => $sessionTokenHash,
            ]
        );
    }

    public function recordAccountAudit(
        int $affectedUserId,
        ?int $actorUserId,
        string $actionType,
        ?string $reason = null,
        array $details = [],
        array $metadata = []
    ): void {
        if ($affectedUserId <= 0) {
            throw new RuntimeException('An affected user is required for account audit logging.');
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO user_account_audit (
                affected_user_id,
                actor_user_id,
                action_type,
                reason,
                details_json,
                device_id,
                ip_address,
                user_agent
            ) VALUES (
                :affected_user_id,
                :actor_user_id,
                :action_type,
                :reason,
                :details_json,
                :device_id,
                :ip_address,
                :user_agent
            )',
            [
                'affected_user_id' => $affectedUserId,
                'actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
                'action_type' => trim($actionType),
                'reason' => $this->normaliseOptionalString($reason, 255),
                'details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'device_id' => $this->metadataValue($metadata, 'device_id', 64),
                'ip_address' => $this->metadataValue($metadata, 'ip_address', 45),
                'user_agent' => $this->metadataValue($metadata, 'user_agent', 1000),
            ]
        );
    }

    public function fetchLogonHistoryForUser(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT
                id,
                user_id,
                attempted_email_address,
                event_type,
                success,
                reason,
                session_token_hash,
                device_id,
                ip_address,
                user_agent,
                browser_label,
                occurred_at
             FROM user_logon_history
             WHERE user_id = :user_id
             ORDER BY occurred_at DESC, id DESC
             LIMIT ' . $this->normaliseLimit($limit),
            ['user_id' => $userId]
        );
    }

    public function fetchAuditHistoryForUser(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        return InterfaceDB::fetchAll(
            'SELECT
                id,
                affected_user_id,
                actor_user_id,
                action_type,
                reason,
                details_json,
                device_id,
                ip_address,
                user_agent,
                created_at
             FROM user_account_audit
             WHERE affected_user_id = :user_id
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $this->normaliseLimit($limit),
            ['user_id' => $userId]
        );
    }

    public function fetchRecentAccountAudit(int $limit = 100): array
    {
        return InterfaceDB::fetchAll(
            'SELECT
                audit.id,
                audit.affected_user_id,
                audit.actor_user_id,
                audit.action_type,
                audit.reason,
                audit.details_json,
                audit.device_id,
                audit.ip_address,
                audit.user_agent,
                audit.created_at,
                affected.display_name AS affected_user_display_name,
                actor.display_name AS actor_user_display_name
             FROM user_account_audit audit
             INNER JOIN users affected
                ON affected.id = audit.affected_user_id
             LEFT JOIN users actor
                ON actor.id = audit.actor_user_id
             ORDER BY audit.created_at DESC, audit.id DESC
             LIMIT ' . $this->normaliseLimit($limit)
        );
    }

    private function metadataValue(array $metadata, string $key, int $maxLength): ?string
    {
        return $this->normaliseOptionalString($metadata[$key] ?? null, $maxLength);
    }

    private function normaliseOptionalString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        return $value;
    }

    private function normaliseLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }
}
