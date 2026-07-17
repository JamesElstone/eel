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
        $this->assertSchemaReady();
        $report = (new IxbrlAccountsReportService())->build($companyId, $accountingPeriodId);
        $mappings = (new IxbrlTaxonomyProfileService())->mappings();

        return (int)\InterfaceDB::transaction(function () use (
            $companyId,
            $accountingPeriodId,
            $report,
            $mappings
        ): int {
            $token = 'build:' . bin2hex(random_bytes(8));
            \InterfaceDB::prepareExecute(
                'INSERT INTO ixbrl_generation_runs (
                    company_id, accounting_period_id, status, error_message,
                    taxonomy_profile, basis_version, basis_hash
                 ) VALUES (
                    :company_id, :accounting_period_id, :status, :token,
                    :taxonomy_profile, :basis_version, :basis_hash
                 )',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'status' => 'draft',
                    'token' => $token,
                    'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                    'basis_version' => IxbrlTaxonomyProfileService::BASIS_VERSION,
                    'basis_hash' => (string)$report['basis_hash'],
                ]
            );
            $runId = (int)\InterfaceDB::fetchColumn(
                'SELECT id FROM ixbrl_generation_runs
                 WHERE company_id = :company_id
                   AND accounting_period_id = :accounting_period_id
                   AND error_message = :token
                 ORDER BY id DESC LIMIT 1',
                [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'token' => $token,
                ]
            );
            if ($runId <= 0) {
                throw new \RuntimeException('Could not create an iXBRL generation run.');
            }

            foreach ($mappings as $mapping) {
                $fact = $this->factFromMapping($mapping, $report, false);
                if ($fact !== null) {
                    $this->insertFact($runId, $fact);
                }

                if (!empty($mapping['comparative_enabled']) && is_array($report['comparative'] ?? null)) {
                    $comparativeFact = $this->factFromMapping($mapping, $report, true);
                    if ($comparativeFact !== null) {
                        $this->insertFact($runId, $comparativeFact);
                    }
                }
            }

            \InterfaceDB::prepareExecute(
                'UPDATE ixbrl_generation_runs
                 SET status = :status,
                     validation_status = :validation_status,
                     external_validation_status = :external_validation_status,
                     external_validator = NULL,
                     external_validation_errors_json = NULL,
                     external_validation_warnings_json = NULL,
                     external_validation_log_path = NULL,
                     external_validated_at = NULL,
                     external_validated_sha256 = NULL,
                     error_message = NULL
                 WHERE id = :id',
                [
                    'status' => 'ready',
                    'validation_status' => 'not_validated',
                    'external_validation_status' => 'not_configured',
                    'id' => $runId,
                ]
            );

            return $runId;
        });
    }

    public function getFacts(int $runId): array
    {
        if ($runId <= 0 || !\InterfaceDB::tableExists('ixbrl_generation_facts')) {
            return [];
        }

        return \InterfaceDB::fetchAll(
            'SELECT * FROM ixbrl_generation_facts
             WHERE run_id = :run_id
             ORDER BY fact_key ASC, context_ref ASC',
            ['run_id' => $runId]
        );
    }

    public function getLatestRun(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists('ixbrl_generation_runs')
            || !\InterfaceDB::tableExists('ixbrl_generation_facts')) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT r.*, COUNT(f.id) AS fact_count
             FROM ixbrl_generation_runs r
             LEFT JOIN ixbrl_generation_facts f ON f.run_id = r.id
             WHERE r.company_id = :company_id
               AND r.accounting_period_id = :accounting_period_id
             GROUP BY r.id
             ORDER BY r.id DESC LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return null;
        }

        $row['run_freshness'] = $this->getRunFreshness((int)$row['id']);
        $row['facts_current'] = (string)($row['run_freshness']['state'] ?? '') === 'current';
        return $row;
    }

    public function getRunFreshness(int $runId): array
    {
        if ($runId <= 0 || !\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            return ['state' => 'missing', 'detail' => 'No iXBRL facts are available for freshness checking.'];
        }
        if (!\InterfaceDB::columnExists('ixbrl_generation_runs', 'basis_version')
            || !\InterfaceDB::columnExists('ixbrl_generation_runs', 'basis_hash')) {
            return [
                'state' => 'unverifiable',
                'detail' => 'Apply the latest iXBRL taxonomy migration and rebuild the facts.',
            ];
        }
        $run = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id, basis_version, basis_hash
             FROM ixbrl_generation_runs WHERE id = :id LIMIT 1',
            ['id' => $runId]
        );
        if (!is_array($run)) {
            return ['state' => 'missing', 'detail' => 'The iXBRL generation run could not be found.'];
        }
        $builtHash = trim((string)($run['basis_hash'] ?? ''));
        if ($builtHash === '' || (string)($run['basis_version'] ?? '') !== IxbrlTaxonomyProfileService::BASIS_VERSION) {
            return [
                'state' => 'unverifiable',
                'detail' => 'The iXBRL run predates complete source-basis tracking and must be rebuilt.',
            ];
        }

        try {
            $currentHash = (string)(new IxbrlAccountsReportService())->build(
                (int)$run['company_id'],
                (int)$run['accounting_period_id']
            )['basis_hash'];
        } catch (\Throwable $exception) {
            return [
                'state' => 'stale',
                'detail' => 'The current accounts source is not filing-ready: ' . $exception->getMessage(),
                'built_hash' => $builtHash,
            ];
        }

        $current = hash_equals($builtHash, $currentHash);
        return [
            'state' => $current ? 'current' : 'stale',
            'detail' => $current
                ? 'The iXBRL facts match the current accounts, disclosures and taxonomy profile.'
                : 'Accounts data, disclosures or taxonomy mappings have changed. Rebuild the iXBRL facts.',
            'built_hash' => $builtHash,
            'current_hash' => $currentHash,
        ];
    }

    /**
     * Retained for callers during migration. It deliberately performs no DDL
     * or data seeding; schema changes belong to downstream migrations.
     */
    public function ensureSchema(): void
    {
    }

    private function assertSchemaReady(): void
    {
        foreach (['ixbrl_generation_runs', 'ixbrl_generation_facts', 'ixbrl_fact_mappings', 'ixbrl_accounts_disclosures'] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                throw new \RuntimeException('Apply the latest iXBRL database migrations before building facts.');
            }
        }
        foreach (['basis_version', 'basis_hash', 'external_validated_sha256'] as $column) {
            if (!\InterfaceDB::columnExists('ixbrl_generation_runs', $column)) {
                throw new \RuntimeException('Apply the latest iXBRL database migrations before building facts.');
            }
        }
        if (!\InterfaceDB::columnExists('ixbrl_generation_facts', 'dimensions_json')) {
            throw new \RuntimeException('Apply the latest iXBRL taxonomy migration before building facts.');
        }
    }

    private function insertFact(int $runId, array $fact): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO ixbrl_generation_facts (
                run_id, fact_key, taxonomy_concept, label, value_type,
                numeric_value, text_value, date_value, unit_ref, decimals_value,
                context_ref, dimensions_json, source_json
             ) VALUES (
                :run_id, :fact_key, :taxonomy_concept, :label, :value_type,
                :numeric_value, :text_value, :date_value, :unit_ref, :decimals_value,
                :context_ref, :dimensions_json, :source_json
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
                'dimensions_json' => $fact['dimensions_json'],
                'source_json' => json_encode($fact['source'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function factFromMapping(array $mapping, array $report, bool $comparative): ?array
    {
        $company = (array)$report['company'];
        $disclosures = $comparative
            ? (array)($report['comparative']['disclosures'] ?? [])
            : (array)$report['disclosures'];
        $period = $comparative
            ? (array)($report['comparative']['period'] ?? [])
            : (array)$report['accounting_period'];
        $accountsMapping = $comparative
            ? (array)($report['comparative']['mapping'] ?? [])
            : (array)$report['current'];
        $buckets = (array)($accountsMapping['buckets'] ?? []);
        $sourceKey = (string)($mapping['source_key'] ?? '');
        $calculationType = (string)$mapping['calculation_type'];
        $directorLoanDisclosure = $comparative
            ? (array)($report['comparative']['director_loan_disclosure'] ?? [])
            : (array)($report['director_loan_disclosure'] ?? []);

        $value = match ($calculationType) {
            'company_field' => $company[$sourceKey] ?? '',
            'period_field' => $period[$sourceKey] ?? '',
            'derived' => $buckets[$sourceKey] ?? 0,
            'disclosure_field' => $disclosures[$sourceKey] ?? null,
            'application_value' => $sourceKey === 'app_version'
                ? ($report['application_version'] ?? IxbrlTaxonomyProfileService::BASIS_VERSION)
                : ($report['application_name'] ?? 'EEL Accounts'),
            'fixed_marker' => '',
            'disclosure_statement' => !empty($disclosures[$sourceKey])
                ? (new IxbrlTaxonomyProfileService())->statementText($sourceKey)
                : null,
            'absence_statement' => array_key_exists($sourceKey, $disclosures)
                && (int)$disclosures[$sourceKey] === 0
                    ? (new IxbrlTaxonomyProfileService())->absenceStatementText((string)$mapping['fact_key'])
                    : null,
            'director_loan_statement' => !empty(($directorLoanDisclosure['has_company_to_director_exposure'] ?? false))
                ? (new IxbrlTaxonomyProfileService())->directorLoanStatementText(
                    $directorLoanDisclosure
                )
                : (array_key_exists($sourceKey, $disclosures) && (int)$disclosures[$sourceKey] === 0
                    ? (new IxbrlTaxonomyProfileService())->absenceStatementText((string)$mapping['fact_key'])
                    : null),
            default => null,
        };
        if ($value === null) {
            return null;
        }

        $type = (string)$mapping['value_type'];
        $decimals = $mapping['decimals_value'] !== null ? (string)$mapping['decimals_value'] : null;
        $numeric = null;
        $text = null;
        $date = null;
        if ($type === 'numeric') {
            $precision = $decimals === '0' ? 0 : 2;
            $numeric = round((float)$value * (float)($mapping['sign_multiplier'] ?? 1), $precision);
        } elseif ($type === 'date') {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value) === 1 ? (string)$value : null;
        } elseif ($type === 'boolean') {
            $text = !empty($value) ? 'true' : 'false';
        } else {
            $text = (string)$value;
        }

        $dimensionsJson = $mapping['dimensions_json'] ?? null;
        $dimensions = is_string($dimensionsJson) && $dimensionsJson !== ''
            ? (array)(json_decode($dimensionsJson, true) ?: [])
            : [];
        $contextProfile = (string)$mapping['context_profile'];
        if ((string)$mapping['fact_key'] === 'entity_trading_status') {
            $tradingStatus = (string)($disclosures['entity_trading_status'] ?? '');
            if ($tradingStatus === 'never_traded') {
                $contextProfile = 'duration_trading_never';
                $dimensions = ['bus:EntityTradingStatusDimension' => 'bus:EntityHasNeverTraded'];
            } elseif ($tradingStatus === 'no_longer_trading') {
                $contextProfile = 'duration_trading_ceased';
                $dimensions = ['bus:EntityTradingStatusDimension' => 'bus:EntityNoLongerTradingButTradedInPast'];
            } else {
                $contextProfile = 'duration';
                $dimensions = [];
            }
        }
        $contextRef = $this->contextRef($contextProfile, $comparative);
        $sources = (array)($accountsMapping['sources'][$sourceKey] ?? []);

        return [
            'fact_key' => (string)$mapping['fact_key'],
            'taxonomy_concept' => (string)$mapping['taxonomy_concept'],
            'label' => (string)$mapping['label'],
            'value_type' => $type,
            'numeric_value' => $numeric,
            'text_value' => $text,
            'date_value' => $date,
            'unit_ref' => $mapping['unit_ref'] !== null ? (string)$mapping['unit_ref'] : null,
            'decimals_value' => $decimals,
            'context_ref' => $contextRef,
            'dimensions_json' => $dimensions !== []
                ? json_encode($dimensions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : null,
            'source' => [
                'calculation_type' => $calculationType,
                'source_key' => $sourceKey,
                'namespace_uri' => (string)$mapping['namespace_uri'],
                'local_name' => (string)$mapping['local_name'],
                'period_start' => (string)($period['period_start'] ?? ''),
                'period_end' => (string)($period['period_end'] ?? ''),
                'comparative' => $comparative,
                'dimensions' => $dimensions,
                'source_rows' => $sources,
                'disclosure_revision' => (int)($disclosures['revision'] ?? 0),
                'director_loan_reporting_presentation' => (array)($accountsMapping['director_loan_reporting_presentation'] ?? []),
            ],
        ];
    }

    private function contextRef(string $profile, bool $comparative): string
    {
        $prefix = $comparative ? 'comparative' : 'current';
        return match ($profile) {
            'duration' => $prefix . '_period_duration',
            'instant_start' => $prefix . '_period_start',
            'instant_end' => $prefix . '_period_end',
            'instant_end_creditors_within' => $prefix . '_period_end_creditors_within_one_year',
            'instant_end_creditors_after' => $prefix . '_period_end_creditors_after_one_year',
            'instant_approval' => 'accounts_approval_date',
            'duration_director_1' => $prefix . '_period_duration_director_1',
            'duration_country_formation' => $prefix . '_period_duration_country_formation',
            'duration_legal_form' => $prefix . '_period_duration_legal_form',
            'duration_registered_office' => $prefix . '_period_duration_registered_office',
            'duration_accounting_standards' => $prefix . '_period_duration_accounting_standards',
            'duration_accounts_status' => $prefix . '_period_duration_accounts_status',
            'duration_trading_never' => $prefix . '_period_duration_entity_never_traded',
            'duration_trading_ceased' => $prefix . '_period_duration_entity_no_longer_trading',
            default => $prefix . '_period_duration',
        };
    }
}
