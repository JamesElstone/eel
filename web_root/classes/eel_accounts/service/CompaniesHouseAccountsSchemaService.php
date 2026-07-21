<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class CompaniesHouseAccountsSchemaService implements CompaniesHouseSchemaCurrentnessInterface
{
    public const SOURCE_URL = 'https://xmlgw.companieshouse.gov.uk/SchemaStatus';
    public const PROFILE_NAME = 'revised_accounts';
    /** @var array<string,string> */
    private const ROOTS = [
        'envelope' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/Egov_ch-v2-0.xsd',
        'form_submission' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/FormSubmission-v2-11.xsd',
        'submission_status' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetSubmissionStatus-v2-9.xsd',
        'status_ack' => 'https://xmlgw.companieshouse.gov.uk/v1-0/schema/forms/GetStatusAck-v1-1.xsd',
    ];
    private const MAX_FILES = 500;
    private const MAX_FILE_BYTES = 5242880;
    private const MAX_TOTAL_BYTES = 104857600;

    private ?\Closure $fetcher;
    private string $cacheDirectory;

    public function __construct(?callable $fetcher = null, ?string $cacheDirectory = null)
    {
        $this->fetcher = $fetcher === null ? null : \Closure::fromCallable($fetcher);
        $this->cacheDirectory = rtrim($cacheDirectory ?? dirname(__DIR__, 4) . '/third_party/companies_house/schema', '/\\');
    }

    public function fetchStatus(): array
    {
        $snapshot = \InterfaceDB::fetchOne(
            'SELECT * FROM companies_house_schema_snapshots WHERE profile_name = :profile AND is_active = 1 ORDER BY id DESC LIMIT 1',
            ['profile' => self::PROFILE_NAME]
        );
        $catalogue = \InterfaceDB::fetchAll(
            'SELECT schema_name, source_url, lifecycle_status, release_date, live_date, deprecated_date, retirement_date, last_seen_at FROM companies_house_schema_catalogue ORDER BY schema_name'
        );
        return ['snapshot' => is_array($snapshot) ? $snapshot : null, 'catalogue' => $catalogue, 'roots' => self::ROOTS];
    }

    public function ensureCurrent(mixed $progress = null): array
    {
        $this->progress($progress, 'Checking the Companies House XML schema catalogue.', 5);
        $this->ensureDirectory($this->cacheDirectory);
        $lock = fopen($this->cacheDirectory . '/.refresh.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('The Companies House schema refresh lock could not be acquired.');
        }
        try {
            return $this->refreshLocked($progress);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function refreshLocked(mixed $progress): array
    {
        $statusResponse = $this->fetch(self::SOURCE_URL);
        $catalogue = $this->parseCatalogue($statusResponse['body']);
        $this->persistCatalogue($catalogue);
        foreach (self::ROOTS as $key => $url) {
            if ($key === 'envelope') {
                continue; // The envelope schema is not consistently listed on SchemaStatus.
            }
            $entry = $catalogue[$this->canonicalUrl($url)] ?? null;
            if (!is_array($entry) || ($entry['lifecycle_status'] ?? '') !== 'live') {
                throw new \RuntimeException('Companies House marks the pinned ' . basename($url) . ' schema as unavailable for LIVE use. A software update is required.');
            }
        }

        $staging = $this->cacheDirectory . '/.staging/' . bin2hex(random_bytes(12));
        $this->ensureDirectory($staging);
        try {
            [$files, $edges] = $this->downloadClosure($staging, $catalogue, $progress);
            $catalogueHash = hash('sha256', $this->canonicalJson(array_values($catalogue)));
            $manifestData = ['profile' => self::PROFILE_NAME, 'roots' => self::ROOTS, 'files' => $files, 'dependencies' => $edges];
            $manifest = hash('sha256', $this->canonicalJson($manifestData));
            $active = \InterfaceDB::fetchOne(
                'SELECT * FROM companies_house_schema_snapshots WHERE profile_name = :profile AND is_active = 1 ORDER BY id DESC LIMIT 1',
                ['profile' => self::PROFILE_NAME]
            );
            if (is_array($active) && hash_equals((string)$active['manifest_sha256'], $manifest)) {
                \InterfaceDB::prepareExecute(
                    'UPDATE companies_house_schema_snapshots SET checked_at = :checked, catalogue_sha256 = :catalogue WHERE id = :id',
                    ['checked' => gmdate('Y-m-d H:i:s'), 'catalogue' => $catalogueHash, 'id' => (int)$active['id']]
                );
                $this->removeTree($staging);
                $this->progress($progress, 'Companies House XML schemas are current.', 70);
                return ['success' => true, 'changed' => false, 'snapshot_id' => (int)$active['id'], 'manifest_sha256' => $manifest, 'local_path' => (string)$active['local_path']];
            }

            $snapshotPath = $this->cacheDirectory . '/snapshots/' . $manifest;
            $this->ensureDirectory(dirname($snapshotPath));
            if (is_dir($snapshotPath)) {
                $this->removeTree($staging);
            } elseif (!rename($staging, $snapshotPath)) {
                throw new \RuntimeException('The verified Companies House schema snapshot could not be activated atomically.');
            }
            $snapshotId = $this->persistSnapshot($manifest, $catalogueHash, $snapshotPath, $files, $edges);
            $this->progress($progress, 'Companies House XML schema snapshot verified and activated.', 70);
            return ['success' => true, 'changed' => true, 'snapshot_id' => $snapshotId, 'manifest_sha256' => $manifest, 'local_path' => $snapshotPath];
        } catch (\Throwable $exception) {
            if (is_dir($staging)) {
                $this->removeTree($staging);
            }
            throw $exception;
        }
    }

    /** @return array{0:array<string,array<string,mixed>>,1:list<array<string,string>>} */
    private function downloadClosure(string $staging, array $catalogue, mixed $progress): array
    {
        $queue = [];
        foreach (self::ROOTS as $key => $url) {
            $queue[] = [$this->canonicalUrl($url), $key === 'envelope' ? 'envelope' : 'profile_root'];
        }
        $files = [];
        $edges = [];
        $total = 0;
        while ($queue !== []) {
            [$url, $role] = array_shift($queue);
            if (isset($files[$url])) {
                if ($role !== 'dependency') {
                    $files[$url]['file_role'] = $role;
                }
                continue;
            }
            if (count($files) >= self::MAX_FILES) {
                throw new \RuntimeException('Companies House schema dependency limit exceeded.');
            }
            $response = $this->fetch($url);
            $body = $response['body'];
            $size = strlen($body);
            $total += $size;
            if ($size === 0 || $size > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('A Companies House schema exceeded the configured download limit.');
            }
            $document = $this->loadXml($body, $url);
            $relativePath = $this->relativePath($url);
            $target = $staging . '/' . $relativePath;
            $this->ensureDirectory(dirname($target));
            if (file_put_contents($target, $body, LOCK_EX) !== $size) {
                throw new \RuntimeException('A Companies House schema could not be written to staging.');
            }
            $root = $document->documentElement;
            $canonical = $this->canonicalUrl($url);
            $files[$canonical] = [
                'source_url' => $canonical,
                'relative_path' => $relativePath,
                'schema_name' => basename((string)parse_url($url, PHP_URL_PATH)),
                'file_role' => $role,
                'catalogue_status' => $catalogue[$canonical]['lifecycle_status'] ?? null,
                'target_namespace' => $root?->getAttribute('targetNamespace') ?: null,
                'file_size' => $size,
                'sha256' => hash('sha256', $body),
                'etag' => $response['headers']['etag'] ?? null,
                'last_modified' => $response['headers']['last-modified'] ?? null,
            ];
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
            foreach ($xpath->query('/xs:schema/xs:include | /xs:schema/xs:import | /xs:schema/xs:redefine') ?: [] as $node) {
                if (!$node instanceof \DOMElement) { continue; }
                $location = trim($node->getAttribute('schemaLocation'));
                if ($location === '') { continue; }
                $child = $this->resolveUrl($url, $location);
                $edges[] = ['parent_url' => $canonical, 'child_url' => $child, 'relation_type' => $node->localName, 'declared_namespace' => $node->getAttribute('namespace'), 'schema_location' => $location];
                $queue[] = [$child, 'dependency'];
            }
            $this->progress($progress, 'Downloaded ' . count($files) . ' Companies House schema file(s).', min(65, 10 + count($files)));
        }
        ksort($files);
        usort($edges, static fn(array $a, array $b): int => strcmp(implode('|', $a), implode('|', $b)));
        return [$files, $edges];
    }

    private function persistSnapshot(string $manifest, string $catalogueHash, string $path, array $files, array $edges): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return (int)\InterfaceDB::transaction(function () use ($manifest, $catalogueHash, $path, $files, $edges, $now): int {
            \InterfaceDB::prepareExecute('UPDATE companies_house_schema_snapshots SET is_active = 0 WHERE profile_name = :profile', ['profile' => self::PROFILE_NAME]);
            $existing = \InterfaceDB::fetchOne('SELECT id FROM companies_house_schema_snapshots WHERE manifest_sha256 = :manifest', ['manifest'=>$manifest]);
            $params = ['manifest'=>$manifest,'catalogue'=>$catalogueHash,'path'=>$path,'profile'=>self::PROFILE_NAME,'roots'=>count(self::ROOTS),'dependencies'=>count($edges),'files'=>count($files),'checked'=>$now,'verified'=>$now];
            if (is_array($existing)) {
                \InterfaceDB::prepareExecute('UPDATE companies_house_schema_snapshots SET catalogue_sha256=:catalogue,local_path=:path,is_active=1,profile_name=:profile,root_count=:roots,dependency_count=:dependencies,file_count=:files,checked_at=:checked,verified_at=:verified WHERE manifest_sha256=:manifest', $params);
            } else {
                \InterfaceDB::prepareExecute('INSERT INTO companies_house_schema_snapshots (manifest_sha256,catalogue_sha256,local_path,is_active,profile_name,root_count,dependency_count,file_count,checked_at,verified_at) VALUES (:manifest,:catalogue,:path,1,:profile,:roots,:dependencies,:files,:checked,:verified)', $params);
            }
            $row = \InterfaceDB::fetchOne('SELECT id FROM companies_house_schema_snapshots WHERE manifest_sha256 = :manifest', ['manifest'=>$manifest]);
            $id = (int)($row['id'] ?? 0);
            \InterfaceDB::prepareExecute('DELETE FROM companies_house_schema_files WHERE snapshot_id = :id', ['id'=>$id]);
            $ids = [];
            foreach ($files as $url => $file) {
                \InterfaceDB::prepareExecute('INSERT INTO companies_house_schema_files (snapshot_id,source_url,relative_path,schema_name,file_role,catalogue_status,target_namespace,file_size,sha256,etag,last_modified) VALUES (:snapshot,:url,:path,:name,:role,:status,:namespace,:size,:sha,:etag,:modified)', ['snapshot'=>$id,'url'=>$url,'path'=>$file['relative_path'],'name'=>$file['schema_name'],'role'=>$file['file_role'],'status'=>$file['catalogue_status'],'namespace'=>$file['target_namespace'],'size'=>$file['file_size'],'sha'=>$file['sha256'],'etag'=>$file['etag'],'modified'=>$file['last_modified']]);
                $ids[$url] = $this->lastInsertId();
            }
            foreach ($edges as $edge) {
                \InterfaceDB::prepareExecute('INSERT INTO companies_house_schema_dependencies (snapshot_id,parent_file_id,child_file_id,relation_type,declared_namespace,schema_location) VALUES (:snapshot,:parent,:child,:relation,:namespace,:location)', ['snapshot'=>$id,'parent'=>$ids[$edge['parent_url']],'child'=>$ids[$edge['child_url']],'relation'=>$edge['relation_type'],'namespace'=>$edge['declared_namespace'] ?: null,'location'=>$edge['schema_location']]);
            }
            return $id;
        });
    }

    private function parseCatalogue(string $html): array
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { $ok = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok) { throw new \RuntimeException('Companies House SchemaStatus could not be parsed.'); }
        $result = [];
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//tr[.//a[@href]]') ?: [] as $row) {
            if (!$row instanceof \DOMElement) { continue; }
            $link = null;
            foreach ($xpath->query('.//a[@href]', $row) ?: [] as $candidate) {
                if ($candidate instanceof \DOMElement && str_contains(strtolower($candidate->getAttribute('href')), '.xsd')) { $link = $candidate; break; }
            }
            if (!$link instanceof \DOMElement) { continue; }
            $url = $this->resolveUrl(self::SOURCE_URL, $link->getAttribute('href'));
            $cellTexts = [];
            foreach ($xpath->query('./th | ./td', $row) ?: [] as $cell) {
                $cellTexts[] = strtolower(preg_replace('/\s+/', ' ', trim($cell->textContent)) ?? '');
            }
            $text = implode(' ', $cellTexts);
            $status = null;
            foreach (['retired','deprecated','live','released'] as $candidate) {
                if (in_array($candidate, $cellTexts, true) || preg_match('/\b' . $candidate . '\b/', $text)) { $status = $candidate; break; }
            }
            if ($status === null) { continue; }
            preg_match_all('/\b([0-3]?\d)[\/\-]([01]?\d)[\/\-](20\d{2})\b/', $text, $dates);
            $normalisedDates = [];
            foreach ($dates[0] ?? [] as $date) { $normalisedDates[] = $this->normaliseDate($date); }
            $canonical = $this->canonicalUrl($url);
            $result[$canonical] = ['schema_name'=>basename((string)parse_url($url, PHP_URL_PATH)),'source_url'=>$canonical,'lifecycle_status'=>$status,'release_date'=>$normalisedDates[0] ?? null,'live_date'=>$normalisedDates[1] ?? null,'deprecated_date'=>$normalisedDates[2] ?? null,'retirement_date'=>$normalisedDates[3] ?? null];
        }
        if ($result === []) { throw new \RuntimeException('Companies House SchemaStatus contained no recognised schema lifecycle rows.'); }
        ksort($result);
        return $result;
    }

    private function persistCatalogue(array $catalogue): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach ($catalogue as $entry) {
            $params = ['name'=>$entry['schema_name'],'url'=>$entry['source_url'],'status'=>$entry['lifecycle_status'],'released'=>$entry['release_date'],'live'=>$entry['live_date'],'deprecated'=>$entry['deprecated_date'],'retired'=>$entry['retirement_date'],'seen'=>$now];
            $existing = \InterfaceDB::fetchOne('SELECT id FROM companies_house_schema_catalogue WHERE source_url = :url', ['url'=>$entry['source_url']]);
            if (is_array($existing)) {
                \InterfaceDB::prepareExecute('UPDATE companies_house_schema_catalogue SET schema_name=:name,lifecycle_status=:status,release_date=:released,live_date=:live,deprecated_date=:deprecated,retirement_date=:retired,last_seen_at=:seen WHERE source_url=:url', $params);
            } else {
                \InterfaceDB::prepareExecute('INSERT INTO companies_house_schema_catalogue (schema_name,source_url,lifecycle_status,release_date,live_date,deprecated_date,retirement_date,last_seen_at) VALUES (:name,:url,:status,:released,:live,:deprecated,:retired,:seen)', $params);
            }
        }
    }

    private function fetch(string $url): array
    {
        $url = $this->canonicalUrl($url);
        $response = $this->fetcher instanceof \Closure ? ($this->fetcher)($url) : $this->curlFetch($url);
        if (!is_array($response) || (int)($response['status_code'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) {
            throw new \RuntimeException('Companies House schema download failed for ' . basename((string)parse_url($url, PHP_URL_PATH)) . '.');
        }
        $final = $this->canonicalUrl((string)($response['final_url'] ?? $url));
        if ($final !== $url) { throw new \RuntimeException('Companies House schema downloads may not redirect.'); }
        $headers = [];
        foreach ((array)($response['headers'] ?? []) as $name => $value) { $headers[strtolower((string)$name)] = trim((string)$value); }
        return ['body'=>$response['body'],'headers'=>$headers];
    }

    private function curlFetch(string $url): array
    {
        $headers = [];
        $handle = curl_init($url);
        if ($handle === false) { throw new \RuntimeException('Companies House schema download could not be initialised.'); }
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'eel_accounts Companies House schema verifier',CURLOPT_HEADERFUNCTION=>static function ($curl, string $line) use (&$headers): int { $parts=explode(':',$line,2); if(count($parts)===2){$headers[trim($parts[0])]=trim($parts[1]);} return strlen($line); }]);
        $body = curl_exec($handle); $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE); $final=(string)curl_getinfo($handle,CURLINFO_EFFECTIVE_URL); $error=curl_error($handle); curl_close($handle);
        if (!is_string($body)) { throw new \RuntimeException('Companies House schema download failed: ' . $error); }
        return ['status_code'=>$status,'headers'=>$headers,'body'=>$body,'final_url'=>$final];
    }

    private function loadXml(string $xml, string $url): \DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) { throw new \RuntimeException('Unsafe XML declaration in Companies House schema.'); }
        $document = new \DOMDocument(); $previous=libxml_use_internal_errors(true);
        try { $ok=$document->loadXML($xml, LIBXML_NONET); $errors=libxml_get_errors(); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$ok || $document->documentElement?->namespaceURI !== 'http://www.w3.org/2001/XMLSchema') { throw new \RuntimeException('Invalid Companies House XSD: ' . basename((string)parse_url($url, PHP_URL_PATH)) . '.'); }
        return $document;
    }

    private function canonicalUrl(string $url): string
    {
        $parts=parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['host'] ?? '')) !== 'xmlgw.companieshouse.gov.uk' || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)) { throw new \RuntimeException('Companies House schema URL is outside the approved host.'); }
        $path=(string)($parts['path'] ?? ''); if ($path === '' || str_contains($path, '..')) { throw new \RuntimeException('Companies House schema URL has an unsafe path.'); }
        return 'https://xmlgw.companieshouse.gov.uk' . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^https?://#i', $relative)) { return $this->canonicalUrl($relative); }
        $basePath=(string)parse_url($base,PHP_URL_PATH); $path=str_starts_with($relative,'/') ? $relative : dirname($basePath) . '/' . $relative;
        $parts=[]; foreach(explode('/',$path) as $part){if($part===''||$part==='.')continue;if($part==='..'){array_pop($parts);continue;}$parts[]=$part;}
        return $this->canonicalUrl('https://xmlgw.companieshouse.gov.uk/' . implode('/',$parts));
    }

    private function relativePath(string $url): string { return ltrim((string)parse_url($url, PHP_URL_PATH), '/'); }
    private function normaliseDate(string $date): ?string { $value=\DateTimeImmutable::createFromFormat('!j/n/Y',str_replace('-','/',$date)); return $value?->format('Y-m-d') ?: null; }
    private function canonicalJson(array $value): string { $json=json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); if(!is_string($json)){throw new \RuntimeException('Companies House schema manifest could not be encoded.');} return $json; }
    private function lastInsertId(): int { return (int)(\InterfaceDB::fetchColumn(strtolower(\InterfaceDB::driverName())==='sqlite'?'SELECT last_insert_rowid()':'SELECT LAST_INSERT_ID()') ?: 0); }
    private function progress(mixed $progress,string $message,int $percent): void { if($progress instanceof \ActionProgressFramework){$progress->report($message,$percent);return;} if(is_callable($progress)){$progress($message,$percent);} }
    private function ensureDirectory(string $path): void { if(!is_dir($path)&&!mkdir($path,0770,true)&&!is_dir($path)){throw new \RuntimeException('Companies House schema cache directory could not be created.');} }
    private function removeTree(string $path): void { if(!is_dir($path)){return;} foreach(new \FilesystemIterator($path) as $item){$item->isDir()&&!$item->isLink()?$this->removeTree($item->getPathname()):unlink($item->getPathname());} rmdir($path); }
}
