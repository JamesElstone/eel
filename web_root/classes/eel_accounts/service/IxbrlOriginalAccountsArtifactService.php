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
        private readonly ?IxbrlAccountingService $accountingService = null,
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

        if ($this->artifactService !== null) {
            // Legacy test seam only. Runtime generation renders Companies House
            // bytes directly from the approved fact snapshot below.
            $base = $this->artifactService->locate($companyId, $accountingPeriodId);
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
        } else {
            $rendered = ($this->accountingService ?? new IxbrlAccountingService())->buildAuthorityDocument(
                $companyId,
                $accountingPeriodId,
                IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS,
                $evidenceArtifactId
            );
            if (empty($rendered['success'])) {
                return [
                    'success' => false,
                    'errors' => (array)($rendered['errors'] ?? ['The Companies House accounts iXBRL could not be rendered.']),
                    'warnings' => (array)($rendered['warnings'] ?? []),
                ];
            }
            $run = (array)$rendered['run'];
            $source = (string)$rendered['xhtml'];
            $base = [
                'ok' => true,
                'run_id' => (int)($run['id'] ?? 0),
                'filing_approval_id' => (int)($run['filing_approval_id'] ?? 0),
                'basis_hash' => (string)($run['basis_hash'] ?? ''),
            ];
        }
        $source = (new IxbrlEvidenceFooterService())->withFooter($source, $evidenceArtifactId);
        $sourceValidation = $this->validateSource($source);
        if (empty($sourceValidation['success'])) {
            return $sourceValidation;
        }
        $authorityValidation = (new IxbrlAuthorityValidationService())->validate(
            $source,
            IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS
        );
        if (empty($authorityValidation['ok'])) {
            return [
                'success' => false,
                'errors' => $this->authorityErrors($authorityValidation),
                'warnings' => [],
                'authority_validation' => $authorityValidation,
            ];
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
        $basisHash = hash(
            'sha256',
            \eel_accounts\Support\Utf8::json($basis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
        $profile = (new IxbrlAuthorityProfileService())->profile(
            IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS
        );
        try {
            $activeTaxonomyPackage = (new FrcTaxonomyPackageService())->activePackage();
            $artifactRecord = [
                'generation_run_id' => (int)$base['run_id'],
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'filing_approval_id' => (int)($base['filing_approval_id'] ?? 0),
                'authority' => \eel_accounts\Repository\IxbrlAccountsArtifactRepository::AUTHORITY_COMPANIES_HOUSE,
                'filing_kind' => 'original',
                'render_model_sha256' => $basisHash,
                'taxonomy_profile' => IxbrlTaxonomyProfileService::PROFILE,
                'generation_status' => 'generated',
                'output_path' => $path,
                'output_filename' => $filename,
                'output_sha256' => $sha256,
            ];
            $taxonomyPackageId = is_array($activeTaxonomyPackage)
                ? (int)($activeTaxonomyPackage['id'] ?? 0)
                : 0;
            $taxonomyPackageHash = is_array($activeTaxonomyPackage)
                ? strtolower(trim((string)($activeTaxonomyPackage['sha256'] ?? '')))
                : '';
            if ($taxonomyPackageId > 0 && preg_match('/^[a-f0-9]{64}$/', $taxonomyPackageHash) === 1) {
                $artifactRecord['taxonomy_package_id'] = $taxonomyPackageId;
                $artifactRecord['taxonomy_package_sha256'] = $taxonomyPackageHash;
            }
            $artifactId = (new IxbrlValidationEvidenceService())->createAccountsArtifact($artifactRecord, $profile);
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }

        $this->reportProgress(
            $progress,
            'Running Arelle validation for the Companies House original-accounts iXBRL…',
            45
        );
        $validation = ($this->validationService ?? new IxbrlExternalValidationService())
            ->validateArtifact(
                $path,
                [],
                [],
                IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS
            );
        try {
            $validationRunId = (new IxbrlValidationEvidenceService())->recordAccountsValidation(
                $artifactId,
                $profile,
                'passed',
                $sourceValidation,
                'passed',
                [],
                $authorityValidation,
                $validation
            );
        } catch (\Throwable $exception) {
            return $this->failure($exception->getMessage());
        }
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

        return [
            'success' => true,
            'errors' => [],
            'warnings' => (array)($validation['warnings'] ?? []),
            'path' => $path,
            'filename' => $filename,
            'sha256' => $sha256,
            'validated_sha256' => $validatedHash,
            'basis_hash' => $basisHash,
            'base_run_id' => (int)$base['run_id'],
            'base_sha256' => $sha256,
            'fact_count' => count($matches[0] ?? []),
            'declarations' => [
                'accounts_approval_date' => (string)($classification['accounts_approval_date'] ?? ''),
            ],
            'validation' => $validation,
            'accounts_artifact_id' => $artifactId,
            'accounts_validation_run_id' => $validationRunId,
            'authority_validation' => $authorityValidation,
            'authority_profile' => IxbrlAuthorityProfileService::COMPANIES_HOUSE_ACCOUNTS,
            'authority_profile_fingerprint' => (string)($authorityValidation['profile_fingerprint'] ?? ''),
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

        $uploads = \eel_accounts\Store\AccountingConfigurationStore::uploads();
        $uploadRoot = trim((string)($uploads['upload_base_dir'] ?? ''));
        if ($uploadRoot === '') {
            $uploadRoot = rtrim((string)PROJECT_ROOT, '\\/') . DIRECTORY_SEPARATOR . 'files';
        }

        return rtrim($uploadRoot, '\\/')
            . DIRECTORY_SEPARATOR . $companyNumber
            . DIRECTORY_SEPARATOR . 'ixbrl';
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'errors' => [$message], 'warnings' => []];
    }

    /** @return list<string> */
    private function authorityErrors(array $validation): array
    {
        $errors = [];
        foreach ((array)($validation['errors'] ?? []) as $error) {
            $message = is_array($error) ? trim((string)($error['message'] ?? '')) : trim((string)$error);
            if ($message !== '') {
                $errors[] = $message;
            }
        }

        return $errors !== []
            ? array_values(array_unique($errors))
            : ['The accounts do not satisfy the Companies House iXBRL authority profile.'];
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
