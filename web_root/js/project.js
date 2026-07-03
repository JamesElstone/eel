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
        if (state.inFlight || state.pending.size === 0) {
            return;
        }

        const entries = Array.from(state.pending.entries()).map(([transactionId, checked]) => ({
            transactionId,
            checked,
            controls: autoApprovalControlsForTransaction(transactionId),
        }));
        state.pending.clear();
        state.inFlight = true;

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
            if (state.pending.size > 0) {
                state.timerId = window.setTimeout(() => flushAutoApprovalBatch(form), autoApprovalDebounceMs);
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

        const state = autoApprovalFormState(form);
        state.pending.set(transactionId, checked);
        setAutoApprovalStatus(autoApprovalControlsForTransaction(transactionId), 'Saving...');

        if (state.timerId) {
            window.clearTimeout(state.timerId);
        }

        state.timerId = window.setTimeout(() => flushAutoApprovalBatch(form), autoApprovalDebounceMs);
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

    initialiseManualAssetLegalWarnings(document);
    initialiseUploadProcessingIndicators(document);
    initialiseVatRegistrationForms(document);
    initialiseStatementMappingForms(document);
    initialiseTransactionCategorisationAutosave(document);
    initialiseTransactionAutoApprovalControls(document);
    restoreStoredCardMaximizedStates(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    initialiseManualAssetLegalWarnings(node);
                    initialiseUploadProcessingIndicators(node);
                    initialiseVatRegistrationForms(node);
                    initialiseStatementMappingForms(node);
                    initialiseTransactionCategorisationAutosave(node);
                    initialiseTransactionAutoApprovalControls(node);
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
