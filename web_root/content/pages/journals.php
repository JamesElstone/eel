<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _journals extends BaseModulePageFramework
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
        $companyId = (int)($baseContext['company_id'] ?? 0);
        $taxYearId = (int)($baseContext['tax_year_id'] ?? 0);

        return [
            'journal_entries' => ($companyId > 0 && $taxYearId > 0)
                ? (new TransactionJournalService())->fetchJournals($companyId, $taxYearId)
                : [],
        ];
    }
}
