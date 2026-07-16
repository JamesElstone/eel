<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class IxbrlFactBuilderService
{
    public function buildFacts(int $companyId, int $accountingPeriodId): int
    {
        $this->ensureSchema();
        $company = $this->fetchCompany($companyId);
        $accountingPeriod = $this->fetchAccountingPeriod($companyId, $accountingPeriodId);
        if ($company === null || $accountingPeriod === null) {
            throw new \InvalidArgumentException('Select a valid company and accounting period before building iXBRL facts.');
        }

        return (int)\InterfaceDB::transaction(function () use ($companyId, $accountingPeriodId, $company, $accountingPeriod): int {
            $runToken = 'build:' . bin2hex(random_bytes(8));
            \InterfaceDB::prepareExecute(
                'INSERT INTO ixbrl_generation_runs (company_id, accounting_period_id, status, error_message)
                 VALUES (:company_id, :accounting_period_id, :status, :run_token)',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'status' => 'draft', 'run_token' => $runToken]
            );
            $runId = (int)\InterfaceDB::fetchColumn(
                'SELECT id
                 FROM ixbrl_generation_runs
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND status = :status
                   AND error_message = :run_token
                 ORDER BY id DESC
                 LIMIT 1',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'status' => 'draft', 'run_token' => $runToken]
            );
            if ($runId <= 0) {
                throw new \RuntimeException('Could not create an iXBRL generation run.');
            }

            $mapping = (new \eel_accounts\Service\IxbrlAccountsMappingService())->getAccountsMapping($companyId, $accountingPeriodId);
            $bucketValues = (array)($mapping['buckets'] ?? []);
            $directorLoanPresentation = (array)($mapping['director_loan_reporting_presentation'] ?? []);
            foreach ($this->activeMappings() as $factMapping) {
                $fact = $this->factFromMapping(
                    $factMapping,
                    $company,
                    $accountingPeriod,
                    $bucketValues,
                    $directorLoanPresentation
                );
                \InterfaceDB::prepareExecute(
                    'INSERT INTO ixbrl_generation_facts (
                        run_id, fact_key, taxonomy_concept, label, value_type,
                        numeric_value, text_value, date_value, unit_ref, decimals_value, context_ref, source_json
                     ) VALUES (
                        :run_id, :fact_key, :taxonomy_concept, :label, :value_type,
                        :numeric_value, :text_value, :date_value, :unit_ref, :decimals_value, :context_ref, :source_json
                     )',
                    [
                        'run_id' => $runId,
                        'fact_key' => $fact['fact_key'],
                        'taxonomy_concept' => $fact['taxonomy_concept'],
                        'label' => $fact['label'],
                        'value_type' => $fact['value_type'],
                        'numeric_value' => $fact['numeric_value'],
                        'text_value' => $fact['text_value'],
                        'date_value' => $fact['date_value'],
                        'unit_ref' => $fact['unit_ref'],
                        'decimals_value' => $fact['decimals_value'],
                        'context_ref' => $fact['context_ref'],
                        'source_json' => json_encode($fact['source'], JSON_THROW_ON_ERROR),
                    ]
                );
            }

            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     error_message = NULL
                 WHERE id = :id',
                ['status' => 'ready', 'id' => $runId]
            );

            return $runId;
        });
    }

    public function getFacts(int $runId): array
    {
        $this->ensureSchema();
        if ($runId <= 0) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM ixbrl_generation_facts
             WHERE run_id = :run_id
             ORDER BY fact_key ASC, context_ref ASC',
            ['run_id' => $runId]
        );
    }

    public function getLatestRun(int $companyId, int $accountingPeriodId): ?array
    {
        $this->ensureSchema();
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT r.*,
                    COUNT(f.id) AS fact_count
             FROM ixbrl_generation_runs r
             LEFT JOIN ixbrl_generation_facts f ON f.run_id = r.id
             WHERE r.company_id = :company_id
               AND r.accounting_period_id = :accounting_period_id
             GROUP BY r.id
             ORDER BY r.id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        if (!is_array($row)) {
            return null;
        }

        $freshness = $this->getRunFreshness((int)$row['id']);
        $row['run_freshness'] = $freshness;
        $row['facts_current'] = (string)($freshness['state'] ?? '') === 'current';

        return $row;
    }

    public function getRunFreshness(int $runId): array
    {
        if ($runId <= 0
            || !\InterfaceDB::tableExists('ixbrl_generation_runs')
            || !\InterfaceDB::tableExists('ixbrl_generation_facts')) {
            return [
                'state' => 'missing',
                'detail' => 'No iXBRL facts are available for freshness checking.',
            ];
        }

        $run = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id
             FROM ixbrl_generation_runs
             WHERE id = :id
             LIMIT 1',
            ['id' => $runId]
        );
        if (!is_array($run)) {
            return [
                'state' => 'missing',
                'detail' => 'The iXBRL generation run could not be found.',
            ];
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT fact_key, source_json
             FROM ixbrl_generation_facts
             WHERE run_id = :run_id
               AND fact_key IN (:within_key, :after_key)',
            [
                'run_id' => $runId,
                'within_key' => 'creditors_within_one_year',
                'after_key' => 'creditors_after_one_year',
            ]
        );
        $sources = [];
        foreach ($rows as $row) {
            $sources[(string)($row['fact_key'] ?? '')] = (string)($row['source_json'] ?? '');
        }
        foreach (['creditors_within_one_year', 'creditors_after_one_year'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $sources)) {
                return [
                    'state' => 'unverifiable',
                    'detail' => 'The latest iXBRL run is missing creditor fact provenance and must be rebuilt.',
                ];
            }
        }

        $builtPresentations = [];
        $legacyCount = 0;
        foreach ($sources as $sourceJson) {
            $decoded = json_decode($sourceJson, true);
            if (!is_array($decoded)) {
                return [
                    'state' => 'unverifiable',
                    'detail' => 'The latest iXBRL creditor fact provenance is malformed and must be rebuilt.',
                ];
            }

            if (!array_key_exists('director_loan_reporting_presentation', $decoded)) {
                $legacyCount++;
                continue;
            }
            $normalised = $this->normalisePresentationProvenance(
                (array)$decoded['director_loan_reporting_presentation']
            );
            if ($normalised === null) {
                return [
                    'state' => 'unverifiable',
                    'detail' => 'The latest iXBRL Director Loan presentation provenance is invalid and must be rebuilt.',
                ];
            }
            $builtPresentations[] = $normalised;
        }

        $current = $this->presentationProvenance(
            (new DirectorLoanReportingPresentationService())->resolveForReporting(
                (int)$run['company_id'],
                (int)$run['accounting_period_id']
            )
        );
        if ($legacyCount === count($sources)) {
            return [
                'state' => 'unverifiable',
                'detail' => 'The latest iXBRL facts pre-date Director Loan repayment-presentation provenance and must be rebuilt before generating or filing.',
                'current' => $current,
            ];
        } elseif ($legacyCount > 0 || count($builtPresentations) !== count($sources)) {
            return [
                'state' => 'unverifiable',
                'detail' => 'The latest iXBRL creditor facts disagree about Director Loan presentation provenance and must be rebuilt.',
                'current' => $current,
            ];
        } else {
            $built = $builtPresentations[0];
            foreach ($builtPresentations as $candidate) {
                if ($candidate !== $built) {
                    return [
                        'state' => 'unverifiable',
                        'detail' => 'The latest iXBRL creditor facts contain inconsistent Director Loan presentation provenance and must be rebuilt.',
                        'current' => $current,
                    ];
                }
            }
        }

        $fresh = (int)$built['revision'] === (int)$current['revision']
            && (string)$built['classification'] === (string)$current['classification']
            && (int)$built['liability_nominal_account_id'] === (int)$current['liability_nominal_account_id'];

        return [
            'state' => $fresh ? 'current' : 'stale',
            'detail' => $fresh
                ? 'The iXBRL facts use the current Director Loan repayment presentation.'
                : 'The Director Loan repayment presentation has changed since these iXBRL facts were built. Rebuild the facts before generating or filing.',
            'built' => $built,
            'current' => $current,
        ];
    }

    public function ensureSchema(): void
    {
        if (!\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS ixbrl_generation_runs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NOT NULL,
                    accounting_period_id INT NOT NULL,
                    status ENUM('draft','ready','generated','failed') NOT NULL DEFAULT 'draft',
                    export_type VARCHAR(32) NOT NULL DEFAULT 'preview',
                    taxonomy_profile VARCHAR(100) NULL,
                    validation_status VARCHAR(32) NOT NULL DEFAULT 'not_validated',
                    validation_errors_json LONGTEXT NULL,
                    external_validator VARCHAR(50) NULL,
                    external_validation_status VARCHAR(32) NOT NULL DEFAULT 'not_configured',
                    external_validation_errors_json LONGTEXT NULL,
                    external_validation_warnings_json LONGTEXT NULL,
                    external_validation_log_path VARCHAR(1000) NULL,
                    external_validated_at DATETIME NULL,
                    generated_filename VARCHAR(255) NULL,
                    generated_path VARCHAR(1000) NULL,
                    output_sha256 CHAR(64) NULL,
                    generated_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    error_message TEXT NULL,
                    KEY idx_ixbrl_runs_company_accounting_period (company_id, accounting_period_id),
                    KEY idx_ixbrl_runs_status (status),
                    CONSTRAINT fk_ixbrl_runs_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_ixbrl_runs_accounting_period FOREIGN KEY (accounting_period_id) REFERENCES accounting_periods(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        foreach ($this->runMetadataColumns() as $column => $definition) {
            if (!\InterfaceDB::columnExists('ixbrl_generation_runs', $column)) {
                \InterfaceDB::prepareExecute('ALTER TABLE ixbrl_generation_runs ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        if (!\InterfaceDB::tableExists('ixbrl_generation_facts')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS ixbrl_generation_facts (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    run_id BIGINT NOT NULL,
                    fact_key VARCHAR(150) NOT NULL,
                    taxonomy_concept VARCHAR(255) NOT NULL,
                    label VARCHAR(255) NOT NULL,
                    value_type ENUM('numeric','text','date','boolean') NOT NULL,
                    numeric_value DECIMAL(18,2) NULL,
                    text_value TEXT NULL,
                    date_value DATE NULL,
                    unit_ref VARCHAR(50) NULL DEFAULT 'GBP',
                    decimals_value VARCHAR(20) NULL DEFAULT '2',
                    context_ref VARCHAR(100) NOT NULL,
                    source_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ixbrl_generation_facts_key_context (run_id, fact_key, context_ref),
                    KEY idx_ixbrl_generation_facts_run (run_id),
                    CONSTRAINT fk_ixbrl_generation_facts_run FOREIGN KEY (run_id) REFERENCES ixbrl_generation_runs(id) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!\InterfaceDB::tableExists('ixbrl_fact_mappings')) {
            \InterfaceDB::prepareExecute(
                "CREATE TABLE IF NOT EXISTS ixbrl_fact_mappings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    fact_key VARCHAR(150) NOT NULL,
                    taxonomy_concept VARCHAR(255) NOT NULL,
                    label VARCHAR(255) NOT NULL,
                    value_type ENUM('numeric','text','date','boolean') NOT NULL,
                    calculation_type ENUM('nominal_subtype_sum','nominal_account_sum','manual','derived','company_field','period_field') NOT NULL,
                    source_key VARCHAR(150) NULL,
                    sign_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1,
                    is_required TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 100,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_ixbrl_fact_mappings_fact_key (fact_key),
                    KEY idx_ixbrl_fact_mappings_active_sort (is_active, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        $this->seedMappings();
    }

    private function seedMappings(): void
    {
        foreach ($this->seedRows() as $row) {
            if (\InterfaceDB::countWhere('ixbrl_fact_mappings', 'fact_key', $row['fact_key']) > 0) {
                continue;
            }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ixbrl_fact_mappings (
                    fact_key, taxonomy_concept, label, value_type, calculation_type, source_key,
                    sign_multiplier, is_required, sort_order, is_active
                 ) VALUES (
                    :fact_key, :taxonomy_concept, :label, :value_type, :calculation_type, :source_key,
                    :sign_multiplier, :is_required, :sort_order, :is_active
                 )',
                $row
            );
        }

        \InterfaceDB::prepareExecute(
            'UPDATE ixbrl_fact_mappings
             SET taxonomy_concept = :taxonomy_concept,
                 label = :label,
                 source_key = :source_key
             WHERE fact_key = :fact_key',
            [
                'taxonomy_concept' => 'uk-gaap:CreditorsDueAfterMoreThanOneYear',
                'label' => 'Creditors after more than one year',
                'source_key' => 'creditors_after_more_than_one_year',
                'fact_key' => 'creditors_after_one_year',
            ]
        );
        \InterfaceDB::prepareExecute(
            'UPDATE ixbrl_fact_mappings
             SET source_key = :source_key
             WHERE fact_key = :fact_key',
            ['source_key' => 'equity_capital_reserves', 'fact_key' => 'equity']
        );
    }

    private function activeMappings(): array
    {
        return \InterfaceDB::fetchAll(
            'SELECT *
             FROM ixbrl_fact_mappings
             WHERE is_active = 1
             ORDER BY sort_order ASC, fact_key ASC'
        );
    }

    private function factFromMapping(
        array $mapping,
        array $company,
        array $accountingPeriod,
        array $buckets,
        array $directorLoanPresentation
    ): array
    {
        $type = (string)$mapping['value_type'];
        $sourceKey = (string)($mapping['source_key'] ?? '');
        $value = match ((string)$mapping['calculation_type']) {
            'company_field' => (string)($company[$sourceKey] ?? ''),
            'period_field' => (string)($accountingPeriod[$sourceKey] ?? ''),
            'derived' => (float)($buckets[$sourceKey] ?? 0),
            'manual' => $this->manualValue((string)$mapping['fact_key']),
            default => null,
        };

        $numeric = null;
        $text = null;
        $date = null;
        if ($type === 'numeric') {
            $numeric = round(((float)$value) * (float)($mapping['sign_multiplier'] ?? 1), 2);
        } elseif ($type === 'date') {
            $date = $this->dateOrNull((string)$value);
        } elseif ($type === 'boolean') {
            $text = $value ? 'true' : 'false';
        } else {
            $text = (string)$value;
        }

        $source = [
            'calculation_type' => (string)$mapping['calculation_type'],
            'source_key' => $sourceKey,
            'export_notice' => 'Generated FRS 105 micro-entity accounts iXBRL export for review and validation before filing.',
        ];
        if (in_array((string)$mapping['fact_key'], ['creditors_within_one_year', 'creditors_after_one_year'], true)) {
            $source['director_loan_reporting_presentation'] = $this->presentationProvenance($directorLoanPresentation);
        }

        return [
            'fact_key' => (string)$mapping['fact_key'],
            'taxonomy_concept' => (string)$mapping['taxonomy_concept'],
            'label' => (string)$mapping['label'],
            'value_type' => $type,
            'numeric_value' => $numeric,
            'text_value' => $text,
            'date_value' => $date,
            'unit_ref' => $type === 'numeric' ? 'GBP' : null,
            'decimals_value' => $type === 'numeric' ? '2' : null,
            'context_ref' => $this->contextRef((string)$mapping['fact_key'], $type),
            'source' => $source,
        ];
    }

    private function presentationProvenance(array $presentation): array
    {
        $classification = (string)($presentation['classification']
            ?? DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR);
        if (!in_array($classification, [
            DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR,
            DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR,
        ], true)) {
            $classification = DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR;
        }

        return [
            'provenance_version' => DirectorLoanReportingPresentationService::PROVENANCE_VERSION,
            'classification' => $classification,
            'revision' => max(0, (int)($presentation['revision'] ?? 0)),
            'liability_nominal_account_id' => max(0, (int)($presentation['liability_nominal_account_id'] ?? 0)),
            'explicit' => !empty($presentation['explicit']),
        ];
    }

    private function normalisePresentationProvenance(array $presentation): ?array
    {
        $classification = (string)($presentation['classification'] ?? '');
        if (!in_array($classification, [
            DirectorLoanReportingPresentationService::WITHIN_ONE_YEAR,
            DirectorLoanReportingPresentationService::AFTER_MORE_THAN_ONE_YEAR,
        ], true)) {
            return null;
        }

        return [
            'provenance_version' => max(1, (int)($presentation['provenance_version'] ?? 1)),
            'classification' => $classification,
            'revision' => max(0, (int)($presentation['revision'] ?? 0)),
            'liability_nominal_account_id' => max(0, (int)($presentation['liability_nominal_account_id'] ?? 0)),
            'explicit' => !empty($presentation['explicit']),
        ];
    }

    private function contextRef(string $factKey, string $valueType): string
    {
        if (in_array($factKey, [
            'current_assets',
            'fixed_assets',
            'creditors_within_one_year',
            'creditors_after_one_year',
            'net_current_assets_liabilities',
            'total_assets_less_current_liabilities',
            'net_assets_liabilities',
            'equity',
        ], true)) {
            return 'current_period_end';
        }

        if (in_array($factKey, [
            'period_start',
            'period_end',
            'average_number_employees',
            'dormant_false',
        ], true)) {
            return 'current_period_duration';
        }

        return $valueType === 'date' ? 'current_period_duration' : 'entity';
    }

    private function manualValue(string $factKey): string|int|bool
    {
        return match ($factKey) {
            'average_number_employees' => 0,
            'dormant_false' => false,
            'production_software' => 'eel bookkeeping app',
            'micro_entity_statement' => 'These draft accounts are prepared for review under the FRS 105 micro-entities regime.',
            'audit_exemption_statement' => 'For the year ending on the balance sheet date the company was entitled to exemption from audit under section 477 of the Companies Act 2006.',
            'directors_responsibility_statement' => 'The director acknowledges responsibility for complying with the Companies Act 2006 requirements for accounting records and accounts.',
            'members_no_audit_statement' => 'The members have not required the company to obtain an audit of its accounts for the year in question.',
            default => '',
        };
    }

    private function fetchCompany(int $companyId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM companies
             WHERE id = :id
             LIMIT 1',
            ['id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function fetchAccountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function dateOrNull(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function seedRows(): array
    {
        return [
            $this->seed('entity_name', 'uk-bus:EntityCurrentLegalOrRegisteredName', 'Entity name', 'text', 'company_field', 'company_name', 10),
            $this->seed('company_number', 'uk-bus:UKCompaniesHouseRegisteredNumber', 'Company number', 'text', 'company_field', 'company_number', 20),
            $this->seed('period_start', 'uk-bus:StartDateForPeriodCoveredByReport', 'Period start', 'date', 'period_field', 'period_start', 30),
            $this->seed('period_end', 'uk-bus:EndDateForPeriodCoveredByReport', 'Period end', 'date', 'period_field', 'period_end', 40),
            $this->seed('balance_sheet_date', 'uk-bus:BalanceSheetDate', 'Balance sheet date', 'date', 'period_field', 'period_end', 50),
            $this->seed('current_assets', 'uk-gaap:CurrentAssets', 'Current assets', 'numeric', 'derived', 'current_assets', 100),
            $this->seed('fixed_assets', 'uk-gaap:FixedAssets', 'Fixed assets', 'numeric', 'derived', 'fixed_assets', 110),
            $this->seed('creditors_within_one_year', 'uk-gaap:CreditorsDueWithinOneYear', 'Creditors within one year', 'numeric', 'derived', 'creditors_within_one_year', 120),
            $this->seed('creditors_after_one_year', 'uk-gaap:CreditorsDueAfterMoreThanOneYear', 'Creditors after more than one year', 'numeric', 'derived', 'creditors_after_more_than_one_year', 130),
            $this->seed('net_current_assets_liabilities', 'uk-gaap:NetCurrentAssetsLiabilities', 'Net current assets / liabilities', 'numeric', 'derived', 'net_current_assets_liabilities', 140),
            $this->seed('total_assets_less_current_liabilities', 'uk-gaap:TotalAssetsLessCurrentLiabilities', 'Total assets less current liabilities', 'numeric', 'derived', 'total_assets_less_current_liabilities', 150),
            $this->seed('net_assets_liabilities', 'uk-gaap:NetAssetsLiabilities', 'Net assets / liabilities', 'numeric', 'derived', 'net_assets_liabilities', 160),
            $this->seed('equity', 'uk-gaap:CapitalAndReserves', 'Equity / capital and reserves', 'numeric', 'derived', 'equity_capital_reserves', 170),
            $this->seed('average_number_employees', 'uk-gaap:AverageNumberEmployeesDuringPeriod', 'Average number of employees', 'numeric', 'manual', null, 200),
            $this->seed('dormant_false', 'uk-bus:EntityDormant', 'Dormant false', 'boolean', 'manual', null, 210),
            $this->seed('micro_entity_statement', 'uk-gaap:MicroEntityAccountsStatement', 'Micro-entity statement', 'text', 'manual', null, 220),
            $this->seed('audit_exemption_statement', 'uk-gaap:AuditExemptionStatement', 'Audit exemption statement', 'text', 'manual', null, 230),
            $this->seed('directors_responsibility_statement', 'uk-gaap:DirectorsResponsibilityStatement', 'Directors responsibility statement', 'text', 'manual', null, 240),
            $this->seed('members_no_audit_statement', 'uk-gaap:MembersHaveNotRequiredAuditStatement', 'Members no-audit statement', 'text', 'manual', null, 250),
            $this->seed('production_software', 'uk-bus:NameProductionSoftware', 'Production software', 'text', 'manual', null, 260),
        ];
    }

    private function runMetadataColumns(): array
    {
        return [
            'export_type' => "VARCHAR(32) NOT NULL DEFAULT 'preview'",
            'taxonomy_profile' => 'VARCHAR(100) NULL',
            'validation_status' => "VARCHAR(32) NOT NULL DEFAULT 'not_validated'",
            'validation_errors_json' => 'LONGTEXT NULL',
            'external_validator' => 'VARCHAR(50) NULL',
            'external_validation_status' => "VARCHAR(32) NOT NULL DEFAULT 'not_configured'",
            'external_validation_errors_json' => 'LONGTEXT NULL',
            'external_validation_warnings_json' => 'LONGTEXT NULL',
            'external_validation_log_path' => 'VARCHAR(1000) NULL',
            'external_validated_at' => 'DATETIME NULL',
        ];
    }

    private function seed(string $factKey, string $concept, string $label, string $valueType, string $calculationType, ?string $sourceKey, int $sortOrder): array
    {
        return [
            'fact_key' => $factKey,
            'taxonomy_concept' => $concept,
            'label' => $label,
            'value_type' => $valueType,
            'calculation_type' => $calculationType,
            'source_key' => $sourceKey,
            'sign_multiplier' => '1.00',
            'is_required' => 1,
            'sort_order' => $sortOrder,
            'is_active' => 1,
        ];
    }
}
