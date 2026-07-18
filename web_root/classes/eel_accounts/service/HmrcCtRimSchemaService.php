<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimSchemaService
{
    private const APPLICABILITY_XPATH = 'CompanyTaxReturn/CompanyInformation/PeriodCovered/From/minInclusive/@value';

    public function catalogueValidationFiles(int $packageId, string $directory): array
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_rim_files')) { return []; }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $type = strtolower($file->getExtension());
            if (!$file->isFile() || !in_array($type, ['xsd', 'sch', 'xslt'], true)) { continue; }
            $path = $file->getPathname();
            $archivePath = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($path, strlen(rtrim($directory, '\\/'))), '\\/'));
            $role = $type === 'sch' ? 'schematron' : ($type === 'xslt' ? 'transform' : (stripos($archivePath, 'envelope') !== false ? 'envelope_schema' : null));
            \InterfaceDB::prepareExecute('INSERT INTO hmrc_ct_rim_files (package_id, archive_path, extracted_path, file_type, file_size, sha256, file_role) VALUES (:package_id, :archive_path, :extracted_path, :file_type, :file_size, :sha256, :file_role) ON DUPLICATE KEY UPDATE extracted_path = VALUES(extracted_path), file_type = VALUES(file_type), file_size = VALUES(file_size), sha256 = VALUES(sha256), file_role = COALESCE(VALUES(file_role), file_role)', [
                'package_id' => $packageId,
                'archive_path' => $archivePath,
                'extracted_path' => $path,
                'file_type' => $type,
                'file_size' => (int)$file->getSize(),
                'sha256' => (string)(hash_file('sha256', $path) ?: ''),
                'file_role' => $role,
            ]);
        }
        return \InterfaceDB::fetchAll('SELECT * FROM hmrc_ct_rim_files WHERE package_id = :package_id ORDER BY archive_path ASC', ['package_id' => $packageId]);
    }

    public function analyseApplicability(int $packageId, string $directory, string $formVersion): array
    {
        $files = $this->catalogueValidationFiles($packageId, $directory);
        $candidates = [];
        foreach ($files as $file) {
            if ((string)($file['file_type'] ?? '') !== 'xsd' || preg_match('~(^|[\\/])(diffs?|old|previous)([\\/]|$)~i', (string)$file['archive_path']) === 1) { continue; }
            $xml = @simplexml_load_file((string)$file['extracted_path']);
            if (!$xml instanceof \SimpleXMLElement || !$xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]')) { continue; }
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_files SET file_role = \'primary_schema\' WHERE id = :id', ['id' => (int)$file['id']]);
            $dates = $xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]//*[local-name()="element" and @name="CompanyInformation"]//*[local-name()="element" and @name="PeriodCovered"]//*[local-name()="element" and @name="From"]//*[local-name()="minInclusive"]/@value');
            $date = count((array)$dates) === 1 ? trim((string)$dates[0]) : null;
            $candidates[] = ['file_id' => (int)$file['id'], 'date' => $this->isDate($date) ? $date : null, 'score' => stripos(basename((string)$file['archive_path']), 'CT-') === 0 ? 10 : 0];
        }
        if ($candidates === []) {
            return ['status' => 'failed', 'applicable_from' => null, 'source_file_id' => null, 'xpath' => null, 'error' => 'The HMRC CT600 primary XSD was not found.'];
        }
        $dated = array_values(array_filter($candidates, static fn(array $candidate): bool => $candidate['date'] !== null));
        if ($dated === []) {
            $best = $candidates[0];
            return strtoupper(trim($formVersion)) === 'V2'
                ? ['status' => 'open_start', 'applicable_from' => null, 'source_file_id' => $best['file_id'], 'xpath' => null]
                : ['status' => 'failed', 'applicable_from' => null, 'source_file_id' => $best['file_id'], 'xpath' => null, 'error' => 'The HMRC CT600 primary XSD did not expose an applicability date.'];
        }
        usort($dated, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $best = $dated[0];
        foreach ($dated as $candidate) {
            if ($candidate['score'] === $best['score'] && $candidate['date'] !== $dated[0]['date']) {
                return ['status' => 'ambiguous', 'applicable_from' => null, 'source_file_id' => null, 'xpath' => self::APPLICABILITY_XPATH, 'error' => 'The HMRC CT600 XSD applicability date was ambiguous.'];
            }
        }
        return ['status' => 'confirmed', 'applicable_from' => $dated[0]['date'], 'source_file_id' => $dated[0]['file_id'], 'xpath' => self::APPLICABILITY_XPATH];
    }

    public function applyApplicability(int $packageId, string $directory, string $formVersion): array
    {
        $result = $this->analyseApplicability($packageId, $directory, $formVersion);
        \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET applicable_from = :applicable_from, applicability_source_file_id = :source_file_id, applicability_xpath = :xpath, applicability_extracted_at = :extracted_at, applicability_status = :status WHERE id = :id', [
            'applicable_from' => $result['applicable_from'] ?? null,
            'source_file_id' => $result['source_file_id'] ?? null,
            'xpath' => $result['xpath'] ?? null,
            'extracted_at' => gmdate('Y-m-d H:i:s'),
            'status' => $result['status'],
            'id' => $packageId,
        ]);
        return $result;
    }

    public function recalculateWindows(): void
    {
        $rows = \InterfaceDB::fetchAll('SELECT form_version, MIN(applicable_from) AS applicable_from FROM hmrc_ct_rim_packages WHERE applicability_status IN (\'confirmed\', \'open_start\') GROUP BY form_version ORDER BY (applicable_from IS NOT NULL) ASC, applicable_from ASC, form_version ASC');
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1]['applicable_from'] ?? null;
            $to = $next !== null ? (new \DateTimeImmutable((string)$next))->modify('-1 day')->format('Y-m-d') : null;
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET applicable_to = :applicable_to WHERE form_version = :form_version', ['applicable_to' => $to, 'form_version' => (string)$row['form_version']]);
        }
    }

    private function isDate(?string $value): bool
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) { return false; }
        try { return (new \DateTimeImmutable($value))->format('Y-m-d') === $value; } catch (\Throwable) { return false; }
    }
}
