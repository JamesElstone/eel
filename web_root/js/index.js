(() => {
    const body = document.body;
    const toggle = document.getElementById('sidebar-toggle');

    function syncCompanySelectorOptionLabels() {
        const select = document.getElementById('company-selector');
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const collapsed = body.classList.contains('sidebar-collapsed');
        Array.from(select.options).forEach((option) => {
            const shortLabel = option.dataset.shortLabel || option.text;
            const fullLabel = option.getAttribute('data-full-label') || option.text;

            if (!option.hasAttribute('data-full-label')) {
                option.setAttribute('data-full-label', option.text);
            }

            option.text = collapsed ? shortLabel : fullLabel;
        });
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            body.classList.toggle('sidebar-collapsed');
            toggle.setAttribute('aria-expanded', body.classList.contains('sidebar-collapsed') ? 'false' : 'true');
            syncCompanySelectorOptionLabels();
        });
    }

    async function sendAjax(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                ...(options.headers || {}),
            },
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        return response.json();
    }

    function formRequestUrl(form) {
        const action = form.getAttribute('action');

        if (typeof action === 'string' && action.trim() !== '') {
            return action;
        }

        return window.location.href;
    }

    function replaceCards(cards) {
        Object.entries(cards || {}).forEach(([domId, html]) => {
            const current = document.getElementById(domId);
            if (!current) {
                return;
            }

            const template = document.createElement('template');
            template.innerHTML = html.trim();
            const replacement = template.content.firstElementChild;

            if (replacement) {
                current.replaceWith(replacement);
            }
        });
    }

    function replaceFlash(html) {
        const flash = document.getElementById('flash-messages');
        if (flash) {
            flash.innerHTML = html || '';
        }
    }

    function updateSelectOptions(select, options, selectedValue) {
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        select.innerHTML = '';

        (Array.isArray(options) ? options : []).forEach((optionData) => {
            const option = document.createElement('option');
            option.value = String(optionData?.value ?? '');
            option.text = String(optionData?.label ?? '');

            if (optionData?.short_label) {
                option.dataset.shortLabel = String(optionData.short_label);
            }

            if (optionData?.disabled) {
                option.disabled = true;
            }

            if (option.value === String(selectedValue ?? '')) {
                option.selected = true;
            }

            select.appendChild(option);
        });
    }

    function replaceSelectorUi(selectorUi) {
        if (!selectorUi) {
            return;
        }

        const companySelect = document.getElementById('company-selector');
        const taxYearSelect = document.getElementById('tax-year-selector');
        const companyForm = companySelect instanceof HTMLSelectElement ? companySelect.closest('form') : null;
        const taxYearForm = taxYearSelect instanceof HTMLSelectElement ? taxYearSelect.closest('form') : null;

        updateSelectOptions(companySelect, selectorUi.companies, selectorUi.selected_company_id);
        if (companySelect instanceof HTMLSelectElement) {
            companySelect.disabled = Boolean(selectorUi.company_selector_disabled);
        }

        updateSelectOptions(taxYearSelect, selectorUi.tax_years, selectorUi.selected_tax_year_id);
        if (taxYearSelect instanceof HTMLSelectElement) {
            taxYearSelect.disabled = Boolean(selectorUi.tax_year_selector_disabled);
        }

        if (companyForm instanceof HTMLFormElement) {
            const taxYearInput = companyForm.querySelector('input[name="tax_year_id"]');
            if (taxYearInput instanceof HTMLInputElement) {
                taxYearInput.value = '';
            }
        }

        if (taxYearForm instanceof HTMLFormElement) {
            const companyInput = taxYearForm.querySelector('input[name="company_id"]');
            if (companyInput instanceof HTMLInputElement) {
                companyInput.value = String(selectorUi.selected_company_id ?? '');
            }

            taxYearForm.hidden = !selectorUi.show_tax_year_selector;
        }

        syncCompanySelectorOptionLabels();
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.dataset.ajax !== 'true') {
            return;
        }

        event.preventDefault();

        const formData = new FormData(form);
        formData.set('_ajax', '1');

        try {
            const payload = await sendAjax(formRequestUrl(form), {
                method: form.method || 'POST',
                body: formData,
            });

            replaceCards(payload.cards);
            replaceSelectorUi(payload.selector_ui);
            replaceFlash(payload.flash_html);

        } catch (error) {
            console.error(error);
        }
    });

    document.addEventListener('click', async (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-ajax-link="true"]') : null;
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }

        event.preventDefault();
        window.location.href = link.href;
    });

    document.addEventListener('change', (event) => {
        const select = event.target;
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const form = select.closest('form[data-ajax="true"]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (select.name === 'company_id') {
            const taxYearInput = form.querySelector('input[name="tax_year_id"]');
            if (taxYearInput instanceof HTMLInputElement) {
                taxYearInput.value = '';
            }
        }

        form.requestSubmit();
    });

    syncCompanySelectorOptionLabels();
})();
