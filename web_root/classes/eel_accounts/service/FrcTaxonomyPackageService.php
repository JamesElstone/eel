<?php
declare(strict_types=1);

namespace eel_accounts\Service;

/** Manages the official FRC taxonomy package used for offline accounts iXBRL validation. */
final class FrcTaxonomyPackageService
{
    public const SOURCE_URL = 'https://www.frc.org.uk/library/standards-codes-policy/accounting-and-reporting/frc-taxonomies/current-frc-taxonomy-suites/2026-frc-taxonomy-suite/';

    public function fetchPackages(): array
    {
        if (!\InterfaceDB::tableExists('frc_taxonomy_packages')) { return []; }
        return \InterfaceDB::fetchAll('SELECT * FROM frc_taxonomy_packages ORDER BY published_at DESC, id DESC');
    }

    public function activePackage(): ?array
    {
        if (!\InterfaceDB::tableExists('frc_taxonomy_packages')) { return null; }
        $row = \InterfaceDB::fetchOne("SELECT * FROM frc_taxonomy_packages WHERE is_active = 1 AND package_state = 'verified' ORDER BY verified_at DESC, id DESC LIMIT 1");
        if (!is_array($row) || !is_file((string)($row['local_path'] ?? ''))) { return null; }
        $hash = hash_file('sha256', (string)$row['local_path']);
        return is_string($hash) && hash_equals((string)$row['sha256'], strtolower($hash)) ? $row : null;
    }

    public function refreshAndInstall(): array
    {
        if (!\InterfaceDB::tableExists('frc_taxonomy_packages')) {
            throw new \RuntimeException('Apply database migration 2026_07_21_003_frc_taxonomy_artifacts.sql before refreshing the FRC taxonomy package.');
        }
        $html = $this->fetch(self::SOURCE_URL);
        if (preg_match('/href=["\']([^"\']+\.zip)["\'][^>]*>[^<]*(?:2026 FRC Taxonomy Suite|FRC Taxonomy Suite 2026)/i', $html, $match) !== 1
            && preg_match('/(?:2026 FRC Taxonomy Suite|FRC Taxonomy Suite 2026).*?href=["\']([^"\']+\.zip)["\']/is', $html, $match) !== 1
            && preg_match('/href=["\']([^"\']+\.zip)["\']/i', $html, $match) !== 1) {
            throw new \RuntimeException('The official FRC 2026 taxonomy ZIP download link could not be found.');
        }
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        if (str_starts_with($url, '/')) { $url = 'https://www.frc.org.uk' . $url; }
        if (!preg_match('#^https://(?:www\.)?frc\.org\.uk/|^https://media\.frc\.org\.uk/#i', $url)) {
            throw new \RuntimeException('The FRC taxonomy download URL is not trusted.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'frc_taxonomy_');
        if ($temporary === false) { throw new \RuntimeException('Could not create a temporary FRC taxonomy download file.'); }
        try {
            $this->download($url, $temporary);
            $entry = 'FRS-102/2026-01-01/FRS-102-2026-01-01.xsd';
            $inventory = (new HmrcCtRimZipService())->inventory($temporary);
            $hasEntry = in_array(
                strtolower($entry),
                array_map(static fn(array $file): string => strtolower((string)($file['archive_path'] ?? '')), $inventory),
                true
            );
            if (!$hasEntry) { throw new \RuntimeException('The FRC taxonomy package does not contain the required FRS-102 2026 entry point.'); }
            $sha256 = hash_file('sha256', $temporary);
            if (!is_string($sha256) || $sha256 === '') { throw new \RuntimeException('The downloaded FRC taxonomy could not be fingerprinted.'); }
            $directory = rtrim(PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'third_party' . DIRECTORY_SEPARATOR . 'frc' . DIRECTORY_SEPARATOR . 'taxonomies';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new \RuntimeException('The FRC taxonomy store could not be created.'); }
            $path = $directory . DIRECTORY_SEPARATOR . 'frc-2026-v1.0.0-' . substr($sha256, 0, 16) . '.zip';
            if (!is_file($path) && !rename($temporary, $path)) { throw new \RuntimeException('The verified FRC taxonomy package could not be stored.'); }
            if (is_file($temporary)) { @unlink($temporary); }
            \InterfaceDB::transaction(function () use ($url, $path, $sha256): void {
                \InterfaceDB::prepareExecute('UPDATE frc_taxonomy_packages SET is_active = 0 WHERE is_active = 1');
                \InterfaceDB::prepareExecute(
                    "INSERT INTO frc_taxonomy_packages (taxonomy_version, artifact_version, source_url, download_url, local_path, sha256, package_state, is_active, published_at, verified_at) VALUES ('2026', 'v1.0.0', :source, :download, :path, :sha, 'verified', 1, '2025-11-18', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source_url=VALUES(source_url),download_url=VALUES(download_url),local_path=VALUES(local_path),package_state='verified',is_active=1,verified_at=CURRENT_TIMESTAMP,verification_error=NULL",
                    ['source' => self::SOURCE_URL, 'download' => $url, 'path' => $path, 'sha' => strtolower($sha256)]
                );
            });
            return ['success' => true, 'path' => $path, 'sha256' => strtolower($sha256)];
        } finally { if (is_file($temporary)) { @unlink($temporary); } }
    }

    private function fetch(string $url): string
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('PHP cURL is required to refresh FRC taxonomy metadata.'); }
        $handle = curl_init($url); curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'EEL Accounts FRC taxonomy refresh']);
        $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) { throw new \RuntimeException('FRC taxonomy metadata refresh failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.'); }
        return $body;
    }

    private function download(string $url, string $path): void
    {
        $handle = curl_init($url); $file = fopen($path, 'wb');
        if ($handle === false || $file === false) { throw new \RuntimeException('The FRC taxonomy download could not be started.'); }
        curl_setopt_array($handle, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'EEL Accounts FRC taxonomy download']);
        curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle); fclose($file);
        if ($status < 200 || $status >= 300 || $error !== '') { throw new \RuntimeException('The FRC taxonomy download failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.'); }
    }
}
