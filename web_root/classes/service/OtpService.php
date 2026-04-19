<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
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

    private string $issuer;
    private OtpVerificationService $otpVerificationService;

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
            'otp_secret' => $secret,
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
            'pending_otp_secret' => $secret,
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

        InterfaceDB::prepareExecute(
            'UPDATE user_totp
             SET otp_secret = pending_otp_secret,
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

        return is_array($row) ? $row : null;
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
}
