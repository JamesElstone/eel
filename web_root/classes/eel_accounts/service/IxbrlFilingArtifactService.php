<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Locates the one immutable accounts artifact that is safe to hand to a filing provider. */
final class IxbrlFilingArtifactService
{
    public function locate(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return $this->failure('missing', 'Select a valid company and accounting period.');
        }
        if (!\InterfaceDB::tableExists('ixbrl_generation_runs')) {
            return $this->failure('missing', 'Accounts iXBRL generation table is missing.');
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ixbrl_generation_runs
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             ORDER BY id DESC
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
        if (!is_array($row)) {
            return $this->failure('missing', 'No accounts iXBRL run exists for this period.');
        }

        $runId = (int)($row['id'] ?? 0);
        if ((string)($row['status'] ?? '') !== 'generated') {
            $failed = (string)($row['status'] ?? '') === 'failed';
            return $this->failure(
                $failed ? 'validation_failed' : 'missing',
                $failed
                    ? 'The latest iXBRL generation run failed.'
                    : 'The latest iXBRL run has not produced an accounts filing artifact.',
                $runId
            );
        }

        $freshness = (new IxbrlFactBuilderService())->getRunFreshness($runId);
        if ((string)($freshness['state'] ?? '') !== 'current') {
            return $this->failure(
                'stale',
                (string)($freshness['detail'] ?? 'The generated accounts iXBRL is stale and must be rebuilt.'),
                $runId
            );
        }

        $internalStatus = (string)($row['validation_status'] ?? 'not_validated');
        if ($internalStatus !== 'passed') {
            $state = $internalStatus === 'failed' ? 'validation_failed' : 'unvalidated';
            return $this->failure(
                $state,
                $state === 'validation_failed'
                    ? 'The latest accounts iXBRL failed internal validation.'
                    : 'The latest accounts iXBRL has not passed internal validation.',
                $runId
            );
        }

        $externalStatus = (string)($row['external_validation_status'] ?? 'not_configured');
        if ($externalStatus !== 'passed') {
            $state = in_array($externalStatus, ['failed', 'error', 'tampered'], true)
                ? 'validation_failed'
                : 'unvalidated';
            return $this->failure(
                $state,
                $state === 'validation_failed'
                    ? 'The latest accounts iXBRL did not pass Arelle validation.'
                    : 'The latest accounts iXBRL has not passed Arelle validation.',
                $runId
            );
        }

        $path = trim((string)($row['generated_path'] ?? ''));
        if ($path === '' || !is_file($path)) {
            return $this->failure('missing', 'The latest generated accounts iXBRL/XHTML file was not found.', $runId);
        }

        $outputHash = strtolower(trim((string)($row['output_sha256'] ?? '')));
        $validatedHash = strtolower(trim((string)($row['external_validated_sha256'] ?? '')));
        if ($outputHash === '' || $validatedHash === '') {
            return $this->failure(
                'unvalidated',
                'The latest accounts iXBRL does not have complete generation and Arelle fingerprints.',
                $runId
            );
        }
        if (!hash_equals($outputHash, $validatedHash)) {
            return $this->failure(
                'tampered',
                'The generated accounts iXBRL does not match the artifact validated by Arelle.',
                $runId
            );
        }

        $fileHash = hash_file('sha256', $path);
        if (!is_string($fileHash) || !hash_equals($outputHash, strtolower($fileHash))) {
            return $this->failure(
                'tampered',
                'The generated accounts iXBRL file has changed since it was generated and validated.',
                $runId
            );
        }

        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => $runId,
            'path' => $path,
            'filename' => basename($path),
            'warnings' => [],
            'errors' => [],
            'hash' => $outputHash,
            'basis_hash' => (string)($row['basis_hash'] ?? ''),
        ];
    }

    private function failure(string $state, string $message, int $runId = 0): array
    {
        return [
            'ok' => false,
            'state' => $state,
            'run_id' => $runId,
            'path' => null,
            'filename' => null,
            'warnings' => [],
            'errors' => [$message],
            'hash' => null,
            'basis_hash' => null,
        ];
    }
}
