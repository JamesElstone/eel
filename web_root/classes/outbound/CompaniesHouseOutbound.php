<?php
declare(strict_types=1);


final class CompaniesHouseOutbound
{
    public static function credential(string $environment = 'TEST', ?string $keysPath = null): array
    {
        return OutboundHelper::loadCredential(
            'COMPANIESHOUSE',
            'COMPANY_LOOKUP',
            FrameworkHelper::normaliseEnvironmentMode($environment),
            $keysPath
        );
    }

    public static function request(array $request, string $environment = 'TEST'): array
    {
        $defaultHeaders = ['Accept' => 'application/json'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'provider' => 'COMPANIESHOUSE',
            'tag' => 'COMPANY_LOOKUP',
            'environment' => FrameworkHelper::normaliseEnvironmentMode($environment),
            'method' => 'GET',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'basic_api_key',
            'timeout_seconds' => 20,
        ], $request));
    }
}
