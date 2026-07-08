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
    public const CATEGORISATION_SUMMARY_FACT = 'transactions.categorisation.summary';

    private const TRANSACTIONS_IMPORTED_FACT = 'transactions.imported';
    private const IMPORTED_FILTER_SELECTION_SOURCE = 'transactions_imported_filters';

    private const TRANSACTION_ACTIONS = [
        'save_transaction_category',
        'defer_transaction',
        'mark_director_loan',
        'toggle_inter_ac_transaction',
        'save_inter_ac_transaction',
        'cancel_inter_ac_transaction',
        'start_transaction_split',
        'add_transaction_split_line',
        'save_transaction_split_line',
        'defer_transaction_split_line',
        'remove_transaction_split_line',
        'merge_transaction_split',
    ];
    private const TRANSACTION_SPLIT_ACTIONS = [
        'start_transaction_split',
        'add_transaction_split_line',
        'save_transaction_split_line',
        'defer_transaction_split_line',
        'remove_transaction_split_line',
        'merge_transaction_split',
    ];

    public function handle(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $intent = trim((string)$request->input('intent', $request->input('global_action', '')));
        if ($intent === '' && $this->positiveInt($request->input('rule_id', 0)) > 0) {
            $intent = 'edit_categorisation_rule';
        }

        if ($this->requiresUnlockedPeriod($intent)
            && (new \eel_accounts\Service\YearEndLockService())->isLocked($this->selectedCompanyId($request), $this->selectedAccountingPeriodId($request))
        ) {
            $changedFacts = in_array($intent, self::TRANSACTION_SPLIT_ACTIONS, true)
                ? $this->changedFactsForTransactionSplitResult(false, $intent)
                : (in_array($intent, self::TRANSACTION_ACTIONS, true)
                ? $this->changedFactsForTransactionCategoryResult(false, $intent, false)
                : ['page.context']);

            return $this->result(
                false,
                ['This accounting period is locked, so transactions can be reviewed but not changed.'],
                [],
                $this->filterContext($request),
                [],
                $changedFacts
            );
        }

        return match ($intent) {
            'select_transaction_month',
            'edit_categorisation_rule',
            'cancel_categorisation_rule' => $this->selectionResult($request, $intent),
            'save_transaction_note' => $this->saveTransactionNote($request),
            'save_transaction_category',
            'defer_transaction',
            'mark_director_loan' => $this->saveTransactionCategory($request, $services, $intent),
            'toggle_inter_ac_transaction' => $this->toggleInterAccountTransaction($request, $services),
            'save_inter_ac_transaction' => $this->saveInterAccountTransaction($request, $services),
            'cancel_inter_ac_transaction' => $this->cancelInterAccountTransaction($request, $services),
            'start_transaction_split',
            'add_transaction_split_line',
            'save_transaction_split_line',
            'defer_transaction_split_line',
            'remove_transaction_split_line',
            'merge_transaction_split' => $this->saveTransactionSplit($request, $services, $intent),
            'auto_create_transaction_rule' => $this->draftCategorisationRule($request, $services),
            'run_auto_rules' => $this->runAutoRules($request, $services),
            'set_auto_approval_state',
            'sync_auto_approval_state',
            'toggle_auto_approval' => $this->setAutoApprovalState($request, $services),
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

    private function requiresUnlockedPeriod(string $intent): bool
    {
        return in_array($intent, [
            'save_transaction_note',
            'save_transaction_category',
            'defer_transaction',
            'mark_director_loan',
            'toggle_inter_ac_transaction',
            'save_inter_ac_transaction',
            'cancel_inter_ac_transaction',
            'start_transaction_split',
            'add_transaction_split_line',
            'save_transaction_split_line',
            'defer_transaction_split_line',
            'remove_transaction_split_line',
            'merge_transaction_split',
            'auto_create_transaction_rule',
            'run_auto_rules',
            'set_auto_approval_state',
            'sync_auto_approval_state',
            'toggle_auto_approval',
            'post_categorised_transactions',
        ], true);
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
        $context = $actionResult->context();
        $monthKey = $dashboardRepository->normaliseTransactionMonthFilter((string)(
            $context['month_key']
            ?? $request->input('month_key', $request->query('month_key', ''))
        ));
        $monthStatus = [];
        if ($monthKey === '' && $companyId > 0 && $accountingPeriodId > 0) {
            $monthStatus = self::service($services, \eel_accounts\Service\StatementUploadService::class)->buildMonthStatus($companyId, $accountingPeriodId);
        }
        $monthKey = $monthKey !== '' ? $monthKey : $dashboardRepository->defaultTransactionMonth($monthStatus);
        $categoryFilter = $dashboardRepository->normaliseTransactionCategoryFilter((string)(
            $context['category_filter']
            ?? $request->input('category_filter', $request->query('category_filter', 'not_posted'))
        ));
        $accountFilter = $dashboardRepository->normaliseTransactionAccountFilter(
            $context['account_filter']
            ?? $request->input('account_filter', $request->query('account_filter', 0))
        );
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
            'account_filter' => $accountFilter,
            'selected_account_filter' => $accountFilter,
            'editing_rule_id' => $editingRuleId,
            'inter_ac_transaction_id' => max(0, (int)($context['inter_ac_transaction_id'] ?? $request->input('inter_ac_transaction_id', 0))),
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

        $fromImportedFilters = $intent === 'select_transaction_month'
            && trim((string)$request->input('selection_source', '')) === self::IMPORTED_FILTER_SELECTION_SOURCE;
        $changedFacts = $fromImportedFilters ? [self::TRANSACTIONS_IMPORTED_FACT] : ['page.context'];
        $query = $this->filterQuery($request, $context);
        if ($fromImportedFilters) {
            $query['show_card'] = 'transactions_imported';
        }

        return ActionResultFramework::success($changedFacts, query: $query, context: $context);
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
        $interAccountMarker = $existingTransaction !== null
            ? self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class)->fetchMarkerForTransaction($transactionId)
            : null;
        $isDirectorLoanAction = $globalAction === 'mark_director_loan';
        $directorLoanResolution = $isDirectorLoanAction && is_array($existingTransaction)
            ? $this->directorLoanNominalResolution($companyId, $existingTransaction)
            : ['nominal_account_id' => null, 'error' => ''];
        $targetNominalAccountId = match ($globalAction) {
            'defer_transaction' => null,
            'mark_director_loan' => $directorLoanResolution['nominal_account_id'],
            default => $nominalAccountId,
        };
        $targetTransferAccountId = $globalAction === 'defer_transaction' || $isDirectorLoanAction ? null : $transferAccountId;
        $targetAutoExcluded = $globalAction === 'defer_transaction';
        $isTransferTransaction = is_array($existingTransaction) && $this->transactionIsTransferMode($existingTransaction);
        $errors = [];
        $categorisationChanged = false;

        if (!in_array($globalAction, self::TRANSACTION_ACTIONS, true)) {
            return ActionResultFramework::none();
        }

        if ($transactionId <= 0 || $existingTransaction === null) {
            $errors[] = 'Select a valid transaction before saving categorisation changes.';
        } elseif ($interAccountMarker !== null) {
            $errors[] = 'Cancel the inter-account match before changing this transaction categorisation.';
        } elseif ($isDirectorLoanAction && $isTransferTransaction) {
            $errors[] = 'Transfer rows cannot be marked as director loans.';
        } elseif ($isDirectorLoanAction && trim((string)($directorLoanResolution['error'] ?? '')) !== '') {
            $errors[] = (string)$directorLoanResolution['error'];
        } elseif (
            $globalAction !== 'defer_transaction'
            && !$isDirectorLoanAction
            && !$isTransferTransaction
            && ($targetNominalAccountId === null || $targetNominalAccountId <= 0)
        ) {
            $errors[] = 'Choose a nominal account before saving Manual or Auto categorisation.';
        } elseif (
            $globalAction !== 'defer_transaction'
            && !$isDirectorLoanAction
            && $isTransferTransaction
            && ($targetTransferAccountId === null || $targetTransferAccountId <= 0)
        ) {
            $errors[] = 'Choose the matching owned bank or trade account before saving this transfer.';
        }

        if ($errors !== []) {
            return $this->result(
                false,
                $errors,
                $flashMessages,
                $context,
                [],
                $this->changedFactsForTransactionCategoryResult(false, $globalAction, false)
            );
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
                $categorisationChanged = !empty($saveResult['changed']);

                if ($categorisationChanged) {
                    $flashMessages[] = match ($globalAction) {
                        'defer_transaction' => 'Transaction deferred for manual review.',
                        'save_transaction_category' => $isTransferTransaction
                            ? 'Transfer account saved.'
                            : 'Manual categorisation saved.',
                        'mark_director_loan' => 'Director loan categorisation saved.',
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

        return $this->result(
            $errors === [],
            $errors,
            $flashMessages,
            $context,
            [],
            $this->changedFactsForTransactionCategoryResult($errors === [], $globalAction, $categorisationChanged)
        );
    }

    private function changedFactsForTransactionCategoryResult(bool $success, string $globalAction, bool $changed): array
    {
        if ($globalAction !== 'save_transaction_category') {
            return ['page.context'];
        }

        if (!$success) {
            return [self::TRANSACTIONS_IMPORTED_FACT];
        }

        return $changed ? [self::CATEGORISATION_SUMMARY_FACT] : [];
    }

    private function toggleInterAccountTransaction(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $errors = [];
        $messages = [];
        $changedFacts = ['page.context', self::TRANSACTIONS_IMPORTED_FACT, self::CATEGORISATION_SUMMARY_FACT, 'year.end.checklist', 'year.end.state'];

        if ($transactionId <= 0) {
            $errors[] = 'Select a valid transaction before marking an inter-account transaction.';
        }

        if ($errors === []) {
            try {
                $markerService = self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class);
                $marker = $markerService->fetchMarkerForTransaction($transactionId);

                if ($marker !== null) {
                    return $this->cancelInterAccountTransaction($request, $services);
                } elseif ($this->checkboxValue($request, 'inter_ac_pending')) {
                    $context['inter_ac_transaction_id'] = 0;
                } else {
                    $context['inter_ac_transaction_id'] = $transactionId;
                }
            } catch (Throwable $exception) {
                $errors[] = 'The inter-account marker could not be changed: ' . $exception->getMessage();
            }
        }

        return $this->result($errors === [], $errors, $messages, $context, [], $changedFacts);
    }

    private function saveInterAccountTransaction(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $matchedTransactionId = $this->positiveInt($request->post('matched_transaction_id', 0));
        $errors = [];
        $messages = [];
        $changedFacts = ['page.context', self::TRANSACTIONS_IMPORTED_FACT, self::CATEGORISATION_SUMMARY_FACT, 'transaction.search', 'year.end.checklist', 'year.end.state'];

        if ($transactionId <= 0 || $matchedTransactionId <= 0) {
            $errors[] = 'Choose a valid inter-account transaction match.';
        }

        if ($errors === []) {
            try {
                $saveResult = self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class)->saveMarker(
                    $transactionId,
                    $matchedTransactionId,
                    'user:' . $this->currentUserId(),
                    'inter_ac_marker'
                );
                $errors = array_merge($errors, array_map('strval', (array)($saveResult['errors'] ?? [])));

                if ($errors === []) {
                    $context['inter_ac_transaction_id'] = 0;
                    $messages[] = 'Inter-account transaction match saved.';
                }
            } catch (Throwable $exception) {
                $errors[] = 'The inter-account transaction match could not be saved: ' . $exception->getMessage();
            }
        }

        return $this->result($errors === [], $errors, $messages, $context, [], $changedFacts);
    }

    private function cancelInterAccountTransaction(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $errors = [];
        $messages = [];
        $changedFacts = ['page.context', self::TRANSACTIONS_IMPORTED_FACT, self::CATEGORISATION_SUMMARY_FACT, 'transaction.search', 'year.end.checklist', 'year.end.state'];

        if ($transactionId <= 0) {
            $errors[] = 'Select a valid transaction before cancelling an inter-account match.';
        }

        if ($errors === []) {
            try {
                $clearMarkerResult = self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class)->clearMarkerForTransaction(
                    $transactionId,
                    'inter_ac_cancel'
                );
                $errors = array_merge($errors, array_map('strval', (array)($clearMarkerResult['errors'] ?? [])));

                if ($errors === [] && empty($clearMarkerResult['removed'])) {
                    $errors[] = 'No inter-account match needed cancelling.';
                }

                if ($errors === []) {
                    $context['inter_ac_transaction_id'] = 0;
                    $messages[] = 'Inter-account transaction match cancelled.';
                }
            } catch (Throwable $exception) {
                $errors[] = 'The inter-account transaction match could not be cancelled: ' . $exception->getMessage();
            }
        }

        return $this->result($errors === [], $errors, $messages, $context, [], $changedFacts);
    }

    private function saveTransactionSplit(
        RequestFramework $request,
        PageServiceFramework $services,
        string $globalAction
    ): ActionResultFramework {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $lineId = $this->positiveInt($request->post('transaction_split_line_id', 0));
        $confirmedJournalRebuild = $this->checkboxValue($request, 'confirm_rebuild_journal');
        $splitService = self::service($services, \eel_accounts\Service\TransactionSplitService::class);
        $errors = [];
        $messages = [];

        if ($transactionId > 0
            && self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class)->fetchMarkerForTransaction($transactionId) !== null
        ) {
            $errors[] = 'Cancel the inter-account match before changing this transaction split.';
        }

        try {
            $result = $errors !== []
                ? ['success' => false, 'errors' => $errors]
                : match ($globalAction) {
                    'start_transaction_split' => $splitService->startSplit($companyId, $transactionId),
                    'add_transaction_split_line' => $splitService->addLine($companyId, $transactionId),
                    'save_transaction_split_line' => $splitService->saveLine($companyId, $lineId, $this->postArray($request)),
                    'defer_transaction_split_line' => $splitService->deferLine($companyId, $lineId),
                    'remove_transaction_split_line' => $splitService->removeLine($companyId, $lineId),
                    'merge_transaction_split' => $splitService->mergeSplit($companyId, $transactionId, $confirmedJournalRebuild),
                    default => ['success' => false, 'errors' => ['Unknown transaction split action.']],
                };

            if (!empty($result['requires_confirmation'])) {
                $errors[] = 'This split transaction already has a derived journal. Tick confirm and save again to rebuild it.';
            } else {
                $resultErrors = array_map('strval', (array)($result['errors'] ?? []));
                $errors = array_values(array_unique(array_merge($errors, $resultErrors)));
                if (!empty($result['success'])) {
                    $messages = array_merge($messages, array_map('strval', (array)($result['messages'] ?? [])));
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'The transaction split could not be saved: ' . $exception->getMessage();
        }

        if ($globalAction === 'save_transaction_split_line' && $errors === []) {
            $messages = [];
        }

        return $this->result(
            $errors === [],
            $errors,
            $messages,
            $context,
            [],
            $this->changedFactsForTransactionSplitResult($errors === [], $globalAction)
        );
    }

    private function changedFactsForTransactionSplitResult(bool $success, string $globalAction): array
    {
        if ($globalAction === 'save_transaction_split_line') {
            return [self::TRANSACTIONS_IMPORTED_FACT, self::CATEGORISATION_SUMMARY_FACT];
        }

        if (!$success) {
            return [self::TRANSACTIONS_IMPORTED_FACT];
        }

        return match ($globalAction) {
            'start_transaction_split',
            'add_transaction_split_line',
            'defer_transaction_split_line',
            'remove_transaction_split_line',
            'merge_transaction_split' => [self::TRANSACTIONS_IMPORTED_FACT, self::CATEGORISATION_SUMMARY_FACT],
            default => ['page.context'],
        };
    }

    private function saveTransactionNote(RequestFramework $request): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $query = array_merge($this->filterQuery($request, $context), ['show_card' => 'transactions_imported']);
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);
        $transactionId = $this->positiveInt($request->post('transaction_id', 0));
        $notes = (string)$request->post('notes', '');
        $errors = [];

        if ($companyId <= 0 || $accountingPeriodId <= 0 || $transactionId <= 0) {
            $errors[] = 'Select a valid transaction before saving notes.';
        }

        $transaction = null;
        if ($errors === []) {
            $transaction = InterfaceDB::fetchOne(
                'SELECT id, company_id, accounting_period_id
                 FROM transactions
                 WHERE id = :transaction_id
                 LIMIT 1',
                ['transaction_id' => $transactionId]
            );

            if (!is_array($transaction)
                || (int)($transaction['company_id'] ?? 0) !== $companyId
                || (int)($transaction['accounting_period_id'] ?? 0) !== $accountingPeriodId
            ) {
                $errors[] = 'The selected transaction does not belong to the selected company and accounting period.';
            }
        }

        if ($errors === []) {
            try {
                (new \eel_accounts\Service\YearEndLockService())->assertUnlocked($companyId, $accountingPeriodId, 'change transaction notes in this period');
                InterfaceDB::prepareExecute(
                    'UPDATE transactions
                     SET notes = :notes,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :transaction_id',
                    [
                        'notes' => $notes,
                        'transaction_id' => $transactionId,
                    ]
                );
            } catch (Throwable $exception) {
                $errors[] = 'The transaction note could not be saved: ' . $exception->getMessage();
            }
        }

        return $this->result($errors === [], $errors, [], $context, $query, [self::TRANSACTIONS_IMPORTED_FACT]);
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
        } elseif (self::service($services, \eel_accounts\Service\TransactionInterAccountMarkerService::class)->fetchMarkerForTransaction($transactionId) !== null) {
            $errors[] = 'Cancel the inter-account match before creating a categorisation rule from this transaction.';
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

    private function setAutoApprovalState(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);
        $states = $this->autoApprovalStatesFromRequest($request);

        try {
            $result = self::service($services, \eel_accounts\Service\TransactionAutoApprovalService::class)->setTransactionApprovalStates(
                $companyId,
                $accountingPeriodId,
                $states,
                $this->currentUserId()
            );
        } catch (Throwable $exception) {
            $result = [
                'success' => false,
                'errors' => ['The auto approval could not be saved: ' . $exception->getMessage()],
            ];
        }

        return $this->result(
            !empty($result['success']),
            array_map('strval', (array)($result['errors'] ?? [])),
            !empty($result['success']) ? ['Auto approval updated.'] : [],
            $context,
            [],
            []
        );
    }

    private function postCategorisedTransactions(RequestFramework $request, PageServiceFramework $services): ActionResultFramework
    {
        $context = $this->filterContext($request);
        $companyId = $this->selectedCompanyId($request);
        $accountingPeriodId = $this->selectedAccountingPeriodId($request);
        $defaultBankNominalId = $this->defaultBankNominalId($companyId);
        $errors = [];
        $flashMessages = [];
        $approvalService = self::service($services, \eel_accounts\Service\TransactionAutoApprovalService::class);
        $postScope = strtolower(trim((string)$request->input('post_scope', 'month')));
        $isPeriodScope = $postScope === 'period';
        $monthKey = $isPeriodScope ? null : ($context['month_key'] !== '' ? $context['month_key'] : null);
        $pendingAutoApprovalCount = $approvalService->pendingPostConfirmationCount($companyId, $accountingPeriodId, $monthKey);

        if ($pendingAutoApprovalCount > 0 && !$this->checkboxValue($request, 'confirm_auto_categorisations')) {
            return $this->result(
                false,
                [sprintf('Confirm %d checked auto decision(s) before posting.', $pendingAutoApprovalCount)],
                [],
                $context
            );
        }

        $postResult = self::service($services, \eel_accounts\Service\TransactionJournalService::class)->postCategorisedTransactions(
            $companyId,
            $accountingPeriodId,
            $defaultBankNominalId,
            $monthKey,
            'transactions_page_post'
        );

        $errors = array_merge($errors, array_map('strval', (array)($postResult['errors'] ?? [])));
        if (!empty($postResult['success'])) {
            if ($pendingAutoApprovalCount > 0) {
                $confirmResult = $approvalService->confirmPostableAutoTransactions(
                    $companyId,
                    $accountingPeriodId,
                    $monthKey,
                    $this->currentUserId()
                );
                $errors = array_merge($errors, array_map('strval', (array)($confirmResult['errors'] ?? [])));
                if (!empty($confirmResult['success']) && (int)($confirmResult['confirmed'] ?? 0) > 0) {
                    $flashMessages[] = sprintf(
                        '%d checked auto decision(s) confirmed%s.',
                        (int)$confirmResult['confirmed'],
                        $isPeriodScope ? ' for the accounting period' : ''
                    );
                }
            }

            $flashMessages[] = sprintf(
                'Posting complete%s: %d created, %d rebuilt, %d unchanged.',
                $isPeriodScope ? ' for the accounting period' : '',
                (int)($postResult['created'] ?? 0),
                (int)($postResult['rebuilt'] ?? 0),
                (int)($postResult['unchanged'] ?? 0)
            );
        }

        return $this->result($errors === [], $errors, $flashMessages, $context, [], ['page.context', self::TRANSACTIONS_IMPORTED_FACT, 'transaction.search', 'year.end.checklist', 'year.end.state']);
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
            'category_filter' => $dashboardRepository->normaliseTransactionCategoryFilter((string)$request->input('category_filter', 'not_posted')),
            'account_filter' => $dashboardRepository->normaliseTransactionAccountFilter($request->input('account_filter', 0)),
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
            'account_filter' => (int)($context['account_filter'] ?? 0) > 0 ? (int)$context['account_filter'] : null,
            'rule_id' => (int)($context['editing_rule_id'] ?? 0) > 0 ? (int)$context['editing_rule_id'] : null,
        ];
    }

    private function result(bool $success, array $errors, array $messages, array $context, array $query = [], array $changedFacts = ['page.context']): ActionResultFramework
    {
        $flashMessages = array_map(
            static fn(string $message): array => ['type' => 'error', 'message' => $message],
            array_values(array_filter(array_map('strval', $errors), static fn(string $message): bool => trim($message) !== ''))
        );

        foreach ($messages as $message) {
            $flashMessages[] = ['type' => 'success', 'message' => (string)$message];
        }

        return new ActionResultFramework($success, $changedFacts, $flashMessages, $query, $context);
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

    private function currentUserId(): int
    {
        $sessionAuthenticationService = new SessionAuthenticationService();
        $sessionAuthenticationService->startSession();
        $currentDeviceId = trim((string)AntiFraudService::instance()->requestValue('Client-Device-ID'));

        return $sessionAuthenticationService->authenticatedUserId($currentDeviceId !== '' ? $currentDeviceId : null);
    }

    private function directorLoanNominalResolution(int $companyId, array $transaction): array
    {
        if ($companyId <= 0) {
            return [
                'nominal_account_id' => null,
                'error' => 'Select a company before marking director loan transactions.',
            ];
        }

        $amount = round((float)($transaction['amount'] ?? 0), 2);
        if (abs($amount) < 0.005) {
            return [
                'nominal_account_id' => null,
                'error' => 'Director loan shortcut requires a non-zero transaction amount.',
            ];
        }

        $settings = (new \eel_accounts\Store\CompanySettingsStore($companyId))->all();
        if ($amount < 0) {
            $nominalId = self::positiveInt($settings['director_loan_asset_nominal_id'] ?? '');

            return $nominalId > 0
                ? ['nominal_account_id' => $nominalId, 'error' => '']
                : [
                    'nominal_account_id' => null,
                    'error' => 'Set the Director Loan Asset nominal before marking an outgoing transaction as a director loan.',
                ];
        }

        $nominalId = self::positiveInt($settings['director_loan_liability_nominal_id'] ?? '');
        if ($nominalId <= 0) {
            $nominalId = self::positiveInt($settings['director_loan_nominal_id'] ?? '');
        }

        return $nominalId > 0
            ? ['nominal_account_id' => $nominalId, 'error' => '']
            : [
                'nominal_account_id' => null,
                'error' => 'Set the Director Loan Liability nominal before marking an incoming transaction as a director loan.',
            ];
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

    private function autoApprovalStatesFromRequest(RequestFramework $request): array
    {
        $ids = $request->post('auto_approval_transaction_ids', []);
        $values = $request->post('auto_approval_correct_values', []);

        if (!is_array($ids) || !is_array($values)) {
            $transactionId = $this->positiveInt($request->post('transaction_id', 0));

            return $transactionId > 0
                ? [$transactionId => $this->checkboxValue($request, 'auto_approval_correct')]
                : [];
        }

        $states = [];
        foreach (array_values($ids) as $index => $id) {
            $transactionId = $this->positiveInt($id);
            if ($transactionId <= 0) {
                continue;
            }

            $states[$transactionId] = in_array(
                strtolower(trim((string)($values[$index] ?? '0'))),
                ['1', 'true', 'on', 'yes'],
                true
            );
        }

        return $states;
    }

    private static function positiveInt(mixed $value): int
    {
        if (!is_scalar($value) && $value !== null) {
            return 0;
        }

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
            'split_line_description',
            'split_line_amount',
            'split_line_notes',
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
