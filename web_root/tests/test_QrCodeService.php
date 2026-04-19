<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

$harness = new GeneratedServiceClassTestHarness();
$harness->run(QrCodeService::class, function (GeneratedServiceClassTestHarness $harness, QrCodeService $service): void {
    $harness->check(QrCodeService::class, 'generates an SVG QR code string for short input', function () use ($harness, $service): void {
        $svg = $service->generateQRcode('abc');

        $harness->assertTrue(str_starts_with($svg, '<svg '));
        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 290 290"'));
        $harness->assertTrue(str_contains($svg, '<image href="data:image/png;base64,'));
        $harness->assertTrue(!str_contains($svg, 'abc'));
    });

    $harness->check(QrCodeService::class, 'uses a larger QR version when the payload grows', function () use ($harness, $service): void {
        $svg = $service->generateQRcode(str_repeat('a', 18));

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 330 330"'));
    });

    $harness->check(QrCodeService::class, 'supports the version 4 M maximum payload', function () use ($harness, $service): void {
        $svg = $service->generateQRcode(str_repeat('a', 62));

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 410 410"'));
    });

    $harness->check(QrCodeService::class, 'resolves the Elstone otpauth payload to version 5 M', function () use ($harness, $service): void {
        $input = 'otpauth://totp/Elstone:demo?secret=JBSWY3DPEHPK3PXP&issuer=Elstone';

        $inspect = Closure::bind(function () use ($input): int {
            return $this->resolveVersion(strlen($input), 'M');
        }, $service, QrCodeService::class);

        $harness->assertSame(5, $inspect());

        $svg = $service->generateQRcode($input, ['error_correction_level' => 'M']);
        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 450 450"'));
    });

    $harness->check(QrCodeService::class, 'supports a custom module size without changing the QR matrix', function () use ($harness, $service): void {
        $svg = $service->generateQRcode('abc', ['module_size' => 5]);

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 145 145"'));
        $harness->assertTrue(str_contains($svg, 'width="145" height="145"'));
    });

    $harness->check(QrCodeService::class, 'supports PNG output', function () use ($harness, $service): void {
        $png = $service->generatePng('abc');

        $harness->assertTrue(str_starts_with($png, "\x89PNG\r\n\x1A\n"));
    });

    $harness->check(QrCodeService::class, 'supports higher error correction levels when choosing the version', function () use ($harness, $service): void {
        $svg = $service->generateQRcode(str_repeat('a', 20), ['error_correction_level' => 'H']);

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 370 370"'));
    });

    $harness->check(QrCodeService::class, 'auto mode chooses the smallest version then the strongest ECC and matching module size', function () use ($harness, $service): void {
        $config = $service->resolveAutoOptions(str_repeat('a', 20));

        $harness->assertSame('svg', $config['format']);
        $harness->assertSame('Q', $config['error_correction_level']);
        $harness->assertSame(10, $config['module_size']);
        $harness->assertSame(2, $config['version']);
    });

    $harness->check(QrCodeService::class, 'generateQRcode accepts auto selection for ECC and module size', function () use ($harness, $service): void {
        $svg = $service->generateQRcode(str_repeat('a', 20), [
            'error_correction_level' => 'auto',
            'module_size' => 'auto',
        ]);

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 330 330"'));
    });

    $harness->check(QrCodeService::class, 'supports the longer Elstone otpauth payload under auto selection', function () use ($harness, $service): void {
        $input = 'otpauth://totp/Elstone:demo?secret=BCUTUKT6MT33Y3FCHSMHU2XRHKLTX7RN&issuer=Elstone&algorithm=SHA1&digits=6&period=30';
        $config = $service->resolveAutoOptions($input);

        $harness->assertSame('L', $config['error_correction_level']);
        $harness->assertSame(7, $config['module_size']);
        $harness->assertSame(6, $config['version']);

        $svg = $service->generateQRcode($input, [
            'error_correction_level' => 'auto',
            'module_size' => 'auto',
        ]);

        $harness->assertTrue(str_contains($svg, 'viewBox="0 0 343 343"'));
    });

    $harness->check(QrCodeService::class, 'matches the version 1 M byte-mode codewords for A', function () use ($harness, $service): void {
        $inspect = Closure::bind(function (): array {
            $data = $this->buildDataCodewords('A', 16);
            $ecc = $this->buildErrorCorrectionCodewords($data, 10);

            return [$data, $ecc];
        }, $service, QrCodeService::class);

        [$data, $ecc] = $inspect();

        $harness->assertSame(
            [64, 20, 16, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236],
            $data
        );
        $harness->assertSame(
            [107, 112, 244, 24, 163, 122, 17, 95, 52, 252],
            $ecc
        );
    });

    $harness->check(QrCodeService::class, 'matches the version 5 M byte-mode block and interleave vectors for the Elstone otpauth payload', function () use ($harness, $service): void {
        $input = 'otpauth://totp/Elstone:demo?secret=JBSWY3DPEHPK3PXP&issuer=Elstone';

        $inspect = Closure::bind(function () use ($input): array {
            $spec = $this->buildVersionSpec(5, 'M');
            $data = $this->buildDataCodewords($input, $spec['data_codewords'], 5);
            $blocks = $this->splitDataIntoBlocks($data, $spec['num_blocks'], $spec['ec_codewords_per_block']);
            $eccBlocks = [];

            foreach ($blocks as $block) {
                $eccBlocks[] = $this->buildErrorCorrectionCodewords($block, $spec['ec_codewords_per_block']);
            }

            $interleaved = [];
            $maxDataBlockLength = 0;

            foreach ($blocks as $block) {
                $maxDataBlockLength = max($maxDataBlockLength, count($block));
            }

            for ($index = 0; $index < $maxDataBlockLength; $index++) {
                foreach ($blocks as $block) {
                    if (array_key_exists($index, $block)) {
                        $interleaved[] = $block[$index];
                    }
                }
            }

            for ($index = 0; $index < $spec['ec_codewords_per_block']; $index++) {
                foreach ($eccBlocks as $block) {
                    $interleaved[] = $block[$index];
                }
            }

            return [$data, $blocks, $eccBlocks, $interleaved];
        }, $service, QrCodeService::class);

        [$data, $blocks, $eccBlocks, $interleaved] = $inspect();

        $harness->assertSame(
            [68, 38, 247, 71, 6, 23, 87, 70, 131, 162, 242, 247, 70, 247, 71, 2, 244, 86, 199, 55, 70, 246, 230, 83, 166, 70, 86, 214, 243, 247, 54, 86, 55, 38, 87, 67, 212, 164, 37, 53, 117, 147, 52, 69, 4, 84, 133, 4, 179, 53, 5, 133, 2, 102, 151, 55, 55, 86, 87, 35, 212, 86, 199, 55, 70, 246, 230, 80, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17],
            $data
        );
        $harness->assertSame(
            [68, 38, 247, 71, 6, 23, 87, 70, 131, 162, 242, 247, 70, 247, 71, 2, 244, 86, 199, 55, 70, 246, 230, 83, 166, 70, 86, 214, 243, 247, 54, 86, 55, 38, 87, 67, 212, 164, 37, 53, 117, 147, 52],
            $blocks[0]
        );
        $harness->assertSame(
            [69, 4, 84, 133, 4, 179, 53, 5, 133, 2, 102, 151, 55, 55, 86, 87, 35, 212, 86, 199, 55, 70, 246, 230, 80, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17],
            $blocks[1]
        );
        $harness->assertSame(
            [1, 248, 70, 238, 85, 34, 164, 230, 122, 93, 54, 84, 141, 234, 19, 135, 214, 152, 182, 53, 205, 50, 38, 32],
            $eccBlocks[0]
        );
        $harness->assertSame(
            [2, 148, 185, 120, 246, 144, 15, 18, 105, 143, 54, 86, 21, 121, 211, 140, 51, 180, 38, 196, 45, 171, 58, 138],
            $eccBlocks[1]
        );
        $harness->assertSame(
            [68, 69, 38, 4, 247, 84, 71, 133, 6, 4, 23, 179, 87, 53, 70, 5, 131, 133, 162, 2, 242, 102, 247, 151, 70, 55, 247, 55, 71, 86, 2, 87, 244, 35, 86, 212, 199, 86, 55, 199, 70, 55, 246, 70, 230, 246, 83, 230, 166, 80, 70, 236, 86, 17, 214, 236, 243, 17, 247, 236, 54, 17, 86, 236, 55, 17, 38, 236, 87, 17, 67, 236, 212, 17, 164, 236, 37, 17, 53, 236, 117, 17, 147, 236, 52, 17, 1, 2, 248, 148, 70, 185, 238, 120, 85, 246, 34, 144, 164, 15, 230, 18, 122, 105, 93, 143, 54, 54, 84, 86, 141, 21, 234, 121, 19, 211, 135, 140, 214, 51, 152, 180, 182, 38, 53, 196, 205, 45, 50, 171, 38, 58, 32, 138],
            $interleaved
        );
    });

    $harness->check(QrCodeService::class, 'places format bits in the standard QR coordinates', function () use ($harness, $service): void {
        $inspect = Closure::bind(function (): array {
            $version = 2;
            $size = $this->matrixSize($version);
            [$modules, $reserved] = $this->createBaseMatrix($version, $size, [6, 18]);
            $this->placeFormatInformation($modules, 0, 'M');

            return [$modules, $reserved];
        }, $service, QrCodeService::class);

        [$modules, $reserved] = $inspect();

        $harness->assertTrue($reserved[8][7]);
        $harness->assertTrue($reserved[18][8]);
        $harness->assertTrue($reserved[8][5]);
        $harness->assertTrue($modules[0][8] === false);
        $harness->assertTrue($modules[2][8] === false);
        $harness->assertTrue($modules[8][7] === false);
        $harness->assertTrue($modules[8][5] === false);
        $harness->assertTrue($modules[8][24] === false);
        $harness->assertTrue($modules[18][8] === false);
        $harness->assertTrue($modules[24][8] === true);
    });

    $harness->check(QrCodeService::class, 'rejects payloads longer than the supported limit', function () use ($harness, $service): void {
        try {
            $service->generateQRcode(str_repeat('a', 214));
        } catch (InvalidArgumentException $exception) {
            $harness->assertTrue(str_contains($exception->getMessage(), 'supports up to 213 bytes'));
            $harness->assertTrue(!str_contains($exception->getMessage(), 'supports up to 62 bytes'));
            return;
        }

        throw new RuntimeException('Expected InvalidArgumentException for oversized QR payload.');
    });

    $harness->check(QrCodeService::class, 'rejects unsupported output formats', function () use ($harness, $service): void {
        try {
            $service->generateQRcode('abc', ['format' => 'gif']);
        } catch (InvalidArgumentException $exception) {
            $harness->assertTrue(str_contains($exception->getMessage(), 'format must be svg or png'));
            return;
        }

        throw new RuntimeException('Expected InvalidArgumentException for unsupported QR output format.');
    });
});
