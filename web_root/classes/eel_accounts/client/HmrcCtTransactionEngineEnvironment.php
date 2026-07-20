<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Client;

/** Closed endpoint and GovTalk-class map. Request data can never select a URL. */
final class HmrcCtTransactionEngineEnvironment
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
        $origin = $environment === 'TEST' ? self::TEST_ORIGIN : self::LIVE_ORIGIN;

        return [
            'environment' => $environment,
            'credential_environment' => $environment === 'TEST' ? 'TEST' : 'LIVE',
            'class' => $environment === 'TIL' ? 'HMRC-CT-CT600-TIL' : 'HMRC-CT-CT600',
            'gateway_test' => $environment === 'TEST' ? '1' : '0',
            'statutory' => $environment === 'LIVE',
            'submission_url' => $origin . '/submission',
            'poll_url' => $origin . '/poll',
            'allowed_host' => (string)parse_url($origin, PHP_URL_HOST),
        ];
    }

    /** Accept only the documented poll endpoint in the selected environment. */
    public static function responseEndpoint(string $endpoint, string $environment): string
    {
        $profile = self::profile($environment);
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return (string)$profile['poll_url'];
        }

        $parts = parse_url($endpoint);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? rtrim((string)($parts['path'] ?? ''), '/') : '';
        $port = is_array($parts) && isset($parts['port']) ? (int)$parts['port'] : 443;

        if (
            !is_array($parts)
            || $scheme !== 'https'
            || $host !== strtolower((string)$profile['allowed_host'])
            || $path !== '/poll'
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException(
                'HMRC response endpoint is outside the selected Transaction Engine environment.'
            );
        }

        return (string)$profile['poll_url'];
    }
}
