<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/** Removes one managed HMRC computation-taxonomy installation and its catalogue. */
final class HmrcCtComputationPackageDeleteService
{
    private string $cacheDirectory;

    public function __construct(?string $cacheDirectory = null)
    {
        $default = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR
            . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'ct-computation';
        $this->cacheDirectory = rtrim(trim((string)($cacheDirectory ?? $default)), '\\/');
    }

    /** @return array{success: bool, package_id?: int, errors?: list<string>} */
    public function delete(int $packageId): array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
            return ['success' => false, 'errors' => ['The selected HMRC computation-taxonomy package was not found.']];
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT id, local_path FROM hmrc_ct_computation_packages WHERE id = :id LIMIT 1',
            ['id' => $packageId]
        );
        if (!is_array($row)) {
            return ['success' => false, 'errors' => ['The selected HMRC computation-taxonomy package was not found.']];
        }

        $references = \InterfaceDB::tableExists('corporation_tax_computation_runs')
            ? (int)\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM corporation_tax_computation_runs WHERE computation_taxonomy_package_id = :id',
                ['id' => $packageId]
            )
            : 0;
        if ($references > 0) {
            return ['success' => false, 'errors' => [
                'This HMRC computation-taxonomy package is referenced by ' . $references
                . ' generated Corporation Tax computation run(s) and cannot be deleted.',
            ]];
        }

        $localPath = trim((string)($row['local_path'] ?? ''));
        if ($localPath !== '') {
            $this->removeManagedPath($localPath);
            $archivePath = $this->cacheDirectory . DIRECTORY_SEPARATOR . basename(rtrim($localPath, '\\/')) . '.zip';
            $this->removeManagedPath($archivePath);
        }

        \InterfaceDB::beginTransaction();
        try {
            // Foreign-key cascades remove the files, concepts, mappings and mapping children.
            \InterfaceDB::prepareExecute(
                'DELETE FROM hmrc_ct_computation_packages WHERE id = :id',
                ['id' => $packageId]
            );
            \InterfaceDB::commit();
        } catch (\Throwable $exception) {
            \InterfaceDB::rollBack();
            throw $exception;
        }

        return ['success' => true, 'package_id' => $packageId];
    }

    private function removeManagedPath(string $path): void
    {
        $this->assertManagedPath($path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            $this->removeManagedDirectory($path);
            return;
        }
        @chmod($path, 0666);
        if (!@unlink($path)) {
            throw new \RuntimeException('The HMRC computation-taxonomy package file could not be deleted.');
        }
    }

    private function removeManagedDirectory(string $directory): void
    {
        $this->assertManagedPath($directory);
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            throw new \RuntimeException('The HMRC computation-taxonomy directory could not be read for deletion.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeManagedDirectory($path);
                continue;
            }
            @chmod($path, 0666);
            if (!@unlink($path)) {
                throw new \RuntimeException('An HMRC computation-taxonomy package file could not be deleted.');
            }
        }
        @chmod($directory, 0777);
        if (!@rmdir($directory)) {
            throw new \RuntimeException('The HMRC computation-taxonomy directory could not be deleted.');
        }
    }

    private function assertManagedPath(string $path): void
    {
        $cache = $this->absolutePath($this->cacheDirectory);
        $candidate = $this->absolutePath($path);
        $prefix = rtrim(str_replace('\\', '/', $cache), '/') . '/';
        $candidate = str_replace('\\', '/', $candidate);
        if ($candidate === rtrim($prefix, '/') || !str_starts_with($candidate . '/', $prefix)) {
            throw new \RuntimeException('Refusing to delete a path outside the HMRC computation-taxonomy cache.');
        }
    }

    private function absolutePath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return rtrim($resolved, '\\/');
        }
        $parent = realpath(dirname($path));
        return rtrim((string)($parent !== false ? $parent : dirname($path)), '\\/')
            . DIRECTORY_SEPARATOR . basename($path);
    }
}
