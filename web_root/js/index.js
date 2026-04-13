(() => {
    const body = document.body;
    const toggle = document.getElementById('sidebar-toggle');

    if (toggle) {
        toggle.addEventListener('click', () => {
            body.classList.toggle('sidebar-collapsed');
            toggle.setAttribute('aria-expanded', body.classList.contains('sidebar-collapsed') ? 'false' : 'true');
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
            replaceFlash(payload.flash_html);

            if (payload.url) {
                window.history.pushState({}, '', payload.url);
            }
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
})();
