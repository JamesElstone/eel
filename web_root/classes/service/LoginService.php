<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LoginService
{
    private const MAX_LOGIN_EMAIL_LENGTH = 254;
    private const MAX_LOGIN_PASSWORD_LENGTH = 4096;

    private ?string $pepper = null;

    public function __construct(
        private readonly UserAuthenticationService $userAuthenticationService,
        private readonly OtpService $otpService,
        private readonly QrCodeService $qrCodeService,
        private readonly SessionAuthenticationService $sessionAuthenticationService,
        private readonly UserSessionService $userSessionService = new UserSessionService(),
        private readonly UserHistoryStore $userHistoryStore = new UserHistoryStore(),
    ) {
    }

    public function startLogin(string $emailAddress, string $password, string $deviceId): array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $emailAddress = strtolower(trim($emailAddress));

        if (mb_strlen($emailAddress) > self::MAX_LOGIN_EMAIL_LENGTH || mb_strlen($password) > self::MAX_LOGIN_PASSWORD_LENGTH) {
            $this->sessionAuthenticationService->clearPendingOtpSetup();
            $this->sessionAuthenticationService->clearPendingOtp();

            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => false,
                'retry_after_seconds' => 0,
                'rate_limit' => $this->userAuthenticationService->loginRateLimitStatus('', $deviceId),
                'account_locked' => false,
                'throttled' => false,
                'errors' => ['Invalid email address or password.'],
            ];
        }

        $passwordDiagnostics = $this->passwordAttemptDiagnostics($password);
        $password = $this->normaliseSubmittedLoginPassword($password);
        $rateLimit = $this->userAuthenticationService->loginRateLimitStatus($emailAddress, $deviceId);

        if (!empty($rateLimit['is_locked'])) {
            $this->sessionAuthenticationService->clearPendingOtpSetup();
            $this->sessionAuthenticationService->clearPendingOtp();

            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => false,
                'account_locked' => true,
                'retry_after_seconds' => 0,
                'rate_limit' => $rateLimit,
                'errors' => ['This account has been locked after too many incorrect password attempts.'],
            ];
        }

        if (!empty($rateLimit['is_throttled'])) {
            $rateLimit = $this->userAuthenticationService->recordFailedPasswordAttempt($emailAddress, $deviceId);
            $this->sessionAuthenticationService->clearPendingOtpSetup();
            $this->sessionAuthenticationService->clearPendingOtp();
            $this->userHistoryStore->recordLogonEvent(
                null,
                $emailAddress,
                'login_failed',
                false,
                'Login was attempted while rate limited.',
                null,
                $this->userSessionService->buildRequestMetadata($deviceId)
            );

            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => false,
                'throttled' => !empty($rateLimit['is_throttled']),
                'account_locked' => !empty($rateLimit['is_locked']),
                'retry_after_seconds' => (int)($rateLimit['retry_after_seconds'] ?? 0),
                'rate_limit' => $rateLimit,
                'errors' => !empty($rateLimit['is_locked'])
                    ? ['This account has been locked after too many incorrect password attempts.']
                    : ['Please wait before trying again.'],
            ];
        }

        $user = $this->userAuthenticationService->authenticateByEmailAddress($emailAddress, $password);

        if (!is_array($user)) {
            $failureDetails = $this->userAuthenticationService->primaryCredentialFailureDetails($emailAddress);
            $rateLimit = $this->userAuthenticationService->recordFailedPasswordAttempt($emailAddress, $deviceId);
            $this->sessionAuthenticationService->clearPendingOtpSetup();
            $this->sessionAuthenticationService->clearPendingOtp();
            $this->userHistoryStore->recordLogonEvent(
                $failureDetails['user_id'] ?? null,
                $emailAddress,
                'login_failed',
                false,
                $this->loginFailureReason(
                    (string)($failureDetails['reason'] ?? 'Primary credentials were rejected.'),
                    $passwordDiagnostics
                ),
                null,
                $this->userSessionService->buildRequestMetadata($deviceId)
            );

            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => false,
                'retry_after_seconds' => (int)($rateLimit['retry_after_seconds'] ?? 0),
                'rate_limit' => $rateLimit,
                'account_locked' => !empty($rateLimit['is_locked']),
                'throttled' => !empty($rateLimit['is_throttled']),
                'errors' => !empty($rateLimit['is_locked'])
                    ? ['This account has been locked after too many incorrect password attempts.']
                    : (!empty($rateLimit['is_throttled'])
                        ? ['Invalid email address or password. Please wait before trying again.']
                        : ['Invalid email address or password.']),
            ];
        }

        $this->userAuthenticationService->clearLoginRateLimit($emailAddress, $deviceId);

        $userId = max(0, (int)($user['id'] ?? 0));
        if ($userId <= 0) {
            throw new RuntimeException('Authenticated user could not be resolved.');
        }

        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($user['email_address'] ?? ''),
            'login_succeeded',
            true,
            'Primary sign-in credentials were accepted.',
            null,
            $this->userSessionService->buildRequestMetadata($deviceId)
        );

        if (!empty($user['must_change_password'])) {
            $this->sessionAuthenticationService->beginPendingPasswordChange($userId, $deviceId);

            return [
                'success' => true,
                'authenticated' => false,
                'requires_password_change' => true,
                'errors' => [],
            ];
        }

        return $this->continueAfterPrimaryCredentials($user, $deviceId);
    }

    private function passwordAttemptDiagnostics(string $password): array
    {
        $trimmedPassword = trim($password);
        $normalisedPassword = $this->normaliseSubmittedLoginPassword($password);

        return [
            'entered_length' => mb_strlen($password),
            'trimmed_length' => mb_strlen($trimmedPassword),
            'login_length' => mb_strlen($normalisedPassword),
            'trimmed_whitespace' => $password !== $trimmedPassword,
            'removed_format_characters' => $trimmedPassword !== $normalisedPassword,
            'contains_non_ascii' => preg_match('/[^\x20-\x7E]/', $password) === 1,
        ];
    }

    private function normaliseSubmittedLoginPassword(string $password): string
    {
        $password = trim($password);
        $normalisedPassword = preg_replace('/^\p{Cf}+|\p{Cf}+$/u', '', $password);

        return is_string($normalisedPassword) ? $normalisedPassword : $password;
    }

    private function loginFailureReason(string $reason, array $passwordDiagnostics): string
    {
        $reason = trim($reason);
        $enteredLength = max(0, (int)($passwordDiagnostics['entered_length'] ?? 0));
        $trimmedLength = max(0, (int)($passwordDiagnostics['trimmed_length'] ?? 0));
        $loginLength = max(0, (int)($passwordDiagnostics['login_length'] ?? $trimmedLength));
        $trimmedWhitespace = !empty($passwordDiagnostics['trimmed_whitespace']) ? 'yes' : 'no';
        $removedFormatCharacters = !empty($passwordDiagnostics['removed_format_characters']) ? 'yes' : 'no';
        $containsNonAscii = !empty($passwordDiagnostics['contains_non_ascii']) ? 'yes' : 'no';

        return mb_substr(
            $reason
                . ' Entered password length: ' . $enteredLength
                . '; trimmed length: ' . $trimmedLength
                . '; login length: ' . $loginLength
                . '; whitespace removed: ' . $trimmedWhitespace
                . '; invisible format chars removed: ' . $removedFormatCharacters
                . '; non-ASCII present: ' . $containsNonAscii . '.',
            0,
            255
        );
    }

    public function completeRequiredPasswordChange(string $password, string $passwordConfirmation, string $deviceId): array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $userId = $this->sessionAuthenticationService->pendingPasswordChangeUserId($deviceId);

        if ($userId <= 0) {
            return [
                'success' => false,
                'authenticated' => false,
                'errors' => ['Your password change session expired or changed device. Please sign in again.'],
            ];
        }

        if ($password !== $passwordConfirmation) {
            return [
                'success' => false,
                'authenticated' => false,
                'requires_password_change' => true,
                'errors' => ['The new password and confirmation do not match.'],
            ];
        }

        $result = $this->userAuthenticationService->setPasswordDirectly($userId, $password);

        if (empty($result['success'])) {
            return [
                'success' => false,
                'authenticated' => false,
                'requires_password_change' => true,
                'errors' => (array)($result['errors'] ?? ['Password update failed.']),
            ];
        }

        $user = $this->userAuthenticationService->userById($userId);
        $this->userHistoryStore->recordAccountAudit(
            $userId,
            $userId,
            'password_changed_self',
            'The user changed their password during sign-in.',
            ['forced_change' => true],
            $this->userSessionService->buildRequestMetadata($deviceId)
        );
        $this->sessionAuthenticationService->clearPendingPasswordChange();

        return $this->continueAfterPrimaryCredentials($user ?? [], $deviceId);
    }

    private function continueAfterPrimaryCredentials(array $user, string $deviceId): array
    {
        $userId = max(0, (int)($user['id'] ?? 0));
        $emailAddress = (string)($user['email_address'] ?? '');

        if ($userId <= 0) {
            throw new RuntimeException('Authenticated user could not be resolved.');
        }

        if ($this->otpService->isOTPenabled($userId)) {
            $this->sessionAuthenticationService->beginPendingOtp($userId, $deviceId);

            return [
                'success' => true,
                'authenticated' => false,
                'requires_otp' => true,
                'errors' => [],
            ];
        }

        if ((int)($user['otp_required'] ?? 1) !== 1) {
            $session = $this->userSessionService->startAuthenticatedSession($userId, $deviceId, $emailAddress);
            $this->userHistoryStore->attachSessionTokenHashToLatestLogonEvent(
                $userId,
                'login_succeeded',
                (string)$session['session_token_hash']
            );
            $this->sessionAuthenticationService->completeAuthentication(
                $userId,
                $deviceId,
                (string)$session['session_token_hash'],
                $emailAddress
            );

            return [
                'success' => true,
                'authenticated' => true,
                'requires_otp' => false,
                'requires_otp_setup' => false,
                'errors' => [],
            ];
        }

        $this->sessionAuthenticationService->beginPendingOtpSetup($userId, $deviceId);
        $this->userHistoryStore->recordLogonEvent(
            $userId,
            $emailAddress,
            'otp_setup_started',
            true,
            'OTP enrollment is required before access is granted.',
            null,
            $this->userSessionService->buildRequestMetadata($deviceId)
        );

        return [
            'success' => true,
            'authenticated' => false,
            'requires_otp' => false,
            'requires_otp_setup' => true,
            'errors' => [],
        ];
    }

    public function completeOtpLogin(string $code, string $deviceId): array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $userId = $this->sessionAuthenticationService->pendingOtpUserId($deviceId);

        if ($userId <= 0) {
            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => false,
                'errors' => ['Your sign-in session expired or changed device. Please sign in again.'],
            ];
        }

        if (!$this->otpService->checkOTP($userId, $code, true)) {
            $attempts = $this->sessionAuthenticationService->recordPendingOtpFailure();
            $user = $this->userAuthenticationService->userById($userId);
            $this->userHistoryStore->recordLogonEvent(
                $userId,
                (string)($user['email_address'] ?? ''),
                'otp_challenge_failed',
                false,
                'The submitted OTP code was not recognised.',
                null,
                $this->userSessionService->buildRequestMetadata($deviceId)
            );

            if ($attempts >= $this->sessionAuthenticationService->maxPendingOtpAttempts()) {
                $this->sessionAuthenticationService->clearPendingOtp();

                return [
                    'success' => false,
                    'authenticated' => false,
                    'requires_otp' => false,
                    'errors' => ['Too many invalid OTP attempts. Please sign in again.'],
                ];
            }

            return [
                'success' => false,
                'authenticated' => false,
                'requires_otp' => true,
                'errors' => ['The OTP code was not recognised.'],
            ];
        }

        $user = $this->userAuthenticationService->userById($userId);
        $session = $this->userSessionService->startAuthenticatedSession(
            $userId,
            $deviceId,
            (string)($user['email_address'] ?? '')
        );
        $mfaContext = $this->buildTotpMfaContext($userId);
        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($user['email_address'] ?? ''),
            'otp_challenge_passed',
            true,
            'OTP challenge completed successfully.',
            (string)$session['session_token_hash'],
            (array)($session['metadata'] ?? [])
        );
        $this->userHistoryStore->attachSessionTokenHashToLatestLogonEvent(
            $userId,
            'login_succeeded',
            (string)$session['session_token_hash']
        );
        $this->userHistoryStore->recordAccountAudit(
            $userId,
            $userId,
            'mfa_authenticated',
            'TOTP challenge completed successfully.',
            $mfaContext,
            (array)($session['metadata'] ?? [])
        );
        $this->sessionAuthenticationService->completeAuthentication(
            $userId,
            $deviceId,
            (string)$session['session_token_hash'],
            (string)($user['email_address'] ?? ''),
            $mfaContext
        );

        return [
            'success' => true,
            'authenticated' => true,
            'requires_otp' => false,
            'errors' => [],
        ];
    }

    public function logout(): void
    {
        $userId = $this->sessionAuthenticationService->authenticatedUserId();
        $sessionTokenHash = $this->sessionAuthenticationService->authenticatedSessionTokenHash();

        if ($userId > 0 && $sessionTokenHash !== '') {
            $this->userSessionService->clearAuthenticatedSession(
                $userId,
                $sessionTokenHash,
                'logout',
                'The user signed out from the app.'
            );
        }

        $this->sessionAuthenticationService->logout();
    }

    public function beginOtpSetup(int $userId, string $deviceId, bool $recordPrimaryLoginAccepted = false): array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $user = $this->userAuthenticationService->userById($userId);

        if ($recordPrimaryLoginAccepted) {
            $this->userHistoryStore->recordLogonEvent(
                $userId,
                (string)($user['email_address'] ?? ''),
                'login_succeeded',
                true,
                'Primary sign-in credentials were accepted.',
                null,
                $this->userSessionService->buildRequestMetadata($deviceId)
            );
        }

        $this->sessionAuthenticationService->beginPendingOtpSetup($userId, $deviceId);
        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($user['email_address'] ?? ''),
            'otp_setup_started',
            true,
            'OTP enrollment has started for this account.',
            null,
            $this->userSessionService->buildRequestMetadata($deviceId)
        );

        return $this->pendingOtpSetupViewData($deviceId) ?? throw new RuntimeException('OTP setup could not be started.');
    }

    public function pendingOtpSetupViewData(string $deviceId): ?array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $userId = $this->sessionAuthenticationService->pendingOtpSetupUserId($deviceId);

        if ($userId <= 0) {
            return null;
        }

        $this->otpService->ensureOTPsecret($userId);
        $otpauthUri = $this->otpService->generateOTPstring($userId);

        return [
            'qr_svg' => $this->qrCodeService->generateSvg($otpauthUri, [
                'error_correction_level' => 'auto',
                'module_size' => 'auto',
            ]),
            'otpauth_uri' => $otpauthUri,
            'manual_secret' => $this->otpService->getManualEntrySecret($userId),
        ];
    }

    public function completeOtpSetup(string $code, string $deviceId): array
    {
        $this->sessionAuthenticationService->invalidateForDeviceMismatch($deviceId);
        $userId = $this->sessionAuthenticationService->pendingOtpSetupUserId($deviceId);

        if ($userId <= 0) {
            return [
                'success' => false,
                'authenticated' => false,
                'errors' => ['Your OTP setup session expired or changed device. Please sign in again.'],
            ];
        }

        if (!$this->otpService->enableOTP($userId, $code)) {
            return [
                'success' => false,
                'authenticated' => false,
                'errors' => ['The OTP code did not match the QR setup for this account.'],
            ];
        }

        $this->sessionAuthenticationService->clearPendingOtpSetup();
        $user = $this->userAuthenticationService->userById($userId);
        $session = $this->userSessionService->startAuthenticatedSession(
            $userId,
            $deviceId,
            (string)($user['email_address'] ?? '')
        );
        $mfaContext = $this->buildTotpMfaContext($userId);
        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($user['email_address'] ?? ''),
            'otp_setup_completed',
            true,
            'OTP enrollment completed successfully.',
            (string)$session['session_token_hash'],
            (array)($session['metadata'] ?? [])
        );
        $this->userHistoryStore->attachSessionTokenHashToLatestLogonEvent(
            $userId,
            'login_succeeded',
            (string)$session['session_token_hash']
        );
        $this->userHistoryStore->recordAccountAudit(
            $userId,
            $userId,
            'mfa_authenticated',
            'TOTP setup completed and authenticated successfully.',
            $mfaContext,
            (array)($session['metadata'] ?? [])
        );
        $this->sessionAuthenticationService->completeAuthentication(
            $userId,
            $deviceId,
            (string)$session['session_token_hash'],
            (string)($user['email_address'] ?? ''),
            $mfaContext
        );

        return [
            'success' => true,
            'authenticated' => true,
            'errors' => [],
        ];
    }

    private function buildTotpMfaContext(int $userId): array
    {
        return [
            'type' => 'TOTP',
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'unique_reference' => hash_hmac('sha256', 'eel-accounts:totp:' . $userId, $this->pepper()),
        ];
    }

    private function pepper(): string
    {
        if ($this->pepper !== null) {
            return $this->pepper;
        }

        $this->pepper = SecurityStore::ensureFact('pepper');

        return $this->pepper;
    }
}
