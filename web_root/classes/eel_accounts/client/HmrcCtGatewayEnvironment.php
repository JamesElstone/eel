<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

/**
 * Closed environment map for the Corporation Tax XML Transaction Engine.
 *
 * Keeping this map out of request data prevents a submitted form from
 * selecting an endpoint, a GovTalk class or statutory effect.
 */
final class HmrcCtGatewayEnvironment
{
    private const TEST_ORIGIN = 'https://test-transaction-engine.tax.service.gov.uk';
    private const LIVE_ORIGIN = 'https://transaction-engine.tax.service.gov.uk';

    public static function normalise(string $environment): string
    {
        $environment = strtoupper(trim($environment));

        if (!in_array($environment, ['TEST', 'TIL', 'LIVE'], true)) {
            throw new \InvalidArgumentException(
                'HMRC Corporation Tax environment must be TEST, TIL or LIVE.'
            );
        }

        return $environment;
    }

    public static function profile(string $environment): array
    {
        $environment = self::normalise($environment);
        $isTest = $environment === 'TEST';
        $origin = $isTest ? self::TEST_ORIGIN : self::LIVE_ORIGIN;

        return [
            'environment' => $environment,
            'credential_environment' => $isTest ? 'TEST' : 'LIVE',
            'class' => $environment === 'TIL' ? 'HMRC-CT-CT600-TIL' : 'HMRC-CT-CT600',
            'gateway_test' => $isTest ? '1' : '0',
            'statutory' => $environment === 'LIVE',
            'submission_url' => $origin . '/submission',
            'poll_url' => $origin . '/poll',
            'allowed_host' => (string)parse_url($origin, PHP_URL_HOST),
        ];
    }

    /**
     * Validate an HMRC-supplied response endpoint before it can become an
     * outbound target. Empty endpoints use the documented poll endpoint.
     */
    public static function responseEndpoint(string $endpoint, string $environment): string
    {
        $profile = self::profile($environment);
        $endpoint = trim($endpoint);

        if ($endpoint === '') {
            return $profile['poll_url'];
        }

        $parts = parse_url($endpoint);

        if (!is_array($parts)) {
            throw new \InvalidArgumentException('HMRC response endpoint is not a valid URL.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;

        if (
            $scheme !== 'https'
            || $host !== strtolower($profile['allowed_host'])
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || $path !== '/poll'
        ) {
            throw new \InvalidArgumentException(
                'HMRC response endpoint is outside the selected Transaction Engine environment.'
            );
        }

        return 'https://' . $profile['allowed_host'] . '/poll';
    }
}
