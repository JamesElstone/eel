/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
(() => {
    let activeManualAssetWarningButton = null;

    function clearManualAssetWarning(refocus = false) {
        document.querySelectorAll('.manual-asset-warning-backdrop').forEach((node) => node.remove());
        document.querySelectorAll('.manual-asset-warning-window').forEach((node) => node.remove());

        if (activeManualAssetWarningButton instanceof HTMLButtonElement) {
            if (refocus && activeManualAssetWarningButton.isConnected) {
                activeManualAssetWarningButton.focus();
            }
            activeManualAssetWarningButton = null;
        }
    }

    function showManualAssetWarning(form, submitter) {
        clearManualAssetWarning(false);

        const backdrop = document.createElement('div');
        backdrop.className = 'chicken-check-backdrop manual-asset-warning-backdrop';

        const windowShell = document.createElement('div');
        windowShell.className = 'warn chicken-check-window manual-asset-warning-window';
        windowShell.setAttribute('role', 'alertdialog');

        const title = document.createElement('div');
        title.className = 'chicken-check-title';
        title.textContent = String(submitter.dataset.manualAssetWarningTitle || 'Manual asset legal warning');

        const message = document.createElement('div');
        message.className = 'chicken-check-message';
        message.textContent = String(submitter.dataset.manualAssetWarningMessage || '');

        const actions = document.createElement('div');
        actions.className = 'chicken-check-actions';

        const confirm = document.createElement('button');
        confirm.className = 'button danger';
        confirm.type = 'button';
        confirm.textContent = String(submitter.dataset.manualAssetWarningConfirmText || 'Acknowledge and Post');
        confirm.addEventListener('click', () => {
            const acknowledged = form.querySelector('[data-manual-asset-legal-acknowledged]');
            if (acknowledged instanceof HTMLInputElement) {
                acknowledged.value = '1';
            }
            clearManualAssetWarning(false);
            form.requestSubmit(submitter);
        });

        const cancel = document.createElement('button');
        cancel.className = 'button button-inline';
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => clearManualAssetWarning(true));

        actions.append(confirm, cancel);
        windowShell.append(title, message, actions);

        activeManualAssetWarningButton = submitter;
        document.body.appendChild(backdrop);
        document.body.appendChild(windowShell);
        confirm.focus();
    }

    function initialiseManualAssetLegalWarnings(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-manual-asset-form="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.manualAssetWarningBound === '1') {
                return;
            }

            form.dataset.manualAssetWarningBound = '1';
            form.addEventListener('submit', (event) => {
                const submitter = event.submitter;
                if (!(submitter instanceof HTMLButtonElement) || submitter.dataset.manualAssetLegalCheck !== 'true') {
                    return;
                }

                const acknowledged = form.querySelector('[data-manual-asset-legal-acknowledged]');
                if (acknowledged instanceof HTMLInputElement && acknowledged.value === '1') {
                    return;
                }

                event.preventDefault();
                showManualAssetWarning(form, submitter);
            });
        });
    }

    function initialiseDirectorLoanOffsetAcknowledgements(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-director-loan-offset-ack-form="true"], [data-year-end-ack-form="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const checkbox = form.querySelector('[data-director-loan-offset-ack-checkbox], [data-year-end-ack-checkbox]');
            const submitButton = form.querySelector('[data-director-loan-offset-ack-submit], [data-year-end-ack-submit]');

            if (!(checkbox instanceof HTMLInputElement) || !(submitButton instanceof HTMLButtonElement)) {
                return;
            }

            const syncAcknowledgement = () => {
                submitButton.disabled = !checkbox.checked;
            };

            if (form.dataset.directorLoanOffsetAckBound !== '1') {
                checkbox.addEventListener('change', syncAcknowledgement);
                form.dataset.directorLoanOffsetAckBound = '1';
            }

            syncAcknowledgement();
        });
    }

    function setUploadProcessingState(form, isProcessing) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitButton = form.querySelector('[data-upload-submit]');
        const processingIcon = form.querySelector('[data-upload-processing-icon]');

        if (isProcessing && submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = true;
        }

        if (processingIcon instanceof HTMLElement) {
            processingIcon.classList.toggle('is-hidden', !isProcessing);
        }
    }

    function initialiseUploadProcessingIndicators(root = document) {
        const dropzones = root.querySelectorAll ? root.querySelectorAll('[data-upload-dropzone]') : [];

        dropzones.forEach((dropzone) => {
            if (!(dropzone instanceof HTMLElement)) {
                return;
            }

            const form = dropzone.closest('form');
            if (!(form instanceof HTMLFormElement) || form.dataset.uploadProcessingBound === '1') {
                return;
            }

            const input = form.querySelector('[data-upload-input]');
            const accountSelect = form.querySelector('#upload_account_id');

            form.dataset.uploadProcessingBound = '1';

            const clearProcessing = () => setUploadProcessingState(form, false);

            if (input instanceof HTMLInputElement) {
                input.addEventListener('change', clearProcessing);
            }

            if (accountSelect instanceof HTMLSelectElement) {
                accountSelect.addEventListener('change', clearProcessing);
            }

            form.addEventListener('submit', (event) => {
                const maxFiles = Number(dropzone.dataset.uploadMaxFiles || '12');

                if (accountSelect instanceof HTMLSelectElement && !accountSelect.value) {
                    clearProcessing();
                    return;
                }

                if (input instanceof HTMLInputElement && input.files && input.files.length > maxFiles) {
                    clearProcessing();
                    return;
                }

                if (!event.defaultPrevented) {
                    setUploadProcessingState(form, true);
                }
            });
        });
    }

    function checkedVatRegisteredValue(form) {
        const checked = form.querySelector('[data-vat-registered-control]:checked');

        return checked instanceof HTMLInputElement ? checked.value : '0';
    }

    function vatValidationHash(countryValue, numberValue) {
        const country = String(countryValue || '').trim().toUpperCase();
        const number = String(numberValue || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');

        return `${country}:${number}`;
    }

    function initialiseVatRegistrationForms(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-vat-registration-form]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const country = form.querySelector('[data-vat-country-code]');
            const number = form.querySelector('[data-vat-number]');
            const panel = form.querySelector('[data-vat-fields]');
            const saveButton = form.querySelector('[data-vat-save-button]');
            const checkButton = form.querySelector('[data-vat-check-button]');
            const registeredControls = Array.from(form.querySelectorAll('[data-vat-registered-control]'))
                .filter((control) => control instanceof HTMLInputElement);

            const initialRegistered = registeredControls.length > 0
                ? String(registeredControls[0].dataset.vatInitialValue || '0')
                : '0';
            const initialCountry = country instanceof HTMLSelectElement
                ? String(country.dataset.vatInitialValue || '')
                : '';
            const initialNumber = number instanceof HTMLInputElement
                ? String(number.dataset.vatInitialValue || '')
                : '';
            const validationStatus = saveButton instanceof HTMLButtonElement
                ? String(saveButton.dataset.vatValidationStatus || '')
                : '';
            const validatedHash = saveButton instanceof HTMLButtonElement
                ? String(saveButton.dataset.vatValidatedHash || '')
                : '';

            const sync = () => {
                const registered = checkedVatRegisteredValue(form) === '1';
                const countryValue = country instanceof HTMLSelectElement ? String(country.value || '') : '';
                const numberValue = number instanceof HTMLInputElement ? String(number.value || '').trim() : '';
                const hasMatchedVatValidation = validationStatus === 'valid'
                    && vatValidationHash(countryValue, numberValue) === validatedHash;

                if (panel instanceof HTMLElement) {
                    panel.classList.toggle('is-hidden', !registered);
                }

                if (checkButton instanceof HTMLButtonElement) {
                    checkButton.disabled = !registered || countryValue === '' || numberValue === '';
                }

                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.disabled = registered
                        ? !hasMatchedVatValidation
                        : checkedVatRegisteredValue(form) === initialRegistered
                            && countryValue === initialCountry
                            && numberValue === initialNumber;
                }
            };

            sync();

            if (form.dataset.vatRegistrationBound === '1') {
                return;
            }

            form.dataset.vatRegistrationBound = '1';
            registeredControls.forEach((control) => control.addEventListener('change', sync));

            if (country instanceof HTMLSelectElement) {
                country.addEventListener('change', sync);
            }

            if (number instanceof HTMLInputElement) {
                number.addEventListener('input', sync);
                number.addEventListener('change', sync);
            }
        });
    }

    function initialiseStatementMappingForms(root = document) {
        const selectors = root.querySelectorAll ? root.querySelectorAll('[data-statement-mapping-account-selector]') : [];

        selectors.forEach((selector) => {
            if (!(selector instanceof HTMLSelectElement)) {
                return;
            }

            const form = selector.closest('form');
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const sync = () => {
                const uploadIdInput = form.querySelector('input[name="upload_id"]');
                const uploadId = uploadIdInput instanceof HTMLInputElement
                    ? Number.parseInt(String(uploadIdInput.value || '0'), 10)
                    : 0;
                const enabled = String(selector.value || '').trim() !== '' && uploadId > 0;

                form.querySelectorAll('[data-statement-mapping-requires-account]').forEach((control) => {
                    if (
                        control instanceof HTMLButtonElement
                        || control instanceof HTMLInputElement
                        || control instanceof HTMLSelectElement
                        || control instanceof HTMLTextAreaElement
                    ) {
                        control.disabled = !enabled;
                    }
                });

                return enabled;
            };

            sync();

            if (selector.dataset.statementMappingBound === '1') {
                return;
            }

            selector.dataset.statementMappingBound = '1';
            selector.addEventListener('change', () => {
                if (sync()) {
                    form.requestSubmit();
                }
            });
        });
    }

    function formForControl(control) {
        const formId = String(control.getAttribute('form') || '').trim();
        if (formId !== '') {
            const form = document.getElementById(formId);
            return form instanceof HTMLFormElement ? form : null;
        }

        return control.closest('form');
    }

    function initialiseTransactionCategorisationAutosave(root = document) {
        const controls = root.querySelectorAll ? root.querySelectorAll('[data-autosave-submit-target]') : [];

        controls.forEach((control) => {
            if (
                !(
                    control instanceof HTMLInputElement
                    || control instanceof HTMLSelectElement
                    || control instanceof HTMLTextAreaElement
                )
            ) {
                return;
            }

            if (control.dataset.autosaveBound === '1') {
                return;
            }

            control.dataset.autosaveBound = '1';
            control.addEventListener('change', () => {
                if (control.dataset.autosaveRequireValue === '1' && String(control.value || '').trim() === '') {
                    return;
                }
                if (Object.prototype.hasOwnProperty.call(control.dataset, 'initialValue')
                    && String(control.value || '') === String(control.dataset.initialValue || '')
                ) {
                    return;
                }

                const form = formForControl(control);
                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                const submitSelector = String(control.dataset.autosaveSubmitTarget || '').trim();
                if (submitSelector === '') {
                    return;
                }

                const submitter = form.querySelector(submitSelector);
                if (!(submitter instanceof HTMLButtonElement) || submitter.disabled) {
                    return;
                }

                form.requestSubmit(submitter);
            });
        });
    }

    function initialiseVehicleRows(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-vehicle-row="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.vehicleRowBound === '1') {
                return;
            }

            form.dataset.vehicleRowBound = '1';
            const formId = form.id;
            const saveButton = document.querySelector(`[data-vehicle-save][form="${CSS.escape(formId)}"]`);
            const controls = Array.from(document.querySelectorAll(`[data-vehicle-watch][form="${CSS.escape(formId)}"], #${CSS.escape(formId)} [data-vehicle-watch]`));

            const markDirty = () => {
                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.disabled = false;
                }
            };

            controls.forEach((control) => {
                control.addEventListener('change', markDirty);
                control.addEventListener('input', markDirty);
            });
        });
    }

    const autoApprovalBatchState = new WeakMap();
    const autoApprovalDebounceMs = 180;
    const autoApprovalCompletionTimeoutMs = 5000;

    function autoApprovalFormState(form) {
        let state = autoApprovalBatchState.get(form);
        if (!state) {
            state = {
                pending: new Map(),
                timerId: 0,
                inFlight: false,
            };
            autoApprovalBatchState.set(form, state);
        }

        return state;
    }

    function autoApprovalControlsForTransaction(transactionId) {
        return Array.from(document.querySelectorAll('[data-auto-approval-control="true"]'))
            .filter((control) => control instanceof HTMLInputElement
                && String(control.dataset.autoApprovalTransactionId || '').trim() === String(transactionId));
    }

    function autoApprovalStatusForControl(control) {
        const item = control.closest('[data-auto-approval-item="true"]');
        const status = item instanceof HTMLElement ? item.querySelector('[data-auto-approval-status]') : null;

        return status instanceof HTMLElement ? status : null;
    }

    function autoApprovalDecisionLabel(control) {
        return control.checked ? 'Correct' : 'Unconfirmed';
    }

    function setAutoApprovalStatus(controls, message) {
        controls.forEach((control) => {
            const status = autoApprovalStatusForControl(control);
            if (status instanceof HTMLElement) {
                status.dataset.autoApprovalDefaultStatus = autoApprovalDecisionLabel(control);
                status.textContent = message !== ''
                    ? message
                    : String(status.dataset.autoApprovalDefaultStatus || 'Unconfirmed');
            }
        });
    }

    function autoApprovalCardForElement(element) {
        const card = element instanceof Element ? element.closest('.card[data-card-key]') : null;

        return card instanceof HTMLElement ? card : null;
    }

    function autoApprovalPostForms(root = document) {
        const selector = 'form[data-transactions-imported-post-form="true"]';
        if (root instanceof HTMLFormElement && root.matches(selector)) {
            return [root];
        }

        return root && typeof root.querySelectorAll === 'function'
            ? Array.from(root.querySelectorAll(selector)).filter((form) => form instanceof HTMLFormElement)
            : [];
    }

    function autoApprovalSavePendingForCard(card) {
        if (!(card instanceof HTMLElement)) {
            return false;
        }

        return Array.from(card.querySelectorAll('form[data-auto-approval-batch-form="true"]')).some((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return false;
            }

            const state = autoApprovalBatchState.get(form);

            return Boolean(state && (state.inFlight || state.pending.size > 0 || state.timerId));
        });
    }

    function visibleAutoApprovalControlsForCard(card) {
        const controlsByTransaction = new Map();

        if (!(card instanceof HTMLElement)) {
            return [];
        }

        card.querySelectorAll('[data-auto-approval-control="true"]').forEach((control) => {
            if (!(control instanceof HTMLInputElement)) {
                return;
            }

            const transactionId = String(control.dataset.autoApprovalTransactionId || '').trim();
            if (transactionId === '' || controlsByTransaction.has(transactionId)) {
                return;
            }

            controlsByTransaction.set(transactionId, control);
        });

        return Array.from(controlsByTransaction.values());
    }

    function currentAutoApprovalPending(control) {
        if (!(control instanceof HTMLInputElement) || !control.checked) {
            return false;
        }

        return control.dataset.autoApprovalPendingInitial === '1'
            || control.dataset.autoApprovalDirty === '1'
            || control.dataset.autoApprovalConfirmedInitial !== '1';
    }

    function pendingAutoApprovalCountForCard(card, initialPendingCount) {
        let count = Math.max(0, Number.parseInt(String(initialPendingCount || '0'), 10) || 0);

        visibleAutoApprovalControlsForCard(card).forEach((control) => {
            const wasPending = control.dataset.autoApprovalPendingInitial === '1';
            const isPending = currentAutoApprovalPending(control);

            if (isPending && !wasPending) {
                count += 1;
            } else if (!isPending && wasPending) {
                count -= 1;
            }
        });

        return Math.max(0, count);
    }

    function setAutoApprovalPostButtonSaving(button, isSaving) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(button.dataset, 'autoApprovalOriginalTitle')) {
            button.dataset.autoApprovalOriginalTitle = button.getAttribute('title') || '';
        }

        button.disabled = isSaving;
        if (isSaving) {
            button.title = 'Saving auto decisions...';
            return;
        }

        const originalTitle = String(button.dataset.autoApprovalOriginalTitle || '');
        if (originalTitle !== '') {
            button.title = originalTitle;
        } else {
            button.removeAttribute('title');
        }
    }

    function syncTransactionsImportedPostConfirmationState(root = document) {
        autoApprovalPostForms(root).forEach((form) => {
            const card = autoApprovalCardForElement(form);
            const button = form.querySelector('[data-post-categorised-transactions-button="true"]');
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            const pendingCount = pendingAutoApprovalCountForCard(card, form.dataset.initialPendingAutoApprovalCount);
            const isSaving = autoApprovalSavePendingForCard(card);

            delete button.dataset.chickenArmed;
            if (pendingCount > 0) {
                button.dataset.chickenCheck = 'true';
                button.dataset.chickenTitle = String(button.dataset.autoApprovalConfirmTitle || 'Confirm checked auto decisions');
                button.dataset.chickenMessage = String(button.dataset.autoApprovalConfirmMessageTemplate || '')
                    .replace('{count}', String(pendingCount));
                button.dataset.chickenConfirmText = String(button.dataset.autoApprovalConfirmText || 'Post Transactions');
                button.dataset.chickenButtonClass = String(button.dataset.autoApprovalConfirmButtonClass || 'button primary');
                button.dataset.submitField = 'confirm_auto_categorisations';
                button.dataset.submitValue = '1';
            } else {
                delete button.dataset.chickenCheck;
                delete button.dataset.chickenTitle;
                delete button.dataset.chickenMessage;
                delete button.dataset.chickenConfirmText;
                delete button.dataset.chickenButtonClass;
                delete button.dataset.submitField;
                delete button.dataset.submitValue;
            }

            setAutoApprovalPostButtonSaving(button, isSaving);
        });
    }

    function syncAutoApprovalDuplicateControls(transactionId, checked) {
        autoApprovalControlsForTransaction(transactionId).forEach((control) => {
            control.checked = checked;
        });
    }

    function autoApprovalBatchFormForControl(control) {
        const card = control.closest('.card[data-card-key]');
        const form = card instanceof HTMLElement
            ? card.querySelector('form[data-auto-approval-batch-form="true"]')
            : null;

        return form instanceof HTMLFormElement ? form : null;
    }

    function replaceAutoApprovalBatchInputs(form, entries) {
        form.querySelectorAll('[data-auto-approval-batch-dynamic="true"]').forEach((node) => node.remove());

        entries.forEach((entry) => {
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'auto_approval_transaction_ids[]';
            idInput.value = entry.transactionId;
            idInput.dataset.autoApprovalBatchDynamic = 'true';

            const valueInput = document.createElement('input');
            valueInput.type = 'hidden';
            valueInput.name = 'auto_approval_correct_values[]';
            valueInput.value = entry.checked ? '1' : '0';
            valueInput.dataset.autoApprovalBatchDynamic = 'true';

            form.append(idInput, valueInput);
        });
    }

    function waitForAutoApprovalAjaxCompletion() {
        const flash = document.getElementById('flash-messages');

        return new Promise((resolve) => {
            let resolved = false;
            let observer = null;
            const finish = () => {
                if (resolved) {
                    return;
                }

                resolved = true;
                if (observer) {
                    observer.disconnect();
                }
                resolve();
            };

            window.setTimeout(finish, autoApprovalCompletionTimeoutMs);

            if (!(flash instanceof HTMLElement)) {
                return;
            }

            observer = new MutationObserver(finish);
            observer.observe(flash, {
                childList: true,
                subtree: true,
            });
        });
    }

    function autoApprovalFlashHasError() {
        const flash = document.getElementById('flash-messages');

        return flash instanceof HTMLElement && Boolean(flash.querySelector('.alert.error'));
    }

    async function flushAutoApprovalBatch(form) {
        const state = autoApprovalFormState(form);
        if (state.inFlight) {
            syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);
            return;
        }

        if (state.timerId) {
            window.clearTimeout(state.timerId);
            state.timerId = 0;
        }

        if (state.pending.size === 0) {
            syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);
            return;
        }

        const entries = Array.from(state.pending.entries()).map(([transactionId, checked]) => ({
            transactionId,
            checked,
            controls: autoApprovalControlsForTransaction(transactionId),
        }));
        state.pending.clear();
        state.inFlight = true;
        syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);

        entries.forEach((entry) => setAutoApprovalStatus(entry.controls, 'Saving...'));
        replaceAutoApprovalBatchInputs(form, entries);

        const submitter = form.querySelector('[data-auto-approval-batch-submit]');
        try {
            if (submitter instanceof HTMLButtonElement) {
                form.requestSubmit(submitter);
            } else {
                form.requestSubmit();
            }
            await waitForAutoApprovalAjaxCompletion();

            entries.forEach((entry) => {
                if (state.pending.has(entry.transactionId)) {
                    return;
                }

                setAutoApprovalStatus(entry.controls, autoApprovalFlashHasError() ? 'Not saved' : 'Saved');
                window.setTimeout(() => {
                    if (!state.pending.has(entry.transactionId)) {
                        setAutoApprovalStatus(entry.controls, '');
                    }
                }, 1800);
            });
        } finally {
            state.inFlight = false;
            syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);
            if (state.pending.size > 0) {
                state.timerId = window.setTimeout(() => flushAutoApprovalBatch(form), autoApprovalDebounceMs);
                syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);
            }
        }
    }

    function queueAutoApprovalState(control) {
        const transactionId = String(control.dataset.autoApprovalTransactionId || '').trim();
        if (transactionId === '') {
            return;
        }

        const form = autoApprovalBatchFormForControl(control);
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const checked = control.checked;
        syncAutoApprovalDuplicateControls(transactionId, checked);
        autoApprovalControlsForTransaction(transactionId).forEach((matchingControl) => {
            matchingControl.dataset.autoApprovalDirty = '1';
        });

        const state = autoApprovalFormState(form);
        state.pending.set(transactionId, checked);
        setAutoApprovalStatus(autoApprovalControlsForTransaction(transactionId), 'Saving...');
        syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);

        if (state.timerId) {
            window.clearTimeout(state.timerId);
        }

        state.timerId = window.setTimeout(() => flushAutoApprovalBatch(form), autoApprovalDebounceMs);
        syncTransactionsImportedPostConfirmationState(autoApprovalCardForElement(form) || document);
    }

    function initialiseTransactionAutoApprovalControls(root = document) {
        const controls = root.querySelectorAll ? root.querySelectorAll('[data-auto-approval-control="true"]') : [];

        controls.forEach((control) => {
            if (!(control instanceof HTMLInputElement) || control.dataset.autoApprovalBound === '1') {
                return;
            }

            control.dataset.autoApprovalBound = '1';
            control.addEventListener('change', () => queueAutoApprovalState(control));
        });

        syncTransactionsImportedPostConfirmationState(root);
    }

    function setYearEndStateCardProcessing(form, submitter) {
        const card = form.closest('[data-year-end-state-card="true"]');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        card.dataset.yearEndStateProcessing = '1';
        card.querySelectorAll('button').forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            button.disabled = true;
        });

        if (submitter instanceof HTMLButtonElement) {
            const runningLabel = String(submitter.dataset.yearEndStateRunningLabel || '').trim();
            if (runningLabel !== '') {
                submitter.textContent = runningLabel;
            }
        }
    }

    function initialiseYearEndStateForms(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('[data-year-end-state-form="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.yearEndStateBound === '1') {
                return;
            }

            form.dataset.yearEndStateBound = '1';
            form.addEventListener('submit', (event) => {
                if (event.defaultPrevented) {
                    return;
                }

                const card = form.closest('[data-year-end-state-card="true"]');
                if (card instanceof HTMLElement && card.dataset.yearEndStateProcessing === '1') {
                    event.preventDefault();
                    return;
                }

                const submitter = event.submitter instanceof HTMLButtonElement
                    ? event.submitter
                    : form.querySelector('[data-year-end-state-submit="true"]');

                setYearEndStateCardProcessing(form, submitter);
            });
        });
    }

    const cardMaximizedStoragePrefix = 'eel_accounts:card_maximized:';

    function cardMaximizedPageKey() {
        const params = new URLSearchParams(window.location.search);
        return params.get('page') || 'default';
    }

    function cardMaximizedStorageKey(card) {
        if (!(card instanceof HTMLElement)) {
            return '';
        }

        const cardKey = String(card.dataset.cardKey || '').trim();
        return cardKey === '' ? '' : `${cardMaximizedStoragePrefix}${cardMaximizedPageKey()}:${cardKey}`;
    }

    function readStoredCardMaximizedState(card) {
        const storageKey = cardMaximizedStorageKey(card);
        if (storageKey === '') {
            return false;
        }

        try {
            return window.localStorage.getItem(storageKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function persistCardMaximizedState(card) {
        const storageKey = cardMaximizedStorageKey(card);
        if (storageKey === '') {
            return;
        }

        try {
            window.localStorage.setItem(storageKey, card.classList.contains('card-maximized') ? '1' : '0');
        } catch (error) {
            // Ignore storage failures; the card still behaves normally for this page view.
        }
    }

    function persistVisibleCardMaximizedStates() {
        document.querySelectorAll('.card[data-card-key]').forEach((card) => {
            if (card instanceof HTMLElement) {
                persistCardMaximizedState(card);
            }
        });
    }

    function syncCardMaximizedToggle(card, maximized) {
        const toggle = card.querySelector('[data-card-size-toggle]');
        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        toggle.setAttribute('aria-pressed', maximized ? 'true' : 'false');
        toggle.setAttribute('aria-label', maximized ? 'Minimize card' : 'Maximize card');
    }

    function syncCardMaximizedBodyState() {
        if (!(document.body instanceof HTMLElement)) {
            return;
        }

        document.body.classList.toggle('card-maximized-active', Boolean(document.querySelector('.card.card-maximized')));
    }

    function applyStoredCardMaximizedState(card) {
        if (!(card instanceof HTMLElement) || !readStoredCardMaximizedState(card)) {
            return;
        }

        document.querySelectorAll('.card.card-maximized').forEach((maximizedCard) => {
            if (maximizedCard instanceof HTMLElement && maximizedCard !== card) {
                maximizedCard.classList.remove('card-maximized');
                syncCardMaximizedToggle(maximizedCard, false);
                persistCardMaximizedState(maximizedCard);
            }
        });

        card.classList.add('card-maximized');
        syncCardMaximizedToggle(card, true);
        syncCardMaximizedBodyState();
    }

    function restoreStoredCardMaximizedStates(root = document) {
        const cards = [];

        if (root instanceof HTMLElement && root.matches('.card[data-card-key]')) {
            cards.push(root);
        }

        if (root && typeof root.querySelectorAll === 'function') {
            root.querySelectorAll('.card[data-card-key]').forEach((card) => {
                if (card instanceof HTMLElement) {
                    cards.push(card);
                }
            });
        }

        cards.forEach(applyStoredCardMaximizedState);
        syncCardMaximizedBodyState();
    }

    document.addEventListener('click', (event) => {
        const cardSizeToggle = event.target instanceof Element ? event.target.closest('[data-card-size-toggle]') : null;
        if (cardSizeToggle instanceof HTMLButtonElement) {
            window.setTimeout(persistVisibleCardMaximizedStates, 0);
        }

        const accountingPeriodSummaryButton = event.target instanceof Element
            ? event.target.closest('[data-accounting-period-summary-button="true"]')
            : null;

        if (!(accountingPeriodSummaryButton instanceof HTMLButtonElement)) {
            return;
        }

        event.preventDefault();

        const accountingPeriodId = String(accountingPeriodSummaryButton.dataset.accountingPeriodId || '').trim();
        const accountingPeriodSelect = document.querySelector('.site-context-slot select[data-site-context-key="accounting_period_id"]');

        if (accountingPeriodId !== '' && accountingPeriodSelect instanceof HTMLSelectElement) {
            accountingPeriodSelect.value = accountingPeriodId;
            accountingPeriodSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            window.setTimeout(persistVisibleCardMaximizedStates, 0);
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (!document.querySelector('[data-year-end-state-processing="1"]')) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });

    initialiseManualAssetLegalWarnings(document);
    initialiseDirectorLoanOffsetAcknowledgements(document);
    initialiseUploadProcessingIndicators(document);
    initialiseVehicleRows(document);
    initialiseVatRegistrationForms(document);
    initialiseStatementMappingForms(document);
    initialiseTransactionCategorisationAutosave(document);
    initialiseTransactionAutoApprovalControls(document);
    initialiseYearEndStateForms(document);
    restoreStoredCardMaximizedStates(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    initialiseManualAssetLegalWarnings(node);
                    initialiseDirectorLoanOffsetAcknowledgements(node);
                    initialiseUploadProcessingIndicators(node);
                    initialiseVehicleRows(node);
                    initialiseVatRegistrationForms(node);
                    initialiseStatementMappingForms(node);
                    initialiseTransactionCategorisationAutosave(node);
                    initialiseTransactionAutoApprovalControls(node);
                    initialiseYearEndStateForms(node);
                    restoreStoredCardMaximizedStates(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
})();
