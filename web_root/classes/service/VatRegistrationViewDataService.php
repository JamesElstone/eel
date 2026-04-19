<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class VatRegistrationViewDataService
{
    public static function validationHash(VatRegistrationService $vatService, array $settings): string {
        return strtoupper(trim((string)($settings['vat_country_code'] ?? '')))
            . ':'
            . $vatService->normaliseVatNumber((string)($settings['vat_number'] ?? ''));
    }

    public static function comparisonCountryValue(?string $value): string {
        $normalised = strtoupper(trim((string)$value));

        if (in_array($normalised, ['UNITED KINGDOM', 'GREAT BRITAIN', 'GB', 'UK'], true)) {
            return 'GB';
        }

        return $normalised;
    }

    public static function addressTableValues(
        string $name,
        string $address,
        string $line1 = '',
        string $postcode = '',
        string $country = ''
    ): array {
        $resolvedLine1 = trim($line1);
        $resolvedPostcode = trim($postcode);
        $resolvedCountry = trim($country);
        $cleanAddress = trim($address);

        if ($cleanAddress !== '') {
            $parts = preg_split('/[\r\n,]+/', $cleanAddress) ?: [];
            $parts = array_values(array_filter(
                array_map(static fn($part) => trim((string)$part), $parts),
                static fn($part) => $part !== ''
            ));

            if ($resolvedLine1 === '' && isset($parts[0])) {
                $resolvedLine1 = $parts[0];
            }

            if ($resolvedPostcode === '' && preg_match('/\b[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}\b/i', $cleanAddress, $matches)) {
                $resolvedPostcode = strtoupper(trim((string)$matches[0]));
            }

            if ($resolvedCountry === '' && !empty($parts)) {
                $lastPart = (string)$parts[count($parts) - 1];

                if (!preg_match('/\d/', $lastPart)) {
                    $resolvedCountry = $lastPart;
                }
            }
        }

        return [
            'name' => trim($name),
            'line1' => $resolvedLine1,
            'postcode' => $resolvedPostcode,
            'country' => $resolvedCountry,
        ];
    }
}
