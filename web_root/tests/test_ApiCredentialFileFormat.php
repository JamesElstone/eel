<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    ApiCredentialFileFormat::class,
    static function (GeneratedServiceClassTestHarness $h, ApiCredentialFileFormat $format): void {
        unset($format);
        $h->check(ApiCredentialFileFormat::class, 'recognises legacy and canonical credential layouts', static function () use ($h): void {
            $h->assertSame(
                ApiCredentialFileFormat::LEGACY_LAYOUT,
                ApiCredentialFileFormat::requireLayout(ApiCredentialFileFormat::LEGACY_HEADER)
            );
            $h->assertSame(
                ApiCredentialFileFormat::CANONICAL_LAYOUT,
                ApiCredentialFileFormat::requireLayout(ApiCredentialFileFormat::CANONICAL_HEADER)
            );
            $h->assertSame(8, ApiCredentialFileFormat::expectedColumnCount(ApiCredentialFileFormat::LEGACY_LAYOUT));
            $h->assertSame(9, ApiCredentialFileFormat::expectedColumnCount(ApiCredentialFileFormat::CANONICAL_LAYOUT));
        });

        $h->check(ApiCredentialFileFormat::class, 'normalises a safe software reference', static function () use ($h): void {
            $h->assertSame('EEL Accounts/2026.08', ApiCredentialFileFormat::normaliseSoftwareReference('  EEL Accounts/2026.08  '));
            $h->assertThrows(
                static fn(): string => ApiCredentialFileFormat::normaliseSoftwareReference("invalid\nreference"),
                RuntimeException::class
            );
        });
    }
);
