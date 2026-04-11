<?php
declare(strict_types=1);

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../lib/helpers/ctrl_helpers.php';

function hmrcOutboundLoadCredential(
    string $tag = 'VAT_CHECK',
    string $environment = 'TEST',
    ?string $keysPath = null,
    string $provider = 'HMRC'
): array {
    return outboundHelperLoadCredential(
        $provider,
        $tag,
        ctrl_normalise_environment_mode($environment),
        $keysPath
    );
}

function hmrcOutboundTokenRequest(array $request): array {
    $defaultHeaders = ['Content-Type' => 'application/x-www-form-urlencoded'];
    $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

    return outboundHelperRequest(array_replace([
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

function hmrcOutboundVatLookupRequest(array $request): array {
    $defaultHeaders = ['Accept' => 'application/vnd.hmrc.2.0+json'];
    $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

    return outboundHelperRequest(array_replace([
        'transport' => 'http',
        'method' => 'GET',
        'headers' => array_replace($defaultHeaders, $requestHeaders),
        'auth' => 'bearer',
        'timeout_seconds' => 10,
    ], $request));
}
