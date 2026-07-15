<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompanyIncorporationEligibilityService
{
    public const EARLIEST_SUPPORTED_DATE = '2011-01-05';
    public const STATUS_SUPPORTED = 'supported';
    public const STATUS_BEFORE_CUTOFF = 'before_cutoff';
    public const STATUS_MISSING = 'missing';
    public const STATUS_INVALID = 'invalid';

    public function evaluate(?string $incorporationDate): array
    {
        $incorporationDate = trim((string)$incorporationDate);

        if ($incorporationDate === '') {
            return $this->result(
                self::STATUS_MISSING,
                false,
                null,
                'Eligibility unavailable',
                'Companies House did not provide an incorporation date. Only companies incorporated on or after 5 January 2011 are supported.'
            );
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $incorporationDate);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $incorporationDate) {
            return $this->result(
                self::STATUS_INVALID,
                false,
                $incorporationDate,
                'Not supported',
                'Companies House returned an invalid incorporation date. Only companies incorporated on or after 5 January 2011 are supported.'
            );
        }

        if ($incorporationDate < self::EARLIEST_SUPPORTED_DATE) {
            return $this->result(
                self::STATUS_BEFORE_CUTOFF,
                false,
                $incorporationDate,
                'Not supported',
                'Only companies incorporated on or after 5 January 2011 are supported.'
            );
        }

        return $this->result(
            self::STATUS_SUPPORTED,
            true,
            $incorporationDate,
            'Eligible',
            'This company is supported.'
        );
    }

    private function result(
        string $status,
        bool $isSupported,
        ?string $incorporationDate,
        string $label,
        string $message
    ): array {
        return [
            'status' => $status,
            'eligible' => $isSupported,
            'is_supported' => $isSupported,
            'incorporation_date' => $incorporationDate,
            'earliest_supported_date' => self::EARLIEST_SUPPORTED_DATE,
            'label' => $label,
            'message' => $message,
        ];
    }
}
