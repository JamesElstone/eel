<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimZipService
{
    public function countXsdFiles(string $path): int
    {
        $content = $this->readArchive($path);
        [$endRecord, $contentLength] = $this->endRecord($content);
        $offset = (int)$endRecord['central_offset'];
        $totalEntries = (int)$endRecord['entries_total'];
        $xsdCount = 0;
        for ($index = 0; $index < $totalEntries; $index++) {
            [$header, $entryName, $nextOffset] = $this->centralEntry($content, $offset, $contentLength);
            if (str_ends_with(strtolower($entryName), '.xsd')) { $xsdCount++; }
            $offset = $nextOffset;
        }
        return $xsdCount;
    }

    public function extract(string $path, string $directory): void
    {
        $content = $this->readArchive($path);
        [$endRecord, $contentLength] = $this->endRecord($content);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('The HMRC CT600 RIM extraction directory could not be created.');
        }
        $offset = (int)$endRecord['central_offset'];
        $totalEntries = (int)$endRecord['entries_total'];
        for ($index = 0; $index < $totalEntries; $index++) {
            [$header, $entryName, $nextOffset] = $this->centralEntry($content, $offset, $contentLength);
            $target = $this->safeTarget($directory, $entryName);
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

    public function ensureExtracted(string $path): bool
    {
        if (!is_file($path)) { throw new \RuntimeException('The stored HMRC CT600 RIM ZIP file was not found.'); }
        $directory = $this->extractionDirectory($path);
        $entries = is_dir($directory) ? glob($directory . DIRECTORY_SEPARATOR . '*') : [];
        if (is_array($entries) && $entries !== []) { return false; }
        $this->extract($path, $directory);
        return true;
    }

    public function extractionDirectory(string $path): string
    {
        return dirname($path) . DIRECTORY_SEPARATOR . substr(basename($path), 0, -4);
    }

    private function readArchive(string $path): string
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') { throw new \RuntimeException('The HMRC CT600 RIM archive is empty or unreadable.'); }
        return $content;
    }

    private function endRecord(string $content): array
    {
        $endOffset = strrpos($content, "PK\x05\x06");
        if ($endOffset === false || strlen($content) - $endOffset < 22) { throw new \RuntimeException('The HMRC CT600 RIM archive is not a valid ZIP archive.'); }
        $record = unpack('Vsignature/vdisk/vcentral_disk/ventries_disk/ventries_total/Vcentral_size/Vcentral_offset/vcomment_length', substr($content, $endOffset, 22));
        if (!is_array($record) || (int)$record['signature'] !== 0x06054b50 || (int)$record['disk'] !== 0 || (int)$record['central_disk'] !== 0) { throw new \RuntimeException('The HMRC CT600 RIM ZIP directory is malformed.'); }
        return [$record, strlen($content)];
    }

    private function centralEntry(string $content, int $offset, int $contentLength): array
    {
        if ($offset < 0 || $offset + 46 > $contentLength || substr($content, $offset, 4) !== "PK\x01\x02") { throw new \RuntimeException('The HMRC CT600 RIM ZIP directory is malformed.'); }
        $header = unpack('Vsignature/vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed_size/Vuncompressed_size/vname_length/vextra_length/vcomment_length/vdisk_start/vinternal_attributes/Vexternal_attributes/Vlocal_offset', substr($content, $offset, 46));
        if (!is_array($header)) { throw new \RuntimeException('The HMRC CT600 RIM ZIP directory is malformed.'); }
        $nameLength = (int)$header['name_length']; $extraLength = (int)$header['extra_length']; $commentLength = (int)$header['comment_length']; $nameOffset = $offset + 46; $nextOffset = $nameOffset + $nameLength + $extraLength + $commentLength;
        if ($nameLength <= 0 || $nextOffset > $contentLength) { throw new \RuntimeException('The HMRC CT600 RIM ZIP directory is malformed.'); }
        $entryName = str_replace('\\', '/', substr($content, $nameOffset, $nameLength));
        $this->assertSafeEntryName($entryName);
        return [$header, $entryName, $nextOffset];
    }

    private function safeTarget(string $directory, string $entryName): string
    {
        return rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entryName);
    }

    private function assertSafeEntryName(string $entryName): void
    {
        if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(^|/)\.\.?(/|$)#', $entryName) === 1 || preg_match('/^[A-Za-z]:/', $entryName) === 1) { throw new \RuntimeException('The HMRC CT600 RIM archive contains an unsafe path.'); }
    }
}
