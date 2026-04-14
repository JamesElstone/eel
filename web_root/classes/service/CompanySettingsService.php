<?php
declare(strict_types=1);

final class CompanySettingsService
{
    public function loadFromDatabase(CompanySettingsStore $settingsStore, int $companyId, int $taxYearId): array
    {
        $settings = $this->defaultSettings();
        $settings['company_id'] = $companyId > 0 ? (string)$companyId : '';
        $settings['tax_year_id'] = $taxYearId > 0 ? (string)$taxYearId : '';

        if ($companyId > 0) {
            $settingsStore->persistMissingDefaults();
        }

        $companyRepository = new CompanyRepository();
        $taxYearRepository = new TaxYearRepository();

        if ($companyId > 0) {
            $company = $companyRepository->fetchSettingsCompany($companyId);

            if ($company !== null) {
                $settings['company_name'] = (string)($company['company_name'] ?? '');
                $settings['companies_house_number'] = (string)($company['company_number'] ?? '');
                $settings['incorporation_date'] = (string)($company['incorporation_date'] ?? '');
                $settings['company_status'] = (string)($company['company_status'] ?? '');
                $settings['registered_office_address_line_1'] = (string)($company['registered_office_address_line_1'] ?? '');
                $settings['registered_office_address_line_2'] = (string)($company['registered_office_address_line_2'] ?? '');
                $settings['registered_office_locality'] = (string)($company['registered_office_locality'] ?? '');
                $settings['registered_office_region'] = (string)($company['registered_office_region'] ?? '');
                $settings['registered_office_postal_code'] = (string)($company['registered_office_postal_code'] ?? '');
                $settings['registered_office_country'] = (string)($company['registered_office_country'] ?? '');
                $settings['registered_office_care_of'] = (string)($company['registered_office_care_of'] ?? '');
                $settings['registered_office_po_box'] = (string)($company['registered_office_po_box'] ?? '');
                $settings['registered_office_premises'] = (string)($company['registered_office_premises'] ?? '');
                $settings['can_file'] = isset($company['can_file']) ? (int)$company['can_file'] : null;
                $settings['has_charges'] = isset($company['has_charges']) ? (int)$company['has_charges'] : null;
                $settings['has_insolvency_history'] = isset($company['has_insolvency_history']) ? (int)$company['has_insolvency_history'] : null;
                $settings['has_been_liquidated'] = isset($company['has_been_liquidated']) ? (int)$company['has_been_liquidated'] : null;
                $settings['registered_office_is_in_dispute'] = isset($company['registered_office_is_in_dispute']) ? (int)$company['registered_office_is_in_dispute'] : null;
                $settings['undeliverable_registered_office_address'] = isset($company['undeliverable_registered_office_address']) ? (int)$company['undeliverable_registered_office_address'] : null;
                $settings['has_super_secure_pscs'] = isset($company['has_super_secure_pscs']) ? (int)$company['has_super_secure_pscs'] : null;
                $settings['companies_house_environment'] = (string)($company['companies_house_environment'] ?? '');
                $settings['companies_house_etag'] = (string)($company['companies_house_etag'] ?? '');
                $settings['companies_house_last_checked_at'] = (string)($company['companies_house_last_checked_at'] ?? '');
                $settings['companies_house_profile_json'] = (string)($company['companies_house_profile_json'] ?? '');
                $settings['is_vat_registered'] = !empty($company['is_vat_registered']);
                $settings['vat_country_code'] = strtoupper(trim((string)($company['vat_country_code'] ?? '')));
                $settings['vat_number'] = (string)($company['vat_number'] ?? '');
                $settings['vat_validation_status'] = (string)($company['vat_validation_status'] ?? '');
                $settings['vat_validated_at'] = (string)($company['vat_validated_at'] ?? '');
                $settings['vat_validation_source'] = (string)($company['vat_validation_source'] ?? '');
                $settings['vat_validation_name'] = (string)($company['vat_validation_name'] ?? '');
                $settings['vat_validation_address_line1'] = (string)($company['vat_validation_address_line1'] ?? '');
                $settings['vat_validation_postcode'] = (string)($company['vat_validation_postcode'] ?? '');
                $settings['vat_validation_country_code'] = (string)($company['vat_validation_country_code'] ?? '');
                $settings['vat_last_error'] = (string)($company['vat_last_error'] ?? '');
            }
        }

        if ($taxYearId > 0) {
            $taxYear = $taxYearRepository->fetchTaxYear($companyId, $taxYearId);

            if ($taxYear !== null) {
                $settings['financial_period_label'] = (string)($taxYear['label'] ?? '');
                $settings['period_start'] = (string)($taxYear['period_start'] ?? '');
                $settings['period_end'] = (string)($taxYear['period_end'] ?? '');
            }
        }

        foreach ($settingsStore->all() as $setting => $value) {
            $settings[$setting] = $value;
        }

        return $settings;
    }

    public function saveToDatabase(CompanySettingsStore $settingsStore, array $settings): void
    {
        $companyRepository = new CompanyRepository();
        $taxYearRepository = new TaxYearRepository();
        InterfaceDB::beginTransaction();

        try {
            $companyRepository->updateBasicDetails($settings);
            $taxYearRepository->updatePeriod(
                (int)$settings['company_id'],
                (int)$settings['tax_year_id'],
                (string)$settings['financial_period_label'],
                (string)$settings['period_start'],
                (string)$settings['period_end']
            );

            foreach (CompanySettingsStore::definitions() as $settingName => $definition) {
                if (!array_key_exists($settingName, $settings)) {
                    continue;
                }

                $settingsStore->set($settingName, $settings[$settingName], (string)$definition['type']);
            }

            $settingsStore->flush();
            InterfaceDB::commit();
        } catch (Throwable $e) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    public function saveCompanySection(CompanySettingsStore $settingsStore, array $settings): void
    {
        $companyRepository = new CompanyRepository();
        InterfaceDB::beginTransaction();

        try {
            $companyRepository->saveCompanySection($settings);

            $settingsStore->set('utr', $settings['utr'], 'int');
            $settingsStore->set('default_currency', $settings['default_currency'], 'char');
            $settingsStore->set('date_format', $settings['date_format'], 'char');
            $settingsStore->flush();

            InterfaceDB::commit();
        } catch (Throwable $e) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    public function saveAccountingSection(CompanySettingsStore $settingsStore, array &$settings): void
    {
        $taxYearRepository = new TaxYearRepository();
        InterfaceDB::beginTransaction();

        try {
            if ($settings['tax_year_id'] === '') {
                $newPeriodId = $taxYearRepository->createPeriod(
                    (int)$settings['company_id'],
                    (string)$settings['period_start'],
                    (string)$settings['period_end'],
                    (string)$settings['financial_period_label']
                );
                $settings['tax_year_id'] = $newPeriodId > 0 ? (string)$newPeriodId : '';
            } else {
                $taxYearRepository->updatePeriod(
                    (int)$settings['company_id'],
                    (int)$settings['tax_year_id'],
                    (string)$settings['financial_period_label'],
                    (string)$settings['period_start'],
                    (string)$settings['period_end']
                );
            }

            $settingsStore->flush();
            InterfaceDB::commit();
        } catch (Throwable $e) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    public function saveNominalsSection(CompanySettingsStore $settingsStore, array $settings): void
    {
        $settingsStore->set('default_bank_nominal_id', $settings['default_bank_nominal_id'], 'int');
        $settingsStore->set('default_expense_nominal_id', $settings['default_expense_nominal_id'], 'int');
        $settingsStore->set('director_loan_nominal_id', $settings['director_loan_nominal_id'], 'int');
        $settingsStore->set('vat_nominal_id', $settings['vat_nominal_id'], 'int');
        $settingsStore->set('uncategorised_nominal_id', $settings['uncategorised_nominal_id'], 'int');
        $settingsStore->flush();
    }

    public function saveImportReviewSection(CompanySettingsStore $settingsStore, array $settings): void
    {
        $settingsStore->set('enable_duplicate_file_check', $settings['enable_duplicate_file_check'], 'bool');
        $settingsStore->set('enable_duplicate_row_check', $settings['enable_duplicate_row_check'], 'bool');
        $settingsStore->set('auto_create_rule_prompt', $settings['auto_create_rule_prompt'], 'bool');
        $settingsStore->set('lock_posted_periods', $settings['lock_posted_periods'], 'bool');
        $settingsStore->flush();
    }

    public function applyNominalSuggestions(CompanySettingsStore $settingsStore, array &$settings, array $nominalAccounts): bool
    {
        $suggestions = $this->buildNominalDefaultSuggestions($nominalAccounts);

        if (count($suggestions) !== 5) {
            return false;
        }

        $settings['default_bank_nominal_id'] = (string)$suggestions['default_bank_nominal_id']['id'];
        $settings['default_expense_nominal_id'] = (string)$suggestions['default_expense_nominal_id']['id'];
        $settings['director_loan_nominal_id'] = (string)$suggestions['director_loan_nominal_id']['id'];
        $settings['vat_nominal_id'] = (string)$suggestions['vat_nominal_id']['id'];
        $settings['uncategorised_nominal_id'] = (string)$suggestions['uncategorised_nominal_id']['id'];

        $this->saveNominalsSection($settingsStore, $settings);

        return true;
    }

    public function hasCompanySettingsRow(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        $stmt = InterfaceDB::prepareExecute('SELECT COUNT(*) FROM company_settings WHERE company_id = ?', [$companyId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function defaultSettings(): array
    {
        return array_merge(CompanySettingsStore::defaults(), [
            'company_id' => '',
            'tax_year_id' => '',
            'company_name' => '',
            'companies_house_number' => '',
            'incorporation_date' => '',
            'company_status' => '',
            'registered_office_address_line_1' => '',
            'registered_office_address_line_2' => '',
            'registered_office_locality' => '',
            'registered_office_region' => '',
            'registered_office_postal_code' => '',
            'registered_office_country' => '',
            'registered_office_care_of' => '',
            'registered_office_po_box' => '',
            'registered_office_premises' => '',
            'can_file' => null,
            'has_charges' => null,
            'has_insolvency_history' => null,
            'has_been_liquidated' => null,
            'registered_office_is_in_dispute' => null,
            'undeliverable_registered_office_address' => null,
            'has_super_secure_pscs' => null,
            'companies_house_environment' => '',
            'companies_house_etag' => '',
            'companies_house_last_checked_at' => '',
            'companies_house_profile_json' => '',
            'is_vat_registered' => false,
            'vat_country_code' => '',
            'vat_number' => '',
            'vat_validation_status' => '',
            'vat_validated_at' => '',
            'vat_validation_source' => '',
            'vat_validation_name' => '',
            'vat_validation_address_line1' => '',
            'vat_validation_postcode' => '',
            'vat_validation_country_code' => '',
            'vat_last_error' => '',
            'financial_period_label' => '',
            'period_start' => '',
            'period_end' => '',
        ]);
    }

    private function buildNominalDefaultSuggestions(array $nominalAccounts): array
    {
        $normalised = array_map(static function (array $row): array {
            return [
                'id' => (int)($row['id'] ?? 0),
                'code' => trim((string)($row['code'] ?? '')),
                'name' => trim((string)($row['name'] ?? '')),
                'account_type' => strtolower(trim((string)($row['account_type'] ?? ''))),
                'subtype_code' => strtolower(trim((string)($row['subtype_code'] ?? ''))),
            ];
        }, $nominalAccounts);

        return array_filter([
            'default_bank_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['id'] > 0
                    && ($row['subtype_code'] === 'bank'
                        || $row['code'] === '1200'
                        || str_contains(strtolower($row['name']), 'bank'));
            }),
            'default_expense_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && $row['account_type'] === 'expense'
                    && !str_contains($name, 'director loan')
                    && !str_contains($name, 'vat')
                    && !str_contains($name, 'tax');
            }),
            'director_loan_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['id'] > 0
                    && ($row['subtype_code'] === 'director_loan_liability'
                        || str_contains(strtolower($row['name']), 'director loan'));
            }),
            'vat_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['id'] > 0
                    && ($row['subtype_code'] === 'vat_control'
                        || str_contains(strtolower($row['name']), 'vat')
                        || str_contains(strtolower($row['code']), 'vat'));
            }),
            'uncategorised_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && ($row['code'] === '9999'
                        || str_contains($name, 'uncategorised')
                        || str_contains($name, 'unclassified'));
            }),
        ], static fn(?array $row): bool => $row !== null);
    }

    private function firstMatchingNominal(array $nominals, callable $predicate): ?array
    {
        foreach ($nominals as $nominal) {
            if ($predicate($nominal)) {
                return $nominal;
            }
        }

        return null;
    }
}
