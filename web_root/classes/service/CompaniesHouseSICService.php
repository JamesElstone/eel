<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class CompaniesHouseSICService
{
    private const DEFAULT_SOURCE_URL = 'https://resources.companieshouse.gov.uk/sic/';

    public function __construct(
        private readonly string $sourceUrl = self::DEFAULT_SOURCE_URL,
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function ensureLookupDataAvailable(): array
    {
        if (InterfaceDB::tableRowCount('sic_codes') > 0) {
            return [
                'refreshed' => false,
                'section_count' => InterfaceDB::tableRowCount('sic_section'),
                'sic_code_count' => InterfaceDB::tableRowCount('sic_codes'),
                'source' => 'database',
            ];
        }

        return $this->refreshLookupData();
    }

    public function refreshLookupData(?string $html = null): array
    {
        $html = $html ?? $this->downloadSourceHtml();
        $parsed = $this->parseLookupHtml($html);

        if ($parsed['sections'] === [] || $parsed['codes'] === []) {
            throw new RuntimeException('No SIC sections or codes could be parsed from the Companies House source page.');
        }

        InterfaceDB::beginTransaction();

        try {
            InterfaceDB::prepareExecute('DELETE FROM sic_codes');
            InterfaceDB::prepareExecute('DELETE FROM sic_section');

            $sectionInsert = InterfaceDB::prepare(
                'INSERT INTO sic_section (
                    section_letter,
                    description
                ) VALUES (?, ?)'
            );

            $sectionIdsByLetter = [];

            foreach ($parsed['sections'] as $section) {
                $sectionInsert->execute([
                    (string)$section['section_letter'],
                    (string)$section['description'],
                ]);

                $sectionIdsByLetter[(string)$section['section_letter']] = (int)InterfaceDB::fetchColumn(
                    'SELECT id
                     FROM sic_section
                     WHERE section_letter = ?
                     ORDER BY id DESC
                     LIMIT 1',
                    [(string)$section['section_letter']]
                );
            }

            $codeInsert = InterfaceDB::prepare(
                'INSERT INTO sic_codes (
                    section_id,
                    sic_code,
                    description
                ) VALUES (?, ?, ?)'
            );

            foreach ($parsed['codes'] as $code) {
                $sectionLetter = (string)($code['section_letter'] ?? '');

                if ($sectionLetter === '' || !isset($sectionIdsByLetter[$sectionLetter])) {
                    continue;
                }

                $codeInsert->execute([
                    $sectionIdsByLetter[$sectionLetter],
                    (string)$code['sic_code'],
                    (string)$code['description'],
                ]);
            }

            InterfaceDB::commit();
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return [
            'refreshed' => true,
            'section_count' => count($parsed['sections']),
            'sic_code_count' => count($parsed['codes']),
            'source' => $this->sourceUrl,
        ];
    }

    public function parseLookupHtml(string $html): array
    {
        $html = $this->ensureUtf8($html);

        if (trim($html) === '') {
            throw new RuntimeException('The SIC source HTML is empty.');
        }

        $dom = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($loaded !== true) {
            throw new RuntimeException('The SIC source HTML could not be parsed.');
        }

        $xpath = new DOMXPath($dom);
        $rows = $xpath->query('//table[@id="sic-codes"]/tbody/tr');

        if (!$rows instanceof DOMNodeList || $rows->length === 0) {
            throw new RuntimeException('The SIC codes table was not found in the Companies House source HTML.');
        }

        $sections = [];
        $sectionDescriptionsByLetter = [];
        $codes = [];
        $currentSectionLetter = '';

        /** @var DOMElement $row */
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);

            if (!$cells instanceof DOMNodeList || $cells->length < 2) {
                continue;
            }

            $firstCell = $this->normaliseWhitespace((string)$cells->item(0)?->textContent);
            $secondCell = $this->normaliseWhitespace((string)$cells->item(1)?->textContent);

            if ($firstCell === '' || $secondCell === '') {
                continue;
            }

            if (preg_match('/^Section\s+([A-Z])$/i', $firstCell, $matches) === 1) {
                $currentSectionLetter = strtoupper((string)$matches[1]);
                $sectionDescription = rtrim($secondCell, ':');

                if (!isset($sectionDescriptionsByLetter[$currentSectionLetter])) {
                    $sectionDescriptionsByLetter[$currentSectionLetter] = $sectionDescription;
                    $sections[] = [
                        'section_letter' => $currentSectionLetter,
                        'description' => $sectionDescription,
                    ];
                }

                continue;
            }

            if ($currentSectionLetter === '') {
                continue;
            }

            if (preg_match('/^\d{4,5}$/', $firstCell) !== 1) {
                continue;
            }

            $codes[] = [
                'section_letter' => $currentSectionLetter,
                'sic_code' => $firstCell,
                'description' => $secondCell,
            ];
        }

        return [
            'sections' => $sections,
            'codes' => $codes,
        ];
    }

    public function extractSicCodesFromProfileJson(?string $profileJson): array
    {
        $profileJson = trim((string)$profileJson);

        if ($profileJson === '') {
            return [];
        }

        $decoded = json_decode($profileJson, true);
        $sicCodes = is_array($decoded['sic_codes'] ?? null) ? $decoded['sic_codes'] : [];

        $codes = [];

        foreach ($sicCodes as $code) {
            $value = trim((string)$code);

            if ($value !== '' && preg_match('/^\d{4,5}$/', $value) === 1) {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }

    public function fetchResolvedCodes(array $sicCodes): array
    {
        $sicCodes = array_values(array_filter(array_map(
            static fn(mixed $value): string => trim((string)$value),
            $sicCodes
        ), static fn(string $value): bool => $value !== ''));

        if ($sicCodes === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sicCodes), '?'));
        $rows = InterfaceDB::prepareExecute(
            'SELECT
                c.sic_code,
                c.description,
                s.section_letter,
                s.description AS section_description
             FROM sic_codes c
             INNER JOIN sic_section s ON s.id = c.section_id
             WHERE c.sic_code IN (' . $placeholders . ')
             ORDER BY c.sic_code',
            $sicCodes
        )->fetchAll();

        $resolvedByCode = [];

        foreach ($rows as $row) {
            $resolvedByCode[trim((string)($row['sic_code'] ?? ''))] = [
                'sic_code' => trim((string)($row['sic_code'] ?? '')),
                'description' => trim((string)($row['description'] ?? '')),
                'section_letter' => trim((string)($row['section_letter'] ?? '')),
                'section_description' => trim((string)($row['section_description'] ?? '')),
            ];
        }

        $resolved = [];

        foreach ($sicCodes as $sicCode) {
            $resolved[] = $resolvedByCode[$sicCode] ?? [
                'sic_code' => $sicCode,
                'description' => '',
                'section_letter' => '',
                'section_description' => '',
            ];
        }

        return $resolved;
    }

    public function formatResolvedCodesForDisplay(array $resolvedCodes): array
    {
        $lines = [];

        foreach ($resolvedCodes as $row) {
            $sicCode = trim((string)($row['sic_code'] ?? ''));
            $description = trim((string)($row['description'] ?? ''));
            $sectionLetter = trim((string)($row['section_letter'] ?? ''));
            $sectionDescription = trim((string)($row['section_description'] ?? ''));

            if ($sicCode === '') {
                continue;
            }

            $line = $sicCode;

            if ($description !== '') {
                $line .= ' - ' . $description;
            }

            if ($sectionLetter !== '') {
                $line .= ' (Section ' . $sectionLetter;

                if ($sectionDescription !== '') {
                    $line .= ': ' . $sectionDescription;
                }

                $line .= ')';
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function downloadSourceHtml(): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => max(1, $this->timeoutSeconds),
                'header' => "User-Agent: EEL-Accounts/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($this->sourceUrl, false, $context);

        if (!is_string($html) || trim($html) === '') {
            throw new RuntimeException('Unable to download the Companies House SIC reference page.');
        }

        return $html;
    }

    private function normaliseWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function ensureUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }

        return $value;
    }
}
