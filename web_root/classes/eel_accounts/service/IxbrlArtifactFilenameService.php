<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Builds the canonical, auditable filename for newly generated iXBRL filing artifacts. */
final class IxbrlArtifactFilenameService
{
    public const DESTINATION_HMRC_COMPUTATION = 'hmrc-computation';
    public const DESTINATION_HMRC_ACCOUNTING = 'hmrc-accounting';
    public const DESTINATION_COMPANIES_HOUSE = 'companies-house';

    private const DESTINATIONS = [
        self::DESTINATION_HMRC_COMPUTATION,
        self::DESTINATION_HMRC_ACCOUNTING,
        self::DESTINATION_COMPANIES_HOUSE,
    ];

    public function build(
        string $companyNumber,
        int $accountingPeriodId,
        int $approvalId,
        int $runId,
        string $destination,
        string $periodStart,
        string $periodEnd,
        string $sha256
    ): string {
        $number = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($companyNumber)));
        $hash = strtolower(trim($sha256));
        if ($number === '' || $accountingPeriodId <= 0 || $approvalId <= 0 || $runId <= 0) {
            throw new \InvalidArgumentException('Invalid iXBRL artifact identity.');
        }
        if (!in_array($destination, self::DESTINATIONS, true)) {
            throw new \InvalidArgumentException('Invalid iXBRL artifact destination.');
        }
        if (!$this->validDate($periodStart) || !$this->validDate($periodEnd) || $periodStart > $periodEnd) {
            throw new \InvalidArgumentException('Invalid iXBRL artifact coverage dates.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Invalid iXBRL artifact SHA-256 hash.');
        }

        return 'accounts_' . $number
            . '_' . $accountingPeriodId
            . '_' . $approvalId
            . '_' . $runId
            . '_' . $destination
            . '_' . $periodStart
            . '_' . $periodEnd
            . '_' . substr($hash, 0, 16)
            . '.xhtml';
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^\d{8}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Ymd') === $value;
    }
}
