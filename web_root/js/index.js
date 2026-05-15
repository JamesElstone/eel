/**
 * eelKit Framework
 * Copyright (c) 2026 James Elstone
 * Licensed under the BSD 3-Clause License
 * See LICENSE file for details.
 */
(() => {
    const body = document.body;
    let cardBodySequence = 0;
    const flashBaseTimeoutMs = 5000;
    const flashCascadeTimeoutMs = 2000;
    const flashDismissTransitionMs = 450;
    const flashHistoryLimit = 50;
    const flashHistory = [];
    let activeChickenCheckButton = null;
    const afStorageKey = 'af_client_device_id';
    const afPersistentCookieName = 'af_client_device_id';
    const tableCondensedStoragePrefix = 'table_condensed_view:';
    let afEphemeralDeviceId = null;
    const ajaxNonceBootstrapId = 'ajax-security-bootstrap';
    const ajaxNonceState = {
        available: [],
        inFlight: new Set(),
    };
    const cardAutoRefreshState = new WeakMap();
    const afHeaderMap = {
        'Client-Browser-JS-User-Agent': 'X-AntiFraud-Client-Browser-JS-User-Agent',
        'Client-Device-ID': 'X-AntiFraud-Client-Device-ID',
        'Client-Screens': 'X-AntiFraud-Client-Screens',
        'Client-Timezone': 'X-AntiFraud-Client-Timezone',
        'Client-Window-Size': 'X-AntiFraud-Client-Window-Size',
    };

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

    function createAjaxError(status, payload = null) {
        const error = new Error(`Request failed with status ${status}`);

        error.status = status;
        error.payload = payload;

        return error;
    }

    function loadAjaxNonceBootstrap() {
        const node = document.getElementById(ajaxNonceBootstrapId);
        if (!(node instanceof HTMLElement)) {
            return;
        }

        try {
            const payload = JSON.parse(node.dataset.noncePayload || '{}');
            replaceAjaxNoncePool(payload?.nonce_pool);
        } catch (error) {
            console.error(error);
        }
    }

    function replaceAjaxNoncePool(noncePool) {
        ajaxNonceState.available = Array.isArray(noncePool)
            ? noncePool
                .map((nonce) => String(nonce || '').trim())
                .filter((nonce) => nonce !== '')
            : [];
        ajaxNonceState.inFlight.clear();
    }

    function appendAjaxNonce(nonce) {
        const value = String(nonce || '').trim();
        if (value === '') {
            return;
        }

        if (!ajaxNonceState.available.includes(value) && !ajaxNonceState.inFlight.has(value)) {
            ajaxNonceState.available.push(value);
        }
    }

    function requiresAjaxNonce(method, payload) {
        const methodName = String(method || 'GET').toUpperCase();

        if (methodName !== 'POST' || !payload || typeof payload !== 'object') {
            return false;
        }

        const ajaxFlag = String(payload._ajax || '').trim();
        const action = String(payload.action || '').trim();
        const cardAction = String(payload.card_action || '').trim();
        const tableExportPrepare = String(payload._table_export_prepare || '').trim();

        return ajaxFlag === '1' && (action !== '' || cardAction !== '' || tableExportPrepare !== '');
    }

    function reserveAjaxNonce() {
        const nonce = String(ajaxNonceState.available.shift() || '').trim();

        if (nonce === '') {
            return null;
        }

        ajaxNonceState.inFlight.add(nonce);

        return nonce;
    }

    function restoreAjaxNonce(nonce) {
        const value = String(nonce || '').trim();
        if (value === '') {
            return;
        }

        ajaxNonceState.inFlight.delete(value);
        if (!ajaxNonceState.available.includes(value)) {
            ajaxNonceState.available.unshift(value);
        }
    }

    function completeAjaxNonce(usedNonce, replacementNonce) {
        const usedValue = String(usedNonce || '').trim();
        const replacementValue = String(replacementNonce || '').trim();

        if (usedValue !== '') {
            ajaxNonceState.inFlight.delete(usedValue);
        }

        if (replacementValue !== '') {
            appendAjaxNonce(replacementValue);
        }
    }

    function normaliseAjaxErrors(payload) {
        if (!payload || !Array.isArray(payload.errors)) {
            return [];
        }

        return payload.errors
            .map((message) => String(message).trim())
            .filter((message) => message !== '');
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeCssIdentifier(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }

        return String(value).replace(/["\\]/g, '\\$&');
    }

    function tableCondensedStorageKey(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return '';
        }

        const tableKey = String(toggle.dataset.tableKey || '').trim();

        return tableKey !== '' ? `${tableCondensedStoragePrefix}${tableKey}` : '';
    }

    function findCondensedTableTarget(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return null;
        }

        const toolbar = toggle.closest('.card-toolbar');
        let sibling = toolbar instanceof HTMLElement ? toolbar.nextElementSibling : null;

        while (sibling instanceof HTMLElement) {
            if (sibling.matches('.table-scroll, .table-scroll-mini, table')) {
                return sibling;
            }

            const tableTarget = sibling.querySelector('.table-scroll, .table-scroll-mini, table');
            if (tableTarget instanceof HTMLElement) {
                return tableTarget;
            }

            sibling = sibling.nextElementSibling;
        }

        return null;
    }

    function tableCondensedEnabled(toggle) {
        const key = tableCondensedStorageKey(toggle);
        if (key === '' || !afStorageAvailable('localStorage')) {
            return false;
        }

        try {
            return window.localStorage.getItem(key) === '1';
        } catch (error) {
            return false;
        }
    }

    function setTableCondensed(toggle, condensed, persist = true) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        const enabled = Boolean(condensed);
        const target = findCondensedTableTarget(toggle);

        if (target instanceof HTMLElement) {
            target.classList.toggle('table-condensed', enabled);
        }

        toggle.classList.toggle('primary', enabled);
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');

        const key = tableCondensedStorageKey(toggle);
        if (!persist || key === '' || !afStorageAvailable('localStorage')) {
            return;
        }

        try {
            window.localStorage.setItem(key, enabled ? '1' : '0');
        } catch (error) {
            // Storage may be disabled; the current page state has still been updated.
        }
    }

    function initialiseTableCondensedControls(root = document) {
        const toggles = root.querySelectorAll ? root.querySelectorAll('.table-condensed-toggle') : [];

        toggles.forEach((toggle) => {
            if (!(toggle instanceof HTMLButtonElement)) {
                return;
            }

            setTableCondensed(toggle, tableCondensedEnabled(toggle), false);

            if (toggle.dataset.tableCondensedBound === '1') {
                return;
            }

            toggle.addEventListener('click', () => {
                setTableCondensed(toggle, !toggle.classList.contains('primary'));
            });
            toggle.dataset.tableCondensedBound = '1';
        });
    }

    function renderErrorFlashHtml(payload) {
        const errors = normaliseAjaxErrors(payload);

        if (errors.length === 0) {
            return '';
        }

        return errors
            .map((message) => `<div class="alert error">${escapeHtml(message)}</div>`)
            .join('');
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
                let payload = null;

                try {
                    payload = xhr.responseText !== '' ? JSON.parse(xhr.responseText) : null;
                } catch (error) {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject(createAjaxError(xhr.status));
                        return;
                    }

                    reject(error);
                    return;
                }

                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(createAjaxError(xhr.status, payload));
                    return;
                }

                resolve(payload);
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

        const payload = await response.json();

        if (!response.ok) {
            throw createAjaxError(response.status, payload);
        }

        return payload;
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

    function currentPageId() {
        const main = document.querySelector('main[data-current-page]');

        return main instanceof HTMLElement ? String(main.dataset.currentPage || '').trim() : '';
    }

    function navigateToAjaxPayloadPage(payload) {
        const nextPage = String(payload?.page || '').trim();
        const nextUrl = String(payload?.url || '').trim();

        if (nextPage === '' || nextUrl === '' || nextPage === currentPageId()) {
            return false;
        }

        window.location.href = nextUrl;

        return true;
    }

    function triggerFileDownload(url) {
        const downloadUrl = String(url || '').trim();
        if (downloadUrl === '') {
            return;
        }

        const link = document.createElement('a');
        link.href = downloadUrl;
        link.rel = 'noopener';
        link.hidden = true;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function appendCurrentPageCardKeys(formData, form = null) {
        if (!(formData instanceof FormData)) {
            return;
        }

        formData.delete('cards[]');
        formData.delete('cards');

        if (form instanceof HTMLFormElement && form.dataset.invalidatePage === 'true') {
            return;
        }

        const cardNodes = document.querySelectorAll('.card[data-card-key]');

        cardNodes.forEach((node) => {
            if (!(node instanceof HTMLElement)) {
                return;
            }

            const cardKey = (node.dataset.cardKey || '').trim();

            if (cardKey !== '') {
                formData.append('cards[]', cardKey);
            }
        });
    }

    function appendRequestedVisibleCard(formData, submitter) {
        if (!(formData instanceof FormData)) {
            return;
        }

        const cardKey = submitter instanceof HTMLElement
            ? String(submitter.dataset.showCard || '').trim()
            : '';

        if (cardKey !== '') {
            formData.set('show_card', cardKey);
        }
    }

    function resolveSelfVisibleCardField(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const field = form.querySelector('input[name="show_card"]');
        const requestedCard = field instanceof HTMLInputElement
            ? String(field.value || '').trim()
            : '';

        if (requestedCard !== '.self') {
            return;
        }

        const card = form.closest('.card[data-card-key]');
        const cardKey = card instanceof HTMLElement
            ? String(card.dataset.cardKey || '').trim()
            : '';

        if (cardKey !== '') {
            field.value = cardKey;
        }
    }

    function formDataToJsonPayload(formData) {
        const payload = {};

        if (!(formData instanceof FormData)) {
            return payload;
        }

        formData.forEach((value, key) => {
            const normalisedKey = key.endsWith('[]') ? key.slice(0, -2) : key;

            if (Object.prototype.hasOwnProperty.call(payload, normalisedKey)) {
                if (Array.isArray(payload[normalisedKey])) {
                    payload[normalisedKey].push(value);
                    return;
                }

                payload[normalisedKey] = [payload[normalisedKey], value];
                return;
            }

            payload[normalisedKey] = key.endsWith('[]') ? [value] : value;
        });

        return payload;
    }

    function handleAjaxSecurityFailure(payload) {
        if (!payload || !payload.reload_required) {
            return;
        }

        window.setTimeout(() => {
            window.location.reload();
        }, 150);
    }

    function initStateWatchers(root = document) {
        const nodes = root.querySelectorAll ? root.querySelectorAll('[data-state-fields]') : [];

        nodes.forEach((node) => {
            if (!(node instanceof HTMLElement) || node.dataset.stateBound === '1') {
                return;
            }

            const fieldIds = (node.dataset.stateFields || '')
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value !== '');
            const targetId = node.dataset.stateTarget || '';
            const target = document.getElementById(targetId);

            if (!(target instanceof HTMLButtonElement) || fieldIds.length === 0) {
                return;
            }

            const fields = fieldIds
                .map((id) => document.getElementById(id))
                .filter((field) => field instanceof HTMLElement);

            if (fields.length === 0) {
                return;
            }

            const defaults = new Map();
            const stateValue = (field) => {
                if (field instanceof HTMLInputElement && field.type === 'checkbox') {
                    return field.checked ? '1' : '0';
                }

                return field.value;
            };

            fields.forEach((field) => {
                defaults.set(field, field.dataset.stateDefault ?? stateValue(field));
            });

            const sync = () => {
                const changed = fields.some((field) => stateValue(field) !== defaults.get(field));
                target.disabled = !changed;
            };

            fields.forEach((field) => {
                field.addEventListener('change', sync);
                field.addEventListener('input', sync);
            });

            sync();
            node.dataset.stateBound = '1';
        });
    }

    function initialiseDirtyActionControls(root = document) {
        const fields = root.querySelectorAll ? root.querySelectorAll('[data-dirty-action-target]') : [];

        fields.forEach((field) => {
            if (
                !(
                    field instanceof HTMLInputElement
                    || field instanceof HTMLSelectElement
                    || field instanceof HTMLTextAreaElement
                )
            ) {
                return;
            }

            const sync = () => {
                const targetSelector = String(field.dataset.dirtyActionTarget || '').trim();
                if (targetSelector === '') {
                    return;
                }

                const formId = String(field.getAttribute('form') || '').trim();
                const scope = formId !== ''
                    ? document.getElementById(formId)
                    : field.closest('form');

                if (!(scope instanceof HTMLElement)) {
                    return;
                }

                const initialValue = field.dataset.initialValue ?? field.defaultValue ?? '';
                const currentValue = field.value ?? '';
                const hasChanged = currentValue !== initialValue;
                const hasRequiredValue = field.dataset.dirtyRequireValue === '1'
                    ? currentValue.trim() !== ''
                    : true;

                try {
                    scope.querySelectorAll(targetSelector).forEach((button) => {
                        if (!(button instanceof HTMLButtonElement)) {
                            return;
                        }

                        const enableMode = String(button.dataset.dirtyEnableMode || field.dataset.dirtyEnableMode || 'changed').trim();
                        const enabled = enableMode === 'selected'
                            ? hasRequiredValue
                            : hasChanged && hasRequiredValue;

                        button.disabled = !enabled;
                        syncButtonTitleVisibility(button);
                    });
                } catch (error) {
                    console.error('Failed to sync dirty action controls.', error);
                }
            };

            sync();

            if (field.dataset.dirtyActionBound === '1') {
                return;
            }

            field.dataset.dirtyActionBound = '1';
            field.addEventListener('change', sync);
            field.addEventListener('input', sync);
        });
    }

    function triggerStateSync(field) {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function initDangerZoneConfirmationControls(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('form[data-ajax="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const clearInput = form.querySelector('[data-clear-confirm-input]');
            const clearButton = form.querySelector('#clear-imported-data-button');
            const deleteCheckbox = form.querySelector('[data-delete-confirm-checkbox]');
            const deleteInput = form.querySelector('[data-delete-confirm-input]');
            const deleteButton = form.querySelector('[data-delete-confirm-button]');

            if (
                !(clearInput instanceof HTMLInputElement)
                && !(deleteCheckbox instanceof HTMLInputElement)
                && !(deleteInput instanceof HTMLInputElement)
            ) {
                return;
            }

            const syncExpectedValueConfirmation = (input, button, options = {}) => {
                if (!(input instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
                    return;
                }

                const controllingCheckbox = options.checkbox instanceof HTMLInputElement ? options.checkbox : null;
                const enabled = controllingCheckbox === null ? true : controllingCheckbox.checked;
                const expectedValue = String(input.dataset.expectedValue || '').trim();
                const enteredValue = input.value.trim();

                input.disabled = !enabled;
                if (enabled) {
                    input.removeAttribute('disabled');
                } else {
                    input.setAttribute('disabled', 'disabled');
                }

                if (!enabled && options.clearWhenDisabled !== false) {
                    input.value = '';
                }

                button.disabled = !enabled || expectedValue === '' || enteredValue !== expectedValue;
            };

            const syncClearConfirmation = () => {
                syncExpectedValueConfirmation(clearInput, clearButton, {
                    clearWhenDisabled: false,
                });
            };

            const syncDeleteConfirmation = () => {
                syncExpectedValueConfirmation(deleteInput, deleteButton, {
                    checkbox: deleteCheckbox,
                });
            };

            if (form.dataset.dangerZoneBound !== '1') {
                if (clearInput instanceof HTMLInputElement) {
                    clearInput.addEventListener('input', syncClearConfirmation);
                    clearInput.addEventListener('change', syncClearConfirmation);
                }

                if (deleteCheckbox instanceof HTMLInputElement) {
                    deleteCheckbox.addEventListener('change', syncDeleteConfirmation);
                }

                if (deleteInput instanceof HTMLInputElement) {
                    deleteInput.addEventListener('input', syncDeleteConfirmation);
                    deleteInput.addEventListener('change', syncDeleteConfirmation);
                }

                form.dataset.dangerZoneBound = '1';
            }

            if (deleteCheckbox instanceof HTMLInputElement && !deleteCheckbox.checked) {
                deleteInput?.setAttribute('disabled', 'disabled');
            }

            syncClearConfirmation();
            syncDeleteConfirmation();

            window.requestAnimationFrame(() => {
                syncClearConfirmation();
                syncDeleteConfirmation();
            });
        });
    }

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
                summary.innerHTML = 'No files selected yet.';
            } else if (maxReached) {
                summary.innerHTML = `Too many files selected.<br>Please keep it to ${String(maxFiles)} CSV files or fewer.`;
            } else {
                summary.innerHTML = `${String(files.length)} file${files.length > 1 ? 's' : ''} selected:`;
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
        const shouldDisable = !hasAccount || !hasFiles;

        submitButton.disabled = shouldDisable;
        syncButtonTitleVisibility(submitButton);
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
                    window.alert(`Please upload no more than ${String(maxFiles)} CSV files at once.`);
                }
            });
        });
    }

    function syncPasswordRequirementPanel(panel) {
        if (!(panel instanceof HTMLElement)) {
            return;
        }

        const form = panel.closest('form');
        const inputId = String(panel.dataset.passwordRequirementsFor || '').trim();
        const passwordInput = inputId !== ''
            ? document.getElementById(inputId)
            : form instanceof HTMLFormElement
                ? form.querySelector('input[type="password"][pattern]')
                : null;

        if (!(passwordInput instanceof HTMLInputElement)) {
            return;
        }

        panel.hidden = passwordInput.value !== '' && passwordInput.validity.valid;
    }

    function initialisePasswordRequirementPanels(root = document) {
        const panels = root.querySelectorAll ? root.querySelectorAll('[data-password-requirements-panel]') : [];

        panels.forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }

            const form = panel.closest('form');
            const inputId = String(panel.dataset.passwordRequirementsFor || '').trim();
            const passwordInput = inputId !== ''
                ? document.getElementById(inputId)
                : form instanceof HTMLFormElement
                    ? form.querySelector('input[type="password"][pattern]')
                    : null;

            if (!(passwordInput instanceof HTMLInputElement)) {
                return;
            }

            syncPasswordRequirementPanel(panel);

            if (panel.dataset.passwordRequirementsBound === '1') {
                return;
            }

            panel.dataset.passwordRequirementsBound = '1';
            passwordInput.addEventListener('input', () => syncPasswordRequirementPanel(panel));
            passwordInput.addEventListener('change', () => syncPasswordRequirementPanel(panel));
        });
    }

    function syncSubmitAction(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || !submitter.form) {
            return;
        }

        const actionValue = submitter.dataset.submitAction;
        if (typeof actionValue !== 'string' || actionValue === '') {
            return;
        }

        const actionField = submitter.form.querySelector('#settings_action_field');
        if (actionField instanceof HTMLInputElement) {
            actionField.value = actionValue;
        }
    }

    function syncSubmitField(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || !submitter.form) {
            return;
        }

        const fieldName = String(submitter.dataset.submitField || '').trim();
        if (fieldName === '') {
            return;
        }

        const field = submitter.form.querySelector(`[name="${escapeCssIdentifier(fieldName)}"]`);
        if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
            field.value = String(submitter.dataset.submitValue ?? '1');
        }
    }

    function syncButtonTitleVisibility(root = document) {
        const buttons = root instanceof HTMLButtonElement
            ? [root]
            : (root.querySelectorAll ? Array.from(root.querySelectorAll('button')) : []);

        buttons.forEach((button) => {
            if (!(button instanceof HTMLButtonElement)) {
                return;
            }

            if (button.dataset.preserveTitle === 'true') {
                return;
            }

            const currentTitle = String(button.getAttribute('title') || '').trim();
            if (currentTitle !== '' && !button.dataset.disabledTitle) {
                button.dataset.disabledTitle = currentTitle;
            }

            const disabledTitle = String(button.dataset.disabledTitle || '').trim();
            if (button.disabled) {
                if (disabledTitle !== '') {
                    button.setAttribute('title', disabledTitle);
                }
                return;
            }

            button.removeAttribute('title');
        });
    }

    function initialiseButtonTitleVisibility() {
        let syncingButtonTitles = false;
        const syncSafely = (root = document) => {
            if (syncingButtonTitles) {
                return;
            }

            syncingButtonTitles = true;
            try {
                syncButtonTitleVisibility(root);
            } finally {
                syncingButtonTitles = false;
            }
        };

        syncSafely(document);

        if (body.dataset.buttonTitleObserverBound === '1') {
            return;
        }

        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type === 'attributes' && mutation.target instanceof HTMLButtonElement) {
                    syncSafely(mutation.target);
                    return;
                }

                mutation.addedNodes.forEach((node) => {
                    if (node instanceof HTMLButtonElement || node instanceof HTMLElement) {
                        syncSafely(node);
                    }
                });
            });
        });

        observer.observe(body, {
            attributes: true,
            attributeFilter: ['disabled'],
            childList: true,
            subtree: true,
        });

        body.dataset.buttonTitleObserverBound = '1';
    }

    function replaceCards(cards) {
        const entries = Object.entries(cards || {});
        const pageStack = document.querySelector('.page-stack');

        entries.forEach(([domId, html], index) => {
            try {
                const current = document.getElementById(domId);

                if (typeof html !== 'string' || html.trim() === '') {
                    if (current) {
                        current.remove();
                    }
                    return;
                }

                const template = document.createElement('template');
                template.innerHTML = html.trim();
                const replacement = template.content.firstElementChild;

                if (replacement instanceof HTMLElement && current) {
                    current.replaceWith(replacement);
                    initialiseCardToggles(replacement);
                    initStateWatchers(replacement);
                    initialiseDirtyActionControls(replacement);
                    initDangerZoneConfirmationControls(replacement);
                    initialiseUploadDropzones(replacement);
                    initialisePasswordRequirementPanels(replacement);
                    initialiseTableCondensedControls(replacement);
                    initialiseCardAutoRefresh(replacement);
                    return;
                }

                if (replacement instanceof HTMLElement && pageStack instanceof HTMLElement) {
                    const nextEntry = entries
                        .slice(index + 1)
                        .find(([nextDomId]) => document.getElementById(nextDomId));
                    const anchor = nextEntry ? document.getElementById(nextEntry[0]) : null;

                    pageStack.insertBefore(replacement, anchor instanceof HTMLElement ? anchor : null);
                    initialisePageCardTabs(replacement);
                    initialiseCardToggles(replacement);
                    initStateWatchers(replacement);
                    initialiseDirtyActionControls(replacement);
                    initDangerZoneConfirmationControls(replacement);
                    initialiseUploadDropzones(replacement);
                    initialisePasswordRequirementPanels(replacement);
                    initialiseTableCondensedControls(replacement);
                    initialiseCardAutoRefresh(replacement);
                }
            } catch (error) {
                console.error(`Failed to replace AJAX card ${domId}.`, error);
            }
        });
    }

    function cardAutoRefreshNodes(root) {
        const nodes = [];

        if (root instanceof HTMLElement && root.matches('.card[data-card-refresh-ms][data-card-key]')) {
            nodes.push(root);
        }

        if (root && typeof root.querySelectorAll === 'function') {
            root.querySelectorAll('.card[data-card-refresh-ms][data-card-key]').forEach((node) => {
                if (node instanceof HTMLElement) {
                    nodes.push(node);
                }
            });
        }

        return nodes;
    }

    function initialiseCardAutoRefresh(root = document) {
        cardAutoRefreshNodes(root).forEach((card) => {
            if (cardAutoRefreshState.has(card)) {
                return;
            }

            const intervalMs = Math.max(5000, Number.parseInt(String(card.dataset.cardRefreshMs || ''), 10));
            const cardKey = String(card.dataset.cardKey || '').trim();
            if (!Number.isFinite(intervalMs) || cardKey === '') {
                return;
            }

            const state = {
                inFlight: false,
                timerId: null,
            };
            cardAutoRefreshState.set(card, state);

            const schedule = () => {
                if (!card.isConnected) {
                    return;
                }

                state.timerId = window.setTimeout(refresh, intervalMs);
            };

            const refresh = async () => {
                if (!card.isConnected) {
                    return;
                }

                if (document.hidden || state.inFlight) {
                    schedule();
                    return;
                }

                state.inFlight = true;
                const payload = {
                    _ajax: '1',
                    _card_refresh: '1',
                    cards: [cardKey],
                };
                const refreshFact = String(card.dataset.cardRefreshFact || '').trim();
                if (refreshFact !== '') {
                    payload._invalidate_fact = refreshFact;
                }

                try {
                    const response = await sendAjax(window.location.href, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                        headers: { 'Content-Type': 'application/json' },
                    });

                    applyAjaxPayloadFragment('cards', () => replaceCards(response.cards));
                } catch (error) {
                    console.error(`Failed to refresh card ${cardKey}.`, error);
                } finally {
                    state.inFlight = false;
                    schedule();
                }
            };

            schedule();
        });
    }

    function activatePageCardTab(tab) {
        if (!(tab instanceof HTMLButtonElement)) {
            return;
        }

        const tablist = tab.closest('[role="tablist"]');
        const tabsRoot = tab.closest('.page-card-tabs');
        const panelId = String(tab.dataset.pageCardTab || '').trim();
        const panel = panelId !== '' ? document.getElementById(panelId) : null;

        if (!(tablist instanceof HTMLElement) || !(tabsRoot instanceof HTMLElement) || !(panel instanceof HTMLElement)) {
            return;
        }

        tablist.querySelectorAll('[role="tab"]').forEach((node) => {
            const button = node instanceof HTMLButtonElement ? node : null;
            if (!button) {
                return;
            }

            const selected = button === tab;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.tabIndex = selected ? 0 : -1;
        });

        tabsRoot.querySelectorAll('.page-card-tab-panel').forEach((node) => {
            if (node instanceof HTMLElement) {
                node.hidden = node !== panel;
            }
        });
    }

    function showPageCardTabForCard(cardKey) {
        const key = String(cardKey || '').trim();
        if (key === '') {
            return;
        }

        const card = document.querySelector(`.card[data-card-key="${escapeCssIdentifier(key)}"]`);
        const panel = card instanceof HTMLElement ? card.closest('.page-card-tab-panel') : null;
        const tab = panel instanceof HTMLElement && panel.id
            ? document.querySelector(`.page-card-tab[data-page-card-tab="${escapeCssIdentifier(panel.id)}"]`)
            : null;

        if (tab instanceof HTMLButtonElement) {
            activatePageCardTab(tab);
        }
    }

    function activatePageCardTabByLabel(control) {
        if (!(control instanceof HTMLElement)) {
            return;
        }

        const label = String(control.dataset.pageCardSwitchTab || '').trim().toLowerCase();
        const tabsRoot = control.closest('.page-card-tabs');
        if (label === '' || !(tabsRoot instanceof HTMLElement)) {
            return;
        }

        const tab = Array.from(tabsRoot.querySelectorAll('.page-card-tab'))
            .find((node) => node instanceof HTMLButtonElement && String(node.textContent || '').trim().toLowerCase() === label);

        if (tab instanceof HTMLButtonElement) {
            activatePageCardTab(tab);
            tab.focus();
        }
    }

    function initialisePageCardTabs(root = document) {
        const tablists = root.querySelectorAll ? root.querySelectorAll('.page-card-tablist') : [];

        tablists.forEach((tablist) => {
            if (!(tablist instanceof HTMLElement) || tablist.dataset.pageCardTabsBound === '1') {
                return;
            }

            const tabs = Array.from(tablist.querySelectorAll('.page-card-tab'))
                .filter((node) => node instanceof HTMLButtonElement);

            tabs.forEach((tab, index) => {
                tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;

                tab.addEventListener('click', () => {
                    activatePageCardTab(tab);
                });

                tab.addEventListener('keydown', (event) => {
                    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                        return;
                    }

                    event.preventDefault();
                    let nextIndex = index;

                    if (event.key === 'Home') {
                        nextIndex = 0;
                    } else if (event.key === 'End') {
                        nextIndex = tabs.length - 1;
                    } else {
                        const offset = event.key === 'ArrowRight' ? 1 : -1;
                        nextIndex = (index + offset + tabs.length) % tabs.length;
                    }

                    const nextTab = tabs[nextIndex];
                    if (nextTab instanceof HTMLButtonElement) {
                        activatePageCardTab(nextTab);
                        nextTab.focus();
                    }
                });
            });

            tablist.dataset.pageCardTabsBound = '1';
        });

        const switchers = root.querySelectorAll ? root.querySelectorAll('[data-page-card-switch-tab]') : [];
        switchers.forEach((switcher) => {
            if (!(switcher instanceof HTMLElement) || switcher.dataset.pageCardSwitchTabBound === '1') {
                return;
            }

            switcher.addEventListener('click', () => {
                activatePageCardTabByLabel(switcher);
            });
            switcher.dataset.pageCardSwitchTabBound = '1';
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
            logFlashMessages(flash);
            scheduleFlashDismissals(flash);
        }
    }

    function logFlashMessages(flashContainer) {
        if (!(flashContainer instanceof HTMLElement)) {
            return;
        }

        const messages = Array.from(flashContainer.querySelectorAll('.alert'));

        messages.forEach((message) => {
            if (!(message instanceof HTMLElement) || message.dataset.flashHistoryLogged === '1') {
                return;
            }

            const type = message.classList.contains('error') ? 'error' : 'success';
            const text = (message.innerText || message.textContent || '')
                .trim()
                .replace(/\s*\n\s*/g, ' - ');

            if (text === '') {
                return;
            }

            message.dataset.flashHistoryLogged = '1';
            flashHistory.unshift({
                timestamp: new Date(),
                type,
                text,
            });

            if (flashHistory.length > flashHistoryLimit) {
                flashHistory.length = flashHistoryLimit;
            }

            updateFlashHistoryPopover();
            console.log(`[flash:${type}] ${text}`);
        });
    }

    function formatFlashHistoryTimestamp(date) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }

        return date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    }

    function updateFlashHistoryPopover() {
        const popover = document.getElementById('flash-history-popover');
        if (!(popover instanceof HTMLElement)) {
            return;
        }

        if (flashHistory.length === 0) {
            popover.innerHTML = '<div class="flash-history-empty">No flash messages yet.</div>';
            return;
        }

        const list = document.createElement('ul');
        list.className = 'flash-history-list';

        flashHistory.forEach((entry) => {
            const item = document.createElement('li');
            item.className = `flash-history-item ${entry.type}`;

            const timestamp = document.createElement('span');
            timestamp.className = 'flash-history-time';
            timestamp.textContent = formatFlashHistoryTimestamp(entry.timestamp);

            const text = document.createElement('span');
            text.className = 'flash-history-text';
            text.textContent = entry.text;

            item.append(timestamp, text);
            list.appendChild(item);
        });

        popover.replaceChildren(list);
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

    function applyAjaxPayloadFragment(name, callback) {
        try {
            callback();
        } catch (error) {
            console.error(`Failed to apply AJAX ${name} update.`, error);
        }
    }

    function beginButtonProcessingState(submitter) {
        if (!(submitter instanceof HTMLButtonElement)) {
            return () => {};
        }

        const processingText = String(submitter.dataset.processingText || '').trim();
        if (processingText === '') {
            return () => {};
        }

        const originalHtml = submitter.innerHTML;
        const originalDisabled = submitter.disabled;
        const shouldDisable = String(submitter.dataset.processingState || '').trim().toLowerCase() === 'disabled';

        submitter.textContent = processingText;
        if (shouldDisable) {
            submitter.disabled = true;
            submitter.setAttribute('aria-disabled', 'true');
        }

        return () => {
            if (!submitter.isConnected) {
                return;
            }

            submitter.innerHTML = originalHtml;
            submitter.disabled = originalDisabled;
            if (!originalDisabled) {
                submitter.removeAttribute('aria-disabled');
            }
        };
    }

    function clearChickenCheck(refocus = false) {
        document.querySelectorAll('.chicken-check-backdrop').forEach((node) => node.remove());
        document.querySelectorAll('.chicken-check-window').forEach((node) => node.remove());

        if (activeChickenCheckButton instanceof HTMLButtonElement) {
            delete activeChickenCheckButton.dataset.chickenArmed;
            if (refocus && activeChickenCheckButton.isConnected) {
                activeChickenCheckButton.focus();
            }
        }

        activeChickenCheckButton = null;
    }

    function passChickenCheck(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || submitter.dataset.chickenCheck !== 'true') {
            return true;
        }

        const form = submitter.form;
        if (!(form instanceof HTMLFormElement)) {
            return true;
        }

        if (submitter.dataset.chickenArmed === 'true') {
            clearChickenCheck(false);
            return true;
        }

        clearChickenCheck(false);

        const backdrop = document.createElement('div');
        backdrop.className = 'chicken-check-backdrop';

        const windowShell = document.createElement('div');
        windowShell.className = 'warn chicken-check-window';
        windowShell.setAttribute('role', 'alertdialog');

        const title = document.createElement('div');
        title.className = 'chicken-check-title';
        title.textContent = String(submitter.dataset.chickenTitle || 'Confirm delete');

        const message = document.createElement('div');
        message.className = 'chicken-check-message';
        message.innerHTML = String(submitter.dataset.chickenMessage || 'Press the button again to confirm.');

        const actions = document.createElement('div');
        actions.className = 'chicken-check-actions';

        const confirm = document.createElement('button');
        confirm.className = String(submitter.dataset.chickenButtonClass || 'button danger');
        confirm.type = 'button';
        confirm.textContent = String(submitter.dataset.chickenConfirmText || submitter.textContent || 'Confirm');
        confirm.addEventListener('click', () => {
            submitter.dataset.chickenArmed = 'true';
            form.requestSubmit(submitter);
        });

        const cancel = document.createElement('button');
        cancel.className = 'button button-inline';
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', () => clearChickenCheck(true));

        actions.append(confirm, cancel);
        windowShell.append(title, message, actions);

        submitter.dataset.chickenArmed = 'true';
        activeChickenCheckButton = submitter;
        document.body.appendChild(backdrop);
        document.body.appendChild(windowShell);
        submitter.focus();

        return false;
    }

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        resolveSelfVisibleCardField(form);

        if (form.dataset.ajax !== 'true') {
            return;
        }

        event.preventDefault();

        if (!passChickenCheck(event.submitter)) {
            return;
        }

        syncSubmitAction(event.submitter);
        syncSubmitField(event.submitter);

        const formData = new FormData(form);

        formData.set('_ajax', '1');
        appendCurrentPageCardKeys(formData, form);
        appendRequestedVisibleCard(formData, event.submitter);

        const method = (form.method || 'POST').toUpperCase();

        const requestUrl = method === 'GET'
            ? requestUrlWithFormData(formRequestUrl(form), formData)
            : formRequestUrl(form);

        if (event.submitter instanceof HTMLButtonElement && event.submitter.name) {
            formData.append(event.submitter.name, event.submitter.value);
        }

        const requestBody = method === 'GET' ? null : JSON.stringify(formDataToJsonPayload(formData));
        const requestHeaders = method === 'GET' ? undefined : { 'Content-Type': 'application/json' };
        const requestPayload = method === 'GET' ? null : formDataToJsonPayload(formData);
        const ajaxNonce = requiresAjaxNonce(method, requestPayload) ? reserveAjaxNonce() : null;

        if (ajaxNonce && requestPayload) {
            requestPayload.ajax_nonce = ajaxNonce;
        }

        const restoreProcessingState = beginButtonProcessingState(event.submitter);

        try {
            const payload = await sendAjax(requestUrl, {
                method,
                body: method === 'GET' ? null : JSON.stringify(requestPayload),
                headers: requestHeaders,
                transport: form.dataset.ajaxTransport === 'xhr' ? 'xhr' : 'fetch',
            });

            completeAjaxNonce(ajaxNonce, payload?.ajax_nonce);

            if (navigateToAjaxPayloadPage(payload)) {
                return;
            }

            if (payload && typeof payload.download_url === 'string' && payload.download_url.trim() !== '') {
                triggerFileDownload(payload.download_url);
                return;
            }

            applyAjaxPayloadFragment('sidebar', () => replaceSidebar(payload.sidebar_html));
            applyAjaxPayloadFragment('cards', () => replaceCards(payload.cards));
            applyAjaxPayloadFragment('flash', () => replaceFlash(payload.flash_html));
            applyAjaxPayloadFragment('visible card', () => showPageCardTabForCard(payload.show_card));

        } catch (error) {
            restoreAjaxNonce(ajaxNonce);
            const flashHtml = error && error.payload && typeof error.payload.flash_html === 'string'
                ? error.payload.flash_html
                : renderErrorFlashHtml(error ? error.payload : null);

            if (flashHtml !== '') {
                replaceFlash(flashHtml);
            }

            handleAjaxSecurityFailure(error ? error.payload : null);

            console.error(error);
        } finally {
            restoreProcessingState();
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

        form.requestSubmit();
    });

    initialiseSidebar(document);
    initialisePageCardTabs(document);
    initialiseCardToggles();
    initStateWatchers(document);
    initialiseDirtyActionControls(document);
    initDangerZoneConfirmationControls(document);
    initialiseUploadDropzones(document);
    initialisePasswordRequirementPanels(document);
    initialiseTableCondensedControls(document);
    initialiseCardAutoRefresh(document);
    initialiseButtonTitleVisibility();
    logFlashMessages(document.getElementById('flash-messages'));
    scheduleFlashDismissals(document.getElementById('flash-messages'));
    loadAjaxNonceBootstrap();
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
