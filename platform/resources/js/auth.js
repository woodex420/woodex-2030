/* Progressive enhancement only: normal Fortify POST/CSRF/2FA/intended redirects remain authoritative. */
export function signInFailure(status, retryAfter) {
    if (status === 419) return 'Your sign-in session expired or cookies were blocked. Reload this page, or open sign-in in a new tab.';
    if (status === 429) {
        const seconds = Number(retryAfter);
        return Number.isInteger(seconds) && seconds > 0 && seconds <= 3600
            ? `Too many sign-in attempts. Wait ${seconds} seconds and try again.`
            : 'Too many sign-in attempts. Wait a minute and try again.';
    }
    return 'Sign-in could not finish. Please try again, or open sign-in in a new tab.';
}

export function sameOriginDestination(value, origin) {
    try {
        if (typeof value !== 'string' || !value.trim()) return null;
        const url = new URL(value, origin);
        return url.origin === origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null;
    } catch {
        return null;
    }
}

export function installSignIn(form) {
    if (form.dataset.enhanced) return;
    form.dataset.enhanced = 'true';
    // Native validation bubbles can be suppressed inside sandboxed frames. Always show readable feedback.
    form.noValidate = true;
    const status = form.querySelector('[data-sign-in-status]');
    const submit = form.querySelector('button[type="submit"]');
    const password = form.querySelector('[name="password"]');
    const label = submit.textContent;
    let busy = false;
    const show = (message, error = true) => {
        status.textContent = message;
        status.hidden = false;
        status.setAttribute('role', error ? 'alert' : 'status');
    };
    const reset = () => {
        busy = false;
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        submit.textContent = label;
    };
    window.addEventListener('pageshow', reset);
    form.querySelector('[data-show-password]')?.addEventListener('click', (event) => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        event.currentTarget.textContent = visible ? 'Hide password' : 'Show password';
        event.currentTarget.setAttribute('aria-pressed', visible ? 'true' : 'false');
    });
    // Some preview frames suppress native form submission BEFORE a submit event is fired.
    // Explicit activation keeps validation and the real Fortify POST working in those frames.
    const requestSignIn = () => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    submit.addEventListener('click', (event) => {
        event.preventDefault();
        requestSignIn();
    });
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('input[name="email"], input[name="password"]')) {
            event.preventDefault();
            requestSignIn();
        }
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy) return;
        if (!form.checkValidity()) {
            const invalid = form.querySelector(':invalid');
            show(invalid?.name === 'password' ? 'Enter your password to continue.' : 'Enter a valid email address to continue.');
            invalid?.focus();
            return;
        }
        busy = true;
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.textContent = 'Signing in…';
        show('Checking your account…', false);
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 20000);
        try {
            const endpoint = sameOriginDestination(form.getAttribute('action'), window.location.origin);
            if (!endpoint) {
                show('The sign-in address does not match this page. Reload sign-in in a new tab.');
                return;
            }
            // JSON retains Fortify validation/2FA, without following an HTTP or internal-host Location behind TLS proxies.
            const response = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin', redirect: 'error',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams(new FormData(form)), signal: controller.signal,
            });
            if (!response.ok) {
                if (response.status === 422) {
                    const failure = await response.json().catch(() => null);
                    const message = failure?.errors?.email?.[0] || failure?.errors?.password?.[0];
                    show(typeof message === 'string' ? message.slice(0, 400) : 'Check your email and password, then try again.');
                } else {
                    show(signInFailure(response.status, response.headers.get('Retry-After')));
                }
                return;
            }
            const result = await response.json().catch(() => null);
            const destination = sameOriginDestination(result?.two_factor === true ? form.dataset.twoFactorUrl : result?.redirect, window.location.origin);
            if (!destination) {
                show('Sign-in returned an incomplete or unexpected result. Reload this page and try again.');
                return;
            }
            show('Signed in. Opening your dashboard…', false);
            window.location.assign(destination);
        } catch {
            show(controller.signal.aborted
                ? 'Sign-in took too long. Try again, or open sign-in in a new tab.'
                : 'The sign-in request could not reach the server. Check your connection and try again.');
        } finally {
            window.clearTimeout(timeout);
            reset();
        }
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('form[data-sign-in]').forEach(installSignIn);
    const glass = document.querySelector('[data-auth-glass-toggle]');
    if (glass) {
        const render = () => { const transparent = document.documentElement.dataset.authGlass !== 'solid'; glass.textContent = transparent ? 'Glass appearance' : 'Solid contrast'; glass.setAttribute('aria-pressed', transparent ? 'true' : 'false'); };
        glass.addEventListener('click', () => { const value = document.documentElement.dataset.authGlass === 'solid' ? 'glass' : 'solid'; document.documentElement.dataset.authGlass = value; try { localStorage.setItem('wx.auth.glass', value); } catch {} render(); });
        render();
    }
}
