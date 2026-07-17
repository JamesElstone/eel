<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class IxbrlAccountsDisclosureService
{
    public const ACCOUNTING_STANDARD_FRS_105 = 'FRS_105';

    private const TABLE = 'ixbrl_accounts_disclosures';
    private const DISCLOSURE_FIELDS = [
        'accounting_standard',
        'average_number_employees',
        'entity_dormant',
        'entity_trading_status',
        'micro_entity_eligibility_confirmed',
        'going_concern_basis_appropriate',
        'has_material_off_balance_sheet_arrangements',
        'has_director_advances_credits_or_guarantees',
        'has_financial_commitments_guarantees_or_contingencies',
        'accounts_approval_date',
        'approving_director_name',
        'prepared_under_small_companies_regime',
        'audit_exempt_section_477',
        'directors_acknowledge_responsibilities',
        'members_have_not_required_audit',
    ];
    private const FIELD_LABELS = [
        'accounting_standard' => 'accounting standard',
        'average_number_employees' => 'average number of employees',
        'entity_dormant' => 'dormant-company status',
        'entity_trading_status' => 'entity trading status',
        'micro_entity_eligibility_confirmed' => 'micro-entity eligibility',
        'going_concern_basis_appropriate' => 'going-concern basis',
        'has_material_off_balance_sheet_arrangements' => 'material off-balance-sheet arrangements',
        'has_director_advances_credits_or_guarantees' => 'director advances, credits or guarantees',
        'has_financial_commitments_guarantees_or_contingencies' => 'financial commitments, guarantees or contingencies',
        'accounts_approval_date' => 'accounts approval date',
        'approving_director_name' => 'approving director',
        'prepared_under_small_companies_regime' => 'small companies regime statement',
        'audit_exempt_section_477' => 'section 477 audit exemption statement',
        'directors_acknowledge_responsibilities' => 'directors responsibilities acknowledgement',
        'members_have_not_required_audit' => 'members have not required an audit statement',
    ];

    public function get(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0
            || $accountingPeriodId <= 0
            || !\InterfaceDB::tableExists(self::TABLE)) {
            return null;
        }

        $row = \InterfaceDB::fetchOne(
            'SELECT *
             FROM ' . self::TABLE . '
             WHERE company_id = :company_id
               AND accounting_period_id = :accounting_period_id
             LIMIT 1',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );

        return is_array($row) ? $this->normaliseStoredRow($row) : null;
    }

    public function fetch(int $companyId, int $accountingPeriodId): array
    {
        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        $defaults = $this->emptyDisclosures();
        if ($period === null) {
            return [
                'success' => false,
                'available' => false,
                'schema_ready' => \InterfaceDB::tableExists(self::TABLE),
                'complete' => false,
                'missing_fields' => array_keys(self::FIELD_LABELS),
                'missing_labels' => array_values(self::FIELD_LABELS),
                'disclosures' => $defaults,
                'year_end_locked' => false,
                'trading_status_evidence' => ['has_previous_trading_evidence' => false, 'sources' => []],
                'trading_status_answers' => ['is_still_trading' => null, 'has_ever_traded' => null],
                'director_suggestions' => [],
                'errors' => ['The selected accounting period could not be found for this company.'],
            ];
        }
        if (!\InterfaceDB::tableExists(self::TABLE)) {
            return [
                'success' => false,
                'available' => false,
                'schema_ready' => false,
                'complete' => false,
                'missing_fields' => array_keys(self::FIELD_LABELS),
                'missing_labels' => array_values(self::FIELD_LABELS),
                'disclosures' => $defaults,
                'stored' => false,
                'year_end_locked' => (new YearEndLockService())->isLocked($companyId, $accountingPeriodId),
                'trading_status_evidence' => ['has_previous_trading_evidence' => false, 'sources' => []],
                'trading_status_answers' => ['is_still_trading' => null, 'has_ever_traded' => null],
                'suggested_disclosures' => [],
                'suggestion_sources' => [],
                'director_suggestions' => $this->directorSuggestions($companyId),
                'accounting_period' => $period,
                'errors' => ['The iXBRL accounts disclosures migration has not been applied.'],
            ];
        }

        $row = $this->get($companyId, $accountingPeriodId);
        $disclosures = array_replace($defaults, $row ?? []);
        $dormancy = $this->calculateDormancy($companyId, $period);
        if (!empty($dormancy['calculated'])) {
            $disclosures['entity_dormant'] = (int)$dormancy['entity_dormant'];
        }
        $smallCompanies = $this->calculateSmallCompaniesRegime($period, $disclosures);
        if (!empty($smallCompanies['available'])) {
            $disclosures['prepared_under_small_companies_regime'] = !empty($smallCompanies['qualifies']) ? 1 : 0;
        } else {
            $disclosures['prepared_under_small_companies_regime'] = null;
        }
        $missing = $this->missingFields($disclosures);
        $unsupported = $this->unsupportedProfileFields($disclosures);
        $profileErrors = $this->unsupportedProfileErrors($disclosures);
        $suggestions = $this->companiesHouseSuggestions($companyId, $period);
        $displayTradingStatus = trim((string)(
            $row['entity_trading_status']
            ?? $suggestions['values']['entity_trading_status']
            ?? ''
        ));
        $tradingEvidence = $this->calculateTradingEvidence($companyId, $period, $dormancy);

        return [
            'success' => true,
            'available' => true,
            'schema_ready' => true,
            'complete' => $missing === [] && $unsupported === [],
            'profile_supported' => $unsupported === [],
            'profile_errors' => $profileErrors,
            'missing_fields' => $missing,
            'missing_labels' => array_values(array_map(
                static fn(string $field): string => self::FIELD_LABELS[$field] ?? $field,
                $missing
            )),
            'disclosures' => $disclosures,
            'stored' => $row !== null,
            'year_end_locked' => (new YearEndLockService())->isLocked($companyId, $accountingPeriodId),
            'trading_status_evidence' => $tradingEvidence,
            'trading_status_answers' => $this->tradingStatusAnswers($displayTradingStatus),
            'suggested_disclosures' => (array)($suggestions['values'] ?? []),
            'suggestion_sources' => (array)($suggestions['sources'] ?? []),
            'director_suggestions' => $this->directorSuggestions($companyId),
            'accounting_period' => $period,
            'dormancy' => $dormancy,
            'small_companies_regime' => $smallCompanies,
            'errors' => [],
        ];
    }

    public function save(
        int $companyId,
        int $accountingPeriodId,
        array $input,
        string $changedBy = 'web_app',
        bool $allowPartial = false
    ): array {
        if (!\InterfaceDB::tableExists(self::TABLE)) {
            return $this->error('The iXBRL accounts disclosures migration has not been applied.');
        }

        $period = $this->accountingPeriod($companyId, $accountingPeriodId);
        if ($period === null) {
            return $this->error('The selected accounting period could not be found for this company.');
        }

        try {
            (new YearEndLockService())->assertLocked(
                $companyId,
                $accountingPeriodId,
                'confirm the accounts disclosures'
            );
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        $dormancy = $this->calculateDormancy($companyId, $period);
        if (empty($dormancy['calculated'])) {
            return $this->error((string)($dormancy['error'] ?? 'Configure a default Sales nominal before saving iXBRL disclosures.'));
        }
        // Dormancy is derived from the ledger; never trust a browser-posted value.
        $input['entity_dormant'] = (int)$dormancy['entity_dormant'];
        $smallCompanies = $this->calculateSmallCompaniesRegime($period, $input);
        if (empty($smallCompanies['available'])) {
            return $this->error((string)($smallCompanies['error'] ?? 'FRS 105 size thresholds and accounting figures are required before saving disclosures.'));
        }
        // The small-companies-regime value is derived server-side; ignore any
        // browser-posted value, including a tampered affirmative answer.
        $input['prepared_under_small_companies_regime'] = !empty($smallCompanies['qualifies']) ? 1 : 0;
        $tradingEvidence = $this->calculateTradingEvidence($companyId, $period, $dormancy);
        [$tradingStatus, $tradingErrors] = $this->deriveTradingStatus($input, $tradingEvidence);
        $input['entity_trading_status'] = $tradingStatus;
        [$values, $errors] = $this->validate($input, $period, $allowPartial);
        $errors = array_values(array_unique(array_merge($tradingErrors, $errors)));
        if ($errors !== []) {
            return ['success' => false, 'changed' => false, 'errors' => $errors];
        }

        $changedBy = $this->actor($changedBy);
        try {
            $result = \InterfaceDB::transaction(function () use (
                $companyId,
                $accountingPeriodId,
                $values,
                $changedBy
            ): array {
                $suffix = \InterfaceDB::driverName() === 'sqlite' ? '' : ' FOR UPDATE';
                $existing = \InterfaceDB::fetchOne(
                    'SELECT *
                     FROM ' . self::TABLE . '
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                     LIMIT 1' . $suffix,
                    ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
                );
                $oldValues = is_array($existing) ? $this->disclosureValues($existing) : null;
                if ($oldValues !== null && $oldValues === $values) {
                    return [
                        'changed' => false,
                        'revision' => max(1, (int)($existing['revision'] ?? 1)),
                    ];
                }

                $revision = is_array($existing)
                    ? max(1, (int)($existing['revision'] ?? 1)) + 1
                    : 1;
                $params = $values + [
                    'company_id' => $companyId,
                    'accounting_period_id' => $accountingPeriodId,
                    'revision' => $revision,
                    'created_by' => $changedBy,
                    'updated_by' => $changedBy,
                ];

                if (is_array($existing)) {
                    $params['id'] = (int)$existing['id'];
                    \InterfaceDB::prepareExecute(
                        'UPDATE ' . self::TABLE . '
                         SET accounting_standard = :accounting_standard,
                             average_number_employees = :average_number_employees,
                             entity_dormant = :entity_dormant,
                             entity_trading_status = :entity_trading_status,
                             micro_entity_eligibility_confirmed = :micro_entity_eligibility_confirmed,
                             going_concern_basis_appropriate = :going_concern_basis_appropriate,
                             has_material_off_balance_sheet_arrangements = :has_material_off_balance_sheet_arrangements,
                             has_director_advances_credits_or_guarantees = :has_director_advances_credits_or_guarantees,
                             has_financial_commitments_guarantees_or_contingencies = :has_financial_commitments_guarantees_or_contingencies,
                             accounts_approval_date = :accounts_approval_date,
                             approving_director_name = :approving_director_name,
                             prepared_under_small_companies_regime = :prepared_under_small_companies_regime,
                             audit_exempt_section_477 = :audit_exempt_section_477,
                             directors_acknowledge_responsibilities = :directors_acknowledge_responsibilities,
                             members_have_not_required_audit = :members_have_not_required_audit,
                             revision = :revision,
                             updated_by = :updated_by,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id',
                        $params
                    );
                } else {
                    \InterfaceDB::prepareExecute(
                        'INSERT INTO ' . self::TABLE . ' (
                            company_id, accounting_period_id, accounting_standard,
                            average_number_employees, entity_dormant, entity_trading_status,
                            micro_entity_eligibility_confirmed,
                            going_concern_basis_appropriate,
                            has_material_off_balance_sheet_arrangements,
                            has_director_advances_credits_or_guarantees,
                            has_financial_commitments_guarantees_or_contingencies,
                            accounts_approval_date,
                            approving_director_name, prepared_under_small_companies_regime,
                            audit_exempt_section_477, directors_acknowledge_responsibilities,
                            members_have_not_required_audit, revision, created_by, updated_by,
                            created_at, updated_at
                         ) VALUES (
                            :company_id, :accounting_period_id, :accounting_standard,
                            :average_number_employees, :entity_dormant, :entity_trading_status,
                            :micro_entity_eligibility_confirmed,
                            :going_concern_basis_appropriate,
                            :has_material_off_balance_sheet_arrangements,
                            :has_director_advances_credits_or_guarantees,
                            :has_financial_commitments_guarantees_or_contingencies,
                            :accounts_approval_date,
                            :approving_director_name, :prepared_under_small_companies_regime,
                            :audit_exempt_section_477, :directors_acknowledge_responsibilities,
                            :members_have_not_required_audit, :revision, :created_by, :updated_by,
                            CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                         )',
                        $params
                    );
                }

                $this->audit($companyId, $accountingPeriodId, $oldValues, $values, $changedBy);

                return ['changed' => true, 'revision' => $revision];
            });
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        return $this->fetch($companyId, $accountingPeriodId) + [
            'changed' => !empty($result['changed']),
            'revision' => (int)($result['revision'] ?? 0),
        ];
    }

    /** Save only the core details panel, preserving independently saved statements. */
    public function saveCoreDetails(int $companyId, int $accountingPeriodId, array $input, string $changedBy = 'web_app'): array
    {
        $current = $this->get($companyId, $accountingPeriodId) ?? $this->emptyDisclosures();
        $merged = array_replace($current, $input);
        $merged['is_still_trading'] = $input['is_still_trading'] ?? null;
        $merged['has_ever_traded'] = $input['has_ever_traded'] ?? null;

        return $this->save($companyId, $accountingPeriodId, $merged, $changedBy, true);
    }

    /** Save one independently editable yes/no disclosure without requiring the core panel. */
    public function saveField(int $companyId, int $accountingPeriodId, string $field, mixed $value, string $changedBy = 'web_app'): array
    {
        $allowed = [
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
        ];
        if (!in_array($field, $allowed, true)) {
            return $this->error('That disclosure cannot be edited independently.');
        }
        try {
            (new YearEndLockService())->assertLocked($companyId, $accountingPeriodId, 'change the accounts disclosure');
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }
        $normalised = $this->booleanValue($value);
        if ($normalised === null) {
            return $this->error('Choose Yes or No before saving this disclosure.');
        }
        if (!\InterfaceDB::tableExists(self::TABLE)) {
            return $this->error('The iXBRL accounts disclosures migration has not been applied.');
        }

        $existing = $this->get($companyId, $accountingPeriodId);
        $oldValues = $existing !== null ? $this->disclosureValues($existing) : null;
        $newValues = $oldValues ?? $this->emptyDisclosures();
        $newValues[$field] = $normalised;
        $changedBy = $this->actor($changedBy);
        try {
            \InterfaceDB::transaction(function () use ($companyId, $accountingPeriodId, $field, $normalised, $changedBy, $existing, $oldValues, $newValues): void {
                if ($existing !== null) {
                    \InterfaceDB::prepareExecute(
                        'UPDATE ' . self::TABLE . ' SET ' . $field . ' = :value, revision = revision + 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                        ['value' => $normalised, 'updated_by' => $changedBy, 'id' => (int)$existing['id']]
                    );
                } else {
                    \InterfaceDB::prepareExecute(
                        'INSERT INTO ' . self::TABLE . ' (company_id, accounting_period_id, accounting_standard, ' . $field . ', revision, created_by, updated_by, created_at, updated_at) VALUES (:company_id, :accounting_period_id, :standard, :value, 1, :created_by, :updated_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId, 'standard' => self::ACCOUNTING_STANDARD_FRS_105, 'value' => $normalised, 'created_by' => $changedBy, 'updated_by' => $changedBy]
                    );
                }
                $this->audit($companyId, $accountingPeriodId, $oldValues, $newValues, $changedBy);
            });
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        return ['success' => true, 'changed' => true];
    }

    /**
     * Companies House dormant status is not the source of truth here. The
     * supported binary rule is: any gross posted credit to the configured
     * Sales nominal means the company was not dormant for this period.
     */
    private function calculateDormancy(int $companyId, array $period): array
    {
        $periodStart = trim((string)($period['period_start'] ?? ''));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($companyId <= 0 || $periodStart === '' || $periodEnd === '') {
            return ['calculated' => false, 'error' => 'The accounting period dates are required to calculate dormant status.'];
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $salesNominalId = (int)($settings['default_sales_nominal_id'] ?? 0);
        $salesNominal = $salesNominalId > 0
            ? \InterfaceDB::fetchOne(
                'SELECT id, code, name FROM nominal_accounts WHERE id = :id LIMIT 1',
                ['id' => $salesNominalId]
            )
            : null;
        if (!is_array($salesNominal)) {
            // Legacy companies may not yet have the EAV setting. Prefer the
            // standard Sales nominal so the calculation remains usable while
            // the migration/defaults card repairs the setting.
            $salesNominal = \InterfaceDB::fetchOne(
                'SELECT na.id, na.code, na.name
                 FROM nominal_accounts na
                 LEFT JOIN nominal_account_subtypes nas ON nas.id = na.account_subtype_id
                 WHERE na.is_active = 1
                   AND na.account_type = :account_type
                   AND (na.code = :sales_code OR nas.code = :turnover_code OR LOWER(na.name) LIKE :sales_name)
                 ORDER BY CASE WHEN na.code = :sales_code_order THEN 0 ELSE 1 END, na.sort_order, na.id
                 LIMIT 1',
                [
                    'account_type' => 'income',
                    'sales_code' => '4000',
                    'turnover_code' => 'turnover',
                    'sales_name' => '%sales%',
                    'sales_code_order' => '4000',
                ]
            );
        }
        if (!is_array($salesNominal) || (int)($salesNominal['id'] ?? 0) <= 0) {
            return [
                'calculated' => false,
                'error' => 'Configure a default Sales nominal before saving iXBRL disclosures.',
            ];
        }

        $grossSales = (float)\InterfaceDB::fetchColumn(
            'SELECT COALESCE(SUM(COALESCE(jl.credit, 0)), 0)
             FROM journal_lines jl
             INNER JOIN journals j ON j.id = jl.journal_id
             WHERE j.company_id = :company_id
               AND j.accounting_period_id = :accounting_period_id
               AND j.is_posted = 1
               AND j.journal_date BETWEEN :period_start AND :period_end
               AND jl.nominal_account_id = :sales_nominal_id
               AND jl.credit > 0',
            [
                'company_id' => $companyId,
                'accounting_period_id' => (int)($period['id'] ?? 0),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'sales_nominal_id' => (int)$salesNominal['id'],
            ]
        );
        $grossSales = round($grossSales, 2);

        return [
            'calculated' => true,
            'entity_dormant' => $grossSales <= 0 ? 1 : 0,
            'has_sales' => $grossSales > 0,
            'gross_sales' => $grossSales,
            'sales_nominal_id' => (int)$salesNominal['id'],
            'sales_nominal_code' => (string)($salesNominal['code'] ?? ''),
            'sales_nominal_name' => (string)($salesNominal['name'] ?? ''),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    /** @return array<string, mixed> */
    private function calculateTradingEvidence(int $companyId, array $period, array $dormancy): array
    {
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        $salesNominalId = (int)($dormancy['sales_nominal_id'] ?? 0);
        $sources = [];
        $grossSalesToPeriodEnd = 0.0;

        if ($companyId > 0 && $periodEnd !== '' && $salesNominalId > 0) {
            $grossSalesToPeriodEnd = round((float)\InterfaceDB::fetchColumn(
                'SELECT COALESCE(SUM(COALESCE(jl.credit, 0)), 0)
                 FROM journal_lines jl
                 INNER JOIN journals j ON j.id = jl.journal_id
                 WHERE j.company_id = :company_id
                   AND j.is_posted = 1
                   AND j.journal_date <= :period_end
                   AND jl.nominal_account_id = :sales_nominal_id
                   AND jl.credit > 0',
                [
                    'company_id' => $companyId,
                    'period_end' => $periodEnd,
                    'sales_nominal_id' => $salesNominalId,
                ]
            ), 2);
            if ($grossSalesToPeriodEnd > 0) {
                $sources[] = [
                    'type' => 'ledger_sales',
                    'label' => 'posted Sales credits',
                    'gross_sales' => $grossSalesToPeriodEnd,
                    'sales_nominal_id' => $salesNominalId,
                    'sales_nominal_code' => (string)($dormancy['sales_nominal_code'] ?? ''),
                ];
            }
        }

        if ($companyId > 0 && $periodEnd !== '' && \InterfaceDB::tableExists(self::TABLE)) {
            $saved = \InterfaceDB::fetchOne(
                'SELECT d.entity_trading_status, ap.period_end
                 FROM ' . self::TABLE . ' d
                 INNER JOIN accounting_periods ap ON ap.id = d.accounting_period_id
                 WHERE d.company_id = :company_id
                   AND ap.company_id = d.company_id
                   AND ap.period_end <= :period_end
                   AND d.entity_trading_status IN (:trading, :no_longer_trading)
                 ORDER BY ap.period_end DESC, d.id DESC
                 LIMIT 1',
                [
                    'company_id' => $companyId,
                    'period_end' => $periodEnd,
                    'trading' => 'trading',
                    'no_longer_trading' => 'no_longer_trading',
                ]
            );
            if (is_array($saved)) {
                $sources[] = [
                    'type' => 'saved_disclosure',
                    'label' => 'saved accounts disclosure',
                    'status' => (string)($saved['entity_trading_status'] ?? ''),
                    'period_end' => (string)($saved['period_end'] ?? ''),
                ];
            }
        }

        $filedEvidence = $this->companiesHouseTradingEvidence($companyId, $periodEnd);
        if ($filedEvidence !== null) {
            $sources[] = $filedEvidence;
        }

        return [
            'has_previous_trading_evidence' => $sources !== [],
            'sources' => $sources,
            'gross_sales_to_period_end' => $grossSalesToPeriodEnd,
            'period_end' => $periodEnd,
        ];
    }

    private function companiesHouseTradingEvidence(int $companyId, string $periodEnd): ?array
    {
        foreach ([
            'companies',
            'companies_house_documents',
            'companies_house_document_facts',
            'companies_house_taxonomy_concepts',
        ] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                return null;
            }
        }
        $companyNumber = trim((string)\InterfaceDB::fetchColumn(
            'SELECT company_number FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        ));
        if ($companyNumber === '' || $periodEnd === '') {
            return null;
        }

        $rows = \InterfaceDB::fetchAll(
            'SELECT d.filing_date, d.document_id, f.raw_value, f.normalised_text,
                    MAX(period_fact.normalised_date) AS period_end
             FROM companies_house_document_facts f
             INNER JOIN companies_house_documents d ON d.id = f.document_fk
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             INNER JOIN companies_house_document_facts period_fact ON period_fact.document_fk = d.id
             INNER JOIN companies_house_taxonomy_concepts period_concept ON period_concept.id = period_fact.concept_fk
             WHERE d.company_number = :company_number
               AND c.short_name = :trading_concept
               AND period_concept.short_name IN (:end_date_concept, :balance_sheet_concept)
               AND period_fact.normalised_date <= :period_end
             GROUP BY d.id, d.filing_date, d.document_id, f.id, f.raw_value, f.normalised_text
             ORDER BY period_end DESC, d.filing_date DESC, d.id DESC, f.id ASC',
            [
                'company_number' => strtoupper($companyNumber),
                'trading_concept' => 'EntityTradingStatus',
                'end_date_concept' => 'EndDateForPeriodCoveredByReport',
                'balance_sheet_concept' => 'BalanceSheetDate',
                'period_end' => $periodEnd,
            ]
        );
        foreach ($rows as $row) {
            $status = $this->tradingStatusValue((string)(
                $row['normalised_text'] ?? $row['raw_value'] ?? ''
            ));
            if (!in_array($status, ['trading', 'no_longer_trading'], true)) {
                continue;
            }
            return [
                'type' => 'companies_house_filing',
                'label' => 'Companies House iXBRL filing',
                'status' => $status,
                'period_end' => (string)($row['period_end'] ?? ''),
                'filing_date' => (string)($row['filing_date'] ?? ''),
                'document_id' => (string)($row['document_id'] ?? ''),
            ];
        }

        return null;
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function deriveTradingStatus(array $input, array $evidence): array
    {
        $stillTrading = $this->booleanValue($input['is_still_trading'] ?? null);
        if ($stillTrading === null) {
            return ['', ['Confirm whether the company was still trading at the accounting period end.']];
        }
        if ($stillTrading === 1) {
            return ['trading', []];
        }
        if (!empty($evidence['has_previous_trading_evidence'])) {
            return ['no_longer_trading', []];
        }

        $everTraded = $this->booleanValue($input['has_ever_traded'] ?? null);
        if ($everTraded === null) {
            return ['', ['Confirm whether the company has ever traded.']];
        }

        return [$everTraded === 1 ? 'no_longer_trading' : 'never_traded', []];
    }

    /** @return array{is_still_trading: ?int, has_ever_traded: ?int} */
    private function tradingStatusAnswers(string $status): array
    {
        return match ($status) {
            'trading' => ['is_still_trading' => 1, 'has_ever_traded' => 1],
            'no_longer_trading' => ['is_still_trading' => 0, 'has_ever_traded' => 1],
            'never_traded' => ['is_still_trading' => 0, 'has_ever_traded' => 0],
            default => ['is_still_trading' => null, 'has_ever_traded' => null],
        };
    }

    /** @return array<string, mixed> */
    private function calculateSmallCompaniesRegime(array $period, array $disclosures): array
    {
        $employees = $disclosures['average_number_employees'] ?? null;
        if ($employees === null || $employees === '' || !is_numeric($employees)) {
            return [
                'available' => false,
                'error' => 'Enter the average number of employees before calculating FRS 105 size eligibility.',
            ];
        }
        $periodStart = trim((string)($period['period_start'] ?? ''));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($periodStart === '' || $periodEnd === '') {
            return ['available' => false, 'error' => 'Accounting-period dates are required to calculate FRS 105 size eligibility.'];
        }
        try {
            $mapping = (new IxbrlAccountsMappingService())->getAccountsMapping(
                (int)($period['company_id'] ?? 0),
                (int)($period['id'] ?? 0)
            );
            $buckets = (array)($mapping['buckets'] ?? []);
            foreach (['turnover', 'fixed_assets', 'current_assets', 'prepayments_accrued_income'] as $key) {
                if (!array_key_exists($key, $buckets) || !is_numeric($buckets[$key])) {
                    return ['available' => false, 'error' => 'Complete accounting figures are required to calculate FRS 105 size eligibility.'];
                }
            }
            $eligibility = (new IxbrlMicroEntityEligibilityService())->evaluate(
                $periodStart,
                $periodEnd,
                (float)$buckets['turnover'],
                (float)$buckets['fixed_assets'] + (float)$buckets['current_assets'] + (float)$buckets['prepayments_accrued_income'],
                (int)$employees
            );
            if (empty($eligibility['thresholds_available'])) {
                return [
                    'available' => false,
                    'error' => (new IxbrlMicroEntityEligibilityService())->detail($eligibility),
                    'eligibility' => $eligibility,
                ];
            }
            return ['available' => true] + $eligibility;
        } catch (\Throwable $exception) {
            return ['available' => false, 'error' => $exception->getMessage()];
        }
    }

    private function validate(array $input, array $period, bool $allowPartial = false): array
    {
        $errors = [];
        $standard = trim((string)($input['accounting_standard'] ?? self::ACCOUNTING_STANDARD_FRS_105));
        if ($standard !== self::ACCOUNTING_STANDARD_FRS_105) {
            $errors[] = 'This builder currently supports FRS 105 accounts only.';
        }

        $employeesRaw = $input['average_number_employees'] ?? null;
        $employees = null;
        if (is_int($employeesRaw)) {
            $employees = $employeesRaw;
        } elseif (is_string($employeesRaw) && preg_match('/^\d+$/', trim($employeesRaw)) === 1) {
            $employees = (int)trim($employeesRaw);
        }
        if (!$allowPartial && ($employees === null || $employees < 0 || $employees > 1000000)) {
            $errors[] = 'Enter the average number of employees as a whole number from 0 to 1,000,000.';
        }

        $approvalDate = trim((string)($input['accounts_approval_date'] ?? ''));
        $date = $approvalDate !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $approvalDate) : false;
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$allowPartial && ($date === false
            || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $approvalDate)) {
            $errors[] = 'Enter a valid accounts approval date.';
        } else {
            $periodEnd = (string)($period['period_end'] ?? '');
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            if ($periodEnd !== '' && $approvalDate < $periodEnd) {
                $errors[] = 'The accounts approval date cannot be before the accounting period end.';
            }
            if ($approvalDate > $today) {
                $errors[] = 'The accounts approval date cannot be in the future.';
            }
        }

        $directorName = trim((string)($input['approving_director_name'] ?? ''));
        if (!$allowPartial && $directorName === '') {
            $errors[] = 'Enter the name of the director who approved the accounts.';
        } elseif (mb_strlen($directorName) > 255) {
            $errors[] = 'The approving director name must be 255 characters or fewer.';
        }

        $booleanFields = [
            'entity_dormant',
            'prepared_under_small_companies_regime',
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
        ];
        $booleans = [];
        foreach ($booleanFields as $field) {
            $value = $this->booleanValue($input[$field] ?? null);
            if ($value === null && !$allowPartial) {
                $errors[] = 'Confirm ' . (self::FIELD_LABELS[$field] ?? $field) . ' with Yes or No.';
            }
            $booleans[$field] = $value;
        }

        $tradingStatus = trim((string)($input['entity_trading_status'] ?? ''));
        if ($tradingStatus !== ''
            && !in_array($tradingStatus, ['trading', 'never_traded', 'no_longer_trading'], true)) {
            $errors[] = 'Choose whether the entity is trading, has never traded, or is no longer trading.';
        }

        return [[
            'accounting_standard' => $standard,
            'average_number_employees' => $employees,
            'entity_dormant' => $booleans['entity_dormant'],
            'entity_trading_status' => $tradingStatus,
            'micro_entity_eligibility_confirmed' => $booleans['micro_entity_eligibility_confirmed'],
            'going_concern_basis_appropriate' => $booleans['going_concern_basis_appropriate'],
            'has_material_off_balance_sheet_arrangements' => $booleans['has_material_off_balance_sheet_arrangements'],
            'has_director_advances_credits_or_guarantees' => $booleans['has_director_advances_credits_or_guarantees'],
            'has_financial_commitments_guarantees_or_contingencies' => $booleans['has_financial_commitments_guarantees_or_contingencies'],
            'accounts_approval_date' => $approvalDate,
            'approving_director_name' => $directorName,
            'prepared_under_small_companies_regime' => $booleans['prepared_under_small_companies_regime'],
            'audit_exempt_section_477' => $booleans['audit_exempt_section_477'],
            'directors_acknowledge_responsibilities' => $booleans['directors_acknowledge_responsibilities'],
            'members_have_not_required_audit' => $booleans['members_have_not_required_audit'],
        ], array_values(array_unique($errors))];
    }

    private function missingFields(array $disclosures): array
    {
        $missing = [];
        foreach (self::DISCLOSURE_FIELDS as $field) {
            $value = $disclosures[$field] ?? null;
            if ($field === 'accounting_standard') {
                if ((string)$value !== self::ACCOUNTING_STANDARD_FRS_105) {
                    $missing[] = $field;
                }
            } elseif (in_array($field, [
                'entity_dormant',
                'prepared_under_small_companies_regime',
                'audit_exempt_section_477',
                'directors_acknowledge_responsibilities',
                'members_have_not_required_audit',
                'micro_entity_eligibility_confirmed',
                'going_concern_basis_appropriate',
                'has_material_off_balance_sheet_arrangements',
                'has_director_advances_credits_or_guarantees',
                'has_financial_commitments_guarantees_or_contingencies',
            ], true)) {
                if ($value === null || !in_array((int)$value, [0, 1], true)) {
                    $missing[] = $field;
                }
            } elseif ($field === 'average_number_employees') {
                if ($value === null || (int)$value < 0) {
                    $missing[] = $field;
                }
            } elseif (trim((string)$value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function unsupportedProfileFields(array $disclosures): array
    {
        $unsupported = [];
        foreach ([
            'prepared_under_small_companies_regime',
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
        ] as $field) {
            $value = $disclosures[$field] ?? null;
            if ($value !== null && (int)$value !== 1) {
                $unsupported[] = $field;
            }
        }
        foreach ([
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
        ] as $field) {
            $value = $disclosures[$field] ?? null;
            if ($value !== null && (int)$value === 1) {
                $unsupported[] = $field;
            }
        }

        return $unsupported;
    }

    private function unsupportedProfileErrors(array $disclosures): array
    {
        $requiredConfirmations = [];
        foreach ([
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
        ] as $field) {
            $value = $disclosures[$field] ?? null;
            if ($value !== null && (int)$value !== 1) {
                $requiredConfirmations[] = self::FIELD_LABELS[$field] ?? $field;
            }
        }

        $positiveNotes = [];
        foreach ([
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
        ] as $field) {
            $value = $disclosures[$field] ?? null;
            if ($value !== null && (int)$value === 1) {
                $positiveNotes[] = self::FIELD_LABELS[$field] ?? $field;
            }
        }

        $errors = [];
        if (($disclosures['prepared_under_small_companies_regime'] ?? null) !== null
            && (int)$disclosures['prepared_under_small_companies_regime'] !== 1) {
            $errors[] = 'The calculated FRS 105 size result is No. All three turnover, balance-sheet total and employee tests must pass before this profile can build iXBRL facts.';
        }
        if ($requiredConfirmations !== []) {
            $errors[] = 'The current builder supports FRS 105 unaudited micro-entity accounts only. Confirm Yes for: '
                . implode(', ', $requiredConfirmations)
                . '.';
        }
        if (($disclosures['micro_entity_eligibility_confirmed'] ?? null) !== null
            && (int)$disclosures['micro_entity_eligibility_confirmed'] !== 1) {
            $errors[] = 'The current FRS 105 simple-note profile requires micro-entity eligibility to be confirmed. The No answer has been saved, but building iXBRL facts is blocked because this profile does not support an ineligible entity.';
        }
        if (($disclosures['going_concern_basis_appropriate'] ?? null) !== null
            && (int)$disclosures['going_concern_basis_appropriate'] !== 1) {
            $errors[] = 'The current FRS 105 simple-note profile supports accounts prepared on a going-concern basis only. The No answer has been saved, but building iXBRL facts is blocked because a non-going-concern basis and its disclosures are not yet supported.';
        }
        if ($positiveNotes !== []) {
            $errors[] = 'The current FRS 105 simple-note profile cannot build accounts for these positive-note disclosures: '
                . implode(', ', $positiveNotes)
                . '. The Yes answer has been saved, but the required note detail and taxonomy tagging are not yet supported.';
        }

        return $errors;
    }

    private function emptyDisclosures(): array
    {
        return [
            'accounting_standard' => self::ACCOUNTING_STANDARD_FRS_105,
            'average_number_employees' => null,
            'entity_dormant' => null,
            'entity_trading_status' => null,
            'micro_entity_eligibility_confirmed' => null,
            'going_concern_basis_appropriate' => null,
            'has_material_off_balance_sheet_arrangements' => null,
            'has_director_advances_credits_or_guarantees' => null,
            'has_financial_commitments_guarantees_or_contingencies' => null,
            'accounts_approval_date' => null,
            'approving_director_name' => null,
            'prepared_under_small_companies_regime' => null,
            'audit_exempt_section_477' => null,
            'directors_acknowledge_responsibilities' => null,
            'members_have_not_required_audit' => null,
        ];
    }

    private function normaliseStoredRow(array $row): array
    {
        foreach ([
            'average_number_employees',
            'entity_dormant',
            'micro_entity_eligibility_confirmed',
            'going_concern_basis_appropriate',
            'has_material_off_balance_sheet_arrangements',
            'has_director_advances_credits_or_guarantees',
            'has_financial_commitments_guarantees_or_contingencies',
            'prepared_under_small_companies_regime',
            'audit_exempt_section_477',
            'directors_acknowledge_responsibilities',
            'members_have_not_required_audit',
            'revision',
        ] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = (int)$row[$field];
            }
        }

        return $row;
    }

    private function disclosureValues(array $row): array
    {
        $row = $this->normaliseStoredRow($row);
        $values = [];
        foreach (self::DISCLOSURE_FIELDS as $field) {
            $values[$field] = $row[$field] ?? null;
        }

        return $values;
    }

    private function accountingPeriod(int $companyId, int $accountingPeriodId): ?array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0) {
            return null;
        }
        $row = \InterfaceDB::fetchOne(
            'SELECT id, company_id, label, period_start, period_end
             FROM accounting_periods
             WHERE id = :id
               AND company_id = :company_id
             LIMIT 1',
            ['id' => $accountingPeriodId, 'company_id' => $companyId]
        );

        return is_array($row) ? $row : null;
    }

    private function directorSuggestions(int $companyId): array
    {
        if ($companyId <= 0 || !\InterfaceDB::tableExists('company_directors')) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string)($row['full_name'] ?? '')),
            \InterfaceDB::fetchAll(
                'SELECT full_name
                 FROM company_directors
                 WHERE company_id = :company_id
                   AND source = :source
                   AND is_active = 1
                 ORDER BY full_name ASC, id ASC',
                ['company_id' => $companyId, 'source' => 'companies_house']
            )
        ), static fn(string $name): bool => $name !== ''));
    }

    private function companiesHouseSuggestions(int $companyId, array $period): array
    {
        foreach ([
            'companies',
            'companies_house_documents',
            'companies_house_document_facts',
            'companies_house_taxonomy_concepts',
        ] as $table) {
            if (!\InterfaceDB::tableExists($table)) {
                return ['values' => [], 'sources' => []];
            }
        }

        $companyNumber = trim((string)\InterfaceDB::fetchColumn(
            'SELECT company_number FROM companies WHERE id = :id LIMIT 1',
            ['id' => $companyId]
        ));
        $periodEnd = trim((string)($period['period_end'] ?? ''));
        if ($companyNumber === '' || $periodEnd === '') {
            return ['values' => [], 'sources' => []];
        }

        $concepts = [
            'AverageNumberEmployeesDuringPeriod',
            'EntityTradingStatus',
            'DateAuthorisationFinancialStatementsForIssue',
            'NameEntityOfficer',
            'StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies',
            'StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct',
            'StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit',
        ];
        $placeholders = implode(', ', array_fill(0, count($concepts), '?'));
        $statement = \InterfaceDB::prepare(
            'SELECT d.filing_date,
                    d.document_id,
                    c.short_name,
                    f.raw_value,
                    f.normalised_numeric,
                    f.normalised_text,
                    f.normalised_date
             FROM companies_house_document_facts f
             INNER JOIN companies_house_documents d ON d.id = f.document_fk
             INNER JOIN companies_house_taxonomy_concepts c ON c.id = f.concept_fk
             WHERE d.company_number = ?
               AND f.is_latest_year_fact = 1
               AND c.short_name IN (' . $placeholders . ')
               AND EXISTS (
                    SELECT 1
                    FROM companies_house_document_facts period_fact
                    INNER JOIN companies_house_taxonomy_concepts period_concept
                      ON period_concept.id = period_fact.concept_fk
                    WHERE period_fact.document_fk = d.id
                      AND period_concept.short_name IN (\'EndDateForPeriodCoveredByReport\', \'BalanceSheetDate\')
                      AND period_fact.normalised_date = ?
               )
             ORDER BY d.filing_date DESC, d.id DESC, f.id ASC'
        );
        $statement->execute(array_merge([strtoupper($companyNumber)], $concepts, [$periodEnd]));
        $rows = $statement->fetchAll() ?: [];

        $values = [];
        $sources = [];
        $map = [
            'AverageNumberEmployeesDuringPeriod' => 'average_number_employees',
            'EntityTradingStatus' => 'entity_trading_status',
            'DateAuthorisationFinancialStatementsForIssue' => 'accounts_approval_date',
            'NameEntityOfficer' => 'approving_director_name',
            'StatementThatCompanyEntitledToExemptionFromAuditUnderSection477CompaniesAct2006RelatingToSmallCompanies' => 'audit_exempt_section_477',
            'StatementThatDirectorsAcknowledgeTheirResponsibilitiesUnderCompaniesAct' => 'directors_acknowledge_responsibilities',
            'StatementThatMembersHaveNotRequiredCompanyToObtainAnAudit' => 'members_have_not_required_audit',
        ];
        foreach ($rows as $row) {
            $concept = (string)($row['short_name'] ?? '');
            $field = $map[$concept] ?? null;
            if ($field === null || array_key_exists($field, $values)) {
                continue;
            }

            $value = match ($field) {
                'average_number_employees' => trim((string)($row['normalised_numeric'] ?? '')) !== ''
                    ? max(0, (int)round((float)$row['normalised_numeric']))
                    : null,
                'entity_dormant' => $this->booleanValue(strtolower(trim((string)(
                    $row['normalised_text'] ?? $row['raw_value'] ?? ''
                )))),
                'entity_trading_status' => $this->tradingStatusValue((string)(
                    $row['normalised_text'] ?? $row['raw_value'] ?? ''
                )),
                'accounts_approval_date' => trim((string)($row['normalised_date'] ?? $row['raw_value'] ?? '')),
                'approving_director_name' => trim((string)($row['normalised_text'] ?? $row['raw_value'] ?? '')),
                default => trim((string)($row['raw_value'] ?? $row['normalised_text'] ?? '')) !== '' ? 1 : null,
            };
            if ($value === null || $value === '') {
                continue;
            }

            $values[$field] = $value;
            $sources[$field] = [
                'label' => 'Companies House filed iXBRL suggestion',
                'concept' => $concept,
                'filing_date' => (string)($row['filing_date'] ?? ''),
                'document_id' => (string)($row['document_id'] ?? ''),
            ];
        }

        return ['values' => $values, 'sources' => $sources];
    }

    private function booleanValue(mixed $value): ?int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'yes' || $value === 'true') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'no' || $value === 'false') {
            return 0;
        }

        return null;
    }

    private function tradingStatusValue(string $value): ?string
    {
        $normalised = strtolower(trim($value));
        if ($normalised === '') {
            return null;
        }
        if (str_contains($normalised, 'never')) {
            return 'never_traded';
        }
        if (str_contains($normalised, 'no longer')
            || str_contains($normalised, 'nolonger')
            || str_contains($normalised, 'ceased')) {
            return 'no_longer_trading';
        }
        if (str_contains($normalised, 'trading')) {
            return 'trading';
        }

        return in_array($normalised, ['trading', 'never_traded', 'no_longer_trading'], true)
            ? $normalised
            : null;
    }

    private function actor(string $actor): string
    {
        $actor = trim($actor);
        return mb_substr($actor !== '' ? $actor : 'web_app', 0, 100);
    }

    private function audit(
        int $companyId,
        int $accountingPeriodId,
        ?array $oldValues,
        array $newValues,
        string $changedBy
    ): void {
        if (!\InterfaceDB::tableExists('year_end_audit_log')) {
            return;
        }

        \InterfaceDB::prepareExecute(
            'INSERT INTO year_end_audit_log (
                company_id, accounting_period_id, action, action_by, action_at,
                old_value_json, new_value_json, notes
             ) VALUES (
                :company_id, :accounting_period_id, :action, :action_by, CURRENT_TIMESTAMP,
                :old_value_json, :new_value_json, :notes
             )',
            [
                'company_id' => $companyId,
                'accounting_period_id' => $accountingPeriodId,
                'action' => 'ixbrl_disclosures_changed',
                'action_by' => $changedBy,
                'old_value_json' => $oldValues !== null
                    ? json_encode($oldValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : null,
                'new_value_json' => json_encode($newValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'notes' => 'Period-specific iXBRL accounts disclosures changed; existing facts and exports must be rebuilt.',
            ]
        );
    }

    private function error(string $message): array
    {
        return ['success' => false, 'changed' => false, 'errors' => [$message]];
    }
}
