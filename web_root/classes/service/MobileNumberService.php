<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class MobileNumberService
{
    public const DEFAULT_COUNTRY_CODE = '+44';

    public static function countryCodeOptions(): array
    {
        $options = self::countryCodeOptionsFromDatabase();
        if ($options !== []) {
            return $options;
        }

        return self::fallbackCountryCodeOptions();
    }

    public static function normaliseFromParts(string $countryCode, string $mobileNumber): string
    {
        $mobileNumber = trim($mobileNumber);
        if ($mobileNumber === '') {
            return '';
        }

        if (str_starts_with($mobileNumber, '+') || str_starts_with($mobileNumber, '00')) {
            $prefix = str_starts_with($mobileNumber, '00') ? '+' . substr($mobileNumber, 2) : $mobileNumber;
            $digits = preg_replace('/\D+/', '', $prefix);
            $countryDigits = ltrim(self::normaliseCountryCode($countryCode), '+');

            if (!is_string($digits) || $digits === '') {
                return '';
            }

            if ($countryDigits !== '' && str_starts_with($digits, $countryDigits)) {
                return '+' . $countryDigits . ltrim(substr($digits, strlen($countryDigits)), '0');
            }

            return '+' . $digits;
        }

        $countryCode = self::normaliseCountryCode($countryCode);
        $digits = preg_replace('/\D+/', '', $mobileNumber);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        return $countryCode . ltrim($digits, '0');
    }

    public static function parts(string $mobileNumber): array
    {
        $mobileNumber = trim($mobileNumber);
        if ($mobileNumber === '') {
            return [
                'country_code' => self::DEFAULT_COUNTRY_CODE,
                'local_number' => '',
            ];
        }

        $countryCodes = array_keys(self::countryCodeOptions());
        usort($countryCodes, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        $normalised = self::normaliseFromParts(self::DEFAULT_COUNTRY_CODE, $mobileNumber);
        $digits = ltrim($normalised, '+');

        if (!str_starts_with($mobileNumber, '+') && !str_starts_with($mobileNumber, '00')) {
            $rawDigits = preg_replace('/\D+/', '', $mobileNumber);
            if (is_string($rawDigits) && $rawDigits !== '') {
                foreach ($countryCodes as $countryCode) {
                    $codeDigits = ltrim($countryCode, '+');
                    if ($codeDigits !== '' && str_starts_with($rawDigits, $codeDigits)) {
                        return [
                            'country_code' => $countryCode,
                            'local_number' => substr($rawDigits, strlen($codeDigits)),
                        ];
                    }
                }
            }
        }

        foreach ($countryCodes as $countryCode) {
            $codeDigits = ltrim($countryCode, '+');
            if (str_starts_with($digits, $codeDigits)) {
                return [
                    'country_code' => $countryCode,
                    'local_number' => substr($digits, strlen($codeDigits)),
                ];
            }
        }

        return [
            'country_code' => self::DEFAULT_COUNTRY_CODE,
            'local_number' => $digits,
        ];
    }

    public static function formatted(string $mobileNumber): string
    {
        $parts = self::parts($mobileNumber);
        $localNumber = (string)($parts['local_number'] ?? '');

        return $localNumber === '' ? '' : (string)$parts['country_code'] . ' ' . $localNumber;
    }

    private static function countryCodeOptionsFromDatabase(): array
    {
        if (!InterfaceDB::tableExists('mobile_country_codes')) {
            return [];
        }

        $rows = InterfaceDB::fetchAll(
            'SELECT country_code,
                    display_name
             FROM mobile_country_codes
             ORDER BY is_default DESC, display_name ASC, country_code ASC'
        );
        $options = [];

        foreach ($rows as $row) {
            $countryCode = self::normaliseCountryCodeValue((string)($row['country_code'] ?? ''));
            $displayName = trim((string)($row['display_name'] ?? ''));

            if ($countryCode === '' || $displayName === '') {
                continue;
            }

            $options[$countryCode] = $displayName . ' (' . $countryCode . ')';
        }

        return $options;
    }

    private static function fallbackCountryCodeOptions(): array
    {
        return [
            '+44' => 'UK (+44)',
            '+1' => 'US / Canada (+1)',
            '+353' => 'Ireland (+353)',
            '+33' => 'France (+33)',
            '+49' => 'Germany (+49)',
            '+34' => 'Spain (+34)',
            '+39' => 'Italy (+39)',
            '+31' => 'Netherlands (+31)',
            '+61' => 'Australia (+61)',
            '+64' => 'New Zealand (+64)',
            '+91' => 'India (+91)',
        ];
    }

    private static function normaliseCountryCodeValue(string $countryCode): string
    {
        $countryCode = trim($countryCode);
        if ($countryCode === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $countryCode);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        return '+' . $digits;
    }

    private static function normaliseCountryCode(string $countryCode): string
    {
        $countryCode = self::normaliseCountryCodeValue($countryCode);

        return array_key_exists($countryCode, self::countryCodeOptions())
            ? $countryCode
            : self::DEFAULT_COUNTRY_CODE;
    }
}
