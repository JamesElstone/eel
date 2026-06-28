<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountInviteService
{
    public const PURPOSE_ACCOUNT_COMPLETION = 'account_completion';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_OPENED = 'opened';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_LOCKED = 'locked';

    private const DEFAULT_EXPIRY_DAYS = 5;
    private const MAX_EXPIRY_DAYS = 31;

    public function __construct(
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
        private readonly UserSessionService $userSessionService = new UserSessionService(),
        private readonly ?SmsService $smsService = null,
        private readonly ?EmailService $emailService = null,
    ) {
    }

    public function createInviteLink(int $actorUserId, int $userId, string $contactMethod, string $baseUrl = ''): array
    {
        return $this->prepareInvite($actorUserId, $userId, $baseUrl, 'invite_link_copied');
    }

    public function sendEmailInvite(int $actorUserId, int $userId, string $baseUrl = ''): array
    {
        $invite = $this->prepareInvite($actorUserId, $userId, $baseUrl);
        if (empty($invite['success'])) {
            return $invite;
        }

        [$contactMethod, $sentTo] = $this->resolveContact($invite, 'email');
        if ($sentTo === '') {
            return ['success' => false, 'errors' => ['The selected user does not have an email address.']];
        }

        try {
            $actor = $this->actorTemplateValues($actorUserId);
            $this->emailService()->sendInvite(
                $sentTo,
                (string)($invite['link'] ?? ''),
                (string)($invite['expires_at'] ?? ''),
                (string)$actor['display_name'],
                (string)($invite['display_name'] ?? ''),
                (string)$actor['email_address'],
                (string)$actor['mobile_number']
            );
            $this->recordInviteDelivery((int)$invite['invite_id'], $actorUserId, $contactMethod, $sentTo, 'sent');
            $this->markInviteSent((int)$invite['invite_id']);
            $this->recordAudit($userId, $actorUserId, 'invite_email_sent', 'An account completion invitation email was sent.', [
                'invite_id' => (int)$invite['invite_id'],
                'contact_method' => $contactMethod,
            ]);
            $invite['status'] = in_array((string)($invite['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_SENT], true)
                ? self::STATUS_SENT
                : (string)($invite['status'] ?? self::STATUS_SENT);
            $invite['contact_method'] = $contactMethod;
            $invite['sent_to'] = $sentTo;
        } catch (Throwable $exception) {
            $this->recordInviteDelivery((int)$invite['invite_id'], $actorUserId, $contactMethod, $sentTo, 'failed', mb_substr($exception->getMessage(), 0, 160));
            $this->recordInviteFailure((int)$invite['invite_id']);
            $this->recordAudit($userId, $actorUserId, 'invite_completion_failed', 'Invite email could not be sent.', [
                'channel' => 'email',
                'error' => mb_substr($exception->getMessage(), 0, 160),
            ]);

            return [
                'success' => false,
                'errors' => ['The invite email could not be sent.'],
            ];
        }

        return $invite;
    }

    public function sendSmsInvite(int $actorUserId, int $userId, string $baseUrl = ''): array
    {
        $invite = $this->prepareInvite($actorUserId, $userId, $baseUrl);
        if (empty($invite['success'])) {
            return $invite;
        }

        [$contactMethod, $sentTo] = $this->resolveContact($invite, 'sms');
        if ($sentTo === '') {
            return ['success' => false, 'errors' => ['The selected user does not have a mobile number.']];
        }

        try {
            $actor = $this->actorTemplateValues($actorUserId);
            $this->smsService()->sendInvite(
                $sentTo,
                (string)($invite['link'] ?? ''),
                (string)($invite['expires_at'] ?? ''),
                (string)$actor['display_name'],
                (string)($invite['display_name'] ?? ''),
                (string)$actor['email_address'],
                (string)$actor['mobile_number']
            );
            $this->recordInviteDelivery((int)$invite['invite_id'], $actorUserId, $contactMethod, $sentTo, 'sent');
            $this->markInviteSent((int)$invite['invite_id']);
            $this->recordAudit($userId, $actorUserId, 'invite_sms_sent', 'An account completion invitation SMS was sent.', [
                'invite_id' => (int)$invite['invite_id'],
                'contact_method' => $contactMethod,
            ]);
            $invite['status'] = in_array((string)($invite['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_SENT], true)
                ? self::STATUS_SENT
                : (string)($invite['status'] ?? self::STATUS_SENT);
            $invite['contact_method'] = $contactMethod;
            $invite['sent_to'] = $sentTo;
        } catch (Throwable $exception) {
            $this->recordInviteDelivery((int)$invite['invite_id'], $actorUserId, $contactMethod, $sentTo, 'failed', mb_substr($exception->getMessage(), 0, 160));
            $this->recordInviteFailure((int)$invite['invite_id']);
            $this->recordAudit($userId, $actorUserId, 'invite_completion_failed', 'Invite SMS could not be sent.', [
                'channel' => 'sms',
                'error' => mb_substr($exception->getMessage(), 0, 160),
            ]);

            return [
                'success' => false,
                'errors' => ['The invite SMS could not be sent.'],
            ];
        }

        return $invite;
    }

    public function revokeInvite(int $actorUserId, int $inviteId): array
    {
        $invite = $this->inviteById($inviteId);
        if ($invite === null) {
            return ['success' => false, 'errors' => ['The selected invitation could not be found.']];
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET status = :status,
                 revoked_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status NOT IN (:completed_status, :revoked_status)',
            [
                'id' => $inviteId,
                'status' => self::STATUS_REVOKED,
                'completed_status' => self::STATUS_COMPLETED,
                'revoked_status' => self::STATUS_REVOKED,
            ]
        );

        $this->recordAudit((int)$invite['user_id'], $actorUserId, 'invite_revoked', 'An administrator revoked this account completion invitation.', [
            'invite_id' => $inviteId,
        ]);

        return ['success' => true, 'errors' => []];
    }

    public function inviteByToken(string $token): ?array
    {
        $tokenHash = self::tokenHash($token);
        if ($tokenHash === '') {
            return null;
        }

        $invite = InterfaceDB::fetchOne(
            'SELECT invites.*,
                    users.display_name,
                    users.email_address,
                    users.mobile_number,
                    users.password_hash,
                    users.is_active,
                    users.account_status
             FROM user_account_invites invites
             INNER JOIN users
                ON users.id = invites.user_id
             WHERE invites.token_hash = :token_hash
               AND invites.purpose = :purpose
             LIMIT 1',
            [
                'token_hash' => $tokenHash,
                'purpose' => self::PURPOSE_ACCOUNT_COMPLETION,
            ]
        );

        return is_array($invite) ? $invite : null;
    }

    public function markOpened(int $inviteId): void
    {
        $metadata = $this->userSessionService->buildRequestMetadata();

        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET opened_at = COALESCE(opened_at, CURRENT_TIMESTAMP),
                 ip_opened = COALESCE(ip_opened, :ip_opened),
                 status = CASE WHEN status IN (:pending_status, :sent_status) THEN :opened_status ELSE status END
             WHERE id = :id',
            [
                'id' => $inviteId,
                'ip_opened' => $this->optionalString((string)($metadata['ip_address'] ?? ''), 45),
                'pending_status' => self::STATUS_PENDING,
                'sent_status' => self::STATUS_SENT,
                'opened_status' => self::STATUS_OPENED,
            ]
        );
    }

    public function markVerified(int $inviteId): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET verified_at = COALESCE(verified_at, CURRENT_TIMESTAMP),
                 status = :status
             WHERE id = :id
               AND status <> :completed_status',
            [
                'id' => $inviteId,
                'status' => self::STATUS_VERIFIED,
                'completed_status' => self::STATUS_COMPLETED,
            ]
        );
    }

    public function recordVerificationFailure(int $inviteId): array
    {
        $invite = $this->inviteById($inviteId);
        if ($invite === null) {
            return ['locked' => false, 'failed_attempts' => 0];
        }

        $failedAttempts = max(0, (int)($invite['failed_attempts'] ?? 0)) + 1;
        $locked = $failedAttempts >= $this->failedAttemptLockThreshold();
        $lockExpiresAt = $locked
            ? (new DateTimeImmutable('now'))->modify('+15 minutes')->format('Y-m-d H:i:s')
            : null;

        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET failed_attempts = :failed_attempts,
                 last_failed_at = CURRENT_TIMESTAMP,
                 locked_at = CASE WHEN :locked = 1 THEN CURRENT_TIMESTAMP ELSE locked_at END,
                 lock_expires_at = CASE WHEN :locked = 1 THEN :lock_expires_at ELSE lock_expires_at END,
                 status = CASE WHEN :locked = 1 THEN :locked_status ELSE status END
             WHERE id = :id',
            [
                'id' => $inviteId,
                'failed_attempts' => $failedAttempts,
                'locked' => $locked ? 1 : 0,
                'lock_expires_at' => $lockExpiresAt,
                'locked_status' => self::STATUS_LOCKED,
            ]
        );

        return ['locked' => $locked, 'failed_attempts' => $failedAttempts];
    }

    public function markCompleted(int $inviteId): void
    {
        $metadata = $this->userSessionService->buildRequestMetadata();

        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET status = :status,
                 used_at = CURRENT_TIMESTAMP,
                 completed_at = CURRENT_TIMESTAMP,
                 ip_used = :ip_used
             WHERE id = :id',
            [
                'id' => $inviteId,
                'status' => self::STATUS_COMPLETED,
                'ip_used' => $this->optionalString((string)($metadata['ip_address'] ?? ''), 45),
            ]
        );
    }

    public function listInvites(int $limit = 200): array
    {
        if (!InterfaceDB::tableExists('user_account_invites')) {
            return [];
        }

        $rows = InterfaceDB::fetchAll(
            'SELECT invites.id,
                    invites.user_id,
                    users.display_name,
                    users.email_address,
                    users.mobile_number,
                    invites.status,
                    invites.expires_at,
                    invites.created_at,
                    invites.last_sent_at,
                    invites.completed_at,
                    invites.revoked_at,
                    invites.failed_attempts
             FROM user_account_invites invites
             INNER JOIN users
                ON users.id = invites.user_id
             ORDER BY invites.created_at DESC, invites.id DESC
             LIMIT ' . max(1, min(500, $limit))
        );

        return $this->withInviteDeliveries($rows);
    }

    public function latestInviteForUsers(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || !InterfaceDB::tableExists('user_account_invites')) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $param = 'user_id_' . $index;
            $params[$param] = $id;
            $placeholders[] = ':' . $param;
        }

        $rows = InterfaceDB::fetchAll(
            'SELECT invites.*
             FROM user_account_invites invites
             INNER JOIN (
                 SELECT user_id, MAX(id) AS invite_id
                 FROM user_account_invites
                 WHERE user_id IN (' . implode(', ', $placeholders) . ')
                 GROUP BY user_id
             ) latest
                ON latest.invite_id = invites.id',
            $params
        );
        $byUserId = [];

        foreach ($rows as $row) {
            $byUserId[(int)($row['user_id'] ?? 0)] = $row;
        }

        return $byUserId;
    }

    public function buildBaseUrl(RequestFramework $request): string
    {
        $override = trim((string)AppConfigurationStore::get('invitation.base_url_override', ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }

        $reverseProxy = new ReverseProxyService();
        $scheme = $reverseProxy->forwardedScheme($request);
        if ($scheme === '') {
            $scheme = $request->isSecure() ? 'https' : 'http';
        }

        $host = $reverseProxy->forwardedHost($request);
        if ($host === '') {
            $host = trim((string)$request->header('Host', ''));
        }

        if ($host === '') {
            return '';
        }

        return rtrim($scheme . '://' . $host, '/');
    }

    public function settingsEnabled(): bool
    {
        return (bool)AppConfigurationStore::get('invitation.enabled', true);
    }

    public static function tokenHash(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }

        return hash('sha256', $token);
    }

    private function prepareInvite(int $actorUserId, int $userId, string $baseUrl, string $auditAction = ''): array
    {
        if (!$this->settingsEnabled()) {
            return ['success' => false, 'errors' => ['Invited account completion is disabled.']];
        }

        $user = $this->userAuthenticationService->userById($userId);
        if ($user === null) {
            return ['success' => false, 'errors' => ['The selected user could not be found.']];
        }

        if ((string)($user['account_status'] ?? '') !== 'pending_invitation') {
            return ['success' => false, 'errors' => ['Only pending invitation accounts can be invited.']];
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return ['success' => false, 'errors' => ['Application base URL could not be resolved.']];
        }

        if (!InterfaceDB::columnExists('user_account_invites', 'token_value')) {
            return ['success' => false, 'errors' => ['The invitation database migration has not been applied.']];
        }

        $invite = $this->reusableInviteForUser($userId);
        $rawToken = trim((string)($invite['token_value'] ?? ''));
        $created = false;

        if ($invite === null || $rawToken === '' || self::tokenHash($rawToken) !== (string)($invite['token_hash'] ?? '')) {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = self::tokenHash($rawToken);
            $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $this->expiryDays() . ' days')->format('Y-m-d H:i:s');
            $metadata = $this->userSessionService->buildRequestMetadata();
            $created = true;

            InterfaceDB::transaction(function () use ($userId, $tokenHash, $rawToken, $expiresAt, $actorUserId, $metadata): void {
                InterfaceDB::prepareExecute(
                    'UPDATE user_account_invites
                     SET status = :revoked_status,
                         revoked_at = CURRENT_TIMESTAMP
                     WHERE user_id = :user_id
                       AND purpose = :purpose
                       AND status IN (:pending_status, :sent_status, :opened_status, :verified_status, :locked_status)',
                    [
                        'user_id' => $userId,
                        'purpose' => self::PURPOSE_ACCOUNT_COMPLETION,
                        'revoked_status' => self::STATUS_REVOKED,
                        'pending_status' => self::STATUS_PENDING,
                        'sent_status' => self::STATUS_SENT,
                        'opened_status' => self::STATUS_OPENED,
                        'verified_status' => self::STATUS_VERIFIED,
                        'locked_status' => self::STATUS_LOCKED,
                    ]
                );

                InterfaceDB::prepareExecute(
                    'INSERT INTO user_account_invites (
                        user_id,
                        token_hash,
                        token_value,
                        purpose,
                        status,
                        expires_at,
                        created_by_user_id,
                        ip_created
                    ) VALUES (
                        :user_id,
                        :token_hash,
                        :token_value,
                        :purpose,
                        :status,
                        :expires_at,
                        :created_by_user_id,
                        :ip_created
                    )',
                    [
                        'user_id' => $userId,
                        'token_hash' => $tokenHash,
                        'token_value' => $rawToken,
                        'purpose' => self::PURPOSE_ACCOUNT_COMPLETION,
                        'status' => self::STATUS_PENDING,
                        'expires_at' => $expiresAt,
                        'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        'ip_created' => $this->optionalString((string)($metadata['ip_address'] ?? ''), 45),
                    ]
                );
            });

            $invite = $this->inviteByToken($rawToken);
            if ($invite === null) {
                return ['success' => false, 'errors' => ['The invitation was created but could not be reloaded.']];
            }
        }

        if ($created) {
            $this->recordAudit($userId, $actorUserId, 'invite_created', 'An administrator generated an account completion invitation.', [
                'invite_id' => (int)$invite['id'],
            ]);
        }
        if ($auditAction !== '') {
            $this->recordAudit($userId, $actorUserId, $auditAction, 'An account completion invitation link was prepared.', [
                'invite_id' => (int)$invite['id'],
            ]);
        }

        return [
            'success' => true,
            'errors' => [],
            'invite_id' => (int)$invite['id'],
            'user_id' => $userId,
            'status' => (string)$invite['status'],
            'expires_at' => (string)$invite['expires_at'],
            'display_name' => (string)($invite['display_name'] ?? $user['display_name'] ?? ''),
            'email_address' => strtolower(trim((string)($invite['email_address'] ?? $user['email_address'] ?? ''))),
            'mobile_number' => trim((string)($invite['mobile_number'] ?? $user['mobile_number'] ?? '')),
            'link' => $baseUrl . '/signup/?token=' . rawurlencode($rawToken),
        ];
    }

    private function reusableInviteForUser(int $userId): ?array
    {
        if (!InterfaceDB::tableExists('user_account_invites') || !InterfaceDB::columnExists('user_account_invites', 'token_value')) {
            return null;
        }

        $invite = InterfaceDB::fetchOne(
            'SELECT invites.*,
                    users.display_name,
                    users.email_address,
                    users.mobile_number
             FROM user_account_invites invites
             INNER JOIN users
                ON users.id = invites.user_id
             WHERE invites.user_id = :user_id
               AND invites.purpose = :purpose
               AND invites.status IN (:pending_status, :sent_status, :opened_status, :verified_status, :locked_status)
               AND invites.expires_at > CURRENT_TIMESTAMP
               AND invites.token_value IS NOT NULL
               AND invites.token_value <> \'\'
             ORDER BY invites.id DESC
             LIMIT 1',
            [
                'user_id' => $userId,
                'purpose' => self::PURPOSE_ACCOUNT_COMPLETION,
                'pending_status' => self::STATUS_PENDING,
                'sent_status' => self::STATUS_SENT,
                'opened_status' => self::STATUS_OPENED,
                'verified_status' => self::STATUS_VERIFIED,
                'locked_status' => self::STATUS_LOCKED,
            ]
        );

        return is_array($invite) ? $invite : null;
    }

    private function resolveContact(array $user, string $contactMethod): array
    {
        $contactMethod = strtolower(trim($contactMethod));
        if ($contactMethod === 'sms') {
            $mobileNumber = trim((string)($user['mobile_number'] ?? ''));
            return ['sms', $mobileNumber];
        }

        $emailAddress = strtolower(trim((string)($user['email_address'] ?? '')));
        if ($contactMethod === 'email') {
            return ['email', $emailAddress];
        }

        if ($emailAddress !== '') {
            return ['email', $emailAddress];
        }

        return ['sms', trim((string)($user['mobile_number'] ?? ''))];
    }

    private function recordInviteDelivery(
        int $inviteId,
        int $actorUserId,
        string $contactMethod,
        string $sentTo,
        string $status,
        string $errorSummary = ''
    ): void {
        if ($inviteId <= 0 || !InterfaceDB::tableExists('user_account_invite_deliveries')) {
            return;
        }

        $contactMethod = strtolower(trim($contactMethod));
        $sentTo = trim($sentTo);
        $status = strtolower(trim($status));
        if ($contactMethod === '' || $sentTo === '' || $status === '') {
            return;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO user_account_invite_deliveries (
                invite_id,
                contact_method,
                sent_to,
                status,
                sent_at,
                created_by_user_id,
                error_summary
            ) VALUES (
                :invite_id,
                :contact_method,
                :sent_to,
                :status,
                CASE WHEN :sent_status = :status_for_sent_at THEN CURRENT_TIMESTAMP ELSE NULL END,
                :created_by_user_id,
                :error_summary
            )',
            [
                'invite_id' => $inviteId,
                'contact_method' => $contactMethod,
                'sent_to' => $sentTo,
                'status' => $status,
                'sent_status' => 'sent',
                'status_for_sent_at' => $status,
                'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                'error_summary' => $this->optionalString($errorSummary, 255),
            ]
        );
    }

    private function withInviteDeliveries(array $rows): array
    {
        if ($rows === [] || !InterfaceDB::tableExists('user_account_invite_deliveries')) {
            foreach ($rows as &$row) {
                $row['deliveries'] = [];
                $row['delivery_summary'] = 'Not sent';
            }
            unset($row);

            return $rows;
        }

        $inviteIds = [];
        foreach ($rows as $row) {
            $inviteId = (int)($row['id'] ?? 0);
            if ($inviteId > 0) {
                $inviteIds[$inviteId] = $inviteId;
            }
        }

        if ($inviteIds === []) {
            return $rows;
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($inviteIds) as $index => $inviteId) {
            $param = 'invite_id_' . $index;
            $params[$param] = $inviteId;
            $placeholders[] = ':' . $param;
        }

        $deliveryRows = InterfaceDB::fetchAll(
            'SELECT invite_id,
                    contact_method,
                    sent_to,
                    status,
                    sent_at,
                    created_at,
                    created_by_user_id,
                    error_summary
             FROM user_account_invite_deliveries
             WHERE invite_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY invite_id ASC, created_at ASC, id ASC',
            $params
        );
        $byInviteId = [];

        foreach ($deliveryRows as $delivery) {
            $inviteId = (int)($delivery['invite_id'] ?? 0);
            if ($inviteId <= 0) {
                continue;
            }

            $byInviteId[$inviteId][] = $delivery;
        }

        foreach ($rows as &$row) {
            $inviteId = (int)($row['id'] ?? 0);
            $deliveries = $byInviteId[$inviteId] ?? [];
            $row['deliveries'] = $deliveries;
            $row['delivery_summary'] = $this->deliverySummary($deliveries);
        }
        unset($row);

        return $rows;
    }

    private function deliverySummary(array $deliveries): string
    {
        $latestByMethod = [];
        foreach ($deliveries as $delivery) {
            $method = strtolower(trim((string)($delivery['contact_method'] ?? '')));
            if ($method === '') {
                continue;
            }

            $latestByMethod[$method] = strtolower(trim((string)($delivery['status'] ?? 'created')));
        }

        if ($latestByMethod === []) {
            return 'Not sent';
        }

        $parts = [];
        foreach ($latestByMethod as $method => $status) {
            $parts[] = HelperFramework::labelFromKey($method) . ': ' . HelperFramework::labelFromKey($status);
        }

        return implode(' | ', $parts);
    }

    private function markInviteSent(int $inviteId): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET status = CASE
                     WHEN status = :pending_status THEN :sent_status
                     ELSE status
                 END,
                 last_sent_at = CURRENT_TIMESTAMP,
                 send_attempts = send_attempts + 1
             WHERE id = :id',
            [
                'id' => $inviteId,
                'pending_status' => self::STATUS_PENDING,
                'sent_status' => self::STATUS_SENT,
            ]
        );
    }

    private function recordInviteFailure(int $inviteId): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET failed_attempts = failed_attempts + 1,
                 last_failed_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['id' => $inviteId]
        );
    }

    private function inviteById(int $inviteId): ?array
    {
        if ($inviteId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
            'SELECT *
             FROM user_account_invites
             WHERE id = :id
             LIMIT 1',
            ['id' => $inviteId]
        );

        return is_array($row) ? $row : null;
    }

    private function expiryDays(): int
    {
        return max(1, min(self::MAX_EXPIRY_DAYS, (int)AppConfigurationStore::get('invitation.expiry_days', self::DEFAULT_EXPIRY_DAYS)));
    }

    private function failedAttemptLockThreshold(): int
    {
        return 8;
    }

    private function recordAudit(int $affectedUserId, ?int $actorUserId, string $actionType, string $reason, array $details = []): void
    {
        if ($affectedUserId <= 0) {
            return;
        }

        $this->userHistoryStore->recordAccountAudit(
            $affectedUserId,
            $actorUserId,
            $actionType,
            $reason,
            $details,
            $this->userSessionService->buildRequestMetadata()
        );
    }

    private function actorDisplayName(int $actorUserId): string
    {
        return (string)$this->actorTemplateValues($actorUserId)['display_name'];
    }

    private function actorTemplateValues(int $actorUserId): array
    {
        if ($actorUserId <= 0) {
            return [
                'display_name' => '',
                'email_address' => '',
                'mobile_number' => '',
            ];
        }

        $user = $this->userAuthenticationService->userById($actorUserId);
        if ($user === null) {
            return [
                'display_name' => '',
                'email_address' => '',
                'mobile_number' => '',
            ];
        }

        return [
            'display_name' => trim((string)($user['display_name'] ?? '')),
            'email_address' => strtolower(trim((string)($user['email_address'] ?? ''))),
            'mobile_number' => trim((string)($user['mobile_number'] ?? '')),
        ];
    }

    private function optionalString(string $value, int $maxLength): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    private function smsService(): SmsService
    {
        return $this->smsService ?? new SmsService();
    }

    private function emailService(): EmailService
    {
        return $this->emailService ?? new EmailService();
    }
}
