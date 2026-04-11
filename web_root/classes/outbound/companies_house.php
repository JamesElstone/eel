<?php
declare(strict_types=1);

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/../lib/helpers/ctrl_helpers.php';

function companiesHouseOutboundCredential(string $environment = 'TEST', ?string $keysPath = null): array {
    return outboundHelperLoadCredential(
        'COMPANIESHOUSE',
        'COMPANY_LOOKUP',
        ctrl_normalise_environment_mode($environment),
        $keysPath
    );
}

function companiesHouseOutboundRequest(array $request, string $environment = 'TEST'): array {
    $defaultHeaders = ['Accept' => 'application/json'];
    $requestHeaders = is_array($request['headers'] ?? null) ? $request['headers'] : [];

    return outboundHelperRequest(array_replace([
        'transport' => 'http',
        'provider' => 'COMPANIESHOUSE',
        'tag' => 'COMPANY_LOOKUP',
        'environment' => ctrl_normalise_environment_mode($environment),
        'method' => 'GET',
        'headers' => array_replace($defaultHeaders, $requestHeaders),
        'auth' => 'basic_api_key',
        'timeout_seconds' => 20,
    ], $request));
}
