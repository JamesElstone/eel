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

    function normaliseValidationType(value) {
        const type = String(value || '').trim().toLowerCase();

        if (type === 'bool' || type === 'boolean') {
            return 'boolean';
        }

        if (type === 'int' || type === 'integer') {
            return 'int';
        }

        if (type === 'float' || type === 'decimal' || type === 'number') {
            return 'float';
        }

        if (type === 'string' || type === 'ascii') {
            return 'ascii';
        }

        return type === 'null' ? 'null' : '';
    }

    function validationTypeControlSelector(token) {
        const attributeValue = String(token).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

        return `[data-validate-type-control="${attributeValue}"]`;
    }

    function validationTypeTargetSelector(token) {
        const attributeValue = String(token).replace(/\\/g, '\\\\').replace(/"/g, '\\"');

        return `[data-validate-type-target="${attributeValue}"]`;
    }

    function validationPairScope(control) {
        if (!(control instanceof HTMLElement)) {
            return document;
        }

        const form = control.closest('form');

        return form instanceof HTMLFormElement ? form : document;
    }

    function dynamicValidationType(control) {
        if (!(control instanceof HTMLElement)) {
            return '';
        }

        const token = String(control.dataset.validateTypeTarget || '').trim();
        if (token === '') {
            return '';
        }

        const source = validationPairScope(control).querySelector(validationTypeControlSelector(token));
        if (!isFormControl(source)) {
            return '';
        }

        return normaliseValidationType(source.value);
    }

    function validationTypeForControl(control) {
        if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) {
            return '';
        }

        const dynamicType = dynamicValidationType(control);
        if (dynamicType !== '') {
            return dynamicType;
        }

        if (control instanceof HTMLInputElement && control.dataset.digitsOnly === 'true') {
            return 'int';
        }

        if (control.hasAttribute('data-validate-boolean')) {
            return 'boolean';
        }

        if (control.hasAttribute('data-validate-int')) {
            return 'int';
        }

        if (control.hasAttribute('data-validate-float') || control.hasAttribute('data-validate-number')) {
            return 'float';
        }

        if (control.hasAttribute('data-validate-ascii') || control.hasAttribute('data-validate-string')) {
            return 'ascii';
        }

        return '';
    }

    function sanitizeValidationValue(value, type) {
        const stringValue = String(value || '');

        if (type === 'int') {
            return stringValue.replace(/[^0-9]/g, '');
        }

        if (type === 'float') {
            let hasDecimalPoint = false;
            let sanitized = '';

            Array.from(stringValue).forEach((character) => {
                if (character >= '0' && character <= '9') {
                    sanitized += character;
                    return;
                }

                if (character === '.' && !hasDecimalPoint) {
                    sanitized += character;
                    hasDecimalPoint = true;
                }
            });

            return sanitized;
        }

        if (type === 'ascii') {
            return Array.from(stringValue)
                .filter((character) => character.charCodeAt(0) < 128)
                .join('');
        }

        return stringValue;
    }

    function validatedBeforeInputValue(input, insertedValue) {
        const value = input.value;
        const start = Number.isFinite(input.selectionStart) ? input.selectionStart : value.length;
        const end = Number.isFinite(input.selectionEnd) ? input.selectionEnd : start;

        return value.substring(0, start) + insertedValue + value.substring(end);
    }

    function insertedValueMatchesValidation(insertedValue, type, input) {
        if (type === 'int') {
            return /^[0-9]*$/.test(insertedValue);
        }

        if (type === 'float') {
            return /^[0-9.]*$/.test(insertedValue)
                && (validatedBeforeInputValue(input, insertedValue).match(/\./g) || []).length <= 1;
        }

        if (type === 'ascii') {
            return Array.from(insertedValue).every((character) => character.charCodeAt(0) < 128);
        }

        return true;
    }

    function enforceValidatedInputBeforeInput(event) {
        const input = event.target;
        if (!(input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) || event.isComposing) {
            return;
        }

        const type = validationTypeForControl(input);
        if (type === '' || type === 'boolean') {
            return;
        }

        if (type === 'null') {
            event.preventDefault();
            return;
        }

        const insertedValue = event.data;
        if (typeof insertedValue !== 'string' || insertedValue === '') {
            return;
        }

        if (!insertedValueMatchesValidation(insertedValue, type, input)) {
            event.preventDefault();
        }
    }

    function restoreValidationDisabledState(control) {
        if (!(control instanceof HTMLElement) || control.dataset.validationNullDisabled !== '1') {
            return;
        }

        if (isFormControl(control)) {
            control.disabled = control.dataset.validationWasDisabled === 'true';
        }

        delete control.dataset.validationNullDisabled;
        delete control.dataset.validationWasDisabled;
    }

    function syncDynamicValidationAttributes(control, type) {
        if (!(control instanceof HTMLElement) || String(control.dataset.validateTypeTarget || '').trim() === '') {
            return;
        }

        [
            'data-validate-boolean',
            'data-validate-int',
            'data-validate-float',
            'data-validate-ascii',
            'data-validate-number',
            'data-validate-string',
        ].forEach((attributeName) => control.removeAttribute(attributeName));

        const attributeName = {
            boolean: 'data-validate-boolean',
            int: 'data-validate-int',
            float: 'data-validate-float',
            ascii: 'data-validate-ascii',
        }[type] || '';

        if (attributeName !== '') {
            control.setAttribute(attributeName, '');
        }

        if (type !== '') {
            control.dataset.activeValidationType = type;
        } else {
            delete control.dataset.activeValidationType;
        }
    }

    function applyNullValidationState(control, type) {
        if (!isFormControl(control)) {
            return;
        }

        if (type !== 'null') {
            restoreValidationDisabledState(control);
            return;
        }

        if (control.dataset.validationNullDisabled !== '1') {
            control.dataset.validationWasDisabled = control.disabled ? 'true' : 'false';
        }

        control.dataset.validationNullDisabled = '1';
        control.disabled = true;
        if ('value' in control) {
            control.value = '';
        }
    }

    function sanitizeValidatedInput(control) {
        if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) {
            return;
        }

        const type = validationTypeForControl(control);
        syncDynamicValidationAttributes(control, type);
        applyNullValidationState(control, type);

        if (type === '' || type === 'null') {
            return;
        }

        if (control instanceof HTMLSelectElement) {
            if (type === 'boolean' && !['true', 'false'].includes(control.value)) {
                control.value = '';
            }
            return;
        }

        if (type === 'boolean') {
            return;
        }

        const previousValue = control.value;
        const previousStart = control.selectionStart;
        const previousEnd = control.selectionEnd;
        const nextValue = sanitizeValidationValue(previousValue, type);
        const maxLength = Number.parseInt(control.getAttribute('maxlength') || '0', 10);
        const constrainedValue = maxLength > 0 ? nextValue.substring(0, maxLength) : nextValue;

        if (previousValue === constrainedValue) {
            return;
        }

        control.value = constrainedValue;

        if (
            document.activeElement === control
            && typeof control.setSelectionRange === 'function'
            && Number.isFinite(previousStart)
            && Number.isFinite(previousEnd)
        ) {
            const removedBeforeCursor = previousValue.substring(0, previousStart).length
                - sanitizeValidationValue(previousValue.substring(0, previousStart), type).length;
            const nextStart = Math.max(0, previousStart - removedBeforeCursor);
            const nextEnd = Math.max(nextStart, previousEnd - removedBeforeCursor);
            control.setSelectionRange(
                Math.min(nextStart, constrainedValue.length),
                Math.min(nextEnd, constrainedValue.length)
            );
        }
    }

    function syncDynamicValidationTargets(control) {
        if (!(control instanceof HTMLElement)) {
            return;
        }

        const token = String(control.dataset.validateTypeControl || '').trim();
        if (token === '') {
            return;
        }

        validationPairScope(control).querySelectorAll(validationTypeTargetSelector(token)).forEach((target) => {
            sanitizeValidatedInput(target);
        });
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
        const defaultEnabled = String(toggle?.dataset?.tableCondensedDefault || '') === '1';

        if (key !== '' && afStorageAvailable('localStorage')) {
            try {
                const stored = window.localStorage.getItem(key);
                if (stored === '1' || stored === '0') {
                    return stored === '1';
                }
            } catch (error) {
                return defaultEnabled;
            }
        }

        return defaultEnabled;
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

    function showClientFlash(message, type = 'success') {
        const className = type === 'error' ? 'error' : 'success';
        replaceFlash(`<div class="alert ${className}">${escapeHtml(message)}</div>`);
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
        options = ajaxOptionsWithSiteContext(options);

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

    function ajaxOptionsWithSiteContext(options = {}) {
        const method = String(options.method || 'GET').toUpperCase();
        if (method === 'GET' || typeof options.body !== 'string') {
            return options;
        }

        const contentType = ajaxOptionsContentType(options.headers);
        if (!contentType.includes('application/json')) {
            return options;
        }

        try {
            const payload = JSON.parse(options.body || '{}');
            if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return options;
            }

            appendSiteContextSelectionsToPayload(payload);

            return {
                ...options,
                body: JSON.stringify(payload),
            };
        } catch (error) {
            return options;
        }
    }

    function ajaxOptionsContentType(headers) {
        if (headers instanceof Headers) {
            return String(headers.get('Content-Type') || '').toLowerCase();
        }

        if (!headers || typeof headers !== 'object') {
            return '';
        }

        const entries = Object.entries(headers);
        const match = entries.find(([name]) => String(name).toLowerCase() === 'content-type');

        return String(match ? match[1] : '').toLowerCase();
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

    function copyableTableExportFormat(format) {
        return ['csv', 'tsv', 'ascii'].includes(String(format || '').trim().toLowerCase());
    }

    function tableExportFormatForForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return '';
        }

        const field = form.querySelector('input[name="_table_export_prepare"]');

        return field instanceof HTMLInputElement
            ? String(field.value || '').trim().toLowerCase()
            : '';
    }

    function rememberTableExportClipboardIntent(event) {
        const button = event.target instanceof Element
            ? event.target.closest('button')
            : null;

        if (!(button instanceof HTMLButtonElement) || !(button.form instanceof HTMLFormElement)) {
            return;
        }

        const format = tableExportFormatForForm(button.form);
        if (event.ctrlKey && copyableTableExportFormat(format)) {
            button.dataset.tableExportClipboard = '1';
            return;
        }

        delete button.dataset.tableExportClipboard;
    }

    function tableExportClipboardRequested(submitter) {
        if (!(submitter instanceof HTMLButtonElement) || !(submitter.form instanceof HTMLFormElement)) {
            return false;
        }

        return submitter.dataset.tableExportClipboard === '1'
            && copyableTableExportFormat(tableExportFormatForForm(submitter.form));
    }

    function clearTableExportClipboardIntent(submitter) {
        if (submitter instanceof HTMLButtonElement) {
            delete submitter.dataset.tableExportClipboard;
        }
    }

    async function copyTableExportToClipboard(payload) {
        const text = typeof payload?.clipboard_text === 'string' ? payload.clipboard_text : '';
        const format = String(payload?.clipboard_format || 'table').trim().toUpperCase() || 'TABLE';

        if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
            showClientFlash('Clipboard copy is not available in this browser context.', 'error');
            return;
        }

        try {
            await navigator.clipboard.writeText(text);
            showClientFlash(`${format} copied to clipboard.`);
        } catch (error) {
            showClientFlash('Unable to copy table export to the clipboard.', 'error');
            console.error(error);
        }
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

    function collectSiteContextSelections() {
        const selections = [];
        const selects = document.querySelectorAll('.site-context-slot select[data-site-context-key]');

        selects.forEach((select) => {
            if (!(select instanceof HTMLSelectElement)) {
                return;
            }

            const key = String(select.dataset.siteContextKey || '').trim();
            if (key === '') {
                return;
            }

            const inputName = normaliseSiteContextInputName(select.dataset.siteContextInputName);
            selections.push({
                key,
                inputName,
                value: String(select.value ?? ''),
            });
        });

        return selections;
    }

    function normaliseSiteContextInputName(inputName) {
        const value = String(inputName || '').trim();

        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(value) ? value : '';
    }

    function appendSiteContextSelectionsToFormData(formData, form = null) {
        if (!(formData instanceof FormData)) {
            return;
        }

        formData.delete('site_context_keys[]');
        formData.delete('site_context_keys');
        formData.delete('site_context_values[]');
        formData.delete('site_context_values');

        collectSiteContextSelections().forEach((selection) => {
            formData.append('site_context_keys[]', selection.key);
            formData.append('site_context_values[]', selection.value);

            if (selection.inputName !== '' && !formHasEnabledNamedField(form, selection.inputName)) {
                formData.set(selection.inputName, selection.value);
            }
        });
    }

    function syncSiteContextFieldsToForm(form) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        form.querySelectorAll('input[data-site-context-submit-field="true"]').forEach((node) => {
            node.remove();
        });

        collectSiteContextSelections().forEach((selection) => {
            const keyField = document.createElement('input');
            keyField.type = 'hidden';
            keyField.name = 'site_context_keys[]';
            keyField.value = selection.key;
            keyField.dataset.siteContextSubmitField = 'true';

            const valueField = document.createElement('input');
            valueField.type = 'hidden';
            valueField.name = 'site_context_values[]';
            valueField.value = selection.value;
            valueField.dataset.siteContextSubmitField = 'true';

            form.append(keyField, valueField);

            if (selection.inputName !== '' && !formHasEnabledNamedField(form, selection.inputName)) {
                const namedField = document.createElement('input');
                namedField.type = 'hidden';
                namedField.name = selection.inputName;
                namedField.value = selection.value;
                namedField.dataset.siteContextSubmitField = 'true';
                form.append(namedField);
            }
        });
    }

    function appendSiteContextSelectionsToPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const selections = collectSiteContextSelections();
        if (selections.length === 0) {
            delete payload.site_context_keys;
            delete payload.site_context_values;
            return;
        }

        payload.site_context_keys = selections.map((selection) => selection.key);
        payload.site_context_values = selections.map((selection) => selection.value);

        selections.forEach((selection) => {
            if (selection.inputName !== '' && !Object.prototype.hasOwnProperty.call(payload, selection.inputName)) {
                payload[selection.inputName] = selection.value;
            }
        });
    }

    function formHasEnabledNamedField(form, fieldName) {
        if (!(form instanceof HTMLFormElement)) {
            return false;
        }

        const escapedName = escapeCssIdentifier(fieldName);
        const fields = form.querySelectorAll(`[name="${escapedName}"]`);

        return Array.from(fields).some((field) => {
            if (!(field instanceof HTMLInputElement)
                && !(field instanceof HTMLSelectElement)
                && !(field instanceof HTMLTextAreaElement)
                && !(field instanceof HTMLButtonElement)) {
                return false;
            }

            return !field.disabled && field.dataset.siteContextSubmitField !== 'true';
        });
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

    function isFormControl(node) {
        return node instanceof HTMLInputElement
            || node instanceof HTMLSelectElement
            || node instanceof HTMLTextAreaElement
            || node instanceof HTMLButtonElement;
    }

    function visibleWhenFieldSelector(fieldName) {
        const escapedAttributeValue = String(fieldName).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        const escapedFieldName = escapeCssIdentifier(fieldName);

        return `[name="${escapedAttributeValue}"], #${escapedFieldName}`;
    }

    function visibleWhenSourceScope(target) {
        if (!(target instanceof HTMLElement)) {
            return document;
        }

        const form = target.closest('form');

        return form instanceof HTMLFormElement ? form : document;
    }

    function visibleWhenSourceControls(target) {
        if (!(target instanceof HTMLElement)) {
            return [];
        }

        const fieldName = String(target.dataset.visibleWhenField || '').trim();
        if (fieldName === '') {
            return [];
        }

        try {
            return Array.from(visibleWhenSourceScope(target).querySelectorAll(visibleWhenFieldSelector(fieldName)))
                .filter((node) => isFormControl(node));
        } catch (error) {
            console.error('Failed to resolve visible-when source field.', error);

            return [];
        }
    }

    function visibleWhenControlValues(control) {
        if (control instanceof HTMLSelectElement && control.multiple) {
            return Array.from(control.selectedOptions).map((option) => option.value);
        }

        if (control instanceof HTMLInputElement && (control.type === 'checkbox' || control.type === 'radio')) {
            return control.checked ? [control.value] : [];
        }

        return [control.value ?? ''];
    }

    function visibleWhenFieldMatches(target) {
        const expectedValue = String(target.dataset.visibleWhenValue ?? '');
        const controls = visibleWhenSourceControls(target);

        return controls.some((control) => visibleWhenControlValues(control).includes(expectedValue));
    }

    function restoreVisibleWhenControl(control) {
        if (!isFormControl(control) || control.dataset.visibleWhenDisabled !== '1') {
            return;
        }

        control.disabled = control.dataset.visibleWhenWasDisabled === 'true';
        delete control.dataset.visibleWhenDisabled;
        delete control.dataset.visibleWhenWasDisabled;
    }

    function syncVisibleWhenTarget(target) {
        if (!(target instanceof HTMLElement)) {
            return;
        }

        const visible = visibleWhenFieldMatches(target);
        const disableNestedControls = String(target.dataset.visibleWhenDisableControls || '').trim().toLowerCase() !== 'false';
        const nestedControls = target.querySelectorAll('input, select, textarea, button');

        target.hidden = !visible;
        target.setAttribute('aria-hidden', visible ? 'false' : 'true');

        nestedControls.forEach((control) => {
            if (!isFormControl(control)) {
                return;
            }

            if (visible || !disableNestedControls) {
                restoreVisibleWhenControl(control);
                return;
            }

            if (control.dataset.visibleWhenDisabled !== '1') {
                control.dataset.visibleWhenWasDisabled = control.disabled ? 'true' : 'false';
            }

            control.dataset.visibleWhenDisabled = '1';
            control.disabled = true;
        });
    }

    function syncVisibleWhenField(field) {
        if (!isFormControl(field)) {
            return;
        }

        const identifiers = new Set();
        const fieldName = String(field.getAttribute('name') || '').trim();
        const fieldId = String(field.id || '').trim();

        if (fieldName !== '') {
            identifiers.add(fieldName);
        }

        if (fieldId !== '') {
            identifiers.add(fieldId);
        }

        if (identifiers.size === 0) {
            return;
        }

        document.querySelectorAll('[data-visible-when-field]').forEach((target) => {
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const targetFieldName = String(target.dataset.visibleWhenField || '').trim();
            if (identifiers.has(targetFieldName)) {
                syncVisibleWhenTarget(target);
            }
        });
    }

    function initialiseVisibleWhenControls(root = document) {
        const targets = [];

        if (root instanceof HTMLElement && root.matches('[data-visible-when-field]')) {
            targets.push(root);
        }

        if (root.querySelectorAll) {
            root.querySelectorAll('[data-visible-when-field]').forEach((node) => {
                targets.push(node);
            });
        }

        targets.forEach((target) => {
            if (target instanceof HTMLElement) {
                syncVisibleWhenTarget(target);
            }
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

    function initDangerZoneConfirmationControls(root = document) {
        const forms = root.querySelectorAll ? root.querySelectorAll('form[data-ajax="true"]') : [];

        forms.forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const clearInput = form.querySelector('[data-clear-confirm-input]');
            const clearButton = form.querySelector('[data-clear-confirm-button], #clear-imported-data-button');
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
                        updateCardMaximizedBodyState();
                    }
                    return;
                }

                const template = document.createElement('template');
                template.innerHTML = html.trim();
                const replacement = template.content.firstElementChild;

                if (replacement instanceof HTMLElement && current) {
                    current.replaceWith(replacement);
                    initialisePageCardTabs(replacement);
                    initialiseCardToggles(replacement);
                    initStateWatchers(replacement);
                    initialiseVisibleWhenControls(replacement);
                    initialiseDirtyActionControls(replacement);
                    initDangerZoneConfirmationControls(replacement);
                    initialiseUploadDropzones(replacement);
                    initialisePasswordRequirementPanels(replacement);
                    initialiseTableCondensedControls(replacement);
                    initialiseCardAutoRefresh(replacement);
                    updateCardMaximizedBodyState();
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
                    initialiseVisibleWhenControls(replacement);
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

        initialiseVisibleWhenControls(document);
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
                appendSiteContextSelectionsToPayload(payload);

                try {
                    const response = await sendAjax(window.location.href, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                        headers: { 'Content-Type': 'application/json' },
                    });

                    applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(response.site_context_html));
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

    function prefersReducedMotion() {
        return window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function scrollPageStackToTarget(pageStack, target, behavior) {
        if (!(pageStack instanceof HTMLElement) || !(target instanceof HTMLElement)) {
            return false;
        }

        const canScroll = pageStack.scrollHeight > pageStack.clientHeight + 1;
        if (!canScroll) {
            return false;
        }

        const stackRect = pageStack.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const marginTop = Number.parseFloat(window.getComputedStyle(target).scrollMarginTop || '0');
        const nextScrollTop = pageStack.scrollTop + targetRect.top - stackRect.top - (Number.isFinite(marginTop) ? marginTop : 0);

        pageStack.scrollTo({
            top: Math.max(0, nextScrollTop),
            behavior,
        });

        return true;
    }

    function focusRevealedCard(card) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        const preferred = card.querySelector('[data-card-reveal-focus]');
        const title = card.querySelector('.card-title');
        const target = preferred instanceof HTMLElement
            ? preferred
            : (title instanceof HTMLElement ? title : card);

        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
        }

        try {
            target.focus({ preventScroll: true });
        } catch (error) {
            target.focus();
        }
    }

    function revealPageCard(cardKey, options = {}) {
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

        window.requestAnimationFrame(() => {
            const target = card.closest('.page-stack-card') || card;
            const behavior = prefersReducedMotion() ? 'auto' : 'smooth';
            const pageStack = target instanceof HTMLElement ? target.closest('.page-stack') : null;

            if (!scrollPageStackToTarget(pageStack, target, behavior) && target instanceof HTMLElement) {
                target.scrollIntoView({
                    block: 'start',
                    inline: 'nearest',
                    behavior,
                });
            }

            focusRevealedCard(card);
        });
    }

    function activatePageCardTabByLabel(control) {
        if (!(control instanceof HTMLElement)) {
            return;
        }

        const label = String(control.dataset.pageCardSwitchTab || '').trim().toLowerCase();
        const tabsRoot = control.closest('.page-card-tabs') || document.querySelector('.page-card-tabs');
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

    function updateCardMaximizedBodyState() {
        if (!(body instanceof HTMLElement)) {
            return;
        }

        body.classList.toggle('card-maximized-active', Boolean(document.querySelector('.card.card-maximized')));
    }

    function setCardMaximized(card, maximized, focusToggle = false) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        card.classList.toggle('card-maximized', maximized);

        const toggle = card.querySelector('[data-card-size-toggle]');
        if (toggle instanceof HTMLButtonElement) {
            toggle.setAttribute('aria-pressed', maximized ? 'true' : 'false');
            toggle.setAttribute('aria-label', maximized ? 'Minimize card' : 'Maximize card');

            if (focusToggle) {
                toggle.focus({preventScroll: true});
            }
        }

        updateCardMaximizedBodyState();
    }

    function toggleCardMaximized(toggle) {
        if (!(toggle instanceof HTMLButtonElement)) {
            return;
        }

        const card = toggle.closest('.card');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        const nextMaximized = !card.classList.contains('card-maximized');
        if (nextMaximized) {
            document.querySelectorAll('.card.card-maximized').forEach((maximizedCard) => {
                if (maximizedCard instanceof HTMLElement && maximizedCard !== card) {
                    setCardMaximized(maximizedCard, false);
                }
            });
        }

        setCardMaximized(card, nextMaximized);
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

            const type = message.classList.contains('error')
                ? 'error'
                : (message.classList.contains('warning') ? 'warning' : 'success');
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
            const empty = document.createElement('div');
            empty.className = 'flash-history-empty';
            empty.textContent = 'No flash messages yet.';
            popover.replaceChildren(empty);
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

    function replaceSiteContextSlots(slotHtml) {
        if (!slotHtml || typeof slotHtml !== 'object') {
            return;
        }

        Object.entries(slotHtml).forEach(([slot, html]) => {
            const slotName = String(slot || '').trim();
            if (slotName === '') {
                return;
            }

            const current = document.getElementById(`site-context-${slotName}-slot`);
            if (!(current instanceof HTMLElement)) {
                return;
            }

            current.innerHTML = typeof html === 'string' ? html : '';
        });
    }

    function replaceDeveloperOptionsStatus(html) {
        const current = document.getElementById('developer-options-status-slot');
        if (!(current instanceof HTMLElement)) {
            return;
        }

        current.innerHTML = typeof html === 'string' ? html : '';
    }

    function replaceTopbar(html) {
        if (typeof html !== 'string') {
            return;
        }

        const current = document.getElementById('topbar-shell');
        if (html.trim() === '') {
            if (current instanceof HTMLElement) {
                current.remove();
            }
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const replacement = template.content.firstElementChild;
        if (!(replacement instanceof HTMLElement)) {
            return;
        }

        if (current instanceof HTMLElement) {
            current.replaceWith(replacement);
            return;
        }

        const flash = document.getElementById('flash-messages');
        if (flash instanceof HTMLElement) {
            flash.before(replacement);
        }
    }

    function replacePageFooter(html) {
        if (typeof html !== 'string' || html.trim() === '') {
            return;
        }

        const current = document.getElementById('page-footer');
        if (!(current instanceof HTMLElement)) {
            return;
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const replacement = template.content.firstElementChild;

        if (replacement instanceof HTMLElement) {
            current.replaceWith(replacement);
            renderPageLoadTime();
        }
    }

    function formHasRequiredInviteContact(form) {
        if (!(form instanceof HTMLFormElement) || form.dataset.requireInviteContact !== 'true') {
            return true;
        }

        const email = form.querySelector('[data-invite-contact-field="email"]');
        const mobile = form.querySelector('[data-invite-contact-field="mobile"]');
        const hasEmail = email instanceof HTMLInputElement && email.value.trim() !== '';
        const hasMobile = mobile instanceof HTMLInputElement && mobile.value.trim() !== '';
        const clearContactValidity = () => {
            if (email instanceof HTMLInputElement) {
                email.setCustomValidity('');
            }
            if (mobile instanceof HTMLInputElement) {
                mobile.setCustomValidity('');
            }
        };

        if (hasEmail || hasMobile) {
            clearContactValidity();
            return true;
        }

        const message = 'Enter an email address or mobile number.';
        if (email instanceof HTMLInputElement) {
            email.setCustomValidity(message);
            email.reportValidity();
            email.addEventListener('input', clearContactValidity, { once: true });
            if (mobile instanceof HTMLInputElement) {
                mobile.addEventListener('input', clearContactValidity, { once: true });
            }
            return false;
        }

        if (mobile instanceof HTMLInputElement) {
            mobile.setCustomValidity(message);
            mobile.reportValidity();
            mobile.addEventListener('input', clearContactValidity, { once: true });
        }

        return false;
    }

    function selectUserCreateMode(button) {
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const mode = String(button.dataset.userCreateModeButton || '').trim();
        const container = button.closest('.card-body');
        if (mode === '' || !(container instanceof HTMLElement)) {
            return;
        }

        container.querySelectorAll('[data-user-create-mode-button]').forEach((candidate) => {
            if (!(candidate instanceof HTMLButtonElement)) {
                return;
            }

            candidate.setAttribute('aria-selected', candidate === button ? 'true' : 'false');
        });

        container.querySelectorAll('[data-user-create-mode-panel]').forEach((panel) => {
            if (!(panel instanceof HTMLElement)) {
                return;
            }

            const selected = String(panel.dataset.userCreateModePanel || '').trim() === mode;
            panel.hidden = !selected;
        });
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

    function normaliseAjaxPendingBlurScope(value) {
        const scope = String(value || '').trim().toLowerCase();

        return ['none', 'card', 'page'].includes(scope) ? scope : '';
    }

    function controlAjaxPendingBlurScope(control) {
        return control instanceof HTMLElement
            ? normaliseAjaxPendingBlurScope(control.dataset.blurScope)
            : '';
    }

    function setFormPendingBlurOverride(form, control) {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const scope = controlAjaxPendingBlurScope(control);
        if (scope !== '') {
            form.dataset.ajaxPendingBlurOverride = scope;
            return;
        }

        delete form.dataset.ajaxPendingBlurOverride;
    }

    function ajaxPendingBlurScope(form) {
        if (form instanceof HTMLFormElement) {
            const overrideScope = normaliseAjaxPendingBlurScope(form.dataset.ajaxPendingBlurOverride);
            if (overrideScope !== '') {
                return overrideScope;
            }
        }

        const pageStack = document.querySelector('.page-stack[data-ajax-pending-blur]');
        return pageStack instanceof HTMLElement
            ? normaliseAjaxPendingBlurScope(pageStack.dataset.ajaxPendingBlur)
            : '';
    }

    function ajaxPendingBlurTarget(form) {
        const scope = ajaxPendingBlurScope(form);
        if (scope === 'page') {
            const pageStack = document.querySelector('.page-stack[data-ajax-pending-blur]');

            return pageStack instanceof HTMLElement ? pageStack : null;
        }

        if (scope !== 'card' || !(form instanceof HTMLFormElement)) {
            return null;
        }

        const card = form.closest('.card[data-card-key]');
        const cardBody = card instanceof HTMLElement ? card.querySelector('.card-body') : null;

        return cardBody instanceof HTMLElement ? cardBody : null;
    }

    function beginAjaxPendingBlur(form) {
        const target = ajaxPendingBlurTarget(form);
        if (!(target instanceof HTMLElement)) {
            return () => {};
        }

        const pendingCount = Math.max(0, Number.parseInt(String(target.dataset.ajaxPendingCount || '0'), 10) || 0);
        if (pendingCount === 0) {
            target.dataset.ajaxPendingHadAriaBusy = target.hasAttribute('aria-busy') ? 'true' : 'false';
            target.dataset.ajaxPendingAriaBusy = target.getAttribute('aria-busy') || '';
            target.classList.add('is-ajax-pending');
            target.setAttribute('aria-busy', 'true');
        }

        target.dataset.ajaxPendingCount = String(pendingCount + 1);

        return () => {
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const nextCount = Math.max(0, (Number.parseInt(String(target.dataset.ajaxPendingCount || '1'), 10) || 1) - 1);
            if (nextCount > 0) {
                target.dataset.ajaxPendingCount = String(nextCount);
                return;
            }

            target.classList.remove('is-ajax-pending');
            delete target.dataset.ajaxPendingCount;

            if (target.dataset.ajaxPendingHadAriaBusy === 'true') {
                target.setAttribute('aria-busy', target.dataset.ajaxPendingAriaBusy || 'false');
            } else {
                target.removeAttribute('aria-busy');
            }

            delete target.dataset.ajaxPendingHadAriaBusy;
            delete target.dataset.ajaxPendingAriaBusy;
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
        message.textContent = String(submitter.dataset.chickenMessage || 'Press the button again to confirm.')
            .replace(/<br\s*\/?>/gi, '\n');

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
        syncSiteContextFieldsToForm(form);

        if (form.dataset.ajax !== 'true') {
            return;
        }

        event.preventDefault();

        if (!formHasRequiredInviteContact(form)) {
            return;
        }

        if (!passChickenCheck(event.submitter)) {
            return;
        }

        if (event.submitter instanceof HTMLElement) {
            setFormPendingBlurOverride(form, event.submitter);
        }

        syncSubmitField(event.submitter);

        const formData = new FormData(form);

        formData.set('_ajax', '1');
        appendCurrentPageCardKeys(formData, form);
        appendRequestedVisibleCard(formData, event.submitter);
        appendSiteContextSelectionsToFormData(formData, form);

        if (tableExportClipboardRequested(event.submitter)) {
            formData.set('_table_export_clipboard', '1');
        } else {
            formData.delete('_table_export_clipboard');
        }

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
        const restorePendingBlur = beginAjaxPendingBlur(form);

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

            if (payload && typeof payload.clipboard_text === 'string') {
                await copyTableExportToClipboard(payload);
                return;
            }

            if (payload && typeof payload.download_url === 'string' && payload.download_url.trim() !== '') {
                triggerFileDownload(payload.download_url);
                return;
            }

            applyAjaxPayloadFragment('sidebar', () => replaceSidebar(payload.sidebar_html));
            applyAjaxPayloadFragment('topbar', () => replaceTopbar(payload.topbar_html));
            applyAjaxPayloadFragment('footer', () => replacePageFooter(payload.footer_html));
            applyAjaxPayloadFragment('site context', () => replaceSiteContextSlots(payload.site_context_html));
            applyAjaxPayloadFragment('developer options status', () => replaceDeveloperOptionsStatus(payload.developer_options_status_html));
            applyAjaxPayloadFragment('cards', () => replaceCards(payload.cards));
            applyAjaxPayloadFragment('flash', () => replaceFlash(payload.flash_html));
            applyAjaxPayloadFragment('visible card', () => revealPageCard(payload.show_card, { source: 'ajax' }));

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
            clearTableExportClipboardIntent(event.submitter);
            restorePendingBlur();
            delete form.dataset.ajaxPendingBlurOverride;
            restoreProcessingState();
        }
    });

    document.addEventListener('click', async (event) => {
        rememberTableExportClipboardIntent(event);

        const cardSizeToggle = event.target instanceof Element ? event.target.closest('[data-card-size-toggle]') : null;
        if (cardSizeToggle instanceof HTMLButtonElement) {
            event.preventDefault();
            toggleCardMaximized(cardSizeToggle);
            return;
        }

        const userCreateModeButton = event.target instanceof Element ? event.target.closest('[data-user-create-mode-button]') : null;
        if (userCreateModeButton instanceof HTMLButtonElement) {
            event.preventDefault();
            selectUserCreateMode(userCreateModeButton);
            return;
        }

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
        if (event.key === 'Escape') {
            const maximizedCard = document.querySelector('.card.card-maximized');
            if (maximizedCard instanceof HTMLElement) {
                event.preventDefault();
                setCardMaximized(maximizedCard, false, true);
            }

            return;
        }

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

    document.addEventListener('beforeinput', enforceValidatedInputBeforeInput);

    document.addEventListener('change', (event) => {
        if (isFormControl(event.target)) {
            syncVisibleWhenField(event.target);
            syncDynamicValidationTargets(event.target);
            sanitizeValidatedInput(event.target);
        }

        const submitOnChangeControl = event.target instanceof Element
            ? event.target.closest('[data-submit-on-change="true"]')
            : null;

        if (submitOnChangeControl instanceof HTMLElement) {
            const form = submitOnChangeControl.closest('form[data-ajax="true"]');
            if (form instanceof HTMLFormElement) {
                setFormPendingBlurOverride(form, submitOnChangeControl);
                form.requestSubmit();
                return;
            }
        }

        const select = event.target;
        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (select.dataset.noSubmitOnChange === 'true') {
            return;
        }

        const form = select.closest('form[data-ajax="true"]');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        setFormPendingBlurOverride(form, select);
        form.requestSubmit();
    });

    document.addEventListener('input', (event) => {
        sanitizeValidatedInput(event.target);

        if (isFormControl(event.target)) {
            syncVisibleWhenField(event.target);
        }
    });

    initialiseSidebar(document);
    initialisePageCardTabs(document);
    initialiseCardToggles();
    initStateWatchers(document);
    initialiseVisibleWhenControls(document);
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

    const requestedCard = new URLSearchParams(window.location.search).get('show_card');
    if (requestedCard) {
        revealPageCard(requestedCard, { source: 'initial-load' });
    }

    if (document.readyState === 'complete') {
        renderPageLoadTime();
    } else {
        window.addEventListener('load', () => {
            renderPageLoadTime();
            window.setTimeout(renderPageLoadTime, 0);
        }, { once: true });
    }
})();
