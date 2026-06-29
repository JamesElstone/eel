<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class ExpenseAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        $companyId = HelperFramework::sanitiseId($request->input('company_id', null), $context->companyId($request));
        $intent = trim((string)$request->input('intent', ''));
        $service = new \eel_accounts\Service\ExpenseClaimService();

        try {
            $result = match ($intent) {
                'add_claimant' => $service->createClaimant($companyId, (string)$request->input('claimant_name', '')),
                'activate_claimant' => $service->setClaimantActive($companyId, (int)$request->input('claimant_id', 0), true),
                'deactivate_claimant' => $service->setClaimantActive($companyId, (int)$request->input('claimant_id', 0), false),
                'create_claim' => $service->createClaim($companyId, [
                    'claimant_id' => (int)$request->input('claimant_id', 0),
                    'claim_year' => (int)$request->input('claim_year', 0),
                    'claim_month' => (int)$request->input('claim_month', 0),
                    'incorporation_date' => (string)$request->input('incorporation_date', ''),
                ]),
                'save_line' => $service->saveLine($companyId, (int)$request->input('claim_id', 0), [
                    'id' => (int)$request->input('line_id', 0),
                    'expense_date' => (string)$request->input('expense_date', ''),
                    'description' => (string)$request->input('description', ''),
                    'amount' => (string)$request->input('amount', ''),
                    'nominal_account_id' => (int)$request->input('nominal_account_id', 0),
                    'default_expense_nominal_id' => (int)$request->input('default_expense_nominal_id', 0),
                    'receipt_reference' => (string)$request->input('receipt_reference', ''),
                    'notes' => (string)$request->input('notes', ''),
                ]),
                'delete_line' => $service->deleteLine(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('line_id', 0)
                ),
                'select_claim', 'filter_claims' => ['success' => true],
                default => ['success' => false, 'errors' => ['Unknown expense action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $filters = $this->filtersFromRequest($request, $result);

        return new ActionResultFramework(
            !empty($result['success']),
            ['expense.claimants', 'expenses.state', 'expense.claim.editor'],
            $this->flashMessages($intent, $result),
            $filters,
            ['expense_filters' => $filters]
        );
    }

    private function filtersFromRequest(RequestFramework $request, array $result): array
    {
        $filters = [
            'query' => trim((string)$request->input('query', $request->input('expense_query', ''))),
            'status' => trim((string)$request->input('status', $request->input('expense_status', 'all'))),
            'claim_id' => max(0, (int)$request->input('claim_id', 0)),
            'claim_reference_code' => trim((string)$request->input('claim_reference_code', '')),
        ];

        if (isset($result['claim']) && is_array($result['claim'])) {
            $filters['claim_id'] = max(0, (int)($result['claim']['id'] ?? 0));
            $filters['claim_reference_code'] = '';
        }

        return array_filter(
            $filters,
            static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0
        );
    }

    private function flashMessages(string $intent, array $result): array
    {
        if (empty($result['success'])) {
            return array_map(
                static fn(mixed $error): array => ['type' => 'error', 'message' => (string)$error],
                (array)($result['errors'] ?? ['The expense action could not be completed.'])
            );
        }

        $messages = (array)($result['messages'] ?? []);
        if ($messages === []) {
            $messages[] = match ($intent) {
                'add_claimant' => 'Claimant saved.',
                'activate_claimant' => 'Claimant activated.',
                'deactivate_claimant' => 'Claimant deactivated.',
                'create_claim' => 'Expense claim opened.',
                'save_line' => 'Expense line saved.',
                'delete_line' => 'Expense line deleted.',
                default => '',
            };
        }

        return array_values(array_filter(array_map(
            static fn(mixed $message): array => ['type' => 'success', 'message' => (string)$message],
            $messages
        ), static fn(array $message): bool => trim((string)$message['message']) !== ''));
    }
}
