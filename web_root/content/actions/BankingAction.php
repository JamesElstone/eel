<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class BankingAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', ''));

        return match ($intent) {
            'add' => $this->saveCompanyAccount($request, $services, false),
            'edit' => $this->editCompanyAccount($request, $services, false),
            'select_field_mapping' => $this->editAccountMapping($request, $services),
            'delete' => $this->deleteCompanyAccount($request, $services),
            'save' => $this->saveCompanyAccount($request, $services, true),
            'assign_missing_nominals' => $this->assignMissingNominals($request),
            'save_account_mapping' => $this->saveAccountMapping($request, $services),
            'save_mapping' => $this->saveAccountMapping($request, $services),
            default => ActionResultFramework::none(),
        };
    }

    private function editAccountMapping(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $accountId = HelperFramework::sanitiseId(
            $request->input('field_mapping_account_id', $request->input('account_id', 0))
        );

        if ($accountId <= 0) {
            return ActionResultFramework::success(
                flashMessages: ['Invalid Account ID given, no action taken.']
            );
        }

        return ActionResultFramework::success(
            changedFacts: ['page.context'],
            context: [
                'field_mapping' => [
                    'mode' => 'account',
                    'account_id' => $accountId,
                ],
            ]
        );
    }

    private function editCompanyAccount(RequestFramework $request, PageServiceFramework $services, bool $isUpdate): ActionResultFramework {
        
        $accountId = HelperFramework::sanitiseId($request->input('account_id', 0));

        if ($accountId > 0) {

            return ActionResultFramework::success(
                changedFacts: ['page.context'],
                query: [
                    'edit_account_id' => $accountId,
                    'show_card' => 'banking_account_form',
                ],
                context: [
                    'edit_account_id' => $accountId,
                    'company' => [
                        'account' => [
                            'id' => $accountId,
                        ],
                    ],
                ]
            );

        } else {
            return ActionResultFramework::success(
                flashMessages: ['Invalid Account ID given, no action taken.']
            );
        }
    }

    private function saveCompanyAccount(RequestFramework $request, PageServiceFramework $services, bool $isUpdate): ActionResultFramework
    {
        $companyAccountService = $this->companyAccountService($services);
        $accountingContext = new AccountingContextService();

        $companyId = $accountingContext->authCompanyId();
        $taxYearId = $accountingContext->authTaxYearId();

        $accountId = HelperFramework::sanitiseId($request->input('account_id'));

        $payload = $this->accountPayload($request);

        $result = $isUpdate
            ? $companyAccountService->updateAccount($companyId, $accountId, $payload)
            : $companyAccountService->createAccount($companyId, $payload);

        $flashMessages = [];
        $flashErrors = array_map('strval', (array)($result['errors'] ?? []));
        $context = [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
        ];

        if (!empty($result['success'])) {
            $flashMessages[] = $isUpdate ? 'Account updated successfully.' : 'Account saved successfully.';

            return $this->result(
                true,
                $flashMessages,
                [],
                $this->queryState($request, [
                    'edit_account_id' => 0,
                    'mapping_account_id' => max(0, (int)$request->input('mapping_account_id', 0)),
                    'show_card' => 'banking_accounts',
                ]),
                $context
            );
        }

        $context['banking_account_form'] = $payload;

        return $this->result(
            false,
            $flashMessages,
            $flashErrors,
            $this->queryState($request, [
                'edit_account_id' => $isUpdate ? $accountId : 0,
                'mapping_account_id' => max(0, (int)$request->input('mapping_account_id', 0)),
            ]),
            $context
        );
    }

    private function deleteCompanyAccount(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $accountingContext = new AccountingContextService();
        $companyId = $accountingContext->authCompanyId();
        $taxYearId = $accountingContext->authTaxYearId();
        $accountId = max(0, (int)$request->input('account_id', 0));

        $result = $this->companyAccountService($services)->deleteAccount($companyId, $accountId);

        $flashMessages = [];
        $flashErrors = array_map('strval', (array)($result['errors'] ?? []));

        if (!empty($result['success'])) {
            $flashMessages[] = 'Account deleted successfully.';
        }

        return $this->result(
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            $this->queryState($request, [
                'edit_account_id' => max(0, (int)$request->input('edit_account_id', 0)) === $accountId ? 0 : max(0, (int)$request->input('edit_account_id', 0)),
                'mapping_account_id' => max(0, (int)$request->input('mapping_account_id', 0)) === $accountId ? 0 : max(0, (int)$request->input('mapping_account_id', 0)),
            ]),
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ]
        );
    }

    private function saveAccountMapping(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {

        $accountingContext = new AccountingContextService();
        $companyId = $accountingContext->authCompanyId();
        $taxYearId = $accountingContext->authTaxYearId();

        $payload = [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'upload_id' => max(0, (int)$request->input('upload_id', 0)),
            'account_id' => max(0, (int)$request->input('account_id', $request->input('mapping_account_id', 0))),
        ];

        foreach (array_keys(StatementUploadService::fieldDefinitions()) as $fieldName) {
            $payload['mapping_' . $fieldName] = $request->input('mapping_' . $fieldName, '');
        }

        $result = $this->statementUploadService($services)->saveFieldMapping($payload);
        $flashMessages = array_map('strval', (array)($result['warnings'] ?? []));
        $flashErrors = array_map('strval', (array)($result['errors'] ?? []));

        if (!empty($result['success'])) {
            $flashMessages[] = 'Field mapping saved.';
        }

        return $this->result(
            !empty($result['success']),
            $flashMessages,
            $flashErrors,
            $this->queryState($request, [
                'mapping_account_id' => max(0, (int)$request->input('field_mapping_account_id', $request->input('mapping_account_id', 0))),
                'edit_account_id' => max(0, (int)$request->input('edit_account_id', 0)),
            ]),
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ]
        );
    }

    private function assignMissingNominals(RequestFramework $request): ActionResultFramework
    {
        if (!(bool)AppConfigurationStore::get('developer_options', false)) {
            return $this->result(
                false,
                [],
                ['Developer options must be enabled before account nominals can be repaired.'],
                $this->queryState($request),
                []
            );
        }

        $accountingContext = new AccountingContextService();
        $companyId = $accountingContext->authCompanyId();
        $taxYearId = $accountingContext->authTaxYearId();
        $result = (new CompanyAccountNominalService())->assignMissingNominals($companyId);
        $messages = [];
        $errors = array_map('strval', (array)($result['errors'] ?? []));

        if (!empty($result['success'])) {
            $messages[] = sprintf(
                'Account nominal repair complete: %d assigned, %d created, %d unchanged.',
                (int)($result['assigned'] ?? 0),
                (int)($result['created'] ?? 0),
                (int)($result['unchanged'] ?? 0)
            );
        }

        return $this->result(
            !empty($result['success']),
            $messages,
            $errors,
            $this->queryState($request, ['show_card' => 'banking_accounts']),
            [
                'company_id' => $companyId,
                'tax_year_id' => $taxYearId,
            ]
        );
    }

    private function queryState(RequestFramework $request, array $overrides = []): array
    {

        $accountingContext = new AccountingContextService();
        $companyId = $accountingContext->authCompanyId();
        $taxYearId = $accountingContext->authTaxYearId();

        $query = [
            'company_id' => $companyId,
            'tax_year_id' => $taxYearId,
            'edit_account_id' => max(0, (int)$request->input('edit_account_id', 0)),
            'mapping_account_id' => max(0, (int)$request->input('mapping_account_id', 0)),
        ];

        foreach ($overrides as $key => $value) {
            $query[$key] = $value;
        }

        return $query;
    }

    private function accountPayload(RequestFramework $request): array
    {
        return [
            'account_name' => trim((string)$request->input('account_name', '')),
            'account_type' => trim((string)$request->input('account_type', CompanyAccountService::TYPE_BANK)),
            'institution_name' => trim((string)$request->input('institution_name', '')),
            'account_identifier' => trim((string)$request->input('account_identifier', '')),
            'nominal_account_id' => trim((string)$request->input('nominal_account_id', '')),
            'internal_transfer_marker' => trim((string)$request->input('internal_transfer_marker', '')),
            'contact_name' => trim((string)$request->input('contact_name', '')),
            'phone_number' => trim((string)$request->input('phone_number', '')),
            'address_line_1' => trim((string)$request->input('address_line_1', '')),
            'address_line_2' => trim((string)$request->input('address_line_2', '')),
            'address_locality' => trim((string)$request->input('address_locality', '')),
            'address_region' => trim((string)$request->input('address_region', '')),
            'address_postal_code' => trim((string)$request->input('address_postal_code', '')),
            'address_country' => trim((string)$request->input('address_country', '')),
            'is_active' => in_array((string)$request->input('is_active', ''), ['1', 'true', 'yes', 'on'], true),
        ];
    }

    private function result(bool $success, array $messages, array $errors, array $query, array $context): ActionResultFramework
    {
        $flash = [];

        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message !== '') {
                $flash[] = $message;
            }
        }

        foreach ($errors as $error) {
            $error = trim((string)$error);
            if ($error !== '') {
                $flash[] = [
                    'type' => 'error',
                    'message' => $error,
                ];
            }
        }

        return new ActionResultFramework($success, ['page.context'], $flash, $query, $context);
    }

    private function companyAccountService(PageServiceFramework $services): CompanyAccountService
    {
        $service = $services->get(CompanyAccountService::class);

        if (!$service instanceof CompanyAccountService) {
            throw new RuntimeException('CompanyAccountService is unavailable.');
        }

        return $service;
    }

    private function statementUploadService(PageServiceFramework $services): StatementUploadService
    {
        $service = $services->get(StatementUploadService::class);

        if (!$service instanceof StatementUploadService) {
            throw new RuntimeException('StatementUploadService is unavailable.');
        }

        return $service;
    }
}
