<?php
/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

const EEL_EXTERNAL_IP_LOOKUP_URL = ExternalIpLookupOutbound::DEFAULT_LOOKUP_URL;

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
    return (new ExternalIpLookupOutbound())->fetch($url);
}

function eel_set_external_ip_validate(string $value): string
{
    return (new ExternalIpLookupOutbound())->validatePublicIp($value);
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
        $ip = (new ExternalIpLookupOutbound())->lookupPublicIp();
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
