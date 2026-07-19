<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);


namespace eel_accounts\Service;

final class CompanySettingsService
{
    public function loadFromDatabase(\eel_accounts\Store\CompanySettingsStore $settingsStore, int $companyId, int $accountingPeriodId): array
    {
        $settings = $this->defaultSettings();
        $settings['company_id'] = $companyId > 0 ? (string)$companyId : '';
        $settings['accounting_period_id'] = $accountingPeriodId > 0 ? (string)$accountingPeriodId : '';

        if ($companyId > 0) {
            $settingsStore->persistMissingDefaults();
        }

        $companyRepository = new \eel_accounts\Repository\CompanyRepository();
        $accountingPeriodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();

        if ($companyId > 0) {
            $company = $companyRepository->fetchCompanyDetails($companyId);

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
                $settings['is_vat_registered'] = !empty($company['is_vat_registered']);
                $settings['vat_country_code'] = strtoupper(trim((string)($company['vat_country_code'] ?? '')));
                $settings['vat_number'] = (string)($company['vat_number'] ?? '');
                $settings['vat_validation_status'] = (string)($company['vat_validation_status'] ?? '');
                $settings['vat_validated_at'] = (string)($company['vat_validated_at'] ?? '');
                $settings['vat_validation_source'] = (string)($company['vat_validation_source'] ?? '');
                $settings['vat_validation_mode'] = (string)($company['vat_validation_mode'] ?? '');
                $settings['vat_validation_name'] = (string)($company['vat_validation_name'] ?? '');
                $settings['vat_validation_address_line1'] = (string)($company['vat_validation_address_line1'] ?? '');
                $settings['vat_validation_postcode'] = (string)($company['vat_validation_postcode'] ?? '');
                $settings['vat_validation_country_code'] = (string)($company['vat_validation_country_code'] ?? '');
                $settings['vat_last_error'] = (string)($company['vat_last_error'] ?? '');
            }
        }

        if ($accountingPeriodId > 0) {
            $accountingPeriod = $accountingPeriodRepository->fetchAccountingPeriod($companyId, $accountingPeriodId);

            if ($accountingPeriod !== null) {
                $settings['financial_period_label'] = (string)($accountingPeriod['label'] ?? '');
                $settings['period_start'] = (string)($accountingPeriod['period_start'] ?? '');
                $settings['period_end'] = (string)($accountingPeriod['period_end'] ?? '');
            }
        }

        foreach ($settingsStore->all() as $setting => $value) {
            $settings[$setting] = $value;
        }

        $settings = $this->normaliseDirectorLoanNominalSettings($settings);

        return $settings;
    }

    public function saveToDatabase(\eel_accounts\Store\CompanySettingsStore $settingsStore, array $settings): void
    {
        $settings = $this->normaliseDirectorLoanNominalSettings($settings);
        (new YearEndLockService())->assertUnlocked(
            (int)$settings['company_id'],
            (int)$settings['accounting_period_id'],
            'change the accounting period details'
        );
        $companyRepository = new \eel_accounts\Repository\CompanyRepository();
        $accountingPeriodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
        \InterfaceDB::beginTransaction();

        try {
            $companyRepository->updateBasicDetails($settings);
            $accountingPeriodRepository->updatePeriod(
                (int)$settings['company_id'],
                (int)$settings['accounting_period_id'],
                (string)$settings['financial_period_label'],
                (string)$settings['period_start'],
                (string)$settings['period_end']
            );

            foreach (\eel_accounts\Store\CompanySettingsStore::definitions() as $settingName => $definition) {
                if (!array_key_exists($settingName, $settings)) {
                    continue;
                }

                $settingsStore->set($settingName, $settings[$settingName], (string)$definition['type']);
            }

            $settingsStore->flush();
            \InterfaceDB::commit();
        } catch (\Throwable $e) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    public function saveCompanySection(\eel_accounts\Store\CompanySettingsStore $settingsStore, array $settings): void
    {
        $companyId = (int)($settings['company_id'] ?? $settingsStore->companyId());
        $hasCessationSetting = array_key_exists('qualifying_activity_ceased_on', $settings);
        $existingCessationDate = trim((string)$settingsStore->get('qualifying_activity_ceased_on', ''));
        $cessationDate = $hasCessationSetting
            ? $this->normaliseQualifyingActivityCessationDate((string)$settings['qualifying_activity_ceased_on'])
            : $existingCessationDate;
        $cessationChanged = $hasCessationSetting && $cessationDate !== $existingCessationDate;

        if ($cessationChanged) {
            (new VatSupportScopeService())->assertTaxAndYearEndSupported(
                $companyId,
                'change the qualifying-activity cessation date'
            );
        }

        $companyRepository = new \eel_accounts\Repository\CompanyRepository();
        $settings['utr'] = $this->normaliseUtr((string)($settings['utr'] ?? ''));
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            if ($cessationChanged) {
                $this->assertCessationChangeUnlocked(
                    $companyId,
                    $existingCessationDate,
                    $cessationDate
                );
            }

            $companyRepository->saveCompanySection($settings);

            $settingsStore->set('utr', $settings['utr'], 'char');
            if ($hasCessationSetting) {
                $settingsStore->set('qualifying_activity_ceased_on', $cessationDate, 'char');
            }
            $settingsStore->set('default_currency', $settings['default_currency'], 'char');
            $settingsStore->set('date_format', $settings['date_format'], 'char');
            $settingsStore->flush();

            if ($cessationChanged) {
                $rebuild = (new CapitalAllowanceService())->rebuildForCompany($companyId);
                if (isset($rebuild['success']) && empty($rebuild['success'])) {
                    throw new \RuntimeException((string)(($rebuild['errors'] ?? [])[0] ?? 'Capital allowances could not be rebuilt.'));
                }
            }

            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    private function normaliseUtr(string $utr): string
    {
        return preg_replace('/\s+/', '', trim($utr)) ?? '';
    }

    private function normaliseQualifyingActivityCessationDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof \DateTimeImmutable
            || $parsed->format('Y-m-d') !== $date
            || (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0))) {
            throw new \RuntimeException('The qualifying-activity cessation date must use the YYYY-MM-DD format.');
        }

        return $date;
    }

    private function assertCessationChangeUnlocked(
        int $companyId,
        string $existingDate,
        string $newDate
    ): void {
        $affectedFrom = $existingDate === ''
            ? $newDate
            : ($newDate === '' ? $existingDate : min($existingDate, $newDate));
        if ($companyId <= 0 || $affectedFrom === '') {
            return;
        }

        $periodIds = \InterfaceDB::fetchAll(
            'SELECT id
             FROM accounting_periods
             WHERE company_id = :company_id
               AND period_end >= :affected_from
             ORDER BY period_start ASC, id ASC',
            [
                'company_id' => $companyId,
                'affected_from' => $affectedFrom,
            ]
        ) ?: [];
        $locks = new YearEndLockService();
        foreach ($periodIds as $row) {
            $periodId = (int)($row['id'] ?? 0);
            if ($periodId <= 0) {
                continue;
            }
            if (\InterfaceDB::tableExists('corporation_tax_periods')
                && (int)\InterfaceDB::fetchColumn(
                    'SELECT COUNT(*)
                     FROM corporation_tax_periods
                     WHERE company_id = :company_id
                       AND accounting_period_id = :accounting_period_id
                       AND status IN (\'submitted\', \'accepted\')',
                    [
                        'company_id' => $companyId,
                        'accounting_period_id' => $periodId,
                    ]
                ) > 0) {
                throw new \RuntimeException(
                    'The qualifying-activity cessation date cannot be changed because it would affect '
                    . 'submitted or accepted Corporation Tax evidence.'
                );
            }

            $locks->assertUnlockedForUpdate(
                $companyId,
                $periodId,
                'change the qualifying-activity cessation date because it affects this period or a later period'
            );
        }
    }

    public function saveAccountingSection(\eel_accounts\Store\CompanySettingsStore $settingsStore, array &$settings): void
    {
        $accountingPeriodRepository = new \eel_accounts\Repository\AccountingPeriodRepository();
        \InterfaceDB::beginTransaction();

        try {
            if ($settings['accounting_period_id'] === '') {
                $newPeriodId = $accountingPeriodRepository->createPeriod(
                    (int)$settings['company_id'],
                    (string)$settings['period_start'],
                    (string)$settings['period_end'],
                    (string)$settings['financial_period_label']
                );
                $settings['accounting_period_id'] = $newPeriodId > 0 ? (string)$newPeriodId : '';
            } else {
                (new YearEndLockService())->assertUnlocked(
                    (int)$settings['company_id'],
                    (int)$settings['accounting_period_id'],
                    'change the accounting period details'
                );
                $accountingPeriodRepository->updatePeriod(
                    (int)$settings['company_id'],
                    (int)$settings['accounting_period_id'],
                    (string)$settings['financial_period_label'],
                    (string)$settings['period_start'],
                    (string)$settings['period_end']
                );
            }

            $settingsStore->flush();
            \InterfaceDB::commit();
        } catch (\Throwable $e) {
            if (\InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $e;
        }
    }

    public function saveNominalsSection(\eel_accounts\Store\CompanySettingsStore $settingsStore, array $settings): void
    {
        $companyId = $settingsStore->companyId();
        $prepayments = new PrepaymentAssetNominalService();
        $change = $prepayments->prepareChange(
            $companyId,
            max(0, (int)($settings['prepayment_asset_nominal_id'] ?? 0))
        );
        $settings['prepayment_asset_nominal_id'] = (string)(int)$change['nominal']['id'];
        $ownsTransaction = !\InterfaceDB::inTransaction();
        if ($ownsTransaction) {
            \InterfaceDB::beginTransaction();
        }

        try {
            $settingsStore->set('default_bank_nominal_id', $settings['default_bank_nominal_id'] ?? '', 'int');
            $settingsStore->set('default_sales_nominal_id', $settings['default_sales_nominal_id'] ?? '', 'int');
            $settingsStore->set('default_trade_nominal_id', $settings['default_trade_nominal_id'] ?? '', 'int');
            $settingsStore->set('default_expense_nominal_id', $settings['default_expense_nominal_id'] ?? '', 'int');
            $settingsStore->set('tools_small_equipment_nominal_id', $settings['tools_small_equipment_nominal_id'] ?? '', 'int');
            $settingsStore->set('prepayment_asset_nominal_id', $settings['prepayment_asset_nominal_id'], 'int');
            $directorLoanLiabilityNominalId = $this->directorLoanLiabilityNominalSetting($settings);
            $settingsStore->set('director_loan_asset_nominal_id', $settings['director_loan_asset_nominal_id'] ?? '', 'int');
            $settingsStore->set('director_loan_liability_nominal_id', $directorLoanLiabilityNominalId, 'int');
            $settingsStore->set('director_loan_nominal_id', $directorLoanLiabilityNominalId, 'int');
            $settingsStore->set('participator_loan_asset_nominal_id', $settings['participator_loan_asset_nominal_id'] ?? '', 'int');
            $settingsStore->set('participator_loan_liability_nominal_id', $settings['participator_loan_liability_nominal_id'] ?? '', 'int');
            $settingsStore->set('vat_nominal_id', $settings['vat_nominal_id'] ?? '', 'int');
            $settingsStore->set('uncategorised_nominal_id', $settings['uncategorised_nominal_id'] ?? '', 'int');
            $settingsStore->set('corporation_tax_expense_nominal_id', $settings['corporation_tax_expense_nominal_id'] ?? '', 'int');
            $settingsStore->set('corporation_tax_liability_nominal_id', $settings['corporation_tax_liability_nominal_id'] ?? '', 'int');
            foreach ($this->helperNominalSettingKeys() as $key) {
                $settingsStore->set($key, $settings[$key] ?? '', 'int');
            }
            $settingsStore->flush();
            if (!empty($change['changed'])) {
                $prepayments->synchroniseChange(
                    $companyId,
                    (array)$change['review_ids'],
                    (array)$change['accounting_period_ids']
                );
            }
            if ($ownsTransaction) {
                \InterfaceDB::commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && \InterfaceDB::inTransaction()) {
                \InterfaceDB::rollBack();
            }
            throw $exception;
        }
    }

    public function saveImportReviewSection(\eel_accounts\Store\CompanySettingsStore $settingsStore, array $settings): void
    {
        $settingsStore->set('enable_duplicate_file_check', $settings['enable_duplicate_file_check'], 'bool');
        $settingsStore->set('enable_duplicate_row_check', $settings['enable_duplicate_row_check'], 'bool');
        $settingsStore->set('auto_create_rule_prompt', $settings['auto_create_rule_prompt'], 'bool');
        $settingsStore->set('lock_posted_periods', $settings['lock_posted_periods'], 'bool');
        $settingsStore->flush();
    }

    public function applyNominalSuggestions(\eel_accounts\Store\CompanySettingsStore $settingsStore, array &$settings, array $nominalAccounts): bool
    {
        $suggestions = $this->buildNominalDefaultSuggestions($nominalAccounts);
        $applied = false;

        foreach ([
            'default_bank_nominal_id',
            'default_sales_nominal_id',
            'default_trade_nominal_id',
            'default_expense_nominal_id',
            'tools_small_equipment_nominal_id',
            'prepayment_asset_nominal_id',
            'director_loan_asset_nominal_id',
            'director_loan_liability_nominal_id',
            'participator_loan_asset_nominal_id',
            'participator_loan_liability_nominal_id',
            'vat_nominal_id',
            'uncategorised_nominal_id',
            'corporation_tax_expense_nominal_id',
            'corporation_tax_liability_nominal_id',
            ...$this->helperNominalSettingKeys(),
        ] as $key) {
            if ($this->hasAssignedNominal($settings, $key)) {
                continue;
            }

            if (!isset($suggestions[$key]['id'])) {
                continue;
            }

            $settings[$key] = (string)$suggestions[$key]['id'];
            if ($key === 'director_loan_liability_nominal_id') {
                $settings['director_loan_nominal_id'] = (string)$suggestions[$key]['id'];
            }
            $applied = true;
        }

        if (!$applied) {
            return false;
        }

        $this->saveNominalsSection($settingsStore, $settings);

        return true;
    }

    private function normaliseDirectorLoanNominalSettings(array $settings): array
    {
        $liabilityNominalId = $this->directorLoanLiabilityNominalSetting($settings);
        if ($liabilityNominalId !== '') {
            $settings['director_loan_liability_nominal_id'] = $liabilityNominalId;
            $settings['director_loan_nominal_id'] = $liabilityNominalId;
        }

        return $settings;
    }

    private function directorLoanLiabilityNominalSetting(array $settings): string
    {
        $liabilityNominalId = trim((string)($settings['director_loan_liability_nominal_id'] ?? ''));
        if ($liabilityNominalId !== '') {
            return $liabilityNominalId;
        }

        return trim((string)($settings['director_loan_nominal_id'] ?? ''));
    }

    public function hasCompanySettingsRow(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        return \InterfaceDB::countWhere('company_settings', 'company_id', $companyId) > 0;
    }

    public function defaultCurrencySymbol(array $settings): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->defaultCurrencySymbol($settings);
    }

    public function money(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->format($settings, $value);
    }

    public function moneyHtml(array $settings, mixed $value): string
    {
        return (new \eel_accounts\Service\MoneyFormatService())->formatHtml($settings, $value);
    }

    public function parseMoney(mixed $value): ?float
    {
        return (new \eel_accounts\Service\MoneyFormatService())->parseAmount($value);
    }

    private function defaultSettings(): array
    {
        return array_merge(\eel_accounts\Store\CompanySettingsStore::defaults(), [
            'company_id' => '',
            'accounting_period_id' => '',
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
            'is_vat_registered' => false,
            'vat_country_code' => '',
            'vat_number' => '',
            'vat_validation_status' => '',
            'vat_validated_at' => '',
            'vat_validation_source' => '',
            'vat_validation_mode' => '',
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

        $suggestions = array_filter([
            'default_bank_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['id'] > 0
                    && ($row['subtype_code'] === 'bank'
                        || $row['code'] === '1200'
                        || str_contains(strtolower($row['name']), 'bank'));
            }),
            'default_sales_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && $row['account_type'] === 'income'
                    && ($row['subtype_code'] === 'turnover'
                        || $row['code'] === '4000'
                        || str_contains($name, 'sales'));
            }),
            'default_trade_nominal_id' => $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['code'] === '2300')
                ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && $row['account_type'] === 'liability'
                    && ($row['subtype_code'] === 'trade_creditor'
                        || str_contains($name, 'trade creditor'));
            }),
            'default_expense_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && $row['account_type'] === 'liability'
                    && ($row['subtype_code'] === 'expense_payable'
                        || $row['code'] === '2110'
                        || str_contains($name, 'expense claims payable'));
            }) ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                $name = strtolower($row['name']);

                return $row['id'] > 0
                    && $row['account_type'] === 'expense'
                    && !str_contains($name, 'director loan')
                    && !str_contains($name, 'vat')
                    && !str_contains($name, 'tax');
            }),
            'tools_small_equipment_nominal_id' => $this->firstMatchingNominal($normalised, static fn(array $row): bool => $row['id'] > 0 && $row['code'] === '6070')
                ?? $this->firstMatchingNominal($normalised, static function (array $row): bool {
                    $name = strtolower($row['name']);

                    return $row['id'] > 0
                        && $row['account_type'] === 'expense'
                        && str_contains($name, 'tools')
                        && str_contains($name, 'equipment');
                }),
            'prepayment_asset_nominal_id' => $this->firstMatchingNominal(
                $normalised,
                static fn(array $row): bool => $row['id'] > 0
                    && $row['account_type'] === 'asset'
                    && $row['subtype_code'] === 'prepayments'
            ),
            'director_loan_asset_nominal_id' => $this->directorLoanAssetNominalSuggestion($normalised),
            'director_loan_liability_nominal_id' => $this->directorLoanLiabilityNominalSuggestion($normalised),
            'director_loan_nominal_id' => $this->directorLoanLiabilityNominalSuggestion($normalised),
            'participator_loan_asset_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['account_type'] === 'asset' && $row['subtype_code'] === 'participator_loan_asset';
            }),
            'participator_loan_liability_nominal_id' => $this->firstMatchingNominal($normalised, static function (array $row): bool {
                return $row['account_type'] === 'liability' && $row['subtype_code'] === 'participator_loan_liability';
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
            'corporation_tax_expense_nominal_id' => $this->firstMatchingNominal(
                $normalised,
                static fn(array $row): bool => $row['id'] > 0 && $row['account_type'] === 'expense' && $row['subtype_code'] === 'corp_tax_expense'
            ),
            'corporation_tax_liability_nominal_id' => $this->firstMatchingNominal(
                $normalised,
                static fn(array $row): bool => $row['id'] > 0 && $row['account_type'] === 'liability' && $row['subtype_code'] === 'corp_tax'
            ),
        ], static fn(?array $row): bool => $row !== null);

        $byCode = [];
        foreach ($normalised as $row) {
            $byCode[(string)$row['code']] = $row;
        }
        $suggestions['dividends_payable_nominal_id'] = $byCode['2150'] ?? null;
        $suggestions['default_expense_charge_nominal_id'] = $byCode['6000'] ?? $this->firstMatchingNominal(
            $normalised,
            static fn(array $row): bool => $row['id'] > 0 && $row['account_type'] === 'expense'
        );
        foreach (AssetService::assetCategoryOptions() as $category => $_label) {
            $codes = AssetService::assetNominalCodesForCategory((string)$category);
            $suggestions[$category . '_asset_cost_nominal_id'] = $byCode[(string)$codes['cost']] ?? null;
            $suggestions[$category . '_accum_dep_nominal_id'] = $byCode[(string)$codes['accum']] ?? null;
        }

        return array_filter($suggestions, static fn(?array $row): bool => $row !== null);
    }

    public function helperNominalSettingKeys(): array
    {
        $keys = ['dividends_payable_nominal_id', 'default_expense_charge_nominal_id'];
        foreach (AssetService::assetCategoryOptions() as $category => $_label) {
            $keys[] = $category . '_asset_cost_nominal_id';
            $keys[] = $category . '_accum_dep_nominal_id';
        }

        return $keys;
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

    private function directorLoanAssetNominalSuggestion(array $nominals): ?array
    {
        return $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['subtype_code'] === 'director_loan_asset'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'asset'
                && $row['code'] === '1200'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'asset'
                && str_contains(strtolower($row['name']), 'director loan')
        );
    }

    private function directorLoanLiabilityNominalSuggestion(array $nominals): ?array
    {
        return $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['subtype_code'] === 'director_loan_liability'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'liability'
                && $row['code'] === '2100'
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && $row['account_type'] === 'liability'
                && str_contains(strtolower($row['name']), 'director loan')
        ) ?? $this->firstMatchingNominal(
            $nominals,
            static fn(array $row): bool => $row['id'] > 0
                && str_contains(strtolower($row['name']), 'director loan')
        );
    }

    private function hasAssignedNominal(array $settings, string $key): bool
    {
        return (int)($settings[$key] ?? 0) > 0;
    }
}
