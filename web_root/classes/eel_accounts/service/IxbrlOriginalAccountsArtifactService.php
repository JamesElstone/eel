<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Freezes the approved ordinary accounts iXBRL for an original Companies House filing. */
final class IxbrlOriginalAccountsArtifactService
{
    private const REVISED_MARKER = 'ReportAnAmendedRevisedVersionPreviouslyFiledReportTruefalse';

    public function __construct(
        private readonly ?IxbrlFilingArtifactService $artifactService = null,
        private readonly ?IxbrlExternalValidationService $validationService = null,
        private readonly ?string $outputDirectory = null,
    ) {
    }

    public function prepare(
        int $companyId,
        int $accountingPeriodId,
        array $classification,
        string $evidenceArtifactId = '',
        mixed $progress = null
    ): array {
        if ((string)($classification['filing_kind'] ?? '') !== 'original'
            || trim((string)($classification['approval_basis_hash'] ?? '')) === '') {
            return $this->failure('A current approved Original filing classification is required.');
        }

        $base = ($this->artifactService ?? new IxbrlFilingArtifactService())
            ->locate($companyId, $accountingPeriodId);
        if (empty($base['ok'])) {
            return [
                'success' => false,
                'errors' => (array)($base['errors'] ?? ['A filing-ready Accounting iXBRL artifact is required.']),
                'warnings' => [],
            ];
        }
        $source = file_get_contents((string)$base['path']);
        if (!is_string($source) || $source === '') {
            return $this->failure('The approved Accounting iXBRL artifact could not be read.');
        }
        $sourceValidation = $this->validateSource($source);
        if (empty($sourceValidation['success'])) {
            return $sourceValidation;
        }

        $period = \InterfaceDB::fetchOne(
            'SELECT ap.period_start, ap.period_end, c.company_number
             FROM accounting_periods ap
             INNER JOIN companies c ON c.id = ap.company_id
             WHERE ap.id = :id AND ap.company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );
        if (!is_array($period)) {
            return $this->failure('The selected accounting period was not found.');
        }

        $sha256 = hash('sha256', $source);
        try {
            $filename = (new IxbrlArtifactFilenameService())->build(
                (string)$period['company_number'],
                $accountingPeriodId,
                (int)($base['filing_approval_id'] ?? 0),
                (int)$base['run_id'],
                IxbrlArtifactFilenameService::DESTINATION_COMPANIES_HOUSE,
                str_replace('-', '', (string)$period['period_start']),
                str_replace('-', '', (string)$period['period_end']),
                $sha256
            );
            $directory = $this->managedDirectory($companyId);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->failure('Could not create the Companies House artifact directory.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if (!is_string($existingHash) || !hash_equals($sha256, strtolower($existingHash))) {
                return $this->failure('The original-accounts artifact filename is occupied by different content.');
            }
        } else {
            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                return $this->failure('Could not create the original-accounts artifact.');
            }
            try {
                if (!flock($handle, LOCK_EX)
                    || fwrite($handle, $source) !== strlen($source)
                    || !fflush($handle)) {
                    throw new \RuntimeException('Could not write the original-accounts artifact.');
                }
            } catch (\Throwable $exception) {
                fclose($handle);
                @unlink($path);
                return $this->failure($exception->getMessage());
            }
            fclose($handle);
        }

        $this->reportProgress(
            $progress,
            'Running Arelle validation for the Companies House original-accounts iXBRL…',
            45
        );
        $validation = ($this->validationService ?? new IxbrlExternalValidationService())
            ->validateArtifact($path);
        if ((string)($validation['status'] ?? '') !== 'passed') {
            return [
                'success' => false,
                'errors' => (array)($validation['errors'] ?? ['The original accounts did not pass Arelle validation.']),
                'warnings' => (array)($validation['warnings'] ?? []),
                'validation' => $validation,
            ];
        }
        $validatedHash = strtolower(trim((string)($validation['validated_sha256'] ?? '')));
        if ($validatedHash === '' || !hash_equals($sha256, $validatedHash)) {
            return $this->failure('The original artifact does not match the file validated by Arelle.');
        }

        preg_match_all('/<ix:(?:nonNumeric|nonFraction)\b/i', $source, $matches);
        $basis = [
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'filing_kind' => 'original',
            'classification_approval_hash' => (string)$classification['approval_basis_hash'],
            'base_run_id' => (int)$base['run_id'],
            'base_sha256' => $sha256,
            'base_basis_hash' => (string)($base['basis_hash'] ?? ''),
        ];

        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($validation['warnings'] ?? []),
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
            'validated_sha256' => $validatedHash,
            'basis_hash' => hash('sha256', \eel_accounts\Support\Utf8::json($basis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'base_run_id' => (int)$base['run_id'],
            'base_sha256' => $sha256,
            'fact_count' => count($matches[0] ?? []),
            'declarations' => [
                'accounts_approval_date' => (string)($classification['accounts_approval_date'] ?? ''),
            ],
            'validation' => $validation,
            'evidence_artifact_id' => $evidenceArtifactId,
        ];
    }

    public function validateSource(string $source): array
    {
        if (trim($source) === '') {
            return $this->failure('The original accounts iXBRL is empty.');
        }
        if (str_contains($source, self::REVISED_MARKER)) {
            return $this->failure('Original accounts must not contain the amended/revised report marker.');
        }

        return ['success' => true, 'errors' => [], 'warnings' => []];
    }

    private function managedDirectory(int $companyId): string
    {
        if ($this->outputDirectory !== null && trim($this->outputDirectory) !== '') {
            return rtrim($this->outputDirectory, '\\/');
        }
        $company = \InterfaceDB::fetchOne(
            'SELECT company_number FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        );
        $companyNumber = strtoupper((string)preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            trim((string)($company['company_number'] ?? ''))
        ));
        if ($companyNumber === '') {
            throw new \RuntimeException('A Companies House number is required to store the original iXBRL artifact.');
        }

        return rtrim((string)PROJECT_ROOT, '\\/')
            . DIRECTORY_SEPARATOR . 'files'
            . DIRECTORY_SEPARATOR . $companyNumber
            . DIRECTORY_SEPARATOR . 'ixbrl';
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'errors' => [$message], 'warnings' => []];
    }

    private function reportProgress(mixed $progress, string $message, int $percent): void
    {
        if ($progress instanceof \ActionProgressFramework) {
            $progress->report($message, $percent);
        } elseif (is_callable($progress)) {
            $progress($message, $percent);
        }
    }
}
