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
            $xsdCount = $this->countXsdFiles($temporary);
            if ($xsdCount < 1) { throw new \RuntimeException('The downloaded HMRC CT600 RIM archive does not contain an XSD validation file.'); }
            $sha256 = hash_file('sha256', $temporary); $filename = 'ct600-' . strtolower((string)$row['form_version']) . '-artefacts-' . strtolower((string)$row['artifact_version']) . '.zip'; $path = $directory . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
            if (is_file($path)) { @unlink($path); }
            if (!rename($temporary, $path)) { throw new \RuntimeException('The verified HMRC CT600 RIM file could not be stored.'); }
            $extractDirectory = $directory . DIRECTORY_SEPARATOR . substr(basename($path), 0, -4);
            $this->extractArchive($path, $extractDirectory);
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET local_path = :local_path, sha256 = :sha256, xsd_count = :xsd_count, package_state = \'verified\', verification_error = NULL, checked_at = :checked_at WHERE id = :id', ['local_path' => $path, 'sha256' => $sha256, 'xsd_count' => $xsdCount, 'checked_at' => gmdate('Y-m-d H:i:s'), 'id' => $packageId]);
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

    private function countXsdFiles(string $path): int
    {
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                throw new \RuntimeException('The downloaded HMRC CT600 RIM file is not a valid ZIP archive.');
            }
            $xsdCount = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_ends_with(strtolower((string)$zip->getNameIndex($index)), '.xsd')) {
                    $xsdCount++;
                }
            }
            $zip->close();
            return $xsdCount;
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('The downloaded HMRC CT600 RIM file is empty or unreadable.');
        }

        $endOffset = strrpos($content, "PK\x05\x06");
        if ($endOffset === false || strlen($content) - $endOffset < 22) {
            throw new \RuntimeException('The downloaded HMRC CT600 RIM file is not a valid ZIP archive.');
        }
        $endRecord = unpack('Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length', substr($content, $endOffset, 22));
        if (!is_array($endRecord) || (int)$endRecord['signature'] !== 0x06054b50 || (int)$endRecord['disk'] !== 0 || (int)$endRecord['central_disk'] !== 0) {
            throw new \RuntimeException('The downloaded HMRC CT600 RIM ZIP directory is malformed.');
        }

        $offset = (int)$endRecord['central_offset'];
        $totalEntries = (int)$endRecord['entries_total'];
        $xsdCount = 0;
        for ($index = 0; $index < $totalEntries; $index++) {
            if ($offset < 0 || $offset + 46 > strlen($content) || substr($content, $offset, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('The downloaded HMRC CT600 RIM ZIP directory is malformed.');
            }
            $header = unpack('Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset', substr($content, $offset, 46));
            if (!is_array($header)) {
                throw new \RuntimeException('The downloaded HMRC CT600 RIM ZIP directory is malformed.');
            }
            $nameLength = (int)$header['name_length'];
            $extraLength = (int)$header['extra_length'];
            $commentLength = (int)$header['comment_length'];
            $nameOffset = $offset + 46;
            $nextOffset = $nameOffset + $nameLength + $extraLength + $commentLength;
            if ($nameLength <= 0 || $nextOffset > strlen($content)) {
                throw new \RuntimeException('The downloaded HMRC CT600 RIM ZIP directory is malformed.');
            }
            if (str_ends_with(strtolower(substr($content, $nameOffset, $nameLength)), '.xsd')) {
                $xsdCount++;
            }
            $offset = $nextOffset;
        }

        return $xsdCount;
    }

    private function extractArchive(string $path, string $directory): void
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('The HMRC CT600 RIM archive could not be read for extraction.');
        }
        $endOffset = strrpos($content, "PK\x05\x06");
        if ($endOffset === false || strlen($content) - $endOffset < 22) {
            throw new \RuntimeException('The HMRC CT600 RIM archive directory is malformed.');
        }
        $endRecord = unpack('Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length', substr($content, $endOffset, 22));
        if (!is_array($endRecord) || (int)$endRecord['signature'] !== 0x06054b50 || (int)$endRecord['disk'] !== 0 || (int)$endRecord['central_disk'] !== 0) {
            throw new \RuntimeException('The HMRC CT600 RIM archive directory is malformed.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The HMRC CT600 RIM extraction directory could not be created.');
        }

        $offset = (int)$endRecord['central_offset'];
        $totalEntries = (int)$endRecord['entries_total'];
        $contentLength = strlen($content);
        for ($index = 0; $index < $totalEntries; $index++) {
            if ($offset < 0 || $offset + 46 > $contentLength || substr($content, $offset, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('The HMRC CT600 RIM archive directory is malformed.');
            }
            $header = unpack('Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset', substr($content, $offset, 46));
            if (!is_array($header)) { throw new \RuntimeException('The HMRC CT600 RIM archive directory is malformed.'); }
            $nameLength = (int)$header['name_length'];
            $extraLength = (int)$header['extra_length'];
            $commentLength = (int)$header['comment_length'];
            $nameOffset = $offset + 46;
            $nextOffset = $nameOffset + $nameLength + $extraLength + $commentLength;
            if ($nameLength <= 0 || $nextOffset > $contentLength) { throw new \RuntimeException('The HMRC CT600 RIM archive directory is malformed.'); }
            $entryName = str_replace('\\', '/', substr($content, $nameOffset, $nameLength));
            if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.?(/|$)#', $entryName) === 1 || preg_match('/^[A-Za-z]:/', $entryName) === 1) {
                throw new \RuntimeException('The HMRC CT600 RIM archive contains an unsafe path.');
            }
            $localOffset = (int)$header['local_offset'];
            if ($localOffset < 0 || $localOffset + 30 > $contentLength || substr($content, $localOffset, 4) !== "PK\x03\x04") {
                throw new \RuntimeException('The HMRC CT600 RIM archive entry is malformed.');
            }
            $localHeader = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length', substr($content, $localOffset, 30));
            if (!is_array($localHeader)) { throw new \RuntimeException('The HMRC CT600 RIM archive entry is malformed.'); }
            $dataOffset = $localOffset + 30 + (int)$localHeader['name_length'] + (int)$localHeader['extra_length'];
            $compressedSize = (int)$header['compressed_size'];
            $uncompressedSize = (int)$header['uncompressed_size'];
            if ($dataOffset < 0 || $compressedSize < 0 || $dataOffset + $compressedSize > $contentLength) { throw new \RuntimeException('The HMRC CT600 RIM archive entry is malformed.'); }
            $entryData = substr($content, $dataOffset, $compressedSize);
            $method = (int)$header['method'];
            $data = $method === 0 ? $entryData : ($method === 8 ? @gzinflate($entryData) : false);
            if (!is_string($data) || strlen($data) !== $uncompressedSize || !hash_equals(sprintf('%08x', (int)$header['crc']), hash('crc32b', $data))) {
                throw new \RuntimeException('The HMRC CT600 RIM archive entry failed verification.');
            }
            $target = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);
            if (str_ends_with($entryName, '/')) {
                if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) { throw new \RuntimeException('The HMRC CT600 RIM archive directory could not be created.'); }
            } else {
                $parent = dirname($target);
                if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) { throw new \RuntimeException('The HMRC CT600 RIM archive directory could not be created.'); }
                if (@file_put_contents($target, $data) === false) { throw new \RuntimeException('The HMRC CT600 RIM archive file could not be extracted.'); }
            }
            $offset = $nextOffset;
        }
    }
}
