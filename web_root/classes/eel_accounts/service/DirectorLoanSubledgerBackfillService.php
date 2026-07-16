<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class DirectorLoanSubledgerBackfillService
{
    public function run(bool $apply = false): array
    {
        $report = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'companies' => [],
            'director_sync_count' => 0,
            'nominal_mapping_count' => 0,
            'attribution_count' => 0,
            'disabled_rule_count' => 0,
            'ambiguous' => [],
            'errors' => [],
        ];

        foreach (\InterfaceDB::fetchAll(
            'SELECT id, company_name, companies_house_officers_json
             FROM companies
             ORDER BY id'
        ) as $company) {
            try {
                $companyReport = $this->runForCompany($company, $apply, $report);
                $report['companies'][] = $companyReport;
            } catch (\Throwable $exception) {
                $report['errors'][] = 'Company #' . (int)$company['id'] . ': ' . $exception->getMessage();
            }
        }

        $report['success'] = $report['errors'] === [];
        return $report;
    }

    private function runForCompany(array $company, bool $apply, array &$report): array
    {
        $companyId = (int)$company['id'];
        $directorService = new CompanyDirectorService();
        $preview = $directorService->previewFromStoredOfficersJson((string)($company['companies_house_officers_json'] ?? '')) ?? [];
        if ($apply && $preview !== []) {
            $sync = $directorService->syncFromStoredOfficersJson(
                $companyId,
                (string)$company['companies_house_officers_json']
            );
            if (empty($sync['success'])) {
                throw new \RuntimeException(implode(' ', (array)$sync['errors']));
            }
            $report['director_sync_count'] += (int)$sync['synced_count'];
        }

        $directors = $directorService->fetchForCompany($companyId);
        $previewOnly = false;
        if ($directors === [] && $preview !== []) {
            $previewOnly = true;
            $directors = array_map(static fn(array $director, int $index): array => [
                'id' => -1 * ($index + 1),
                'full_name' => (string)$director['full_name'],
                'appointed_on' => $director['appointed_on'],
                'resigned_on' => $director['resigned_on'],
            ], $preview, array_keys($preview));
        }

        $mapping = $this->nominalMappingPreview($companyId);
        if ($apply) {
            $appliedMapping = (new DirectorLoanAttributionService())->mapControlNominalsIfUnambiguous($companyId);
            $report['nominal_mapping_count'] += count((array)($appliedMapping['mapped'] ?? []));
        }

        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        $nominalIds = (array)$controls['all'];
        $companyReport = [
            'company_id' => $companyId,
            'company_name' => (string)$company['company_name'],
            'companies_house_directors_found' => count($preview),
            'structured_directors_available' => count($directors),
            'nominal_mapping' => $mapping,
            'attributions' => 0,
            'ambiguous' => 0,
        ];

        if ($nominalIds === []) {
            $report['ambiguous'][] = ['company_id' => $companyId, 'source' => 'nominal_mapping', 'reason' => 'DLA control nominals are not mapped unambiguously.'];
            $companyReport['ambiguous']++;
            return $companyReport;
        }

        $sources = [
            ['table' => 'transactions', 'type' => 'transaction', 'date' => 'source.txn_date', 'company_join' => '', 'company_column' => 'source.company_id'],
            ['table' => 'transaction_split_lines', 'type' => 'transaction_split_line', 'date' => 't.txn_date', 'company_join' => ' INNER JOIN transaction_splits ts ON ts.id = source.split_id INNER JOIN transactions t ON t.id = ts.transaction_id', 'company_column' => 't.company_id'],
            ['table' => 'expense_claim_lines', 'type' => 'expense_claim_line', 'date' => 'source.expense_date', 'company_join' => ' INNER JOIN expense_claims ec ON ec.id = source.expense_claim_id', 'company_column' => 'ec.company_id'],
        ];

        foreach ($sources as $source) {
            $rows = $this->unattributedSourceRows($companyId, $nominalIds, $source);
            foreach ($rows as $row) {
                $director = $this->deterministicDirector($directors, (string)$row['entry_date']);
                if ($director === null) {
                    $this->recordAmbiguous($report, $companyReport, $companyId, (string)$source['type'], (int)$row['id'], 'No unique director or unique tenure match.');
                    continue;
                }
                if ($apply) {
                    \InterfaceDB::prepareExecute(
                        'UPDATE ' . $source['table'] . ' SET director_id = :director_id WHERE id = :id',
                        ['director_id' => (int)$director['id'], 'id' => (int)$row['id']]
                    );
                    (new DirectorLoanAttributionService())->recordChange(
                        $companyId,
                        (string)$source['type'],
                        (int)$row['id'],
                        null,
                        (int)$director['id'],
                        'director_loan_subledger_backfill',
                        'Deterministic historical director attribution.'
                    );
                }
                $report['attribution_count']++;
                $companyReport['attributions']++;
            }
        }

        $journalLines = $this->unattributedJournalLines($companyId, $nominalIds);
        foreach ($journalLines as $line) {
            $director = $this->deterministicDirector($directors, (string)$line['entry_date']);
            if ($director === null) {
                $this->recordAmbiguous($report, $companyReport, $companyId, 'journal_line', (int)$line['id'], 'No unique director or unique tenure match.');
                continue;
            }
            if ($apply) {
                $result = (new DirectorLoanAttributionService())->assignJournalLine(
                    $companyId,
                    (int)$line['id'],
                    (int)$director['id'],
                    'director_loan_subledger_backfill',
                    'Deterministic historical director attribution.'
                );
                if (empty($result['success'])) {
                    throw new \RuntimeException(implode(' ', (array)$result['errors']));
                }
            }
            $report['attribution_count']++;
            $companyReport['attributions']++;
        }

        $rules = $this->unattributedRules($companyId, $nominalIds);
        foreach ($rules as $rule) {
            $director = count($directors) === 1 ? $directors[0] : null;
            if ($director !== null) {
                if ($apply) {
                    \InterfaceDB::prepareExecute(
                        'UPDATE categorisation_rules SET director_id = :director_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                        ['director_id' => (int)$director['id'], 'id' => (int)$rule['id']]
                    );
                    (new DirectorLoanAttributionService())->recordChange(
                        $companyId,
                        'categorisation_rule',
                        (int)$rule['id'],
                        null,
                        (int)$director['id'],
                        'director_loan_subledger_backfill',
                        'Single-director historical rule attribution.'
                    );
                }
                $report['attribution_count']++;
                $companyReport['attributions']++;
                continue;
            }

            if ($apply && (int)$rule['is_active'] === 1) {
                \InterfaceDB::prepareExecute(
                    'UPDATE categorisation_rules SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    ['id' => (int)$rule['id']]
                );
            }
            if ((int)$rule['is_active'] === 1) {
                $report['disabled_rule_count']++;
            }
            $this->recordAmbiguous($report, $companyReport, $companyId, 'categorisation_rule', (int)$rule['id'], 'Rule has no unambiguous director and is disabled on apply.');
        }

        return $companyReport;
    }

    private function unattributedSourceRows(int $companyId, array $nominalIds, array $source): array
    {
        if (!\InterfaceDB::tableExists((string)$source['table']) || !\InterfaceDB::columnExists((string)$source['table'], 'director_id')) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($nominalIds), '?'));
        $sql = 'SELECT source.id, ' . $source['date'] . ' AS entry_date
                FROM ' . $source['table'] . ' source' . $source['company_join'] . '
                WHERE ' . $source['company_column'] . ' = ?
                  AND source.director_id IS NULL
                  AND source.nominal_account_id IN (' . $placeholders . ')';
        return \InterfaceDB::fetchAll($sql, array_merge([$companyId], $nominalIds));
    }

    private function unattributedJournalLines(int $companyId, array $nominalIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($nominalIds), '?'));
        return \InterfaceDB::fetchAll(
            'SELECT jl.id, j.journal_date AS entry_date
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             WHERE j.company_id = ?
               AND jl.director_id IS NULL
               AND jl.nominal_account_id IN (' . $placeholders . ')
             ORDER BY j.journal_date, jl.id',
            array_merge([$companyId], $nominalIds)
        );
    }

    private function unattributedRules(int $companyId, array $nominalIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($nominalIds), '?'));
        return \InterfaceDB::fetchAll(
            'SELECT id, is_active
             FROM categorisation_rules
             WHERE company_id = ?
               AND director_id IS NULL
               AND nominal_account_id IN (' . $placeholders . ')',
            array_merge([$companyId], $nominalIds)
        );
    }

    private function deterministicDirector(array $directors, string $date): ?array
    {
        if (count($directors) === 1) {
            return $directors[0];
        }
        $matches = array_values(array_filter($directors, static function (array $director) use ($date): bool {
            $appointed = trim((string)($director['appointed_on'] ?? ''));
            $resigned = trim((string)($director['resigned_on'] ?? ''));
            return $date !== ''
                && ($appointed === '' || $appointed <= $date)
                && ($resigned === '' || $resigned >= $date);
        }));
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function nominalMappingPreview(int $companyId): array
    {
        $controls = (new DirectorLoanAttributionService())->controlNominalIds($companyId);
        return [
            'asset_nominal_id' => (int)$controls['asset'],
            'liability_nominal_id' => (int)$controls['liability'],
            'unambiguous' => (int)$controls['asset'] > 0 && (int)$controls['liability'] > 0,
        ];
    }

    private function recordAmbiguous(
        array &$report,
        array &$companyReport,
        int $companyId,
        string $sourceType,
        int $sourceId,
        string $reason
    ): void {
        $report['ambiguous'][] = [
            'company_id' => $companyId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason' => $reason,
        ];
        $companyReport['ambiguous']++;
    }
}
