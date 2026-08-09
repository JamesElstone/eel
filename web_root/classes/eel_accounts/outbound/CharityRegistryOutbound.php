<?php
declare(strict_types=1);

namespace eel_accounts\Outbound;

final class CharityRegistryOutbound
{
    public static function charityCommission(array $request): array
    {
        return self::authenticatedRequest('CHARITYCOMMISSION', $request);
    }

    public static function oscr(array $request): array
    {
        return self::authenticatedRequest('OSCR', $request, 'x-functions-key');
    }

    public static function ccni(array $request): array
    {
        return \ApiHelperOutbound::request(array_replace([
            'transport' => 'http',
            'method' => 'GET',
            'auth' => 'none',
            'timeout_seconds' => 20,
            'headers' => ['Accept' => 'text/html'],
        ], $request));
    }

    private static function authenticatedRequest(string $provider, array $request, string $header = 'Ocp-Apim-Subscription-Key'): array
    {
        $credential = \ApiHelperOutbound::loadCredential($provider, 'REST', 'CHARITY_LOOKUP', 'LIVE');
        $apiKey = trim((string)($credential['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException($provider . ' charity-register API key is not configured.');
        }

        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $headers[$header] = $apiKey;

        return \ApiHelperOutbound::request(array_replace([
            'transport' => 'http',
            'provider' => $provider,
            'gateway' => 'REST',
            'tag' => 'CHARITY_LOOKUP',
            'environment' => 'LIVE',
            'method' => 'GET',
            'auth' => 'none',
            'credential' => $credential,
            'timeout_seconds' => 20,
            'headers' => array_replace(['Accept' => 'application/json'], $headers),
        ], $request, ['headers' => array_replace(['Accept' => 'application/json'], $headers)]));
    }
}
