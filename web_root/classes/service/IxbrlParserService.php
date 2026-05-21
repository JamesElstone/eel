<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlParserService
{
    public function parse(string $xhtml): array {
        $xhtml = $this->ensureUtf8($xhtml);

        if (trim($xhtml) === '') {
            throw new RuntimeException('The XHTML/iXBRL content is empty.');
        }

        $dom = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($loaded !== true) {
            throw new RuntimeException('The XHTML/iXBRL content could not be parsed as XML.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');

        $contextsByRef = $this->parseContexts($xpath);
        $latestReportingEndDate = $this->detectLatestReportingEndDate($contextsByRef);

        // Latest-year context detection is driven by the actual context dates in the filing.
        // We deliberately avoid guessing from context ids like icur/iprev because the ids are not reliable semantics.
        foreach ($contextsByRef as $contextRef => $context) {
            $contextsByRef[$contextRef]['is_latest_year_context'] = $latestReportingEndDate !== null
                && (
                    ($context['instant_date'] !== null && $context['instant_date'] === $latestReportingEndDate)
                    || ($context['period_end'] !== null && $context['period_end'] === $latestReportingEndDate)
                );
        }

        $facts = $this->parseFacts($xpath, $contextsByRef);
        $summary = $this->buildSummary($contextsByRef, $facts, $latestReportingEndDate);

        return [
            'latest_reporting_end_date' => $latestReportingEndDate,
            'contexts' => array_values($contextsByRef),
            'facts' => $facts,
            'summary' => $summary,
        ];
    }

    private function parseContexts(DOMXPath $xpath): array {
        $contextsByRef = [];
        $contextNodes = $xpath->query('//xbrli:context');

        if (!$contextNodes instanceof DOMNodeList) {
            return [];
        }

        /** @var DOMElement $contextNode */
        foreach ($contextNodes as $contextNode) {
            $contextRef = trim($contextNode->getAttribute('id'));

            if ($contextRef === '') {
                continue;
            }

            $periodStart = $this->firstNodeValue($xpath, './xbrli:period/xbrli:startDate', $contextNode);
            $periodEnd = $this->firstNodeValue($xpath, './xbrli:period/xbrli:endDate', $contextNode);
            $instantDate = $this->firstNodeValue($xpath, './xbrli:period/xbrli:instant', $contextNode);

            // Dimension/member parsing is kept explicit so we can preserve the original XBRL axis/member pairing.
            // Those dimensions are important for facts such as creditors within one year vs after more than one year.
            $dimensions = [];
            $dimensionNodes = $xpath->query('.//xbrldi:explicitMember', $contextNode);

            if ($dimensionNodes instanceof DOMNodeList) {
                /** @var DOMElement $dimensionNode */
                foreach ($dimensionNodes as $dimensionNode) {
                    $dimensions[] = [
                        'dimension' => trim($dimensionNode->getAttribute('dimension')),
                        'member' => trim($dimensionNode->textContent),
                    ];
                }
            }

            usort($dimensions, static function (array $left, array $right): int {
                $leftKey = ($left['dimension'] ?? '') . '|' . ($left['member'] ?? '');
                $rightKey = ($right['dimension'] ?? '') . '|' . ($right['member'] ?? '');

                return strcmp($leftKey, $rightKey);
            });

            $contextsByRef[$contextRef] = [
                'context_ref' => $contextRef,
                'period_start' => $this->normaliseDateString($periodStart),
                'period_end' => $this->normaliseDateString($periodEnd),
                'instant_date' => $this->normaliseDateString($instantDate),
                'dimensions' => $dimensions,
                'dimension_json' => $dimensions === [] ? null : $this->encodeJson($dimensions),
                'is_latest_year_context' => false,
            ];
        }

        return $contextsByRef;
    }

    private function detectLatestReportingEndDate(array $contextsByRef): ?string {
        $candidateDates = [];

        foreach ($contextsByRef as $context) {
            $periodEnd = $context['period_end'] ?? null;
            $instantDate = $context['instant_date'] ?? null;

            if (is_string($periodEnd) && $periodEnd !== '') {
                $candidateDates[] = $periodEnd;
            }

            if (is_string($instantDate) && $instantDate !== '') {
                $candidateDates[] = $instantDate;
            }
        }

        if ($candidateDates === []) {
            return null;
        }

        sort($candidateDates);

        return (string)end($candidateDates);
    }

    private function parseFacts(DOMXPath $xpath, array $contextsByRef): array {
        $facts = [];
        $seen = [];
        $factNodes = $xpath->query('//ix:nonFraction | //ix:nonNumeric');

        if (!$factNodes instanceof DOMNodeList) {
            return [];
        }

        /** @var DOMElement $factNode */
        foreach ($factNodes as $factNode) {
            $contextRef = trim($factNode->getAttribute('contextRef'));
            $conceptName = trim($factNode->getAttribute('name'));

            if ($contextRef === '' || $conceptName === '' || !isset($contextsByRef[$contextRef])) {
                continue;
            }

            $context = $contextsByRef[$contextRef];

            // Prior-year comparative facts are deliberately excluded from storage.
            // We only keep facts tied to contexts that match the latest reporting end date for this filing.
            if (empty($context['is_latest_year_context'])) {
                continue;
            }

            $rawValue = $this->normaliseWhitespace(html_entity_decode(trim($factNode->textContent), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($rawValue === '') {
                continue;
            }

            $isNumeric = strtolower($factNode->localName) === 'nonfraction';
            $signHint = $isNumeric ? $this->detectNumericSignHint($factNode, $rawValue) : null;
            $normalisedNumeric = $isNumeric ? $this->normaliseNumericValue($rawValue, $signHint) : null;
            $normalisedDate = !$isNumeric ? $this->normalisePossibleDateValue($rawValue) : null;
            $normalisedText = $isNumeric ? null : ($normalisedDate !== null ? null : $rawValue);
            $shortName = preg_replace('/^.*:/', '', $conceptName) ?? $conceptName;
            $friendlyLabel = $this->friendlyLabelForFact($shortName, is_array($context['dimensions'] ?? null) ? $context['dimensions'] : []);
            $conceptFriendlyLabel = $this->genericFriendlyLabelForConcept($shortName);
            $valueType = $isNumeric ? 'numeric' : ($normalisedDate !== null ? 'date' : 'text');
            $dedupeKey = implode('|', [
                $contextRef,
                $conceptName,
                $rawValue,
                (string)$factNode->getAttribute('unitRef'),
                (string)$factNode->getAttribute('decimals'),
            ]);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $facts[] = [
                'context_ref' => $contextRef,
                'concept_name' => $conceptName,
                'short_name' => $shortName,
                'friendly_label' => $friendlyLabel,
                'concept_friendly_label' => $conceptFriendlyLabel,
                'value_type' => $valueType,
                'fact_name' => $friendlyLabel,
                'raw_value' => $rawValue,
                'normalised_numeric' => $normalisedNumeric,
                'normalised_text' => $normalisedText,
                'normalised_date' => $normalisedDate,
                'unit_ref' => trim($factNode->getAttribute('unitRef')) ?: null,
                'decimals_value' => trim($factNode->getAttribute('decimals')) ?: null,
                'sign_hint' => $signHint,
                'is_numeric' => $isNumeric ? 1 : 0,
                'is_latest_year_fact' => 1,
            ];
        }

        usort($facts, static function (array $left, array $right): int {
            $leftLabel = (string)($left['friendly_label'] ?? $left['concept_name'] ?? '');
            $rightLabel = (string)($right['friendly_label'] ?? $right['concept_name'] ?? '');

            if ($leftLabel !== $rightLabel) {
                return strcmp($leftLabel, $rightLabel);
            }

            return strcmp((string)($left['context_ref'] ?? ''), (string)($right['context_ref'] ?? ''));
        });

        return $facts;
    }

    private function buildSummary(array $contextsByRef, array $facts, ?string $latestReportingEndDate): array {
        $latestContexts = array_values(array_filter($contextsByRef, static fn(array $context): bool => !empty($context['is_latest_year_context'])));
        $periodStart = $this->firstFactDateByShortName($facts, 'StartDateForPeriodCoveredByReport');
        $periodEnd = $this->firstFactDateByShortName($facts, 'EndDateForPeriodCoveredByReport') ?? $latestReportingEndDate;
        $balanceSheetDate = $this->firstFactDateByShortName($facts, 'BalanceSheetDate');

        if ($periodStart === null) {
            $durationStarts = array_values(array_filter(array_map(
                static fn(array $context): ?string => $context['period_start'] ?? null,
                $latestContexts
            )));

            sort($durationStarts);
            $periodStart = $durationStarts[0] ?? null;
        }

        return [
            'latest_year_period_start' => $periodStart,
            'latest_year_period_end' => $periodEnd,
            'balance_sheet_date' => $balanceSheetDate,
            'latest_year_context_count' => count($latestContexts),
            'latest_year_fact_count' => count($facts),
        ];
    }

    private function firstFactDateByShortName(array $facts, string $shortName): ?string {
        foreach ($facts as $fact) {
            if (($fact['short_name'] ?? '') === $shortName && isset($fact['normalised_date']) && $fact['normalised_date'] !== null) {
                return (string)$fact['normalised_date'];
            }
        }

        return null;
    }

    private function detectNumericSignHint(DOMElement $factNode, string $rawValue): ?string {
        $rawValue = trim($rawValue);

        if ($rawValue === '-') {
            return 'zero_dash';
        }

        if (preg_match('/^\(.*\)$/', $rawValue) === 1) {
            return 'inline_parentheses';
        }

        $previousText = $this->siblingText($factNode, true);
        $nextText = $this->siblingText($factNode, false);

        if (preg_match('/\(\s*$/', $previousText) === 1 && preg_match('/^\s*\)/', $nextText) === 1) {
            return 'presentation_parentheses';
        }

        return null;
    }

    private function siblingText(DOMNode $node, bool $previous, int $limit = 4): string {
        $parts = [];
        $current = $previous ? $node->previousSibling : $node->nextSibling;
        $steps = 0;

        while ($current !== null && $steps < $limit) {
            $text = trim($current->textContent ?? '');

            if ($text !== '') {
                if ($previous) {
                    array_unshift($parts, $text);
                } else {
                    $parts[] = $text;
                }
            }

            $current = $previous ? $current->previousSibling : $current->nextSibling;
            $steps++;
        }

        return trim(implode('', $parts));
    }

    private function normaliseNumericValue(string $rawValue, ?string $signHint): ?string {
        $value = trim($rawValue);

        if ($value === '-') {
            return '0';
        }

        if ($signHint === 'inline_parentheses' && preg_match('/^\(\s*([^)]+?)\s*\)$/', $value, $matches) === 1) {
            $value = '-' . trim((string)$matches[1]);
        }

        $value = str_replace(',', '', $value);

        if ($signHint === 'presentation_parentheses' && $value !== '' && $value[0] !== '-') {
            $value = '-' . ltrim($value, '+');
        }

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function normalisePossibleDateValue(string $rawValue): ?string {
        $rawValue = trim($rawValue);

        if ($rawValue === '') {
            return null;
        }

        $formats = [
            'Y-m-d',
            'j F Y',
            'd F Y',
            'j M Y',
            'd M Y',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $rawValue);

            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function friendlyLabelForFact(string $shortName, array $dimensions): ?string {
        $memberValues = array_map(
            static fn(array $dimension): string => (string)($dimension['member'] ?? ''),
            $dimensions
        );

        return match ($shortName) {
            'CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset' => 'Called up share capital not paid',
            'FixedAssets' => 'Fixed assets',
            'CurrentAssets' => 'Current assets',
            'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal' => 'Prepayments and accrued income',
            'Creditors' => in_array('uk-core:WithinOneYear', $memberValues, true)
                ? 'Creditors within one year'
                : (in_array('uk-core:AfterOneYear', $memberValues, true)
                    ? 'Creditors after more than one year'
                    : 'Creditors'),
            'NetCurrentAssetsLiabilities' => 'Net current assets/liabilities',
            'TotalAssetsLessCurrentLiabilities' => 'Total assets less current liabilities',
            'NetAssetsLiabilities' => 'Net assets/liabilities',
            'Equity' => 'Equity / capital and reserves',
            'BalanceSheetDate' => 'Balance sheet date',
            'DateAuthorisationFinancialStatementsForIssue' => 'Date authorisation financial statements for issue',
            'StartDateForPeriodCoveredByReport' => 'Start date for period covered by report',
            'EndDateForPeriodCoveredByReport' => 'End date for period covered by report',
            default => preg_replace('/(?<!^)([A-Z])/', ' $1', $shortName) ?: $shortName,
        };
    }

    private function genericFriendlyLabelForConcept(string $shortName): ?string {
        return match ($shortName) {
            'CalledUpShareCapitalNotPaidNotExpressedAsCurrentAsset' => 'Called up share capital not paid',
            'FixedAssets' => 'Fixed assets',
            'CurrentAssets' => 'Current assets',
            'PrepaymentsAccruedIncomeNotExpressedWithinCurrentAssetSubtotal' => 'Prepayments and accrued income',
            'Creditors' => 'Creditors',
            'NetCurrentAssetsLiabilities' => 'Net current assets/liabilities',
            'TotalAssetsLessCurrentLiabilities' => 'Total assets less current liabilities',
            'NetAssetsLiabilities' => 'Net assets/liabilities',
            'Equity' => 'Equity / capital and reserves',
            'BalanceSheetDate' => 'Balance sheet date',
            'DateAuthorisationFinancialStatementsForIssue' => 'Date authorisation financial statements for issue',
            'StartDateForPeriodCoveredByReport' => 'Start date for period covered by report',
            'EndDateForPeriodCoveredByReport' => 'End date for period covered by report',
            default => preg_replace('/(?<!^)([A-Z])/', ' $1', $shortName) ?: $shortName,
        };
    }

    private function firstNodeValue(DOMXPath $xpath, string $expression, DOMNode $contextNode): ?string {
        $nodes = $xpath->query($expression, $contextNode);

        if (!$nodes instanceof DOMNodeList || $nodes->length === 0) {
            return null;
        }

        return trim((string)$nodes->item(0)?->textContent);
    }

    private function normaliseWhitespace(string $value): string {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function normaliseDateString(?string $value): ?string {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        return HelperFramework::normaliseDate($value) ?? $this->normalisePossibleDateValue($value);
    }

    private function ensureUtf8(string $value): string {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }

        if (function_exists('iconv')) {
            $converted = iconv('Windows-1252', 'UTF-8//IGNORE', $value);

            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    private function encodeJson(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Unable to encode iXBRL dimensions as JSON.');
        }

        return $json;
    }
}
