<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

interface CompaniesHouseSchemaCurrentnessInterface
{
    /** @return array<string, mixed> */
    public function ensureCurrent(mixed $progress = null): array;
}
