<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CorporationTaxRateRuleService
{
    public const SOURCE_URL = 'https://www.gov.uk/government/publications/rates-and-allowances-corporation-tax/rates-and-allowances-corporation-tax';

    public function ensureSchema(): void
    {
        if (\InterfaceDB::tableExists('corporation_tax_rate_rules')) {
            return;
        }

        if (\InterfaceDB::driverName() === 'sqlite') {
            \InterfaceDB::execute(
                'CREATE TABLE IF NOT EXISTS corporation_tax_rate_rules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    regime TEXT NOT NULL DEFAULT \'non_ring_fence\',
                    financial_year_start TEXT NOT NULL,
                    financial_year_end TEXT NOT NULL,
                    rule_version TEXT NOT NULL,
                    main_rate REAL NOT NULL,
                    small_profits_rate REAL DEFAULT NULL,
                    lower_limit REAL DEFAULT NULL,
                    upper_limit REAL DEFAULT NULL,
                    marginal_relief_fraction REAL DEFAULT NULL,
                    source_url TEXT NOT NULL,
                    source_updated_at TEXT DEFAULT NULL,
                    source_checked_at TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    notes TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (regime, financial_year_start, rule_version)
                )'
            );
            \InterfaceDB::execute(
                'CREATE INDEX IF NOT EXISTS idx_ct_rate_rules_lookup
                 ON corporation_tax_rate_rules (regime, is_active, financial_year_start, financial_year_end)'
            );
            return;
        }

        \InterfaceDB::execute(
            'CREATE TABLE IF NOT EXISTS corporation_tax_rate_rules (
                id int(11) NOT NULL AUTO_INCREMENT,
                regime varchar(32) NOT NULL DEFAULT \'non_ring_fence\',
                financial_year_start date NOT NULL,
                financial_year_end date NOT NULL,
                rule_version varchar(32) NOT NULL,
                main_rate decimal(8,6) NOT NULL,
                small_profits_rate decimal(8,6) DEFAULT NULL,
                lower_limit decimal(12,2) DEFAULT NULL,
                upper_limit decimal(12,2) DEFAULT NULL,
                marginal_relief_fraction decimal(8,6) DEFAULT NULL,
                source_url varchar(500) NOT NULL,
                source_updated_at date DEFAULT NULL,
                source_checked_at date NOT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                notes text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id),
                UNIQUE KEY uq_ct_rate_rule_version (regime, financial_year_start, rule_version),
                KEY idx_ct_rate_rules_lookup (regime, is_active, financial_year_start, financial_year_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function fetchRules(): array
    {
        $this->ensureSchema();

        return \InterfaceDB::fetchAll(
            'SELECT id,
                    regime,
                    financial_year_start,
                    financial_year_end,
                    rule_version,
                    main_rate,
                    small_profits_rate,
                    lower_limit,
                    upper_limit,
                    marginal_relief_fraction,
                    source_url,
                    source_updated_at,
                    source_checked_at,
                    is_active,
                    notes,
                    updated_at
             FROM corporation_tax_rate_rules
             ORDER BY financial_year_start DESC, is_active DESC, source_checked_at DESC, id DESC'
        );
    }

    public function fetchActiveRuleForFinancialYear(\DateTimeImmutable $financialYearStart): ?array
    {
        $this->ensureSchema();
        $date = $financialYearStart->format('Y-m-d');

        $row = \InterfaceDB::fetchOne(
            'SELECT financial_year_start,
                    financial_year_end,
                    rule_version,
                    main_rate,
                    small_profits_rate,
                    lower_limit,
                    upper_limit,
                    marginal_relief_fraction,
                    source_url,
                    source_updated_at,
                    source_checked_at
             FROM corporation_tax_rate_rules
             WHERE regime = :regime
               AND is_active = 1
               AND financial_year_start <= :financial_year_start
               AND financial_year_end >= :financial_year_start
             ORDER BY source_checked_at DESC, id DESC
             LIMIT 1',
            [
                'regime' => 'non_ring_fence',
                'financial_year_start' => $date,
            ]
        );

        return is_array($row) ? $row : null;
    }

    public function refreshFromHmrc(): array
    {
        $this->ensureSchema();
        $html = $this->fetchSourceHtml();
        $parsed = $this->parseGovUkHtml($html, self::SOURCE_URL, (new \DateTimeImmutable('today'))->format('Y-m-d'));
        $rules = (array)($parsed['rules'] ?? []);
        if ($rules === []) {
            return ['success' => false, 'errors' => ['No Corporation Tax rate rules were parsed from GOV.UK.'], 'warnings' => []];
        }

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            foreach ($rules as $rule) {
                $this->deactivateSupersededRules($rule);
                $this->upsertRule($rule);
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }

            throw $exception;
        }

        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($parsed['warnings'] ?? []),
            'refreshed_count' => count($rules),
            'source_url' => self::SOURCE_URL,
            'source_updated_at' => (string)($parsed['source_updated_at'] ?? ''),
            'source_checked_at' => (string)($parsed['source_checked_at'] ?? ''),
        ];
    }

    public function parseGovUkHtml(string $html, string $sourceUrl = self::SOURCE_URL, ?string $checkedAt = null): array
    {
        $html = trim($html);
        if ($html === '') {
            throw new \RuntimeException('The HMRC rates page returned an empty response.');
        }

        $sourceUpdatedAt = $this->extractSourceUpdatedAt($html);
        $checkedAt = $checkedAt !== null && trim($checkedAt) !== '' ? trim($checkedAt) : (new \DateTimeImmutable('today'))->format('Y-m-d');

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $table = $this->findCorporationTaxRateTable(new \DOMXPath($dom));
        if ($table === null) {
            throw new \RuntimeException('The Corporation Tax rates table could not be found on the GOV.UK page.');
        }

        $grid = $this->tableGrid($table);
        if (count($grid) < 2) {
            throw new \RuntimeException('The Corporation Tax rates table was not readable.');
        }

        $header = $grid[0];
        $years = [];
        for ($index = 1, $count = count($header); $index < $count; $index++) {
            if (preg_match('/\b(20\d{2})\b/', (string)$header[$index], $matches) === 1) {
                $years[$index] = (int)$matches[1];
            }
        }

        if ($years === []) {
            throw new \RuntimeException('No financial years were found in the Corporation Tax rates table.');
        }

        $smallRow = $this->findRow($grid, 'small profits rate');
        $mainUpperRow = $this->findRow($grid, 'main rate (companies with profits over');
        $mainFlatRow = $this->findRow($grid, 'main rate (all profits except ring fence profits)');
        $lowerRow = $this->findRow($grid, 'marginal relief lower limit');
        $upperRow = $this->findRow($grid, 'marginal relief upper limit');
        $fractionRow = $this->findRow($grid, 'standard fraction');

        $rules = [];
        foreach ($years as $columnIndex => $year) {
            $mainRate = $this->parsePercent($mainUpperRow[$columnIndex] ?? null)
                ?? $this->parsePercent($mainFlatRow[$columnIndex] ?? null);
            if ($mainRate === null) {
                continue;
            }

            $smallRate = $this->parsePercent($smallRow[$columnIndex] ?? null);
            $lowerLimit = $this->parseMoney($lowerRow[$columnIndex] ?? null);
            $upperLimit = $this->parseMoney($upperRow[$columnIndex] ?? null);
            $fraction = $this->parseFraction($fractionRow[$columnIndex] ?? null);
            $start = sprintf('%04d-04-01', $year);
            $end = sprintf('%04d-03-31', $year + 1);

            $rules[] = [
                'regime' => 'non_ring_fence',
                'financial_year_start' => $start,
                'financial_year_end' => $end,
                'rule_version' => $this->ruleVersion($start, $end, $mainRate, $smallRate, $lowerLimit, $upperLimit, $fraction),
                'main_rate' => $mainRate,
                'small_profits_rate' => $smallRate,
                'lower_limit' => $lowerLimit,
                'upper_limit' => $upperLimit,
                'marginal_relief_fraction' => $fraction,
                'source_url' => $sourceUrl,
                'source_updated_at' => $sourceUpdatedAt,
                'source_checked_at' => $checkedAt,
                'is_active' => 1,
                'notes' => $smallRate !== null
                    ? 'Parsed from the GOV.UK Corporation Tax rates table for non-ring-fence profits.'
                    : 'Parsed from the GOV.UK Corporation Tax rates table for the pre-small-profits-rate non-ring-fence main rate.',
            ];
        }

        return [
            'rules' => $rules,
            'source_url' => $sourceUrl,
            'source_updated_at' => $sourceUpdatedAt,
            'source_checked_at' => $checkedAt,
            'warnings' => [],
        ];
    }

    private function fetchSourceHtml(): string
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL is required to refresh HMRC rates.');
        }

        $handle = curl_init(self::SOURCE_URL);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialise HMRC rates download.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EEL Accounts HMRC rates refresh',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException('HMRC rates download failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.');
        }

        return $body;
    }

    private function findCorporationTaxRateTable(\DOMXPath $xpath): ?\DOMElement
    {
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }

            $text = strtolower($this->normaliseText($table->textContent));
            if (
                str_contains($text, 'small profits rate')
                && str_contains($text, 'main rate')
                && str_contains($text, 'marginal relief lower limit')
                && str_contains($text, 'standard fraction')
            ) {
                return $table;
            }
        }

        return null;
    }

    private function tableGrid(\DOMElement $table): array
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if (!$cell instanceof \DOMElement || !in_array(strtolower($cell->tagName), ['th', 'td'], true)) {
                    continue;
                }
                $cells[] = $this->normaliseText($cell->textContent);
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function findRow(array $grid, string $needle): array
    {
        $needle = strtolower($needle);
        foreach ($grid as $row) {
            if (str_contains(strtolower((string)($row[0] ?? '')), $needle)) {
                return $row;
            }
        }

        return [];
    }

    private function extractSourceUpdatedAt(string $html): ?string
    {
        if (preg_match('/Updated\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $html, $matches) !== 1) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($matches[1]))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function parsePercent(mixed $value): ?float
    {
        $text = $this->normaliseText((string)$value);
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%/', $text, $matches) !== 1) {
            return null;
        }

        return round(((float)$matches[1]) / 100, 6);
    }

    private function parseMoney(mixed $value): ?float
    {
        $text = $this->normaliseText((string)$value);
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }
        $number = preg_replace('/[^0-9.]/', '', $text);
        if (!is_string($number) || $number === '') {
            return null;
        }

        return round((float)$number, 2);
    }

    private function parseFraction(mixed $value): ?float
    {
        $text = $this->normaliseText((string)$value);
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }
        if (preg_match('/([0-9]+)\s*\/\s*([0-9]+)/', $text, $matches) === 1 && (float)$matches[2] !== 0.0) {
            return round(((float)$matches[1]) / ((float)$matches[2]), 6);
        }

        return is_numeric($text) ? round((float)$text, 6) : null;
    }

    private function ruleVersion(
        string $financialYearStart,
        string $financialYearEnd,
        float $mainRate,
        ?float $smallProfitsRate,
        ?float $lowerLimit,
        ?float $upperLimit,
        ?float $marginalReliefFraction
    ): string {
        $hash = hash('sha256', json_encode([
            'source' => self::SOURCE_URL,
            'regime' => 'non_ring_fence',
            'financial_year_start' => $financialYearStart,
            'financial_year_end' => $financialYearEnd,
            'main_rate' => $mainRate,
            'small_profits_rate' => $smallProfitsRate,
            'lower_limit' => $lowerLimit,
            'upper_limit' => $upperLimit,
            'marginal_relief_fraction' => $marginalReliefFraction,
        ], JSON_UNESCAPED_SLASHES));

        return 'govuk-fy' . substr($financialYearStart, 0, 4) . '-' . substr($hash, 0, 12);
    }

    private function deactivateSupersededRules(array $rule): void
    {
        \InterfaceDB::prepareExecute(
            'UPDATE corporation_tax_rate_rules
             SET is_active = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE regime = :regime
               AND financial_year_start = :financial_year_start
               AND rule_version <> :rule_version',
            [
                'regime' => (string)$rule['regime'],
                'financial_year_start' => (string)$rule['financial_year_start'],
                'rule_version' => (string)$rule['rule_version'],
            ]
        );
    }

    private function upsertRule(array $rule): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO corporation_tax_rate_rules (
                regime,
                financial_year_start,
                financial_year_end,
                rule_version,
                main_rate,
                small_profits_rate,
                lower_limit,
                upper_limit,
                marginal_relief_fraction,
                source_url,
                source_updated_at,
                source_checked_at,
                is_active,
                notes,
                created_at,
                updated_at
             ) VALUES (
                :regime,
                :financial_year_start,
                :financial_year_end,
                :rule_version,
                :main_rate,
                :small_profits_rate,
                :lower_limit,
                :upper_limit,
                :marginal_relief_fraction,
                :source_url,
                :source_updated_at,
                :source_checked_at,
                :is_active,
                :notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )
             ON DUPLICATE KEY UPDATE
                financial_year_end = VALUES(financial_year_end),
                main_rate = VALUES(main_rate),
                small_profits_rate = VALUES(small_profits_rate),
                lower_limit = VALUES(lower_limit),
                upper_limit = VALUES(upper_limit),
                marginal_relief_fraction = VALUES(marginal_relief_fraction),
                source_url = VALUES(source_url),
                source_updated_at = VALUES(source_updated_at),
                source_checked_at = VALUES(source_checked_at),
                is_active = VALUES(is_active),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP',
            [
                'regime' => (string)$rule['regime'],
                'financial_year_start' => (string)$rule['financial_year_start'],
                'financial_year_end' => (string)$rule['financial_year_end'],
                'rule_version' => (string)$rule['rule_version'],
                'main_rate' => (float)$rule['main_rate'],
                'small_profits_rate' => $rule['small_profits_rate'],
                'lower_limit' => $rule['lower_limit'],
                'upper_limit' => $rule['upper_limit'],
                'marginal_relief_fraction' => $rule['marginal_relief_fraction'],
                'source_url' => (string)$rule['source_url'],
                'source_updated_at' => $rule['source_updated_at'],
                'source_checked_at' => (string)$rule['source_checked_at'],
                'is_active' => (int)$rule['is_active'],
                'notes' => (string)$rule['notes'],
            ]
        );
    }

    private function normaliseText(string $value): string
    {
        $value = html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $value = str_replace(
            ["\xc3\x82\xc2\xa3", "\xc2\xa3", "\xc3\x82\xc2\xa0", "\xc2\xa0", "\xe2\x80\x94"],
            ['GBP ', 'GBP ', ' ', ' ', '—'],
            $value
        );
        $value = preg_replace('/\s*\(/u', ' (', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
