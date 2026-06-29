<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ExternalIpLookupOutbound
{
    public const DEFAULT_LOOKUP_URL = 'https://api.ipify.org?format=text';

    public function __construct(
        private readonly mixed $requester = null,
    ) {
    }

    public function lookupPublicIp(string $url = self::DEFAULT_LOOKUP_URL): string
    {
        return $this->validatePublicIp($this->fetch($url));
    }

    public function fetch(string $url = self::DEFAULT_LOOKUP_URL): string
    {
        if (is_callable($this->requester)) {
            $response = ($this->requester)($url);

            return trim((string)$response);
        }

        $response = \ApiHelperOutbound::request([
            'url' => $url,
            'method' => 'GET',
            'headers' => [
                'Accept' => 'text/plain',
            ],
            'timeout_seconds' => 10,
            'user_agent' => 'eelKit-ExternalIpLookup/1.0',
            'max_response_bytes' => 256,
            'fail_on_error' => true,
        ]);

        $statusCode = (int)($response['status_code'] ?? 0);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('External IP lookup failed with HTTP status ' . $statusCode . '.');
        }

        return trim((string)($response['body'] ?? ''));
    }

    public function validatePublicIp(string $value): string
    {
        $ip = AntiFraudService::instance()->extractIp($value);

        if (
            $ip === null
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new RuntimeException('External IP lookup returned an invalid public IP address.');
        }

        return $ip;
    }
}
