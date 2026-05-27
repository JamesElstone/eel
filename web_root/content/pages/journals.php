<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journals extends PageContextFramework
{
    public function id(): string
    {
        return 'journals';
    }

    public function title(): string
    {
        return 'Journals';
    }

    public function subtitle(): string
    {
        return 'Review posted journals for the selected company and accounting period.';
    }

    public function cards(): array
    {
        return ['journals_list'];
    }

    protected function moduleContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult,
        array $baseContext
    ): array {
        $company = (array)($baseContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);

        return [
            'journal_entries' => ($companyId > 0 && $accountingPeriodId > 0)
                ? (new TransactionJournalService())->fetchJournals($companyId, $accountingPeriodId)
                : [],
        ];
    }
}
