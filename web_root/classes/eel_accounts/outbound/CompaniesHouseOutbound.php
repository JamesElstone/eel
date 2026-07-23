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
            'REST',
            'COMPANY_LOOKUP',
            \HelperFramework::normaliseEnvironmentMode($environment),
            $keysPath
        );
    }

    public static function request(array $request, string $environment = 'TEST'): array
    {
        $defaultHeaders = ['Accept' => 'application/json'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $credential = self::credential($environment, (string)($request['keys_path'] ?? ''));
        $apiKey = (string)($credential['api_key'] ?? '');

        if ($apiKey === '') {
            throw new \RuntimeException('Companies House API key is not configured.');
        }

        // Companies House REST uses the API key as the Basic-auth username and
        // an empty password. eelKit's generic basic_api_key mode maps the new
        // API_IDENTITY/API_KEY fields as username/password, so construct the
        // provider-specific header here instead.
        $requestHeaders['Authorization'] = 'Basic ' . base64_encode($apiKey . ':');
        $request['headers'] = array_replace($defaultHeaders, $requestHeaders);

        return \ApiHelperOutbound::request(array_replace([
            'transport' => 'http',
            'provider' => 'COMPANIESHOUSE',
            'gateway' => 'REST',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => \HelperFramework::normaliseEnvironmentMode($environment),
            'method' => 'GET',
            'headers' => $request['headers'],
            'auth' => 'none',
            'credential' => $credential,
            'timeout_seconds' => 20,
        ], $request));
    }
}


