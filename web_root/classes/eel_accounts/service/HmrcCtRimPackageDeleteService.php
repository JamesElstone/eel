<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimPackageDeleteService
{
    public function delete(int $packageId): array
    {
        $row = \InterfaceDB::fetchOne('SELECT id, local_path FROM hmrc_ct_rim_packages WHERE id = :id LIMIT 1', ['id' => $packageId]);
        if (!is_array($row)) { return ['success' => false, 'errors' => ['The selected HMRC CT600 RIM package was not found.']]; }

        $zipPath = trim((string)($row['local_path'] ?? ''));
        $extractDirectory = null;
        if ($zipPath !== '') {
            $cacheDirectory = (new HmrcCtRimCatalogueService())->cacheDirectory();
            $this->assertInsideDirectory($zipPath, $cacheDirectory);
            $extractDirectory = (new HmrcCtRimZipService())->extractionDirectory($zipPath);
            $this->assertInsideDirectory($extractDirectory, $cacheDirectory);
            if (is_dir($extractDirectory)) { $this->deleteDirectory($extractDirectory); }
            if (is_file($zipPath) && !@unlink($zipPath)) { throw new \RuntimeException('The HMRC CT600 RIM ZIP file could not be deleted.'); }
        }

        \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_rim_files WHERE package_id = :package_id', ['package_id' => $packageId]);
        \InterfaceDB::prepareExecute('DELETE FROM hmrc_ct_rim_packages WHERE id = :id', ['id' => $packageId]);
        return ['success' => true, 'package_id' => $packageId];
    }

    private function assertInsideDirectory(string $path, string $directory): void
    {
        $directoryPrefix = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (!str_starts_with(strtolower($candidate), strtolower($directoryPrefix))) {
            throw new \RuntimeException('The selected HMRC CT600 RIM path is outside the cache directory.');
        }
    }

    private function deleteDirectory(string $directory): void
    {
        $entries = @scandir($directory);
        if (!is_array($entries)) { throw new \RuntimeException('The HMRC CT600 RIM extracted directory could not be read for deletion.'); }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->deleteDirectory($path);
                continue;
            }
            @chmod($path, 0666);
            if (!@unlink($path)) {
                throw new \RuntimeException('An HMRC CT600 RIM extracted file could not be deleted: ' . $path);
            }
        }
        @chmod($directory, 0777);
        if (!@rmdir($directory)) { throw new \RuntimeException('The HMRC CT600 RIM extracted directory could not be deleted: ' . $directory); }
    }
}
