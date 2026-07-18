<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimDownloadService
{
    public function download(int $packageId): array
    {
        $row = \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct_rim_packages WHERE id = :id LIMIT 1', ['id' => $packageId]);
        if (!is_array($row)) { return ['success' => false, 'errors' => ['The selected HMRC CT600 RIM package was not found.']]; }
        $url = trim((string)($row['download_url'] ?? ''));
        if (!str_starts_with($url, 'https://assets.publishing.service.gov.uk/')) { return ['success' => false, 'errors' => ['The HMRC CT600 RIM download URL is not trusted.']]; }
        $directory = (new HmrcCtRimCatalogueService())->cacheDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new \RuntimeException('The HMRC CT600 RIM cache directory could not be created.'); }
        $temporary = tempnam(sys_get_temp_dir(), 'hmrc_ct_rim_');
        if ($temporary === false) { throw new \RuntimeException('A temporary HMRC CT600 RIM download file could not be created.'); }
        try {
            $this->downloadFile($url, $temporary);
            $zipService = new HmrcCtRimZipService();
            $xsdCount = $zipService->countXsdFiles($temporary);
            if ($xsdCount < 1) { throw new \RuntimeException('The downloaded HMRC CT600 RIM archive does not contain an XSD validation file.'); }
            $sha256 = hash_file('sha256', $temporary); $filename = 'ct600-' . strtolower((string)$row['form_version']) . '-artefacts-' . strtolower((string)$row['artifact_version']) . '.zip'; $path = $directory . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
            if (is_file($path)) { @unlink($path); }
            if (!rename($temporary, $path)) { throw new \RuntimeException('The verified HMRC CT600 RIM file could not be stored.'); }
            $zipService->extract($path, $zipService->extractionDirectory($path));
            $schemaService = new HmrcCtRimSchemaService();
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET local_path = :local_path, sha256 = :sha256, xsd_count = :xsd_count, package_state = \'verified\', verification_error = NULL, checked_at = :checked_at WHERE id = :id', ['local_path' => $path, 'sha256' => $sha256, 'xsd_count' => $xsdCount, 'checked_at' => gmdate('Y-m-d H:i:s'), 'id' => $packageId]);
            $analysis = $schemaService->applyApplicability($packageId, $zipService->extractionDirectory($path), (string)$row['form_version']);
            if (in_array((string)($analysis['status'] ?? ''), ['failed', 'ambiguous'], true)) {
                throw new \RuntimeException((string)($analysis['error'] ?? 'The HMRC CT600 applicability could not be determined.'));
            }
            $schemaService->recalculateWindows();
            return ['success' => true, 'filename' => basename($path), 'path' => $path, 'sha256' => $sha256, 'xsd_count' => $xsdCount];
        } catch (\Throwable $exception) {
            @unlink($temporary); \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET package_state = \'failed\', verification_error = :error, checked_at = :checked_at WHERE id = :id', ['error' => $exception->getMessage(), 'checked_at' => gmdate('Y-m-d H:i:s'), 'id' => $packageId]);
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function downloadFile(string $url, string $path): void
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('PHP cURL is required to download HMRC CT600 RIM artefacts.'); }
        $handle = curl_init($url); $file = fopen($path, 'wb');
        if ($handle === false || $file === false) { throw new \RuntimeException('The HMRC CT600 RIM download could not be started.'); }
        curl_setopt_array($handle, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_USERAGENT => 'EEL Accounts HMRC CT600 RIM download']);
        curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle); fclose($file);
        if ($status < 200 || $status >= 300 || $error !== '') { throw new \RuntimeException('The HMRC CT600 RIM download failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.'); }
    }

}
