<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class HmrcSubmissionPackageService
{
    public function locateAccountsIxbrl(int $companyId, int $accountingPeriodId): array
    {
        return (new IxbrlFilingArtifactService())->locate($companyId, $accountingPeriodId);
    }

    public function locateComputationsIxbrl(int $companyId, int $accountingPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return $this->artifactFailure('missing', 'Select a company and accounting period with CT periods.');
        }
        $periods = \InterfaceDB::fetchAll(
            'SELECT id FROM corporation_tax_periods
             WHERE company_id = :company_id AND accounting_period_id = :period_id AND status <> :superseded
             ORDER BY sequence_no, id',
            ['company_id' => $companyId, 'period_id' => $accountingPeriodId, 'superseded' => 'superseded']
        );
        if ($periods === []) {
            return $this->artifactFailure('missing', 'No current CT periods exist for the accounting period.');
        }
        $artifacts = [];
        foreach ($periods as $period) {
            $artifact = $this->locateComputationsIxbrlForCtPeriod($companyId, (int)$period['id']);
            if (empty($artifact['ok'])) {
                return $artifact + ['artifacts' => $artifacts];
            }
            $artifacts[] = $artifact;
        }
        return ['ok' => true, 'state' => 'ready', 'artifacts' => $artifacts, 'errors' => [], 'warnings' => []];
    }

    public function locateComputationsIxbrlForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0 || !\InterfaceDB::tableExists('corporation_tax_periods')) {
            return $this->artifactFailure('missing', 'Select a company and CT period.');
        }
        $period = \InterfaceDB::fetchOne(
            'SELECT id, company_id, accounting_period_id FROM corporation_tax_periods
             WHERE id = :id AND company_id = :company_id AND status <> :superseded LIMIT 1',
            ['id' => $ctPeriodId, 'company_id' => $companyId, 'superseded' => 'superseded']
        );
        if (!is_array($period)) {
            return $this->artifactFailure('mismatched_period', 'The requested CT period does not belong to this company or is superseded.');
        }
        try {
            $status = (new IxbrlTaxComputationService())->status(
                $companyId,
                (int)$period['accounting_period_id'],
                $ctPeriodId
            );
        } catch (\Throwable $exception) {
            return $this->artifactFailure('error', 'The computations iXBRL artifact could not be verified.', [$exception->getMessage()]);
        }
        if (empty($status['fileable'])) {
            $errors = (array)($status['fileable_errors'] ?? $status['artifact_errors'] ?? $status['errors'] ?? []);
            return $this->artifactFailure('not_ready', (string)($errors[0] ?? 'The computations iXBRL artifact is not filing-ready.'), $errors);
        }
        $run = (array)$status['run'];
        return [
            'ok' => true,
            'state' => 'ready',
            'run_id' => (int)$run['id'],
            'ct_period_id' => $ctPeriodId,
            'path' => (string)$run['generated_path'],
            'filename' => (string)$run['generated_filename'],
            'hash' => (string)$run['output_sha256'],
            'warnings' => json_decode((string)($run['external_validation_warnings_json'] ?? '[]'), true) ?: [],
            'errors' => [],
        ];
    }

    public function buildSubmissionEnvelope(int $submissionId): array
    {
        return [
            'ok' => false,
            'path' => null,
            'body' => null,
            'errors' => [
                'CT600 submission is not implemented.',
            ],
        ];
    }

    public function hashPackage(int $submissionId): string
    {
        return '';
    }

    private function submissionUnavailable(): array
    {
        return [
            'ok' => false,
            'state' => 'not_implemented',
            'run_id' => null,
            'path' => null,
            'filename' => null,
            'warnings' => [],
            'errors' => ['CT600 submission is not implemented.'],
            'hash' => null,
        ];
    }

    private function artifactFailure(string $state, string $message, array $errors = []): array
    {
        return [
            'ok' => false,
            'state' => $state,
            'run_id' => null,
            'path' => null,
            'filename' => null,
            'warnings' => [],
            'errors' => $errors !== [] ? array_values(array_map('strval', $errors)) : [$message],
            'hash' => null,
        ];
    }
}
