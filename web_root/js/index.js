(() => {
    const body = document.body;
    let cardBodySequence = 0;
    const flashBaseTimeoutMs = 20000;
    const flashCascadeTimeoutMs = 2000;
    const flashDismissTransitionMs = 450;
    const afStorageKey = 'af_client_device_id';
    const afPersistentCookieName = 'af_client_device_id';
    let afEphemeralDeviceId = null;
    const afHeaderMap = {
        'Client-Browser-JS-User-Agent': 'X-AntiFraud-Client-Browser-JS-User-Agent',
        'Client-Device-ID': 'X-AntiFraud-Client-Device-ID',
        'Client-Screens': 'X-AntiFraud-Client-Screens',
        'Client-Timezone': 'X-AntiFraud-Client-Timezone',
        'Client-Window-Size': 'X-AntiFraud-Client-Window-Size',
    };

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

    function resolvePageLoadDurationMs() {
        if (!window.performance) {
            return null;
        }

        const navigationEntry = typeof window.performance.getEntriesByType === 'function'
            ? window.performance.getEntriesByType('navigation')[0]
            : null;
        if (navigationEntry && typeof navigationEntry.duration === 'number' && navigationEntry.duration > 0) {
            return navigationEntry.duration;
        }

        const timing = window.performance.timing;
        if (timing && typeof timing.navigationStart === 'number' && timing.navigationStart > 0) {
            const completedAt = typeof timing.loadEventEnd === 'number' && timing.loadEventEnd > 0
                ? timing.loadEventEnd
                : Date.now();
            const duration = completedAt - timing.navigationStart;

            if (Number.isFinite(duration) && duration > 0) {
                return duration;
            }
        }

        const nowDuration = typeof window.performance.now === 'function'
            ? window.performance.now()
            : null;
        if (Number.isFinite(nowDuration) && nowDuration > 0) {
            return nowDuration;
        }

        return null;
    }

    function renderPageLoadTime() {
        const node = document.getElementById('page-load-time');
        if (!(node instanceof HTMLElement)) {
            return;
        }

        const duration = resolvePageLoadDurationMs();
        if (!Number.isFinite(duration) || duration <= 0) {
            node.textContent = 'Page load time unavailable';
            return;
        }

        node.textContent = `Page loaded in ${(duration / 1000).toFixed(2)}s`;
    }

    function updateSidebarToggleState(toggleButton) {
        if (!(toggleButton instanceof HTMLButtonElement)) {
            return;
        }

        toggleButton.setAttribute(
            'aria-expanded',
            body.classList.contains('sidebar-collapsed') ? 'false' : 'true'
        );
    }

    function updateNavScrollHints(shell) {
        if (!(shell instanceof HTMLElement)) {
            return;
        }

        const navGroup = shell.querySelector('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            shell.classList.remove('has-overflow-top', 'has-overflow-bottom');
            return;
        }

        const hasOverflowTop = navGroup.scrollTop > 2;
        const hasOverflowBottom = (navGroup.scrollTop + navGroup.clientHeight) < (navGroup.scrollHeight - 2);

        shell.classList.toggle('has-overflow-top', hasOverflowTop);
        shell.classList.toggle('has-overflow-bottom', hasOverflowBottom);
    }

    function centeredNavScrollTop(navLink) {
        if (!(navLink instanceof HTMLElement)) {
            return null;
        }

        const navGroup = navLink.closest('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            return null;
        }

        const targetScrollTop = navLink.offsetTop - ((navGroup.clientHeight - navLink.offsetHeight) / 2);
        const maxScrollTop = Math.max(0, navGroup.scrollHeight - navGroup.clientHeight);

        return Math.max(0, Math.min(targetScrollTop, maxScrollTop));
    }

    function easeInOutCubic(progress) {
        if (progress < 0.5) {
            return 4 * progress * progress * progress;
        }

        return 1 - Math.pow(-2 * progress + 2, 3) / 2;
    }

    function animateNavScroll(navGroup, targetScrollTop, durationMs = 320) {
        if (!(navGroup instanceof HTMLElement)) {
            return Promise.resolve();
        }

        if (navGroup.dataset.navScrollAnimationFrame) {
            window.cancelAnimationFrame(Number(navGroup.dataset.navScrollAnimationFrame));
            delete navGroup.dataset.navScrollAnimationFrame;
        }

        const startScrollTop = navGroup.scrollTop;
        const distance = targetScrollTop - startScrollTop;

        if (Math.abs(distance) < 1 || durationMs <= 0) {
            navGroup.scrollTop = targetScrollTop;
            return Promise.resolve();
        }

        const startTime = window.performance && typeof window.performance.now === 'function'
            ? window.performance.now()
            : Date.now();

        return new Promise((resolve) => {
            const step = (now) => {
                const elapsed = now - startTime;
                const progress = Math.min(1, elapsed / durationMs);
                const easedProgress = easeInOutCubic(progress);

                navGroup.scrollTop = startScrollTop + (distance * easedProgress);

                if (progress < 1) {
                    navGroup.dataset.navScrollAnimationFrame = String(window.requestAnimationFrame(step));
                    return;
                }

                delete navGroup.dataset.navScrollAnimationFrame;
                navGroup.scrollTop = targetScrollTop;
                resolve();
            };

            navGroup.dataset.navScrollAnimationFrame = String(window.requestAnimationFrame(step));
        });
    }

    function centerNavLinkInView(navLink, behaviour = 'smooth') {
        if (!(navLink instanceof HTMLElement)) {
            return;
        }

        const navGroup = navLink.closest('.nav-group');
        if (!(navGroup instanceof HTMLElement)) {
            return;
        }

        const nextScrollTop = centeredNavScrollTop(navLink);
        if (!Number.isFinite(nextScrollTop)) {
            return;
        }

        if (behaviour === 'auto') {
            navGroup.scrollTop = nextScrollTop;
            return Promise.resolve();
        }

        return animateNavScroll(navGroup, nextScrollTop);
    }

    function initialiseSidebar(scope = document) {
        const sidebar = scope.querySelector ? scope.querySelector('#sidebar-shell') : null;
        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        const toggle = sidebar.querySelector('#sidebar-toggle');
        if (toggle instanceof HTMLButtonElement && toggle.dataset.sidebarToggleBound !== 'true') {
            toggle.addEventListener('click', () => {
                body.classList.toggle('sidebar-collapsed');
                updateSidebarToggleState(toggle);
                syncCompanySelectorOptionLabels();
                const navShell = sidebar.querySelector('.nav-scroll-shell');
                if (navShell instanceof HTMLElement) {
                    updateNavScrollHints(navShell);
                }
            });
            toggle.dataset.sidebarToggleBound = 'true';
        }

        updateSidebarToggleState(toggle);

        const navShell = sidebar.querySelector('.nav-scroll-shell');
        const navGroup = navShell instanceof HTMLElement ? navShell.querySelector('.nav-group') : null;

        if (navShell instanceof HTMLElement && navGroup instanceof HTMLElement) {
            navShell.classList.remove('is-ready');
            navShell.classList.remove('is-animated');

            const activeNavLink = navGroup.querySelector('.nav-link.active');

            if (activeNavLink instanceof HTMLElement) {
                centerNavLinkInView(activeNavLink, 'auto');
            }

            if (navGroup.dataset.navHintsBound !== 'true') {
                navGroup.addEventListener('scroll', () => {
                    updateNavScrollHints(navShell);
                }, { passive: true });

                window.addEventListener('resize', () => {
                    updateNavScrollHints(navShell);
                });

                navGroup.dataset.navHintsBound = 'true';
            }

            window.setTimeout(() => {
                updateNavScrollHints(navShell);
                navShell.classList.add('is-ready');
                window.requestAnimationFrame(() => {
                    navShell.classList.add('is-animated');
                });
            }, 0);
        }
    }

    function afStorageAvailable(storageName) {
        try {
            const storage = window[storageName];
            const probe = '__af_probe__';

            if (!storage) {
                return false;
            }

            storage.setItem(probe, '1');
            storage.removeItem(probe);

            return true;
        } catch (error) {
            return false;
        }
    }

    function afGetCookie(name) {
        const prefix = `${name}=`;
        const parts = document.cookie ? document.cookie.split(';') : [];

        for (const partValue of parts) {
            const part = partValue.trim();

            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.substring(prefix.length));
            }
        }

        return null;
    }

    function afSetCookie(name, value, maxAgeSeconds) {
        let cookie = `${name}=${encodeURIComponent(value)}; path=/; SameSite=Lax; max-age=${String(maxAgeSeconds)}`;

        if (window.location.protocol === 'https:') {
            cookie += '; Secure';
        }

        document.cookie = cookie;
    }

    function afGenerateUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        const template = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx';

        return template.replace(/[xy]/g, (character) => {
            const random = Math.random() * 16 | 0;
            const value = character === 'x' ? random : (random & 0x3 | 0x8);

            return value.toString(16);
        });
    }

    function afGetDeviceId() {
        let resolvedId = null;

        if (afStorageAvailable('localStorage')) {
            try {
                let stored = window.localStorage.getItem(afStorageKey);

                if (stored) {
                    resolvedId = stored;
                } else {
                    stored = afGenerateUuid();
                    window.localStorage.setItem(afStorageKey, stored);
                    resolvedId = stored;
                }
            } catch (error) {
                // Fall through to cookie or in-memory storage.
            }
        }

        if (!resolvedId) {
            const cookieValue = afGetCookie(afPersistentCookieName);

            if (cookieValue) {
                resolvedId = cookieValue;
            }
        }

        if (!resolvedId && !afEphemeralDeviceId) {
            afEphemeralDeviceId = afGenerateUuid();
        }

        if (!resolvedId) {
            resolvedId = afEphemeralDeviceId;
        }

        if (resolvedId) {
            afSetCookie(afPersistentCookieName, resolvedId, 31536000);
        }

        return resolvedId;
    }

    function initialiseLoginCountdown() {
        const container = document.querySelector('[data-login-countdown]');
        if (!(container instanceof HTMLElement)) {
            return;
        }

        const valueNode = container.querySelector('[data-login-countdown-value]');
        const form = container.closest('form');
        const submit = form instanceof HTMLFormElement
            ? form.querySelector('[data-login-submit-disabled="true"], button[type="submit"]')
            : null;
        let remaining = Number.parseInt(container.dataset.loginCountdown || '0', 10);

        if (!Number.isFinite(remaining) || remaining <= 0 || !(valueNode instanceof HTMLElement)) {
            return;
        }

        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
        }

        const tick = () => {
            valueNode.textContent = String(Math.max(0, remaining));

            if (remaining <= 0) {
                container.remove();

                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = false;
                    submit.removeAttribute('data-login-submit-disabled');
                }

                return;
            }

            remaining -= 1;
            window.setTimeout(tick, 1000);
        };

        tick();
    }

    function afFormatTimezone() {
        const offsetMinutes = -new Date().getTimezoneOffset();
        const sign = offsetMinutes >= 0 ? '+' : '-';
        const absoluteMinutes = Math.abs(offsetMinutes);
        const hours = String(Math.floor(absoluteMinutes / 60)).padStart(2, '0');
        const minutes = String(absoluteMinutes % 60).padStart(2, '0');

        return `UTC${sign}${hours}:${minutes}`;
    }

    function afBuildPairString(values) {
        const parts = [];

        Object.keys(values).forEach((key) => {
            const value = values[key];

            if (value === null || value === undefined || value === '') {
                return;
            }

            parts.push(`${key}=${String(value)}`);
        });

        return parts.join('&');
    }

    function afIsSameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (error) {
            return false;
        }
    }

    function afApplyHeaders(headers, values) {
        Object.keys(values).forEach((fieldName) => {
            const value = values[fieldName];
            const headerName = afHeaderMap[fieldName];

            if (!value || !headerName) {
                return;
            }

            headers.set(headerName, value);
        });
    }

    async function afGatherAntiFraudValues() {
        const screenValue = window.screen || null;
        const screenWidth = screenValue && typeof screenValue.width === 'number' ? screenValue.width : null;
        const screenHeight = screenValue && typeof screenValue.height === 'number' ? screenValue.height : null;
        const colourDepth = screenValue && typeof screenValue.colorDepth === 'number' ? screenValue.colorDepth : null;
        const pixelRatio = typeof window.devicePixelRatio === 'number' ? window.devicePixelRatio : null;
        const innerWidth = typeof window.innerWidth === 'number' ? window.innerWidth : null;
        const innerHeight = typeof window.innerHeight === 'number' ? window.innerHeight : null;

        return {
            'Client-Browser-JS-User-Agent': navigator.userAgent || null,
            'Client-Device-ID': afGetDeviceId(),
            'Client-Screens': afBuildPairString({
                width: screenWidth,
                height: screenHeight,
                'scaling-factor': pixelRatio,
                'colour-depth': colourDepth,
            }) || null,
            'Client-Timezone': afFormatTimezone(),
            'Client-Window-Size': afBuildPairString({
                width: innerWidth,
                height: innerHeight,
            }) || null,
        };
    }

    async function afBuildHeaders(url, optionsHeaders) {
        const headers = new Headers(optionsHeaders || {});
        headers.set('X-Requested-With', 'XMLHttpRequest');
        headers.set('Accept', 'application/json');

        if (afIsSameOrigin(url)) {
            const values = await afGatherAntiFraudValues();
            afApplyHeaders(headers, values);
        }

        return headers;
    }

    async function sendXhr(url, options = {}) {
        const headers = await afBuildHeaders(url, options.headers);

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(options.method || 'GET', url, true);

            headers.forEach((value, name) => {
                try {
                    xhr.setRequestHeader(name, value);
                } catch (error) {
                    // Ignore header-setting errors so the request can still continue.
                }
            });

            xhr.onload = () => {
                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(new Error(`Request failed with status ${xhr.status}`));
                    return;
                }

                try {
                    resolve(JSON.parse(xhr.responseText));
                } catch (error) {
                    reject(error);
                }
            };

            xhr.onerror = () => reject(new Error('Request failed.'));
            xhr.send(options.body ?? null);
        });
    }

    async function sendAjax(url, options = {}) {
        if (options.transport === 'xhr') {
            return sendXhr(url, options);
        }

        const headers = await afBuildHeaders(url, options.headers);
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            headers,
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

    function requestUrlWithFormData(url, formData) {
        const requestUrl = new URL(url, window.location.href);

        formData.forEach((value, key) => {
            requestUrl.searchParams.delete(key);
        });

        formData.forEach((value, key) => {
            requestUrl.searchParams.append(key, String(value));
        });

        return requestUrl.toString();
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
                initialiseCardToggles(replacement);
            }
        });
    }

    function initialiseCardToggles(scope = document) {
        const cards = scope.querySelectorAll ? scope.querySelectorAll('.card') : [];

        cards.forEach((card) => {
            const title = card.querySelector('.card-title');
            const cardBody = card.querySelector('.card-body');

            if (!(title instanceof HTMLElement) || !(cardBody instanceof HTMLElement)) {
                return;
            }

            if (!cardBody.id) {
                cardBodySequence += 1;
                cardBody.id = `card-body-${cardBodySequence}`;
            }

            title.classList.add('card-title-toggle');
            title.setAttribute('role', 'button');
            title.setAttribute('tabindex', '0');
            title.setAttribute('aria-controls', cardBody.id);
            title.setAttribute('aria-expanded', cardBody.hidden ? 'false' : 'true');
        });
    }

    function toggleCardBody(title) {
        if (!(title instanceof HTMLElement)) {
            return;
        }

        const card = title.closest('.card');
        const cardBody = card ? card.querySelector('.card-body') : null;

        if (!(cardBody instanceof HTMLElement)) {
            return;
        }

        const nextHidden = !cardBody.hidden;
        cardBody.hidden = nextHidden;
        title.setAttribute('aria-expanded', nextHidden ? 'false' : 'true');
        card.classList.toggle('card-collapsed', nextHidden);
    }

    function replaceFlash(html) {
        const flash = document.getElementById('flash-messages');
        if (flash) {
            flash.innerHTML = html || '';
            scheduleFlashDismissals(flash);
        }
    }

    function dismissFlashMessage(message) {
        if (!(message instanceof HTMLElement) || !message.isConnected || message.classList.contains('is-dismissing')) {
            return;
        }

        message.classList.add('is-dismissing');

        window.setTimeout(() => {
            if (!message.isConnected) {
                return;
            }

            message.remove();
        }, flashDismissTransitionMs);
    }

    function scheduleFlashDismissals(flashContainer) {
        if (!(flashContainer instanceof HTMLElement)) {
            return;
        }

        const messages = Array.from(flashContainer.querySelectorAll('.alert'));

        messages.forEach((message, index) => {
            const timeoutMs = flashBaseTimeoutMs + (index * flashCascadeTimeoutMs);

            window.setTimeout(() => {
                dismissFlashMessage(message);
            }, timeoutMs);
        });
    }

    function replaceSidebar(html) {
        if (typeof html !== 'string' || html.trim() === '') {
            return;
        }

        const current = document.getElementById('sidebar-shell');
        if (!current) {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const replacement = template.content.firstElementChild;

        if (replacement) {
            current.replaceWith(replacement);
            initialiseSidebar(document);
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
        const method = (form.method || 'POST').toUpperCase();
        const requestUrl = method === 'GET'
            ? requestUrlWithFormData(formRequestUrl(form), formData)
            : formRequestUrl(form);

        try {
            const payload = await sendAjax(requestUrl, {
                method,
                body: method === 'GET' ? null : formData,
                transport: form.dataset.ajaxTransport === 'xhr' ? 'xhr' : 'fetch',
            });

            replaceCards(payload.cards);
            replaceSelectorUi(payload.selector_ui);
            replaceSidebar(payload.sidebar_html);
            replaceFlash(payload.flash_html);

        } catch (error) {
            console.error(error);
        }
    });

    document.addEventListener('click', async (event) => {
        const link = event.target instanceof Element ? event.target.closest('[data-ajax-link="true"]') : null;
        if (!(link instanceof HTMLAnchorElement)) {
            const title = event.target instanceof Element ? event.target.closest('.card-title-toggle') : null;

            if (title instanceof HTMLElement) {
                event.preventDefault();
                toggleCardBody(title);
            }

            return;
        }

        event.preventDefault();
        if (link.closest('.nav-group')) {
            await centerNavLinkInView(link);
        }
        window.location.href = link.href;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const title = event.target instanceof Element ? event.target.closest('.card-title-toggle') : null;
        if (!(title instanceof HTMLElement)) {
            return;
        }

        event.preventDefault();
        toggleCardBody(title);
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
    initialiseSidebar(document);
    initialiseCardToggles();
    scheduleFlashDismissals(document.getElementById('flash-messages'));
    afGetDeviceId();
    initialiseLoginCountdown();

    if (document.readyState === 'complete') {
        renderPageLoadTime();
    } else {
        window.addEventListener('load', () => {
            renderPageLoadTime();
            window.setTimeout(renderPageLoadTime, 0);
        }, { once: true });
    }
})();
