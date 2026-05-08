<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

const EEL_EXTERNAL_IP_LOOKUP_URL = 'https://api.ipify.org?format=text';

function eel_set_external_ip_writeln(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function eel_set_external_ip_error(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

function eel_set_external_ip_fetch(string $url = EEL_EXTERNAL_IP_LOOKUP_URL): string
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: EEL-Accounts-setExternalIP/1.0\r\nAccept: text/plain\r\n",
            'ignore_errors' => false,
            'method' => 'GET',
            'timeout' => 10,
        ],
    ]);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new RuntimeException('Unable to fetch external IP address.');
    }

    return trim($response);
}

function eel_set_external_ip_validate(string $value): string
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

function eel_set_external_ip_update_config(string $ip): array
{
    $previous = AppConfigurationStore::get('antifraud.vendor_public_ip', null, true);
    AppConfigurationStore::setAntifraudVendorPublicIp($ip);

    return [
        'previous' => is_scalar($previous) ? (string)$previous : null,
        'current' => $ip,
    ];
}

function eel_set_external_ip_run_tool(): int
{
    if (PHP_SAPI !== 'cli') {
        eel_set_external_ip_error('This tool can only be run from the command line.');
        return 1;
    }

    try {
        $response = eel_set_external_ip_fetch();
        $ip = eel_set_external_ip_validate($response);
        $result = eel_set_external_ip_update_config($ip);
    } catch (Throwable $exception) {
        eel_set_external_ip_error($exception->getMessage());
        return 1;
    }

    eel_set_external_ip_writeln('External IP detected: ' . $result['current']);

    if ($result['previous'] !== null && $result['previous'] !== $result['current']) {
        eel_set_external_ip_writeln('Previous vendor public IP: ' . $result['previous']);
    }

    eel_set_external_ip_writeln('Updated antifraud.vendor_public_ip in secure/app.php');
    eel_set_external_ip_writeln('-EOL-');

    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_set_external_ip_run_tool());
}
