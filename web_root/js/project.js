/**
 * EEL Accounts
 * Copyright (c) 2026 James Elstone
 * Licensed under the GNU Affero General Public License v3.0 (AGPLv3)
 * See LICENSE file for details.
 */
(() => {
    function updateUploadSelection(dropzone, input) {
        if (!(dropzone instanceof HTMLElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        const files = input.files ? Array.from(input.files) : [];
        const form = dropzone.closest('form');
        const scope = form instanceof HTMLFormElement ? form : dropzone;
        const list = scope.querySelector('[data-upload-file-list]');
        const summary = scope.querySelector('[data-upload-selection-summary]');
        const maxFiles = Number(dropzone.dataset.uploadMaxFiles || '12');
        const maxReached = files.length > maxFiles;

        if (summary instanceof HTMLElement) {
            if (files.length === 0) {
                summary.textContent = 'No files selected yet.';
            } else if (maxReached) {
                summary.textContent = `Too many files selected.\nPlease keep it to ${String(maxFiles)} CSV files or fewer.`;
            } else {
                summary.textContent = `${String(files.length)} file${files.length > 1 ? 's' : ''} selected:`;
            }
        }

        if (!(list instanceof HTMLElement)) {
            return;
        }

        list.innerHTML = '';

        if (files.length === 0) {
            list.hidden = true;
            return;
        }

        files.forEach((file) => {
            const item = document.createElement('li');
            item.textContent = file.name || 'Unnamed file';
            list.appendChild(item);
        });

        list.hidden = false;
    }

    function assignUploadFiles(input, files) {
        if (!(input instanceof HTMLInputElement) || !files || typeof DataTransfer !== 'function') {
            return false;
        }

        const dataTransfer = new DataTransfer();

        Array.from(files).forEach((file) => {
            dataTransfer.items.add(file);
        });

        input.files = dataTransfer.files;
        return true;
    }

    function syncUploadSubmitState(form, input, accountSelect) {
        if (!(form instanceof HTMLFormElement) || !(input instanceof HTMLInputElement)) {
            return;
        }

        const submitButton = form.querySelector('[data-upload-submit]');
        if (!(submitButton instanceof HTMLButtonElement)) {
            return;
        }

        const hasAccount = accountSelect instanceof HTMLSelectElement
            ? String(accountSelect.value || '').trim() !== ''
            : true;
        const hasFiles = input.files instanceof FileList && input.files.length > 0;

        submitButton.disabled = !hasAccount || !hasFiles;
    }

    function setUploadProcessingState(form, isProcessing) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const submitButton = form.querySelector('[data-upload-submit]');
        const processingIcon = form.querySelector('[data-upload-processing-icon]');

        if (isProcessing && submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = isProcessing;
        }

        if (processingIcon instanceof HTMLElement) {
            processingIcon.classList.toggle('is-hidden', !isProcessing);
        }
    }

    function initialiseUploadDropzones(root = document) {
        const dropzones = root.querySelectorAll ? root.querySelectorAll('[data-upload-dropzone]') : [];

        dropzones.forEach((dropzone) => {
            if (!(dropzone instanceof HTMLElement)) {
                return;
            }

            const form = dropzone.closest('form');
            const input = form instanceof HTMLFormElement
                ? form.querySelector('[data-upload-input]')
                : null;
            const accountSelect = form instanceof HTMLFormElement ? form.querySelector('#upload_account_id') : null;
            let dragDepth = 0;

            if (!(input instanceof HTMLInputElement)) {
                return;
            }

            updateUploadSelection(dropzone, input);
            syncUploadSubmitState(form, input, accountSelect);

            if (dropzone.dataset.uploadBound === '1') {
                return;
            }

            dropzone.dataset.uploadBound = '1';

            input.addEventListener('change', () => {
                updateUploadSelection(dropzone, input);
                syncUploadSubmitState(form, input, accountSelect);
                setUploadProcessingState(form, false);
            });

            dropzone.addEventListener('dragenter', (event) => {
                event.preventDefault();
                event.stopPropagation();
                dragDepth += 1;
                dropzone.classList.add('is-dragover');
            });

            dropzone.addEventListener('dragover', (event) => {
                event.preventDefault();
                event.stopPropagation();

                if (event.dataTransfer) {
                    event.dataTransfer.dropEffect = 'copy';
                }

                dropzone.classList.add('is-dragover');
            });

            ['dragleave', 'dragend'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    dragDepth = Math.max(0, dragDepth - 1);

                    if (dragDepth === 0) {
                        dropzone.classList.remove('is-dragover');
                    }
                });
            });

            dropzone.addEventListener('drop', (event) => {
                const droppedFiles = event.dataTransfer ? event.dataTransfer.files : null;

                event.preventDefault();
                event.stopPropagation();
                dragDepth = 0;
                dropzone.classList.remove('is-dragover');

                if (!droppedFiles || droppedFiles.length === 0) {
                    return;
                }

                if (!assignUploadFiles(input, droppedFiles)) {
                    return;
                }

                updateUploadSelection(dropzone, input);
                syncUploadSubmitState(form, input, accountSelect);
                setUploadProcessingState(form, false);
            });

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            if (accountSelect instanceof HTMLSelectElement && accountSelect.dataset.uploadAccountBound !== '1') {
                accountSelect.dataset.uploadAccountBound = '1';

                accountSelect.addEventListener('invalid', () => {
                    accountSelect.classList.add('input-missing-required');
                });

                accountSelect.addEventListener('change', () => {
                    if (accountSelect.value) {
                        accountSelect.classList.remove('input-missing-required');
                    }

                    syncUploadSubmitState(form, input, accountSelect);
                    setUploadProcessingState(form, false);
                });
            }

            if (form.dataset.uploadFormBound === '1') {
                return;
            }

            form.dataset.uploadFormBound = '1';

            form.addEventListener('submit', (event) => {
                const maxFiles = Number(dropzone.dataset.uploadMaxFiles || '12');

                if (accountSelect instanceof HTMLSelectElement && !accountSelect.value) {
                    accountSelect.classList.add('input-missing-required');
                }

                if (input.files && input.files.length > maxFiles) {
                    event.preventDefault();
                    syncUploadSubmitState(form, input, accountSelect);
                    setUploadProcessingState(form, false);
                    window.alert(`Please upload no more than ${String(maxFiles)} CSV files at once.`);
                    return;
                }

                setUploadProcessingState(form, true);
            });
        });
    }

    initialiseUploadDropzones(document);

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    initialiseUploadDropzones(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true,
    });
})();
