<?php
declare(strict_types=1);

final class SessionAuthenticationService
{
    private const SESSION_NAME = 'ELL_ID';
    private const AUTH_USER_ID_KEY = 'auth.user_id';
    private const AUTH_DEVICE_ID_KEY = 'auth.device_id';
    private const AUTH_SESSION_TOKEN_HASH_KEY = 'auth.session_token_hash';
    private const AUTHENTICATED_AT_KEY = 'auth.authenticated_at';
    private const AUTH_USER_EMAIL_KEY = 'auth.user_email';
    private const AUTH_MFA_TYPE_KEY = 'auth.mfa.type';
    private const AUTH_MFA_TIMESTAMP_KEY = 'auth.mfa.timestamp';
    private const AUTH_MFA_UNIQUE_REFERENCE_KEY = 'auth.mfa.unique_reference';
    private const PENDING_OTP_USER_ID_KEY = 'auth.pending_otp.user_id';
    private const PENDING_OTP_DEVICE_ID_KEY = 'auth.pending_otp.device_id';
    private const PENDING_OTP_STARTED_AT_KEY = 'auth.pending_otp.started_at';
    private const PENDING_OTP_ATTEMPTS_KEY = 'auth.pending_otp.attempts';
    private const PENDING_OTP_SETUP_USER_ID_KEY = 'auth.pending_otp_setup.user_id';
    private const PENDING_OTP_SETUP_DEVICE_ID_KEY = 'auth.pending_otp_setup.device_id';
    private const PENDING_OTP_SETUP_STARTED_AT_KEY = 'auth.pending_otp_setup.started_at';
    private const CSRF_TOKEN_KEY = 'auth.csrf_token';
    private const LOGOUT_NOTICE_KEY = 'auth.logout_notice';

    public function __construct(
        private readonly int $pendingOtpLifetimeSeconds = 300,
        private readonly int $maxPendingOtpAttempts = 5,
    ) {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name(self::SESSION_NAME);
        session_start();
    }

    public function csrfToken(): string
    {
        $this->startSession();

        $token = (string)($_SESSION[self::CSRF_TOKEN_KEY] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_TOKEN_KEY] = $token;
        }

        return $token;
    }

    public function isValidCsrfToken(string $submittedToken): bool
    {
        $this->startSession();

        $submittedToken = trim($submittedToken);
        $sessionToken = (string)($_SESSION[self::CSRF_TOKEN_KEY] ?? '');

        return $submittedToken !== ''
            && $sessionToken !== ''
            && hash_equals($sessionToken, $submittedToken);
    }

    public function beginPendingOtp(int $userId, string $deviceId): void
    {
        $this->startSession();
        $this->regenerateSessionId();

        $deviceId = $this->normaliseRequiredDeviceId($deviceId);
        $this->clearAuthenticationState();

        $_SESSION[self::PENDING_OTP_USER_ID_KEY] = $userId;
        $_SESSION[self::PENDING_OTP_DEVICE_ID_KEY] = $deviceId;
        $_SESSION[self::PENDING_OTP_STARTED_AT_KEY] = time();
        $_SESSION[self::PENDING_OTP_ATTEMPTS_KEY] = 0;
    }

    public function completeAuthentication(
        int $userId,
        string $deviceId,
        ?string $sessionTokenHash = null,
        ?string $userEmail = null,
        ?array $mfaContext = null
    ): void
    {
        $this->startSession();
        $this->regenerateSessionId();

        $deviceId = $this->normaliseRequiredDeviceId($deviceId);
        $this->clearPendingOtpSetup();
        $this->clearPendingOtp();

        $_SESSION[self::AUTH_USER_ID_KEY] = $userId;
        $_SESSION[self::AUTH_DEVICE_ID_KEY] = $deviceId;
        $_SESSION[self::AUTH_SESSION_TOKEN_HASH_KEY] = trim((string)$sessionTokenHash);
        $_SESSION[self::AUTHENTICATED_AT_KEY] = time();
        $_SESSION[self::AUTH_USER_EMAIL_KEY] = trim((string)$userEmail);
        $_SESSION[self::AUTH_MFA_TYPE_KEY] = trim((string)($mfaContext['type'] ?? ''));
        $_SESSION[self::AUTH_MFA_TIMESTAMP_KEY] = trim((string)($mfaContext['timestamp'] ?? ''));
        $_SESSION[self::AUTH_MFA_UNIQUE_REFERENCE_KEY] = trim((string)($mfaContext['unique_reference'] ?? ''));
    }

    public function authenticatedUserId(?string $currentDeviceId = null): int
    {
        $this->startSession();

        $userId = max(0, (int)($_SESSION[self::AUTH_USER_ID_KEY] ?? 0));
        $deviceId = (string)($_SESSION[self::AUTH_DEVICE_ID_KEY] ?? '');

        if ($userId <= 0 || $deviceId === '') {
            return 0;
        }

        if ($currentDeviceId !== null && !$this->deviceMatches($deviceId, $currentDeviceId)) {
            return 0;
        }

        return $userId;
    }

    public function isAuthenticated(?string $currentDeviceId = null): bool
    {
        return $this->authenticatedUserId($currentDeviceId) > 0;
    }

    public function pendingOtpUserId(?string $currentDeviceId = null): int
    {
        $this->startSession();

        if ($this->pendingOtpExpired()) {
            $this->clearPendingOtp();
            return 0;
        }

        $userId = max(0, (int)($_SESSION[self::PENDING_OTP_USER_ID_KEY] ?? 0));
        $deviceId = (string)($_SESSION[self::PENDING_OTP_DEVICE_ID_KEY] ?? '');

        if ($userId <= 0 || $deviceId === '') {
            return 0;
        }

        if ($currentDeviceId !== null && !$this->deviceMatches($deviceId, $currentDeviceId)) {
            return 0;
        }

        return $userId;
    }

    public function hasPendingOtp(?string $currentDeviceId = null): bool
    {
        return $this->pendingOtpUserId($currentDeviceId) > 0;
    }

    public function beginPendingOtpSetup(int $userId, string $deviceId): void
    {
        $this->startSession();
        $this->regenerateSessionId();

        $deviceId = $this->normaliseRequiredDeviceId($deviceId);
        $this->clearAuthenticationState();

        $_SESSION[self::PENDING_OTP_SETUP_USER_ID_KEY] = $userId;
        $_SESSION[self::PENDING_OTP_SETUP_DEVICE_ID_KEY] = $deviceId;
        $_SESSION[self::PENDING_OTP_SETUP_STARTED_AT_KEY] = time();
    }

    public function pendingOtpSetupUserId(?string $currentDeviceId = null): int
    {
        $this->startSession();

        if ($this->pendingOtpSetupExpired()) {
            $this->clearPendingOtpSetup();
            return 0;
        }

        $userId = max(0, (int)($_SESSION[self::PENDING_OTP_SETUP_USER_ID_KEY] ?? 0));
        $deviceId = (string)($_SESSION[self::PENDING_OTP_SETUP_DEVICE_ID_KEY] ?? '');

        if ($userId <= 0 || $deviceId === '') {
            return 0;
        }

        if ($currentDeviceId !== null && !$this->deviceMatches($deviceId, $currentDeviceId)) {
            return 0;
        }

        return $userId;
    }

    public function hasPendingOtpSetup(?string $currentDeviceId = null): bool
    {
        return $this->pendingOtpSetupUserId($currentDeviceId) > 0;
    }

    public function recordPendingOtpFailure(): int
    {
        $this->startSession();

        if (!$this->hasPendingOtp()) {
            return 0;
        }

        $attempts = max(0, (int)($_SESSION[self::PENDING_OTP_ATTEMPTS_KEY] ?? 0)) + 1;
        $_SESSION[self::PENDING_OTP_ATTEMPTS_KEY] = $attempts;

        return $attempts;
    }

    public function maxPendingOtpAttempts(): int
    {
        return $this->maxPendingOtpAttempts;
    }

    public function invalidateForDeviceMismatch(?string $currentDeviceId): void
    {
        $this->startSession();

        $currentDeviceId = $this->normaliseDeviceId($currentDeviceId);
        $authenticatedDeviceId = (string)($_SESSION[self::AUTH_DEVICE_ID_KEY] ?? '');
        $pendingDeviceId = (string)($_SESSION[self::PENDING_OTP_DEVICE_ID_KEY] ?? '');
        $pendingSetupDeviceId = (string)($_SESSION[self::PENDING_OTP_SETUP_DEVICE_ID_KEY] ?? '');

        if ($authenticatedDeviceId !== '' && !$this->deviceMatches($authenticatedDeviceId, $currentDeviceId)) {
            $this->logout([
                'type' => 'error',
                'message' => 'Your sign-in session no longer matches this device. Please sign in again.',
            ]);
            return;
        }

        if ($pendingDeviceId !== '' && !$this->deviceMatches($pendingDeviceId, $currentDeviceId)) {
            $this->clearPendingOtp();
        }

        if ($pendingSetupDeviceId !== '' && !$this->deviceMatches($pendingSetupDeviceId, $currentDeviceId)) {
            $this->clearPendingOtpSetup();
        }
    }

    public function clearPendingOtp(): void
    {
        $this->startSession();

        unset(
            $_SESSION[self::PENDING_OTP_USER_ID_KEY],
            $_SESSION[self::PENDING_OTP_DEVICE_ID_KEY],
            $_SESSION[self::PENDING_OTP_STARTED_AT_KEY],
            $_SESSION[self::PENDING_OTP_ATTEMPTS_KEY]
        );
    }

    public function authenticatedSessionTokenHash(): string
    {
        $this->startSession();

        return trim((string)($_SESSION[self::AUTH_SESSION_TOKEN_HASH_KEY] ?? ''));
    }

    public function authenticatedAntiFraudContext(?string $currentDeviceId = null): array
    {
        $userId = $this->authenticatedUserId($currentDeviceId);

        if ($userId <= 0) {
            return [];
        }

        return [
            'user_id' => $userId,
            'email_address' => trim((string)($_SESSION[self::AUTH_USER_EMAIL_KEY] ?? '')),
            'mfa' => [
                'type' => trim((string)($_SESSION[self::AUTH_MFA_TYPE_KEY] ?? '')),
                'timestamp' => trim((string)($_SESSION[self::AUTH_MFA_TIMESTAMP_KEY] ?? '')),
                'unique_reference' => trim((string)($_SESSION[self::AUTH_MFA_UNIQUE_REFERENCE_KEY] ?? '')),
            ],
        ];
    }

    public function logout(?array $notice = null): void
    {
        $this->startSession();

        if (is_array($notice) && $notice !== []) {
            $_SESSION[self::LOGOUT_NOTICE_KEY] = $notice;
        }

        $this->clearAuthenticationState();
        $this->regenerateSessionId();
    }

    public function consumeLogoutNotice(): ?array
    {
        $this->startSession();

        $notice = $_SESSION[self::LOGOUT_NOTICE_KEY] ?? null;
        unset($_SESSION[self::LOGOUT_NOTICE_KEY]);

        return is_array($notice) ? $notice : null;
    }

    public function clearPendingOtpSetup(): void
    {
        $this->startSession();

        unset(
            $_SESSION[self::PENDING_OTP_SETUP_USER_ID_KEY],
            $_SESSION[self::PENDING_OTP_SETUP_DEVICE_ID_KEY],
            $_SESSION[self::PENDING_OTP_SETUP_STARTED_AT_KEY]
        );
    }

    private function clearAuthenticationState(): void
    {
        unset(
            $_SESSION[self::AUTH_USER_ID_KEY],
            $_SESSION[self::AUTH_DEVICE_ID_KEY],
            $_SESSION[self::AUTH_SESSION_TOKEN_HASH_KEY],
            $_SESSION[self::AUTHENTICATED_AT_KEY],
            $_SESSION[self::AUTH_USER_EMAIL_KEY],
            $_SESSION[self::AUTH_MFA_TYPE_KEY],
            $_SESSION[self::AUTH_MFA_TIMESTAMP_KEY],
            $_SESSION[self::AUTH_MFA_UNIQUE_REFERENCE_KEY]
        );

        $this->clearPendingOtpSetup();
        $this->clearPendingOtp();
    }

    private function pendingOtpExpired(): bool
    {
        $startedAt = max(0, (int)($_SESSION[self::PENDING_OTP_STARTED_AT_KEY] ?? 0));

        return $startedAt > 0 && ($startedAt + $this->pendingOtpLifetimeSeconds) < time();
    }

    private function pendingOtpSetupExpired(): bool
    {
        $startedAt = max(0, (int)($_SESSION[self::PENDING_OTP_SETUP_STARTED_AT_KEY] ?? 0));

        return $startedAt > 0 && ($startedAt + $this->pendingOtpLifetimeSeconds) < time();
    }

    private function normaliseRequiredDeviceId(string $deviceId): string
    {
        $deviceId = $this->normaliseDeviceId($deviceId);

        if ($deviceId === '') {
            throw new RuntimeException('A recognised device identifier is required before signing in.');
        }

        return $deviceId;
    }

    private function normaliseDeviceId(?string $deviceId): string
    {
        return trim((string)$deviceId);
    }

    private function deviceMatches(string $expectedDeviceId, ?string $currentDeviceId): bool
    {
        return $expectedDeviceId !== '' && $expectedDeviceId === $this->normaliseDeviceId($currentDeviceId);
    }

    private function regenerateSessionId(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    private function isSecureRequest(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));

        return $https === 'on' || $https === '1' || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}
