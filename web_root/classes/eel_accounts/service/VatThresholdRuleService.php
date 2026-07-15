<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class VatThresholdRuleService
{
    public const NOTICE_URL = 'https://www.gov.uk/government/publications/vat-notice-70011-cancelling-your-registration/vat-notice-70011-supplement';
    public const CONTENT_API_URL = 'https://www.gov.uk/api/content/government/publications/vat-notice-70011-cancelling-your-registration/vat-notice-70011-supplement';
    public const REGISTRATION_GUIDANCE_URL = 'https://www.gov.uk/register-for-vat';
    public const THRESHOLDS_URL = 'https://www.gov.uk/how-vat-works/vat-thresholds';
    public const REGISTRATION_MANUAL_URL = 'https://www.gov.uk/hmrc-internal-manuals/vat-registration-manual/vatreg04100';
    public const DEREGISTRATION_MANUAL_URL = 'https://www.gov.uk/hmrc-internal-manuals/vat-registration-manual/vatreg04150';
    public const CORROBORATING_SUPPLEMENT_URL = 'https://assets.publishing.service.gov.uk/media/5a80db40ed915d74e6230d6d/Supplement_to_Notices_700_2F1_and_700_2F11.pdf';
    public const TYPE_TAXABLE_SUPPLIES = 'taxable_supplies';
    public const TYPE_DISTANCE_SELLING = 'distance_selling';
    public const TYPE_ACQUISITIONS = 'acquisitions';
    public const TYPE_DEREGISTRATION = 'deregistration';

    private const CONTENT_PATH = '/government/publications/vat-notice-70011-cancelling-your-registration/vat-notice-70011-supplement';
    private const TYPES = [
        self::TYPE_TAXABLE_SUPPLIES,
        self::TYPE_DISTANCE_SELLING,
        self::TYPE_ACQUISITIONS,
        self::TYPE_DEREGISTRATION,
    ];

    private ?\Closure $fetcher;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher === null ? null : \Closure::fromCallable($fetcher);
    }

    /** @return list<array<string, mixed>> */
    public function fetchRules(): array
    {
        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return [];
        }

        $rows = \InterfaceDB::fetchAll(
            <<<'SQL'
            SELECT id,
                    threshold_type,
                    jurisdiction,
                    effective_from,
                    effective_to,
                    original_period_text,
                    registration_threshold,
                    deregistration_threshold,
                    source_url,
                    source_content_id,
                    source_updated_at,
                    source_checked_at,
                    dataset_hash,
                    row_hash,
                    is_active,
                    audit_notes,
                    created_at,
                    updated_at
               FROM vat_threshold_rules
              WHERE is_active = 1
              ORDER BY CASE threshold_type
                           WHEN 'taxable_supplies' THEN 1
                           WHEN 'distance_selling' THEN 2
                           WHEN 'acquisitions' THEN 3
                           WHEN 'deregistration' THEN 4
                           ELSE 5
                       END,
                       effective_from DESC,
                       jurisdiction,
                       id DESC
            SQL
        );

        return array_map(fn(array $row): array => $this->normaliseRule($row), $rows);
    }

    /** @return array<string, mixed> */
    public function fetchForDate(string $date, string $type = self::TYPE_TAXABLE_SUPPLIES): array
    {
        $date = $this->normaliseDate($date);
        $type = trim(strtolower($type));
        if ($date === null) {
            return $this->unavailable('A valid threshold date is required.', $type);
        }
        if (!in_array($type, self::TYPES, true)) {
            return $this->unavailable('A recognised VAT threshold type is required.', $type);
        }

        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return $this->unavailable('VAT threshold data has not been imported.', $type);
        }

        $row = \InterfaceDB::fetchOne(
            <<<'SQL'
            SELECT id,
                    threshold_type,
                    jurisdiction,
                    effective_from,
                    effective_to,
                    original_period_text,
                    registration_threshold,
                    deregistration_threshold,
                    source_url,
                    source_content_id,
                    source_updated_at,
                    source_checked_at,
                    dataset_hash,
                    row_hash,
                    is_active,
                    audit_notes,
                    created_at,
                    updated_at
               FROM vat_threshold_rules
              WHERE threshold_type = :threshold_type
                AND is_active = 1
                AND effective_from <= :threshold_date
                AND (effective_to IS NULL OR effective_to >= :threshold_date)
              ORDER BY CASE jurisdiction
                           WHEN 'united_kingdom' THEN 1
                           WHEN 'northern_ireland' THEN 2
                           WHEN 'great_britain' THEN 3
                           ELSE 4
                       END,
                       CASE WHEN registration_threshold IS NULL AND deregistration_threshold IS NULL THEN 1 ELSE 0 END,
                       effective_from DESC,
                       id DESC
              LIMIT 1
            SQL,
            [
                'threshold_type' => $type,
                'threshold_date' => $date,
            ]
        );

        return is_array($row)
            ? $this->normaliseRule($row)
            : $this->unavailable('VAT threshold data is unavailable for the selected date and type.', $type);
    }

    /** @return array<string, mixed> */
    public function refreshFromHmrc(): array
    {
        $this->ensureSqliteSchema();
        if (!$this->schemaReady()) {
            return $this->failure('The VAT threshold rules table is not available. Apply the downstream database migration first.');
        }

        try {
            $parsed = $this->parseContentApiJson($this->fetchContentApiPayload());
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $datasetHash = (string)$parsed['dataset_hash'];
        $activeHash = (string)(\InterfaceDB::fetchColumn(
            'SELECT dataset_hash
               FROM vat_threshold_rules
              WHERE is_active = 1
              GROUP BY dataset_hash
              ORDER BY MAX(id) DESC
              LIMIT 1'
        ) ?: '');

        if ($activeHash !== '' && hash_equals($activeHash, $datasetHash)) {
            return $this->successResult($parsed, 0, true);
        }

        try {
            $this->publishSnapshot($parsed);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage(), (array)($parsed['warnings'] ?? []));
        }

        return $this->successResult($parsed, count((array)$parsed['rows']), false);
    }

    /**
     * Parse and fully validate one GOV.UK Content API response without changing the database.
     *
     * @param string|array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseContentApiJson(string|array $payload, ?string $checkedAt = null): array
    {
        if (is_string($payload)) {
            $payload = trim($payload);
            if ($payload === '') {
                throw new \RuntimeException('The GOV.UK VAT threshold response was empty.');
            }
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('The GOV.UK VAT threshold response was not valid JSON.', 0, $exception);
            }
        }

        if (!is_array($payload) || (string)($payload['base_path'] ?? '') !== self::CONTENT_PATH) {
            throw new \RuntimeException('The GOV.UK response was not the expected VAT Notice 700/11 supplement.');
        }

        $contentId = trim((string)($payload['content_id'] ?? ''));
        if (preg_match('/^[a-f0-9-]{36}$/i', $contentId) !== 1) {
            throw new \RuntimeException('The GOV.UK VAT threshold response did not contain a valid content ID.');
        }

        $body = trim((string)($payload['details']['body'] ?? ''));
        if ($body === '') {
            throw new \RuntimeException('The GOV.UK VAT threshold response did not contain publication content.');
        }

        $sourceUpdatedAt = $this->normaliseTimestamp((string)($payload['updated_at'] ?? ''));
        if ($sourceUpdatedAt === null) {
            throw new \RuntimeException('The GOV.UK VAT threshold response did not contain a valid update timestamp.');
        }
        $checkedAt = $this->normaliseTimestamp($checkedAt ?? '')
            ?? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $dom = $this->dom($body);
        $taxable = $this->historyRows(
            $this->sectionTable($dom, 'registration-limits--taxable-supplies'),
            'taxable_supplies',
            'united_kingdom',
            'registration_threshold'
        );
        $acquisitions = $this->historyRows(
            $this->sectionTable($dom, 'registration-limits--acquisitions'),
            'acquisitions',
            'united_kingdom',
            'registration_threshold'
        );
        $deregistration = $this->historyRows(
            $this->sectionTable($dom, 'cancelled-vat-registration-limits'),
            'deregistration',
            'united_kingdom',
            'deregistration_threshold'
        );

        $warnings = $this->annotateContinuityWarnings($acquisitions);
        $taxable[] = $this->currentMoneyRow(
            $taxable,
            'taxable_supplies',
            'united_kingdom',
            'Current threshold',
            'registration_threshold',
            $this->moneyFromSection($dom, 'current-threshold'),
            'Current taxable-supplies threshold. Its start follows the final closed period in the published history table.'
        );
        $deregistration[] = $this->currentMoneyRow(
            $deregistration,
            'deregistration',
            'united_kingdom',
            'Current limit',
            'deregistration_threshold',
            $this->moneyFromSection($dom, 'current-limit'),
            'Current VAT registration cancellation limit. Its start follows the final closed period in the published history table.'
        );

        $distanceText = $this->sectionText($dom, 'distance-selling');
        $distance = [[
            'threshold_type' => 'distance_selling',
            'jurisdiction' => 'northern_ireland',
            'effective_from' => '2021-01-01',
            'effective_to' => null,
            'original_period_text' => 'Current distance selling threshold',
            'registration_threshold' => $this->moneyFromText($distanceText, 'current distance selling threshold'),
            'deregistration_threshold' => null,
            'audit_notes' => 'Current Northern Ireland distance-selling threshold; the source states the calendar-year basis. Effective date follows the end of the Brexit transition period.',
        ]];

        $acquisitionText = $this->sectionText($dom, 'from-1-january-2021');
        $greatBritainText = $this->sentenceContaining($acquisitionText, 'Great Britain');
        $northernIrelandText = $this->sentenceContaining($acquisitionText, 'Northern Ireland');
        $acquisitions[] = [
            'threshold_type' => 'acquisitions',
            'jurisdiction' => 'great_britain',
            'effective_from' => '2021-01-01',
            'effective_to' => null,
            'original_period_text' => $greatBritainText,
            'registration_threshold' => null,
            'deregistration_threshold' => null,
            'audit_notes' => 'Narrative-only current rule: GOV.UK states that an acquisitions threshold does not apply in Great Britain from 1 January 2021. No numeric amount was imported.',
        ];
        $acquisitions[] = [
            'threshold_type' => 'acquisitions',
            'jurisdiction' => 'northern_ireland',
            'effective_from' => '2021-01-01',
            'effective_to' => null,
            'original_period_text' => $northernIrelandText,
            'registration_threshold' => null,
            'deregistration_threshold' => null,
            'audit_notes' => 'Narrative-only current rule: GOV.UK states that the Northern Ireland limit follows the normal UK registration threshold. No numeric amount was copied or inferred.',
        ];

        $rows = array_merge($taxable, $distance, $acquisitions, $deregistration);
        $this->validateCompleteDataset($rows);
        usort($rows, static function (array $left, array $right): int {
            return strcmp((string)$left['threshold_type'], (string)$right['threshold_type'])
                ?: strcmp((string)$left['jurisdiction'], (string)$right['jurisdiction'])
                ?: strcmp((string)$left['effective_from'], (string)$right['effective_from'])
                ?: strcmp((string)($left['effective_to'] ?? ''), (string)($right['effective_to'] ?? ''))
                ?: strcmp((string)$left['original_period_text'], (string)$right['original_period_text']);
        });

        $canonicalRows = array_map(fn(array $row): array => $this->canonicalRow($row), $rows);
        $datasetJson = json_encode($canonicalRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $datasetHash = hash('sha256', $datasetJson);

        foreach ($rows as &$row) {
            $rowHash = hash('sha256', json_encode($this->canonicalRow($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $row += [
                'source_url' => self::NOTICE_URL,
                'source_content_id' => $contentId,
                'source_updated_at' => $sourceUpdatedAt,
                'source_checked_at' => $checkedAt,
                'dataset_hash' => $datasetHash,
                'row_hash' => $rowHash,
                'is_active' => 1,
            ];
        }
        unset($row);

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'source_url' => self::NOTICE_URL,
            'source_content_id' => $contentId,
            'source_updated_at' => $sourceUpdatedAt,
            'source_checked_at' => $checkedAt,
            'dataset_hash' => $datasetHash,
        ];
    }

    /** @param array<string, mixed> $parsed */
    private function publishSnapshot(array $parsed): void
    {
        $datasetHash = (string)$parsed['dataset_hash'];
        $ownsTransaction = !\InterfaceDB::inTransaction();
        $savepoint = 'vat_threshold_refresh';

        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        } else {
            \InterfaceDB::execute('SAVEPOINT ' . $savepoint);
        }

        try {
            \InterfaceDB::prepareExecute(
                'UPDATE vat_threshold_rules
                    SET is_active = 0,
                        updated_at = :updated_at
                  WHERE is_active = 1',
                ['updated_at' => (string)$parsed['source_checked_at']]
            );

            $existing = (int)(\InterfaceDB::fetchColumn(
                'SELECT COUNT(*) FROM vat_threshold_rules WHERE dataset_hash = :dataset_hash',
                ['dataset_hash' => $datasetHash]
            ) ?: 0);
            if ($existing > 0) {
                \InterfaceDB::prepareExecute(
                    'UPDATE vat_threshold_rules
                        SET is_active = 1,
                            updated_at = :updated_at
                      WHERE dataset_hash = :dataset_hash',
                    [
                        'updated_at' => (string)$parsed['source_checked_at'],
                        'dataset_hash' => $datasetHash,
                    ]
                );
            } else {
                foreach ((array)$parsed['rows'] as $row) {
                    $this->insertRule((array)$row);
                }
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            } else {
                \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction) {
                if (\InterfaceDB::inTransaction()) {
                    \InterfaceDB::rollBack();
                }
            } else {
                try {
                    \InterfaceDB::execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    \InterfaceDB::execute('RELEASE SAVEPOINT ' . $savepoint);
                } catch (\Throwable) {
                }
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $row */
    private function insertRule(array $row): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO vat_threshold_rules (
                threshold_type,
                jurisdiction,
                effective_from,
                effective_to,
                original_period_text,
                registration_threshold,
                deregistration_threshold,
                source_url,
                source_content_id,
                source_updated_at,
                source_checked_at,
                dataset_hash,
                row_hash,
                is_active,
                audit_notes,
                created_at,
                updated_at
             ) VALUES (
                :threshold_type,
                :jurisdiction,
                :effective_from,
                :effective_to,
                :original_period_text,
                :registration_threshold,
                :deregistration_threshold,
                :source_url,
                :source_content_id,
                :source_updated_at,
                :source_checked_at,
                :dataset_hash,
                :row_hash,
                1,
                :audit_notes,
                :created_at,
                :updated_at
             )',
            [
                'threshold_type' => (string)$row['threshold_type'],
                'jurisdiction' => (string)$row['jurisdiction'],
                'effective_from' => (string)$row['effective_from'],
                'effective_to' => $row['effective_to'],
                'original_period_text' => (string)$row['original_period_text'],
                'registration_threshold' => $row['registration_threshold'],
                'deregistration_threshold' => $row['deregistration_threshold'],
                'source_url' => (string)$row['source_url'],
                'source_content_id' => (string)$row['source_content_id'],
                'source_updated_at' => $row['source_updated_at'],
                'source_checked_at' => (string)$row['source_checked_at'],
                'dataset_hash' => (string)$row['dataset_hash'],
                'row_hash' => (string)$row['row_hash'],
                'audit_notes' => (string)$row['audit_notes'],
                'created_at' => (string)$row['source_checked_at'],
                'updated_at' => (string)$row['source_checked_at'],
            ]
        );
    }

    private function fetchContentApiPayload(): string|array
    {
        if ($this->fetcher instanceof \Closure) {
            $result = ($this->fetcher)(self::CONTENT_API_URL);
            if (is_array($result) && array_key_exists('body', $result)) {
                $status = (int)($result['status_code'] ?? 200);
                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException('The GOV.UK VAT threshold download returned HTTP status ' . $status . '.');
                }
                $result = $result['body'];
            }
            if (!is_string($result) && !is_array($result)) {
                throw new \RuntimeException('The injected GOV.UK VAT threshold fetcher returned an unsupported response.');
            }
            return $result;
        }

        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL is required to refresh HMRC VAT thresholds.');
        }
        $handle = curl_init(self::CONTENT_API_URL);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialise the GOV.UK VAT threshold download.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EEL Accounts VAT threshold refresh',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || trim($body) === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException(
                'The GOV.UK VAT threshold download failed'
                . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status)
                . '.'
            );
        }
        return $body;
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html)) {
                throw new \RuntimeException('The GOV.UK VAT threshold publication HTML was not readable.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $dom;
    }

    private function sectionTable(\DOMDocument $dom, string $headingId): \DOMElement
    {
        $heading = $dom->getElementById($headingId);
        if (!$heading instanceof \DOMElement) {
            throw new \RuntimeException('The GOV.UK VAT threshold publication is missing section "' . $headingId . '".');
        }
        for ($node = $heading->nextSibling; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if (strtolower($node->tagName) === 'h2') {
                break;
            }
            if (strtolower($node->tagName) === 'table') {
                return $node;
            }
        }
        throw new \RuntimeException('The GOV.UK VAT threshold publication is missing the history table for section "' . $headingId . '".');
    }

    private function sectionText(\DOMDocument $dom, string $headingId): string
    {
        $heading = $dom->getElementById($headingId);
        if (!$heading instanceof \DOMElement) {
            throw new \RuntimeException('The GOV.UK VAT threshold publication is missing section "' . $headingId . '".');
        }
        $headingLevel = (int)substr(strtolower($heading->tagName), 1);
        $parts = [];
        for ($node = $heading->nextSibling; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (preg_match('/^h([1-6])$/', $tag, $matches) === 1 && (int)$matches[1] <= $headingLevel) {
                break;
            }
            $text = $this->normaliseText($node->textContent);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        $text = implode(' ', $parts);
        if ($text === '') {
            throw new \RuntimeException('The GOV.UK VAT threshold publication section "' . $headingId . '" was empty.');
        }
        return $text;
    }

    /** @return list<array<string, mixed>> */
    private function historyRows(
        \DOMElement $table,
        string $type,
        string $jurisdiction,
        string $amountField
    ): array {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && strtolower($cell->tagName) === 'td') {
                    $cells[] = $this->normaliseText($cell->textContent);
                }
            }
            if ($cells === []) {
                continue;
            }
            if (count($cells) < 2) {
                throw new \RuntimeException('A GOV.UK VAT threshold history row did not contain both period and amount.');
            }

            [$from, $to] = $this->parsePeriod($cells[0]);
            $amount = $this->moneyFromText($cells[1], 'annual limit');
            $auditNotes = '';
            if ($type === 'taxable_supplies' && $cells[0] === '1 January 1997 to 31 March 1998' && $amount === 49000.0) {
                $from = '1997-12-01';
                $auditNotes = 'Source correction: the published period text says 1 January 1997, but 1 December 1997 is used to remove the overlap. Corroboration: '
                    . self::REGISTRATION_MANUAL_URL . ', ' . self::DEREGISTRATION_MANUAL_URL
                    . ' and ' . self::CORROBORATING_SUPPLEMENT_URL . '.';
            }
            $rows[] = [
                'threshold_type' => $type,
                'jurisdiction' => $jurisdiction,
                'effective_from' => $from,
                'effective_to' => $to,
                'original_period_text' => $cells[0],
                'registration_threshold' => $amountField === 'registration_threshold' ? $amount : null,
                'deregistration_threshold' => $amountField === 'deregistration_threshold' ? $amount : null,
                'audit_notes' => $auditNotes,
            ];
        }
        if ($rows === []) {
            throw new \RuntimeException('A GOV.UK VAT threshold history table did not contain any data rows.');
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function annotateContinuityWarnings(array &$rows): array
    {
        $indexes = array_keys($rows);
        usort($indexes, static fn(int $left, int $right): int => strcmp((string)$rows[$left]['effective_from'], (string)$rows[$right]['effective_from']));
        $warnings = [];
        for ($position = 1, $count = count($indexes); $position < $count; $position++) {
            $previous = $rows[$indexes[$position - 1]];
            $currentIndex = $indexes[$position];
            $current = $rows[$currentIndex];
            $expected = (new \DateTimeImmutable((string)$previous['effective_to']))->modify('+1 day')->format('Y-m-d');
            $actual = (string)$current['effective_from'];
            if ($actual === $expected) {
                continue;
            }
            $kind = $actual < $expected ? 'overlap' : 'gap';
            $article = $kind === 'overlap' ? 'an' : 'a';
            $warning = 'Published acquisitions history contains ' . $article . ' ' . $kind . ' before "'
                . (string)$current['original_period_text'] . '"; source dates were retained unchanged.';
            $warnings[] = $warning;
            $rows[$currentIndex]['audit_notes'] = $this->appendNote((string)$rows[$currentIndex]['audit_notes'], $warning);
        }
        return $warnings;
    }

    /**
     * @param list<array<string, mixed>> $historyRows
     * @return array<string, mixed>
     */
    private function currentMoneyRow(
        array $historyRows,
        string $type,
        string $jurisdiction,
        string $originalText,
        string $amountField,
        float $amount,
        string $auditNotes
    ): array {
        $lastEnd = '';
        foreach ($historyRows as $row) {
            $lastEnd = max($lastEnd, (string)($row['effective_to'] ?? ''));
        }
        if ($lastEnd === '') {
            throw new \RuntimeException('The current GOV.UK VAT threshold could not be joined to its history.');
        }
        $from = (new \DateTimeImmutable($lastEnd))->modify('+1 day')->format('Y-m-d');
        return [
            'threshold_type' => $type,
            'jurisdiction' => $jurisdiction,
            'effective_from' => $from,
            'effective_to' => null,
            'original_period_text' => $originalText,
            'registration_threshold' => $amountField === 'registration_threshold' ? $amount : null,
            'deregistration_threshold' => $amountField === 'deregistration_threshold' ? $amount : null,
            'audit_notes' => $auditNotes,
        ];
    }

    private function moneyFromSection(\DOMDocument $dom, string $headingId): float
    {
        return $this->moneyFromText($this->sectionText($dom, $headingId), $headingId);
    }

    private function moneyFromText(string $text, string $context): float
    {
        $text = $this->normaliseText($text);
        if (preg_match('/(?:£|GBP\s*)?([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]+)?|[0-9]+(?:\.[0-9]+)?)/u', $text, $matches) !== 1) {
            throw new \RuntimeException('The GOV.UK VAT threshold amount for ' . $context . ' was not readable.');
        }
        $amount = round((float)str_replace(',', '', $matches[1]), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('The GOV.UK VAT threshold amount for ' . $context . ' was not positive.');
        }
        return $amount;
    }

    /** @return array{0: string, 1: string} */
    private function parsePeriod(string $period): array
    {
        if (preg_match('/^(.+?)\s+to\s+(.+)$/i', $period, $matches) !== 1) {
            throw new \RuntimeException('The GOV.UK VAT threshold period "' . $period . '" was not readable.');
        }
        $from = $this->strictDate($matches[1]);
        $to = $this->strictDate($matches[2]);
        if ($from > $to) {
            throw new \RuntimeException('The GOV.UK VAT threshold period "' . $period . '" ends before it starts.');
        }
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    private function strictDate(string $date): \DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat('!j F Y', $this->normaliseText($date), new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$value instanceof \DateTimeImmutable
            || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        ) {
            throw new \RuntimeException('The GOV.UK VAT threshold date "' . $date . '" was not readable.');
        }
        return $value;
    }

    private function sentenceContaining(string $text, string $needle): string
    {
        foreach (preg_split('/(?<=[.!?])\s+/', $text) ?: [] as $sentence) {
            $sentence = $this->normaliseText((string)$sentence);
            if (stripos($sentence, $needle) !== false) {
                return mb_substr($sentence, 0, 255);
            }
        }
        throw new \RuntimeException('The GOV.UK acquisitions guidance did not contain the expected ' . $needle . ' statement.');
    }

    /** @param list<array<string, mixed>> $rows */
    private function validateCompleteDataset(array $rows): void
    {
        $types = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['threshold_type'], $rows)));
        sort($types);
        $expected = self::TYPES;
        sort($expected);
        if ($types !== $expected) {
            throw new \RuntimeException('The GOV.UK VAT threshold response did not contain all required threshold types.');
        }
        foreach ($rows as $row) {
            if (
                !in_array((string)$row['threshold_type'], self::TYPES, true)
                || trim((string)$row['jurisdiction']) === ''
                || $this->normaliseDate((string)$row['effective_from']) === null
                || (($row['effective_to'] ?? null) !== null && $this->normaliseDate((string)$row['effective_to']) === null)
                || trim((string)$row['original_period_text']) === ''
            ) {
                throw new \RuntimeException('The parsed GOV.UK VAT threshold dataset contained an incomplete row.');
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function canonicalRow(array $row): array
    {
        return [
            'threshold_type' => (string)$row['threshold_type'],
            'jurisdiction' => (string)$row['jurisdiction'],
            'effective_from' => (string)$row['effective_from'],
            'effective_to' => $row['effective_to'],
            'original_period_text' => (string)$row['original_period_text'],
            'registration_threshold' => $row['registration_threshold'],
            'deregistration_threshold' => $row['deregistration_threshold'],
            'audit_notes' => (string)$row['audit_notes'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normaliseRule(array $row): array
    {
        $registration = $row['registration_threshold'] ?? null;
        $deregistration = $row['deregistration_threshold'] ?? null;
        return [
            'available' => true,
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'threshold_type' => (string)($row['threshold_type'] ?? ''),
            'jurisdiction' => (string)($row['jurisdiction'] ?? ''),
            'effective_from' => (string)($row['effective_from'] ?? ''),
            'effective_to' => ($row['effective_to'] ?? null) === null ? null : (string)$row['effective_to'],
            'original_period_text' => (string)($row['original_period_text'] ?? ''),
            'registration_threshold' => $registration === null ? null : round((float)$registration, 2),
            'deregistration_threshold' => $deregistration === null ? null : round((float)$deregistration, 2),
            'source_url' => trim((string)($row['source_url'] ?? self::NOTICE_URL)),
            'source_content_id' => (string)($row['source_content_id'] ?? ''),
            'source_updated_at' => ($row['source_updated_at'] ?? null) === null ? null : (string)$row['source_updated_at'],
            'source_checked_at' => (string)($row['source_checked_at'] ?? ''),
            'dataset_hash' => (string)($row['dataset_hash'] ?? ''),
            'row_hash' => (string)($row['row_hash'] ?? ''),
            'rule_version' => (string)($row['dataset_hash'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 1),
            'audit_notes' => (string)($row['audit_notes'] ?? ''),
            'notes' => (string)($row['audit_notes'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'message' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $message, string $type): array
    {
        return [
            'available' => false,
            'id' => null,
            'threshold_type' => $type,
            'jurisdiction' => '',
            'effective_from' => '',
            'effective_to' => null,
            'original_period_text' => '',
            'registration_threshold' => null,
            'deregistration_threshold' => null,
            'source_url' => self::NOTICE_URL,
            'source_content_id' => '',
            'source_updated_at' => null,
            'source_checked_at' => '',
            'dataset_hash' => '',
            'row_hash' => '',
            'rule_version' => '',
            'is_active' => 0,
            'audit_notes' => '',
            'notes' => '',
            'created_at' => '',
            'updated_at' => '',
            'message' => $message,
        ];
    }

    /** @param array<string, mixed> $parsed @return array<string, mixed> */
    private function successResult(array $parsed, int $count, bool $unchanged): array
    {
        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($parsed['warnings'] ?? []),
            'refreshed_count' => $count,
            'unchanged' => $unchanged,
            'dataset_hash' => (string)($parsed['dataset_hash'] ?? ''),
            'source_url' => (string)($parsed['source_url'] ?? self::NOTICE_URL),
            'source_content_id' => (string)($parsed['source_content_id'] ?? ''),
            'source_updated_at' => (string)($parsed['source_updated_at'] ?? ''),
            'source_checked_at' => (string)($parsed['source_checked_at'] ?? ''),
        ];
    }

    /** @param list<string> $warnings @return array<string, mixed> */
    private function failure(string $error, array $warnings = []): array
    {
        return [
            'success' => false,
            'errors' => [$error],
            'warnings' => $warnings,
            'refreshed_count' => 0,
            'unchanged' => false,
            'dataset_hash' => '',
            'source_url' => self::NOTICE_URL,
            'source_content_id' => '',
            'source_updated_at' => '',
            'source_checked_at' => '',
        ];
    }

    private function schemaReady(): bool
    {
        if (!\InterfaceDB::tableExists('vat_threshold_rules')) {
            return false;
        }
        foreach ([
            'threshold_type', 'jurisdiction', 'effective_from', 'effective_to', 'original_period_text',
            'registration_threshold', 'deregistration_threshold', 'source_url', 'source_content_id',
            'source_updated_at', 'source_checked_at', 'dataset_hash', 'row_hash', 'is_active', 'audit_notes',
        ] as $column) {
            if (!\InterfaceDB::columnExists('vat_threshold_rules', $column)) {
                return false;
            }
        }
        return true;
    }

    private function ensureSqliteSchema(): void
    {
        if (\InterfaceDB::driverName() !== 'sqlite') {
            return;
        }
        if (\InterfaceDB::tableExists('vat_threshold_rules') && $this->schemaReady()) {
            return;
        }
        \InterfaceDB::execute('DROP TABLE IF EXISTS vat_threshold_rules');
        \InterfaceDB::execute(
            'CREATE TABLE vat_threshold_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                threshold_type TEXT NOT NULL,
                jurisdiction TEXT NOT NULL,
                effective_from TEXT NOT NULL,
                effective_to TEXT DEFAULT NULL,
                original_period_text TEXT NOT NULL,
                registration_threshold REAL DEFAULT NULL,
                deregistration_threshold REAL DEFAULT NULL,
                source_url TEXT NOT NULL,
                source_content_id TEXT NOT NULL,
                source_updated_at TEXT DEFAULT NULL,
                source_checked_at TEXT NOT NULL,
                dataset_hash TEXT NOT NULL,
                row_hash TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1,
                audit_notes TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (dataset_hash, row_hash)
            )'
        );
        \InterfaceDB::execute(
            'CREATE INDEX idx_vat_threshold_rules_lookup
                 ON vat_threshold_rules (threshold_type, jurisdiction, is_active, effective_from, effective_to)'
        );
        \InterfaceDB::execute(
            'CREATE INDEX idx_vat_threshold_rules_dataset
                 ON vat_threshold_rules (dataset_hash, is_active)'
        );
    }

    private function normaliseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$parsed instanceof \DateTimeImmutable
            || $parsed->format('Y-m-d') !== $date
            || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))
        ) {
            return null;
        }
        return $date;
    }

    private function normaliseTimestamp(string $timestamp): ?string
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normaliseText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xc2\xa0", "\xe2\x80\x94"], [' ', '—'], $value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function appendNote(string $existing, string $note): string
    {
        return $existing === '' ? $note : rtrim($existing, '. ') . '. ' . $note;
    }
}
