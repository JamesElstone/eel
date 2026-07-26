<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'support' . DIRECTORY_SEPARATOR . 'ServiceClassTestHarness.php';

(new GeneratedServiceClassTestHarness())->run(
    \eel_accounts\Service\HmrcCtRimZipService::class,
    static function (GeneratedServiceClassTestHarness $harness, \eel_accounts\Service\HmrcCtRimZipService $service): void {
        $harness->check($service::class, 'derives a sibling extraction directory from a ZIP path', static function () use ($harness, $service): void {
            $path = 'C:' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ct600-v2.zip';
            $harness->assertSame('C:' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'ct600-v2', $service->extractionDirectory($path));
        });

        $harness->check($service::class, 'reads selected entries through a common archive prefix without extraction', static function () use ($harness, $service): void {
            $path = test_tmp_directory() . DIRECTORY_SEPARATOR . 'selected-zip-entry-test.zip';
            $name = 'package-root/META-INF/taxonomyPackage.xml';
            $content = '<taxonomyPackage>fixture</taxonomyPackage>';
            $size = strlen($content);
            $crc = (int)hexdec(hash('crc32b', $content));
            $nameLength = strlen($name);
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0)
                . $name . $content;
            $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 33, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, 0)
                . $name;
            $zip = $local . $central
                . pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
            file_put_contents($path, $zip);
            try {
                $entries = $service->readEntries($path, ['META-INF/taxonomyPackage.xml']);
                $harness->assertSame($content, (string)($entries['META-INF/taxonomyPackage.xml'] ?? ''));
                $harness->assertSame([], $service->readEntries($path, ['missing.xml']));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        });
    }
);
