<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Read-only compatibility facade for the retired REST/draft submission flow.
 * New callers must use HmrcCtSubmissionOrchestrator.
 */
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
        if ($companyId <= 0 || $accountingPeriodId === null || $accountingPeriodId <= 0) {
            return [];
        }
        try {
            return (new HmrcCtSubmissionRepository())->fetchForAccountingPeriod(
                $companyId,
                $accountingPeriodId,
                100
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function getLatestSubmission(int $companyId, int $accountingPeriodId): ?array
    {
        return $this->getSubmissionHistory($companyId, $accountingPeriodId)[0] ?? null;
    }

    public function getLatestSubmissionForCtPeriod(int $companyId, int $ctPeriodId): ?array
    {
        if ($companyId <= 0 || $ctPeriodId <= 0) {
            return null;
        }
        try {
            return (new HmrcCtSubmissionRepository())->fetchLatestForCtPeriod($companyId, $ctPeriodId);
        } catch (\Throwable) {
            return null;
        }
    }

    public function event(int $submissionId, string $level, string $message, array $context = []): void
    {
        if ($submissionId <= 0) {
            return;
        }
        $repository = new HmrcCtSubmissionRepository();
        $row = $repository->fetchById($submissionId);
        if (!is_array($row)) {
            return;
        }
        $repository->recordEvent(
            $submissionId,
            (int)$row['company_id'],
            $level,
            $message,
            $context
        );
    }

    /** Migration guard only; runtime DDL is intentionally forbidden. */
    public function ensureSchema(): void
    {
        (new HmrcCtSubmissionRepository())->requireSchema();
        foreach (['tax_loss_carryforwards', 'tax_loss_movement_history'] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                throw new \RuntimeException('Run the downstream database migrations before using Corporation Tax filing.');
            }
        }
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
        return 'The legacy REST/draft CT600 flow is retired. Use the locked-Year-End GovTalk workflow through HmrcCtSubmissionOrchestrator.';
    }
}
