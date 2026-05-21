<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AccountingContextService implements SiteContextProviderInterface
{
    private const SESSION_USER_ID = 'eel.accounting.user_id';
    private const SESSION_COMPANY_ID = 'eel.accounting.company_id';
    private const SESSION_COMPANY_NAME = 'eel.accounting.company_name';
    private const SESSION_COMPANY_NUMBER = 'eel.accounting.company_number';
    private const SESSION_TAX_YEAR_ID = 'eel.accounting.tax_year_id';

    public function resolveSiteContext(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services,
        array $pageContext
    ): SiteContextResultFramework {
        $resolved = $this->resolveSelection($request, $pageContext);

        return new SiteContextResultFramework(
            context: $resolved['context'],
            selectors: $resolved['selectors']
        );
    }

    public function handleSiteContextAction(
        RequestFramework $request,
        PageInterfaceFramework $page,
        PageServiceFramework $services
    ): ActionResultFramework {
        $key = trim((string)$request->input('site_context_key', ''));
        $value = $this->siteContextActionValue($request);

        if ($key === 'company_id') {
            return $this->handleCompanySelection($request, $value);
        }

        if ($key === 'tax_year_id') {
            return $this->handleTaxYearSelection($request, $value);
        }

        return new ActionResultFramework(false, [], [[
            'type' => 'error',
            'message' => 'The selected site context field is not supported.',
        ]]);
    }

    private function siteContextActionValue(RequestFramework $request): int
    {
        $value = $request->input('site_context_value', null);

        if ($value === null) {
            $inputName = trim((string)$request->input('site_context_input_name', ''));
            if ($inputName !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $inputName) === 1) {
                $value = $request->input($inputName, 0);
            }
        }

        return HelperFramework::sanitiseId($value, 0);
    }

    public function companyId(?RequestFramework $request = null): int
    {
        if ($request instanceof RequestFramework) {
            return (int)($this->resolveSelection($request)['context']['company']['id'] ?? 0);
        }

        return $this->sessionCompanyId();
    }

    public function taxYearId(?RequestFramework $request = null): int
    {
        if ($request instanceof RequestFramework) {
            return (int)($this->resolveSelection($request)['context']['company']['tax_year_id'] ?? 0);
        }

        return $this->sessionTaxYearId();
    }

    public function authCompanyId(): int
    {
        return HelperFramework::sanitiseId($this->companyId());
    }

    public function authTaxYearId(): int
    {
        return HelperFramework::sanitiseId($this->taxYearId());
    }

    public function setPageContext(
        int $companyId,
        string $companyName,
        string $companyNumber,
        int $taxYearId
    ): void {
        $this->startContextSession();

        $companyId = max(0, $companyId);
        $taxYearId = max(0, $taxYearId);
        $companyName = trim($companyName);
        $companyNumber = trim($companyNumber);
        $userId = $this->currentUserId();

        if ($companyId <= 0 || $userId <= 0) {
            $this->clearPageContext();
            return;
        }

        $_SESSION[self::SESSION_USER_ID] = $userId;
        $_SESSION[self::SESSION_COMPANY_ID] = $companyId;
        $_SESSION[self::SESSION_COMPANY_NAME] = $companyName;
        $_SESSION[self::SESSION_COMPANY_NUMBER] = $companyNumber;

        if ($taxYearId > 0) {
            $_SESSION[self::SESSION_TAX_YEAR_ID] = $taxYearId;
        } else {
            unset($_SESSION[self::SESSION_TAX_YEAR_ID]);
        }
    }

    public function clearPageContext(): void
    {
        $this->startContextSession();

        unset(
            $_SESSION[self::SESSION_USER_ID],
            $_SESSION[self::SESSION_COMPANY_ID],
            $_SESSION[self::SESSION_COMPANY_NAME],
            $_SESSION[self::SESSION_COMPANY_NUMBER],
            $_SESSION[self::SESSION_TAX_YEAR_ID]
        );
    }

    private function handleCompanySelection(RequestFramework $request, int $companyId): ActionResultFramework
    {
        if ($companyId <= 0) {
            $this->clearPageContext();

            return ActionResultFramework::success();
        }

        $company = (new CompanyRepository())->fetchCompanyDetails($companyId);
        if ($company === null) {
            return new ActionResultFramework(false, [], [[
                'type' => 'error',
                'message' => 'The selected company could not be found.',
            ]]);
        }

        $taxYearId = $this->validTaxYearId(
            $companyId,
            $this->requestContextId($request, 'tax_year_id', 'tax_year_id')
        );

        if ($taxYearId <= 0) {
            $taxYearId = $this->defaultTaxYearId($companyId);
        }

        $this->setPageContext(
            $companyId,
            trim((string)($company['company_name'] ?? '')),
            trim((string)($company['company_number'] ?? '')),
            $taxYearId
        );

        return ActionResultFramework::success();
    }

    private function handleTaxYearSelection(RequestFramework $request, int $taxYearId): ActionResultFramework
    {
        $companyId = $this->requestContextId($request, 'company_id', 'company_id');
        $companyId = HelperFramework::sanitiseId($companyId, $this->sessionCompanyId());

        if ($companyId <= 0) {
            return new ActionResultFramework(false, [], [[
                'type' => 'error',
                'message' => 'Select a company before selecting an accounting period.',
            ]]);
        }

        $company = (new CompanyRepository())->fetchCompanyDetails($companyId);
        if ($company === null) {
            return new ActionResultFramework(false, [], [[
                'type' => 'error',
                'message' => 'The selected company could not be loaded.',
            ]]);
        }

        if ($taxYearId > 0 && $this->validTaxYearId($companyId, $taxYearId) <= 0) {
            return new ActionResultFramework(false, [], [[
                'type' => 'error',
                'message' => 'The selected accounting period does not belong to the selected company.',
            ]]);
        }

        $this->setPageContext(
            $companyId,
            trim((string)($company['company_name'] ?? '')),
            trim((string)($company['company_number'] ?? '')),
            $taxYearId
        );

        return ActionResultFramework::success();
    }

    private function resolveSelection(RequestFramework $request, array $pageContext = []): array
    {
        $this->ensureCurrentUserOwnsContext();

        $companyRows = (new CompanyRepository())->fetchCompanySelectorRows();
        $requestedCompanyId = $this->contextId($pageContext, 'company_id');
        $requestedCompanyId = HelperFramework::sanitiseId(
            $requestedCompanyId,
            $this->requestContextId($request, 'company_id', 'company_id')
        );

        $companyId = $this->resolveCompanyId($companyRows, $requestedCompanyId);
        $company = $companyId > 0 ? (new CompanyRepository())->fetchCompanyDetails($companyId) : null;
        if ($company === null) {
            $companyId = 0;
        }

        $taxYears = $companyId > 0 ? (new TaxYearRepository())->fetchTaxYears($companyId) : [];
        $requestedTaxYearId = $this->contextId($pageContext, 'tax_year_id');
        $requestedTaxYearId = HelperFramework::sanitiseId(
            $requestedTaxYearId,
            $this->requestContextId($request, 'tax_year_id', 'tax_year_id')
        );
        $taxYearId = $this->resolveTaxYearId($taxYears, $requestedTaxYearId);
        $taxYear = $taxYearId > 0 ? (new TaxYearRepository())->fetchTaxYear($companyId, $taxYearId) : null;

        $companyName = trim((string)($company['company_name'] ?? ''));
        $companyNumber = trim((string)($company['company_number'] ?? ''));
        $this->setPageContext($companyId, $companyName, $companyNumber, $taxYearId);

        return [
            'context' => $this->context($companyId, $company, $taxYearId, $taxYear),
            'selectors' => $this->selectors($companyRows, $companyId, $taxYears, $taxYearId),
        ];
    }

    private function context(int $companyId, ?array $company, int $taxYearId, ?array $taxYear): array
    {
        $settings = CompanySettingsStore::defaults();
        if ($companyId > 0) {
            $settings = (new CompanySettingsService())->loadFromDatabase(
                new CompanySettingsStore($companyId),
                $companyId,
                $taxYearId
            );
        }

        $companyName = trim((string)($company['company_name'] ?? $settings['company_name'] ?? ''));
        $companyNumber = trim((string)($company['company_number'] ?? $settings['companies_house_number'] ?? ''));

        return [
            'site_context' => [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ],
            'page' => [
                'selected_company_id' => $companyId,
                'selected_tax_year_id' => $taxYearId,
            ],
            'company' => [
                'id' => $companyId,
                'name' => $companyName,
                'company_name' => $companyName,
                'number' => $companyNumber,
                'company_number' => $companyNumber,
                'valid_selected' => $companyId > 0 && $company !== null,
                'tax_year_id' => $taxYearId,
                'tax_year_label' => trim((string)($taxYear['label'] ?? '')),
                'settings' => $settings,
            ],
            'tax_year' => [
                'id' => $taxYearId,
                'label' => trim((string)($taxYear['label'] ?? '')),
                'period_start' => trim((string)($taxYear['period_start'] ?? '')),
                'period_end' => trim((string)($taxYear['period_end'] ?? '')),
            ],
        ];
    }

    private function selectors(array $companies, int $companyId, array $taxYears, int $taxYearId): array
    {
        return [
            [
                'key' => 'company_id',
                'input_name' => 'company_id',
                'slot' => 'sidebar',
                'label' => 'Company',
                'value' => $companyId > 0 ? (string)$companyId : '',
                'options' => $this->companyOptions($companies),
                'disabled' => $companies === [],
                'visible' => true,
            ],
            [
                'key' => 'tax_year_id',
                'input_name' => 'tax_year_id',
                'slot' => 'topbar',
                'label' => 'Accounting Period',
                'value' => $taxYearId > 0 ? (string)$taxYearId : '',
                'options' => $this->taxYearOptions($taxYears),
                'disabled' => $companyId <= 0 || $taxYears === [],
                'visible' => true,
            ],
        ];
    }

    private function companyOptions(array $companies): array
    {
        if ($companies === []) {
            return [['value' => '', 'label' => 'No companies']];
        }

        $options = [];
        foreach ($companies as $company) {
            $id = (int)($company['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $name = trim((string)($company['company_name'] ?? ''));
            $number = trim((string)($company['company_number'] ?? ''));
            $label = $name !== '' ? $name : 'Company #' . $id;
            if ($number !== '') {
                $label .= ' (' . $number . ')';
            }

            $options[] = [
                'value' => (string)$id,
                'label' => $label,
                'short_label' => $name !== '' ? $name : 'Company #' . $id,
            ];
        }

        return $options !== [] ? $options : [['value' => '', 'label' => 'No companies']];
    }

    private function taxYearOptions(array $taxYears): array
    {
        if ($taxYears === []) {
            return [['value' => '', 'label' => 'No accounting periods']];
        }

        $options = [];
        foreach ($taxYears as $taxYear) {
            $id = (int)($taxYear['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $label = trim((string)($taxYear['label'] ?? ''));
            if ($label === '') {
                $start = trim((string)($taxYear['period_start'] ?? ''));
                $end = trim((string)($taxYear['period_end'] ?? ''));
                $label = $start !== '' && $end !== ''
                    ? TaxPeriodService::accountingPeriodLabel($start, $end)
                    : 'Accounting period #' . $id;
            }

            $options[] = [
                'value' => (string)$id,
                'label' => $label,
            ];
        }

        return $options !== [] ? $options : [['value' => '', 'label' => 'No accounting periods']];
    }

    private function resolveCompanyId(array $companies, int $requestedCompanyId): int
    {
        $validIds = [];
        foreach ($companies as $company) {
            $id = (int)($company['id'] ?? 0);
            if ($id > 0) {
                $validIds[$id] = true;
            }
        }

        if ($requestedCompanyId > 0 && isset($validIds[$requestedCompanyId])) {
            return $requestedCompanyId;
        }

        $sessionCompanyId = $this->sessionCompanyId();
        if ($sessionCompanyId > 0 && isset($validIds[$sessionCompanyId])) {
            return $sessionCompanyId;
        }

        if (count($validIds) === 1) {
            return (int)array_key_first($validIds);
        }

        return 0;
    }

    private function resolveTaxYearId(array $taxYears, int $requestedTaxYearId): int
    {
        $validIds = [];
        foreach ($taxYears as $taxYear) {
            $id = (int)($taxYear['id'] ?? 0);
            if ($id > 0) {
                $validIds[$id] = true;
            }
        }

        if ($requestedTaxYearId > 0 && isset($validIds[$requestedTaxYearId])) {
            return $requestedTaxYearId;
        }

        $sessionTaxYearId = $this->sessionTaxYearId();
        if ($sessionTaxYearId > 0 && isset($validIds[$sessionTaxYearId])) {
            return $sessionTaxYearId;
        }

        return (int)($taxYears[0]['id'] ?? 0);
    }

    private function validTaxYearId(int $companyId, int $taxYearId): int
    {
        if ($companyId <= 0 || $taxYearId <= 0) {
            return 0;
        }

        return (new TaxYearRepository())->fetchTaxYear($companyId, $taxYearId) !== null ? $taxYearId : 0;
    }

    private function defaultTaxYearId(int $companyId): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $taxYears = (new TaxYearRepository())->fetchTaxYears($companyId);

        return (int)($taxYears[0]['id'] ?? 0);
    }

    private function contextId(array $context, string $key): int
    {
        $direct = HelperFramework::sanitiseId($context[$key] ?? null);
        if ($direct > 0) {
            return $direct;
        }

        return HelperFramework::sanitiseId($context['site_context'][$key] ?? null);
    }

    private function requestContextId(RequestFramework $request, string $key, string $inputName): int
    {
        $direct = HelperFramework::sanitiseId($request->input($inputName, null));
        if ($direct > 0) {
            return $direct;
        }

        $keys = $request->input('site_context_keys', $request->input('site_context_keys[]', []));
        $values = $request->input('site_context_values', $request->input('site_context_values[]', []));
        if (!is_array($keys) || !is_array($values)) {
            return 0;
        }

        foreach (array_values($keys) as $index => $candidateKey) {
            if ((string)$candidateKey !== $key) {
                continue;
            }

            return HelperFramework::sanitiseId($values[$index] ?? null);
        }

        return 0;
    }

    private function sessionCompanyId(): int
    {
        $this->ensureCurrentUserOwnsContext();

        return HelperFramework::sanitiseId($_SESSION[self::SESSION_COMPANY_ID] ?? 0);
    }

    private function sessionTaxYearId(): int
    {
        $this->ensureCurrentUserOwnsContext();

        return HelperFramework::sanitiseId($_SESSION[self::SESSION_TAX_YEAR_ID] ?? 0);
    }

    private function ensureCurrentUserOwnsContext(): void
    {
        $this->startContextSession();
        $currentUserId = $this->currentUserId();
        $storedUserId = HelperFramework::sanitiseId($_SESSION[self::SESSION_USER_ID] ?? 0);

        if ($currentUserId <= 0 || ($storedUserId > 0 && $storedUserId !== $currentUserId)) {
            $this->clearPageContext();
        }
    }

    private function currentUserId(): int
    {
        $session = new SessionAuthenticationService();
        $deviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $session->authenticatedUserId($deviceId !== '' ? $deviceId : null);
    }

    private function startContextSession(): void
    {
        (new SessionAuthenticationService())->startSession();
    }
}
