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
                'bulk_save_lines' => $service->bulkSaveLines($companyId, (int)$request->input('claim_id', 0), [
                    'pasted_lines' => (string)$request->input('pasted_lines', ''),
                    'date_format' => (string)$request->input('date_format', 'd/m/Y'),
                ]),
                'update_line_nominal' => $service->updateLineNominal(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('line_id', 0),
                    (int)$request->input('nominal_account_id', 0)
                ),
                'update_line_type' => $service->updateLineType(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('line_id', 0),
                    (string)$request->input('line_type', 'expense')
                ),
                'save_line_asset_details' => $service->saveLineAssetDetails(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('line_id', 0),
                    [
                        'asset_category' => (string)$request->input('asset_category', ''),
                        'asset_description' => (string)$request->input('asset_description', ''),
                        'asset_useful_life_years' => (int)$request->input('asset_useful_life_years', 0),
                        'asset_depreciation_method' => (string)$request->input('asset_depreciation_method', ''),
                        'asset_residual_value' => (string)$request->input('asset_residual_value', ''),
                    ]
                ),
                'delete_line' => $service->deleteLine(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('line_id', 0)
                ),
                'delete_claim' => $service->deleteClaim(
                    $companyId,
                    (int)$request->input('claim_id', 0)
                ),
                'link_payment' => $service->linkPayment($companyId, (int)$request->input('claim_id', 0), [
                    'transaction_id' => (int)$request->input('transaction_id', 0),
                    'default_expense_nominal_id' => (int)$request->input('default_expense_nominal_id', 0),
                    'default_bank_nominal_id' => (int)$request->input('default_bank_nominal_id', 0),
                ]),
                'post_claim' => $service->postClaim($companyId, (int)$request->input('claim_id', 0), [
                    'default_expense_nominal_id' => (int)$request->input('default_expense_nominal_id', 0),
                ]),
                'unlink_payment' => $service->unlinkPayment(
                    $companyId,
                    (int)$request->input('claim_id', 0),
                    (int)$request->input('payment_link_id', 0)
                ),
                'select_claim', 'filter_claims' => ['success' => true],
                default => ['success' => false, 'errors' => ['Unknown expense action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        $filters = $this->filtersFromRequest($request, $result);

        $resultContext = ['expense_filters' => $filters];

        return new ActionResultFramework(
            !empty($result['success']),
            ['expense.claimants', 'expenses.state', 'expense.claim.editor'],
            $this->flashMessages($intent, $result),
            $filters,
            $resultContext
        );
    }

    private function filtersFromRequest(RequestFramework $request, array $result): array
    {
        $submittedHeatmapClaimantId = $request->input('claimant_id', null);
        $filters = [
            'query' => trim((string)$request->input('query', $request->input('expense_query', ''))),
            'status' => trim((string)$request->input('status', $request->input('expense_status', 'all'))),
            'claim_id' => max(0, (int)$request->input('claim_id', 0)),
            'claim_reference_code' => trim((string)$request->input('claim_reference_code', '')),
            'payment_query' => trim((string)$request->input('payment_query', '')),
            'heatmap_claimant_id' => max(0, (int)($submittedHeatmapClaimantId !== null ? $submittedHeatmapClaimantId : $request->input('expense_heatmap_claimant_id', 0))),
            'heatmap_period_start' => $this->normaliseHeatmapPeriodStart((string)$request->input('expense_heatmap_period_start', '')),
            'heatmap_date' => trim((string)$request->input('expense_heatmap_date', '')),
        ];

        if (isset($result['claim']) && is_array($result['claim'])) {
            $filters['claim_id'] = max(0, (int)($result['claim']['id'] ?? 0));
            $filters['claim_reference_code'] = '';
            $filters['heatmap_claimant_id'] = max(0, (int)($result['claim']['claimant_id'] ?? $filters['heatmap_claimant_id']));
            $filters['heatmap_date'] = '';
        }

        if (isset($result['deleted_claim_id'])) {
            $filters['claim_id'] = 0;
            $filters['claim_reference_code'] = '';
            $filters['heatmap_date'] = '';
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
                'bulk_save_lines' => 'Expense lines imported.',
                'update_line_nominal' => 'Line charge saved.',
                'update_line_type' => 'Line type saved.',
                'save_line_asset_details' => 'Asset details saved.',
                'delete_line' => 'Expense line deleted.',
                'delete_claim' => 'Expense claim deleted.',
                'link_payment' => 'Repayment linked.',
                'unlink_payment' => 'Repayment unlinked.',
                'post_claim' => 'Expense claim submitted.',
                default => '',
            };
        }

        return array_values(array_filter(array_map(
            static fn(mixed $message): array => ['type' => 'success', 'message' => (string)$message],
            $messages
        ), static fn(array $message): bool => trim((string)$message['message']) !== ''));
    }

    private function normaliseHeatmapPeriodStart(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return '';
        }

        return $parsed->format('Y-m-d');
    }
}
