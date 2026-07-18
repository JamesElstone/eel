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
        return $this->submissionUnavailable();
    }

    public function locateComputationsIxbrlForCtPeriod(int $companyId, int $ctPeriodId): array
    {
        return $this->submissionUnavailable();
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
}
