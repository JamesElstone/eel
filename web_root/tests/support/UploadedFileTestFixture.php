<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace {
    final class UploadedFileTestFixture
    {
        /** @var array<string, true> */
        private static array $temporaryPaths = [];
        /** @var array<string, true> */
        private static array $storedPaths = [];
        private static ?array $originalConfig = null;
        private static string $uploadsRoot = '';

        public static function jpegUpload(string $name = 'asset-evidence.jpg'): array
        {
            self::configureUploadsRoot();
            $path = tempnam(sys_get_temp_dir(), 'eel-upload-');
            if (!is_string($path) || $path === '') {
                throw new RuntimeException('Unable to create a temporary upload fixture.');
            }

            $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q==', true);
            if (!is_string($bytes) || file_put_contents($path, $bytes) === false) {
                throw new RuntimeException('Unable to write the temporary upload fixture.');
            }

            self::$temporaryPaths[$path] = true;

            return [
                'name' => $name,
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($bytes),
                'type' => 'image/jpeg',
            ];
        }

        public static function isRegistered(string $path): bool
        {
            return isset(self::$temporaryPaths[$path]);
        }

        public static function move(string $source, string $target): bool
        {
            if (!self::isRegistered($source) || !@rename($source, $target)) {
                return false;
            }

            unset(self::$temporaryPaths[$source]);
            self::$storedPaths[$target] = true;

            return true;
        }

        public static function cleanup(): void
        {
            foreach (array_keys(self::$temporaryPaths + self::$storedPaths) as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            self::$temporaryPaths = [];
            self::$storedPaths = [];

            if (self::$uploadsRoot !== '' && is_dir(self::$uploadsRoot)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(self::$uploadsRoot, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        @rmdir($item->getPathname());
                    } else {
                        @unlink($item->getPathname());
                    }
                }
                @rmdir(self::$uploadsRoot);
            }
            self::$uploadsRoot = '';

            if (is_array(self::$originalConfig)) {
                $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
                $property->setAccessible(true);
                $property->setValue(null, self::$originalConfig);
                self::$originalConfig = null;
            }
        }

        private static function configureUploadsRoot(): void
        {
            if (self::$uploadsRoot !== '') {
                return;
            }

            self::$uploadsRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eel-asset-evidence-' . bin2hex(random_bytes(6));
            if (!mkdir(self::$uploadsRoot, 0700, true) && !is_dir(self::$uploadsRoot)) {
                throw new RuntimeException('Unable to create the temporary uploads root.');
            }

            self::$originalConfig = AppConfigurationStore::config();
            $config = self::$originalConfig;
            $config['uploads'] = is_array($config['uploads'] ?? null) ? $config['uploads'] : [];
            $config['uploads']['upload_base_dir'] = self::$uploadsRoot;

            $property = new ReflectionProperty(AppConfigurationStore::class, 'config');
            $property->setAccessible(true);
            $property->setValue(null, $config);
        }
    }
}

namespace eel_accounts\Service {
    function is_uploaded_file(string $filename): bool
    {
        return \UploadedFileTestFixture::isRegistered($filename) || \is_uploaded_file($filename);
    }

    function move_uploaded_file(string $from, string $to): bool
    {
        if (\UploadedFileTestFixture::isRegistered($from)) {
            return \UploadedFileTestFixture::move($from, $to);
        }

        return \move_uploaded_file($from, $to);
    }
}
