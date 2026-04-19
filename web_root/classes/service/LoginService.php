<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class LoginService
{
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
            $rateLimit = $this->userAuthenticationService->recordFailedPasswordAttempt($emailAddress, $deviceId);
            $this->sessionAuthenticationService->clearPendingOtpSetup();
            $this->sessionAuthenticationService->clearPendingOtp();
            $this->userHistoryStore->recordLogonEvent(
                null,
                $emailAddress,
                'login_failed',
                false,
                'Invalid email address or password.',
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
                        ? ['Incorrect password. Please wait before trying again.']
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

        if ($this->otpService->isOTPenabled($userId)) {
            $this->sessionAuthenticationService->beginPendingOtp($userId, $deviceId);

            return [
                'success' => true,
                'authenticated' => false,
                'requires_otp' => true,
                'errors' => [],
            ];
        }

        $this->sessionAuthenticationService->beginPendingOtpSetup($userId, $deviceId);
        $this->userHistoryStore->recordLogonEvent(
            $userId,
            (string)($user['email_address'] ?? ''),
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
