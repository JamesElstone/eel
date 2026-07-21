<?php
/** EEL Accounts - AGPLv3 */
declare(strict_types=1);

namespace eel_accounts\Service;

/**
 * Adapts one verified, immutable CT-period filing basis to the deliberately
 * narrow CT600 MVP profile. It never reads the ledger or recalculates tax.
 */
final class Ct600ReturnModelService
{
    public const MODEL_VERSION = 'ct600-return-model-v3';

    private ?\Closure $filingModelLoader;
    private ?\Closure $rimResolver;
    private ?\Closure $profileResolver;
    private ?\Closure $factMapper;

    /**
     * @param null|callable(int,int,int):array $filingModelLoader
     * @param null|callable(string,string):array $rimResolver
     * @param null|callable(int):?array $profileResolver
     * @param null|callable(array,array):array $factMapper
     */
    public function __construct(
        ?callable $filingModelLoader = null,
        ?callable $rimResolver = null,
        ?callable $profileResolver = null,
        ?callable $factMapper = null
    ) {
        $this->filingModelLoader = $filingModelLoader !== null ? \Closure::fromCallable($filingModelLoader) : null;
        $this->rimResolver = $rimResolver !== null ? \Closure::fromCallable($rimResolver) : null;
        $this->profileResolver = $profileResolver !== null ? \Closure::fromCallable($profileResolver) : null;
        $this->factMapper = $factMapper !== null ? \Closure::fromCallable($factMapper) : null;
    }

    /** @return array<string,mixed> */
    public function build(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        if ($companyId <= 0 || $accountingPeriodId <= 0 || $ctPeriodId <= 0) {
            return $this->failure('Select a company, accounting period and CT period.');
        }

        try {
            $filing = $this->loadFilingModel($companyId, $accountingPeriodId, $ctPeriodId);
        } catch (\Throwable $exception) {
            return $this->failure('The approved CT-period filing basis could not be loaded.', [$exception->getMessage()]);
        }
        if (empty($filing['available'])) {
            return $this->failure(
                'A current approved CT-period filing basis is required.',
                (array)($filing['errors'] ?? [])
            );
        }

        $model = (array)($filing['model'] ?? []);
        $errors = $this->validateFrozenModel($model, $filing, $companyId, $accountingPeriodId, $ctPeriodId);
        $supplementary = $this->supplementaryPageReasons($model);
        if ($supplementary !== []) {
            foreach ($supplementary as $reason) {
                $errors[] = 'A supplementary CT600 page is required and is outside this MVP: ' . $reason;
            }
        }
        if ($errors !== []) {
            return $this->failure('The frozen return is outside the supported CT600 MVP profile.', $errors);
        }

        $ctPeriod = (array)$model['ct_period'];
        $rim = $this->resolveRim((string)$ctPeriod['start_date'], (string)$ctPeriod['end_date']);
        if (empty($rim['ok'])) {
            return $this->failure('No verified applicable CT600 RIM package is ready.', (array)($rim['errors'] ?? []));
        }
        $packageId = (int)($rim['package_id'] ?? $rim['id'] ?? 0);
        if ($packageId <= 0) {
            $packageId = $this->lookupPackageId($rim);
        }
        if ($packageId <= 0) {
            return $this->failure('The resolved CT600 RIM package has no stable database identity.');
        }

        $profile = $this->resolveProfile($packageId);
        if (!is_array($profile)) {
            return $this->failure('Activate a compatible CT600 mapping profile for the selected RIM package.');
        }

        $returnModel = $this->returnModel($model);
        $mappingInput = $filing;
        $mappingInput['facts'] = array_replace(
            (array)($filing['facts'] ?? []),
            $this->flatten(['ct600' => $returnModel]),
            [
                'return_position.ct600a_a80' => (float)($returnModel['ct600a']['tax_payable'] ?? 0),
                'return_position.tax_payable' => (float)$returnModel['amounts']['tax_payable'],
                // Historical active mapping profiles remain readable until
                // the reviewed return-v2 profile is prepared and activated.
                'computation.summary.s455_tax' => (float)($returnModel['ct600a']['tax_payable'] ?? 0),
                'computation.summary.estimated_corporation_tax' => (float)$returnModel['amounts']['tax_payable'],
            ]
        );
        $mapped = $this->mapFacts($mappingInput, $profile);
        if (empty($mapped['success'])) {
            return $this->failure('The frozen CT600 facts do not satisfy the active mapping profile.', (array)($mapped['errors'] ?? []));
        }

        $mappingErrors = $this->validateMappings((array)($mapped['mappings'] ?? []));
        if ($mappingErrors !== []) {
            return $this->failure('The active CT600 mapping profile is not deterministic.', $mappingErrors);
        }

        $sourceManifest = [
            'manifest_version' => 'ct600-source-manifest-v2',
            'return_model_version' => self::MODEL_VERSION,
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'filing_basis_version' => (string)$filing['basis_version'],
            'filing_basis_hash' => (string)$filing['basis_hash'],
            'filing_approval_id' => (int)($model['approval']['id'] ?? 0),
            'filing_approval_hash' => (string)($model['approval']['basis_hash'] ?? ''),
            'computation_run_id' => (int)($model['computation']['run_id'] ?? 0),
            'computation_hash' => (string)($model['computation']['hash'] ?? ''),
            'accounts_report_basis_version' => (string)($model['accounts_report']['basis_version'] ?? ''),
            'accounts_report_basis_hash' => (string)($model['accounts_report']['basis_hash'] ?? ''),
            'corporation_tax_filing_scope_hash' => (string)($model['corporation_tax_filing_scope']['basis_hash'] ?? ''),
            'ct600a_basis_hash' => (string)($model['ct600a']['basis_hash'] ?? ''),
            'ct600a_review_hash' => (string)($model['ct600a']['review']['basis_hash'] ?? ''),
            'rim_package_id' => $packageId,
            'rim_form_version' => (string)($rim['form_version'] ?? ''),
            'rim_artifact_version' => (string)($rim['artifact_version'] ?? ''),
            'rim_package_sha256' => (string)($rim['sha256'] ?? ''),
            'mapping_profile_id' => (int)$profile['id'],
            'mapping_revision_no' => (int)($profile['revision_no'] ?? 0),
            'mapping_content_hash' => (string)($profile['content_hash'] ?? ''),
            'monetary_policy_version' => (string)($mapped['monetary_policy_version'] ?? ''),
        ];
        $sourceManifestJson = $this->canonicalJson($sourceManifest);
        $returnModelJson = $this->canonicalJson($returnModel);

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => array_values(array_unique(array_merge(
                array_map('strval', (array)($rim['warnings'] ?? [])),
                array_map(static fn(mixed $item): string => is_array($item)
                    ? (string)($item['message'] ?? $item['detail'] ?? '')
                    : (string)$item, (array)($filing['warning_diagnostics'] ?? []))
            ))),
            'company_id' => $companyId,
            'accounting_period_id' => $accountingPeriodId,
            'ct_period_id' => $ctPeriodId,
            'filing_model' => $filing,
            'model_version' => self::MODEL_VERSION,
            'model' => $returnModel,
            'model_json' => $returnModelJson,
            'model_sha256' => hash('sha256', $returnModelJson),
            'rim' => $rim + ['package_id' => $packageId],
            'mapping_profile' => $profile,
            'mapping' => $mapped,
            'source_manifest' => $sourceManifest,
            'source_manifest_json' => $sourceManifestJson,
            'source_manifest_sha256' => hash('sha256', $sourceManifestJson),
        ];
    }

    /** @return array<string,mixed> */
    private function returnModel(array $model): array
    {
        $summary = (array)$model['computation']['summary'];
        $decisions = (array)$model['filing_decisions'];
        $taxableProfit = $this->number($summary, 'taxable_profit');
        $taxableLoss = $this->number($summary, 'taxable_loss');
        $ordinaryTax = $this->number($summary, 'ordinary_corporation_tax');
        $ct600a = (array)($model['ct600a'] ?? []);
        $ct600aTax = round((float)($ct600a['tax_payable'] ?? 0), 2);
        $taxPayable = round($ordinaryTax + $ct600aTax, 2);
        $taxBands = array_values((array)($decisions['tax_calculation_bands'] ?? []));
        $grossTax = round(array_sum(array_map(
            static fn(array $band): float => (float)($band['gross_tax'] ?? 0),
            $taxBands
        )), 2);
        $marginalRelief = round(array_sum(array_map(
            static fn(array $band): float => (float)($band['marginal_relief'] ?? 0),
            $taxBands
        )), 2);

        return [
            'identity' => [
                'company_name' => (string)$model['identity']['company_name'],
                'company_number' => (string)$model['identity']['company_number'],
                'utr' => (string)$model['filing_identity']['utr'],
                'company_type' => (int)$decisions['company_type'],
            ],
            'period' => [
                'start_date' => (string)$model['ct_period']['start_date'],
                'end_date' => (string)$model['ct_period']['end_date'],
            ],
            'return' => [
                'type' => (string)$decisions['return_type'],
                'this_period' => (bool)$decisions['this_period_return'],
                'multiple_returns' => (bool)$decisions['multiple_returns'],
            ],
            'attachments' => [
                'accounts' => (bool)$decisions['accounts_attached'],
                'accounts_same_period' => (bool)$decisions['accounts_same_period'],
                'computations' => (bool)$decisions['computations_attached'],
                'computations_same_period' => (bool)$decisions['computations_same_period'],
                'supplementary_pages' => array_values((array)($decisions['supplementary_pages'] ?? [])),
            ],
            'calculation' => [
                'loss_relief_treatment' => (string)$decisions['loss_relief_treatment'],
                'trading_profit_before_losses' => (float)$decisions['trading_profit_before_losses'],
                'trading_losses_brought_forward_used' => (float)$decisions['trading_losses_brought_forward_used'],
                'net_trading_profits' => (float)$decisions['net_trading_profits'],
                'profits_before_other_deductions' => (float)$decisions['profits_before_other_deductions'],
                'profits_before_donations_group_relief' => (float)$decisions['profits_before_donations_group_relief'],
                'associated_company_count' => (int)$decisions['associated_company_count'],
                'tax_bands' => $taxBands,
                'gross_corporation_tax' => $grossTax,
                'marginal_relief' => $marginalRelief,
            ],
            'capital_allowances' => [
                'aia_claimed_in_trade' => (float)$decisions['aia_claimed_in_trade'],
                'main_pool_capital_allowances' => (float)$decisions['main_pool_capital_allowances'],
                'main_pool_balancing_charges' => (float)$decisions['main_pool_balancing_charges'],
                'special_rate_pool_capital_allowances' => (float)$decisions['special_rate_pool_capital_allowances'],
                'special_rate_pool_balancing_charges' => (float)$decisions['special_rate_pool_balancing_charges'],
                'qualifying_expenditure_other_machinery_plant' => (float)$decisions['qualifying_expenditure_other_machinery_plant'],
            ],
            'amounts' => [
                'turnover' => (float)$model['accounts_facts']['turnover'],
                'accounting_profit' => $this->number($summary, 'accounting_profit'),
                'capital_allowances' => $this->number($summary, 'capital_allowances'),
                'taxable_before_losses' => $this->number($summary, 'taxable_before_losses'),
                'taxable_profit' => $taxableProfit,
                'taxable_loss' => $taxableLoss,
                'losses_brought_forward' => $this->number($summary, 'losses_brought_forward'),
                'losses_used' => $this->number($summary, 'losses_used'),
                'losses_carried_forward' => $this->number($summary, 'losses_carried_forward'),
                'loss_created_in_period' => $this->number($summary, 'loss_created_in_period'),
                'corporation_tax' => $ordinaryTax,
                'net_corporation_tax_chargeable' => $ordinaryTax,
                'net_corporation_tax_liability' => $ordinaryTax,
                'tax_chargeable' => $taxPayable,
                'tax_payable' => $taxPayable,
            ],
            'ct600a' => $ct600a,
        ];
    }

    /** @return list<string> */
    private function validateFrozenModel(
        array $model,
        array $filing,
        int $companyId,
        int $accountingPeriodId,
        int $ctPeriodId
    ): array {
        $errors = [];
        if ((array)($filing['blocking_diagnostics'] ?? []) !== []) {
            $errors[] = 'The approved CT-period basis contains a blocking diagnostic.';
        }
        $profile = (array)($model['supported_return_profile'] ?? []);
        if (empty($profile['supported']) || empty($profile['ordinary_trading_company_confirmed'])) {
            $errors[] = 'Only the approved ordinary UK trading-company profile is supported.';
        }
        if ((array)($profile['failed_checks'] ?? []) !== []) {
            $errors[] = 'A supported-return-profile check failed.';
        }
        if ((int)($model['identity']['company_id'] ?? 0) !== $companyId
            || (int)($model['accounting_period']['id'] ?? 0) !== $accountingPeriodId
            || (int)($model['ct_period']['id'] ?? 0) !== $ctPeriodId) {
            $errors[] = 'The frozen company or period identity does not match the requested return.';
        }
        $name = trim((string)($model['identity']['company_name'] ?? ''));
        if (strlen($name) < 2 || strlen($name) > 56 || preg_match('/[£$#~€]/u', $name) === 1) {
            $errors[] = 'The frozen company name is not valid for the CT600 schema.';
        }
        $number = trim((string)($model['identity']['company_number'] ?? ''));
        if (preg_match('/^[A-Z0-9]{2,8}$/', $number) !== 1) {
            $errors[] = 'The frozen company registration number is not valid for the CT600 schema.';
        }
        if (preg_match('/^[0-9]{10}$/', (string)($model['filing_identity']['utr'] ?? '')) !== 1) {
            $errors[] = 'The approved filing basis must contain a 10-digit Corporation Tax UTR.';
        }
        if ((string)($model['accounts_facts']['presentation_currency'] ?? '') !== 'GBP'
            || !is_int($model['accounts_facts']['turnover'] ?? null) && !is_float($model['accounts_facts']['turnover'] ?? null)
            || (float)($model['accounts_facts']['turnover'] ?? -1) < 0) {
            $errors[] = 'The frozen accounts turnover must be a non-negative GBP amount.';
        }
        if (trim((string)($model['accounts_report']['basis_version'] ?? '')) === ''
            || preg_match('/^[a-f0-9]{64}$/i', (string)($model['accounts_report']['basis_hash'] ?? '')) !== 1) {
            $errors[] = 'The frozen accounts-report identity is missing or invalid.';
        }
        $accounting = (array)($model['accounting_period'] ?? []);
        $ct = (array)($model['ct_period'] ?? []);
        foreach (['start_date', 'end_date'] as $dateKey) {
            if (!$this->isDate((string)($accounting[$dateKey] ?? '')) || !$this->isDate((string)($ct[$dateKey] ?? ''))) {
                $errors[] = 'The frozen accounting and CT period dates must use YYYY-MM-DD.';
                break;
            }
        }
        if ($this->isDate((string)($accounting['start_date'] ?? ''))
            && $this->isDate((string)($accounting['end_date'] ?? ''))
            && $this->isDate((string)($ct['start_date'] ?? ''))
            && $this->isDate((string)($ct['end_date'] ?? ''))) {
            if ((string)($ct['start_date'] ?? '') < (string)($accounting['start_date'] ?? '')
                || (string)($ct['end_date'] ?? '') > (string)($accounting['end_date'] ?? '')
                || (string)($ct['start_date'] ?? '') > (string)($ct['end_date'] ?? '')) {
                $errors[] = 'The frozen CT period is outside its accounting period.';
            }
        }
        $decisions = (array)($model['filing_decisions'] ?? []);
        if (($decisions['return_type'] ?? null) !== 'new'
            || (int)($decisions['company_type'] ?? -1) !== 0
            || empty($decisions['this_period_return'])
            || empty($decisions['accounts_attached'])
            || empty($decisions['computations_attached'])
            || empty($decisions['computations_same_period'])
            || !in_array((array)($decisions['supplementary_pages'] ?? []), [[], ['CT600A']], true)
            || (!empty($model['ct600a']['required']) !== ((array)($decisions['supplementary_pages'] ?? []) === ['CT600A']))) {
            $errors[] = 'The approved filing decisions are outside the original ordinary-company CT600 MVP.';
        }
        $summary = (array)($model['computation']['summary'] ?? []);
        foreach ([
            'accounting_profit', 'capital_allowances', 'taxable_before_losses', 'taxable_profit',
            'taxable_loss', 'losses_brought_forward', 'losses_used', 'losses_carried_forward',
            'loss_created_in_period', 'ordinary_corporation_tax', 's455_tax',
            'estimated_corporation_tax', 'associated_company_count',
        ] as $key) {
            if (!array_key_exists($key, $summary) || !is_int($summary[$key]) && !is_float($summary[$key])) {
                $errors[] = 'The frozen computation is missing numeric fact: ' . $key . '.';
            }
        }
        foreach (['capital_allowances', 'taxable_profit', 'taxable_loss', 'loss_created_in_period', 'losses_brought_forward', 'losses_used', 'losses_carried_forward', 'ordinary_corporation_tax', 's455_tax', 'estimated_corporation_tax', 'associated_company_count'] as $key) {
            if (isset($summary[$key]) && (float)$summary[$key] < 0) {
                $errors[] = 'The frozen computation fact must not be negative: ' . $key . '.';
            }
        }
        $decisionNumericKeys = [
            'trading_profit_before_losses', 'trading_losses_brought_forward_used',
            'net_trading_profits', 'profits_before_other_deductions',
            'profits_before_donations_group_relief', 'associated_company_count',
            'aia_claimed_in_trade', 'main_pool_capital_allowances',
            'main_pool_balancing_charges', 'special_rate_pool_capital_allowances',
            'special_rate_pool_balancing_charges', 'qualifying_expenditure_other_machinery_plant',
        ];
        foreach ($decisionNumericKeys as $key) {
            if (!array_key_exists($key, $decisions) || !is_int($decisions[$key]) && !is_float($decisions[$key])
                || (float)$decisions[$key] < 0.0) {
                $errors[] = 'The approved CT600 decision is missing or invalid: ' . $key . '.';
            }
        }
        if ($decisionNumericKeys !== [] && $summary !== []) {
            $lossesUsed = (float)($summary['losses_used'] ?? 0);
            $expectedTreatment = $lossesUsed > 0.004
                ? 'trading_brought_forward_against_same_trade_profit'
                : 'none';
            if ((string)($decisions['loss_relief_treatment'] ?? '') !== $expectedTreatment
                || abs((float)($decisions['trading_losses_brought_forward_used'] ?? -1) - $lossesUsed) > 0.009
                || abs((float)($decisions['trading_profit_before_losses'] ?? -1) - max(0.0, (float)($summary['taxable_before_losses'] ?? 0))) > 0.009
                || abs((float)($decisions['net_trading_profits'] ?? -1) - (float)($summary['taxable_profit'] ?? 0)) > 0.009
                || abs((float)($decisions['profits_before_other_deductions'] ?? -1) - (float)($summary['taxable_profit'] ?? 0)) > 0.009
                || abs((float)($decisions['profits_before_donations_group_relief'] ?? -1) - (float)($summary['taxable_profit'] ?? 0)) > 0.009
                || abs(
                    (float)($decisions['main_pool_capital_allowances'] ?? 0)
                    + (float)($decisions['special_rate_pool_capital_allowances'] ?? 0)
                    - (float)($summary['capital_allowances'] ?? 0)
                ) > 0.009) {
                $errors[] = 'The approved CT600 presentation decisions do not reconcile to the frozen calculation.';
            }
        }
        $taxBands = (array)($decisions['tax_calculation_bands'] ?? []);
        if ((float)($summary['taxable_profit'] ?? 0) > 0.004 && ($taxBands === [] || count($taxBands) > 2)) {
            $errors[] = 'The approved CT600 tax calculation needs one or two frozen financial-year bands.';
        }
        return array_values(array_unique($errors));
    }

    /** @return list<string> */
    private function supplementaryPageReasons(array $model): array
    {
        $reasons = [];
        foreach ($this->flatten($model) as $key => $value) {
            $normalisedKey = strtolower((string)$key);
            $signalsSupplement = preg_match('/(?:^|\.)(?:ct600[a-p]_required|supplementary_page_required|requires_supplementary_pages)$/', $normalisedKey) === 1;
            if (str_ends_with($normalisedKey, 'ct600a_required')) {
                continue;
            }
            if ($signalsSupplement && ($value === true || is_numeric($value) && (float)$value != 0.0 || is_string($value) && trim($value) !== '')) {
                $reasons[] = str_replace('_', ' ', (string)$key);
            }
        }
        return array_values(array_unique($reasons));
    }

    /** @return list<string> */
    private function validateMappings(array $mappings): array
    {
        if ($mappings === []) {
            return ['The active CT600 mapping profile contains no resolved fields.'];
        }
        $errors = [];
        $paths = [];
        foreach ($mappings as $mapping) {
            $path = trim(str_replace('\\', '/', (string)($mapping['target_xpath'] ?? '')));
            if (!str_starts_with($path, 'IRenvelope/CompanyTaxReturn/')) {
                $errors[] = 'A CT600 mapping target is outside CompanyTaxReturn: ' . ($path !== '' ? $path : '(blank)') . '.';
            }
            if (isset($paths[$path])) {
                $errors[] = 'A CT600 XML target is populated more than once: ' . $path . '.';
            }
            $paths[$path] = true;
            if (!array_key_exists('source_value', $mapping)) {
                $errors[] = 'A resolved CT600 field has no canonical source value: ' . $path . '.';
            }
        }
        return array_values(array_unique($errors));
    }

    private function loadFilingModel(int $companyId, int $accountingPeriodId, int $ctPeriodId): array
    {
        return $this->filingModelLoader !== null
            ? (array)($this->filingModelLoader)($companyId, $accountingPeriodId, $ctPeriodId)
            : (new CtPeriodFilingModelService())->build($companyId, $accountingPeriodId, $ctPeriodId);
    }

    private function resolveRim(string $periodStart, string $periodEnd): array
    {
        return $this->rimResolver !== null
            ? (array)($this->rimResolver)($periodStart, $periodEnd)
            : (new HmrcCt600VersionService())->resolveForCtPeriod($periodStart, $periodEnd);
    }

    private function resolveProfile(int $packageId): ?array
    {
        if ($this->profileResolver !== null) {
            $result = ($this->profileResolver)($packageId);
            return is_array($result) ? $result : null;
        }
        return (new CtFilingMappingService())->activeProfile(CtFilingMappingService::TARGET_RIM, $packageId);
    }

    private function mapFacts(array $filingModel, array $profile): array
    {
        return $this->factMapper !== null
            ? (array)($this->factMapper)($filingModel, $profile)
            : (new CtFilingMappingService())->mapFrozenFacts(
                CtFilingMappingService::TARGET_RIM,
                $filingModel,
                $profile
            );
    }

    private function lookupPackageId(array $rim): int
    {
        if (!\InterfaceDB::tableExists('hmrc_ct_rim_packages')) {
            return 0;
        }
        return (int)(\InterfaceDB::fetchColumn(
            'SELECT id FROM hmrc_ct_rim_packages
             WHERE form_version = :form_version AND artifact_version = :artifact_version
             ORDER BY id DESC LIMIT 1',
            [
                'form_version' => (string)($rim['form_version'] ?? ''),
                'artifact_version' => (string)($rim['artifact_version'] ?? ''),
            ]
        ) ?: 0);
    }

    private function number(array $source, string $key): float
    {
        return (float)$source[$key];
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function flatten(array $value, string $prefix = ''): array
    {
        $facts = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($child)) {
                $facts += $this->flatten($child, $path);
            } else {
                $facts[$path] = $child;
            }
        }
        return $facts;
    }

    private function canonicalJson(array $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalise($child);
            }
            return $item;
        };
        return json_encode(
            $normalise($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /** @return array<string,mixed> */
    private function failure(string $message, array $details = []): array
    {
        $errors = array_values(array_unique(array_filter(array_map(
            'strval',
            array_merge([$message], $details)
        ), static fn(string $item): bool => trim($item) !== '')));
        return [
            'ok' => false,
            'errors' => $errors,
            'warnings' => [],
            'model_version' => self::MODEL_VERSION,
            'model' => [],
            'source_manifest' => [],
            'source_manifest_sha256' => '',
        ];
    }
}
