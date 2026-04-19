<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

if (!defined('AF_HEADER_PREFIX')) {
    define('AF_HEADER_PREFIX', AntiFraudService::HEADER_PREFIX);
}

if (!defined('AF_COOKIE_PREFIX')) {
    define('AF_COOKIE_PREFIX', AntiFraudService::COOKIE_PREFIX);
}

function af_config(): array {
    return AntiFraudService::instance()->config();
}

function af_init_antifraud_data(): array {
    return AntiFraudService::instance()->initAntifraudData();
}

function af_get_antifraud_data(): array {
    return AntiFraudService::instance()->getAntifraudData();
}

function af_request_value(string $fieldName): ?string {
    return AntiFraudService::instance()->requestValue($fieldName);
}

function af_read_header_value(string $headerName): ?string {
    return AntiFraudService::instance()->readHeaderValue($headerName);
}

function af_get_request_headers(): array {
    return AntiFraudService::instance()->getRequestHeaders();
}

function af_cookie_suffix_from_field(string $fieldName): string {
    return AntiFraudService::instance()->cookieSuffixFromField($fieldName);
}

function af_normalise_optional_string(mixed $value): ?string {
    return AntiFraudService::instance()->normaliseOptionalString($value);
}

function af_current_utc_timestamp(): string {
    return AntiFraudService::instance()->currentUtcTimestamp();
}

function af_detect_client_public_ip(): ?string {
    return AntiFraudService::instance()->detectClientPublicIp();
}

function af_extract_ip(string $value): ?string {
    return AntiFraudService::instance()->extractIp($value);
}

function af_detect_vendor_forwarded(): ?string {
    return AntiFraudService::instance()->detectVendorForwarded();
}

function af_detect_vendor_public_ip(?string $configuredValue): ?string {
    return AntiFraudService::instance()->detectVendorPublicIp($configuredValue);
}
