<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class IxbrlRenderService
{
    private const TAXONOMY_PROFILE = 'uk-frs-105-micro-entities-review-export-v1';
    private const TAXONOMY_SCHEMA_REF = 'https://xbrl.frc.org.uk/FRS-105/2026-01-01/frs-105-2026-01-01.xsd';

    public function generatePreview(int $companyId, int $accountingPeriodId): array
    {
        return $this->generateFilingExport($companyId, $accountingPeriodId);
    }

    public function generateFilingExport(int $companyId, int $accountingPeriodId): array
    {
        $builder = new IxbrlFactBuilderService();
        $builder->ensureSchema();
        $run = $builder->getLatestRun($companyId, $accountingPeriodId);
        if (!is_array($run) || (int)($run['fact_count'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['Build iXBRL facts before generating the preview file.']];
        }

        try {
            $facts = $builder->getFacts((int)$run['id']);
            $xhtml = $this->renderXhtml($facts);
            $validationErrors = $this->validateInlineXbrl($xhtml);
            if ($validationErrors !== []) {
                throw new RuntimeException('Generated iXBRL did not pass internal structural validation: ' . implode(' ', $validationErrors));
            }
            $directory = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'ixbrl';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create outbound iXBRL directory.');
            }

            $filename = 'accounts_ixbrl_' . $companyId . '_' . $accountingPeriodId . '_' . (int)$run['id'] . '.xhtml';
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($path, $xhtml) === false) {
                throw new RuntimeException('Could not write generated iXBRL export file.');
            }

            $hash = hash_file('sha256', $path);
            InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     export_type = :export_type,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :validation_errors_json,
                     generated_filename = :filename,
                     generated_path = :path,
                     output_sha256 = :sha,
                     generated_at = CURRENT_TIMESTAMP,
                     error_message = NULL
                 WHERE id = :id',
                [
                    'status' => 'generated',
                    'export_type' => 'filing_export',
                    'taxonomy_profile' => self::TAXONOMY_PROFILE,
                    'validation_status' => 'passed',
                    'validation_errors_json' => json_encode([], JSON_UNESCAPED_SLASHES),
                    'filename' => $filename,
                    'path' => $path,
                    'sha' => $hash,
                    'id' => (int)$run['id'],
                ]
            );

            return ['success' => true, 'errors' => [], 'filename' => $filename, 'path' => $path, 'sha256' => $hash];
        } catch (Throwable $exception) {
            InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     export_type = :export_type,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :validation_errors_json,
                     error_message = :error_message
                 WHERE id = :id',
                [
                    'status' => 'failed',
                    'export_type' => 'filing_export',
                    'taxonomy_profile' => self::TAXONOMY_PROFILE,
                    'validation_status' => 'failed',
                    'validation_errors_json' => json_encode([$exception->getMessage()], JSON_UNESCAPED_SLASHES),
                    'error_message' => $exception->getMessage(),
                    'id' => (int)$run['id'],
                ]
            );

            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function renderXhtml(array $facts): string
    {
        $byKey = [];
        foreach ($facts as $fact) {
            $byKey[(string)$fact['fact_key']] = $fact;
        }

        $companyName = $this->factValue($byKey['entity_name'] ?? []);
        $companyNumber = $this->factValue($byKey['company_number'] ?? []);
        $periodStart = $this->factValue($byKey['period_start'] ?? []);
        $periodEnd = $this->factValue($byKey['period_end'] ?? []);
        $rows = [
            'Fixed assets' => 'fixed_assets',
            'Current assets' => 'current_assets',
            'Creditors: amounts falling due within one year' => 'creditors_within_one_year',
            'Net current assets / liabilities' => 'net_current_assets_liabilities',
            'Total assets less current liabilities' => 'total_assets_less_current_liabilities',
            'Creditors: amounts falling due after more than one year' => 'creditors_after_one_year',
            'Net assets / liabilities' => 'net_assets_liabilities',
            'Capital and reserves' => 'equity',
        ];

        $bodyRows = '';
        foreach ($rows as $label => $key) {
            $bodyRows .= '<tr><th>' . $this->e($label) . '</th><td>' . $this->inlineFact($byKey[$key] ?? []) . '</td></tr>' . "\n";
        }

        $statements = '';
        foreach (['micro_entity_statement', 'audit_exemption_statement', 'directors_responsibility_statement', 'members_no_audit_statement', 'production_software'] as $key) {
            if (isset($byKey[$key])) {
                $statements .= '<p>' . $this->inlineFact($byKey[$key]) . '</p>' . "\n";
            }
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:ix="http://www.xbrl.org/2013/inlineXBRL" xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2015-02-26" xmlns:xbrli="http://www.xbrl.org/2003/instance" xmlns:link="http://www.xbrl.org/2003/linkbase" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:iso4217="http://www.xbrl.org/2003/iso4217" xmlns:uk-bus="http://xbrl.frc.org.uk/cd/2026-01-01/business" xmlns:uk-gaap="http://xbrl.frc.org.uk/fr/2026-01-01/uk-gaap" lang="en">' . "\n"
            . '<head><meta charset="utf-8"/><title>FRS 105 unaudited micro-entity accounts iXBRL export</title></head>' . "\n"
            . '<body>' . "\n"
            . $this->inlineHeader($companyNumber, $periodStart, $periodEnd, $byKey)
            . '<!-- Generated FRS 105 micro-entity accounts iXBRL export. Review and validate before filing. -->' . "\n"
            . '<h1>FRS 105 unaudited micro-entity accounts</h1>' . "\n"
            . '<section><h2>' . $this->e($companyName) . '</h2><p>Company number: ' . $this->e($companyNumber) . '</p><p>Period: ' . $this->e($periodStart) . ' to ' . $this->e($periodEnd) . '</p></section>' . "\n"
            . '<section><h2>Balance sheet</h2><table><tbody>' . "\n"
            . $bodyRows
            . '</tbody></table></section>' . "\n"
            . '<section><h2>Statements</h2>' . "\n"
            . $statements
            . '<p>Generated by eel.</p></section>' . "\n"
            . '</body></html>' . "\n";
    }

    private function inlineFact(array $fact): string
    {
        if ($fact === []) {
            return '';
        }

        $name = $this->e((string)$fact['taxonomy_concept']);
        $context = $this->e((string)$fact['context_ref']);
        $value = $this->e($this->factValue($fact));
        $type = (string)($fact['value_type'] ?? 'text');

        if ($type === 'numeric') {
            return '<ix:nonFraction name="' . $name . '" contextRef="' . $context . '" unitRef="GBP" decimals="' . $this->e((string)($fact['decimals_value'] ?? '2')) . '" scale="0" format="ixt:numdotdecimal">' . $value . '</ix:nonFraction>';
        }

        $format = $type === 'date' ? ' format="ixt:dateyearmonthday"' : '';

        return '<ix:nonNumeric name="' . $name . '" contextRef="' . $context . '"' . $format . '>' . $value . '</ix:nonNumeric>';
    }

    private function factValue(array $fact): string
    {
        return match ((string)($fact['value_type'] ?? 'text')) {
            'numeric' => number_format((float)($fact['numeric_value'] ?? 0), 2, '.', ''),
            'date' => (string)($fact['date_value'] ?? ''),
            default => (string)($fact['text_value'] ?? ''),
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function inlineHeader(string $companyNumber, string $periodStart, string $periodEnd, array $factsByKey): string
    {
        $companyNumber = $companyNumber !== '' ? $companyNumber : 'UNKNOWN';
        $periodStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart) === 1 ? $periodStart : '1970-01-01';
        $periodEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodEnd) === 1 ? $periodEnd : $periodStart;
        $hiddenFacts = '';
        foreach (['entity_name', 'company_number', 'period_start', 'period_end', 'balance_sheet_date', 'average_number_employees', 'dormant_false', 'production_software'] as $key) {
            if (isset($factsByKey[$key])) {
                $hiddenFacts .= $this->inlineFact((array)$factsByKey[$key]) . "\n";
            }
        }

        return '<ix:header>' . "\n"
            . '<ix:references><link:schemaRef xlink:type="simple" xlink:href="' . $this->e(self::TAXONOMY_SCHEMA_REF) . '"/></ix:references>' . "\n"
            . ($hiddenFacts !== '' ? '<ix:hidden>' . "\n" . $hiddenFacts . '</ix:hidden>' . "\n" : '')
            . '<ix:resources>' . "\n"
            . '<xbrli:context id="entity"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">' . $this->e($companyNumber) . '</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>' . $this->e($periodEnd) . '</xbrli:instant></xbrli:period></xbrli:context>' . "\n"
            . '<xbrli:context id="current_period_duration"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">' . $this->e($companyNumber) . '</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:startDate>' . $this->e($periodStart) . '</xbrli:startDate><xbrli:endDate>' . $this->e($periodEnd) . '</xbrli:endDate></xbrli:period></xbrli:context>' . "\n"
            . '<xbrli:context id="current_period_end"><xbrli:entity><xbrli:identifier scheme="http://www.companieshouse.gov.uk/">' . $this->e($companyNumber) . '</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>' . $this->e($periodEnd) . '</xbrli:instant></xbrli:period></xbrli:context>' . "\n"
            . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>' . "\n"
            . '</ix:resources>' . "\n"
            . '</ix:header>' . "\n";
    }

    private function validateInlineXbrl(string $xhtml): array
    {
        $errors = [];
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xhtml, LIBXML_NONET);
        $xmlErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return ['Generated XHTML is not well-formed XML.'];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');

        if ($xmlErrors !== []) {
            $errors[] = 'XML parser reported ' . count($xmlErrors) . ' issue(s).';
        }
        if (($xpath->query('//ix:header')->length ?? 0) < 1) {
            $errors[] = 'Inline XBRL header is missing.';
        }
        if (($xpath->query('//link:schemaRef')->length ?? 0) < 1) {
            $errors[] = 'Taxonomy schema reference is missing.';
        }
        if (($xpath->query('//xbrli:context')->length ?? 0) < 2) {
            $errors[] = 'Required XBRL contexts are missing.';
        }
        if (($xpath->query('//xbrli:unit[@id="GBP"]')->length ?? 0) < 1) {
            $errors[] = 'GBP unit is missing.';
        }
        if (($xpath->query('//ix:nonFraction | //ix:nonNumeric')->length ?? 0) < 1) {
            $errors[] = 'No inline facts were generated.';
        }
        if (str_contains($xhtml, 'data-ixbrl-concept')) {
            $errors[] = 'Legacy preview fact markers are still present.';
        }

        return $errors;
    }
}
