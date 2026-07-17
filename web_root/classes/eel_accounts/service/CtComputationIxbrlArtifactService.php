<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CtComputationIxbrlArtifactService
{
    /** @return array<string, mixed> */
    public function locate(int $companyId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0
            || !\InterfaceDB::tableExists('corporation_tax_periods')
            || !\InterfaceDB::tableExists('corporation_tax_computation_runs')) {
            return $this->failure('missing', 'No persisted Corporation Tax computation is available for this CT period.');
        }

        $requiredColumns = [
            'generated_path', 'validation_status', 'external_validation_status',
            'output_sha256', 'external_validated_sha256', 'taxonomy_profile',
        ];
        foreach ($requiredColumns as $column) {
            if (!\InterfaceDB::columnExists('corporation_tax_computation_runs', $column)) {
                return $this->failure(
                    'missing',
                    'The computations iXBRL artifact contract has not been installed. Run the latest downstream database migration.'
                );
            }
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT cr.*, ctp.accounting_period_id, ctp.latest_computation_run_id
             FROM corporation_tax_periods ctp
             INNER JOIN corporation_tax_computation_runs cr
               ON cr.id = ctp.latest_computation_run_id
             WHERE ctp.company_id = :company_id
               AND ctp.id = :ct_period_id
               AND cr.company_id = ctp.company_id
               AND cr.ct_period_id = ctp.id
             LIMIT 1',
            ['company_id' => $companyId, 'ct_period_id' => $ctPeriodId]
        );
        if (!is_array($row)) {
            return $this->failure('missing', 'No locked computation run exists for this CT period.');
        }

        $runId = (int)($row['id'] ?? 0);
        if ((string)($row['status'] ?? '') !== 'generated') {
            return $this->failure('missing', 'The locked computation run has not generated a filing artifact.', $runId);
        }
        if ((string)($row['validation_status'] ?? '') !== 'passed') {
            return $this->failure('unvalidated', 'The computations iXBRL has not passed internal validation.', $runId);
        }
        if ((string)($row['external_validation_status'] ?? '') !== 'passed') {
            return $this->failure('unvalidated', 'The computations iXBRL has not passed Arelle validation.', $runId);
        }

        $path = trim((string)($row['generated_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return $this->failure('missing', 'The generated computations iXBRL file was not found.', $runId);
        }

        $outputHash = strtolower(trim((string)($row['output_sha256'] ?? '')));
        $validatedHash = strtolower(trim((string)($row['external_validated_sha256'] ?? '')));
        $fileHash = strtolower((string)(hash_file('sha256', $path) ?: ''));
        if ($outputHash === '' || $validatedHash === '') {
            return $this->failure('unvalidated', 'The computations iXBRL validation fingerprints are incomplete.', $runId);
        }
        if (!hash_equals($outputHash, $validatedHash) || !hash_equals($outputHash, $fileHash)) {
            return $this->failure('tampered', 'The computations iXBRL does not match the generated and Arelle-validated artifact.', $runId);
        }

        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => $runId,
            'accounting_period_id' => (int)($row['accounting_period_id'] ?? 0),
            'ct_period_id' => $ctPeriodId,
            'path' => $path,
            'filename' => basename($path),
            'hash' => $outputHash,
            'computation_hash' => (string)($row['computation_hash'] ?? ''),
            'taxonomy_profile' => (string)($row['taxonomy_profile'] ?? ''),
            'warnings' => [],
            'errors' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function failure(string $state, string $message, int $runId = 0): array
    {
        return [
            'ok' => false,
            'state' => $state,
            'run_id' => $runId > 0 ? $runId : null,
            'path' => null,
            'filename' => null,
            'hash' => null,
            'warnings' => [],
            'errors' => [$message],
        ];
    }
}
