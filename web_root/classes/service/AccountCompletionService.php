<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountCompletionService
{
    public const PUBLIC_VERIFY_ERROR = 'We could not verify those details. Please check them and try again.';
    public const PUBLIC_TOKEN_ERROR = 'This account completion link cannot be used. Please ask for a new invitation.';

    public function __construct(
        private readonly AccountInviteService $inviteService = new AccountInviteService(),
        private readonly UserAuthenticationService $userAuthenticationService = new UserAuthenticationService(),
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
        private readonly UserSessionService $userSessionService = new UserSessionService(),
    ) {
    }

    public function beginFromToken(string $token, AccountCompletionSessionService $completionSession): array
    {
        $invite = $this->inviteService->inviteByToken($token);
        if (!$this->inviteCanBeUsed($invite)) {
            $this->markExpiredIfNeeded($invite);
            return ['success' => false, 'errors' => [self::PUBLIC_TOKEN_ERROR]];
        }

        $inviteId = (int)$invite['id'];
        $userId = (int)$invite['user_id'];
        $this->inviteService->markOpened($inviteId);
        $this->recordAudit($userId, null, 'invite_opened', 'The account completion invitation was opened.', [
            'invite_id' => $inviteId,
        ]);
        $completionSession->begin($inviteId, $userId);

        return ['success' => true, 'errors' => [], 'invite' => $invite];
    }

    public function verificationContext(AccountCompletionSessionService $completionSession): ?array
    {
        $invite = $this->inviteForSession($completionSession);
        if (!$this->inviteCanBeUsed($invite, allowVerified: true)) {
            return null;
        }

        return $invite;
    }

    public function verifyIdentity(
        AccountCompletionSessionService $completionSession,
        string $emailAddress,
        string $mobileCountryCode,
        string $mobileNumber
    ): array {
        $invite = $this->inviteForSession($completionSession);
        if (!$this->inviteCanBeUsed($invite, allowVerified: true)) {
            return ['success' => false, 'errors' => [self::PUBLIC_VERIFY_ERROR]];
        }

        $inviteId = (int)$invite['id'];
        $userId = (int)$invite['user_id'];
        $enteredEmail = strtolower(trim($emailAddress));
        $expectedEmail = strtolower(trim((string)($invite['email_address'] ?? '')));
        $enteredMobile = MobileNumberService::normaliseFromParts($mobileCountryCode, $mobileNumber);
        $expectedMobile = trim((string)($invite['mobile_number'] ?? ''));

        $emailMatches = $enteredEmail !== '' && $expectedEmail !== '' && hash_equals($expectedEmail, $enteredEmail);
        $mobileMatches = $enteredMobile !== '' && $expectedMobile !== '' && hash_equals($expectedMobile, $enteredMobile);

        if (!$emailMatches && !$mobileMatches) {
            $failure = $this->inviteService->recordVerificationFailure($inviteId);
            $this->recordAudit($userId, null, !empty($failure['locked']) ? 'invite_locked' : 'invite_verification_failed', 'Account completion identity verification failed.', [
                'invite_id' => $inviteId,
                'failed_attempts' => (int)($failure['failed_attempts'] ?? 0),
            ]);

            return ['success' => false, 'errors' => [self::PUBLIC_VERIFY_ERROR]];
        }

        $this->inviteService->markVerified($inviteId);
        $completionSession->markVerified();
        $this->recordAudit($userId, null, 'invite_verification_succeeded', 'Account completion identity verification succeeded.', [
            'invite_id' => $inviteId,
        ]);

        return ['success' => true, 'errors' => []];
    }

    public function completeAccount(
        AccountCompletionSessionService $completionSession,
        string $displayName,
        string $emailAddress,
        string $mobileCountryCode,
        string $mobileNumber,
        string $password,
        string $passwordConfirmation
    ): array {
        if (!$completionSession->isVerified()) {
            return ['success' => false, 'errors' => [self::PUBLIC_VERIFY_ERROR]];
        }

        $invite = $this->inviteForSession($completionSession);
        if (!$this->inviteCanBeUsed($invite, allowVerified: true)) {
            return ['success' => false, 'errors' => [self::PUBLIC_TOKEN_ERROR]];
        }

        $inviteId = (int)$invite['id'];
        $userId = (int)$invite['user_id'];
        $displayName = trim($displayName);
        $emailAddress = strtolower(trim($emailAddress));
        $normalisedMobile = MobileNumberService::normaliseFromParts($mobileCountryCode, $mobileNumber);
        $errors = $this->validateCompletionInput($userId, $displayName, $emailAddress, $normalisedMobile, $password, $passwordConfirmation);

        if ($errors !== []) {
            $this->recordAudit($userId, null, 'invite_completion_failed', 'Account completion form validation failed.', [
                'invite_id' => $inviteId,
                'error_count' => count($errors),
            ]);

            return ['success' => false, 'errors' => $errors];
        }

        try {
            $passwordHash = $this->userAuthenticationService->hashPassword($password);
        } catch (Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        InterfaceDB::transaction(function () use ($userId, $displayName, $emailAddress, $normalisedMobile, $passwordHash, $inviteId): void {
            InterfaceDB::prepareExecute(
                'UPDATE users
                 SET display_name = :display_name,
                     email_address = :email_address,
                     mobile_number = :mobile_number,
                     password_hash = :password_hash,
                     must_change_password = 0,
                     is_active = 1,
                     account_status = :account_status,
                     account_completed_at = CURRENT_TIMESTAMP,
                     password_changed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND account_status = :pending_status',
                [
                    'id' => $userId,
                    'display_name' => $displayName,
                    'email_address' => $emailAddress,
                    'mobile_number' => $normalisedMobile !== '' ? $normalisedMobile : null,
                    'password_hash' => $passwordHash,
                    'account_status' => 'active',
                    'pending_status' => 'pending_invitation',
                ]
            );

            $this->inviteService->markCompleted($inviteId);
        });

        UserAuthenticationService::forgetUserByIdCache($userId);
        $this->recordAudit($userId, null, 'invite_completed', 'The invited user completed account setup.', [
            'invite_id' => $inviteId,
        ]);
        $completionSession->clear();

        return ['success' => true, 'errors' => []];
    }

    public function inviteForSession(AccountCompletionSessionService $completionSession): ?array
    {
        $inviteId = $completionSession->inviteId();
        $userId = $completionSession->userId();

        if ($inviteId <= 0 || $userId <= 0) {
            return null;
        }

        $row = InterfaceDB::fetchOne(
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
             WHERE invites.id = :invite_id
               AND invites.user_id = :user_id
             LIMIT 1',
            [
                'invite_id' => $inviteId,
                'user_id' => $userId,
            ]
        );

        return is_array($row) ? $row : null;
    }

    private function validateCompletionInput(
        int $userId,
        string $displayName,
        string $emailAddress,
        string $mobileNumber,
        string $password,
        string $passwordConfirmation
    ): array {
        $errors = [];

        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }

        if ($emailAddress === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address must be valid.';
        } elseif ($this->emailAddressUsedByAnotherUser($userId, $emailAddress)) {
            $errors[] = 'A user with that email address already exists.';
        }

        if ($mobileNumber !== '' && preg_match('/^\+[1-9][0-9]{6,14}$/', $mobileNumber) !== 1) {
            $errors[] = 'Mobile number must include a valid country code and 7 to 15 digits.';
        }

        if ($password === '') {
            $errors[] = 'Password is required.';
        }

        if ($password !== $passwordConfirmation) {
            $errors[] = 'The password and confirmation do not match.';
        }

        return $errors;
    }

    private function emailAddressUsedByAnotherUser(int $userId, string $emailAddress): bool
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id
             FROM users
             WHERE email_address = :email_address
               AND id <> :id
             LIMIT 1',
            [
                'email_address' => $emailAddress,
                'id' => $userId,
            ]
        );

        return is_array($row);
    }

    private function inviteCanBeUsed(?array $invite, bool $allowVerified = false): bool
    {
        if ($invite === null) {
            return false;
        }

        if ((string)($invite['purpose'] ?? '') !== AccountInviteService::PURPOSE_ACCOUNT_COMPLETION) {
            return false;
        }

        if ((string)($invite['account_status'] ?? '') !== 'pending_invitation') {
            return false;
        }

        if (trim((string)($invite['password_hash'] ?? '')) !== '') {
            return false;
        }

        $status = (string)($invite['status'] ?? '');
        $allowedStatuses = [
            AccountInviteService::STATUS_PENDING,
            AccountInviteService::STATUS_SENT,
            AccountInviteService::STATUS_OPENED,
        ];
        if ($allowVerified) {
            $allowedStatuses[] = AccountInviteService::STATUS_VERIFIED;
        }

        if (!in_array($status, $allowedStatuses, true)) {
            return false;
        }

        $expiresAt = HelperFramework::parseDateTimeValue((string)($invite['expires_at'] ?? ''));

        return $expiresAt instanceof DateTimeImmutable && $expiresAt >= new DateTimeImmutable('now');
    }

    private function markExpiredIfNeeded(?array $invite): void
    {
        if ($invite === null) {
            return;
        }

        $expiresAt = HelperFramework::parseDateTimeValue((string)($invite['expires_at'] ?? ''));
        if (!$expiresAt instanceof DateTimeImmutable || $expiresAt >= new DateTimeImmutable('now')) {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_account_invites
             SET status = :expired_status
             WHERE id = :id
               AND status IN (:pending_status, :sent_status, :opened_status, :verified_status)',
            [
                'id' => (int)$invite['id'],
                'expired_status' => AccountInviteService::STATUS_EXPIRED,
                'pending_status' => AccountInviteService::STATUS_PENDING,
                'sent_status' => AccountInviteService::STATUS_SENT,
                'opened_status' => AccountInviteService::STATUS_OPENED,
                'verified_status' => AccountInviteService::STATUS_VERIFIED,
            ]
        );
        $this->recordAudit((int)($invite['user_id'] ?? 0), null, 'invite_expired', 'The account completion invitation expired.', [
            'invite_id' => (int)($invite['id'] ?? 0),
        ]);
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
}
