<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class NominalsAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));

        return match ($intent) {
            'save_nominals' => $this->saveNominals($request),
            'apply_nominal_suggestions' => $this->applySuggestions($request),
            'edit_nominal_account' => $this->editNominalAccount($request),
            'edit_nominal_subtype' => $this->editNominalSubtype($request),
            'cancel_nominal_edit' => $this->cancelNominalEdit($request),
            'add_nominal_account' => $this->saveNominalAccount($request, null),
            'save_nominal_account' => $this->saveNominalAccount($request, max(0, (int)$request->input('nominal_account_id', 0))),
            'add_nominal_subtype' => $this->saveNominalSubtype($request, null),
            'save_nominal_subtype' => $this->saveNominalSubtype($request, max(0, (int)$request->input('subtype_id', 0))),
            default => ActionResultFramework::none(),
        };
    }

    private function editNominalAccount(RequestFramework $request): ActionResultFramework
    {
        $editNominalId = max(0, (int)$request->input('edit_nominal_id', 0));

        return ActionResultFramework::success(
            ['page.context'],
            [],
            [
                'edit_nominal_id' => $editNominalId > 0 ? $editNominalId : null,
                'edit_subtype_id' => null,
                'show_card' => 'nominals_add_account',
            ],
            [
                'nominals' => [
                    'editing_nominal_id' => $editNominalId,
                    'editing_subtype_id' => 0,
                ],
            ]
        );
    }

    private function editNominalSubtype(RequestFramework $request): ActionResultFramework
    {
        $editSubtypeId = max(0, (int)$request->input('edit_subtype_id', 0));

        return ActionResultFramework::success(
            ['page.context'],
            [],
            [
                'edit_nominal_id' => null,
                'edit_subtype_id' => $editSubtypeId > 0 ? $editSubtypeId : null,
                'show_card' => 'nominals_add_category',
            ],
            [
                'nominals' => [
                    'editing_nominal_id' => 0,
                    'editing_subtype_id' => $editSubtypeId,
                ],
            ]
        );
    }

    private function cancelNominalEdit(RequestFramework $request): ActionResultFramework
    {
        return ActionResultFramework::success(
            ['page.context'],
            [],
            [
                'edit_nominal_id' => null,
                'edit_subtype_id' => null,
                'show_card' => trim((string)$request->input('show_card', '')) ?: null,
            ],
            [
                'nominals' => [
                    'editing_nominal_id' => 0,
                    'editing_subtype_id' => 0,
                ],
            ]
        );
    }

    private function saveNominalAccount(RequestFramework $request, ?int $id): ActionResultFramework
    {
        if ($id !== null && $id <= 0) {
            return $this->errorResult('Select a nominal account before saving changes.');
        }

        $subtypeRepository = new NominalSubtypeRepository();
        $accountRepository = new NominalAccountRepository();
        $subtypeIndex = [];

        foreach ($subtypeRepository->fetchNominalSubtypes() as $subtype) {
            if (is_array($subtype)) {
                $subtypeIndex[(int)($subtype['id'] ?? 0)] = $subtype;
            }
        }

        $input = [
            'code' => trim((string)$request->post('nominal_code', '')),
            'name' => trim((string)$request->post('nominal_name', '')),
            'account_type' => trim((string)$request->post('nominal_account_type', '')),
            'account_subtype_id' => trim((string)$request->post('nominal_account_subtype_id', '')),
            'tax_treatment' => trim((string)$request->post('nominal_tax_treatment', 'allowable')),
            'sort_order' => trim((string)$request->post('nominal_sort_order', '100')),
            'is_active' => (string)$request->post('nominal_is_active', '') === '1' ? 1 : 0,
        ];
        $errors = $accountRepository->validateInput($input, $subtypeIndex, $id);

        if ($errors !== []) {
            return $this->errorResult(implode(' ', $errors));
        }

        try {
            InterfaceDB::beginTransaction();
            $accountRepository->save($input, $id);
            InterfaceDB::commit();
        } catch (Throwable $exception) {
            InterfaceDB::rollBack();
            return $this->errorResult($exception->getMessage());
        }

        return ActionResultFramework::success(
            ['page.context'],
            [[
                'type' => 'success',
                'message' => $id === null ? 'Nominal account added successfully.' : 'Nominal account saved successfully.',
            ]],
            [
                'edit_nominal_id' => null,
                'edit_subtype_id' => null,
                'show_card' => 'nominals_accounts',
            ],
            [
                'nominals' => [
                    'editing_nominal_id' => 0,
                    'editing_subtype_id' => 0,
                ],
            ]
        );
    }

    private function saveNominalSubtype(RequestFramework $request, ?int $id): ActionResultFramework
    {
        if ($id !== null && $id <= 0) {
            return $this->errorResult('Select a nominal category before saving changes.');
        }

        $subtypeRepository = new NominalSubtypeRepository();
        $input = [
            'code' => trim((string)$request->post('subtype_code', '')),
            'name' => trim((string)$request->post('subtype_name', '')),
            'parent_account_type' => trim((string)$request->post('subtype_parent_account_type', '')),
            'sort_order' => trim((string)$request->post('subtype_sort_order', '100')),
            'is_active' => (string)$request->post('subtype_is_active', '') === '1' ? 1 : 0,
        ];
        $errors = $subtypeRepository->validateInput($input, $id);

        if ($errors !== []) {
            return $this->errorResult(implode(' ', $errors));
        }

        try {
            InterfaceDB::beginTransaction();
            $subtypeRepository->save($input, $id);
            InterfaceDB::commit();
        } catch (Throwable $exception) {
            InterfaceDB::rollBack();
            return $this->errorResult($exception->getMessage());
        }

        return ActionResultFramework::success(
            ['page.context'],
            [[
                'type' => 'success',
                'message' => $id === null ? 'Nominal category added successfully.' : 'Nominal category saved successfully.',
            ]],
            [
                'edit_nominal_id' => null,
                'edit_subtype_id' => null,
                'show_card' => 'nominals_categories',
            ],
            [
                'nominals' => [
                    'editing_nominal_id' => 0,
                    'editing_subtype_id' => 0,
                ],
            ]
        );
    }

    private function saveNominals(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return $this->errorResult('Select a company before saving nominal defaults.');
        }

        try {
            $settingsStore = new CompanySettingsStore($companyId);
            $settingsService = new CompanySettingsService();
            $settings = [
                'default_bank_nominal_id' => trim((string)$request->post('default_bank_nominal_id', '')),
                'default_expense_nominal_id' => trim((string)$request->post('default_expense_nominal_id', '')),
                'director_loan_nominal_id' => trim((string)$request->post('director_loan_nominal_id', '')),
                'vat_nominal_id' => trim((string)$request->post('vat_nominal_id', '')),
                'uncategorised_nominal_id' => trim((string)$request->post('uncategorised_nominal_id', '')),
            ];

            $settingsService->saveNominalsSection($settingsStore, $settings);
            $nominalAccounts = (new NominalAccountRepository())->fetchNominalAccounts($companyId);

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message_html' => $this->savedNominalsMessageHtml($settings, $nominalAccounts),
                ]]
            );
        } catch (Throwable $exception) {
            return $this->errorResult($exception->getMessage());
        }
    }

    private function applySuggestions(RequestFramework $request): ActionResultFramework
    {
        $companyId = (new AccountingContextService())->authCompanyId();

        if ($companyId <= 0) {
            return $this->errorResult('Select a company before applying suggested nominal defaults.');
        }

        try {
            $settingsStore = new CompanySettingsStore($companyId);
            $settingsService = new CompanySettingsService();
            $nominalAccounts = (new NominalAccountRepository())->fetchNominalAccounts($companyId);
            $settings = $settingsService->loadFromDatabase($settingsStore, $companyId, 0);

            if (!$settingsService->applyNominalSuggestions($settingsStore, $settings, $nominalAccounts)) {
                return $this->errorResult('No complete nominal suggestion set is currently available.');
            }

            return ActionResultFramework::success(
                ['page.context'],
                [[
                    'type' => 'success',
                    'message' => 'Suggested nominal assignments applied successfully.',
                ]]
            );
        } catch (Throwable $exception) {
            return $this->errorResult($exception->getMessage());
        }
    }

    private function errorResult(string $message): ActionResultFramework
    {
        return new ActionResultFramework(
            false,
            ['page.context'],
            [[
                'type' => 'error',
                'message' => $message,
            ]]
        );
    }

    private function savedNominalsMessageHtml(array $settings, array $nominalAccounts): string
    {
        $labels = [
            'default_bank_nominal_id' => 'Default bank',
            'default_expense_nominal_id' => 'Default expense',
            'director_loan_nominal_id' => 'Director loan',
            'vat_nominal_id' => 'VAT control',
            'uncategorised_nominal_id' => 'Fallback uncategorised',
        ];
        $nominalsById = [];

        foreach ($nominalAccounts as $nominalAccount) {
            $id = (int)($nominalAccount['id'] ?? 0);
            if ($id > 0) {
                $nominalsById[$id] = $nominalAccount;
            }
        }

        $parts = [];
        foreach ($labels as $key => $label) {
            $nominalId = (int)($settings[$key] ?? 0);
            $nominalLabel = $nominalId > 0 && isset($nominalsById[$nominalId])
                ? FormattingFramework::nominalLabel($nominalsById[$nominalId])
                : 'Unassigned';

            $parts[] = HelperFramework::escape($label . ': ' . $nominalLabel);
        }

        return 'Nominal defaults saved successfully.<br>Saved:<br>' . implode('<br>', $parts);
    }
}
