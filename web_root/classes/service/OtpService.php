<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class OtpService
{
    private const DEFAULT_ISSUER = 'eel';
    private const DEFAULT_ALGORITHM = 'SHA1';
    private const DEFAULT_DIGITS = 6;
    private const DEFAULT_PERIOD = 30;
    private const DEFAULT_SECRET_BYTES = 20;
    private const DEFAULT_WINDOW = 1;
    private const ENCRYPTION_PREFIX = 'eel:v1:gcm:';
    private const DEFAULT_PENDING_SECRET_LIFETIME_SECONDS = 300;

    private string $issuer;
    private OtpVerificationService $otpVerificationService;
    private ?string $encryptionKey = null;

    public function __construct(string $issuer = self::DEFAULT_ISSUER)
    {
        $this->issuer = trim($issuer) !== '' ? $issuer : self::DEFAULT_ISSUER;
        $this->otpVerificationService = new OtpVerificationService();
    }

    public function generateOTPsecret(int $userId): string
    {
        $this->assertUserExists($userId);

        $secret = $this->generateBase32Secret(self::DEFAULT_SECRET_BYTES);
        $params = [
            'user_id' => $userId,
            'otp_secret' => $this->encryptSecret($secret),
            'otp_algorithm' => self::DEFAULT_ALGORITHM,
            'otp_digits' => self::DEFAULT_DIGITS,
            'otp_period' => self::DEFAULT_PERIOD,
        ];

        if ($this->getUserTotpRow($userId) !== null) {
            InterfaceDB::prepareExecute(
                'UPDATE user_totp
                 SET otp_secret = :otp_secret,
                     otp_algorithm = :otp_algorithm,
                     otp_digits = :otp_digits,
                     otp_period = :otp_period,
                     otp_enabled = 0,
                     otp_confirmed_at = NULL,
                     otp_last_used_timestep = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id',
                $params
            );
        } else {
            InterfaceDB::prepareExecute(
                'INSERT INTO user_totp (
                    user_id,
                    otp_secret,
                    otp_algorithm,
                    otp_digits,
                    otp_period,
                    otp_enabled,
                    otp_confirmed_at,
                    otp_last_used_timestep,
                    created_at,
                    updated_at
                )
                VALUES (
                    :user_id,
                    :otp_secret,
                    :otp_algorithm,
                    :otp_digits,
                    :otp_period,
                    0,
                    NULL,
                    NULL,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )',
                $params
            );
        }

        return $secret;
    }

    public function generateOTPstring(int $userId): string
    {
        $user = $this->getUser($userId);
        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['otp_secret'])) {
            throw new RuntimeException('No OTP secret exists for this user.');
        }

        $label = $this->buildLabel((string)$user['display_name']);
        $issuerEncoded = rawurlencode($this->issuer);
        $labelEncoded = rawurlencode($label);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $labelEncoded,
            rawurlencode((string)$totp['otp_secret']),
            $issuerEncoded,
            rawurlencode((string)$totp['otp_algorithm']),
            (int)$totp['otp_digits'],
            (int)$totp['otp_period']
        );
    }

    public function checkOTP(int $userId, string $code, bool $preventReplay = true): bool
    {
        return $this->verifyOtpCode($userId, $code, $preventReplay, true);
    }

    public function enableOTP(int $userId, string $code): bool
    {
        if (!$this->verifyOtpCode($userId, $code, true, false)) {
            return false;
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_enabled = 1,
                 otp_confirmed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return true;
    }

    public function disableOTP(int $userId): bool
    {
        $statement = InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_secret = NULL,
                 otp_enabled = 0,
                 otp_confirmed_at = NULL,
                 otp_last_used_timestep = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return $statement->rowCount() > 0;
    }

    public function isOTPenabled(int $userId): bool
    {
        $value = InterfaceDB::fetchColumn(
            'SELECT otp_enabled
             FROM user_totp
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        return $value !== false && (int)$value === 1;
    }

    public function rotateOTPsecret(int $userId): string
    {
        return $this->generateOTPsecret($userId);
    }

    public function beginPendingOtpEnrollment(int $userId): string
    {
        $this->assertUserExists($userId);

        $secret = $this->generateBase32Secret(self::DEFAULT_SECRET_BYTES);
        $params = [
            'user_id' => $userId,
            'pending_otp_secret' => $this->encryptSecret($secret),
            'pending_otp_algorithm' => self::DEFAULT_ALGORITHM,
            'pending_otp_digits' => self::DEFAULT_DIGITS,
            'pending_otp_period' => self::DEFAULT_PERIOD,
        ];

        if ($this->getUserTotpRow($userId) !== null) {
            InterfaceDB::prepareExecute(
                'UPDATE user_totp
                 SET pending_otp_secret = :pending_otp_secret,
                     pending_otp_algorithm = :pending_otp_algorithm,
                     pending_otp_digits = :pending_otp_digits,
                     pending_otp_period = :pending_otp_period,
                     pending_otp_requested_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = :user_id',
                $params
            );
        } else {
            InterfaceDB::prepareExecute(
                'INSERT INTO user_totp (
                    user_id,
                    otp_secret,
                    pending_otp_secret,
                    pending_otp_algorithm,
                    pending_otp_digits,
                    pending_otp_period,
                    pending_otp_requested_at,
                    otp_algorithm,
                    otp_digits,
                    otp_period,
                    otp_enabled,
                    otp_confirmed_at,
                    otp_last_used_timestep,
                    created_at,
                    updated_at
                )
                VALUES (
                    :user_id,
                    NULL,
                    :pending_otp_secret,
                    :pending_otp_algorithm,
                    :pending_otp_digits,
                    :pending_otp_period,
                    CURRENT_TIMESTAMP,
                    :pending_otp_algorithm,
                    :pending_otp_digits,
                    :pending_otp_period,
                    0,
                    NULL,
                    NULL,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )',
                $params
            );
        }

        return $secret;
    }

    public function hasPendingOtpSecret(int $userId): bool
    {
        $totp = $this->getUserTotpRow($userId);

        return $totp !== null && !empty($totp['pending_otp_secret']);
    }

    public function clearExpiredPendingOtpEnrollments(?int $userId = null): int
    {
        $lifetimeSeconds = $this->pendingSecretLifetimeSeconds();
        $params = [
            'expires_before' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('-' . $lifetimeSeconds . ' seconds')
                ->format('Y-m-d H:i:s'),
        ];
        $userSql = '';

        if ($userId !== null) {
            if ($userId <= 0) {
                return 0;
            }

            $userSql = ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $statement = InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET pending_otp_secret = NULL,
                 pending_otp_algorithm = NULL,
                 pending_otp_digits = NULL,
                 pending_otp_period = NULL,
                 pending_otp_requested_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE pending_otp_secret IS NOT NULL
               AND pending_otp_requested_at IS NOT NULL
               AND pending_otp_requested_at < :expires_before' . $userSql,
            $params
        );

        return $statement->rowCount();
    }

    public function pendingManualEntrySecret(int $userId): string
    {
        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['pending_otp_secret'])) {
            throw new RuntimeException('No pending OTP secret exists for this user.');
        }

        return (string)$totp['pending_otp_secret'];
    }

    public function generatePendingOtpString(int $userId): string
    {
        $user = $this->getUser($userId);
        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['pending_otp_secret'])) {
            throw new RuntimeException('No pending OTP secret exists for this user.');
        }

        return $this->buildOtpAuthUri(
            (string)$user['display_name'],
            (string)$totp['pending_otp_secret'],
            (string)($totp['pending_otp_algorithm'] ?? self::DEFAULT_ALGORITHM),
            (int)($totp['pending_otp_digits'] ?? self::DEFAULT_DIGITS),
            (int)($totp['pending_otp_period'] ?? self::DEFAULT_PERIOD)
        );
    }

    public function completePendingOtpEnrollment(int $userId, string $code): bool
    {
        $matchedTimestep = $this->verifyPendingOtpCode($userId, $code);

        if ($matchedTimestep === null) {
            return false;
        }

        $totp = $this->getUserTotpRow($userId);
        if ($totp === null || empty($totp['pending_otp_secret'])) {
            return false;
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_secret = :otp_secret,
                 otp_algorithm = COALESCE(pending_otp_algorithm, otp_algorithm),
                 otp_digits = COALESCE(pending_otp_digits, otp_digits),
                 otp_period = COALESCE(pending_otp_period, otp_period),
                 otp_enabled = 1,
                 otp_confirmed_at = CURRENT_TIMESTAMP,
                 otp_last_used_timestep = :otp_last_used_timestep,
                 pending_otp_secret = NULL,
                 pending_otp_algorithm = NULL,
                 pending_otp_digits = NULL,
                 pending_otp_period = NULL,
                 pending_otp_requested_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id',
            [
                'user_id' => $userId,
                'otp_secret' => $this->encryptSecret((string)$totp['pending_otp_secret']),
                'otp_last_used_timestep' => $matchedTimestep,
            ]
        );

        return true;
    }

    public function resetOtp(int $userId): bool
    {
        $statement = InterfaceDB::prepareExecute(
            'DELETE FROM user_totp
             WHERE user_id = :user_id',
            ['user_id' => $userId]
        );

        return $statement->rowCount() > 0;
    }

    public function hasOTPsecret(int $userId): bool
    {
        $totp = $this->getUserTotpRow($userId);

        return $totp !== null && !empty($totp['otp_secret']);
    }

    public function ensureOTPsecret(int $userId): string
    {
        if ($this->hasOTPsecret($userId)) {
            return $this->getManualEntrySecret($userId);
        }

        return $this->generateOTPsecret($userId);
    }

    public function getManualEntrySecret(int $userId): string
    {
        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['otp_secret'])) {
            throw new RuntimeException('No OTP secret exists for this user.');
        }

        return (string)$totp['otp_secret'];
    }

    private function verifyOtpCode(int $userId, string $code, bool $preventReplay, bool $requireEnabled): bool
    {
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['otp_secret'])) {
            return false;
        }

        if ($requireEnabled && (int)($totp['otp_enabled'] ?? 0) !== 1) {
            return false;
        }

        $matchedTimestep = $this->otpVerificationService->verifyCode(
            $code,
            (int)$totp['otp_digits'],
            strtoupper((string)$totp['otp_algorithm']),
            (int)$totp['otp_period'],
            (string)$totp['otp_secret'],
            $this->currentUnixTime(),
            self::DEFAULT_WINDOW,
            $totp['otp_last_used_timestep'] !== null ? (int)$totp['otp_last_used_timestep'] : null,
            $preventReplay
        );

        if ($matchedTimestep === null) {
            return false;
        }

        if ($preventReplay) {
            $this->updateLastUsedTimestep($userId, $matchedTimestep);
        }

        return true;
    }

    private function assertUserExists(int $userId): void
    {
        if (
            InterfaceDB::fetchColumn(
                'SELECT id
                 FROM users
                 WHERE id = :user_id
                 LIMIT 1',
                ['user_id' => $userId]
            ) === false
        ) {
            throw new RuntimeException('User not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getUser(int $userId): array
    {
        $row = InterfaceDB::fetchOne(
            'SELECT id, display_name
             FROM users
             WHERE id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        if (!is_array($row)) {
            throw new RuntimeException('User not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getUserTotpRow(int $userId): ?array
    {
        $this->clearExpiredPendingOtpEnrollments($userId);

        $row = InterfaceDB::fetchOne(
            'SELECT
                user_id,
                otp_secret,
                pending_otp_secret,
                pending_otp_algorithm,
                pending_otp_digits,
                pending_otp_period,
                pending_otp_requested_at,
                otp_algorithm,
                otp_digits,
                otp_period,
                otp_enabled,
                otp_confirmed_at,
                otp_last_used_timestep
             FROM user_totp
             WHERE user_id = :user_id
             LIMIT 1',
            ['user_id' => $userId]
        );

        if (!is_array($row)) {
            return null;
        }

        return $this->decryptTotpRow($row);
    }

    private function updateLastUsedTimestep(int $userId, int $timestep): void
    {
        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_last_used_timestep = :otp_last_used_timestep,
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id',
            [
                'user_id' => $userId,
                'otp_last_used_timestep' => $timestep,
            ]
        );
    }

    private function currentUnixTime(): int
    {
        return time();
    }

    private function verifyPendingOtpCode(int $userId, string $code): ?int
    {
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $totp = $this->getUserTotpRow($userId);

        if ($totp === null || empty($totp['pending_otp_secret'])) {
            return null;
        }

        return $this->otpVerificationService->verifyCode(
            $code,
            (int)($totp['pending_otp_digits'] ?? self::DEFAULT_DIGITS),
            strtoupper((string)($totp['pending_otp_algorithm'] ?? self::DEFAULT_ALGORITHM)),
            (int)($totp['pending_otp_period'] ?? self::DEFAULT_PERIOD),
            (string)$totp['pending_otp_secret'],
            $this->currentUnixTime(),
            self::DEFAULT_WINDOW,
            null,
            true
        );
    }

    private function buildLabel(string $displayName): string
    {
        $displayName = trim($displayName);

        if ($displayName === '') {
            $displayName = 'User';
        }

        return $this->issuer . ':' . $displayName;
    }

    private function buildOtpAuthUri(
        string $displayName,
        string $secret,
        string $algorithm,
        int $digits,
        int $period
    ): string {
        $label = $this->buildLabel($displayName);
        $issuerEncoded = rawurlencode($this->issuer);
        $labelEncoded = rawurlencode($label);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            $labelEncoded,
            rawurlencode($secret),
            $issuerEncoded,
            rawurlencode($algorithm),
            $digits,
            $period
        );
    }

    private function generateBase32Secret(int $byteLength): string
    {
        return $this->base32Encode(random_bytes($byteLength));
    }

    private function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binaryString = '';

        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $binaryString .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binaryString, 5);
        $encoded = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function decryptTotpRow(array $row): array
    {
        $updates = [];

        foreach (['otp_secret', 'pending_otp_secret'] as $field) {
            $stored = trim((string)($row[$field] ?? ''));
            if ($stored === '') {
                continue;
            }

            $resolved = $this->decryptSecret($stored);
            $row[$field] = $resolved['secret'];

            if (!empty($resolved['legacy'])) {
                $updates[$field] = $this->encryptSecret((string)$resolved['secret']);
            }
        }

        if ($updates !== []) {
            $this->updateStoredSecrets((int)($row['user_id'] ?? 0), $updates);
        }

        return $row;
    }

    private function updateStoredSecrets(int $userId, array $updates): void
    {
        if ($userId <= 0) {
            return;
        }

        $setParts = [];
        $params = ['user_id' => $userId];

        foreach ($updates as $field => $value) {
            if (!in_array($field, ['otp_secret', 'pending_otp_secret'], true)) {
                continue;
            }

            $setParts[] = $field . ' = :' . $field;
            $params[$field] = (string)$value;
        }

        if ($setParts === []) {
            return;
        }

        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET ' . implode(', ', $setParts) . ',
                 updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id',
            $params
        );
    }

    private function encryptSecret(string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to encrypt OTP secrets.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if (!is_string($ciphertext) || $ciphertext === '' || $tag === '') {
            throw new RuntimeException('OTP secret could not be encrypted.');
        }

        return self::ENCRYPTION_PREFIX
            . base64_encode($nonce)
            . ':'
            . base64_encode($tag)
            . ':'
            . base64_encode($ciphertext);
    }

    private function decryptSecret(string $storedSecret): array
    {
        $storedSecret = trim($storedSecret);

        if (!str_starts_with($storedSecret, self::ENCRYPTION_PREFIX)) {
            return [
                'secret' => $storedSecret,
                'legacy' => true,
            ];
        }

        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required to decrypt OTP secrets.');
        }

        $payload = substr($storedSecret, strlen(self::ENCRYPTION_PREFIX));
        $parts = explode(':', $payload);

        if (count($parts) !== 3) {
            throw new RuntimeException('Encrypted OTP secret format is invalid.');
        }

        [$nonceEncoded, $tagEncoded, $ciphertextEncoded] = $parts;
        $nonce = base64_decode($nonceEncoded, true);
        $tag = base64_decode($tagEncoded, true);
        $ciphertext = base64_decode($ciphertextEncoded, true);

        if (!is_string($nonce) || !is_string($tag) || !is_string($ciphertext)) {
            throw new RuntimeException('Encrypted OTP secret encoding is invalid.');
        }

        $secret = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if (!is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('Encrypted OTP secret could not be decrypted.');
        }

        return [
            'secret' => $secret,
            'legacy' => false,
        ];
    }

    private function encryptionKey(): string
    {
        if ($this->encryptionKey !== null) {
            return $this->encryptionKey;
        }

        $factName = trim((string)AppConfigurationStore::get('totp.encryption_key_fact', 'totp_encryption_key'));
        if ($factName === '') {
            $factName = 'totp_encryption_key';
        }

        $fact = SecurityStore::ensureFact($factName);
        $binaryFact = ctype_xdigit($fact) ? hex2bin($fact) : false;
        $material = is_string($binaryFact) && $binaryFact !== '' ? $binaryFact : $fact;

        return $this->encryptionKey = hash('sha256', $material, true);
    }

    private function pendingSecretLifetimeSeconds(): int
    {
        $configured = (int)AppConfigurationStore::get(
            'totp.pending_secret_lifetime_seconds',
            self::DEFAULT_PENDING_SECRET_LIFETIME_SECONDS
        );

        return max(60, $configured);
    }
}
