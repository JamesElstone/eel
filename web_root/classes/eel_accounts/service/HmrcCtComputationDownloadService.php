<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Download, safely expand and catalogue the explicitly accepted CT2024 package. */
final class HmrcCtComputationDownloadService
{
    private const DIRECTORY_NAME = 'CT2024-v1.0.0';
    private const MANIFEST_IDENTIFIER = 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/v1.0.0/';
    private const ENTRY_POINT_HREF = 'http://www.hmrc.gov.uk/schemas/ct/comp/2024-01-01/ct-comp-2024.xsd';
    private const MAX_ARCHIVE_BYTES = 52428800;
    private const MAX_REDIRECTS = 5;
    private const TRUSTED_DOWNLOAD_HOSTS = [
        'www.hmrc.gov.uk',
        'hmrc.gov.uk',
        'assets.publishing.service.gov.uk',
    ];

    private ?\Closure $fetcher;
    private ?\Closure $cataloguer;
    private string $cacheDirectory;

    public function __construct(
        ?callable $fetcher = null,
        ?string $cacheDirectory = null,
        ?callable $cataloguer = null
    ) {
        $this->fetcher = $fetcher === null ? null : \Closure::fromCallable($fetcher);
        $this->cataloguer = $cataloguer === null ? null : \Closure::fromCallable($cataloguer);
        $default = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR
            . 'third_party' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'ct-computation';
        $this->cacheDirectory = rtrim(trim((string)($cacheDirectory ?? $default)), '\\/');
        if ($this->cacheDirectory === '') {
            throw new \InvalidArgumentException('An HMRC computation-taxonomy cache directory is required.');
        }
    }

    /**
     * @return array{success: bool, already_installed: bool, package_id: int, profile_id: int,
     *     file_count: int, concept_count: int, archive_path: ?string, directory: ?string,
     *     errors: list<string>}
     */
    public function install(string $actor = 'hmrc-computation-download'): array
    {
        $lock = null;
        try {
            $this->ensureCacheDirectory();
            $lock = $this->acquireInstallLock();
        } catch (\Throwable $exception) {
            return $this->failureResult($exception->getMessage());
        }

        try {
            try {
                return $this->installLocked($actor);
            } catch (\Throwable $exception) {
                $this->markFailed($exception->getMessage());
                return $this->failureResult($exception->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function installLocked(string $actor): array
    {
        $catalogue = new HmrcCtComputationCatalogueService();
        $package = $this->cataloguer instanceof \Closure ? null : $this->package();
        if (is_array($package) && (string)($package['package_state'] ?? '') === 'verified') {
            $verifiedHash = $catalogue->verifiedPackageHash($package);
            if ($verifiedHash !== null) {
                try {
                    $profileId = $this->activeOrPreparedProfile((int)$package['id'], $actor);
                    return $this->successResult($package, $profileId, true);
                } catch (\Throwable $exception) {
                    $this->markFailed($exception->getMessage());
                    return $this->failureResult($exception->getMessage());
                }
            }
        }

        $temporaryArchive = null;
        $stagingDirectory = null;
        $installedThisRun = false;
        try {
            $expandedDirectory = $this->expandedDirectory();

            $url = HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL;
            $archiveBytes = $this->fetchArchive($url);
            if ($archiveBytes === '' || strlen($archiveBytes) > self::MAX_ARCHIVE_BYTES) {
                throw new \RuntimeException('The HMRC CT2024 computation-taxonomy archive is empty or unexpectedly large.');
            }

            $temporaryArchive = tempnam($this->cacheDirectory, 'ct2024-download-');
            if ($temporaryArchive === false) {
                throw new \RuntimeException('A temporary HMRC CT2024 archive could not be created.');
            }
            if (file_put_contents($temporaryArchive, $archiveBytes, LOCK_EX) !== strlen($archiveBytes)) {
                throw new \RuntimeException('The HMRC CT2024 archive could not be stored completely.');
            }

            $zip = new HmrcCtRimZipService();
            if ($zip->countXsdFiles($temporaryArchive) < 1) {
                throw new \RuntimeException('The HMRC CT2024 archive contains no XSD taxonomy files.');
            }
            $stagingDirectory = $this->cacheDirectory . DIRECTORY_SEPARATOR
                . '.ct2024-staging-' . bin2hex(random_bytes(8));
            $zip->extract($temporaryArchive, $stagingDirectory);
            if (!$this->isAcceptedExpandedPackage($stagingDirectory, $catalogue)) {
                throw new \RuntimeException('The downloaded archive is not the accepted HMRC CT2024 V1.0.0 computation taxonomy.');
            }

            $this->replaceExpandedDirectory($stagingDirectory, $expandedDirectory);
            $installedThisRun = true;
            $stagingDirectory = null;
            $archivePath = $this->archivePath();
            if (is_file($archivePath) && !@unlink($archivePath)) {
                throw new \RuntimeException('The previous HMRC CT2024 archive could not be replaced.');
            }
            $this->renameManagedPath(
                $temporaryArchive,
                $archivePath,
                'The verified HMRC CT2024 archive could not be stored.'
            );
            $temporaryArchive = null;

            $catalogued = $this->catalogueAcceptedPackage($catalogue, $expandedDirectory, $actor);
            $fresh = $this->package((int)($catalogued['package_id'] ?? 0));
            return $this->successResult(
                is_array($fresh) ? $fresh : $catalogued,
                (int)($catalogued['mapping_profile_id'] ?? 0),
                false,
                $catalogued
            );
        } catch (\Throwable $exception) {
            $errors = [$exception->getMessage()];
            if ($installedThisRun) {
                try {
                    $this->removeManagedDirectory($this->expandedDirectory());
                } catch (\Throwable $cleanupException) {
                    $errors[] = $cleanupException->getMessage();
                }
                $archivePath = $this->archivePath();
                if (is_file($archivePath) && !@unlink($archivePath)) {
                    $errors[] = 'The partial HMRC CT2024 archive could not be removed.';
                }
            }
            $this->markFailed(implode(' ', $errors));
            return $this->failureResult($errors);
        } finally {
            if (is_string($temporaryArchive) && is_file($temporaryArchive)) {
                @unlink($temporaryArchive);
            }
            if (is_string($stagingDirectory) && is_dir($stagingDirectory)) {
                $this->removeManagedDirectory($stagingDirectory);
            }
        }
    }

    public function cacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    private function activeOrPreparedProfile(int $packageId, string $actor): int
    {
        $mappings = new CtFilingMappingService();
        $profile = $mappings->activeProfile(CtFilingMappingService::TARGET_COMPUTATION, $packageId);
        return is_array($profile)
            ? (int)$profile['id']
            : $mappings->prepareMappingsForPackage(CtFilingMappingService::TARGET_COMPUTATION, $packageId, $actor);
    }

    private function catalogueAcceptedPackage(
        HmrcCtComputationCatalogueService $catalogue,
        string $directory,
        string $actor
    ): array {
        $result = $this->cataloguer instanceof \Closure
            ? ($this->cataloguer)($directory, $actor)
            : $catalogue->catalogueAccepted2024Directory($directory, $actor);
        if (!is_array($result) || empty($result['package_id']) || empty($result['mapping_profile_id'])) {
            throw new \RuntimeException('The HMRC CT2024 package catalogue returned an invalid result.');
        }
        return $result;
    }

    private function fetchArchive(string $url): string
    {
        if (!hash_equals(HmrcCtComputationCatalogueService::CT2024_DOWNLOAD_URL, $url)) {
            throw new \RuntimeException('The HMRC computation-taxonomy download URL is not trusted.');
        }
        if ($this->fetcher instanceof \Closure) {
            $bytes = ($this->fetcher)($url);
            if (!is_string($bytes)) {
                throw new \RuntimeException('The HMRC CT2024 download returned an invalid response.');
            }
            return $bytes;
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL is required to download HMRC computation-taxonomy artefacts.');
        }
        return $this->downloadWithCurl($url);
    }

    private function downloadWithCurl(string $url): string
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $this->assertTrustedDownloadUrl($url);
            $bytes = '';
            $location = null;
            $tooLarge = false;
            $handle = curl_init($url);
            if ($handle === false) {
                throw new \RuntimeException('The HMRC CT2024 download could not be started.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'EEL Accounts HMRC CT computation taxonomy download',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($unused, string $header) use (&$location): int {
                    if (preg_match('/^Location:\s*(.+)$/i', trim($header), $match) === 1) {
                        $location = trim($match[1]);
                    }
                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$bytes, &$tooLarge): int {
                    if (strlen($bytes) + strlen($chunk) > self::MAX_ARCHIVE_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $bytes .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $executed = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);

            if ($tooLarge) {
                throw new \RuntimeException('The HMRC CT2024 computation-taxonomy archive exceeds the 50 MB limit.');
            }
            if ($executed !== true || $error !== '') {
                throw new \RuntimeException('The HMRC CT2024 download failed'
                    . ($error !== '' ? ': ' . $error : '.'));
            }
            if ($status >= 300 && $status < 400) {
                if ($location === null || $location === '') {
                    throw new \RuntimeException('The HMRC CT2024 download returned a redirect without a destination.');
                }
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('The HMRC CT2024 download exceeded the redirect limit.');
                }
                $url = $this->resolveRedirectUrl($url, $location);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('The HMRC CT2024 download failed with HTTP status ' . $status . '.');
            }
            return $bytes;
        }

        throw new \RuntimeException('The HMRC CT2024 download exceeded the redirect limit.');
    }

    private function resolveRedirectUrl(string $currentUrl, string $location): string
    {
        $location = trim($location);
        if (str_starts_with($location, '//')) {
            $resolved = 'https:' . $location;
        } elseif (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            $resolved = $location;
        } else {
            $current = parse_url($currentUrl);
            if (!is_array($current) || empty($current['host'])) {
                throw new \RuntimeException('The HMRC CT2024 redirect could not be resolved.');
            }
            $authority = 'https://' . (string)$current['host']
                . (isset($current['port']) ? ':' . (int)$current['port'] : '');
            if (str_starts_with($location, '/')) {
                $resolved = $authority . $location;
            } else {
                $basePath = (string)($current['path'] ?? '/');
                $slash = strrpos($basePath, '/');
                $directory = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
                $resolved = $authority . $directory . $location;
            }
        }
        $this->assertTrustedDownloadUrl($resolved);
        return $resolved;
    }

    private function assertTrustedDownloadUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
        $port = is_array($parts) && isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($scheme !== 'https' || $port !== 443
            || !in_array($host, self::TRUSTED_DOWNLOAD_HOSTS, true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('The HMRC CT2024 download redirected to an untrusted URL.');
        }
    }

    private function isAcceptedExpandedPackage(string $directory, HmrcCtComputationCatalogueService $catalogue): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        try {
            $manifest = $catalogue->inspectDirectory($directory);
        } catch (\Throwable) {
            return false;
        }
        return !empty($manifest['has_manifest'])
            && (string)($manifest['taxonomy_version'] ?? '') === '2024'
            && strtoupper((string)($manifest['artifact_version'] ?? '')) === 'V1.0.0'
            && hash_equals(self::MANIFEST_IDENTIFIER, (string)($manifest['identifier'] ?? ''))
            && hash_equals(self::ENTRY_POINT_HREF, (string)($manifest['entry_point_href'] ?? ''))
            && is_string($manifest['entry_point_path'] ?? null)
            && is_file((string)$manifest['entry_point_path']);
    }

    private function replaceExpandedDirectory(string $staging, string $destination): void
    {
        $this->assertManagedPath($staging);
        $this->assertManagedPath($destination);
        if (is_dir($destination)) {
            $this->removeManagedDirectory($destination);
        }
        $this->renameManagedPath(
            $staging,
            $destination,
            'The verified HMRC CT2024 package could not be installed.'
        );
    }

    private function renameManagedPath(string $source, string $destination, string $error): void
    {
        $this->assertManagedPath($source);
        $this->assertManagedPath($destination);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (@rename($source, $destination)) {
                return;
            }
            clearstatcache(true, $source);
            clearstatcache(true, $destination);
            if (!file_exists($source) && file_exists($destination)) {
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException($error);
    }

    private function removeManagedDirectory(string $directory): void
    {
        $this->assertManagedPath($directory);
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isLink() || $entry->isFile()) {
                @unlink($entry->getPathname());
            } elseif ($entry->isDir()) {
                @rmdir($entry->getPathname());
            }
        }
        @rmdir($directory);
        if (is_dir($directory)) {
            throw new \RuntimeException('A partial HMRC CT2024 extraction could not be removed.');
        }
    }

    private function assertManagedPath(string $path): void
    {
        $cache = str_replace('\\', '/', $this->absolutePath($this->cacheDirectory));
        $candidate = str_replace('\\', '/', $this->absolutePath($path));
        $prefix = rtrim($cache, '/') . '/';
        if ($candidate === $cache || !str_starts_with($candidate . '/', $prefix)) {
            throw new \RuntimeException('Refusing to modify a path outside the HMRC computation-taxonomy cache.');
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

    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDirectory)
            && !mkdir($this->cacheDirectory, 0775, true)
            && !is_dir($this->cacheDirectory)) {
            throw new \RuntimeException('The HMRC computation-taxonomy cache directory could not be created.');
        }
    }

    /** @return resource */
    private function acquireInstallLock(): mixed
    {
        $path = $this->cacheDirectory . DIRECTORY_SEPARATOR . '.ct2024-install.lock';
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('The HMRC CT2024 installation lock could not be created.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another HMRC CT2024 installation is already running.');
        }
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, 'PID ' . (string)getmypid() . ' since ' . gmdate('c'));
        fflush($handle);
        return $handle;
    }

    private function archivePath(): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME . '.zip';
    }

    private function expandedDirectory(): string
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME;
    }

    private function package(int $packageId = 0): ?array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
            return null;
        }
        $row = $packageId > 0
            ? \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct_computation_packages WHERE id = :id LIMIT 1', ['id' => $packageId])
            : \InterfaceDB::fetchOne(
                'SELECT * FROM hmrc_ct_computation_packages
                 WHERE taxonomy_version = :taxonomy AND UPPER(artifact_version) = :artifact
                 ORDER BY id DESC LIMIT 1',
                ['taxonomy' => '2024', 'artifact' => 'V1.0.0']
            );
        return is_array($row) ? $row : null;
    }

    private function packageWithCounts(int $packageId): ?array
    {
        foreach ((new HmrcCtComputationCatalogueService())->fetchPackages() as $package) {
            if ((int)($package['id'] ?? 0) === $packageId) {
                return $package;
            }
        }
        return $this->package($packageId);
    }

    private function markFailed(string $error): void
    {
        if ($this->cataloguer instanceof \Closure) {
            return;
        }
        if (!\InterfaceDB::tableExists('hmrc_ct_computation_packages')) {
            return;
        }
        \InterfaceDB::prepareExecute(
            'UPDATE hmrc_ct_computation_packages
             SET package_state = :state, verification_error = :error, checked_at = CURRENT_TIMESTAMP
             WHERE taxonomy_version = :taxonomy AND UPPER(artifact_version) = :artifact',
            ['state' => 'failed', 'error' => $error, 'taxonomy' => '2024', 'artifact' => 'V1.0.0']
        );
    }

    private function successResult(
        array $package,
        int $profileId,
        bool $alreadyInstalled,
        array $catalogued = []
    ): array {
        $packageId = (int)($package['id'] ?? $catalogued['package_id'] ?? 0);
        $withCounts = $packageId > 0 && !($this->cataloguer instanceof \Closure)
            ? $this->packageWithCounts($packageId)
            : null;
        $source = is_array($withCounts) ? $withCounts : ($catalogued + $package);
        return [
            'success' => true,
            'already_installed' => $alreadyInstalled,
            'package_id' => $packageId,
            'profile_id' => $profileId,
            'file_count' => (int)($source['file_count'] ?? $catalogued['file_count'] ?? 0),
            'concept_count' => (int)($source['concept_count'] ?? $catalogued['concept_count'] ?? 0),
            'archive_path' => is_file($this->archivePath()) ? $this->archivePath() : null,
            'directory' => (string)($source['local_path'] ?? $catalogued['directory'] ?? $this->expandedDirectory()),
            'errors' => [],
        ];
    }

    /** @param string|list<string> $errors */
    private function failureResult(string|array $errors): array
    {
        $errors = is_array($errors) ? array_values($errors) : [$errors];
        return [
            'success' => false,
            'already_installed' => false,
            'package_id' => $this->cataloguer instanceof \Closure ? 0 : (int)($this->package()['id'] ?? 0),
            'profile_id' => 0,
            'file_count' => 0,
            'concept_count' => 0,
            'archive_path' => null,
            'directory' => null,
            'errors' => $errors,
        ];
    }
}
