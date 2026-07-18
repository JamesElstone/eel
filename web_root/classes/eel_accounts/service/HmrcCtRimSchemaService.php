<?php
declare(strict_types=1);

namespace eel_accounts\Service;

final class HmrcCtRimSchemaService
{
    public function applicableFrom(string $directory, string $formVersion): ?string
    {
        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'xsd' || preg_match('~(^|[\\/])(diffs?|old|previous)([\\/]|$)~i', $file->getPathname()) === 1) { continue; }
            $xml = @simplexml_load_file($file->getPathname());
            if (!$xml instanceof \SimpleXMLElement) { continue; }
            $dates = $xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]//*[local-name()="element" and @name="CompanyInformation"]//*[local-name()="element" and @name="PeriodCovered"]//*[local-name()="element" and @name="From"]//*[local-name()="minInclusive"]/@value');
            if (!is_array($dates) || count($dates) !== 1) { continue; }
            $date = trim((string)$dates[0]);
            if (!$this->isDate($date)) { continue; }
            $score = 0;
            if (stripos($file->getFilename(), 'CT-') === 0) { $score += 10; }
            if ($xml->xpath('//*[local-name()="element" and @name="CompanyTaxReturn"]')) { $score += 10; }
            $candidates[] = ['score' => $score, 'date' => $date, 'path' => $file->getPathname()];
        }
        if ($candidates === []) {
            if (strtoupper(trim($formVersion)) === 'V2') { return null; }
            throw new \RuntimeException('The HMRC CT600 primary XSD did not expose an applicability date.');
        }
        usort($candidates, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if ($candidate['score'] === $best['score'] && $candidate['date'] !== $best['date']) { throw new \RuntimeException('The HMRC CT600 XSD applicability date was ambiguous.'); }
        }
        return $best['date'];
    }

    public function recalculateWindows(): void
    {
        $rows = \InterfaceDB::fetchAll('SELECT form_version, MIN(applicable_from) AS applicable_from FROM hmrc_ct_rim_packages GROUP BY form_version ORDER BY (applicable_from IS NOT NULL) ASC, applicable_from ASC, form_version ASC');
        foreach ($rows as $index => $row) {
            $next = $rows[$index + 1]['applicable_from'] ?? null;
            $to = $next !== null ? (new \DateTimeImmutable((string)$next))->modify('-1 day')->format('Y-m-d') : null;
            \InterfaceDB::prepareExecute('UPDATE hmrc_ct_rim_packages SET applicable_to = :applicable_to WHERE form_version = :form_version', ['applicable_to' => $to, 'form_version' => (string)$row['form_version']]);
        }
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) { return false; }
        try { return (new \DateTimeImmutable($value))->format('Y-m-d') === $value; } catch (\Throwable) { return false; }
    }
}
