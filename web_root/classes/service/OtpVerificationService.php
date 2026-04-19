<?php
declare(strict_types=1);

final class OtpVerificationService
{
    public function currentTimestep(int $currentUnixTime, int $period): int
    {
        if ($period <= 0) {
            throw new InvalidArgumentException('OTP period must be a positive integer.');
        }

        return intdiv($currentUnixTime, $period);
    }

    public function generateCodeForTimestep(
        int $digits,
        string $algorithm,
        string $base32Secret,
        int $timestep
    ): string {
        if ($digits <= 0) {
            throw new InvalidArgumentException('OTP digits must be a positive integer.');
        }

        if ($timestep < 0) {
            throw new InvalidArgumentException('OTP timestep cannot be negative.');
        }

        $secret = $this->base32Decode($base32Secret);
        $counterBytes = pack('N2', ($timestep >> 32) & 0xffffffff, $timestep & 0xffffffff);

        $hash = hash_hmac(strtolower($algorithm), $counterBytes, $secret, true);
        if ($hash === false) {
            throw new RuntimeException('Failed to generate HMAC for OTP.');
        }

        $offset = ord(substr($hash, -1)) & 0x0f;
        $binary = substr($hash, $offset, 4);

        if ($binary === false || strlen($binary) !== 4) {
            throw new RuntimeException('Failed to extract dynamic truncation block.');
        }

        $value = unpack('N', $binary)[1] & 0x7fffffff;
        $otp = $value % (10 ** $digits);

        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    public function verifyCode(
        string $submittedCode,
        int $digits,
        string $algorithm,
        int $period,
        string $base32Secret,
        int $currentUnixTime,
        int $window = 1,
        ?int $lastUsedTimestep = null,
        bool $preventReplay = true
    ): ?int {
        if ($digits <= 0 || $period <= 0 || $window < 0) {
            return null;
        }

        $currentTimestep = $this->currentTimestep($currentUnixTime, $period);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidateTimestep = $currentTimestep + $offset;
            if ($candidateTimestep < 0) {
                continue;
            }

            $candidateCode = $this->generateCodeForTimestep($digits, $algorithm, $base32Secret, $candidateTimestep);
            if (!hash_equals($candidateCode, $submittedCode)) {
                continue;
            }

            if ($preventReplay && $lastUsedTimestep !== null && $candidateTimestep <= $lastUsedTimestep) {
                return null;
            }

            return $candidateTimestep;
        }

        return null;
    }

    private function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(trim($base32));
        $base32 = str_replace('=', '', $base32);

        if ($base32 === '') {
            return '';
        }

        $binaryString = '';
        $length = strlen($base32);

        for ($i = 0; $i < $length; $i++) {
            $character = $base32[$i];
            $position = strpos($alphabet, $character);

            if ($position === false) {
                throw new InvalidArgumentException('Invalid Base32 character encountered.');
            }

            $binaryString .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($binaryString, 8);
        $decoded = '';

        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }

            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
