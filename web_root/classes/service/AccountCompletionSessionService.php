<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountCompletionSessionService
{
    private const INVITE_ID_KEY = 'signup.invite_id';
    private const USER_ID_KEY = 'signup.user_id';
    private const VALIDATED_KEY = 'signup.validated';
    private const VERIFIED_KEY = 'signup.verified';
    private const CREATED_AT_KEY = 'signup.created_at';
    private const EXPIRES_AT_KEY = 'signup.expires_at';

    public function __construct(
        private readonly int $lifetimeSeconds = 900,
        private readonly SessionAuthenticationService $sessionAuthenticationService = new SessionAuthenticationService(),
    ) {
    }

    public function begin(int $inviteId, int $userId): void
    {
        $this->sessionAuthenticationService->startSession();
        $this->clear();

        $_SESSION[self::INVITE_ID_KEY] = $inviteId;
        $_SESSION[self::USER_ID_KEY] = $userId;
        $_SESSION[self::VALIDATED_KEY] = true;
        $_SESSION[self::VERIFIED_KEY] = false;
        $_SESSION[self::CREATED_AT_KEY] = time();
        $_SESSION[self::EXPIRES_AT_KEY] = time() + $this->lifetimeSeconds;
    }

    public function markVerified(): void
    {
        $this->sessionAuthenticationService->startSession();
        if (!$this->isValid()) {
            $this->clear();
            return;
        }

        $_SESSION[self::VERIFIED_KEY] = true;
    }

    public function inviteId(): int
    {
        $this->sessionAuthenticationService->startSession();

        return $this->isValid() ? max(0, (int)($_SESSION[self::INVITE_ID_KEY] ?? 0)) : 0;
    }

    public function userId(): int
    {
        $this->sessionAuthenticationService->startSession();

        return $this->isValid() ? max(0, (int)($_SESSION[self::USER_ID_KEY] ?? 0)) : 0;
    }

    public function isValidated(): bool
    {
        $this->sessionAuthenticationService->startSession();

        return $this->isValid() && !empty($_SESSION[self::VALIDATED_KEY]);
    }

    public function isVerified(): bool
    {
        $this->sessionAuthenticationService->startSession();

        return $this->isValid() && !empty($_SESSION[self::VERIFIED_KEY]);
    }

    public function clear(): void
    {
        $this->sessionAuthenticationService->startSession();

        unset(
            $_SESSION[self::INVITE_ID_KEY],
            $_SESSION[self::USER_ID_KEY],
            $_SESSION[self::VALIDATED_KEY],
            $_SESSION[self::VERIFIED_KEY],
            $_SESSION[self::CREATED_AT_KEY],
            $_SESSION[self::EXPIRES_AT_KEY]
        );
    }

    private function isValid(): bool
    {
        $inviteId = max(0, (int)($_SESSION[self::INVITE_ID_KEY] ?? 0));
        $userId = max(0, (int)($_SESSION[self::USER_ID_KEY] ?? 0));
        $expiresAt = max(0, (int)($_SESSION[self::EXPIRES_AT_KEY] ?? 0));

        return $inviteId > 0 && $userId > 0 && $expiresAt >= time();
    }
}
