<?php
declare(strict_types=1);

final class AppService
{
    private array $instances = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $uploadBasePath,
    ) {
    }

    public function get(string $key): object {
        return $this->instances[$key] ??= match ($key) {
            'transaction_categorisation' => new TransactionCategorisationService($this->pdo),
            'categorisation_rule' => new CategorisationRuleService($this->pdo),
            'transaction_journal' => new TransactionJournalService($this->pdo),
            'expense_claim' => new ExpenseClaimService(
                $this->pdo,
                $this->requireService('transaction_categorisation', TransactionCategorisationService::class),
                $this->requireService('transaction_journal', TransactionJournalService::class)
            ),
            'asset' => new AssetService(
                $this->pdo,
                $this->requireService('transaction_categorisation', TransactionCategorisationService::class),
                $this->requireService('transaction_journal', TransactionJournalService::class)
            ),
            'statement_upload' => new StatementUploadService(
                $this->pdo,
                $this->uploadBasePath,
                $this->requireService('transaction_categorisation', TransactionCategorisationService::class),
                new ReceiptDownloadService($this->pdo, $this->uploadBasePath)
            ),
            'company_account' => new CompanyAccountService($this->pdo),
            'company_data_reset' => new CompanyDataResetService($this->pdo),
            'company_orphaned_file_cleanup' => new CompanyOrphanedFileCleanupService(
                $this->pdo,
                $this->uploadBasePath,
                $this->uploadBasePath,
                $this->uploadBasePath
            ),
            'banking_reconciliation' => new BankingReconciliationService($this->pdo),
            'year_end_metrics' => new YearEndMetricsService(
                $this->pdo,
                $this->requireService('banking_reconciliation', BankingReconciliationService::class)
            ),
            'year_end_tax_readiness' => new YearEndTaxReadinessService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'year_end_companies_house_comparison' => new YearEndCompaniesHouseComparisonService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'year_end_lock' => new YearEndLockService($this->pdo),
            'director_loan' => new DirectorLoanService($this->pdo),
            'trial_balance' => new TrialBalanceService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class),
                $this->requireService('year_end_lock', YearEndLockService::class)
            ),
            'trial_balance_validation' => new TrialBalanceValidationService(
                $this->pdo,
                $this->requireService('trial_balance', TrialBalanceService::class),
                $this->requireService('year_end_metrics', YearEndMetricsService::class),
                $this->requireService('year_end_lock', YearEndLockService::class)
            ),
            'trial_balance_comparison' => new TrialBalanceComparisonService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'opening_balance' => new OpeningBalanceService(
                $this->pdo,
                null,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'year_end_adjustment' => new YearEndAdjustmentService(
                $this->pdo,
                null,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'corporation_tax_computation' => new CorporationTaxComputationService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class)
            ),
            'year_end_checklist' => new YearEndChecklistService(
                $this->pdo,
                $this->requireService('year_end_metrics', YearEndMetricsService::class),
                $this->requireService('year_end_tax_readiness', YearEndTaxReadinessService::class),
                $this->requireService('year_end_companies_house_comparison', YearEndCompaniesHouseComparisonService::class),
                $this->requireService('year_end_lock', YearEndLockService::class)
            ),
            default => throw new InvalidArgumentException('Unknown service key: ' . $key),
        };
    }

    public function getMany(array $keys): array
    {
        $services = [];

        foreach ($keys as $key) {
            $services[(string)$key] = $this->get((string)$key);
        }

        return $services;
    }

    private function requireService(string $key, string $expectedClass): object {
        $service = $this->get($key);

        if (!$service instanceof $expectedClass) {
            throw new RuntimeException('Service ' . $key . ' did not resolve to ' . $expectedClass . '.');
        }

        return $service;
    }
}
