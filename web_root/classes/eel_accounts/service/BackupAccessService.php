<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class BackupAccessService
{
    public function canUseBackups(\SessionAuthenticationService $session): bool
    {
        $deviceId = trim((string)\AntiFraudService::instance()->requestValue('Client-Device-ID'));
        $userId = $session->authenticatedUserId($deviceId);

        return $userId > 0
            && in_array('backup', (new \CardAccessFramework())->allowedCardsForUser($userId, ['backup']), true);
    }
}
