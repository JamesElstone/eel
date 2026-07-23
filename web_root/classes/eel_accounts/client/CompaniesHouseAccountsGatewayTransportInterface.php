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
    public function checkCompanyAuthentication(
        string $companyNumber,
        string $companyAuthenticationCode,
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array;

    public function prepareAccounts(
        array $payload,
        string $environment,
        string $schemaManifestSha256
    ): CompaniesHousePreparedAccountsRequest;

    public function sendPreparedAccounts(
        CompaniesHousePreparedAccountsRequest $request,
        ?callable $afterReceive = null
    ): array;

    public function getSubmissionStatus(
        string $submissionNumber,
        string $environment,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null,
        string $schemaManifestSha256 = ''
    ): array;

    public function acknowledgeSubmissionStatus(
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array;

    public function getDocument(
        string $documentRequestKey,
        string $environment,
        string $schemaManifestSha256,
        ?callable $beforeSend = null,
        ?callable $afterReceive = null
    ): array;
}
