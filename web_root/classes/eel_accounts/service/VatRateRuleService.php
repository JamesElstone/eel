<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class VatRateRuleService
{
    public const NOTICE_URL = 'https://www.gov.uk/guidance/vat-guide-notice-700';
    public const NOTICE_CONTENT_API_URL = 'https://www.gov.uk/api/content/guidance/vat-guide-notice-700';
    public const CURRENT_RATES_URL = 'https://www.gov.uk/vat-rates';
    public const CURRENT_RATES_CONTENT_API_URL = 'https://www.gov.uk/api/content/vat-rates';

    private const NOTICE_CONTENT_PATH = '/guidance/vat-guide-notice-700';
    private const CURRENT_RATES_CONTENT_PATH = '/vat-rates';

    /** @var null|\Closure(string): mixed */
    private ?\Closure $fetcher;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? \Closure::fromCallable($fetcher) : null;
    }

    /**
     * The production table is migration-owned. This fallback exists so isolated
     * SQLite service tests can exercise the complete refresh transaction.
     */
    public function ensureSchema(): void
    {
        if ($this->schemaReady()) {
            return;
        }

        if (\InterfaceDB::driverName() !== 'sqlite') {
            throw new \RuntimeException('The vat_rate_rules migration has not been applied.');
        }

        if (\InterfaceDB::tableExists('vat_rate_rules')) {
            \InterfaceDB::execute('DROP TABLE vat_rate_rules');
        }

        \InterfaceDB::execute(
            'CREATE TABLE vat_rate_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rate_type TEXT NOT NULL,
                scope TEXT NOT NULL DEFAULT \'uk\',
                effective_from TEXT NOT NULL,
                effective_to TEXT DEFAULT NULL,
                rate_percentage REAL NOT NULL,
                original_period_text TEXT NOT NULL,
                source_url TEXT NOT NULL,
                source_content_id TEXT NOT NULL,
                source_updated_at TEXT DEFAULT NULL,
                source_checked_at TEXT NOT NULL,
                rule_version TEXT NOT NULL,
                dataset_hash TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (dataset_hash, rate_type, scope, effective_from),
                CHECK (rate_type IN (\'standard\', \'reduced\', \'zero\')),
                CHECK (rate_percentage >= 0 AND rate_percentage <= 100),
                CHECK (effective_to IS NULL OR effective_from <= effective_to)
            )'
        );
        \InterfaceDB::execute(
            'CREATE INDEX idx_vat_rate_rules_lookup
             ON vat_rate_rules (rate_type, scope, is_active, effective_from, effective_to)'
        );
        \InterfaceDB::execute(
            'CREATE INDEX idx_vat_rate_rules_dataset
             ON vat_rate_rules (dataset_hash, is_active)'
        );
    }

    public function fetchRules(): array
    {
        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT id,
                    rate_type,
                    scope,
                    effective_from,
                    effective_to,
                    rate_percentage,
                    original_period_text,
                    source_url,
                    source_content_id,
                    source_updated_at,
                    source_checked_at,
                    rule_version,
                    dataset_hash,
                    is_active,
                    notes,
                    created_at,
                    updated_at
             FROM vat_rate_rules
             ORDER BY is_active DESC,
                      rate_type ASC,
                      effective_from DESC,
                      source_checked_at DESC,
                      id DESC'
        );

        return array_map([$this, 'normaliseStoredRule'], $rows ?: []);
    }

    public function fetchForDateAndScope(string $date, string $rateType, string $scope): array
    {
        $date = $this->normaliseDate($date);
        $rateType = strtolower(trim($rateType));
        $scope = strtolower(trim($scope));

        if ($date === null) {
            return $this->unavailable($rateType, $scope, 'A valid VAT rate date in YYYY-MM-DD format is required.');
        }
        if (!in_array($rateType, ['standard', 'reduced', 'zero'], true)) {
            return $this->unavailable($rateType, $scope, 'The VAT rate type must be standard, reduced or zero.');
        }
        if ($scope === '') {
            return $this->unavailable($rateType, $scope, 'A VAT rate scope is required.');
        }

        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return $this->unavailable(
                $rateType,
                $scope,
                'VAT rate data is unavailable until the downstream database migration has been applied.'
            );
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT id,
                    rate_type,
                    scope,
                    effective_from,
                    effective_to,
                    rate_percentage,
                    original_period_text,
                    source_url,
                    source_content_id,
                    source_updated_at,
                    source_checked_at,
                    rule_version,
                    dataset_hash,
                    is_active,
                    notes,
                    created_at,
                    updated_at
             FROM vat_rate_rules
             WHERE rate_type = :rate_type
               AND scope = :scope
               AND is_active = 1
               AND effective_from <= :rate_date
               AND (effective_to IS NULL OR effective_to >= :rate_date)
             ORDER BY effective_from DESC, source_checked_at DESC, id DESC
             LIMIT 1',
            [
                'rate_type' => $rateType,
                'scope' => $scope,
                'rate_date' => $date,
            ]
        );

        if (!is_array($row)) {
            return $this->unavailable($rateType, $scope, 'No active sourced VAT rate covers the requested date and scope.');
        }

        return ['available' => true, 'message' => ''] + $this->normaliseStoredRule($row);
    }

    public function refreshFromHmrc(): array
    {
        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return $this->failureResult('The VAT rate rules table is not available. Apply the downstream database migration first.');
        }

        try {
            $noticeJson = $this->fetchContent(self::NOTICE_CONTENT_API_URL);
            $currentJson = $this->fetchContent(self::CURRENT_RATES_CONTENT_API_URL);
            $parsed = $this->parseContentApiDocuments(
                $noticeJson,
                $currentJson,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')
            );
            $rules = (array)($parsed['rules'] ?? []);
            if ($rules === []) {
                throw new \RuntimeException('No headline VAT rate rules were parsed from GOV.UK.');
            }

            $datasetHash = (string)$parsed['dataset_hash'];
            $activeHash = (string)(\InterfaceDB::fetchColumn(
                'SELECT dataset_hash
                 FROM vat_rate_rules
                 WHERE is_active = 1
                 ORDER BY id DESC
                 LIMIT 1'
            ) ?: '');

            if ($activeHash !== '' && hash_equals($activeHash, $datasetHash)) {
                return $this->refreshResult($parsed, true, 0, true);
            }

            $savepoint = 'vat_rate_rules_refresh';
            $ownsTransaction = !\InterfaceDB::inTransaction();
            if ($ownsTransaction) {
                \InterfaceDB::beginTransaction();
            } else {
                \InterfaceDB::execute('SAVEPOINT ' . $savepoint);
            }

            try {
                \InterfaceDB::execute(
                    'UPDATE vat_rate_rules
                     SET is_active = 0,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE is_active = 1'
                );

                foreach ($rules as $rule) {
                    $this->storeRule($rule);
                }

                if ($ownsTransaction) {
                    \InterfaceDB::commit();
                } else {
                    \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
                }
            } catch (\Throwable $exception) {
                if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                } elseif (!$ownsTransaction && \InterfaceDB::inTransaction()) {
                    \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
                }

                throw $exception;
            }

            return $this->refreshResult($parsed, true, count($rules), false);
        } catch (\Throwable $exception) {
            return $this->failureResult($exception->getMessage());
        }
    }

    /**
     * Parse and cross-check both complete Content API documents before any
     * database write is attempted.
     */
    public function parseContentApiDocuments(
        string $noticeJson,
        string $currentRatesJson,
        ?string $checkedAt = null
    ): array {
        $notice = $this->decodeContentDocument(
            $noticeJson,
            'VAT Notice 700',
            self::NOTICE_CONTENT_PATH
        );
        $current = $this->decodeContentDocument(
            $currentRatesJson,
            'current VAT rates',
            self::CURRENT_RATES_CONTENT_PATH
        );
        $checkedAt = $this->normaliseCheckedAt($checkedAt);

        $noticeBody = (string)$notice['details']['body'];
        $currentBody = (string)$current['details']['body'];
        $sourceContentId = trim((string)$notice['content_id']);
        $crossCheckContentId = trim((string)$current['content_id']);
        $sourceUpdatedAt = $this->sourceUpdatedAt($notice, 'VAT Notice 700');
        $crossCheckUpdatedAt = $this->sourceUpdatedAt($current, 'current VAT rates');

        $historic = $this->parseHistoricNoticeBody($noticeBody);
        $currentRates = $this->parseCurrentRatesBody($currentBody);

        foreach (['standard', 'reduced', 'zero'] as $rateType) {
            if (!array_key_exists($rateType, $currentRates)) {
                throw new \RuntimeException('The GOV.UK current rates page did not provide the ' . $rateType . ' VAT rate.');
            }

            $latest = null;
            foreach ($historic as $rule) {
                if ((string)$rule['rate_type'] === $rateType && $rule['effective_to'] === null) {
                    $latest = $rule;
                }
            }
            if ($latest === null) {
                throw new \RuntimeException('VAT Notice 700 did not provide a current ' . $rateType . ' VAT rate.');
            }
            if (abs((float)$latest['rate_percentage'] - (float)$currentRates[$rateType]) > 0.000001) {
                throw new \RuntimeException(
                    'The current ' . $rateType . ' VAT rate does not agree between VAT Notice 700 and the GOV.UK VAT rates page.'
                );
            }
        }

        ksort($currentRates);
        $canonical = [];
        foreach ($historic as $rule) {
            $canonical[] = $this->canonicalRateRule($rule);
        }
        usort($canonical, static fn(array $left, array $right): int => ($left['rate_type'] . $left['effective_from']) <=> ($right['rate_type'] . $right['effective_from']));
        $datasetIdentity = [
            'source' => [
                'url' => self::NOTICE_URL,
                'base_path' => self::NOTICE_CONTENT_PATH,
                'content_id' => $sourceContentId,
            ],
            'cross_check' => [
                'url' => self::CURRENT_RATES_URL,
                'base_path' => self::CURRENT_RATES_CONTENT_PATH,
                'content_id' => $crossCheckContentId,
                'current_rates' => $currentRates,
            ],
            'rules' => $canonical,
        ];
        $datasetHash = hash(
            'sha256',
            json_encode(
                $datasetIdentity,
                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            )
        );

        $rules = [];
        foreach ($historic as $rule) {
            $isCurrent = $rule['effective_to'] === null;
            $notes = (string)$rule['notes'];
            if ($isCurrent) {
                $notes .= ' Current value cross-checked against ' . self::CURRENT_RATES_URL
                    . ' (content ' . $crossCheckContentId
                    . ($crossCheckUpdatedAt !== null ? ', updated ' . $crossCheckUpdatedAt : '') . ').';
            }

            $ruleIdentity = $this->canonicalRateRule($rule) + [
                'source_content_id' => $sourceContentId,
                'cross_check_content_id' => $crossCheckContentId,
            ];
            $rules[] = array_merge($rule, [
                'scope' => 'uk',
                'source_url' => self::NOTICE_URL,
                'source_content_id' => $sourceContentId,
                'source_updated_at' => $sourceUpdatedAt,
                'source_checked_at' => $checkedAt,
                'rule_version' => 'govuk-' . substr(hash(
                    'sha256',
                    json_encode(
                        $ruleIdentity,
                        JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
                    )
                ), 0, 24),
                'dataset_hash' => $datasetHash,
                'is_active' => 1,
                'notes' => trim($notes),
            ]);
        }

        return [
            'rules' => $rules,
            'dataset_hash' => $datasetHash,
            'source_url' => self::NOTICE_URL,
            'source_content_id' => $sourceContentId,
            'source_updated_at' => $sourceUpdatedAt,
            'source_checked_at' => $checkedAt,
            'cross_check_url' => self::CURRENT_RATES_URL,
            'cross_check_content_id' => $crossCheckContentId,
            'cross_check_updated_at' => $crossCheckUpdatedAt,
            'warnings' => [],
        ];
    }

    private function parseHistoricNoticeBody(string $html): array
    {
        $dom = $this->dom($html, 'VAT Notice 700');
        $xpath = new \DOMXPath($dom);
        $rulesByType = [];

        foreach (['standard' => 'standard-rate', 'reduced' => 'reduced-rate'] as $rateType => $headingId) {
            $heading = $xpath->query('//*[@id="' . $headingId . '"]')->item(0);
            if (!$heading instanceof \DOMElement) {
                throw new \RuntimeException('The ' . $rateType . ' VAT rate heading was not found in VAT Notice 700.');
            }
            $table = $xpath->query('following-sibling::table[1]', $heading)->item(0);
            if (!$table instanceof \DOMElement) {
                throw new \RuntimeException('The ' . $rateType . ' VAT rate history table was not found in VAT Notice 700.');
            }

            $parsedRows = [];
            foreach ($table->getElementsByTagName('tr') as $row) {
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                        $cells[] = $this->normaliseText($cell->textContent);
                    }
                }
                if (count($cells) < 2 || strtolower($cells[0]) === 'date') {
                    continue;
                }

                $date = $this->sourceDate($cells[0]);
                $percentage = $this->percentage($cells[1]);
                if ($date === null || $percentage === null) {
                    throw new \RuntimeException('An unreadable ' . $rateType . ' VAT rate row was found in VAT Notice 700.');
                }
                $parsedRows[] = [
                    'rate_type' => $rateType,
                    'effective_from' => $date,
                    'effective_to' => null,
                    'rate_percentage' => $percentage,
                    'original_period_text' => $cells[0],
                    'notes' => 'Historic headline VAT rate parsed from VAT Notice 700 section 3.3.1. Source amount: ' . $cells[1] . '.',
                ];
            }

            if ($parsedRows === []) {
                throw new \RuntimeException('No ' . $rateType . ' VAT rate history was parsed from VAT Notice 700.');
            }
            usort($parsedRows, static fn(array $left, array $right): int => $left['effective_from'] <=> $right['effective_from']);
            $rulesByType[$rateType] = $this->closePeriods($parsedRows);
        }

        $historicText = strtolower($this->normaliseText($html));
        if (!str_contains($historicText, 'zero rate has existed throughout that time')) {
            throw new \RuntimeException('VAT Notice 700 did not confirm the historic zero rate.');
        }
        $rulesByType['zero'] = [[
            'rate_type' => 'zero',
            'effective_from' => '1973-04-01',
            'effective_to' => null,
            'rate_percentage' => 0.0,
            'original_period_text' => '1 April 1973 onwards',
            'notes' => 'VAT Notice 700 section 3.3.1 states that the zero rate has existed since VAT was introduced on 1 April 1973.',
        ]];

        return array_merge($rulesByType['standard'], $rulesByType['reduced'], $rulesByType['zero']);
    }

    private function parseCurrentRatesBody(string $html): array
    {
        $dom = $this->dom($html, 'the current VAT rates page');
        $rates = [];
        foreach ($dom->getElementsByTagName('tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = $this->normaliseText($cell->textContent);
                }
            }
            if (count($cells) < 2) {
                continue;
            }

            $label = strtolower($cells[0]);
            foreach (['standard', 'reduced', 'zero'] as $rateType) {
                if (str_contains($label, $rateType . ' rate')) {
                    $percentage = $this->percentage($cells[1]);
                    if ($percentage === null) {
                        throw new \RuntimeException('The current ' . $rateType . ' VAT rate was unreadable.');
                    }
                    $rates[$rateType] = $percentage;
                }
            }
        }

        return $rates;
    }

    private function closePeriods(array $rules): array
    {
        $seen = [];
        foreach ($rules as $index => &$rule) {
            $start = (string)$rule['effective_from'];
            if (isset($seen[$start])) {
                throw new \RuntimeException('VAT Notice 700 contained duplicate headline VAT rate dates.');
            }
            $seen[$start] = true;
            if (isset($rules[$index + 1])) {
                $rule['effective_to'] = (new \DateTimeImmutable((string)$rules[$index + 1]['effective_from']))
                    ->modify('-1 day')
                    ->format('Y-m-d');
            }
        }
        unset($rule);

        return $rules;
    }

    private function canonicalRateRule(array $rule): array
    {
        return [
            'rate_type' => (string)$rule['rate_type'],
            'scope' => 'uk',
            'effective_from' => (string)$rule['effective_from'],
            'effective_to' => $rule['effective_to'],
            'rate_percentage' => round((float)$rule['rate_percentage'], 3),
            'original_period_text' => (string)$rule['original_period_text'],
            'source_notes' => trim((string)$rule['notes']),
        ];
    }

    private function storeRule(array $rule): void
    {
        $sql = 'INSERT INTO vat_rate_rules (
                    rate_type,
                    scope,
                    effective_from,
                    effective_to,
                    rate_percentage,
                    original_period_text,
                    source_url,
                    source_content_id,
                    source_updated_at,
                    source_checked_at,
                    rule_version,
                    dataset_hash,
                    is_active,
                    notes,
                    created_at,
                    updated_at
                ) VALUES (
                    :rate_type,
                    :scope,
                    :effective_from,
                    :effective_to,
                    :rate_percentage,
                    :original_period_text,
                    :source_url,
                    :source_content_id,
                    :source_updated_at,
                    :source_checked_at,
                    :rule_version,
                    :dataset_hash,
                    1,
                    :notes,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )';

        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(dataset_hash, rate_type, scope, effective_from) DO UPDATE SET
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP'
            : ' ON DUPLICATE KEY UPDATE
                    is_active = 1,
                    updated_at = CURRENT_TIMESTAMP';

        \InterfaceDB::prepareExecute($sql, [
            'rate_type' => (string)$rule['rate_type'],
            'scope' => (string)$rule['scope'],
            'effective_from' => (string)$rule['effective_from'],
            'effective_to' => $rule['effective_to'],
            'rate_percentage' => round((float)$rule['rate_percentage'], 3),
            'original_period_text' => (string)$rule['original_period_text'],
            'source_url' => (string)$rule['source_url'],
            'source_content_id' => (string)$rule['source_content_id'],
            'source_updated_at' => $rule['source_updated_at'],
            'source_checked_at' => (string)$rule['source_checked_at'],
            'rule_version' => (string)$rule['rule_version'],
            'dataset_hash' => (string)$rule['dataset_hash'],
            'notes' => (string)$rule['notes'],
        ]);
    }

    private function fetchContent(string $url): string
    {
        if ($this->fetcher !== null) {
            $response = ($this->fetcher)($url);
            if (is_array($response) && array_key_exists('status_code', $response)) {
                $status = (int)$response['status_code'];
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('GOV.UK Content API returned HTTP status ' . $status . '.');
                }
                $response = $response['body'] ?? '';
            }
            if (is_array($response)) {
                $response = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (!is_string($response) || trim($response) === '') {
                throw new \RuntimeException('GOV.UK Content API returned an empty response.');
            }

            return $response;
        }

        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL is required to refresh GOV.UK VAT rates.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialise the GOV.UK VAT rates download.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EEL Accounts VAT rate refresh',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || trim($body) === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'GOV.UK VAT rates download failed'
                . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status)
                . '.'
            );
        }

        return $body;
    }

    private function decodeContentDocument(string $json, string $label, string $expectedBasePath): array
    {
        try {
            $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The GOV.UK ' . $label . ' Content API response was not valid JSON.', 0, $exception);
        }
        if (!is_array($document)) {
            throw new \RuntimeException('The GOV.UK ' . $label . ' Content API response was incomplete.');
        }
        $basePath = trim((string)($document['base_path'] ?? ''));
        if ($basePath !== $expectedBasePath) {
            throw new \RuntimeException('The GOV.UK ' . $label . ' response was not the expected Content API page.');
        }

        $contentId = trim((string)($document['content_id'] ?? ''));
        $body = trim((string)($document['details']['body'] ?? ''));
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $contentId) !== 1) {
            throw new \RuntimeException('The GOV.UK ' . $label . ' Content API response did not contain a valid content ID.');
        }
        if ($body === '') {
            throw new \RuntimeException('The GOV.UK ' . $label . ' Content API response was incomplete.');
        }

        return $document;
    }

    private function sourceUpdatedAt(array $document, string $label): string
    {
        foreach (['public_updated_at', 'updated_at'] as $field) {
            $timestamp = $this->normaliseSourceTimestamp($document[$field] ?? null);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        throw new \RuntimeException(
            'The GOV.UK ' . $label . ' Content API response did not contain a valid source update timestamp.'
        );
    }

    private function dom(string $html, string $label): \DOMDocument
    {
        $html = trim($html);
        if ($html === '') {
            throw new \RuntimeException('The GOV.UK ' . $label . ' content was empty.');
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \RuntimeException('The GOV.UK ' . $label . ' content was unreadable.');
        }

        return $dom;
    }

    private function refreshResult(array $parsed, bool $success, int $count, bool $unchanged): array
    {
        return [
            'success' => $success,
            'errors' => [],
            'warnings' => (array)($parsed['warnings'] ?? []),
            'refreshed_count' => $count,
            'unchanged' => $unchanged,
            'dataset_hash' => (string)($parsed['dataset_hash'] ?? ''),
            'source_url' => (string)($parsed['source_url'] ?? self::NOTICE_URL),
            'source_content_id' => (string)($parsed['source_content_id'] ?? ''),
            'source_updated_at' => $parsed['source_updated_at'] ?? null,
            'source_checked_at' => (string)($parsed['source_checked_at'] ?? ''),
            'cross_check_url' => (string)($parsed['cross_check_url'] ?? self::CURRENT_RATES_URL),
            'cross_check_content_id' => (string)($parsed['cross_check_content_id'] ?? ''),
        ];
    }

    private function failureResult(string $message): array
    {
        return [
            'success' => false,
            'errors' => [$message],
            'warnings' => [],
            'refreshed_count' => 0,
            'unchanged' => false,
        ];
    }

    private function ensureSqliteSchema(): void
    {
        if (\InterfaceDB::driverName() === 'sqlite' && !$this->schemaReady()) {
            $this->ensureSchema();
        }
    }

    private function schemaReady(): bool
    {
        if (!\InterfaceDB::tableExists('vat_rate_rules')) {
            return false;
        }

        foreach ([
            'rate_type',
            'scope',
            'effective_from',
            'effective_to',
            'rate_percentage',
            'original_period_text',
            'source_url',
            'source_content_id',
            'source_updated_at',
            'source_checked_at',
            'rule_version',
            'dataset_hash',
            'is_active',
            'notes',
        ] as $column) {
            if (!\InterfaceDB::columnExists('vat_rate_rules', $column)) {
                return false;
            }
        }

        return true;
    }

    private function normaliseStoredRule(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['rate_percentage'] = round((float)($row['rate_percentage'] ?? 0), 3);
        $row['effective_to'] = isset($row['effective_to']) && trim((string)$row['effective_to']) !== ''
            ? (string)$row['effective_to']
            : null;
        $row['source_updated_at'] = isset($row['source_updated_at']) && trim((string)$row['source_updated_at']) !== ''
            ? (string)$row['source_updated_at']
            : null;
        $row['is_active'] = (int)($row['is_active'] ?? 0);

        return $row;
    }

    private function unavailable(string $rateType, string $scope, string $message): array
    {
        return [
            'available' => false,
            'message' => $message,
            'id' => 0,
            'rate_type' => $rateType,
            'scope' => $scope,
            'effective_from' => '',
            'effective_to' => null,
            'rate_percentage' => null,
            'original_period_text' => '',
            'source_url' => self::NOTICE_URL,
            'source_content_id' => '',
            'source_updated_at' => null,
            'source_checked_at' => '',
            'rule_version' => '',
            'dataset_hash' => '',
            'is_active' => 0,
            'notes' => '',
        ];
    }

    private function sourceDate(string $value): ?string
    {
        try {
            return (new \DateTimeImmutable($this->normaliseText($value)))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseDate(string $value): ?string
    {
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function percentage(string $value): ?float
    {
        if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%/', $this->normaliseText($value), $matches) !== 1) {
            return null;
        }

        return round((float)$matches[1], 3);
    }

    private function normaliseSourceTimestamp(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseCheckedAt(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The VAT rate source check timestamp was invalid.', 0, $exception);
        }
    }

    private function normaliseText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", "\xe2\x80\x94"], [' ', '—'], $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
