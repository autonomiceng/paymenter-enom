{{-- One shared confirmation dialog plus progressive enhancement for every client page. Pages work without it. --}}
@once
<dialog class="enom-dialog" id="enom-confirm" aria-labelledby="enom-confirm-title" aria-describedby="enom-confirm-body">
    <form method="dialog">
        <h2 id="enom-confirm-title"></h2>
        <p id="enom-confirm-body"></p>
        <div class="enom-dialog__actions">
            <button type="submit" value="cancel" class="enom-btn enom-btn--secondary">Cancel</button>
            <button type="submit" value="ok" class="enom-btn enom-btn--primary" id="enom-confirm-ok"></button>
        </div>
    </form>
</dialog>
<p class="enom-sr" aria-live="polite" id="enom-live"></p>
@verbatim
<script>
(() => {
    const roots = document.querySelectorAll('.enom');
    roots.forEach(root => root.classList.add('enom-js'));
    const live = document.getElementById('enom-live');
    const say = text => { if (live) { live.textContent = ''; setTimeout(() => { live.textContent = text; }, 30); } };

    /* Timestamps in the viewer's own time zone. Calendar dates are plain text and never converted. */
    const full = new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'});
    const relative = 'RelativeTimeFormat' in Intl ? new Intl.RelativeTimeFormat(undefined, {numeric: 'auto'}) : null;
    const ago = date => {
        const seconds = (date.getTime() - Date.now()) / 1000;
        const steps = [[60, 'second'], [3600, 'minute', 60], [86400, 'hour', 3600], [604800, 'day', 86400], [2629800, 'week', 604800], [31557600, 'month', 2629800], [Infinity, 'year', 31557600]];
        for (const [limit, unit, size = 1] of steps) {
            if (Math.abs(seconds) < limit) return relative.format(Math.round(seconds / size), unit);
        }
    };
    document.querySelectorAll('time[data-enom-time]').forEach(node => {
        const date = new Date(node.getAttribute('datetime'));
        if (isNaN(date)) return;
        node.title = full.format(date);
        node.textContent = node.dataset.enomTime === 'relative' && relative ? ago(date) : full.format(date);
    });

    /* Tabs. Without script every section shows and the tab links jump to it. */
    document.querySelectorAll('[data-enom-tabs]').forEach(list => {
        const tabs = [...list.querySelectorAll('[data-enom-tab]')];
        const panels = tabs.map(tab => document.getElementById(tab.dataset.enomTab));
        list.setAttribute('role', 'tablist');
        const select = (index, focus) => {
            tabs.forEach((tab, i) => {
                const on = i === index;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.tabIndex = on ? 0 : -1;
                if (panels[i]) panels[i].hidden = !on;
            });
            history.replaceState(null, '', index === 0 ? location.pathname + location.search : '#' + tabs[index].dataset.enomTab);
            if (focus) tabs[index].focus();
        };
        tabs.forEach((tab, i) => {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', tab.dataset.enomTab);
            panels[i]?.setAttribute('role', 'tabpanel');
            panels[i]?.setAttribute('aria-labelledby', tab.id);
            tab.addEventListener('click', event => {
                event.preventDefault();
                select(i, false);
            });
            tab.addEventListener('keydown', event => {
                const step = {ArrowRight: 1, ArrowLeft: -1}[event.key];
                if (!step) return;
                event.preventDefault();
                select((i + step + tabs.length) % tabs.length, true);
            });
        });
        window.addEventListener('hashchange', () => {
            const index = tabs.findIndex(tab => '#' + tab.dataset.enomTab === location.hash);
            select(index >= 0 ? index : 0, false);
        });
        const fromHash = tabs.findIndex(tab => '#' + tab.dataset.enomTab === location.hash);
        select(fromHash >= 0 ? fromHash : 0, false);
        if (fromHash >= 0) requestAnimationFrame(() => window.scrollTo(0, Math.max(0, list.getBoundingClientRect().top + window.scrollY - 160)));
    });

    /* Confirmation for paid or risky actions only. */
    const dialog = document.getElementById('enom-confirm');
    const ask = (form, submitter) => {
        const data = form.dataset;
        if (!dialog || typeof dialog.showModal !== 'function') {
            const option = form.querySelector('option:checked[data-confirm-body]');
            return window.confirm(data.confirmTitle + '\n\n' + (option?.dataset.confirmBody || data.confirmBody || '')) ? Promise.resolve(true) : Promise.resolve(false);
        }
        dialog.querySelector('#enom-confirm-title').textContent = data.confirmTitle || 'Are you sure?';
        // A select can carry its own wording per choice (for example the renewal term and its total).
        const option = form.querySelector('option:checked[data-confirm-body]');
        dialog.querySelector('#enom-confirm-body').textContent = option?.dataset.confirmBody || data.confirmBody || '';
        const ok = dialog.querySelector('#enom-confirm-ok');
        ok.textContent = data.confirmOk || 'Continue';
        ok.className = 'enom-btn ' + (data.confirmTone === 'danger' ? 'enom-btn--danger' : 'enom-btn--primary');
        dialog.returnValue = '';
        dialog.showModal();
        ok.focus();
        return new Promise(resolve => dialog.addEventListener('close', () => resolve(dialog.returnValue === 'ok'), {once: true}));
    };
    const changed = form => (form.dataset.confirmWhenChanged || '').split(',').filter(Boolean).some(name => {
        const field = form.querySelector('[name="' + name + '"]');
        return field && !field.disabled && field.value.trim().toLowerCase() !== (field.defaultValue || '').trim().toLowerCase();
    });

    /* Inline validation: native constraints, shown next to each field. */
    const messageFor = field => {
        const v = field.validity;
        if (v.valueMissing) return field.tagName === 'SELECT' ? 'Choose an option.' : 'Required.';
        if (v.typeMismatch && field.type === 'email') return 'Enter an email like name@example.com.';
        if (v.tooLong) return 'This is too long.';
        if (v.patternMismatch) return field.dataset.patternMessage || 'Check the format.';
        return field.validationMessage;
    };
    const showError = (field, message) => {
        const id = field.id + '-client-error';
        let node = document.getElementById(id);
        if (!node) {
            node = document.createElement('p');
            node.id = id;
            node.className = 'enom-error';
            (field.closest('[data-field]') || field.parentElement).appendChild(node);
        }
        node.textContent = message || '';
        node.hidden = !message;
        if (message) {
            field.setAttribute('aria-invalid', 'true');
            field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), id].filter(Boolean).join(' ').split(' ').filter((v, i, a) => a.indexOf(v) === i).join(' '));
        } else if (!field.dataset.serverInvalid) {
            field.removeAttribute('aria-invalid');
        }
    };
    const hostname = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/i;
    const nameserverError = value => {
        const hosts = value.split(/[\s,]+/).map(h => h.trim()).filter(Boolean);
        if (hosts.length < 2) return 'Enter at least 2 nameservers, one per line.';
        if (hosts.length > 12) return 'Enter no more than 12 nameservers.';
        for (const host of hosts) {
            if (/^[0-9.]+$/.test(host) || host.includes(':')) return 'Use hostnames like ns1.example.com, not IP addresses.';
            if (!hostname.test(host)) return '“' + host + '” isn’t a valid hostname.';
        }
        if (new Set(hosts.map(h => h.toLowerCase().replace(/\.$/, ''))).size !== hosts.length) return 'Each nameserver can only be listed once.';
        return '';
    };
    const fieldError = field => {
        if (field.disabled || field.type === 'hidden') return '';
        if (field.matches('[data-enom-nameservers]')) return nameserverError(field.value);
        return field.checkValidity() ? '' : messageFor(field);
    };
    document.querySelectorAll('form[data-enom-form]').forEach(form => {
        form.noValidate = true;
        const fields = () => [...form.querySelectorAll('input, select, textarea')].filter(f => f.id);
        fields().forEach(field => {
            if (field.getAttribute('aria-invalid') === 'true') field.dataset.serverInvalid = '1';
            field.addEventListener('blur', () => { if (field.value !== '' || field.dataset.touched) showError(field, fieldError(field)); field.dataset.touched = '1'; });
            field.addEventListener('input', () => { delete field.dataset.serverInvalid; if (field.getAttribute('aria-invalid') === 'true') showError(field, fieldError(field)); });
        });
    });

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method === 'dialog') return;
        if (form.matches('[data-enom-form]') && !event.submitter?.hasAttribute('formnovalidate')) {
            const invalid = [...form.querySelectorAll('input, select, textarea')].filter(f => f.id).map(f => [f, fieldError(f)]);
            invalid.forEach(([f, m]) => showError(f, m));
            const first = invalid.find(([, m]) => m);
            if (first) {
                event.preventDefault();
                first[0].closest('details')?.setAttribute('open', '');
                first[0].focus();
                first[0].scrollIntoView({block: 'center', behavior: 'smooth'});
                say('Check the highlighted fields.');
                return;
            }
        }
        const needs = form.dataset.confirmTitle && (!form.dataset.confirmWhenChanged || changed(form));
        if (needs && form.dataset.confirmed !== '1') {
            event.preventDefault();
            const submitter = event.submitter;
            if (await ask(form)) {
                form.dataset.confirmed = '1';
                form.requestSubmit ? form.requestSubmit(submitter && submitter.form === form ? submitter : undefined) : form.submit();
            }
            return;
        }
        form.querySelectorAll('button[type="submit"]').forEach(b => { b.dataset.busy = '1'; setTimeout(() => b.disabled = true, 0); });
    });

    window.addEventListener('pageshow', () => document.querySelectorAll('.enom button[data-busy]').forEach(b => { b.disabled = false; delete b.dataset.busy; }));

    /* Show/hide dependent sections (custom nameservers, separate contacts). Hidden fields are disabled so they don't submit. */
    document.querySelectorAll('[data-enom-reveal]').forEach(section => {
        const controls = [...document.querySelectorAll(section.dataset.enomReveal)];
        const update = () => {
            const on = controls.some(c => c.checked) !== ('enomRevealInverse' in section.dataset);
            section.hidden = !on;
            section.querySelectorAll('input, select, textarea').forEach(f => { f.disabled = !on; });
        };
        document.querySelectorAll(controls.map(c => '[name="' + c.name + '"]').join(',')).forEach(c => c.addEventListener('change', update));
        update();
    });

    /* Registry details: a child field shows only when its parent's chosen option requires it. */
    document.querySelectorAll('[data-enom-registry]').forEach(section => {
        const children = [...section.querySelectorAll('[data-registry-parent]')];
        const update = () => children.forEach(wrap => {
            const parentWrap = [...section.querySelectorAll('[data-registry-field]')].find(w => w.dataset.registryField === wrap.dataset.registryParent);
            const parent = parentWrap?.querySelector('select');
            const chosen = parent?.selectedOptions[0];
            const needed = !parentWrap?.hidden && (!parent || (chosen?.dataset.requires || '').split(' ').includes(wrap.dataset.registryField));
            wrap.hidden = !needed;
            wrap.querySelectorAll('input, select').forEach(field => {
                field.disabled = !needed;
                if ('required' in field.dataset) field.required = needed;
            });
        });
        section.addEventListener('change', update);
        update();
    });

    /* Auto-submit for list sort and filters. */
    document.querySelectorAll('select[data-enom-autosubmit]').forEach(select => select.addEventListener('change', () => select.form.submit()));

    /* Domain search: check each ending, a few at a time. */
    document.querySelectorAll('[data-enom-results]').forEach(list => {
        const url = list.dataset.enomResults;
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const rows = [...list.querySelectorAll('[data-enom-result]')];
        const label = {available: ['Available', 'ok'], transferable: ['Taken', 'muted'], taken: ['Taken', 'muted'], premium: ['Premium name', 'muted'],
            managed: ['Already registered here', 'muted'], yours: ['Yours', 'ok'], in_cart: ['In your cart', 'ok'], invalid: ['Not available', 'muted'], error: ['Couldn’t check', 'warn']};
        const render = (row, data) => {
            const state = label[data.status] ? data.status : 'error';
            row.dataset.state = state;
            row.querySelectorAll('[data-when]').forEach(node => { node.style.display = node.dataset.when.split(' ').includes(state) ? 'flex' : 'none'; });
            const [text, tone] = label[state];
            const status = row.querySelector('[data-slot="status"]');
            status.innerHTML = '';
            const badge = document.createElement('span');
            badge.className = 'enom-badge enom-tone-' + tone;
            badge.textContent = text;
            status.appendChild(badge);
            row.enomData = data;
            const register = row.querySelector('[data-slot="register"]');
            const transfer = row.querySelector('[data-slot="transfer"]');
            if (register && data.register_url) register.href = data.register_url;
            if (transfer && data.transfer_url) transfer.href = data.transfer_url;
            if (data.warning) {
                const warning = document.createElement('small');
                warning.textContent = data.warning;
                status.appendChild(warning);
            }
            showPrice(row);
            if (state === 'in_cart' && data.url) row.querySelector('[data-slot="cart"]')?.setAttribute('href', data.url);
            if (state === 'yours' && data.url) row.querySelector('[data-slot="yours"]')?.setAttribute('href', data.url);
        };
        const showPrice = row => {
            const data = row.enomData || {};
            const state = row.dataset.state;
            const price = row.querySelector('[data-slot="price"]');
            price.innerHTML = '';
            const years = Number(data.min_years || 1);
            const total = state === 'available' && data.terms ? data.terms[years] : null;
            const amount = state === 'available' ? (total || data.price) : (state === 'transferable' ? data.transfer_price : null);
            if (state === 'premium') { price.textContent = 'Not sold here'; return; }
            if (!amount) return;
            const strong = document.createElement('strong');
            strong.textContent = state === 'transferable' ? 'Transfer for ' + amount : amount + ' for ' + years + (years === 1 ? ' year' : ' years');
            price.appendChild(strong);
            if (state === 'available' && data.alternative?.total) {
                const alternative = document.createElement('small');
                alternative.textContent = 'or ' + data.alternative.total + ' for ' + data.alternative.years + ' years';
                price.appendChild(alternative);
            }
            const note = document.createElement('small');
            if (state === 'transferable') note.textContent = 'Adds 1 year';
            else note.textContent = 'Renews at ' + (data.renew_price || data.price) + '/yr';
            if (note.textContent) price.appendChild(note);
        };
        const request = async domain => {
            const response = await fetch(url, {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest'},
                body: JSON.stringify({domain})});
            if (response.status === 429) throw new Error('busy');
            return response.json();
        };
        const check = async row => {
            row.dataset.state = 'checking';
            const status = row.querySelector('[data-slot="status"]');
            status.innerHTML = '<span class="enom-checking">Checking</span>';
            row.querySelector('[data-slot="price"]').innerHTML = '';
            const note = row.querySelector('[data-slot="retry-note"]');
            if (note) note.textContent = '';
            try {
                let data = await request(row.dataset.domain);
                // The registrar occasionally fails one lookup under load. Try once more before showing an error.
                if (data.status === 'error') { await new Promise(r => setTimeout(r, 1200)); data = await request(row.dataset.domain); }
                render(row, data);
            } catch (error) {
                render(row, {status: 'error'});
                if (note) note.textContent = error.message === 'busy' ? 'Too many searches. Wait a minute, then retry.' : '';
            }
            const states = rows.map(result => result.dataset.state);
            if (!states.some(state => state === 'checking' || !label[state])) {
                const available = states.filter(state => state === 'available').length;
                const errors = states.filter(state => state === 'error').length;
                say((available ? available + ' of ' + rows.length + ' names are available.' : errors ? '' : 'None of these names are available. Try a different name.')
                    + (errors ? (available ? ' ' : '') + 'Couldn’t check ' + errors + (errors === 1 ? ' name. Retry to check it.' : ' names. Retry to check them.') : ''));
            }
        };
        list.querySelectorAll('[data-enom-retry]').forEach(button => button.addEventListener('click', () => check(button.closest('[data-enom-result]'))));
        const queue = [...rows];
        const worker = async () => { while (queue.length) await check(queue.shift()); };
        Promise.all([worker(), worker()]);
    });
})();
</script>
@endverbatim
@endonce
