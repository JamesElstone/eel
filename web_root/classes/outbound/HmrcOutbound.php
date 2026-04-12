<?php
declare(strict_types=1);


final class HmrcOutbound
{
    public static function loadCredential(
        string $tag = 'VAT_CHECK',
        string $environment = 'TEST',
        ?string $keysPath = null,
        string $provider = 'HMRC'
    ): array {
        return OutboundHelper::loadCredential(
            $provider,
            $tag,
            FrameworkHelper::normaliseEnvironmentMode($environment),
            $keysPath
        );
    }

    public static function tokenRequest(array $request): array
    {
        $defaultHeaders = ['Content-Type' => 'application/x-www-form-urlencoded'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'method' => 'POST',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'oauth_client_credentials',
            'provider' => 'HMRC',
            'tag' => 'VAT_CHECK',
            'environment' => 'TEST',
            'timeout_seconds' => 10,
        ], $request));
    }

    public static function vatLookupRequest(array $request): array
    {
        $defaultHeaders = ['Accept' => 'application/vnd.hmrc.2.0+json'];
        $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

        return OutboundHelper::request(array_replace([
            'transport' => 'http',
            'method' => 'GET',
            'headers' => array_replace($defaultHeaders, $requestHeaders),
            'auth' => 'bearer',
            'timeout_seconds' => 10,
        ], $request));
    }
}
