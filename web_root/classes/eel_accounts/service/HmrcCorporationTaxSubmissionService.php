<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/** Fail-closed compatibility facade retained for existing callers. */
final class HmrcCorporationTaxSubmissionService
{
    public function validatePackage(int $companyId, int $ctPeriodId, string $mode): array
    {
        return $this->retiredResult();
    }

    public function createSubmissionDraft(int $companyId, int $ctPeriodId, string $mode): array
    {
        return $this->retiredResult();
    }

    public function submit(int $submissionId, callable $logger): array
    {
        $message = $this->retiredMessage();
        $logger('error', $message);
        return ['success' => false, 'errors' => [$message]];
    }

    /** @return list<array<string, mixed>> */
    public function getSubmissionHistory(int $companyId, ?int $accountingPeriodId = null): array
    {
        return [];
    }

    public function getLatestSubmission(int $companyId, int $accountingPeriodId): ?array
    {
        return $this->getSubmissionHistory($companyId, $accountingPeriodId)[0] ?? null;
    }

    public function getLatestSubmissionForCtPeriod(int $companyId, int $ctPeriodId): ?array
    {
        return null;
    }

    public function event(int $submissionId, string $level, string $message, array $context = []): void
    {
        // Submission persistence is intentionally disabled.
    }

    /** Runtime schema creation is intentionally disabled. */
    public function ensureSchema(): void
    {
        throw new \LogicException($this->retiredMessage());
    }

    /** @return array<string, mixed> */
    private function retiredResult(): array
    {
        return [
            'success' => false,
            'submission_id' => 0,
            'errors' => [$this->retiredMessage()],
            'warnings' => [],
            'validation' => [],
        ];
    }

    private function retiredMessage(): string
    {
        return 'CT600 submission is not implemented.';
    }
}
