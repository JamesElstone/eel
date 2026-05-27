<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ActivityFeedService
{
    public function __construct(
        private readonly ?AccountingAuditRepository $auditRepository = null,
        private readonly ?UserHistoryStore $userHistoryStore = null,
    ) {
    }

    public function fetchRecentActivity(int $companyId = 0, int $accountingPeriodId = 0, int $limit = 12, string $window = '7_days'): array
    {
        $limit = max(1, min(50, $limit));
        $fetchLimit = min(200, max($limit * 4, 25));
        $auditRepository = $this->auditRepository ?? new AccountingAuditRepository();
        $userHistoryStore = $this->userHistoryStore ?? new UserHistoryStore();
        $items = [];

        foreach ($auditRepository->fetchRecentTransactionCategoryAudit($fetchLimit) as $row) {
            $rowCompanyId = (int)($row['company_id'] ?? 0);
            $rowAccountingPeriodId = (int)($row['accounting_period_id'] ?? 0);
            if (($companyId > 0 && $rowCompanyId !== $companyId) || ($accountingPeriodId > 0 && $rowAccountingPeriodId !== $accountingPeriodId)) {
                continue;
            }

            $items[] = $this->transactionAuditItem($row);
        }

        foreach ($auditRepository->fetchRecentYearEndAudit($fetchLimit) as $row) {
            $rowCompanyId = (int)($row['company_id'] ?? 0);
            $rowAccountingPeriodId = (int)($row['accounting_period_id'] ?? 0);
            if (($companyId > 0 && $rowCompanyId !== $companyId) || ($accountingPeriodId > 0 && $rowAccountingPeriodId !== $accountingPeriodId)) {
                continue;
            }

            $items[] = $this->yearEndAuditItem($row);
        }

        foreach ($userHistoryStore->fetchRecentAccountAudit($fetchLimit) as $row) {
            $items[] = $this->userAccountAuditItem($row);
        }

        usort(
            $items,
            static fn(array $left, array $right): int => strcmp((string)($right['occurred_at'] ?? ''), (string)($left['occurred_at'] ?? ''))
        );

        return array_slice($this->filterByWindow($items, $window), 0, $limit);
    }

    private function transactionAuditItem(array $row): array
    {
        $description = trim((string)($row['transaction_description'] ?? ''));
        if ($description === '') {
            $description = 'Transaction #' . (string)($row['transaction_id'] ?? '');
        }

        $oldLabel = $this->categoryAuditValueLabel(
            (string)($row['old_nominal_name'] ?? ''),
            (string)($row['old_category_status'] ?? ''),
            (int)($row['old_is_auto_excluded'] ?? 0)
        );
        $newLabel = $this->categoryAuditValueLabel(
            (string)($row['new_nominal_name'] ?? ''),
            (string)($row['new_category_status'] ?? ''),
            (int)($row['new_is_auto_excluded'] ?? 0)
        );

        return [
            'type' => 'transaction_category',
            'occurred_at' => (string)($row['changed_at'] ?? ''),
            'title' => 'Transaction categorised',
            'detail' => $description . ': ' . $oldLabel . ' to ' . $newLabel,
            'meta' => (string)($row['changed_by'] ?? ''),
        ];
    }

    private function yearEndAuditItem(array $row): array
    {
        $action = HelperFramework::labelFromKey((string)($row['action'] ?? ''), '_');
        $period = $this->periodLabel($row);
        $notes = trim((string)($row['notes'] ?? ''));

        return [
            'type' => 'year_end',
            'occurred_at' => (string)($row['action_at'] ?? ''),
            'title' => 'Year-end ' . strtolower($action !== '' ? $action : 'activity'),
            'detail' => trim($period . ($notes !== '' ? ': ' . $notes : '')),
            'meta' => (string)($row['action_by'] ?? ''),
        ];
    }

    private function userAccountAuditItem(array $row): array
    {
        $action = HelperFramework::labelFromKey((string)($row['action_type'] ?? ''), '_');
        $affectedUser = trim((string)($row['affected_user_display_name'] ?? ''));
        $actor = trim((string)($row['actor_user_display_name'] ?? 'System'));
        $reason = trim((string)($row['reason'] ?? ''));

        return [
            'type' => 'user_account',
            'occurred_at' => (string)($row['created_at'] ?? ''),
            'title' => 'User account ' . strtolower($action !== '' ? $action : 'activity'),
            'detail' => ($affectedUser !== '' ? $affectedUser : 'User account') . ($reason !== '' ? ': ' . $reason : ''),
            'meta' => $actor,
        ];
    }

    private function categoryAuditValueLabel(string $nominalName, string $status, int $isAutoExcluded): string
    {
        $parts = [];
        $nominalName = trim($nominalName);
        $status = trim($status);

        if ($nominalName !== '') {
            $parts[] = $nominalName;
        }

        if ($status !== '') {
            $parts[] = str_replace('_', ' ', $status);
        }

        if ($isAutoExcluded === 1) {
            $parts[] = 'auto excluded';
        }

        return $parts !== [] ? implode(' | ', $parts) : 'not set';
    }

    private function periodLabel(array $row): string
    {
        $start = trim((string)($row['accounting_period_start'] ?? ''));
        $end = trim((string)($row['accounting_period_end'] ?? ''));

        if ($start !== '' && $end !== '') {
            return $start . ' to ' . $end;
        }

        $accountingPeriodId = (int)($row['accounting_period_id'] ?? 0);

        return $accountingPeriodId > 0 ? 'Tax year #' . $accountingPeriodId : 'No accounting period';
    }

    private function filterByWindow(array $items, string $window): array
    {
        $window = $this->normaliseWindow($window);
        $now = new DateTimeImmutable('now');
        $cutoff = match ($window) {
            '1_day' => $now->modify('-1 day'),
            'this_month' => $now->modify('first day of this month')->setTime(0, 0, 0),
            default => $now->modify('-7 days'),
        };

        return array_values(array_filter($items, static function (array $item) use ($cutoff): bool {
            $occurredAt = trim((string)($item['occurred_at'] ?? ''));
            if ($occurredAt === '') {
                return false;
            }

            try {
                return new DateTimeImmutable($occurredAt) >= $cutoff;
            } catch (Throwable) {
                return false;
            }
        }));
    }

    private function normaliseWindow(string $window): string
    {
        return in_array($window, ['1_day', '7_days', 'this_month'], true) ? $window : '7_days';
    }
}
