<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);



namespace eel_accounts\Outbound;

final class CompaniesHouseOutbound
{
    public static function credential(string $environment = 'TEST', ?string $keysPath = null): array
    {
        return \ApiHelperOutbound::loadCredential(
            'COMPANIESHOUSE',
            'COMPANY_LOOKUP',
            \HelperFramework::normaliseEnvironmentMode($environment),
            $keysPath
        );
    }

    public static function request(array $request, string $environment = 'TEST'): array
    {
        $defaultHeaders = ['Accept' => 'application/json'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return \ApiHelperOutbound::request(array_replace([
            'transport' => 'http',
            'provider' => 'COMPANIESHOUSE',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => \HelperFramework::normaliseEnvironmentMode($environment),
            'method' => 'GET',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'basic_api_key',
            'timeout_seconds' => 20,
        ], $request));
    }
}


