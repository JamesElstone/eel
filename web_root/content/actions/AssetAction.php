<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class AssetAction implements ActionInterfaceFramework
{
    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = new \eel_accounts\Service\AccountingContextService();
        $companyId = HelperFramework::sanitiseId($request->input('company_id', null), $context->companyId($request));
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        $service = new \eel_accounts\Service\AssetService();
        $isDisposalUiNoop = $this->isDisposalUiNoopSubmission($request, $intent);

        $searchContext = in_array($intent, ['set_asset_disposal_method', 'search_asset_disposal_receipts', 'dispose_asset_with_transaction', 'dispose_asset_nil'], true) || $isDisposalUiNoop
            ? $this->searchContext($request, $isDisposalUiNoop ? 'set_asset_disposal_method' : $intent)
            : [];

        try {
            if ($intent === 'save_potential_asset_threshold') {
                (new \eel_accounts\Service\AccountingPeriodAccessService())->assertDataEntryPermitted(
                    $companyId,
                    (int)$request->input('accounting_period_id', 0),
                    'change potential asset review settings for this period'
                );
            }

            $result = match ($intent) {
                'create_asset_from_transaction' => $service->createAssetFromTransaction(
                    $companyId,
                    (int)$request->input('transaction_id', 0),
                    $request->postValues(),
                    (int)$request->input('default_bank_nominal_id', 0)
                ),
                'create_asset_from_transaction_split_line' => $service->createAssetFromTransactionSplitLine(
                    $companyId,
                    (int)$request->input('transaction_split_line_id', 0),
                    $request->postValues(),
                    (int)$request->input('default_bank_nominal_id', 0)
                ),
                'convert_non_asset_to_asset' => $service->convertNonAssetToAsset(
                    $companyId,
                    (string)$request->input('source_type', ''),
                    (int)$request->input('source_id', 0),
                    $request->postValues(),
                    (int)$request->input('default_bank_nominal_id', 0)
                ),
                'create_manual_asset' => $service->createManualAsset(
                    $companyId,
                    (int)$request->input('accounting_period_id', 0),
                    $request->postValues(),
                    (int)$request->input('offset_nominal_id', 0),
                    (array)($request->files()['manual_asset_evidence'] ?? [])
                ),
                'set_asset_disposal_method', 'search_asset_disposal_receipts' => ['success' => true],
                'reconcile_manual_asset_with_transaction' => $service->reconcileManualAssetWithTransaction(
                    $companyId,
                    (int)$request->input('asset_id', 0),
                    (int)$request->input('transaction_id', 0),
                    (int)$request->input('default_bank_nominal_id', 0),
                    $this->truthy($request->input('confirm_rebuild_journal', '0'))
                ),
                'dispose_asset_with_transaction' => $service->disposeAssetWithTransaction(
                    $companyId,
                    (int)$request->input('asset_id', 0),
                    (int)$request->input('transaction_id', 0),
                    (int)$request->input('default_bank_nominal_id', 0)
                ),
                'dispose_asset_nil' => $service->disposeAssetAtNilValue(
                    $companyId,
                    (int)$request->input('asset_id', 0),
                    (string)$request->input('disposal_date', $request->input('disposal_search_date', '')),
                    (string)$request->input('disposal_event_type', ''),
                    (string)$request->input('disposal_reason', '')
                ),
                'save_potential_asset_threshold' => $service->savePotentialAssetThreshold(
                    $companyId,
                    $request->input('potential_asset_threshold', 250)
                ),
                '' => $isDisposalUiNoop ? ['success' => true] : ['success' => false, 'errors' => ['Unknown asset action.']],
                default => ['success' => false, 'errors' => ['Unknown asset action.']],
            };
        } catch (Throwable $exception) {
            $result = ['success' => false, 'errors' => [$exception->getMessage()]];
        }

        if (in_array($intent, ['dispose_asset_with_transaction', 'dispose_asset_nil'], true) && !empty($result['success'])) {
            $searchContext = [];
        }

        return new ActionResultFramework(
            !empty($result['success']),
            ['asset.create', 'asset.reconcile_manual', 'asset.register', 'asset.tax', 'asset.not_an_asset', 'expense.claim.editor', 'expenses.state', 'transactions.imported', 'page.context', 'year.end.checklist'],
            $this->flashMessages($intent, $result),
            $searchContext,
            $searchContext
        );
    }

    private function searchContext(RequestFramework $request, string $intent): array
    {
        $searchDate = trim((string)$request->input('disposal_search_date', $request->input('disposal_date', '')));
        $assetId = max(0, (int)$request->input('asset_id', $request->input('asset_disposal_search_asset_id', 0)));
        $context = [
            'asset_disposal_method_asset_id' => max(0, (int)$request->input('asset_disposal_method_asset_id', $assetId)),
            'asset_disposal_method' => $this->normaliseDisposalMethod((string)$request->input('asset_disposal_method', '')),
        ];

        if ($intent === 'set_asset_disposal_method') {
            return array_filter($context, static fn(mixed $value): bool => $value !== '' && $value !== 0);
        }

        $context['asset_disposal_search_date'] = $searchDate;
        $context['asset_disposal_search_asset_id'] = $assetId;

        return array_filter($context, static fn(mixed $value): bool => $value !== '' && $value !== 0);
    }

    private function normaliseDisposalMethod(string $method): string
    {
        return in_array($method, ['sell_asset', 'at_nil_value'], true) ? $method : '';
    }

    private function isDisposalUiNoopSubmission(RequestFramework $request, string $intent): bool
    {
        if ($intent !== '') {
            return false;
        }

        $assetId = max(0, (int)$request->input('asset_id', $request->input('asset_disposal_method_asset_id', 0)));
        if ($assetId <= 0) {
            return false;
        }

        return $this->normaliseDisposalMethod((string)$request->input('asset_disposal_method', '')) !== '';
    }

    private function flashMessages(string $intent, array $result): array
    {
        if (empty($result['success'])) {
            return array_map(
                static fn(mixed $error): array => ['type' => 'error', 'message' => (string)$error],
                (array)($result['errors'] ?? ['The asset action could not be completed.'])
            );
        }

        $messages = (array)($result['messages'] ?? []);
        if ($messages === []) {
            $messages[] = match ($intent) {
                'save_potential_asset_threshold' => 'Potential asset threshold saved.',
                'convert_non_asset_to_asset' => 'Non-asset converted to an asset.',
                default => '',
            };
        }

        return array_values(array_filter(array_map(
            static fn(mixed $message): array => ['type' => 'success', 'message' => (string)$message],
            $messages
        ), static fn(array $message): bool => trim((string)$message['message']) !== ''));
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}
