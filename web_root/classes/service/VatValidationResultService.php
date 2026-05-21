<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class VatValidationResultService
{
    public function __construct(
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        public readonly ?string $error = null,
        public readonly array $meta = [],
    ) {
    }

    public static function valid(string $source, ?string $name = null, ?string $address = null, array $meta = []): self {
        return new self('valid', $source, $name, $address, null, $meta);
    }

    public static function invalid(string $source, ?string $name = null, ?string $address = null, array $meta = []): self {
        return new self('invalid', $source, $name, $address, null, $meta);
    }

    public static function error(string $source, string $error, array $meta = []): self {
        return new self('error', $source, null, null, $error, $meta);
    }
}
