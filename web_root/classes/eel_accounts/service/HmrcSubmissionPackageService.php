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
        $directory = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'computations';
        $candidates = glob($directory . DIRECTORY_SEPARATOR . 'computations_' . $companyId . '_' . $accountingPeriodId . '*.xhtml') ?: [];
        if ($candidates === []) {
            return [
                'ok' => false,
                'path' => null,
                'filename' => null,
                'warnings' => ['Corporation Tax computations iXBRL generation is not implemented yet.'],
                'errors' => ['Generated computations iXBRL is required before HMRC submission.'],
            ];
        }
        usort($candidates, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return ['ok' => true, 'path' => $candidates[0], 'filename' => basename($candidates[0]), 'warnings' => [], 'errors' => []];
    }

    public function locateComputationsIxbrlForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        $directory = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'computations';
        $candidates = glob($directory . DIRECTORY_SEPARATOR . 'computations_' . $companyId . '_ct_' . $ctPeriodId . '*.xhtml') ?: [];
        if ($candidates === []) {
            return [
                'ok' => false,
                'path' => null,
                'filename' => null,
                'warnings' => ['Corporation Tax computations iXBRL generation is not implemented yet.'],
                'errors' => ['Generated computations iXBRL is required before HMRC submission.'],
            ];
        }
        usort($candidates, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));

        return ['ok' => true, 'path' => $candidates[0], 'filename' => basename($candidates[0]), 'warnings' => [], 'errors' => []];
    }

    public function buildSubmissionEnvelope(int $submissionId): array
    {
        $submission = $this->submission($submissionId);
        if ($submission === null) {
            return ['ok' => false, 'path' => null, 'errors' => ['Submission draft could not be found.']];
        }
        $scope = (new \eel_accounts\Service\VatSupportScopeService())->fetchForCompany((int)($submission['company_id'] ?? 0));
        if (!empty($scope['tax_year_end_read_only'])) {
            return ['ok' => false, 'path' => null, 'errors' => [(string)($scope['message'] ?? \eel_accounts\Service\VatSupportScopeService::UNSUPPORTED_MESSAGE)]];
        }
        $currentAccounts = $this->locateAccountsIxbrl(
            (int)($submission['company_id'] ?? 0),
            (int)($submission['accounting_period_id'] ?? 0)
        );
        if (empty($currentAccounts['ok'])) {
            return [
                'ok' => false,
                'path' => null,
                'errors' => (array)($currentAccounts['errors'] ?? ['Current accounts iXBRL could not be located.']),
            ];
        }
        $submissionAccountsPath = (string)($submission['accounts_ixbrl_path'] ?? '');
        $currentAccountsPath = (string)($currentAccounts['path'] ?? '');
        if ($submissionAccountsPath === ''
            || $currentAccountsPath === ''
            || realpath($submissionAccountsPath) !== realpath($currentAccountsPath)) {
            return [
                'ok' => false,
                'path' => null,
                'errors' => ['The submission draft references an outdated accounts iXBRL file. Refresh the draft after rebuilding the accounts export.'],
            ];
        }
        foreach (['ct600_xml_path', 'accounts_ixbrl_path', 'computations_ixbrl_path'] as $field) {
            $path = (string)($submission[$field] ?? '');
            if ($path === '' || !is_file($path)) {
                return ['ok' => false, 'path' => null, 'errors' => ['Submission package is missing ' . $field . '.']];
            }
        }

        $directory = APP_ROOT . 'outbound' . DIRECTORY_SEPARATOR . 'hmrc' . DIRECTORY_SEPARATOR . 'packages';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['ok' => false, 'path' => null, 'errors' => ['Could not create HMRC package directory.']];
        }
        $path = $directory . DIRECTORY_SEPARATOR . 'hmrc_ct600_package_' . $submissionId . '.xml';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!-- Internal draft HMRC CT package envelope. TODO: align with HMRC Corporation Tax Online XML schema before production filing. -->' . "\n"
            . '<HmrcCt600SubmissionPackage id="' . (int)$submissionId . '">' . "\n"
            . '  <CT600><![CDATA[' . (string)file_get_contents((string)$submission['ct600_xml_path']) . ']]></CT600>' . "\n"
            . '  <AccountsIxbrl filename="' . $this->e(basename((string)$submission['accounts_ixbrl_path'])) . '"><![CDATA[' . (string)file_get_contents((string)$submission['accounts_ixbrl_path']) . ']]></AccountsIxbrl>' . "\n"
            . '  <ComputationsIxbrl filename="' . $this->e(basename((string)$submission['computations_ixbrl_path'])) . '"><![CDATA[' . (string)file_get_contents((string)$submission['computations_ixbrl_path']) . ']]></ComputationsIxbrl>' . "\n"
            . '</HmrcCt600SubmissionPackage>' . "\n";
        if (file_put_contents($path, $xml) === false) {
            return ['ok' => false, 'path' => null, 'errors' => ['Could not write HMRC submission package.']];
        }

        return ['ok' => true, 'path' => $path, 'body' => $xml, 'errors' => []];
    }

    public function hashPackage(int $submissionId): string
    {
        $envelope = $this->buildSubmissionEnvelope($submissionId);
        if (empty($envelope['ok']) || !is_file((string)$envelope['path'])) {
            return '';
        }

        return (string)hash_file('sha256', (string)$envelope['path']);
    }

    private function submission(int $submissionId): ?array
    {
        if ($submissionId <= 0 || !\InterfaceDB::tableExists('hmrc_ct600_submissions')) {
            return null;
        }
        $row = \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct600_submissions WHERE id = :id LIMIT 1', ['id' => $submissionId]);

        return is_array($row) ? $row : null;
    }

    private function accountsFailure(string $state, string $message, int $runId = 0): array
    {
        return [
            'ok' => false,
            'state' => $state,
            'run_id' => $runId > 0 ? $runId : null,
            'path' => null,
            'filename' => null,
            'warnings' => [],
            'errors' => [$message],
            'hash' => null,
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
