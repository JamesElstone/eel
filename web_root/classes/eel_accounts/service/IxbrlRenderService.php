<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlRenderService
{
    public function generatePreview(int $companyId, int $accountingPeriodId): array
    {
        return $this->generateFilingExport($companyId, $accountingPeriodId);
    }

    public function generateFilingExport(int $companyId, int $accountingPeriodId): array
    {
        $builder = new IxbrlFactBuilderService();
        $run = $builder->getLatestRun($companyId, $accountingPeriodId);
        if (!is_array($run) || (int)($run['fact_count'] ?? 0) <= 0) {
            return ['success' => false, 'errors' => ['Build iXBRL facts before generating the accounts file.']];
        }
        $freshness = (array)($run['run_freshness'] ?? $builder->getRunFreshness((int)$run['id']));
        if ((string)($freshness['state'] ?? '') !== 'current') {
            return ['success' => false, 'errors' => [(string)($freshness['detail'] ?? 'Rebuild iXBRL facts before generating.')]];
        }

        $oldGeneratedPath = trim((string)($run['generated_path'] ?? ''));
        $newGeneratedPath = '';
        try {
            $xhtml = $this->renderXhtml(
                $builder->getFacts((int)$run['id']),
                $this->comparativeFactsRequired($companyId, $accountingPeriodId)
            );
            $validationErrors = $this->validateInlineXbrl($xhtml);
            if ($validationErrors !== []) {
                throw new \RuntimeException('Generated iXBRL failed internal validation: ' . implode(' ', $validationErrors));
            }

            $directory = PROJECT_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'ixbrl';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Could not create outbound iXBRL directory.');
            }
            $filename = 'accounts_ixbrl_' . $companyId . '_' . $accountingPeriodId . '_' . (int)$run['id'] . '.xhtml';
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            $newGeneratedPath = $path;
            if (file_put_contents($path, $xhtml) === false) {
                throw new \RuntimeException('Could not write generated iXBRL export file.');
            }
            $hash = (string)hash_file('sha256', $path);

            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     export_type = :export_type,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :validation_errors_json,
                     external_validator = NULL,
                     external_validation_status = :external_validation_status,
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     external_validated_sha256 = NULL,
                     generated_filename = :filename,
                     generated_path = :path,
                     output_sha256 = :sha,
                     generated_at = CURRENT_TIMESTAMP,
                     error_message = NULL
                 WHERE id = :id',
                [
                    'status' => 'generated',
                    'export_type' => 'filing_export',
                    'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                    'validation_status' => 'passed',
                    'validation_errors_json' => json_encode([], JSON_UNESCAPED_SLASHES),
                    'external_validation_status' => 'not_validated',
                    'filename' => $filename,
                    'path' => $path,
                    'sha' => $hash,
                    'id' => (int)$run['id'],
                ]
            );

            return ['success' => true, 'errors' => [], 'filename' => $filename, 'path' => $path, 'sha256' => $hash];
        } catch (\Throwable $exception) {
            foreach (array_unique([$oldGeneratedPath, $newGeneratedPath]) as $failedArtifact) {
                $this->removeManagedArtifact((string)$failedArtifact);
            }
            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     taxonomy_profile = :taxonomy_profile,
                     validation_status = :validation_status,
                     validation_errors_json = :errors,
                     external_validator = NULL,
                     external_validation_status = :external_validation_status,
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     external_validated_sha256 = NULL,
                     generated_filename = NULL,
                     generated_path = NULL,
                     output_sha256 = NULL,
                     generated_at = NULL,
                     error_message = :error_message
                 WHERE id = :id',
                [
                    'status' => 'failed',
                    'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                    'validation_status' => 'failed',
                    'errors' => json_encode([$exception->getMessage()], JSON_UNESCAPED_SLASHES),
                    'external_validation_status' => 'not_validated',
                    'error_message' => $exception->getMessage(),
                    'id' => (int)$run['id'],
                ]
            );
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    private function removeManagedArtifact(string $path): void
    {
        if ($path === '' || !is_file($path)) {
            return;
        }
        $managedDirectory = realpath(PROJECT_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'ixbrl');
        $artifactDirectory = realpath(dirname($path));
        $filename = basename($path);
        if ($managedDirectory === false
            || $artifactDirectory === false
            || strcasecmp($managedDirectory, $artifactDirectory) !== 0
            || preg_match('/^accounts_ixbrl_\d+_\d+_\d+\.xhtml$/', $filename) !== 1) {
            return;
        }
        @unlink($path);
    }

    private function comparativeFactsRequired(int $companyId, int $accountingPeriodId): bool
    {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('year_end_reviews')) {
            return false;
        }
        $periodStart = trim((string)(\InterfaceDB::fetchColumn(
            'SELECT period_start FROM accounting_periods
             WHERE id = :accounting_period_id AND company_id = :company_id
             LIMIT 1',
            ['accounting_period_id' => $accountingPeriodId, 'company_id' => $companyId]
        ) ?: ''));
        if ($periodStart === '') {
            return false;
        }

        return (int)\InterfaceDB::fetchColumn(
            'SELECT COUNT(*)
             FROM accounting_periods ap
             INNER JOIN year_end_reviews yr
               ON yr.company_id = ap.company_id
              AND yr.accounting_period_id = ap.id
              AND yr.is_locked = 1
             WHERE ap.company_id = :company_id
               AND ap.period_end < :period_start',
            ['company_id' => $companyId, 'period_start' => $periodStart]
        ) > 0;
    }

    private function renderXhtml(array $facts, bool $comparativeRequired = false): string
    {
        $indexed = $this->indexFacts($facts);
        $missingFactKeys = [];
        $missingComparativeFactKeys = [];
        $comparativePeriod = $this->comparativePeriod($facts);
        $comparativeRequired = $comparativeRequired || $comparativePeriod !== null;
        foreach ((new IxbrlTaxonomyProfileService())->mappings() as $mapping) {
            if (empty($mapping['is_active']) || empty($mapping['is_required'])) {
                continue;
            }
            $factKey = (string)($mapping['fact_key'] ?? '');
            if ($factKey !== '' && $this->currentFact($indexed, $factKey) === []) {
                $missingFactKeys[] = $factKey;
            }
            if ($comparativeRequired
                && !empty($mapping['comparative_enabled'])
                && $factKey !== ''
                && $this->comparativeFact($indexed, $factKey) === []) {
                $missingComparativeFactKeys[] = $factKey;
            }
        }
        if ($missingFactKeys !== []) {
            throw new \RuntimeException(
                'The current-period iXBRL fact set is incomplete: ' . implode(', ', $missingFactKeys) . '.'
            );
        }
        if ($missingComparativeFactKeys !== []) {
            throw new \RuntimeException(
                'The comparative-period iXBRL fact set is incomplete: '
                . implode(', ', $missingComparativeFactKeys) . '.'
            );
        }
        $this->assertMicroStatementsReconcile($indexed);
        $companyName = $this->currentFact($indexed, 'entity_name');
        $companyNumber = $this->factValue($this->currentFact($indexed, 'company_number'));
        $periodStart = $this->factValue($this->currentFact($indexed, 'period_start'));
        $periodEnd = $this->factValue($this->currentFact($indexed, 'period_end'));

        $hidden = '';
        foreach ([
            'balance_sheet_date',
            'entity_dormant',
            'country_formation_or_incorporation',
            'legal_form_entity',
            'entity_trading_status',
            'accounting_standards_applied',
            'accounts_status',
            'director_signing_financial_statements',
            'production_software',
            'production_software_version',
        ] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== []) {
                $hidden .= $this->inlineFact($fact) . "\n";
            }
        }

        $profitRows = [
            'Turnover' => 'turnover',
            'Other income' => 'other_income',
            'Raw materials and consumables' => 'raw_materials_consumables',
            'Staff costs' => 'staff_costs',
            'Depreciation and other amounts written off assets' => 'depreciation_write_offs',
            'Other charges' => 'other_charges',
            'Tax on profit / loss' => 'tax_on_profit',
            'Profit / loss for the financial year' => 'profit_loss',
        ];
        $balanceRows = [
            'Fixed assets' => 'fixed_assets',
            'Current assets' => 'current_assets',
            'Prepayments and accrued income' => 'prepayments_accrued_income',
            'Creditors: amounts falling due within one year' => 'creditors_within_one_year',
            'Net current assets / liabilities' => 'net_current_assets_liabilities',
            'Total assets less current liabilities' => 'total_assets_less_current_liabilities',
            'Creditors: amounts falling due after more than one year' => 'creditors_after_one_year',
            'Net assets / liabilities' => 'net_assets_liabilities',
            'Equity' => 'equity',
        ];

        $statements = '';
        foreach (['small_companies_regime_statement', 'audit_exemption_statement', 'directors_responsibility_statement', 'members_no_audit_statement'] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== []) {
                $statements .= '<p>' . $this->inlineFact($fact) . '</p>' . "\n";
            }
        }
        $employees = $this->currentFact($indexed, 'average_number_employees');
        if ($employees !== []) {
            $comparativeEmployees = $this->comparativeFact($indexed, 'average_number_employees');
            $statements .= '<p>Average number of employees: ' . $this->inlineFact($employees)
                . ($comparativeEmployees !== []
                    ? ' (comparative: ' . $this->inlineFact($comparativeEmployees) . ')'
                    : '')
                . '</p>' . "\n";
        }
        $approvalDate = $this->currentFact($indexed, 'accounts_approval_date');
        $director = $this->currentFact($indexed, 'approving_director_name');
        if ($approvalDate !== [] && $director !== []) {
            $statements .= '<p>Approved by the board and signed on its behalf by '
                . $this->inlineFact($director) . ', director, on '
                . $this->inlineFact($approvalDate) . '.</p>' . "\n";
        }

        $notes = '';
        foreach ([
            'no_material_off_balance_sheet_arrangements',
            'no_director_advances_or_credits',
            'no_director_guarantees',
            'no_capital_commitments',
            'no_financial_commitments',
            'no_contingent_liabilities',
        ] as $key) {
            $fact = $this->currentFact($indexed, $key);
            if ($fact !== []) {
                $notes .= '<p>' . $this->inlineFact($fact) . '</p>' . "\n";
            }
        }
        $registeredOffice = $this->inlineFact($this->currentFact($indexed, 'registered_office_address_line_1'))
            . '<br/>' . $this->inlineFact($this->currentFact($indexed, 'registered_office_address_line_2'))
            . '<br/>' . $this->inlineFact($this->currentFact($indexed, 'registered_office_address_line_3'))
            . '<br/>' . $this->inlineFact($this->currentFact($indexed, 'registered_office_postal_code'));

        $namespaceAttributes = '';
        foreach (IxbrlTaxonomyProfileService::NAMESPACES as $prefix => $uri) {
            $namespaceAttributes .= ' xmlns:' . $prefix . '="' . $this->e($uri) . '"';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml"'
            . ' xmlns:ix="http://www.xbrl.org/2013/inlineXBRL"'
            . ' xmlns:ixt="http://www.xbrl.org/inlineXBRL/transformation/2015-02-26"'
            . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
            . ' xmlns:xbrldi="http://xbrl.org/2006/xbrldi"'
            . ' xmlns:link="http://www.xbrl.org/2003/linkbase"'
            . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
            . ' xmlns:iso4217="http://www.xbrl.org/2003/iso4217"'
            . $namespaceAttributes
            . ' xml:lang="en">' . "\n"
            . '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/><title>FRS 105 micro-entity accounts</title></head>' . "\n"
            . '<body>' . "\n"
            . '<div style="display:none"><ix:header>' . "\n"
            . ($hidden !== '' ? '<ix:hidden>' . "\n" . $hidden . '</ix:hidden>' . "\n" : '')
            . '<ix:references><link:schemaRef xlink:type="simple" xlink:href="' . $this->e(IxbrlTaxonomyProfileService::SCHEMA_REF) . '"/></ix:references>' . "\n"
            . '<ix:resources>' . "\n"
            . $this->contexts($companyNumber, $periodStart, $periodEnd, $comparativePeriod, $this->factValue($approvalDate))
            . '<xbrli:unit id="GBP"><xbrli:measure>iso4217:GBP</xbrli:measure></xbrli:unit>' . "\n"
            . '<xbrli:unit id="pure"><xbrli:measure>xbrli:pure</xbrli:measure></xbrli:unit>' . "\n"
            . '</ix:resources>' . "\n"
            . '</ix:header></div>' . "\n"
            . '<h1>FRS 105 unaudited micro-entity accounts</h1>' . "\n"
            . '<div><h2>' . $this->inlineFact($companyName) . '</h2>'
            . '<p>Company number: ' . $this->inlineFact($this->currentFact($indexed, 'company_number')) . '</p>'
            . '<p>Jurisdiction: England and Wales</p>'
            . '<p>Legal form: Private limited company (Ltd)</p>'
            . '<p>Registered office:<br/>' . $registeredOffice . '</p>'
            . '<p>Period: ' . $this->inlineFact($this->currentFact($indexed, 'period_start'))
            . ' to ' . $this->inlineFact($this->currentFact($indexed, 'period_end')) . '</p>'
            . '<p>Figures are presented in pounds sterling (GBP) to two decimal places.</p></div>' . "\n"
            . '<div><h2>Profit and loss account</h2>'
            . $this->statementTable($indexed, $profitRows)
            . '</div>' . "\n"
            . '<div><h2>Balance sheet</h2>'
            . $this->statementTable($indexed, $balanceRows)
            . '</div>' . "\n"
            . '<div><h2>Statutory statements and approval</h2>' . $statements . '</div>' . "\n"
            . '<div><h2>Notes</h2>' . $notes . '</div>' . "\n"
            . '</body></html>' . "\n";
    }

    private function statementTable(array $indexed, array $rows): string
    {
        $hasComparative = $this->hasComparative($indexed);
        $html = '<table><thead><tr><th>Item</th><th>Current period</th>'
            . ($hasComparative ? '<th>Comparative period</th>' : '')
            . '</tr></thead><tbody>' . "\n";
        foreach ($rows as $label => $key) {
            $current = $this->currentFact($indexed, $key);
            if ($current === []) {
                continue;
            }
            $html .= '<tr><th>' . $this->e($label) . '</th><td>' . $this->inlineFact($current) . '</td>';
            if ($hasComparative) {
                $html .= '<td>' . $this->inlineFact($this->comparativeFact($indexed, $key)) . '</td>';
            }
            $html .= '</tr>' . "\n";
        }
        return $html . '</tbody></table>';
    }

    private function contexts(string $companyNumber, string $start, string $end, ?array $comparative, string $approvalDate): string
    {
        $companyNumber = $companyNumber !== '' ? $companyNumber : 'UNKNOWN';
        $start = $this->validDate($start, '1970-01-01');
        $end = $this->validDate($end, $start);
        $contexts = $this->durationContext('current_period_duration', $companyNumber, $start, $end)
            . $this->durationContext('current_period_duration_director_1', $companyNumber, $start, $end, [
                'bus:EntityOfficersDimension' => 'bus:Director1',
            ])
            . $this->durationContext('current_period_duration_country_formation', $companyNumber, $start, $end, [
                'countries:CountriesRegionsDimension' => 'countries:EnglandWales',
            ])
            . $this->durationContext('current_period_duration_legal_form', $companyNumber, $start, $end, [
                'bus:LegalFormEntityDimension' => 'bus:PrivateLimitedCompanyLtd',
            ])
            . $this->durationContext('current_period_duration_registered_office', $companyNumber, $start, $end, [
                'bus:EntityContactTypeDimension' => 'bus:RegisteredOffice',
                'countries:CountriesRegionsDimension' => 'countries:UnitedKingdom',
            ])
            . $this->durationContext('current_period_duration_accounting_standards', $companyNumber, $start, $end, [
                'bus:AccountingStandardsDimension' => 'bus:Micro-entities',
            ])
            . $this->durationContext('current_period_duration_accounts_status', $companyNumber, $start, $end, [
                'bus:AccountsStatusDimension' => 'bus:AuditExempt-NoAccountantsReport',
            ])
            . $this->durationContext('current_period_duration_entity_never_traded', $companyNumber, $start, $end, [
                'bus:EntityTradingStatusDimension' => 'bus:EntityHasNeverTraded',
            ])
            . $this->durationContext('current_period_duration_entity_no_longer_trading', $companyNumber, $start, $end, [
                'bus:EntityTradingStatusDimension' => 'bus:EntityNoLongerTradingButTradedInPast',
            ])
            . $this->instantContext('current_period_start', $companyNumber, $start)
            . $this->instantContext('current_period_end', $companyNumber, $end)
            . $this->instantContext('current_period_end_creditors_within_one_year', $companyNumber, $end, [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:WithinOneYear',
            ])
            . $this->instantContext('current_period_end_creditors_after_one_year', $companyNumber, $end, [
                'core:MaturitiesOrExpirationPeriodsDimension' => 'core:AfterOneYear',
            ]);
        if ($approvalDate !== '') {
            $contexts .= $this->instantContext('accounts_approval_date', $companyNumber, $this->validDate($approvalDate, $end));
        }
        if ($comparative !== null) {
            $comparativeStart = $this->validDate((string)($comparative['period_start'] ?? ''), $start);
            $comparativeEnd = $this->validDate((string)($comparative['period_end'] ?? ''), $start);
            $contexts .= $this->durationContext('comparative_period_duration', $companyNumber, $comparativeStart, $comparativeEnd)
                . $this->instantContext('comparative_period_end', $companyNumber, $comparativeEnd)
                . $this->instantContext('comparative_period_end_creditors_within_one_year', $companyNumber, $comparativeEnd, [
                    'core:MaturitiesOrExpirationPeriodsDimension' => 'core:WithinOneYear',
                ])
                . $this->instantContext('comparative_period_end_creditors_after_one_year', $companyNumber, $comparativeEnd, [
                    'core:MaturitiesOrExpirationPeriodsDimension' => 'core:AfterOneYear',
                ]);
        }
        return $contexts;
    }

    private function durationContext(string $id, string $companyNumber, string $start, string $end, array $dimensions = []): string
    {
        return '<xbrli:context id="' . $this->e($id) . '"><xbrli:entity>'
            . $this->entityContent($companyNumber, $dimensions)
            . '</xbrli:entity><xbrli:period><xbrli:startDate>' . $this->e($start)
            . '</xbrli:startDate><xbrli:endDate>' . $this->e($end)
            . '</xbrli:endDate></xbrli:period></xbrli:context>' . "\n";
    }

    private function instantContext(string $id, string $companyNumber, string $date, array $dimensions = []): string
    {
        return '<xbrli:context id="' . $this->e($id) . '"><xbrli:entity>'
            . $this->entityContent($companyNumber, $dimensions)
            . '</xbrli:entity><xbrli:period><xbrli:instant>' . $this->e($date)
            . '</xbrli:instant></xbrli:period></xbrli:context>' . "\n";
    }

    private function entityContent(string $companyNumber, array $dimensions): string
    {
        $content = '<xbrli:identifier scheme="http://www.companieshouse.gov.uk/">' . $this->e($companyNumber) . '</xbrli:identifier>';
        if ($dimensions !== []) {
            $content .= '<xbrli:segment>';
            foreach ($dimensions as $dimension => $member) {
                $content .= '<xbrldi:explicitMember dimension="' . $this->e((string)$dimension) . '">'
                    . $this->e((string)$member) . '</xbrldi:explicitMember>';
            }
            $content .= '</xbrli:segment>';
        }
        return $content;
    }

    private function inlineFact(array $fact): string
    {
        if ($fact === []) {
            return '';
        }
        $name = $this->e((string)$fact['taxonomy_concept']);
        $context = $this->e((string)$fact['context_ref']);
        $type = (string)($fact['value_type'] ?? 'text');
        if ($type === 'numeric') {
            $numeric = (float)($fact['numeric_value'] ?? 0);
            $decimals = (string)($fact['decimals_value'] ?? '2');
            $precision = $decimals === '0' ? 0 : 2;
            $sign = $numeric < 0 ? ' sign="-"' : '';
            return '<ix:nonFraction name="' . $name . '" contextRef="' . $context
                . '" unitRef="' . $this->e((string)($fact['unit_ref'] ?? 'GBP'))
                . '" decimals="' . $this->e($decimals) . '" format="ixt:numdotdecimal"' . $sign . '>'
                . number_format(abs($numeric), $precision, '.', '') . '</ix:nonFraction>';
        }
        $format = $type === 'date' ? ' format="ixt:dateyearmonthday"' : '';
        return '<ix:nonNumeric name="' . $name . '" contextRef="' . $context . '"' . $format . '>'
            . $this->e($this->factValue($fact)) . '</ix:nonNumeric>';
    }

    private function indexFacts(array $facts): array
    {
        $indexed = [];
        foreach ($facts as $fact) {
            $indexed[(string)$fact['fact_key']][(string)$fact['context_ref']] = $fact;
        }
        return $indexed;
    }

    private function currentFact(array $indexed, string $key): array
    {
        foreach ((array)($indexed[$key] ?? []) as $context => $fact) {
            if (!str_starts_with((string)$context, 'comparative_')) {
                return (array)$fact;
            }
        }
        return [];
    }

    private function comparativeFact(array $indexed, string $key): array
    {
        foreach ((array)($indexed[$key] ?? []) as $context => $fact) {
            if (str_starts_with((string)$context, 'comparative_')) {
                return (array)$fact;
            }
        }
        return [];
    }

    private function hasComparative(array $indexed): bool
    {
        return $this->comparativeFact($indexed, 'net_assets_liabilities') !== [];
    }

    private function comparativePeriod(array $facts): ?array
    {
        foreach ($facts as $fact) {
            if (!str_starts_with((string)($fact['context_ref'] ?? ''), 'comparative_')) {
                continue;
            }
            $source = json_decode((string)($fact['source_json'] ?? ''), true);
            if (is_array($source)) {
                return [
                    'period_start' => (string)($source['period_start'] ?? ''),
                    'period_end' => (string)($source['period_end'] ?? ''),
                ];
            }
        }
        return null;
    }

    private function factValue(array $fact): string
    {
        return match ((string)($fact['value_type'] ?? 'text')) {
            'numeric' => (string)($fact['numeric_value'] ?? '0'),
            'date' => (string)($fact['date_value'] ?? ''),
            default => (string)($fact['text_value'] ?? ''),
        };
    }

    private function assertMicroStatementsReconcile(array $indexed): void
    {
        foreach ([false, true] as $comparative) {
            if ($comparative && !$this->hasComparative($indexed)) {
                continue;
            }
            $fact = fn(string $key): array => $comparative
                ? $this->comparativeFact($indexed, $key)
                : $this->currentFact($indexed, $key);
            $amount = static fn(array $row): float => round((float)($row['numeric_value'] ?? 0), 2);

            $profit = round(
                $amount($fact('turnover'))
                + $amount($fact('other_income'))
                - $amount($fact('raw_materials_consumables'))
                - $amount($fact('staff_costs'))
                - $amount($fact('depreciation_write_offs'))
                - $amount($fact('other_charges'))
                - $amount($fact('tax_on_profit')),
                2
            );
            if (abs($profit - $amount($fact('profit_loss'))) >= 0.005) {
                throw new \RuntimeException(
                    ($comparative ? 'Comparative' : 'Current')
                    . ' micro profit-and-loss lines do not reconcile to profit or loss.'
                );
            }

            $netCurrent = round(
                $amount($fact('current_assets'))
                + $amount($fact('prepayments_accrued_income'))
                - $amount($fact('creditors_within_one_year')),
                2
            );
            $totalAssetsLessCurrent = round($amount($fact('fixed_assets')) + $netCurrent, 2);
            $netAssets = round($totalAssetsLessCurrent - $amount($fact('creditors_after_one_year')), 2);
            if (abs($netCurrent - $amount($fact('net_current_assets_liabilities'))) >= 0.005
                || abs($totalAssetsLessCurrent - $amount($fact('total_assets_less_current_liabilities'))) >= 0.005
                || abs($netAssets - $amount($fact('net_assets_liabilities'))) >= 0.005
                || abs($netAssets - $amount($fact('equity'))) >= 0.005) {
                throw new \RuntimeException(
                    ($comparative ? 'Comparative' : 'Current')
                    . ' micro balance-sheet lines do not reconcile to net assets and equity.'
                );
            }
        }
    }

    private function validateInlineXbrl(string $xhtml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($xhtml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return ['Generated XHTML is not well-formed XML.'];
        }

        $errors = [];
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ix', 'http://www.xbrl.org/2013/inlineXBRL');
        $xpath->registerNamespace('xbrli', 'http://www.xbrl.org/2003/instance');
        $xpath->registerNamespace('xbrldi', 'http://xbrl.org/2006/xbrldi');
        $xpath->registerNamespace('link', 'http://www.xbrl.org/2003/linkbase');
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        if (($xpath->query('//ix:header')->length ?? 0) !== 1) {
            $errors[] = 'Exactly one Inline XBRL header is required.';
        }
        if (($xpath->query('//link:schemaRef[@xlink:href="' . IxbrlTaxonomyProfileService::SCHEMA_REF . '"]')->length ?? 0) < 1) {
            $errors[] = 'The FRC 2026 FRS-102 taxonomy entry point is missing.';
        }
        if (($xpath->query('//xbrli:unit[@id="GBP"]')->length ?? 0) < 1
            || ($xpath->query('//xbrli:unit[@id="pure"]')->length ?? 0) < 1) {
            $errors[] = 'Required GBP and pure units are missing.';
        }
        if (($xpath->query('//xbrldi:explicitMember')->length ?? 0) < 3) {
            $errors[] = 'Required creditor and director dimensions are missing.';
        }
        if (($xpath->query('//ix:nonFraction | //ix:nonNumeric')->length ?? 0) < 1) {
            $errors[] = 'No Inline XBRL facts were generated.';
        }
        foreach ([
            'bus:EntityCurrentLegalOrRegisteredName',
            'bus:UKCompaniesHouseRegisteredNumber',
            'bus:CountryFormationOrIncorporation',
            'bus:LegalFormEntity',
            'bus:AddressLine1',
            'bus:AddressLine2',
            'bus:AddressLine3',
            'bus:PostalCodeZip',
            'bus:StartDateForPeriodCoveredByReport',
            'bus:EndDateForPeriodCoveredByReport',
            'bus:BalanceSheetDate',
            'bus:EntityDormantTruefalse',
            'bus:NameEntityOfficer',
            'bus:EntityTradingStatus',
            'bus:AccountingStandardsApplied',
            'bus:AccountsStatusAuditedOrUnaudited',
            'bus:NameProductionSoftware',
            'bus:VersionProductionSoftware',
            'core:DateAuthorisationFinancialStatementsForIssue',
            'core:DirectorSigningFinancialStatements',
            'core:TurnoverRevenue',
            'core:OtherOperatingIncomeFormat2',
            'core:RawMaterialsConsumablesUsed',
            'core:StaffCostsEmployeeBenefitsExpense',
            'core:DepreciationAmortisationImpairmentExpense',
            'core:OtherExternalCharges',
            'core:ProfitLoss',
            'core:PrepaymentsAccruedIncome',
            'core:NetAssetsLiabilities',
            'core:Equity',
            'core:AverageNumberEmployeesDuringPeriod',
            'core:GeneralDescriptionAnyOff-balanceSheetArrangementsIncludingNaturePurposeFinancialImpactOnEntity',
            'core:DescriptionCapitalCommitments',
            'core:DescriptionFinancialCommitmentsOtherThanCapitalCommitments',
            'core:GeneralDescriptionContingentLiabilitiesIncludingFinancialEffectUncertaintiesPossibleReimbursement',
            'direp:StatementThatAccountsHaveBeenPreparedInAccordanceWithProvisionsSmallCompaniesRegime',
            'direp:StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies',
            'direp:StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct',
            'direp:StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit',
            'direp:GeneralDescriptionAdvancesCreditsToDirectorsIncludingTermsInterestRates',
            'direp:GeneralDescriptionGuaranteesTheirTermsDirectors',
        ] as $requiredConcept) {
            $query = '//*[@name="' . $requiredConcept . '"]';
            if (($xpath->query($query)->length ?? 0) < 1) {
                $errors[] = 'Required filing fact is missing: ' . $requiredConcept . '.';
            }
        }
        foreach ($xpath->query('//ix:nonFraction[starts-with(normalize-space(text()), "-")]') ?: [] as $_) {
            $errors[] = 'Negative transformed numbers must use the sign attribute and positive lexical content.';
            break;
        }
        if (str_contains($xhtml, '<section') || str_contains($xhtml, '<meta charset=')) {
            $errors[] = 'HTML5-only markup is not allowed in the Inline XHTML profile.';
        }
        return $errors;
    }

    private function validDate(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
