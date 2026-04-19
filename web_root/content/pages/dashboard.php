<?php
declare(strict_types=1);

final class _dashboard extends BasePageFramework
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function title(): string
    {
        return 'Dashboard';
    }

    public function subtitle(): string
    {
        return 'Track the new page architecture with convention-led cards, shared rendering, and AJAX-only card updates.';
    }

    public function services(): array
    {
        return [CompanyAccountService::class];
    }

    public function cards(): array
    {
        return [
            'hero',
            'overview',
            'activity',
            'dashboard_year_end_readiness',
            'dashboard_recent_transactions',
            'dashboard_action_queue',
            'dashboard_notes',
        ];
    }

    protected function buildContext(
        RequestFramework $request,
        PageServiceFramework $services,
        ActionResultFramework $actionResult
    ): array
    {
        $companyAccountService = $services->get(CompanyAccountService::class);

        $companyId = $request->companyId();
        $taxYearId = $request->taxYearId();
        $settings = $companyId > 0 ? (new CompanySettingsStore($companyId))->all() : CompanySettingsStore::defaults();
        $dashboardRepository = new DashboardRepository();
        $dashboardStats = $dashboardRepository->fetchStats($companyId, $taxYearId);
        $activity = $dashboardRepository->fetchActionQueue($companyId, $taxYearId);

        $stats = [
            [
                'label' => 'Bank accounts',
                'value' => (string)(int)($dashboardStats['bank_accounts'] ?? 0),
                'foot' => 'Active company accounts available for statement upload and reconciliation.',
            ],
            [
                'label' => 'Uncategorised',
                'value' => (string)(int)($dashboardStats['unreconciled_items'] ?? 0),
                'foot' => 'Transactions still waiting for nominal assignment.',
            ],
            [
                'label' => 'Manual journals',
                'value' => (string)(int)($dashboardStats['draft_journals'] ?? 0),
                'foot' => 'Manual-source journals currently sitting in the selected period.',
            ],
            [
                'label' => 'Resolution model',
                'value' => '_page / _cardCard',
                'foot' => 'Pages and cards continue resolving through naming convention helpers.',
            ],
        ];

        $pageCards = $this->cards();

        return [
            'page_id' => 'dashboard',
            'stats' => $stats,
            'activity' => $activity,
            'settings' => $settings,
            'action_queue' => $activity,
            'recent_transactions' => $dashboardRepository->fetchRecentTransactions(
                $companyId,
                $taxYearId,
                (int)($settings['default_bank_nominal_id'] ?? 0)
            ),
            'year_end_dashboard_summary' => ($companyId > 0)
                ? (new YearEndChecklistService())->fetchDashboardSummary($companyId, $taxYearId > 0 ? $taxYearId : null)
                : [],
            'service_class' => get_class($companyAccountService),
            'page_cards' => $pageCards,
            'cards_dom_ids' => array_map(
                static fn(string $cardKey): string => HelperFramework::cardDomId('dashboard', $cardKey),
                $pageCards
            ),
        ];
    }
}

