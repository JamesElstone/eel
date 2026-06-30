/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
(() => {
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

            const sync = () => {
                const registered = checkedVatRegisteredValue(form) === '1';
                const countryValue = country instanceof HTMLSelectElement ? String(country.value || '') : '';
                const numberValue = number instanceof HTMLInputElement ? String(number.value || '').trim() : '';

                if (panel instanceof HTMLElement) {
                    panel.classList.toggle('is-hidden', !registered);
                }

                if (checkButton instanceof HTMLButtonElement) {
                    checkButton.disabled = !registered || countryValue === '' || numberValue === '';
                }

                if (saveButton instanceof HTMLButtonElement) {
                    saveButton.disabled = checkedVatRegisteredValue(form) === initialRegistered
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

    document.addEventListener('click', (event) => {
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

    initialiseUploadProcessingIndicators(document);
    initialiseVatRegistrationForms(document);
    initialiseStatementMappingForms(document);
    initialiseTransactionCategorisationAutosave(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    initialiseUploadProcessingIndicators(node);
                    initialiseVatRegistrationForms(node);
                    initialiseStatementMappingForms(node);
                    initialiseTransactionCategorisationAutosave(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
})();
