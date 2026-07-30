<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

use eel_accounts\Store\AccountingConfigurationStore;

/** Serialises iXBRL mutations for one company and accounting period. */
final class IxbrlFilingOperationLockService
{
    public function execute(int $companyId, int $accountingPeriodId, callable $operation): mixed
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            throw new \InvalidArgumentException('Select a valid company and accounting period.');
        }
        $directory = rtrim(AccountingConfigurationStore::temporaryDirectory(), '\\/')
            . DIRECTORY_SEPARATOR . '.ixbrl-locks';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('The iXBRL generation lock directory could not be created.');
        }
        $path = $directory . DIRECTORY_SEPARATOR
            . 'company-' . $companyId . '-period-' . $accountingPeriodId . '.lock';
        $handle = fopen($path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('The iXBRL generation lock could not be opened.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException(
                'Another iXBRL generation or preparation action is already running for this accounting period.'
            );
        }

        try {
            ftruncate($handle, 0);
            fwrite($handle, (string)getmypid());
            fflush($handle);
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
