<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class TaxRateRuleService
{
    public const HMRC_RATES_COLLECTION_URL = 'https://www.gov.uk/government/collections/rates-and-allowances-hm-revenue-and-customs';
    public const CORPORATION_TAX_SOURCE_URL = \eel_accounts\Service\CorporationTaxRateRuleService::SOURCE_URL;
    public const CAPITAL_ALLOWANCE_WDA_SOURCE_URL = 'https://www.gov.uk/work-out-capital-allowances/rates-and-pools';
    public const CAPITAL_ALLOWANCE_AIA_SOURCE_URL = 'https://www.gov.uk/capital-allowances/annual-investment-allowance';
    public const FRS105_THRESHOLDS_SOURCE_URL = 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies';

    public function ensureSchema(): void
    {
        if (\InterfaceDB::tableExists('tax_rate_rules')) {
            return;
        }

        if (\InterfaceDB::driverName() === 'sqlite') {
            \InterfaceDB::execute(
                'CREATE TABLE IF NOT EXISTS tax_rate_rules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tax_domain TEXT NOT NULL,
                    regime TEXT NOT NULL DEFAULT \'\',
                    rule_key TEXT NOT NULL,
                    rule_label TEXT NOT NULL,
                    period_start TEXT NOT NULL,
                    period_end TEXT NOT NULL DEFAULT \'9999-12-31\',
                    value_type TEXT NOT NULL,
                    rate_value REAL DEFAULT NULL,
                    amount_value REAL DEFAULT NULL,
                    fraction_value REAL DEFAULT NULL,
                    source_url TEXT NOT NULL,
                    source_updated_at TEXT DEFAULT NULL,
                    source_checked_at TEXT NOT NULL,
                    rule_version TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    notes TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (tax_domain, regime, rule_key, period_start, period_end, rule_version)
                )'
            );
            \InterfaceDB::execute(
                'CREATE INDEX IF NOT EXISTS idx_tax_rate_rules_lookup
                 ON tax_rate_rules (tax_domain, regime, rule_key, is_active, period_start, period_end)'
            );
            return;
        }

        \InterfaceDB::execute(
            'CREATE TABLE IF NOT EXISTS tax_rate_rules (
                id int(11) NOT NULL AUTO_INCREMENT,
                tax_domain varchar(64) NOT NULL,
                regime varchar(64) NOT NULL DEFAULT \'\',
                rule_key varchar(96) NOT NULL,
                rule_label varchar(255) NOT NULL,
                period_start date NOT NULL,
                period_end date NOT NULL DEFAULT \'9999-12-31\',
                value_type varchar(32) NOT NULL,
                rate_value decimal(10,6) DEFAULT NULL,
                amount_value decimal(14,2) DEFAULT NULL,
                fraction_value decimal(10,6) DEFAULT NULL,
                source_url varchar(500) NOT NULL,
                source_updated_at date DEFAULT NULL,
                source_checked_at date NOT NULL,
                rule_version varchar(64) NOT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                notes text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT current_timestamp(),
                updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id),
                UNIQUE KEY uq_tax_rate_rule_version (tax_domain, regime, rule_key, period_start, period_end, rule_version),
                KEY idx_tax_rate_rules_lookup (tax_domain, regime, rule_key, is_active, period_start, period_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function fetchRules(): array
    {
        $this->ensureSchema();

        $rules = [];
        foreach ((new \eel_accounts\Service\CorporationTaxRateRuleService())->fetchRules() as $row) {
            $rules[] = $this->displayCorporationTaxRule((array)$row);
        }

        foreach ($this->fetchCatalogRows() as $row) {
            $rules[] = $this->displayCatalogRule((array)$row);
        }

        usort($rules, static function (array $a, array $b): int {
            return strcmp((string)($b['period_start'] ?? ''), (string)($a['period_start'] ?? ''))
                ?: strcmp((string)($a['domain_label'] ?? ''), (string)($b['domain_label'] ?? ''))
                ?: strcmp((string)($a['rule_label'] ?? ''), (string)($b['rule_label'] ?? ''));
        });

        return $rules;
    }

    public function refreshFromHmrc(): array
    {
        $this->ensureSchema();

        $ctResult = (new \eel_accounts\Service\CorporationTaxRateRuleService())->refreshFromHmrc();
        $checkedAt = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $catalogRules = [];

        $ctHtml = $this->fetchSourceHtml(self::CORPORATION_TAX_SOURCE_URL);
        $catalogRules = array_merge(
            $catalogRules,
            $this->parseCorporationTaxCatalogHtml($ctHtml, self::CORPORATION_TAX_SOURCE_URL, $checkedAt)
        );

        $wdaHtml = $this->fetchSourceHtml(self::CAPITAL_ALLOWANCE_WDA_SOURCE_URL);
        $catalogRules = array_merge(
            $catalogRules,
            $this->parseCapitalAllowanceWdaHtml($wdaHtml, self::CAPITAL_ALLOWANCE_WDA_SOURCE_URL, $checkedAt)
        );

        $aiaHtml = $this->fetchSourceHtml(self::CAPITAL_ALLOWANCE_AIA_SOURCE_URL);
        $catalogRules = array_merge(
            $catalogRules,
            $this->parseAnnualInvestmentAllowanceHtml($aiaHtml, self::CAPITAL_ALLOWANCE_AIA_SOURCE_URL, $checkedAt)
        );

        $frs105Html = $this->fetchSourceHtml(self::FRS105_THRESHOLDS_SOURCE_URL);
        $catalogRules = array_merge(
            $catalogRules,
            $this->parseFrs105ThresholdsHtml($frs105Html, self::FRS105_THRESHOLDS_SOURCE_URL, $checkedAt)
        );

        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            foreach ($catalogRules as $rule) {
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
            'success' => !empty($ctResult['success']),
            'errors' => (array)($ctResult['errors'] ?? []),
            'warnings' => (array)($ctResult['warnings'] ?? []),
            'refreshed_count' => (int)($ctResult['refreshed_count'] ?? 0) + count($catalogRules),
            'source_url' => self::HMRC_RATES_COLLECTION_URL,
            'source_checked_at' => $checkedAt,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function parseFrs105ThresholdsHtml(
        string $html,
        string $sourceUrl = self::FRS105_THRESHOLDS_SOURCE_URL,
        ?string $checkedAt = null
    ): array {
        $text = $this->normaliseText(strip_tags($html));
        $sourceUpdatedAt = $this->extractSourceUpdatedAt($html);
        $checkedAt = $this->checkedAt($checkedAt);
        $turnover = $this->thresholdAmount($text, '/turnover\s+of\s+((?:GBP|£)\s*[0-9,.]+\s*(?:million)?)\s+or\s+less/i');
        $balanceSheet = $this->thresholdAmount($text, '/((?:GBP|£)\s*[0-9,.]+\s*(?:million)?)\s+or\s+less\s+on\s+its\s+balance\s+sheet/i');
        $employees = null;
        if (preg_match('/([0-9]+)\s+employees?\s+or\s+less/i', $text, $matches) === 1) {
            $employees = (float)$matches[1];
        }

        if ($turnover === null || $balanceSheet === null || $employees === null) {
            throw new \RuntimeException('The GOV.UK FRS 105 threshold page did not contain all turnover, balance-sheet and employee thresholds.');
        }

        return [
            $this->catalogRule('company_size', 'frs105_micro_entity', 'turnover', 'FRS 105 micro-entity turnover threshold', '2025-04-06', null, 'amount', null, $turnover, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from the GOV.UK micro-entity thresholds page.'),
            $this->catalogRule('company_size', 'frs105_micro_entity', 'balance_sheet_total', 'FRS 105 micro-entity balance-sheet threshold', '2025-04-06', null, 'amount', null, $balanceSheet, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from the GOV.UK micro-entity thresholds page.'),
            $this->catalogRule('company_size', 'frs105_micro_entity', 'employees', 'FRS 105 micro-entity employee threshold', '2025-04-06', null, 'amount', null, $employees, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from the GOV.UK micro-entity thresholds page.'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function fetchRuleForDate(string $taxDomain, string $regime, string $ruleKey, string $date): ?array
    {
        $this->ensureSchema();

        return \InterfaceDB::fetchOne(
            'SELECT *
             FROM tax_rate_rules
             WHERE tax_domain = :tax_domain
               AND regime = :regime
               AND rule_key = :rule_key
               AND is_active = 1
               AND period_start <= :date
               AND period_end >= :date
             ORDER BY period_start DESC, source_checked_at DESC, id DESC
             LIMIT 1',
            [
                'tax_domain' => $taxDomain,
                'regime' => $regime,
                'rule_key' => $ruleKey,
                'date' => $date,
            ]
        ) ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCorporationTaxCatalogHtml(string $html, string $sourceUrl = self::CORPORATION_TAX_SOURCE_URL, ?string $checkedAt = null): array
    {
        $dom = $this->dom($html);
        $xpath = new \DOMXPath($dom);
        $sourceUpdatedAt = $this->extractSourceUpdatedAt($html);
        $checkedAt = $this->checkedAt($checkedAt);
        $rules = [];

        $standardTable = $this->findTable($xpath, ['small profits rate', 'main rate', 'standard fraction']);
        if ($standardTable !== null) {
            $grid = $this->tableGrid($standardTable);
            $header = (array)($grid[0] ?? []);
            $years = $this->yearsByColumn($header);
            $specialRow = $this->findRow($grid, 'special rate for unit trusts');

            foreach ($years as $columnIndex => $year) {
                $rate = $this->parsePercent($specialRow[$columnIndex] ?? null);
                if ($rate === null) {
                    continue;
                }

                $start = sprintf('%04d-04-01', $year);
                $end = sprintf('%04d-03-31', $year + 1);
                $rules[] = $this->catalogRule(
                    'corporation_tax',
                    'special_unit_trust_oeic',
                    'special_rate',
                    'Special rate for unit trusts and open-ended investment companies',
                    $start,
                    $end,
                    'rate',
                    $rate,
                    null,
                    null,
                    $sourceUrl,
                    $sourceUpdatedAt,
                    $checkedAt,
                    'Parsed from GOV.UK Corporation Tax rates and allowances.'
                );
            }
        }

        $ringFenceTable = $this->findTable($xpath, ['small ring fence profits rate', 'ring fence fraction']);
        if ($ringFenceTable === null) {
            return $rules;
        }

        $grid = $this->tableGrid($ringFenceTable);
        $header = (array)($grid[0] ?? []);
        $periods = $this->periodsByColumn($header);
        foreach (array_slice($grid, 1) as $row) {
            $label = strtolower((string)($row[0] ?? ''));
            foreach ($periods as $columnIndex => $period) {
                [$periodStart, $periodEnd] = $period;
                if ($periodStart === '') {
                    continue;
                }

                if (str_contains($label, 'ring fence fraction')) {
                    $fraction = $this->parseFraction($row[$columnIndex] ?? null);
                    if ($fraction !== null) {
                        $rules[] = $this->catalogRule('corporation_tax', 'ring_fence', 'ring_fence_fraction', 'Ring fence fraction', $periodStart, $periodEnd, 'fraction', null, null, $fraction, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK ring fence Corporation Tax rates.');
                    }
                    continue;
                }

                $rate = $this->parsePercent($row[$columnIndex] ?? null);
                if ($rate === null) {
                    continue;
                }

                if (str_contains($label, 'small ring fence profits rate')) {
                    $rules[] = $this->catalogRule('corporation_tax', 'ring_fence', 'small_profits_rate', $this->normaliseText((string)($row[0] ?? 'Small ring fence profits rate')), $periodStart, $periodEnd, 'rate', $rate, null, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK ring fence Corporation Tax rates.');
                } elseif (str_contains($label, 'main ring fence profits rate') || str_contains($label, 'main rate ring fence')) {
                    $rules[] = $this->catalogRule('corporation_tax', 'ring_fence', 'main_rate', $this->normaliseText((string)($row[0] ?? 'Main ring fence rate')), $periodStart, $periodEnd, 'rate', $rate, null, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK ring fence Corporation Tax rates.');
                }
            }
        }

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCapitalAllowanceWdaHtml(string $html, string $sourceUrl = self::CAPITAL_ALLOWANCE_WDA_SOURCE_URL, ?string $checkedAt = null): array
    {
        $text = $this->normaliseText(strip_tags($html));
        $sourceUpdatedAt = $this->extractSourceUpdatedAt($html);
        $checkedAt = $this->checkedAt($checkedAt);
        $rules = [];

        if (
            preg_match('/main pool with a rate of ([0-9]+(?:\.[0-9]+)?)%\s+from\s+April\s+(\d{4}),\s+and\s+([0-9]+(?:\.[0-9]+)?)%\s+before/i', $text, $mainMatches) === 1
            && preg_match('/(\d{1,2}\s+[A-Za-z]+\s+\d{4})\s+for\s+Corporation\s+Tax/i', $text, $dateMatches) === 1
        ) {
            $changeDate = $this->parseDate($dateMatches[1]);
            if ($changeDate !== null) {
                $beforeEnd = $changeDate->modify('-1 day')->format('Y-m-d');
                $fromStart = $changeDate->format('Y-m-d');
                $rules[] = $this->catalogRule('capital_allowances', 'plant_machinery', 'main_pool_wda', 'Main pool writing down allowance', '1900-01-01', $beforeEnd, 'rate', round(((float)$mainMatches[3]) / 100, 6), null, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK writing down allowance rates and pools.');
                $rules[] = $this->catalogRule('capital_allowances', 'plant_machinery', 'main_pool_wda', 'Main pool writing down allowance', $fromStart, null, 'rate', round(((float)$mainMatches[1]) / 100, 6), null, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK writing down allowance rates and pools.');
            }
        }

        if (preg_match('/special rate pool with a rate of ([0-9]+(?:\.[0-9]+)?)%/i', $text, $specialMatches) === 1) {
            $rules[] = $this->catalogRule('capital_allowances', 'plant_machinery', 'special_rate_pool_wda', 'Special rate pool writing down allowance', '1900-01-01', null, 'rate', round(((float)$specialMatches[1]) / 100, 6), null, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK writing down allowance rates and pools.');
        }

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseAnnualInvestmentAllowanceHtml(string $html, string $sourceUrl = self::CAPITAL_ALLOWANCE_AIA_SOURCE_URL, ?string $checkedAt = null): array
    {
        $dom = $this->dom($html);
        $xpath = new \DOMXPath($dom);
        $sourceUpdatedAt = $this->extractSourceUpdatedAt($html);
        $checkedAt = $this->checkedAt($checkedAt);
        $table = $this->findTable($xpath, ['AIA', 'Limited companies']);
        if ($table === null) {
            return [];
        }

        $rules = [];
        foreach (array_slice($this->tableGrid($table), 1) as $row) {
            $amount = $this->parseMoney($row[0] ?? null);
            $companyPeriod = $this->periodForText((string)($row[2] ?? $row[1] ?? ''));
            if ($amount === null || $companyPeriod === null) {
                continue;
            }

            [$periodStart, $periodEnd] = $companyPeriod;
            $rules[] = $this->catalogRule('capital_allowances', 'plant_machinery', 'aia_annual_limit', 'Annual investment allowance limit', $periodStart, $periodEnd, 'amount', null, $amount, null, $sourceUrl, $sourceUpdatedAt, $checkedAt, 'Parsed from GOV.UK annual investment allowance rates.');
        }

        return $rules;
    }

    public function weightedRateForPeriod(string $taxDomain, string $regime, string $ruleKey, string $periodStart, string $periodEnd): float
    {
        return $this->weightedValueForPeriod($taxDomain, $regime, $ruleKey, $periodStart, $periodEnd, 'rate_value');
    }

    public function weightedAmountForPeriod(string $taxDomain, string $regime, string $ruleKey, string $periodStart, string $periodEnd): float
    {
        return $this->weightedValueForPeriod($taxDomain, $regime, $ruleKey, $periodStart, $periodEnd, 'amount_value');
    }

    private function weightedValueForPeriod(string $taxDomain, string $regime, string $ruleKey, string $periodStart, string $periodEnd, string $valueColumn): float
    {
        $this->ensureSchema();
        $start = new \DateTimeImmutable($periodStart);
        $end = new \DateTimeImmutable($periodEnd);
        if ($start > $end) {
            throw new \RuntimeException('Tax rate lookup period start must be on or before the period end.');
        }

        $totalDays = $this->inclusiveDays($start, $end);
        $coveredDays = 0;
        $weightedValue = 0.0;

        foreach ($this->fetchActiveRulesForPeriod($taxDomain, $regime, $ruleKey, $periodStart, $periodEnd) as $rule) {
            if ($rule[$valueColumn] === null || trim((string)$rule[$valueColumn]) === '') {
                continue;
            }

            $ruleStart = new \DateTimeImmutable((string)$rule['period_start']);
            $ruleEnd = new \DateTimeImmutable((string)$rule['period_end']);
            $overlapStart = $ruleStart > $start ? $ruleStart : $start;
            $overlapEnd = $ruleEnd < $end ? $ruleEnd : $end;
            if ($overlapStart > $overlapEnd) {
                continue;
            }

            $days = $this->inclusiveDays($overlapStart, $overlapEnd);
            $coveredDays += $days;
            $weightedValue += ((float)$rule[$valueColumn]) * $days;
        }

        if ($coveredDays < $totalDays) {
            throw new \RuntimeException('No complete active sourced tax rate rule was found for ' . $taxDomain . '/' . $regime . '/' . $ruleKey . ' covering ' . $periodStart . ' to ' . $periodEnd . '.');
        }

        return round($weightedValue / $totalDays, 6);
    }

    private function fetchActiveRulesForPeriod(string $taxDomain, string $regime, string $ruleKey, string $periodStart, string $periodEnd): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM tax_rate_rules
             WHERE tax_domain = :tax_domain
               AND regime = :regime
               AND rule_key = :rule_key
               AND is_active = 1
               AND period_start <= :period_end
               AND period_end >= :period_start
             ORDER BY period_start ASC, source_checked_at DESC, id DESC',
            [
                'tax_domain' => $taxDomain,
                'regime' => $regime,
                'rule_key' => $ruleKey,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]
        ) ?: [];
    }

    private function fetchCatalogRows(): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM tax_rate_rules
             ORDER BY period_start DESC, tax_domain ASC, regime ASC, rule_key ASC, source_checked_at DESC, id DESC'
        ) ?: [];
    }

    private function displayCorporationTaxRule(array $row): array
    {
        $parts = ['Main ' . $this->percent($row['main_rate'] ?? null)];
        if ($row['small_profits_rate'] !== null && trim((string)$row['small_profits_rate']) !== '') {
            $parts[] = 'Small profits ' . $this->percent($row['small_profits_rate']);
        }
        if ($row['lower_limit'] !== null && trim((string)$row['lower_limit']) !== '') {
            $parts[] = 'Lower ' . $this->money($row['lower_limit']);
        }
        if ($row['upper_limit'] !== null && trim((string)$row['upper_limit']) !== '') {
            $parts[] = 'Upper ' . $this->money($row['upper_limit']);
        }
        if ($row['marginal_relief_fraction'] !== null && trim((string)$row['marginal_relief_fraction']) !== '') {
            $parts[] = 'MR ' . number_format((float)$row['marginal_relief_fraction'], 6);
        }

        return [
            'source_table' => 'corporation_tax_rate_rules',
            'domain_label' => 'Corporation Tax',
            'regime_label' => 'Non-ring-fence',
            'rule_label' => 'Rate bands and marginal relief',
            'period_start' => (string)($row['financial_year_start'] ?? ''),
            'period_end' => (string)($row['financial_year_end'] ?? ''),
            'value_summary' => implode('; ', $parts),
            'source_url' => (string)($row['source_url'] ?? ''),
            'source_updated_at' => (string)($row['source_updated_at'] ?? ''),
            'source_checked_at' => (string)($row['source_checked_at'] ?? ''),
            'rule_version' => (string)($row['rule_version'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'notes' => (string)($row['notes'] ?? ''),
        ];
    }

    private function displayCatalogRule(array $row): array
    {
        return [
            'source_table' => 'tax_rate_rules',
            'domain_label' => $this->domainLabel((string)($row['tax_domain'] ?? '')),
            'regime_label' => $this->regimeLabel((string)($row['regime'] ?? '')),
            'rule_label' => (string)($row['rule_label'] ?? ''),
            'period_start' => (string)($row['period_start'] ?? ''),
            'period_end' => (string)($row['period_end'] ?? '') === '9999-12-31' ? '' : (string)($row['period_end'] ?? ''),
            'value_summary' => $this->valueSummary($row),
            'source_url' => (string)($row['source_url'] ?? ''),
            'source_updated_at' => (string)($row['source_updated_at'] ?? ''),
            'source_checked_at' => (string)($row['source_checked_at'] ?? ''),
            'rule_version' => (string)($row['rule_version'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0),
            'notes' => (string)($row['notes'] ?? ''),
        ];
    }

    private function valueSummary(array $row): string
    {
        return match ((string)($row['value_type'] ?? '')) {
            'rate' => $this->percent($row['rate_value'] ?? null),
            'amount' => $this->money($row['amount_value'] ?? null),
            'fraction' => number_format((float)($row['fraction_value'] ?? 0), 6),
            default => '-',
        };
    }

    private function catalogRule(
        string $taxDomain,
        string $regime,
        string $ruleKey,
        string $ruleLabel,
        string $periodStart,
        ?string $periodEnd,
        string $valueType,
        ?float $rateValue,
        ?float $amountValue,
        ?float $fractionValue,
        string $sourceUrl,
        ?string $sourceUpdatedAt,
        string $sourceCheckedAt,
        string $notes
    ): array {
        $rule = [
            'tax_domain' => $taxDomain,
            'regime' => $regime,
            'rule_key' => $ruleKey,
            'rule_label' => $ruleLabel,
            'period_start' => $periodStart,
            'period_end' => $periodEnd ?? '9999-12-31',
            'value_type' => $valueType,
            'rate_value' => $rateValue,
            'amount_value' => $amountValue,
            'fraction_value' => $fractionValue,
            'source_url' => $sourceUrl,
            'source_updated_at' => $sourceUpdatedAt,
            'source_checked_at' => $sourceCheckedAt,
            'is_active' => 1,
            'notes' => $notes,
        ];
        $rule['rule_version'] = $this->ruleVersion($rule);

        return $rule;
    }

    private function ruleVersion(array $rule): string
    {
        $hash = hash('sha256', json_encode([
            'tax_domain' => (string)$rule['tax_domain'],
            'regime' => (string)$rule['regime'],
            'rule_key' => (string)$rule['rule_key'],
            'period_start' => (string)$rule['period_start'],
            'period_end' => $rule['period_end'],
            'value_type' => (string)$rule['value_type'],
            'rate_value' => $rule['rate_value'],
            'amount_value' => $rule['amount_value'],
            'fraction_value' => $rule['fraction_value'],
            'source_url' => (string)$rule['source_url'],
        ], JSON_UNESCAPED_SLASHES));

        return 'govuk-' . substr($hash, 0, 16);
    }

    private function deactivateSupersededRules(array $rule): void
    {
        \InterfaceDB::prepareExecute(
            'UPDATE tax_rate_rules
             SET is_active = 0,
                 updated_at = CURRENT_TIMESTAMP
             WHERE tax_domain = :tax_domain
               AND regime = :regime
               AND rule_key = :rule_key
               AND period_start = :period_start
               AND period_end = :period_end
               AND rule_version <> :rule_version',
            [
                'tax_domain' => (string)$rule['tax_domain'],
                'regime' => (string)$rule['regime'],
                'rule_key' => (string)$rule['rule_key'],
                'period_start' => (string)$rule['period_start'],
                'period_end' => $rule['period_end'],
                'rule_version' => (string)$rule['rule_version'],
            ]
        );
    }

    private function upsertRule(array $rule): void
    {
        $sql = 'INSERT INTO tax_rate_rules (
                tax_domain,
                regime,
                rule_key,
                rule_label,
                period_start,
                period_end,
                value_type,
                rate_value,
                amount_value,
                fraction_value,
                source_url,
                source_updated_at,
                source_checked_at,
                rule_version,
                is_active,
                notes,
                created_at,
                updated_at
            ) VALUES (
                :tax_domain,
                :regime,
                :rule_key,
                :rule_label,
                :period_start,
                :period_end,
                :value_type,
                :rate_value,
                :amount_value,
                :fraction_value,
                :source_url,
                :source_updated_at,
                :source_checked_at,
                :rule_version,
                :is_active,
                :notes,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )';

        $sql .= \InterfaceDB::driverName() === 'sqlite'
            ? ' ON CONFLICT(tax_domain, regime, rule_key, period_start, period_end, rule_version) DO UPDATE SET
                rule_label = excluded.rule_label,
                value_type = excluded.value_type,
                rate_value = excluded.rate_value,
                amount_value = excluded.amount_value,
                fraction_value = excluded.fraction_value,
                source_url = excluded.source_url,
                source_updated_at = excluded.source_updated_at,
                source_checked_at = excluded.source_checked_at,
                is_active = excluded.is_active,
                notes = excluded.notes,
                updated_at = CURRENT_TIMESTAMP'
            : ' ON DUPLICATE KEY UPDATE
                rule_label = VALUES(rule_label),
                value_type = VALUES(value_type),
                rate_value = VALUES(rate_value),
                amount_value = VALUES(amount_value),
                fraction_value = VALUES(fraction_value),
                source_url = VALUES(source_url),
                source_updated_at = VALUES(source_updated_at),
                source_checked_at = VALUES(source_checked_at),
                is_active = VALUES(is_active),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP';

        \InterfaceDB::prepareExecute($sql, [
            'tax_domain' => (string)$rule['tax_domain'],
            'regime' => (string)$rule['regime'],
            'rule_key' => (string)$rule['rule_key'],
            'rule_label' => (string)$rule['rule_label'],
            'period_start' => (string)$rule['period_start'],
            'period_end' => $rule['period_end'],
            'value_type' => (string)$rule['value_type'],
            'rate_value' => $rule['rate_value'],
            'amount_value' => $rule['amount_value'],
            'fraction_value' => $rule['fraction_value'],
            'source_url' => (string)$rule['source_url'],
            'source_updated_at' => $rule['source_updated_at'],
            'source_checked_at' => (string)$rule['source_checked_at'],
            'rule_version' => (string)$rule['rule_version'],
            'is_active' => (int)$rule['is_active'],
            'notes' => (string)$rule['notes'],
        ]);
    }

    private function fetchSourceHtml(string $url): string
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP cURL is required to refresh HMRC tax rates.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Could not initialise HMRC tax rates download.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'EEL Accounts HMRC tax rates refresh',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);

        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            throw new \RuntimeException('HMRC tax rates download failed' . ($error !== '' ? ': ' . $error : ' with HTTP status ' . $status) . '.');
        }

        return $body;
    }

    private function dom(string $html): \DOMDocument
    {
        $html = trim($html);
        if ($html === '') {
            throw new \RuntimeException('The HMRC tax rates page returned an empty response.');
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private function findTable(\DOMXPath $xpath, array $needles): ?\DOMElement
    {
        foreach ($xpath->query('//table') ?: [] as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }

            $text = strtolower($this->normaliseText($table->textContent));
            $matched = true;
            foreach ($needles as $needle) {
                if (!str_contains($text, strtolower((string)$needle))) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
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

    private function yearsByColumn(array $header): array
    {
        $years = [];
        for ($index = 1, $count = count($header); $index < $count; $index++) {
            if (preg_match('/\b(20\d{2})\b/', (string)$header[$index], $matches) === 1) {
                $years[$index] = (int)$matches[1];
            }
        }

        return $years;
    }

    private function periodsByColumn(array $header): array
    {
        $periods = [];
        for ($index = 1, $count = count($header); $index < $count; $index++) {
            $period = $this->periodForText((string)$header[$index]);
            if ($period !== null) {
                $periods[$index] = $period;
            }
        }

        return $periods;
    }

    private function periodForText(string $text): ?array
    {
        $text = $this->normaliseText($text);
        if (preg_match('/From\s+(.+)$/i', $text, $matches) === 1) {
            $date = $this->parseDate($matches[1]);
            return $date instanceof \DateTimeImmutable ? [$date->format('Y-m-d'), null] : null;
        }

        if (preg_match('/(.+?)\s+-\s+(.+)$/', $text, $matches) === 1) {
            $start = $this->parseDate($matches[1]);
            $end = $this->parseDate($matches[2]);
            return $start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable
                ? [$start->format('Y-m-d'), $end->format('Y-m-d')]
                : null;
        }

        if (preg_match('/\b(20\d{2})\s+to\s+(20\d{2})\b/', $text, $matches) === 1) {
            $startYear = (int)$matches[1];
            $endYear = (int)$matches[2];
            return [sprintf('%04d-04-01', $startYear), sprintf('%04d-03-31', $endYear + 1)];
        }

        if (preg_match('/\b(20\d{2})\b/', $text, $matches) === 1) {
            $year = (int)$matches[1];
            return [sprintf('%04d-04-01', $year), sprintf('%04d-03-31', $year + 1)];
        }

        return null;
    }

    private function parseDate(string $text): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->normaliseText($text));
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractSourceUpdatedAt(string $html): ?string
    {
        if (preg_match('/Updated\s+(\d{1,2}\s+[A-Za-z]+\s+\d{4})/i', $html, $matches) !== 1) {
            return null;
        }

        $date = $this->parseDate($matches[1]);

        return $date instanceof \DateTimeImmutable ? $date->format('Y-m-d') : null;
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
        $text = strtolower($this->normaliseText((string)$value));
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }

        $multiplier = str_contains($text, 'million') ? 1000000 : 1;
        $number = preg_replace('/[^0-9.]/', '', $text);
        if (!is_string($number) || $number === '') {
            return null;
        }

        return round(((float)$number) * $multiplier, 2);
    }

    private function thresholdAmount(string $text, string $pattern): ?float
    {
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        return $this->parseMoney($matches[1] ?? null);
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

    private function percent(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '-';
        }

        return number_format(((float)$value) * 100, 2) . '%';
    }

    private function money(mixed $value): string
    {
        if ($value === null || trim((string)$value) === '') {
            return '-';
        }

        return 'GBP ' . number_format((float)$value, 2);
    }

    private function domainLabel(string $domain): string
    {
        return match ($domain) {
            'corporation_tax' => 'Corporation Tax',
            'capital_allowances' => 'Capital Allowances',
            'company_size' => 'Company Size / FRS 105',
            default => ucwords(str_replace('_', ' ', $domain)),
        };
    }

    private function regimeLabel(string $regime): string
    {
        return match ($regime) {
            'non_ring_fence' => 'Non-ring-fence',
            'ring_fence' => 'Ring fence',
            'special_unit_trust_oeic' => 'Unit trust/OEIC',
            'plant_machinery' => 'Plant and machinery',
            'frs105_micro_entity' => 'FRS 105 micro-entity',
            default => ucwords(str_replace('_', ' ', $regime)),
        };
    }

    private function checkedAt(?string $checkedAt): string
    {
        return $checkedAt !== null && trim($checkedAt) !== ''
            ? trim($checkedAt)
            : (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    private function inclusiveDays(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return (int)$start->diff($end)->days + 1;
    }
}
