<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimCatalogueService
{
    public const SOURCE_URL = 'https://www.gov.uk/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts';
    public const CONTENT_API_URL = 'https://www.gov.uk/api/content/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts';
    private const CACHE_DIRECTORY = 'third_party' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'ct600-rim';

    /** @var null|callable(string): mixed */
    private $fetcher;
    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher;
    }

    public function fetchPackages(): array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_rim_packages')) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT * FROM hmrc_ct_rim_packages ORDER BY applicable_from ASC, form_version ASC, artifact_version DESC'
        );
    }

    public function refresh(): array
    {
        $document = $this->decode($this->fetch(self::CONTENT_API_URL));
        if ((string)($document['base_path'] ?? '') !== '/government/publications/corporation-tax-technical-specifications-ct600-rim-artefacts') {
            throw new \RuntimeException('The HMRC CT600 RIM response was not the expected GOV.UK publication.');
        }

        $sourceUpdatedAt = $this->timestamp($document['public_updated_at'] ?? $document['updated_at'] ?? null);
        $checkedAt = gmdate('Y-m-d H:i:s');
        $changeHistory = array_values(array_filter((array)($document['details']['change_history'] ?? $document['change_history'] ?? []), 'is_array'));
        $latestNote = (string)($changeHistory[0]['note'] ?? '');
        $latestChangeAt = $this->timestamp($changeHistory[0]['public_timestamp'] ?? null);
        $attachmentRows = $this->attachments($document);
        $updated = 0;

        foreach ($attachmentRows as $attachment) {
            $formVersion = (string)$attachment['form_version'];
            $artifactVersion = (string)$attachment['artifact_version'];
            $liveFrom = $latestChangeAt !== null && strtolower((string)$attachment['status']) === 'live' ? $latestChangeAt : null;
            $existing = \InterfaceDB::fetchOne(
                'SELECT id, local_path, sha256, package_state, applicable_from, applicable_to FROM hmrc_ct_rim_packages WHERE form_version = :form_version AND artifact_version = :artifact_version LIMIT 1',
                ['form_version' => $formVersion, 'artifact_version' => $artifactVersion]
            );
            $state = is_array($existing) ? (string)($existing['package_state'] ?? 'not_downloaded') : 'not_downloaded';
            if (is_array($existing) && (string)($existing['sha256'] ?? '') !== '' && (string)($existing['package_state'] ?? '') === 'verified') {
                $state = 'stale';
            }

            $sql = 'INSERT INTO hmrc_ct_rim_packages
                (form_version, artifact_version, applicable_from, applicable_to, published_at, live_from, hmrc_status, source_url, download_url, local_path, source_updated_at, checked_at, latest_change_note, package_state)
                VALUES (:form_version, :artifact_version, :applicable_from, :applicable_to, :published_at, :live_from, :hmrc_status, :source_url, :download_url, :local_path, :source_updated_at, :checked_at, :latest_change_note, :package_state)
                ON DUPLICATE KEY UPDATE
                  applicable_from = VALUES(applicable_from), applicable_to = VALUES(applicable_to), live_from = COALESCE(VALUES(live_from), live_from), hmrc_status = VALUES(hmrc_status), source_url = VALUES(source_url), download_url = VALUES(download_url), source_updated_at = VALUES(source_updated_at), checked_at = VALUES(checked_at), latest_change_note = VALUES(latest_change_note), package_state = CASE WHEN package_state = \'verified\' THEN \'stale\' ELSE VALUES(package_state) END';
            \InterfaceDB::prepareExecute($sql, [
                'form_version' => $formVersion,
                'artifact_version' => $artifactVersion,
                'applicable_from' => is_array($existing) ? ($existing['applicable_from'] ?? null) : null,
                'applicable_to' => is_array($existing) ? ($existing['applicable_to'] ?? null) : null,
                'published_at' => $sourceUpdatedAt,
                'live_from' => $liveFrom,
                'hmrc_status' => (string)$attachment['status'],
                'source_url' => self::SOURCE_URL,
                'download_url' => (string)$attachment['url'],
                'local_path' => is_array($existing) ? ($existing['local_path'] ?? null) : null,
                'source_updated_at' => $sourceUpdatedAt,
                'checked_at' => $checkedAt,
                'latest_change_note' => $latestNote,
                'package_state' => $state,
            ]);
            $updated++;
        }

        return ['success' => true, 'updated_count' => $updated, 'source_updated_at' => $sourceUpdatedAt, 'checked_at' => $checkedAt, 'latest_change_note' => $latestNote, 'packages' => $this->fetchPackages()];
    }

    public function cacheDirectory(): string
    {
        return PROJECT_ROOT . self::CACHE_DIRECTORY;
    }

    private function attachments(array $document): array
    {
        $items = array_merge((array)($document['details']['documents'] ?? []), (array)($document['details']['attachments'] ?? []), (array)($document['documents'] ?? []), (array)($document['attachments'] ?? []));
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            $title = trim(strip_tags((string)($item['title'] ?? $item['description'] ?? '')));
            $url = trim((string)($item['url'] ?? $item['href'] ?? ''));
            if ($url === '' || !str_starts_with($url, 'https://assets.publishing.service.gov.uk/')) { continue; }
            if (preg_match('/CT600\s+V([0-9]+)\b.*?Artefacts\s+V([0-9.]+)/i', $title, $match) !== 1) { continue; }
            $rows[] = ['form_version' => 'V' . $match[1], 'artifact_version' => 'V' . $match[2], 'url' => $url, 'status' => stripos($title, 'live') !== false ? 'live' : 'published'];
        }
        return $rows;
    }

    private function fetch(string $url): mixed
    {
        if ($this->fetcher !== null) { return ($this->fetcher)($url); }
        if (!extension_loaded('curl')) { throw new \RuntimeException('PHP cURL is required to refresh HMRC CT600 RIM metadata.'); }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'EEL Accounts HMRC CT600 RIM refresh', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($handle); $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $error = curl_error($handle); curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) { throw new \RuntimeException('HMRC CT600 RIM metadata refresh failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.'); }
        return $body;
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) { return $value; }
        try { $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR); } catch (\JsonException $exception) { throw new \RuntimeException('HMRC CT600 RIM metadata was not valid JSON.', 0, $exception); }
        return is_array($decoded) ? $decoded : [];
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string)$value); if ($value === '') { return null; }
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (\Throwable) { return null; }
    }
}
