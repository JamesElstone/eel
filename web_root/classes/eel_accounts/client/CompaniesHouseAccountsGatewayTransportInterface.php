<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

interface CompaniesHouseAccountsGatewayTransportInterface
{
    public function submitAccounts(array $payload, string $environment): array;

    public function getSubmissionStatus(string $submissionNumber, string $environment): array;
}
