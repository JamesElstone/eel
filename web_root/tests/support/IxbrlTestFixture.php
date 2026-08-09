<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'StandardNominalTestFixture.php';

function ixbrl_test_ensure_frs105_thresholds(): void
{
    (new \eel_accounts\Service\TaxRateRuleService())->ensureSchema();
    foreach ([
        ['turnover', 632000.0, '1900-01-01', '2025-04-05'],
        ['balance_sheet_total', 316000.0, '1900-01-01', '2025-04-05'],
        ['employees', 10.0, '1900-01-01', '2025-04-05'],
        ['turnover', 1000000.0, '2025-04-06', '9999-12-31'],
        ['balance_sheet_total', 500000.0, '2025-04-06', '9999-12-31'],
        ['employees', 10.0, '2025-04-06', '9999-12-31'],
    ] as [$key, $amount, $periodStart, $periodEnd]) {
        if ((int)InterfaceDB::fetchColumn(
            'SELECT COUNT(*) FROM tax_rate_rules
             WHERE tax_domain = :domain AND regime = :regime
               AND rule_key = :rule_key AND period_start = :period_start',
            [
                'domain' => 'company_size',
                'regime' => 'frs105_micro_entity',
                'rule_key' => $key,
                'period_start' => $periodStart,
            ]
        ) > 0) {
            continue;
        }

        InterfaceDB::prepareExecute(
            'INSERT INTO tax_rate_rules (
                tax_domain, regime, rule_key, rule_label, period_start, period_end, value_type,
                amount_value, source_url, source_checked_at, rule_version, is_active, notes
             ) VALUES (
                :domain, :regime, :rule_key, :label, :period_start, :period_end, :value_type,
                :amount, :source_url, :checked_at, :version, 1, :notes
             )',
            [
                'domain' => 'company_size',
                'regime' => 'frs105_micro_entity',
                'rule_key' => $key,
                'label' => 'FRS 105 ' . $key,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'value_type' => 'amount',
                'amount' => $amount,
                'source_url' => 'https://www.gov.uk/annual-accounts/microentities-small-and-dormant-companies',
                'checked_at' => '2026-07-17',
                'version' => 'fixture-frs105-' . $periodStart . '-' . $key,
                'notes' => 'Test fixture.',
            ]
        );
    }
}

function ixbrl_test_assign_principal_activity(int $companyId): string
{
    $sicCode = '43210';
    $sectionId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM sic_section WHERE section_letter = :section_letter LIMIT 1',
        ['section_letter' => 'F']
    );
    if ($sectionId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO sic_section (section_letter, description) VALUES (:section_letter, :description)',
            ['section_letter' => 'F', 'description' => 'Construction']
        );
        $sectionId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM sic_section WHERE section_letter = :section_letter LIMIT 1',
            ['section_letter' => 'F']
        );
    }
    if ((int)InterfaceDB::fetchColumn(
        'SELECT COUNT(*) FROM sic_codes WHERE sic_code = :sic_code',
        ['sic_code' => $sicCode]
    ) === 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO sic_codes (section_id, sic_code, description)
             VALUES (:section_id, :sic_code, :description)',
            [
                'section_id' => $sectionId,
                'sic_code' => $sicCode,
                'description' => 'Electrical installation',
            ]
        );
    }

    $profileJson = (string)(InterfaceDB::fetchColumn(
        'SELECT companies_house_profile_json FROM companies WHERE id = :company_id LIMIT 1',
        ['company_id' => $companyId]
    ) ?: '');
    $profile = json_decode($profileJson, true);
    $profile = is_array($profile) ? $profile : [];
    $profile['sic_codes'] = array_values(array_unique(array_merge(
        is_array($profile['sic_codes'] ?? null) ? $profile['sic_codes'] : [],
        [$sicCode]
    )));
    InterfaceDB::prepareExecute(
        'UPDATE companies SET companies_house_profile_json = :profile_json WHERE id = :company_id',
        [
            'profile_json' => \eel_accounts\Support\Utf8::json($profile, JSON_UNESCAPED_SLASHES),
            'company_id' => $companyId,
        ]
    );

    return $sicCode;
}

function ixbrl_test_assign_sales_nominal(int $companyId): int
{
    $nominalId = (int)InterfaceDB::fetchColumn(
        'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
        ['code' => '4000']
    );
    if ($nominalId <= 0) {
        InterfaceDB::prepareExecute(
            'INSERT INTO nominal_accounts (code, name, account_type, tax_treatment, is_active, sort_order)
             VALUES (:code, :name, :account_type, :tax_treatment, 1, :sort_order)',
            [
                'code' => '4000',
                'name' => 'Sales',
                'account_type' => 'income',
                'tax_treatment' => 'other',
                'sort_order' => 4000,
            ]
        );
        $nominalId = (int)InterfaceDB::fetchColumn(
            'SELECT id FROM nominal_accounts WHERE code = :code LIMIT 1',
            ['code' => '4000']
        );
    }

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('default_sales_nominal_id', $nominalId, 'int');
    $settings->flush();

    return $nominalId;
}

/**
 * Give report-building fixtures valid Director Loan controls without making
 * unrelated bank or creditor balances part of the Director Loan Statement.
 * A fixture can supply either control when it needs to post against a custom
 * nominal; the other control remains the standard zero-balance nominal.
 */
function ixbrl_test_assign_director_loan_nominals(
    int $companyId,
    int $assetNominalId = 0,
    int $liabilityNominalId = 0
): array {
    StandardNominalTestFixture::ensureNominals(['1200', '2100']);
    $assetNominalId = $assetNominalId > 0
        ? $assetNominalId
        : StandardNominalTestFixture::id('1200');
    $liabilityNominalId = $liabilityNominalId > 0
        ? $liabilityNominalId
        : StandardNominalTestFixture::id('2100');

    $settings = new \eel_accounts\Store\CompanySettingsStore($companyId);
    $settings->set('participator_loan_asset_nominal_id', $assetNominalId, 'int');
    $settings->set('participator_loan_liability_nominal_id', $liabilityNominalId, 'int');
    $settings->flush();

    return ['asset' => $assetNominalId, 'liability' => $liabilityNominalId];
}

/** Return a director who was in office on the approval date, creating one only when needed. */
function ixbrl_test_ensure_approving_director(int $companyId, string $approvalDate): array
{
    $director = InterfaceDB::fetchOne(
        'SELECT id, full_name, appointed_on, resigned_on
         FROM company_directors
         WHERE company_id = :company_id
           AND LOWER(TRIM(officer_role)) = :director_role
           AND (appointed_on IS NULL OR appointed_on <= :approval_date_appointed)
           AND (resigned_on IS NULL OR resigned_on >= :approval_date_resigned)
         ORDER BY is_active DESC, id ASC
         LIMIT 1',
        [
            'company_id' => $companyId,
            'director_role' => 'director',
            'approval_date_appointed' => $approvalDate,
            'approval_date_resigned' => $approvalDate,
        ]
    );
    if (is_array($director)) {
        return $director;
    }

    $externalKey = 'ixbrl-approving-director-' . $companyId;
    InterfaceDB::prepareExecute(
        'INSERT INTO company_directors (
            company_id, source, external_key, full_name, officer_role,
            appointed_on, resigned_on, is_active
         ) VALUES (
            :company_id, :source, :external_key, :full_name, :officer_role,
            :appointed_on, NULL, 1
         )',
        [
            'company_id' => $companyId,
            'source' => 'test-fixture',
            'external_key' => $externalKey,
            'full_name' => 'Test Director',
            'officer_role' => 'director',
            'appointed_on' => $approvalDate,
        ]
    );

    $director = InterfaceDB::fetchOne(
        'SELECT id, full_name, appointed_on, resigned_on
         FROM company_directors
         WHERE company_id = :company_id
           AND source = :source
           AND external_key = :external_key
         LIMIT 1',
        [
            'company_id' => $companyId,
            'source' => 'test-fixture',
            'external_key' => $externalKey,
        ]
    );
    if (!is_array($director)) {
        throw new RuntimeException('The iXBRL approving-director fixture could not be created.');
    }

    return $director;
}

function ixbrl_test_complete_disclosures(
    int $companyId,
    int $accountingPeriodId,
    string $actor = 'test-fixture',
    bool $includeCompaniesHouseProfitLoss = false
): array
{
    ixbrl_test_ensure_frs105_thresholds();
    $period = InterfaceDB::fetchOne(
        'SELECT period_end FROM accounting_periods WHERE id = :period_id AND company_id = :company_id',
        ['period_id' => $accountingPeriodId, 'company_id' => $companyId]
    );
    if (!is_array($period)) {
        throw new RuntimeException('The accounting period is unavailable for the iXBRL disclosure fixture.');
    }
    $approvingDirector = ixbrl_test_ensure_approving_director(
        $companyId,
        (string)$period['period_end']
    );
    $principalActivitySicCode = ixbrl_test_assign_principal_activity($companyId);
    InterfaceDB::prepareExecute(
        'UPDATE companies SET
            company_status = COALESCE(NULLIF(company_status, \'\'), :company_status),
            companies_house_type = COALESCE(NULLIF(companies_house_type, \'\'), :company_type),
            companies_house_jurisdiction = COALESCE(NULLIF(companies_house_jurisdiction, \'\'), :jurisdiction),
            registered_office_address_line_1 = COALESCE(NULLIF(registered_office_address_line_1, \'\'), :address_line_1),
            registered_office_address_line_2 = COALESCE(NULLIF(registered_office_address_line_2, \'\'), :address_line_2),
            registered_office_locality = COALESCE(NULLIF(registered_office_locality, \'\'), :locality),
            registered_office_postal_code = COALESCE(NULLIF(registered_office_postal_code, \'\'), :postal_code),
            registered_office_country = COALESCE(NULLIF(registered_office_country, \'\'), :country)
         WHERE id = :company_id',
        [
            'company_status' => 'active',
            'company_type' => 'ltd',
            'jurisdiction' => 'england-wales',
            'address_line_1' => '1 Test Street',
            'address_line_2' => 'Test Industrial Estate',
            'locality' => 'Test Town',
            'postal_code' => 'TE1 1ST',
            'country' => 'United Kingdom',
            'company_id' => $companyId,
        ]
    );
    $result = (new \eel_accounts\Service\IxbrlAccountsDisclosureService())->save(
        $companyId,
        $accountingPeriodId,
        [
            'accounting_standard' => 'FRS_105',
            'average_number_employees' => 1,
            'principal_activity_sic_code' => $principalActivitySicCode,
            'entity_dormant' => 0,
            'is_still_trading' => 1,
            'micro_entity_eligibility_confirmed' => 1,
            'going_concern_basis_appropriate' => 1,
            'has_material_off_balance_sheet_arrangements' => 0,
            'has_director_advances_credits_or_guarantees' => 0,
            'has_financial_commitments_guarantees_or_contingencies' => 0,
            'accounts_approval_date' => (string)$period['period_end'],
            'approving_director_id' => (int)$approvingDirector['id'],
            'prepared_under_small_companies_regime' => 1,
            'audit_exempt_section_477' => 1,
            'directors_acknowledge_responsibilities' => 1,
            'members_have_not_required_audit' => 1,
            'profit_loss_not_delivered_section_444' => $includeCompaniesHouseProfitLoss ? 0 : 1,
        ],
        $actor
    );
    if (empty($result['success']) || empty($result['complete'])) {
        throw new RuntimeException(implode(' ', (array)($result['errors'] ?? $result['profile_errors'] ?? $result['missing_labels'] ?? ['Unable to complete iXBRL disclosures.'])));
    }
    ixbrl_test_approve_companies_house_classification(
        $companyId,
        $accountingPeriodId,
        $actor
    );
    return $result;
}

/**
 * Sign the canonical Companies House classification used by deterministic
 * report-building fixtures. Production users perform this approval before
 * Year End is locked; fixtures may already be locked, so preserve that state.
 */
function ixbrl_test_approve_companies_house_classification(
    int $companyId,
    int $accountingPeriodId,
    string $actor = 'test-fixture'
): array {
    $approvals = new \eel_accounts\Service\YearEndSectionApprovalService();
    $current = $approvals->fetchCompaniesHouseReview($companyId, $accountingPeriodId);
    if (!empty($current['acknowledgement_current'])) {
        return $current;
    }

    $wasLocked = (int)InterfaceDB::fetchColumn(
        'SELECT COALESCE(is_locked, 0)
         FROM year_end_reviews
         WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id
         LIMIT 1',
        ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
    ) === 1;
    if ($wasLocked) {
        InterfaceDB::prepareExecute(
            'UPDATE year_end_reviews SET is_locked = 0
             WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
            ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
        );
    }

    try {
        $review = $approvals->fetchCompaniesHouseReview($companyId, $accountingPeriodId);
        $result = $approvals->approve(
            $companyId,
            $accountingPeriodId,
            (string)($review['check_code'] ?? ''),
            [],
            $actor,
            'Approved by deterministic iXBRL test fixture.'
        );
        if (empty($result['success']) && !empty($result['requires_review'])) {
            $review = $approvals->fetchCompaniesHouseReview($companyId, $accountingPeriodId);
            $result = $approvals->approve(
                $companyId,
                $accountingPeriodId,
                (string)($review['check_code'] ?? ''),
                [],
                $actor,
                'Approved by deterministic iXBRL test fixture.'
            );
        }
        if (empty($result['success'])) {
            throw new RuntimeException(
                'Could not approve the Companies House filing classification: '
                . implode(' ', array_map('strval', (array)($result['errors'] ?? [])))
            );
        }
    } finally {
        if ($wasLocked) {
            InterfaceDB::prepareExecute(
                'UPDATE year_end_reviews SET is_locked = 1
                 WHERE company_id = :company_id AND accounting_period_id = :accounting_period_id',
                ['company_id' => $companyId, 'accounting_period_id' => $accountingPeriodId]
            );
        }
    }

    $final = $approvals->fetchCompaniesHouseReview($companyId, $accountingPeriodId);
    if (empty($final['acknowledgement_current'])) {
        $bundle = (array)($final['bundle'] ?? []);
        $basis = [
            'contract_version' => \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION,
            'check_code' => (string)($bundle['check_code'] ?? $final['check_code'] ?? ''),
            'facts' => (array)($bundle['facts'] ?? []),
            'questions' => (array)($bundle['questions'] ?? []),
            'answers' => [],
        ];
        $saved = (new \eel_accounts\Service\YearEndAcknowledgementService())->save(
            $companyId,
            $accountingPeriodId,
            (string)$basis['check_code'],
            $basis,
            $actor,
            'Approved by deterministic iXBRL test fixture.',
            true,
            \eel_accounts\Service\YearEndSectionApprovalService::CONTRACT_VERSION
        );
        if (empty($saved['success'])) {
            throw new RuntimeException('Could not freeze the locked Companies House classification fixture.');
        }
        $final = $approvals->fetchCompaniesHouseReview($companyId, $accountingPeriodId);
    }

    return $final;
}
