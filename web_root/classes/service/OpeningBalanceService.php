<?php
declare(strict_types=1);

final class OpeningBalanceService
{
    public function __construct(
        private readonly ?ManualJournalService $journalService = null,
        private readonly ?YearEndMetricsService $metricsService = null,
        private readonly ?CompaniesHouseStoredDataService $companiesHouseData = null,
    ) {
    }

    public function fetchContext(int $companyId, int $taxYearId): array {
        $metrics = $this->metricsService ?? new YearEndMetricsService();
        $taxYear = $metrics->fetchTaxYear($companyId, $taxYearId);
        $company = $metrics->fetchCompanySummary($companyId);

        if ($taxYear === null || $company === null) {
            return [
                'available' => false,
                'errors' => ['The selected company or accounting period could not be found.'],
            ];
        }

        return [
            'available' => true,
            'company' => $company,
            'tax_year' => $taxYear,
            'nominals' => $this->fetchNominals(),
            'existing_journal' => ($this->journalService ?? new ManualJournalService())
                ->fetchJournalByTag($companyId, $taxYearId, 'opening_balance'),
            'suggestions' => $this->buildSuggestionRows($companyId, $company, $taxYear),
        ];
    }

    public function saveOpeningBalance(int $companyId, int $taxYearId, array $payload, string $changedBy = 'web_app'): array {
        $context = $this->fetchContext($companyId, $taxYearId);
        if (empty($context['available'])) {
            return $context;
        }

        $existing = $context['existing_journal'] ?? null;
        $replaceExisting = $this->toBool($payload['replace_existing'] ?? false);

        if ($existing !== null && !$replaceExisting) {
            return [
                'success' => false,
                'errors' => ['An opening balance journal already exists for this period. Tick replace to overwrite it deliberately.'],
                'existing_journal' => $existing,
            ];
        }

        $lines = is_array($payload['lines'] ?? null) ? (array)$payload['lines'] : [];
        $entryMode = $this->toBool($payload['is_system_generated'] ?? false) ? 'system_generated' : 'manual';
        $description = trim((string)($payload['description'] ?? ''));
        if ($description === '') {
            $description = 'Opening balances for ' . (string)($context['tax_year']['label'] ?? 'selected period');
        }

        $result = ($this->journalService ?? new ManualJournalService())->saveTaggedJournal(
            $companyId,
            $taxYearId,
            'opening_balance',
            'primary',
            (string)$context['tax_year']['period_start'],
            $description,
            $lines,
            $entryMode,
            null,
            $existing !== null ? (int)$existing['id'] : null,
            trim((string)($payload['notes'] ?? '')),
            $changedBy
        );

        if (empty($result['success'])) {
            return $result;
        }

        return [
            'success' => true,
            'journal' => $result['journal'],
            'replaced_existing' => !empty($result['replaced_existing']),
        ];
    }

    private function fetchNominals(): array {
        $stmt = InterfaceDB::query(
            'SELECT na.id,
                    COALESCE(na.code, \'\') AS code,
                    COALESCE(na.name, \'\') AS name,
                    COALESCE(na.account_type, \'\') AS account_type,
                    COALESCE(na.tax_treatment, \'allowable\') AS tax_treatment,
                    COALESCE(nas.code, \'\') AS subtype_code,
                    COALESCE(nas.name, \'\') AS subtype_name
             FROM nominal_accounts na
             LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
             WHERE COALESCE(na.is_active, 0) = 1
             ORDER BY COALESCE(na.sort_order, 100), COALESCE(na.code, \'\'), COALESCE(na.name, \'\'), na.id'
        );

        return $stmt->fetchAll() ?: [];
    }

    private function buildSuggestionRows(int $companyId, array $company, array $taxYear): array {
        $previousTaxYear = $this->fetchPreviousTaxYear($companyId, (string)$taxYear['period_start']);
        if ($previousTaxYear === null) {
            return [];
        }

        $companyNumber = trim((string)($company['company_number'] ?? ''));
        if ($companyNumber === '') {
            return [];
        }

        $service = $this->companiesHouseData ?? new CompaniesHouseStoredDataService();
        $facts = $service->fetchFactsByCompanyPeriodEndAndConcept($companyNumber, (string)$previousTaxYear['period_end']);
        if ($facts === []) {
            $facts = $this->fetchFactsByContextPeriodEnd($companyNumber, (string)$previousTaxYear['period_end']);
        }
        if ($facts === []) {
            return [];
        }

        $factMap = [];
        foreach ($facts as $fact) {
            $concept = trim((string)($fact['concept_name'] ?? ''));
            $value = isset($fact['normalised_numeric']) ? (float)$fact['normalised_numeric'] : null;
            if ($concept === '' || $value === null) {
                continue;
            }
            $factMap[$concept] = $value;
        }

        $settings = ($this->metricsService ?? new YearEndMetricsService())->fetchCompanySettings($companyId);
        $nominals = $this->fetchNominals();
        $rows = [];

        $bankNominalId = (int)($settings['default_bank_nominal_id'] ?? 0);
        if (($factMap['CurrentAssets'] ?? 0.0) > 0 && $bankNominalId > 0) {
            $rows[] = [
                'nominal_account_id' => $bankNominalId,
                'debit' => number_format((float)$factMap['CurrentAssets'], 2, '.', ''),
                'credit' => '0.00',
                'line_description' => 'Suggested from filed Current Assets for ' . (string)$previousTaxYear['label'],
                'source_label' => 'CurrentAssets',
            ];
        }

        $creditorNominal = $this->findFirstNominal($nominals, static function (array $nominal): bool {
            $name = strtolower((string)($nominal['name'] ?? ''));
            return (string)($nominal['account_type'] ?? '') === 'liability'
                && (str_contains($name, 'creditor') || str_contains($name, 'accrual') || str_contains($name, 'payable'));
        });
        if (($factMap['CreditorsDueWithinOneYear'] ?? 0.0) > 0 && $creditorNominal !== null) {
            $rows[] = [
                'nominal_account_id' => (int)$creditorNominal['id'],
                'debit' => '0.00',
                'credit' => number_format((float)$factMap['CreditorsDueWithinOneYear'], 2, '.', ''),
                'line_description' => 'Suggested from filed Creditors due within one year for ' . (string)$previousTaxYear['label'],
                'source_label' => 'CreditorsDueWithinOneYear',
            ];
        }

        $equityNominal = $this->findFirstNominal($nominals, static function (array $nominal): bool {
            $name = strtolower((string)($nominal['name'] ?? ''));
            return (string)($nominal['account_type'] ?? '') === 'equity'
                || str_contains($name, 'capital')
                || str_contains($name, 'reserve')
                || str_contains($name, 'retained');
        });
        $equityValue = (float)($factMap['CapitalAndReserves'] ?? $factMap['NetAssetsLiabilities'] ?? 0.0);
        if ($equityValue > 0 && $equityNominal !== null) {
            $rows[] = [
                'nominal_account_id' => (int)$equityNominal['id'],
                'debit' => '0.00',
                'credit' => number_format($equityValue, 2, '.', ''),
                'line_description' => 'Suggested from filed Capital and Reserves for ' . (string)$previousTaxYear['label'],
                'source_label' => 'CapitalAndReserves',
            ];
        }

        $debitTotal = 0.0;
        $creditTotal = 0.0;
        foreach ($rows as $row) {
            $debitTotal += (float)$row['debit'];
            $creditTotal += (float)$row['credit'];
        }

        return abs(round($debitTotal - $creditTotal, 2)) < 0.005 ? $rows : [];
    }

    private function fetchPreviousTaxYear(int $companyId, string $periodStart): ?array {
        $row = InterfaceDB::fetchOne( 'SELECT id, label, period_start, period_end
             FROM tax_years
             WHERE company_id = :company_id
               AND period_end < :period_start
             ORDER BY period_end DESC, id DESC
             LIMIT 1', [
            'company_id' => $companyId,
            'period_start' => $periodStart,
        ]);

        return is_array($row) ? $row : null;
    }

    private function findFirstNominal(array $nominals, callable $predicate): ?array {
        foreach ($nominals as $nominal) {
            if ($predicate($nominal)) {
                return $nominal;
            }
        }

        return null;
    }

    private function toBool(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    }

    private function fetchFactsByContextPeriodEnd(string $companyNumber, string $periodEnd): array {
        return InterfaceDB::fetchAll( 'SELECT c.concept_name,
                    f.normalised_numeric
             FROM companies_house_document_facts f
             INNER JOIN companies_house_documents d ON d.id = f.document_fk
             INNER JOIN companies_house_document_contexts ctx ON ctx.id = f.context_fk
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             WHERE d.company_number = :company_number
               AND COALESCE(ctx.period_end, \'\') = :period_end
               AND f.is_latest_year_fact = 1
             ORDER BY d.filing_date DESC, f.id ASC', [
            'company_number' => strtoupper(trim($companyNumber)),
            'period_end' => $periodEnd,
        ]);
    }
}


