<?php
declare(strict_types=1);

namespace eel_accounts\Outbound;

final class CharityRegistryOutbound
{
    public static function charityCommission(
        array $request,
        ?callable $credentialLoader = null,
        ?callable $transport = null
    ): array
    {
        return self::authenticatedRequest('CHARITYCOMMISSION', $request, 'Ocp-Apim-Subscription-Key', $credentialLoader, $transport);
    }

    public static function oscr(
        array $request,
        ?callable $credentialLoader = null,
        ?callable $transport = null
    ): array
    {
        return self::authenticatedRequest('OSCR', $request, 'x-functions-key', $credentialLoader, $transport);
    }

    public static function ccni(array $request, ?callable $transport = null): array
    {
        $sender = $transport ?? static fn(array $outboundRequest): array => \ApiHelperOutbound::request($outboundRequest);
        return $sender(array_replace([
            'transport' => 'http',
            'method' => 'GET',
            'auth' => 'none',
            'follow_location' => false,
            'timeout_seconds' => 20,
            'headers' => ['Accept' => 'text/html'],
        ], $request));
    }

    private static function authenticatedRequest(
        string $provider,
        array $request,
        string $header,
        ?callable $credentialLoader,
        ?callable $transport
    ): array
    {
        $loader = $credentialLoader
            ?? static fn(string $providerName, string $gateway, string $tag, string $environment): array =>
                \ApiHelperOutbound::loadCredential($providerName, $gateway, $tag, $environment);
        $sender = $transport ?? static fn(array $outboundRequest): array => \ApiHelperOutbound::request($outboundRequest);
        $credential = $loader($provider, 'REST', 'CHARITY_LOOKUP', 'LIVE');
        $apiKey = trim((string)($credential['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException($provider . ' charity-register API key is not configured.');
        }

        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $headers[$header] = $apiKey;

        return $sender(array_replace([
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
