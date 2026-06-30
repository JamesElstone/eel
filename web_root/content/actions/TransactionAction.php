<?php
/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
declare(strict_types=1);

final class TransactionAction implements ActionInterfaceFramework
{
    private const TRANSACTIONS_IMPORTED_FACT = 'transactions.imported';
    private const IMPORTED_FILTER_SELECTION_SOURCE = 'transactions_imported_filters';

    private const TRANSACTION_ACTIONS = [
        'save_transaction_category',
        'defer_transaction',
    ];

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        if ($intent === '' && $this->positiveInt($request->input('rule_id', 0)) > 0) {
            $intent = 'edit_categorisation_rule';
        }

        return match ($intent) {
            'select_transaction_month',
            'edit_categorisation_rule',
            'cancel_categorisation_rule' => $this->selectionResult($request, $intent),
            'save_transaction_category',
            'defer_transaction' => $this->saveTransactionCategory($request, $services, $intent),
            'auto_create_transaction_rule' => $this->draftCategorisationRule($request, $services),
            'run_auto_rules' => $this->runAutoRules($request, $services),
            'post_categorised_transactions' => $this->postCategorisedTransactions($request, $services),
            'save_categorisation_rule' => $this->saveCategorisationRule($request, $services),
            'export_categorisation_rules' => $this->exportCategorisationRules($request, $services),
            'import_categorisation_rules' => $this->importCategorisationRules($request, $services),
            'delete_categorisation_rule' => $this->deleteCategorisationRule($request, $services),
            'toggle_categorisation_rule' => $this->toggleCategorisationRule($request, $services),
            'retry_receipt_download' => $this->retryReceiptDownload($request, $services),
            default => ActionResultFramework::none(),
        };
    }

    public static function withTransactionCardContext(
        RequestFramework $request,
        PageServiceFramework $services,
        array $pageContext,
        ActionResultFramework $actionResult
    ): array {
        $page = (array)($pageContext['page'] ?? []);
        if (!empty($page['transaction_card_context_ready'])) {
            return $pageContext;
        }

        $company = (array)($pageContext['company'] ?? []);
        $companyId = (int)($company['id'] ?? 0);
        $accountingPeriodId = (int)($company['accounting_period_id'] ?? 0);
        $dashboardRepository = self::service($services, \eel_accounts\Repository\DashboardRepository::class);
        $monthStatus = $companyId > 0 && $accountingPeriodId > 0
            ? self::service($services, \eel_accounts\Service\StatementUploadService::class)->buildMonthStatus($companyId, $accountingPeriodId)
            : [];
        $context = $actionResult->context();
        $monthKey = $dashboardRepository->normaliseTransactionMonthFilter((string)(
            $context['month_key']
            ?? $request->input('month_key', $request->query('month_key', ''))
        ));
        $monthKey = $monthKey !== '' ? $monthKey : $dashboardRepository->defaultTransactionMonth($monthStatus);
        $categoryFilter = $dashboardRepository->normaliseTransactionCategoryFilter((string)(
            $context['category_filter']
            ?? $request->input('category_filter', $request->query('category_filter', 'all'))
        ));
        $editingRuleId = max(0, (int)($context['editing_rule_id'] ?? 0));
        if ($editingRuleId <= 0 && isset($context['editing_rule_id'])) {
            $editingRuleId = 0;
        } elseif ($editingRuleId <= 0) {
            $editingRuleId = self::positiveInt($request->input('rule_id', $request->query('rule_id', 0)));
        }

        $pageContext['page'] = array_merge($page, [
            'transaction_card_context_ready' => true,
            'month_key' => $monthKey,
            'category_filter' => $categoryFilter,
            'selected_transaction_filter' => $categoryFilter,
            'editing_rule_id' => $editingRuleId,
        ]);

        foreach (['rule_form', 'rule_import_json'] as $key) {
            if (array_key_exists($key, $context)) {
                $pageContext['page'][$key] = $context[$key];
            }
        }

        return $pageContext;
    }

    private function selectionResult(RequestFramework $request, string $intent): ActionResultFramework
    {
        $context = $this->filterContext($request);

        if ($intent === 'edit_categorisation_rule') {
            $context['editing_rule_id'] = $this->positiveInt($request->input('rule_id', 0));
        } elseif ($intent === 'cancel_categorisation_rule') {
            $context['editing_rule_id'] = 0;
        }

        $changedFacts = $intent === 'select_transaction_month'
            && trim((string)$request->input('selection_source', '')) === self::IMPORTED_FILTER_SELECTION_SOURCE
                ? [self::TRANSACTIONS_IMPORTED_FACT]
                : ['page.context'];

        return ActionResultFramework::success($changedFacts, query: $this->filterQuery($request, $context), context: $context);
    }

    private function saveTransactionCategory(
        RequestFramework $request,
        PageServiceFramework $services,
        string $globalAction
    ): ActionResultFramework {
        $context = $this->filterContext($request);
        $flashMessages = [];
        $companyId = $this->selectedCompanyId($request);
        $defaultBankNominalId = $this->defaultBankNominalId($companyId);
        $transactionCategorisationService = self::service($services, \eel_accounts\Service\TransactionCategorisationService::class);
        $transactionJournalService = self::service($services, \eel_accounts\Service\TransactionJournalService::class);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $nominalAccountId = $this->nullablePositiveInt($request->post('nominal_account_id', null));
        $transferAccountId = $this->nullablePositiveInt($request->post('transfer_account_id', null));
        $confirmedJournalRebuild = $this->checkboxValue($request, 'confirm_rebuild_journal');
        $existingTransaction = $transactionCategorisationService->fetchTransaction($transactionId);
        $targetNominalAccountId = $globalAction === 'defer_transaction' ? null : $nominalAccountId;
        $targetTransferAccountId = $globalAction === 'defer_transaction' ? null : $transferAccountId;
        $targetAutoExcluded = $globalAction === 'defer_transaction';
        $isTransferTransaction = is_array($existingTransaction) && $this->transactionIsTransferMode($existingTransaction);
        $errors = [];

        if (!in_array($globalAction, self::TRANSACTION_ACTIONS, true)) {
            return ActionResultFramework::none();
        }

        if ($transactionId <= 0 || $existingTransaction === null) {
            $errors[] = 'Select a valid transaction before saving categorisation changes.';
        } elseif (
            $globalAction !== 'defer_transaction'
            && !$isTransferTransaction
            && ($targetNominalAccountId === null || $targetNominalAccountId <= 0)
        ) {
            $errors[] = 'Choose a nominal account before saving Manual or Auto categorisation.';
        } elseif (
            $globalAction !== 'defer_transaction'
            && $isTransferTransaction
            && ($targetTransferAccountId === null || $targetTransferAccountId <= 0)
        ) {
            $errors[] = 'Choose the matching owned bank or trade account before saving this transfer.';
        }

        if ($errors !== []) {
            return $this->result(false, $errors, $flashMessages, $context);
        }

        try {
            InterfaceDB::beginTransaction();

            $saveResult = $transactionCategorisationService->saveManualCategorisation(
                $transactionId,
                $targetNominalAccountId,
                $targetTransferAccountId,
                $targetAutoExcluded,
                'transactions_page',
                $confirmedJournalRebuild
            );

            if (!empty($saveResult['requires_confirmation'])) {
                InterfaceDB::rollBack();
                $errors[] = 'This transaction already has a derived journal. Tick confirm and save again to rebuild it.';
            } elseif (!empty($saveResult['errors'])) {
                InterfaceDB::rollBack();
                $errors = array_merge($errors, array_map('strval', (array)$saveResult['errors']));
            } else {
                if (!empty($saveResult['changed']) && !empty($saveResult['requires_journal_rebuild'])) {
                    $journalResult = $transactionJournalService->syncJournalForTransaction(
                        $transactionId,
                        $defaultBankNominalId,
                        'transactions_page',
                        true
                    );

                    if (!empty($journalResult['errors'])) {
                        throw new RuntimeException(implode(' ', array_map('strval', $journalResult['errors'])));
                    }

                    if (!empty($journalResult['removed'])) {
                        $flashMessages[] = 'Derived journal removed because the transaction is no longer categorised.';
                    } elseif (!empty($journalResult['rebuilt'])) {
                        $flashMessages[] = 'Derived journal rebuilt from the updated transaction.';
                    }
                }

                InterfaceDB::commit();

                if (!empty($saveResult['changed'])) {
                    $flashMessages[] = match ($globalAction) {
                        'defer_transaction' => 'Transaction deferred for manual review.',
                        'save_transaction_category' => $isTransferTransaction
                            ? 'Transfer account saved.'
                            : 'Manual categorisation saved.',
                        default => 'Manual categorisation saved.',
                    };
                } else {
                    $flashMessages[] = 'No categorisation change was needed.';
                }
            }
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            $errors[] = 'The transaction categorisation could not be saved: ' . $exception->getMessage();
        }

        return $this->result($errors === [], $errors, $flashMessages, $context);
    }

    private function draftCategorisationRule(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $nominalAccountId = $this->nullablePositiveInt($request->post('nominal_account_id', null));
        $transactionCategorisationService = self::service($services, \eel_accounts\Service\TransactionCategorisationService::class);
        $categorisationRuleService = self::service($services, \eel_accounts\Service\CategorisationRuleService::class);
        $existingTransaction = $transactionCategorisationService->fetchTransaction($transactionId);
        $isTransferTransaction = is_array($existingTransaction) && $this->transactionIsTransferMode($existingTransaction);
        $errors = [];

        if ($companyId <= 0) {
            $errors[] = 'Select a company before creating a categorisation rule from a transaction.';
        } elseif ($transactionId <= 0 || $existingTransaction === null) {
            $errors[] = 'Select a valid transaction before creating a categorisation rule.';
        } elseif ($isTransferTransaction) {
            $errors[] = 'Transfer rows cannot be turned into nominal auto-categorisation rules.';
        } elseif ($nominalAccountId === null || $nominalAccountId <= 0) {
            $errors[] = 'Choose a nominal account before creating a categorisation rule from a transaction.';
        }

        if ($errors !== []) {
            return $this->result(false, $errors, [], $context);
        }

        $draft = $categorisationRuleService->buildRuleDraftFromTransaction($transactionId, (int)$nominalAccountId);
        if ($draft === null) {
            return $this->result(false, ['The rule could not be created from the selected transaction.'], [], $context);
        }

        $context['rule_form'] = $draft;
        $context['editing_rule_id'] = 0;

        return $this->result(
            true,
            [],
            ['Automatic rule draft ready to review.'],
            $context,
            array_merge($this->filterQuery($request, $context), ['show_card' => 'transactions_rule_form'])
        );
    }

    private function runAutoRules(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);
        $defaultBankNominalId = $this->defaultBankNominalId($companyId);
        $autoScope = trim((string)$request->post('auto_scope', 'uncategorised')) === 'auto' ? 'auto' : 'uncategorised';
        $confirmAutoRebuild = $this->checkboxValue($request, 'confirm_rebuild_auto_journals');
        $errors = [];
        $flashMessages = [];

        if ($autoScope === 'auto' && !$confirmAutoRebuild) {
            $errors[] = 'Confirm the journal rebuild warning before re-running rules on existing auto-categorised transactions.';
        }

        if ($errors !== []) {
            return $this->result(false, $errors, [], $context);
        }

        try {
            InterfaceDB::beginTransaction();
            $batchResult = self::service($services, \eel_accounts\Service\TransactionCategorisationService::class)->applyAutoCategoryBatch(
                $companyId,
                $accountingPeriodId,
                $autoScope,
                $context['month_key'] !== '' ? $context['month_key'] : null,
                'transactions_page_auto'
            );

            if ($autoScope === 'auto') {
                $journalService = self::service($services, \eel_accounts\Service\TransactionJournalService::class);
                foreach ((array)($batchResult['changed_transaction_ids'] ?? []) as $changedTransactionId) {
                    $journalResult = $journalService->syncJournalForTransaction(
                        (int)$changedTransactionId,
                        $defaultBankNominalId,
                        'transactions_page_auto',
                        true
                    );

                    if (!empty($journalResult['errors'])) {
                        throw new RuntimeException(implode(' ', array_map('strval', $journalResult['errors'])));
                    }
                }
            }

            InterfaceDB::commit();
            $flashMessages[] = sprintf('%d transaction(s) updated by auto categorisation rules.', (int)($batchResult['changed'] ?? 0));

            if ($autoScope === 'auto' && (int)($batchResult['changed'] ?? 0) > 0) {
                $flashMessages[] = 'Derived journals were rebuilt wherever the rule outcome changed.';
            } elseif ((int)($batchResult['changed'] ?? 0) === 0) {
                $flashMessages[] = 'No transactions changed during the auto categorisation run.';
            }
        } catch (Throwable $exception) {
            if (InterfaceDB::inTransaction()) {
                InterfaceDB::rollBack();
            }

            $errors[] = 'The auto categorisation batch could not be completed: ' . $exception->getMessage();
        }

        return $this->result($errors === [], $errors, $flashMessages, $context);
    }

    private function postCategorisedTransactions(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);
        $defaultBankNominalId = $this->defaultBankNominalId($companyId);
        $errors = [];
        $flashMessages = [];

        $postResult = self::service($services, \eel_accounts\Service\TransactionJournalService::class)->postCategorisedTransactions(
            $companyId,
            $accountingPeriodId,
            $defaultBankNominalId,
            $context['month_key'] !== '' ? $context['month_key'] : null,
            'transactions_page_post'
        );

        $errors = array_merge($errors, array_map('strval', (array)($postResult['errors'] ?? [])));
        if (!empty($postResult['success'])) {
            $flashMessages[] = sprintf(
                'Posting complete: %d created, %d rebuilt, %d unchanged.',
                (int)($postResult['created'] ?? 0),
                (int)($postResult['rebuilt'] ?? 0),
                (int)($postResult['unchanged'] ?? 0)
            );
        }

        return $this->result($errors === [], $errors, $flashMessages, $context);
    }

    private function saveCategorisationRule(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $ruleId = $this->nullablePositiveInt($request->post('rule_id', null));
        $postData = $this->postArray($request);
        $saveResult = self::service($services, \eel_accounts\Service\CategorisationRuleService::class)->saveRule($companyId, $postData, $ruleId);
        $errors = array_map('strval', (array)($saveResult['errors'] ?? []));
        $flashMessages = [];

        if (!empty($saveResult['success'])) {
            $context['editing_rule_id'] = 0;
            $flashMessages[] = $ruleId !== null && $ruleId > 0
                ? 'Categorisation rule updated successfully.'
                : 'Categorisation rule created successfully.';
        } else {
            $context['rule_form'] = is_array($saveResult['rule'] ?? null) ? $saveResult['rule'] : [];
            $context['editing_rule_id'] = $ruleId !== null ? $ruleId : 0;
        }

        return $this->result($errors === [], $errors, $flashMessages, $context);
    }

    private function exportCategorisationRules(RequestFramework $request, PageServiceFramework $services): never
    {
        $companyId = $this->selectedCompanyId($request);
        $exportPayload = self::service($services, \eel_accounts\Service\CategorisationRuleService::class)->exportRules($companyId);
        $encoded = json_encode($exportPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            header('Content-Type: text/plain; charset=utf-8', true, 500);
            echo 'The categorisation rules could not be exported.';
            exit;
        }

        $fileName = sprintf(
            'transaction-rules-company-%d-%s.json',
            max(0, $companyId),
            (new DateTimeImmutable('now'))->format('Ymd-His')
        );

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        echo $encoded;
        exit;
    }

    private function importCategorisationRules(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $ruleImportJson = trim((string)$request->post('rules_import_json', ''));
        $importResult = self::service($services, \eel_accounts\Service\CategorisationRuleService::class)->importRules($companyId, $ruleImportJson);
        $errors = array_map('strval', (array)($importResult['errors'] ?? []));
        $flashMessages = [];
        $context['rule_import_json'] = $ruleImportJson;

        if ((int)($importResult['created'] ?? 0) > 0) {
            $flashMessages[] = sprintf(
                'Imported %d categorisation rule(s)%s.',
                (int)($importResult['created'] ?? 0),
                (int)($importResult['failed'] ?? 0) > 0
                    ? sprintf(' with %d skipped.', (int)($importResult['failed'] ?? 0))
                    : ''
            );

            if ((int)($importResult['failed'] ?? 0) === 0) {
                $context['rule_import_json'] = '';
            }
        } elseif ((int)($importResult['failed'] ?? 0) === 0) {
            $errors[] = 'No categorisation rules were imported.';
        }

        return $this->result($errors === [], $errors, $flashMessages, $context);
    }

    private function deleteCategorisationRule(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $ruleId = $this->positiveInt($request->post('rule_id', 0));

        if (self::service($services, \eel_accounts\Service\CategorisationRuleService::class)->deleteRule($this->selectedCompanyId($request), $ruleId)) {
            if ((int)($context['editing_rule_id'] ?? 0) === $ruleId) {
                $context['editing_rule_id'] = 0;
            }

            return $this->result(true, [], ['Categorisation rule deleted successfully.'], $context);
        }

        return $this->result(false, ['The categorisation rule could not be deleted.'], [], $context);
    }

    private function toggleCategorisationRule(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $ruleId = $this->positiveInt($request->post('rule_id', 0));
        $targetActive = $this->checkboxValue($request, 'target_is_active');

        if (self::service($services, \eel_accounts\Service\CategorisationRuleService::class)->setRuleActive($this->selectedCompanyId($request), $ruleId, $targetActive)) {
            return $this->result(true, [], [$targetActive ? 'Categorisation rule activated.' : 'Categorisation rule paused.'], $context);
        }

        return $this->result(false, ['The categorisation rule status could not be updated.'], [], $context);
    }

    private function retryReceiptDownload(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));

        try {
            $result = self::service($services, \eel_accounts\Service\ReceiptDownloadService::class)->downloadReceiptForTransaction($transactionId);
            if (!empty($result['success'])) {
                return $this->result(true, [], [(string)($result['message'] ?? 'Receipt download retried.')], $context);
            }

            return $this->result(false, [(string)($result['message'] ?? $result['error'] ?? 'The receipt could not be downloaded.')], [], $context);
        } catch (Throwable $exception) {
            return $this->result(false, ['The receipt could not be downloaded: ' . $exception->getMessage()], [], $context);
        }
    }

    private function filterContext(RequestFramework $request): array
    {
        $dashboardRepository = new \eel_accounts\Repository\DashboardRepository();

        return [
            'month_key' => $dashboardRepository->normaliseTransactionMonthFilter((string)$request->input('month_key', '')),
            'category_filter' => $dashboardRepository->normaliseTransactionCategoryFilter((string)$request->input('category_filter', 'all')),
            'editing_rule_id' => $this->positiveInt($request->input('rule_id', 0)),
        ];
    }

    private function filterQuery(RequestFramework $request, array $context): array
    {
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);

        return [
            'company_id' => $companyId > 0 ? $companyId : null,
            'accounting_period_id' => $accountingPeriodId > 0 ? $accountingPeriodId : null,
            'month_key' => (string)($context['month_key'] ?? '') !== '' ? (string)$context['month_key'] : null,
            'category_filter' => (string)($context['category_filter'] ?? '') !== '' ? (string)$context['category_filter'] : null,
            'rule_id' => (int)($context['editing_rule_id'] ?? 0) > 0 ? (int)$context['editing_rule_id'] : null,
        ];
    }

    private function result(bool $success, array $errors, array $messages, array $context, array $query = []): ActionResultFramework
    {
        $flashMessages = array_map(
            static fn(string $message): array => ['type' => 'error', 'message' => $message],
            array_values(array_filter(array_map('strval', $errors), static fn(string $message): bool => trim($message) !== ''))
        );

        foreach ($messages as $message) {
            $flashMessages[] = ['type' => 'success', 'message' => (string)$message];
        }

        return new ActionResultFramework($success, ['page.context'], $flashMessages, $query, $context);
    }

    private static function service(PageServiceFramework $services, string $className): object
    {
        return $services->get($className);
    }

    private function selectedCompanyId(RequestFramework $request): int
    {
        $context = new \eel_accounts\Service\AccountingContextService();

        return HelperFramework::sanitiseId($request->input('company_id', null), $context->companyId($request));
    }

    private function selectedAccountingPeriodId(RequestFramework $request): int
    {
        $context = new \eel_accounts\Service\AccountingContextService();

        return HelperFramework::sanitiseId($request->input('accounting_period_id', null), $context->accountingPeriodId($request));
    }

    private function defaultBankNominalId(int $companyId): int
    {
        if ($companyId <= 0) {
            return 0;
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        $value = trim((string)($settings['default_bank_nominal_id'] ?? ''));

        return ctype_digit($value) ? (int)$value : 0;
    }

    private function transactionIsTransferMode(array $transaction): bool
    {
        if ((int)($transaction['is_internal_transfer'] ?? 0) === 1) {
            return true;
        }

        if ((int)($transaction['transfer_account_id'] ?? 0) > 0) {
            return true;
        }

        $mode = strtolower(trim((string)($transaction['category_mode'] ?? $transaction['categorisation_mode'] ?? '')));
        if ($mode === 'transfer') {
            return true;
        }

        $subtype = strtolower(trim((string)($transaction['source_category'] ?? '')));
        return str_contains($subtype, 'transfer');
    }

    private function checkboxValue(RequestFramework $request, string $field): bool
    {
        return in_array(strtolower(trim((string)$request->post($field, ''))), ['1', 'true', 'on', 'yes'], true);
    }

    private static function positiveInt(mixed $value): int
    {
        $value = trim((string)$value);

        return ctype_digit($value) ? (int)$value : 0;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $intValue = self::positiveInt($value);

        return $intValue > 0 ? $intValue : null;
    }

    private function postArray(RequestFramework $request): array
    {
        $keys = [
            'priority',
            'rule_priority',
            'rule_desc_type',
            'rule_desc_value',
            'rule_ref_type',
            'rule_ref_value',
            'desc_match_type',
            'desc_match_value',
            'ref_match_type',
            'ref_match_value',
            'match_type',
            'match_value',
            'source_category_value',
            'source_account_value',
            'nominal_account_id',
            'is_active',
            'transaction_id',
        ];
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $request->post($key, null);
        }

        return $values;
    }
}
