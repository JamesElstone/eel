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
 * Retired compatibility facade.
 *
 * The old API could not accept the declaration, validated accounts artifact,
 * period-specific computations artifact, RIM validation result, or immutable
 * source manifest required for a lawful return. It must therefore fail closed
 * instead of creating the former internal <CT600Draft> document.
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
                'The legacy CT600 draft builder is retired. Prepare a typed, validated, immutable CT/5 package through HmrcCtSubmissionOrchestrator.',
            ],
        ];
    }
}
