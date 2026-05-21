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
    public function generatePreview(int $companyId, int $taxYearId): array
    {
        $builder = new IxbrlFactBuilderService();
        $builder->ensureSchema();
        $run = $builder->getLatestRun($companyId, $taxYearId);
        if (!is_array($run) || (int)($run['fact_count'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['Build iXBRL facts before generating the preview file.']];
        }

        try {
            $facts = $builder->getFacts((int)$run['id']);
            $xhtml = $this->renderXhtml($facts);
            $directory = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'ixbrl';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create outbound iXBRL directory.');
            }

            $filename = 'ixbrl_' . $companyId . '_' . $taxYearId . '_' . (int)$run['id'] . '.xhtml';
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($path, $xhtml) === false) {
                throw new RuntimeException('Could not write generated iXBRL preview file.');
            }

            $hash = hash_file('sha256', $path);
            InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     generated_filename = :filename,
                     generated_path = :path,
                     output_sha256 = :sha,
                     generated_at = CURRENT_TIMESTAMP,
                     error_message = NULL
                 WHERE id = :id',
                [
                    'status' => 'generated',
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
                     error_message = :error_message
                 WHERE id = :id',
                ['status' => 'failed', 'error_message' => $exception->getMessage(), 'id' => (int)$run['id']]
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
            . '<html xmlns="http://www.w3.org/1999/xhtml" lang="en">' . "\n"
            . '<head><meta charset="utf-8"/><title>Unaudited micro-entity accounts</title></head>' . "\n"
            . '<body>' . "\n"
            . '<!-- Internal/generated accounts pack preview only. This is not a complete HMRC CT600 submission package. -->' . "\n"
            . '<h1>Unaudited micro-entity accounts</h1>' . "\n"
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

        return '<span data-ixbrl-concept="' . $this->e((string)$fact['taxonomy_concept']) . '" data-ixbrl-context="' . $this->e((string)$fact['context_ref']) . '">' . $this->e($this->factValue($fact)) . '</span>';
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
}
