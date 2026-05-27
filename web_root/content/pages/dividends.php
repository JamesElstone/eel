<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class _dividends extends PageContextFramework
{
    public function id(): string
    {
        return 'dividends';
    }

    public function title(): string
    {
        return 'Dividends';
    }

    public function subtitle(): string
    {
        return 'Review dividend capacity, declare conservative interim dividends, and inspect posted dividend history.';
    }

    public function cards(): array
    {
        return [
            'dividend_capacity',
            'dividend_declare',
            'dividend_history',
            'dividend_warnings',
        ];
    }

    public function cardLayout(): array
    {
        return [
            [
                'tab' => 'Overview',
                'cards' => [
                    'dividend_capacity',
                    'dividend_warnings',
                ],
            ],
            [
                'tab' => 'Declare Dividend',
                'cards' => [
                    'dividend_declare',
                ],
            ],
            [
                'tab' => 'History',
                'cards' => [
                    'dividend_history',
                ],
            ],
        ];
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
        $asAtDate = trim((string)$request->input('as_at_date', ''));
        $dividendService = new DividendService();

        $nominals = $companyId > 0
            ? $dividendService->ensureDividendNominals($companyId)
            : ['available' => false, 'accounts' => [], 'errors' => []];

        return [
            'dividends' => [
                'as_at_date' => $asAtDate,
                'capacity' => $companyId > 0 && $accountingPeriodId > 0
                    ? $dividendService->getDividendCapacity($companyId, $accountingPeriodId, $asAtDate !== '' ? $asAtDate : null)
                    : ['available' => false, 'errors' => ['Select a company and accounting period before reviewing dividends.']],
                'history' => $companyId > 0 && $accountingPeriodId > 0
                    ? $dividendService->listDividends($companyId, $accountingPeriodId)
                    : [],
                'warnings' => $dividendService->getDividendWarnings($companyId, $accountingPeriodId),
                'nominals' => (array)($nominals['accounts'] ?? []),
                'nominal_errors' => (array)($nominals['errors'] ?? []),
            ],
        ];
    }
}
