<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Fail-closed compatibility facade retained for existing callers.
 */
final class Ct600BuilderService
{
    public function buildCt600Xml(int $companyId, int $accountingPeriodId): array
    {
        return $this->retired();
    }

    public function buildCt600XmlForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        return $this->retired();
    }

    /** @return array<string, mixed> */
    private function retired(): array
    {
        return [
            'ok' => false,
            'path' => null,
            'warnings' => [],
            'errors' => [
                'CT600 submission is not implemented.',
            ],
        ];
    }
}
