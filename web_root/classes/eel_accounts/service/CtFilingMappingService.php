<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

final class CtFilingMappingService
{
    public const TARGET_RIM = 'ct600_rim';
    public const TARGET_COMPUTATION = 'computation_ixbrl';
    public const CONTEXT_HMRC_CT_COMPANY = 'hmrc_ct_company';
    public const CONTEXT_HMRC_CT_UK_TRADE = 'hmrc_ct_uk_trade';

    public function fetchProfiles(?string $targetType = null): array
    {
        if (!\InterfaceDB::tableExists('ct_filing_mapping_profiles')) {
            return [];
        }
        $params = [];
        $where = '';
        if ($targetType !== null) {
            $this->assertTarget($targetType);
            $where = ' WHERE p.target_type = :target_type';
            $params['target_type'] = $targetType;
        }
        return \InterfaceDB::fetchAll(
            'SELECT p.*, r.form_version AS rim_version, r.artifact_version AS rim_artifact_version,
                    c.taxonomy_version, c.artifact_version AS taxonomy_artifact_version
             FROM ct_filing_mapping_profiles p
             LEFT JOIN hmrc_ct_rim_packages r ON r.id = p.rim_package_id
             LEFT JOIN hmrc_ct_computation_packages c ON c.id = p.computation_package_id'
             . $where . ' ORDER BY p.created_at DESC, p.id DESC',
            $params
        );
    }

    public function activeProfile(string $targetType, int $packageId): ?array
    {
        $this->assertTarget($targetType);
        $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $row = \InterfaceDB::fetchOne(
            'SELECT * FROM ct_filing_mapping_profiles
             WHERE target_type = :target_type AND ' . $column . ' = :package_id
               AND status = :status AND compatibility_status = :compatibility
             ORDER BY revision_no DESC, id DESC LIMIT 1',
            ['target_type' => $targetType, 'package_id' => $packageId, 'status' => 'active', 'compatibility' => 'compatible']
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Reviewed templates are keyed only by the package's published natural
     * identity. No database id is portable between installations.
     */
    public function reviewedTemplate(string $targetType, string $version, string $artifactVersion): ?array
    {
        $this->assertTarget($targetType);
        $version = strtoupper(trim($version));
        $artifactVersion = strtoupper(trim($artifactVersion));
        if ($targetType === self::TARGET_RIM
            && $version === 'V3' && $artifactVersion === 'V1.994') {
            return [
                'profile_name' => 'reviewed_ct600_v3_v1_994_return_v2',
                'natural_identity' => ['form_version' => 'V3', 'artifact_version' => 'V1.994'],
                'mappings' => [
                    ['canonical_key' => 'identity.company_name', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/CompanyName'],
                    ['canonical_key' => 'identity.company_number', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/RegistrationNumber'],
                    ['canonical_key' => 'filing_identity.utr', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/Reference'],
                    ['canonical_key' => 'ct_period.start_date', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/From'],
                    ['canonical_key' => 'ct_period.end_date', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyInformation/PeriodCovered/To'],
                    ['canonical_key' => 'accounts_facts.turnover', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/Turnover/Total'],
                    ['canonical_key' => 'filing_decisions.trading_profit_before_losses', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/Profits'],
                    ['canonical_key' => 'filing_decisions.trading_losses_brought_forward_used', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward'],
                    ['canonical_key' => 'filing_decisions.net_trading_profits', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/NetProfits'],
                    ['canonical_key' => 'filing_decisions.profits_before_other_deductions', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ProfitsBeforeOtherDeductions'],
                    ['canonical_key' => 'filing_decisions.profits_before_donations_group_relief', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargesAndReliefs/ProfitsBeforeDonationsAndGroupRelief'],
                    ['canonical_key' => 'computation.summary.taxable_profit', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/ChargeableProfits'],
                    ['canonical_key' => 'computation.summary.ordinary_corporation_tax', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/NetCorporationTaxChargeable'],
                    ['canonical_key' => 'filing_decisions.associated_company_count', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/CorporationTaxChargeable/AssociatedCompanies/ThisPeriod'],
                    ['canonical_key' => 'return_position.ct600a_a80', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/LoansToParticipators'],
                    ['canonical_key' => 'computation.summary.ordinary_corporation_tax', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/NetCorporationTaxLiability'],
                    ['canonical_key' => 'return_position.tax_payable', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxChargeable'],
                    ['canonical_key' => 'return_position.tax_payable', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/CalculationOfTaxOutstandingOrOverpaid/TaxPayable'],
                    ['canonical_key' => 'filing_decisions.aia_claimed_in_trade', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/AIACapitalAllowancesInc'],
                    ['canonical_key' => 'filing_decisions.special_rate_pool_capital_allowances', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantSpecialRatePool/CapitalAllowances'],
                    ['canonical_key' => 'filing_decisions.special_rate_pool_balancing_charges', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantSpecialRatePool/BalancingCharges'],
                    ['canonical_key' => 'filing_decisions.main_pool_capital_allowances', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantMainPool/CapitalAllowances'],
                    ['canonical_key' => 'filing_decisions.main_pool_balancing_charges', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/AllowancesAndCharges/MachineryAndPlantMainPool/BalancingCharges'],
                    ['canonical_key' => 'filing_decisions.qualifying_expenditure_other_machinery_plant', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/QualifyingExpenditure/OtherMachineryAndPlant'],
                    ['canonical_key' => 'computation.summary.loss_created_in_period', 'target_xpath' => 'IRenvelope/CompanyTaxReturn/LossesDeficitsAndExcess/AmountArising/LossesOfTradesUK/Arising'],
                ],
                'unsupported_decisions' => [
                    [
                        'canonical_keys' => ['computation.summary.losses_used', 'computation.summary.losses_carried_forward'],
                        'reason' => 'The frozen aggregate values do not distinguish a box 275 current/later-period claim from a box 285 carried-forward-loss claim. Box 160 is supported only by the exact frozen same-trade relief decision and amount.',
                        'claim_targets' => [
                            'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/DeductionsAndReliefs/TradingLosses',
                            'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/DeductionsAndReliefs/TradingLossesCarriedForward',
                        ],
                    ],
                    [
                        'canonical_keys' => [],
                        'reason' => 'A carry-back election must be explicit; no numeric loss balance may imply it.',
                        'claim_targets' => ['IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/DeductionsAndReliefs/TradingLossesCarriedBack'],
                    ],
                ],
            ];
        }
        if ($targetType === self::TARGET_COMPUTATION
            && in_array($version, ['2024', '2025'], true) && $artifactVersion === 'V1.0.0') {
            return [
                'profile_name' => 'reviewed_ct_computation_' . $version . '_v1_0_0_return_v2',
                'natural_identity' => ['taxonomy_version' => $version, 'artifact_version' => 'V1.0.0'],
                'mappings' => [
                    ['canonical_key' => 'identity.company_name', 'local_name' => 'CompanyName', 'period_type' => 'instant', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'filing_identity.utr', 'local_name' => 'TaxReference', 'period_type' => 'instant', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'ct_period.start_date', 'local_name' => 'StartOfPeriodCoveredByReturn', 'period_type' => 'instant', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'ct_period.end_date', 'local_name' => 'EndOfPeriodCoveredByReturn', 'period_type' => 'instant', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'computation.summary.accounting_profit', 'local_name' => 'ProfitLossPerAccounts', 'context_profile' => self::CONTEXT_HMRC_CT_UK_TRADE],
                    ['canonical_key' => 'computation.summary.disallowable_add_backs', 'local_name' => 'AdjustmentsMiscellaneousExpensesPerAccounts', 'context_profile' => self::CONTEXT_HMRC_CT_UK_TRADE],
                    ['canonical_key' => 'computation.summary.capital_add_backs', 'local_name' => 'AdjustmentsCapitalExpenditure', 'context_profile' => self::CONTEXT_HMRC_CT_UK_TRADE],
                    ['canonical_key' => 'computation.summary.depreciation_add_back', 'local_name' => 'AdjustmentsDepreciation', 'context_profile' => self::CONTEXT_HMRC_CT_UK_TRADE],
                    ['canonical_key' => 'computation.summary.capital_allowances', 'local_name' => 'TotalCapitalAllowances', 'context_profile' => self::CONTEXT_HMRC_CT_UK_TRADE],
                    ['canonical_key' => 'computation.summary.taxable_before_losses', 'local_name' => 'ProfitsBeforeOtherDeductionsAndReliefs', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'computation.summary.losses_brought_forward', 'local_name' => 'TradingLossesBroughtForward', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'computation.summary.losses_used', 'local_name' => 'TradingLossesBroughtForwardAmountUsedAgainstTotalProfits', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'computation.summary.taxable_profit', 'local_name' => 'TotalProfitsChargeableToCorporationTax', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'computation.summary.ordinary_corporation_tax', 'local_name' => 'CorporationTaxChargeable', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'return_position.ct600a_a80', 'local_name' => 'TaxPayableOnLoansToParticipators', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                    ['canonical_key' => 'return_position.tax_payable', 'local_name' => 'NetTaxPayable', 'context_profile' => self::CONTEXT_HMRC_CT_COMPANY],
                ],
                'unsupported_decisions' => [],
            ];
        }
        return null;
    }

    /**
     * Seed and activate an exact reviewed template. Unknown future natural
     * identities receive a draft clone only and therefore fail closed.
     */
    public function prepareMappingsForPackage(string $targetType, int $packageId, string $actor = 'system'): int
    {
        $this->assertTarget($targetType);
        if ($packageId <= 0) { throw new \InvalidArgumentException('A package id is required.'); }
        $packageTable = $targetType === self::TARGET_RIM ? 'hmrc_ct_rim_packages' : 'hmrc_ct_computation_packages';
        $package = \InterfaceDB::fetchOne(
            'SELECT * FROM ' . $packageTable . ' WHERE id = :id LIMIT 1',
            ['id' => $packageId]
        );
        if (!is_array($package)) { throw new \RuntimeException('The mapping package was not found.'); }
        $version = $targetType === self::TARGET_RIM
            ? (string)$package['form_version']
            : (string)$package['taxonomy_version'];
        $template = $this->reviewedTemplate($targetType, $version, (string)$package['artifact_version']);
        if ($template === null) {
            $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
            $draft = \InterfaceDB::fetchOne(
                'SELECT id FROM ct_filing_mapping_profiles
                 WHERE target_type = :target AND ' . $column . ' = :package_id AND status = :draft
                 ORDER BY id DESC LIMIT 1',
                ['target' => $targetType, 'package_id' => $packageId, 'draft' => 'draft']
            );
            return is_array($draft) ? (int)$draft['id'] : $this->cloneDraft($targetType, $packageId, $actor);
        }
        $active = $this->activeProfile($targetType, $packageId);
        if (is_array($active) && (string)$active['profile_name'] === (string)$template['profile_name']) {
            return (int)$active['id'];
        }
        $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $profile = \InterfaceDB::fetchOne(
            'SELECT * FROM ct_filing_mapping_profiles
             WHERE target_type = :target AND ' . $column . ' = :package_id
               AND profile_name = :name AND status IN (:draft, :validated)
             ORDER BY revision_no DESC, id DESC LIMIT 1',
            [
                'target' => $targetType,
                'package_id' => $packageId,
                'name' => (string)$template['profile_name'],
                'draft' => 'draft',
                'validated' => 'validated',
            ]
        );
        if (!is_array($profile)) {
            $revision = (int)(\InterfaceDB::fetchColumn(
                'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM ct_filing_mapping_profiles
                 WHERE target_type = :target AND profile_name = :name',
                ['target' => $targetType, 'name' => (string)$template['profile_name']]
            ) ?: 1);
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct_filing_mapping_profiles
                 (target_type, rim_package_id, computation_package_id, profile_name, revision_no, status,
                  content_hash, compatibility_status, created_by)
                 VALUES (:target, :rim_id, :computation_id, :name, :revision, :status, :hash, :compatibility, :actor)',
                [
                    'target' => $targetType,
                    'rim_id' => $targetType === self::TARGET_RIM ? $packageId : null,
                    'computation_id' => $targetType === self::TARGET_COMPUTATION ? $packageId : null,
                    'name' => (string)$template['profile_name'],
                    'revision' => $revision,
                    'status' => 'draft',
                    'hash' => hash('sha256', 'empty'),
                    'compatibility' => 'pending',
                    'actor' => $actor,
                ]
            );
            $profileId = $this->lastInsertId();
            $this->audit($profileId, 'created', $actor, ['reviewed_natural_identity' => $template['natural_identity']]);
            $profile = $this->profile($profileId);
        }
        $profileId = (int)$profile['id'];
        if ((string)$profile['status'] === 'draft') {
            $sourceRows = \InterfaceDB::fetchAll(
                'SELECT canonical_key, value_type, source_section, is_required
                 FROM ct_filing_canonical_sources WHERE target_scope IN (:both, :target)',
                ['both' => 'both', 'target' => $targetType]
            );
            $sources = [];
            foreach ($sourceRows as $source) { $sources[(string)$source['canonical_key']] = $source; }
            foreach ((array)$template['mappings'] as $index => $mapping) {
                $canonicalKey = (string)$mapping['canonical_key'];
                $source = $sources[$canonicalKey] ?? null;
                if (!is_array($source)) {
                    throw new \RuntimeException('Reviewed mapping source is absent from the canonical inventory: ' . $canonicalKey . '.');
                }
                $input = [
                    'canonical_key' => $canonicalKey,
                    'value_type' => (string)$source['value_type'],
                    'null_policy' => !empty($source['is_required']) ? 'error' : 'omit',
                    'is_required' => !empty($source['is_required']) ? 1 : 0,
                    'sign_multiplier' => 1,
                    'sort_order' => ($index + 1) * 10,
                ];
                if ($targetType === self::TARGET_RIM) {
                    $input['target_xpath'] = (string)$mapping['target_xpath'];
                } else {
                    $concept = $this->reviewedConcept($packageId, (string)$mapping['local_name']);
                    $input += [
                        'taxonomy_concept' => (string)$concept['qname'],
                        'period_type' => (string)($mapping['period_type'] ?? $concept['period_type'] ?? 'duration'),
                        'context_profile' => (string)($mapping['context_profile'] ?? self::CONTEXT_HMRC_CT_COMPANY),
                        'unit_ref' => (string)$source['value_type'] === 'numeric' ? 'GBP' : null,
                        'decimals_value' => (string)$source['value_type'] === 'numeric' ? '2' : null,
                        'presentation_section' => (string)$source['source_section'],
                        'presentation_label' => (string)$source['canonical_key'],
                    ];
                }
                $this->saveMapping($targetType, $profileId, $input, $actor);
            }
            $validation = $this->validateProfile($profileId, $actor);
            if (empty($validation['success'])) {
                throw new \RuntimeException('The reviewed mapping template is incompatible: ' . implode(' ', (array)$validation['errors']));
            }
        }
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] === 'validated') {
            $this->activateProfile($profileId, $actor);
        }
        return $profileId;
    }

    /**
     * Resolves a mapping profile only against a verified frozen CT-period model.
     * No tax calculation or ledger service is reachable through this operation.
     */
    public function mapFrozenFacts(
        string $targetType,
        array $filingModel,
        array $profile,
        ?array $knownMappings = null
    ): array
    {
        $this->assertTarget($targetType);
        if (empty($filingModel['available'])
            || trim((string)($filingModel['basis_version'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/i', (string)($filingModel['basis_hash'] ?? '')) !== 1
            || (int)(($filingModel['run'] ?? [])['run_id'] ?? 0) <= 0
            || (int)(($filingModel['model'] ?? [])['ct_period']['id'] ?? 0) <= 0
            || !is_array($filingModel['seal'] ?? null)
            || $filingModel['seal'] === []) {
            return $this->mappingFailure('A verified, sealed CT-period filing model is required.');
        }
        if ((string)($profile['target_type'] ?? '') !== $targetType
            || (string)($profile['status'] ?? '') !== 'active'
            || (string)($profile['compatibility_status'] ?? '') !== 'compatible'
            || (int)($profile['id'] ?? 0) <= 0) {
            return $this->mappingFailure('An active compatible mapping profile for the requested target is required.');
        }

        if ($knownMappings !== null) {
            $mappings = $knownMappings;
        } elseif ($targetType === self::TARGET_RIM) {
            $mappings = \InterfaceDB::fetchAll(
                'SELECT m.*, i.data_type AS rim_data_type
                 FROM ct600_rim_mappings m
                 LEFT JOIN hmrc_ct_rim_components i
                   ON i.package_id = :package_id AND i.component_path = m.target_xpath
                 WHERE m.profile_id = :profile_id
                 ORDER BY m.sort_order, m.id',
                [
                    'package_id' => (int)($profile['rim_package_id'] ?? 0),
                    'profile_id' => (int)$profile['id'],
                ]
            );
        } else {
            $mappings = \InterfaceDB::fetchAll(
                'SELECT * FROM ct_computation_ixbrl_mappings
                 WHERE profile_id = :profile_id ORDER BY sort_order, id',
                ['profile_id' => (int)$profile['id']]
            );
        }
        if ($mappings === []) {
            return $this->mappingFailure('The active mapping profile contains no mappings.');
        }

        $facts = (array)($filingModel['facts'] ?? []);
        $lossesUsed = $facts['computation.summary.losses_used'] ?? null;
        if ($targetType === self::TARGET_RIM
            && (is_int($lossesUsed) || is_float($lossesUsed))
            && (float)$lossesUsed > 0.0) {
            $decisionAmount = $facts['filing_decisions.trading_losses_brought_forward_used'] ?? null;
            $hasBox160Mapping = false;
            foreach ($mappings as $mapping) {
                if ((string)($mapping['canonical_key'] ?? '') === 'filing_decisions.trading_losses_brought_forward_used'
                    && (string)($mapping['target_xpath'] ?? '') === 'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward') {
                    $hasBox160Mapping = true;
                    break;
                }
            }
            $approvedSameTradeUse = (string)($facts['filing_decisions.loss_relief_treatment'] ?? '')
                    === 'trading_brought_forward_against_same_trade_profit'
                && (is_int($decisionAmount) || is_float($decisionAmount))
                && is_finite((float)$decisionAmount)
                && abs((float)$decisionAmount - (float)$lossesUsed) < 0.005
                && $hasBox160Mapping;
            if (!$approvedSameTradeUse) {
                return [
                    'success' => false,
                    'errors' => [
                        'A positive aggregate losses-used value requires an explicit CT600 claim decision: '
                        . 'box 160 (trading losses brought forward), box 275 (TradingLosses), and box 285 '
                        . '(TradingLossesCarriedForward) cannot be inferred safely.',
                    ],
                    'basis_version' => (string)$filingModel['basis_version'],
                    'basis_hash' => (string)$filingModel['basis_hash'],
                    'computation_run_id' => (int)$filingModel['run']['run_id'],
                    'ct_period_id' => (int)($filingModel['model']['ct_period']['id'] ?? 0),
                    'canonical_values' => [],
                    'mappings' => [],
                    'blocked_claim_targets' => [
                        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/Income/Trading/LossesBroughtForward',
                        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/DeductionsAndReliefs/TradingLosses',
                        'IRenvelope/CompanyTaxReturn/CompanyTaxCalculation/DeductionsAndReliefs/TradingLossesCarriedForward',
                    ],
                ];
            }
        }
        $resolved = [];
        $canonicalValues = [];
        $errors = [];
        foreach ($mappings as $mapping) {
            if (!is_array($mapping) || (int)($mapping['profile_id'] ?? 0) !== (int)$profile['id']) {
                $errors[] = 'A mapping row does not belong to the selected active profile.';
                continue;
            }
            $key = trim((string)($mapping['canonical_key'] ?? ''));
            $exists = $key !== '' && array_key_exists($key, $facts);
            $value = $exists ? $facts[$key] : null;
            $missing = !$exists || $value === null || $value === '';
            $required = !empty($mapping['is_required']) || (string)($mapping['null_policy'] ?? '') === 'error';
            if ($missing && $required) {
                $errors[] = 'Required canonical filing fact is missing: ' . ($key !== '' ? $key : '(blank key)') . '.';
                continue;
            }
            if ($missing && (string)($mapping['null_policy'] ?? 'omit') === 'omit') {
                continue;
            }
            if (!$missing && !$this->valueMatchesType($value, (string)($mapping['value_type'] ?? ''))) {
                $errors[] = 'Canonical filing fact has the wrong type: ' . $key . '.';
                continue;
            }
            $mapping['source_value'] = $value;
            if (!$missing && $targetType === self::TARGET_RIM && (string)($mapping['value_type'] ?? '') === 'numeric') {
                $rimDataType = trim((string)($mapping['rim_data_type'] ?? ''));
                if ($rimDataType === '') {
                    $errors[] = 'The RIM datatype is unresolved for numeric CT600 target: '
                        . (string)($mapping['target_xpath'] ?? '(blank target)') . '.';
                    continue;
                }
                if ($this->isRimMonetaryType($rimDataType)) {
                    try {
                        $mappedValue = (float)$value * (float)($mapping['sign_multiplier'] ?? 1);
                        $mapping['serialized_value'] = (new Ct600MonetaryValuePolicyService())->serialize(
                            $mappedValue,
                            $rimDataType,
                            (string)($mapping['target_xpath'] ?? '')
                        );
                        $mapping['policy_version'] = Ct600MonetaryValuePolicyService::POLICY_VERSION;
                    } catch (\Throwable $exception) {
                        $errors[] = 'CT600 monetary value could not be serialized for ' . $key . ': '
                            . $exception->getMessage();
                        continue;
                    }
                }
            }
            $resolved[] = $mapping;
            $canonicalValues[$key] = $value;
        }
        if ($errors !== []) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'basis_version' => (string)$filingModel['basis_version'],
                'basis_hash' => (string)$filingModel['basis_hash'],
                'computation_run_id' => (int)$filingModel['run']['run_id'],
                'ct_period_id' => (int)($filingModel['model']['ct_period']['id'] ?? 0),
                'canonical_values' => $canonicalValues,
                'mappings' => [],
            ];
        }

        return [
            'success' => true,
            'errors' => [],
            'target_type' => $targetType,
            'profile_id' => (int)$profile['id'],
            'basis_version' => (string)$filingModel['basis_version'],
            'basis_hash' => (string)$filingModel['basis_hash'],
            'computation_run_id' => (int)$filingModel['run']['run_id'],
            'ct_period_id' => (int)($filingModel['model']['ct_period']['id'] ?? 0),
            'canonical_values' => $canonicalValues,
            'monetary_policy_version' => $targetType === self::TARGET_RIM
                ? Ct600MonetaryValuePolicyService::POLICY_VERSION
                : null,
            'mappings' => $resolved,
        ];
    }

    public function cloneDraft(string $targetType, int $packageId, string $actor): int
    {
        $this->assertTarget($targetType);
        $column = $targetType === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $source = \InterfaceDB::fetchOne(
            'SELECT * FROM ct_filing_mapping_profiles WHERE target_type = :target_type
             ORDER BY (status = :active) DESC, revision_no DESC, id DESC LIMIT 1',
            ['target_type' => $targetType, 'active' => 'active']
        );
        $revision = (int)(\InterfaceDB::fetchColumn(
            'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM ct_filing_mapping_profiles WHERE target_type = :target_type AND ' . $column . ' = :package_id',
            ['target_type' => $targetType, 'package_id' => $packageId]
        ) ?: 1);
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_filing_mapping_profiles
             (target_type, rim_package_id, computation_package_id, profile_name, revision_no, status, parent_profile_id,
              content_hash, compatibility_status, compatibility_json, created_by)
             VALUES (:target_type, :rim_package_id, :computation_package_id, :profile_name, :revision_no, :status, :parent_profile_id,
                     :content_hash, :compatibility_status, :compatibility_json, :created_by)',
            [
                'target_type' => $targetType,
                'rim_package_id' => $targetType === self::TARGET_RIM ? $packageId : null,
                'computation_package_id' => $targetType === self::TARGET_COMPUTATION ? $packageId : null,
                'profile_name' => $targetType . '_package_' . $packageId,
                'revision_no' => $revision,
                'status' => 'draft',
                'parent_profile_id' => is_array($source) ? (int)$source['id'] : null,
                'content_hash' => hash('sha256', 'empty'),
                'compatibility_status' => 'pending',
                'compatibility_json' => null,
                'created_by' => $actor,
            ]
        );
        $profileId = $this->lastInsertId();
        if (is_array($source)) {
            $this->copyMappings($targetType, (int)$source['id'], $profileId, $packageId);
        }
        $this->refreshContentHash($profileId);
        $this->audit($profileId, 'created', $actor, ['parent_profile_id' => is_array($source) ? (int)$source['id'] : null]);
        if (is_array($source)) {
            $this->audit($profileId, 'cloned', $actor, ['source_profile_id' => (int)$source['id'], 'unchanged_targets_only' => true]);
        }
        return $profileId;
    }

    public function validateProfile(int $profileId, string $actor): array
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'draft') {
            throw new \RuntimeException('Only draft mapping profiles can be validated.');
        }
        $target = (string)$profile['target_type'];
        $mappingTable = $target === self::TARGET_RIM ? 'ct600_rim_mappings' : 'ct_computation_ixbrl_mappings';
        $mappings = \InterfaceDB::fetchAll('SELECT * FROM ' . $mappingTable . ' WHERE profile_id = :profile_id ORDER BY id', ['profile_id' => $profileId]);
        $errors = [];
        if ($mappings === []) {
            $errors[] = 'The profile contains no mappings.';
        }
        $seen = [];
        foreach ($mappings as $mapping) {
            $source = trim((string)($mapping['canonical_key'] ?? ''));
            if ($source === '' || ($target !== self::TARGET_RIM && isset($seen[$source]))) {
                $errors[] = $source === '' ? 'A mapping has no canonical source.' : 'Canonical source is mapped more than once: ' . $source;
            }
            $seen[$source] = true;
        }
        $inventory = $this->compatibility($profile, $mappings);
        $errors = array_values(array_unique(array_merge($errors, (array)$inventory['errors'])));
        $result = $errors === [] ? 'compatible' : 'incompatible';
        \InterfaceDB::prepareExecute(
            'UPDATE ct_filing_mapping_profiles SET status = :status, compatibility_status = :result,
             compatibility_json = :json, validated_by = :actor, validated_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => $errors === [] ? 'validated' : 'draft', 'result' => $result, 'json' => json_encode($inventory, JSON_UNESCAPED_SLASHES), 'actor' => $actor, 'id' => $profileId]
        );
        $this->refreshContentHash($profileId);
        $this->audit($profileId, 'validated', $actor, $inventory);
        return ['success' => $errors === [], 'errors' => $errors, 'compatibility' => $inventory];
    }

    public function saveMapping(string $targetType, int $profileId, array $input, string $actor): void
    {
        $this->assertTarget($targetType);
        $profile = $this->profile($profileId);
        if ((string)$profile['target_type'] !== $targetType || (string)$profile['status'] !== 'draft') {
            throw new \RuntimeException('Mappings can only be changed on a matching draft profile.');
        }
        $canonicalKey = trim((string)($input['canonical_key'] ?? ''));
        $valueType = trim((string)($input['value_type'] ?? ''));
        if ($canonicalKey === '' || !in_array($valueType, ['numeric', 'text', 'date', 'boolean', 'integer'], true)) {
            throw new \InvalidArgumentException('Select a canonical source and value type.');
        }
        if ($targetType === self::TARGET_RIM) {
            $target = trim((string)($input['target_xpath'] ?? ''));
            if ($target === '') { throw new \InvalidArgumentException('A catalogued RIM component path is required.'); }
            $exists = (int)\InterfaceDB::fetchColumn('SELECT COUNT(*) FROM hmrc_ct_rim_components WHERE package_id = :package_id AND component_path = :target', ['package_id' => (int)$profile['rim_package_id'], 'target' => $target]);
            if ($exists !== 1) { throw new \RuntimeException('The RIM target does not exist in this package inventory.'); }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct600_rim_mappings (profile_id, canonical_key, target_xpath, value_type, sign_multiplier, null_policy, is_required, sort_order)
                 VALUES (:profile_id, :canonical_key, :target, :value_type, :sign_multiplier, :null_policy, :is_required, :sort_order)
                 ON DUPLICATE KEY UPDATE target_xpath = VALUES(target_xpath), value_type = VALUES(value_type), sign_multiplier = VALUES(sign_multiplier), null_policy = VALUES(null_policy), is_required = VALUES(is_required), sort_order = VALUES(sort_order)',
                ['profile_id' => $profileId, 'canonical_key' => $canonicalKey, 'target' => $target, 'value_type' => $valueType, 'sign_multiplier' => (float)($input['sign_multiplier'] ?? 1), 'null_policy' => $this->nullPolicy($input['null_policy'] ?? 'omit'), 'is_required' => !empty($input['is_required']) ? 1 : 0, 'sort_order' => (int)($input['sort_order'] ?? 100)]
            );
        } else {
            $concept = trim((string)($input['taxonomy_concept'] ?? ''));
            $catalogue = \InterfaceDB::fetchOne('SELECT * FROM hmrc_ct_computation_concepts WHERE package_id = :package_id AND qname = :concept LIMIT 1', ['package_id' => (int)$profile['computation_package_id'], 'concept' => $concept]);
            if (!is_array($catalogue)) { throw new \RuntimeException('The taxonomy concept does not exist in this package inventory.'); }
            $dimensions = trim((string)($input['dimensions_json'] ?? ''));
            if ($dimensions !== '' && !is_array(json_decode($dimensions, true))) { throw new \InvalidArgumentException('Dimensions must be a JSON object.'); }
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct_computation_ixbrl_mappings
                 (profile_id, canonical_key, taxonomy_concept, namespace_uri, local_name, value_type, period_type, context_profile,
                  unit_ref, decimals_value, dimensions_json, sign_multiplier, presentation_section, presentation_label, null_policy, is_required, sort_order)
                 VALUES (:profile_id, :canonical_key, :concept, :namespace_uri, :local_name, :value_type, :period_type, :context_profile,
                         :unit_ref, :decimals_value, :dimensions_json, :sign_multiplier, :section, :label, :null_policy, :is_required, :sort_order)
                 ON DUPLICATE KEY UPDATE taxonomy_concept = VALUES(taxonomy_concept), namespace_uri = VALUES(namespace_uri), local_name = VALUES(local_name), value_type = VALUES(value_type), period_type = VALUES(period_type), context_profile = VALUES(context_profile), unit_ref = VALUES(unit_ref), decimals_value = VALUES(decimals_value), dimensions_json = VALUES(dimensions_json), sign_multiplier = VALUES(sign_multiplier), presentation_section = VALUES(presentation_section), presentation_label = VALUES(presentation_label), null_policy = VALUES(null_policy), is_required = VALUES(is_required), sort_order = VALUES(sort_order)',
                ['profile_id' => $profileId, 'canonical_key' => $canonicalKey, 'concept' => $concept, 'namespace_uri' => (string)$catalogue['namespace_uri'], 'local_name' => (string)$catalogue['local_name'], 'value_type' => $valueType, 'period_type' => in_array((string)($input['period_type'] ?? ''), ['instant', 'duration'], true) ? (string)$input['period_type'] : ((string)($catalogue['period_type'] ?? '') ?: 'duration'), 'context_profile' => trim((string)($input['context_profile'] ?? 'ct_period')) ?: 'ct_period', 'unit_ref' => trim((string)($input['unit_ref'] ?? '')) ?: null, 'decimals_value' => trim((string)($input['decimals_value'] ?? '')) ?: null, 'dimensions_json' => $dimensions !== '' ? $dimensions : null, 'sign_multiplier' => (float)($input['sign_multiplier'] ?? 1), 'section' => trim((string)($input['presentation_section'] ?? '')) ?: 'tax_liability', 'label' => trim((string)($input['presentation_label'] ?? '')) ?: $canonicalKey, 'null_policy' => $this->nullPolicy($input['null_policy'] ?? 'omit'), 'is_required' => !empty($input['is_required']) ? 1 : 0, 'sort_order' => (int)($input['sort_order'] ?? 100)]
            );
        }
        $this->refreshContentHash($profileId);
        \InterfaceDB::prepareExecute('UPDATE ct_filing_mapping_profiles SET compatibility_status = :status, compatibility_json = NULL WHERE id = :id', ['status' => 'pending', 'id' => $profileId]);
        $this->audit($profileId, 'mapping_changed', $actor, ['canonical_key' => $canonicalKey]);
    }

    public function activateProfile(int $profileId, string $actor): void
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'validated' || (string)$profile['compatibility_status'] !== 'compatible') {
            throw new \RuntimeException('Only a validated, compatible mapping profile can be activated.');
        }
        $column = (string)$profile['target_type'] === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        \InterfaceDB::beginTransaction();
        try {
            \InterfaceDB::prepareExecute(
                'UPDATE ct_filing_mapping_profiles SET status = :retired, retired_by = :actor, retired_at = CURRENT_TIMESTAMP
                 WHERE target_type = :target_type AND ' . $column . ' = :package_id AND status = :active AND id <> :id',
                ['retired' => 'retired', 'actor' => $actor, 'target_type' => $profile['target_type'], 'package_id' => $profile[$column], 'active' => 'active', 'id' => $profileId]
            );
            \InterfaceDB::prepareExecute(
                'UPDATE ct_filing_mapping_profiles SET status = :active, activated_by = :actor, activated_at = CURRENT_TIMESTAMP WHERE id = :id',
                ['active' => 'active', 'actor' => $actor, 'id' => $profileId]
            );
            $this->audit($profileId, 'activated', $actor, []);
            \InterfaceDB::commit();
        } catch (\Throwable $exception) {
            \InterfaceDB::rollBack();
            throw $exception;
        }
    }

    public function retireProfile(int $profileId, string $actor): void
    {
        $profile = $this->profile($profileId);
        if ((string)$profile['status'] !== 'active') {
            throw new \RuntimeException('Only an active mapping profile can be retired.');
        }
        \InterfaceDB::prepareExecute(
            'UPDATE ct_filing_mapping_profiles SET status = :status, retired_by = :actor, retired_at = CURRENT_TIMESTAMP WHERE id = :id',
            ['status' => 'retired', 'actor' => $actor, 'id' => $profileId]
        );
        if ((string)$profile['target_type'] === self::TARGET_COMPUTATION) {
            \InterfaceDB::prepareExecute(
                'UPDATE corporation_tax_computation_runs SET ixbrl_status = :status WHERE ixbrl_mapping_profile_id = :profile_id',
                ['status' => 'stale', 'profile_id' => $profileId]
            );
        }
        $this->audit($profileId, 'retired', $actor, []);
    }

    private function compatibility(array $profile, array $mappings): array
    {
        $target = (string)$profile['target_type'];
        $inventoryTable = $target === self::TARGET_RIM ? 'hmrc_ct_rim_components' : 'hmrc_ct_computation_concepts';
        $packageColumn = $target === self::TARGET_RIM ? 'rim_package_id' : 'computation_package_id';
        $packageId = (int)$profile[$packageColumn];
        $inventory = \InterfaceDB::fetchAll('SELECT * FROM ' . $inventoryTable . ' WHERE package_id = :package_id', ['package_id' => $packageId]);
        $targets = [];
        foreach ($mappings as $mapping) {
            $targets[] = $target === self::TARGET_RIM ? (string)$mapping['target_xpath'] : (string)$mapping['taxonomy_concept'];
        }
        $available = [];
        $required = [];
        foreach ($inventory as $item) {
            $key = $target === self::TARGET_RIM ? (string)$item['component_path'] : (string)$item['qname'];
            $available[$key] = $item;
            $isLeafTarget = $target !== self::TARGET_RIM
                || !array_key_exists('is_leaf', $item)
                || !empty($item['is_leaf']);
            if (!empty($item['is_required']) && $isLeafTarget) {
                $required[$key] = true;
            }
        }
        $missingTargets = array_values(array_filter(array_unique($targets), static fn(string $item): bool => !isset($available[$item])));
        $unmappedRequired = array_values(array_filter(array_keys($required), static fn(string $item): bool => !in_array($item, $targets, true)));
        $nonLeafTargets = [];
        $unresolvedNumericTargets = [];
        $abstractConceptTargets = [];
        foreach ($mappings as $mapping) {
            $key = $target === self::TARGET_RIM ? (string)$mapping['target_xpath'] : (string)$mapping['taxonomy_concept'];
            $item = $available[$key] ?? null;
            if (!is_array($item)) { continue; }
            if ($target === self::TARGET_RIM) {
                if (array_key_exists('is_leaf', $item) && empty($item['is_leaf'])) {
                    $nonLeafTargets[] = $key;
                }
                if (in_array((string)($mapping['value_type'] ?? ''), ['numeric', 'integer'], true)
                    && trim((string)($item['data_type'] ?? '')) === '') {
                    $unresolvedNumericTargets[] = $key;
                }
            } elseif (!empty($item['is_abstract']) || !empty($item['is_dimension'])) {
                $abstractConceptTargets[] = $key;
            }
        }
        $errors = [];
        if ($inventory === []) {
            $errors[] = 'The selected package has no catalogued schema or taxonomy inventory.';
        }
        if ($missingTargets !== []) {
            $errors[] = count($missingTargets) . ' mapping target(s) do not exist in the selected package.';
        }
        if ($nonLeafTargets !== []) {
            $errors[] = count(array_unique($nonLeafTargets)) . ' RIM mapping target(s) are structural wrappers rather than leaf values.';
        }
        if ($unresolvedNumericTargets !== []) {
            $errors[] = count(array_unique($unresolvedNumericTargets)) . ' numeric RIM mapping target(s) have no resolved datatype.';
        }
        if ($abstractConceptTargets !== []) {
            $errors[] = count(array_unique($abstractConceptTargets)) . ' computation mapping target(s) are abstract or dimensional concepts.';
        }
        $sourceRows = \InterfaceDB::tableExists('ct_filing_canonical_sources') ? \InterfaceDB::fetchAll(
            'SELECT canonical_key, value_type, source_section, is_required
             FROM ct_filing_canonical_sources WHERE target_scope IN (:both, :target)',
            ['both' => 'both', 'target' => $target]
        ) : [];
        $mappedSources = array_map(static fn(array $mapping): string => (string)$mapping['canonical_key'], $mappings);
        $sourceInventory = [];
        foreach ($sourceRows as $sourceRow) {
            $sourceInventory[(string)$sourceRow['canonical_key']] = $sourceRow;
        }
        $unmappedSources = array_values(array_filter(
            array_map(static fn(array $row): string => (string)$row['canonical_key'], array_filter($sourceRows, static fn(array $row): bool => !empty($row['is_required']))),
            static fn(string $key): bool => !in_array($key, $mappedSources, true)
        ));
        if ($unmappedSources !== []) { $errors[] = count($unmappedSources) . ' required canonical source(s) are unmapped.'; }
        $invalidSources = [];
        $invalidRequiredPolicies = [];
        $invalidTypes = [];
        $invalidSections = [];
        foreach ($mappings as $mapping) {
            $key = (string)$mapping['canonical_key'];
            $source = $sourceInventory[$key] ?? null;
            if (!is_array($source)) {
                $invalidSources[] = $key;
                continue;
            }
            if ((string)$mapping['value_type'] !== (string)$source['value_type']) {
                $invalidTypes[] = $key;
            }
            if (!empty($source['is_required'])
                && empty($mapping['is_required'])
                && (string)($mapping['null_policy'] ?? '') !== 'error') {
                $invalidRequiredPolicies[] = $key;
            }
            if ($target === self::TARGET_COMPUTATION
                && (string)($mapping['presentation_section'] ?? '') !== (string)$source['source_section']) {
                $invalidSections[] = $key;
            }
        }
        if ($invalidSources !== []) { $errors[] = count(array_unique($invalidSources)) . ' mapping source(s) are not in the canonical filing inventory.'; }
        if ($invalidRequiredPolicies !== []) { $errors[] = count(array_unique($invalidRequiredPolicies)) . ' required canonical source mapping(s) do not fail closed when absent.'; }
        if ($invalidTypes !== []) { $errors[] = count(array_unique($invalidTypes)) . ' mapping value type(s) disagree with the canonical filing inventory.'; }
        if ($invalidSections !== []) { $errors[] = count(array_unique($invalidSections)) . ' computation mapping section(s) disagree with the canonical report model.'; }
        return [
            'errors' => $errors,
            'missing_targets' => $missingTargets,
            'non_leaf_targets' => array_values(array_unique($nonLeafTargets)),
            'unresolved_numeric_targets' => array_values(array_unique($unresolvedNumericTargets)),
            'abstract_or_dimension_targets' => array_values(array_unique($abstractConceptTargets)),
            'unmapped_required_targets' => $unmappedRequired,
            'unmapped_canonical_sources' => $unmappedSources,
            'invalid_canonical_sources' => array_values(array_unique($invalidSources)),
            'invalid_required_policies' => array_values(array_unique($invalidRequiredPolicies)),
            'invalid_value_types' => array_values(array_unique($invalidTypes)),
            'invalid_presentation_sections' => array_values(array_unique($invalidSections)),
            'mapping_count' => count($mappings),
            'inventory_count' => count($inventory),
        ];
    }

    private function copyMappings(string $target, int $sourceId, int $profileId, int $packageId): void
    {
        if ($target === self::TARGET_RIM) {
            \InterfaceDB::prepareExecute(
                'INSERT INTO ct600_rim_mappings (profile_id, canonical_key, target_xpath, value_type, sign_multiplier, null_policy, is_required, sort_order)
                 SELECT :profile_id, m.canonical_key, m.target_xpath, m.value_type, m.sign_multiplier, m.null_policy, m.is_required, m.sort_order
                 FROM ct600_rim_mappings m INNER JOIN hmrc_ct_rim_components i
                   ON i.package_id = :package_id AND i.component_path = m.target_xpath WHERE m.profile_id = :source_id',
                ['profile_id' => $profileId, 'package_id' => $packageId, 'source_id' => $sourceId]
            );
            return;
        }
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_computation_ixbrl_mappings
             (profile_id, canonical_key, taxonomy_concept, namespace_uri, local_name, value_type, period_type, context_profile,
              unit_ref, decimals_value, dimensions_json, sign_multiplier, presentation_section, presentation_label, null_policy, is_required, sort_order)
             SELECT :profile_id, m.canonical_key, m.taxonomy_concept, m.namespace_uri, m.local_name, m.value_type, m.period_type, m.context_profile,
                    m.unit_ref, m.decimals_value, m.dimensions_json, m.sign_multiplier, m.presentation_section, m.presentation_label, m.null_policy, m.is_required, m.sort_order
             FROM ct_computation_ixbrl_mappings m INNER JOIN hmrc_ct_computation_concepts i
               ON i.package_id = :package_id AND i.qname = m.taxonomy_concept WHERE m.profile_id = :source_id',
            ['profile_id' => $profileId, 'package_id' => $packageId, 'source_id' => $sourceId]
        );
    }

    private function reviewedConcept(int $packageId, string $localName): array
    {
        $rows = \InterfaceDB::fetchAll(
            'SELECT * FROM hmrc_ct_computation_concepts
             WHERE package_id = :package_id AND local_name = :local_name AND is_abstract = 0
             ORDER BY namespace_uri, qname',
            ['package_id' => $packageId, 'local_name' => $localName]
        );
        $computational = array_values(array_filter(
            $rows,
            static fn(array $row): bool => str_contains(strtolower((string)$row['namespace_uri']), '/ct/comp/')
        ));
        if (count($computational) === 1) { return $computational[0]; }
        if (count($rows) === 1) { return $rows[0]; }
        throw new \RuntimeException(
            count($rows) === 0
                ? 'The reviewed CT computation concept is absent: ' . $localName . '.'
                : 'The reviewed CT computation concept is ambiguous: ' . $localName . '.'
        );
    }

    private function refreshContentHash(int $profileId): void
    {
        $profile = $this->profile($profileId);
        $table = (string)$profile['target_type'] === self::TARGET_RIM ? 'ct600_rim_mappings' : 'ct_computation_ixbrl_mappings';
        $rows = \InterfaceDB::fetchAll('SELECT * FROM ' . $table . ' WHERE profile_id = :profile_id ORDER BY canonical_key, id', ['profile_id' => $profileId]);
        foreach ($rows as &$row) {
            unset($row['id'], $row['created_at'], $row['updated_at']);
        }
        unset($row);
        \InterfaceDB::prepareExecute('UPDATE ct_filing_mapping_profiles SET content_hash = :hash WHERE id = :id', ['hash' => hash('sha256', (string)json_encode($rows, JSON_UNESCAPED_SLASHES)), 'id' => $profileId]);
    }

    private function lastInsertId(): int
    {
        $sql = strtolower(\InterfaceDB::driverName()) === 'sqlite'
            ? 'SELECT last_insert_rowid()'
            : 'SELECT LAST_INSERT_ID()';
        return (int)(\InterfaceDB::fetchColumn($sql) ?: 0);
    }

    private function profile(int $profileId): array
    {
        $profile = \InterfaceDB::fetchOne('SELECT * FROM ct_filing_mapping_profiles WHERE id = :id LIMIT 1', ['id' => $profileId]);
        if (!is_array($profile)) {
            throw new \RuntimeException('The mapping profile was not found.');
        }
        return $profile;
    }

    private function audit(int $profileId, string $event, string $actor, array $details): void
    {
        \InterfaceDB::prepareExecute(
            'INSERT INTO ct_filing_mapping_events (profile_id, event_type, actor, detail_json) VALUES (:profile_id, :event_type, :actor, :details)',
            ['profile_id' => $profileId, 'event_type' => $event, 'actor' => $actor, 'details' => json_encode($details, JSON_UNESCAPED_SLASHES)]
        );
    }

    private function assertTarget(string $targetType): void
    {
        if (!in_array($targetType, [self::TARGET_RIM, self::TARGET_COMPUTATION], true)) {
            throw new \InvalidArgumentException('Unsupported CT filing mapping target.');
        }
    }

    private function nullPolicy(mixed $value): string
    {
        $policy = trim((string)$value);
        return in_array($policy, ['omit', 'nil', 'error'], true) ? $policy : 'omit';
    }

    private function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'numeric' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'text' => is_string($value),
            default => false,
        };
    }

    private function isRimMonetaryType(string $type): bool
    {
        $type = strtolower($type);
        return str_contains($type, 'monetary')
            || str_contains($type, 'wholepound')
            || str_contains($type, 'poundpence');
    }

    private function mappingFailure(string $message): array
    {
        return [
            'success' => false,
            'errors' => [$message],
            'basis_version' => '',
            'basis_hash' => '',
            'computation_run_id' => 0,
            'ct_period_id' => 0,
            'canonical_values' => [],
            'mappings' => [],
        ];
    }
}
