@php
    $validationRules = [
        'required' => \Paymenter\Extensions\Servers\Enom\Services\DomainManager::REQUIRED_CONTACT_FIELDS,
        'maxBytes' => \Paymenter\Extensions\Servers\Enom\Services\DomainManager::CONTACT_MAX_BYTES,
        'nanp' => \Paymenter\Extensions\Servers\Enom\Services\DomainManager::NANP_COUNTRIES,
        'messages' => \Paymenter\Extensions\Servers\Enom\Services\DomainManager::CONTACT_MESSAGES,
        // Match the effective native request middleware without modifying input values.
        'trimCharacters' => preg_replace_callback('/\\\\x\{([0-9A-Fa-f]+)\}/', fn ($match) => mb_chr(hexdec($match[1])), \Illuminate\Support\Str::INVISIBLE_CHARACTERS),
    ];
@endphp
<p data-enom-announcer class="sr-only" aria-live="polite" aria-atomic="true"></p>
<script type="application/json" data-enom-validation-rules>@json($validationRules)</script>
@verbatim
<script>
(() => {
    if (window.EnomFormValidation) {
        window.EnomFormValidation.mount();
        return;
    }
    const bound = new WeakSet();
    let sequence = 0;
    const uniqueId = prefix => {
        let id;
        do { id = prefix + (++sequence); } while (document.getElementById(id));
        return id;
    };
    const phpTrim = value => value.replace(/^[ \t\n\r\u0000\u000B]+|[ \t\n\r\u0000\u000B]+$/g, '');
    const reveal = field => {
        for (let node = field.parentElement; node; node = node.parentElement) {
            if (node.tagName === 'DETAILS') node.open = true;
        }
    };
    const focus = field => {
        reveal(field);
        field.scrollIntoView?.({block: 'center'});
        field.focus({preventScroll: true});
    };
    const nativeError = field => {
        const v = field.validity;
        if (v.badInput) return 'Enter a number.';
        if (v.valueMissing) return field.type === 'checkbox' ? 'Confirm this action before continuing.' : 'Complete this required field.';
        if (v.typeMismatch) return field.type === 'email' ? 'Enter an address like name@example.com.' : 'Enter a value in the requested format.';
        if (v.patternMismatch) return 'Use the requested format.';
        if (v.tooLong) return 'Shorten this value to the displayed limit.';
        if (v.tooShort) return 'Enter enough characters to meet the displayed minimum.';
        if (v.rangeUnderflow || v.rangeOverflow) return 'Enter a number within the displayed limits.';
        if (v.stepMismatch) return 'Enter a number in the requested increments.';
        return '';
    };
    const phoneMatches = (value, country, rules) => {
        const international = /^\+([1-9][0-9]{0,2})[. -]+([0-9() -]+)$/.exec(value);
        if (international) {
            const subscriber = international[2].replace(/[() -]/g, '');
            if (/^[0-9]{4,14}$/.test(subscriber) && (international[1] + subscriber).length <= 15) return true;
        }
        if (rules.nanp.includes(country) && /^\+?[0-9() .-]+$/.test(value)) {
            let digits = value.replace(/[^0-9]/g, '');
            if (digits.length === 11 && digits.startsWith('1')) digits = digits.slice(1);
            else if (value.startsWith('+')) return false;
            return /^[2-9][0-9]{2}[2-9][0-9]{6}$/.test(digits);
        }
        return false;
    };
    // Quote field only (data-enom-supported-domain="ordinary-com"). New orders accept one ASCII label of
    // 1 to 63 characters plus .com, as the domain parser and registrar client do. Format guidance only:
    // the server stays authoritative, and the value is never rewritten.
    const ordinaryCom = /^(?![a-z0-9-]{2}--)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.com$/;
    const domainMessages = {
        required: 'Enter a domain name, for example example.com.',
        address: 'Enter only the domain name, such as example.com, without http://, slashes or spaces.',
        characters: 'Use only letters a to z, digits 0 to 9 and hyphens, then .com. Accented and international characters are not accepted in this form.',
        symbols: 'Use only letters a to z, digits 0 to 9 and hyphens before .com.',
        missingEnding: 'Add .com to the end, for example example.com.',
        missingName: 'Enter the name before .com, for example example.com.',
        dots: 'Remove the extra dot. Enter a name such as example.com.',
        ending: 'Only .com domains can be ordered here. Enter a name that ends in .com.',
        subdomain: 'Enter only the name before .com, without www or other parts, for example example.com.',
        idn: 'International domain names, including names that start with xn--, are not available.',
        length: 'The name before .com can have at most 63 characters.',
        hyphenEdge: 'The name before .com cannot start or end with a hyphen.',
        hyphenPair: 'The name before .com cannot have hyphens as its third and fourth characters.',
    };
    const supportedDomainError = (field, value) => {
        if (!value) return field.required ? domainMessages.required : '';
        if (/[\s\/\\:@?#]/.test(value)) return domainMessages.address;
        if (/[^\x21-\x7e]/.test(value)) return domainMessages.characters;
        const labels = value.toLowerCase().split('.');
        if (labels.length === 1) return domainMessages.missingEnding;
        if (labels.includes('')) return labels.length === 2 && labels[0] === '' ? domainMessages.missingName : domainMessages.dots;
        if (labels[labels.length - 1] !== 'com') return domainMessages.ending;
        if (labels.length > 2) return domainMessages.subdomain;
        const name = labels[0];
        if (name.startsWith('xn--')) return domainMessages.idn;
        if (!/^[a-z0-9-]+$/.test(name)) return domainMessages.symbols;
        if (name.length > 63) return domainMessages.length;
        if (name.startsWith('-') || name.endsWith('-')) return domainMessages.hyphenEdge;
        if (name.slice(2, 4) === '--') return domainMessages.hyphenPair;
        if (!ordinaryCom.test(name + '.com')) return field.dataset.enomPatternMessage || domainMessages.symbols;
        return '';
    };
    const mount = () => {
        const rulesNode = document.querySelector('[data-enom-validation-rules]');
        if (!rulesNode) return;
        let rules, normalize;
        try {
            rules = JSON.parse(rulesNode.textContent);
            const trimCharacters = new Set([...rules.trimCharacters, '\u0000']);
            const isBoundary = c => /\p{White_Space}/u.test(c) || trimCharacters.has(c);
            normalize = value => {
                const chars = [...value];
                while (chars.length && isBoundary(chars[0])) chars.shift();
                while (chars.length && isBoundary(chars[chars.length - 1])) chars.pop();
                return chars.join('');
            };
        } catch (_) { return; }
        document.querySelectorAll('form[data-enom-validate]').forEach(form => {
            if (bound.has(form)) return;
            const summary = form.querySelector('[data-enom-summary]');
            const list = summary?.querySelector('ul');
            const announcer = document.querySelector('[data-enom-announcer]');
            if (!summary || !list || !announcer) return;
            let attempted = false;
            const states = [];
            const failOpen = () => { form.noValidate = false; };
            try {
                const fields = [...form.querySelectorAll('input, select, textarea')].filter(field => field.type !== 'hidden');
                fields.forEach(field => {
                    if (!field.id) field.id = uniqueId('enom-field-');
                    const container = field.closest('fieldset') || field.parentElement;
                    const labelNode = [...form.querySelectorAll('label')].find(label => label.htmlFor === field.id || label.contains(field));
                    const label = labelNode ? [...labelNode.childNodes].map(node => node.nodeType === 3 ? node.textContent : (node.tagName === 'SPAN' && node.textContent.trim() === '*' ? '' : node.textContent)).join('').trim() : (field.getAttribute('aria-label') || 'This field');
                    if (labelNode && !labelNode.htmlFor) labelNode.htmlFor = field.id;
                    let client = document.getElementById(field.id + '-client-error') || [...form.querySelectorAll('[data-enom-client-for]')].find(node => node.dataset.enomClientFor === field.name);
                    if (client && !client.id) client.id = field.id + '-client-error';
                    if (!client || !form.contains(client)) {
                        client = document.createElement('p');
                        client.id = field.id + '-client-error';
                        client.className = 'text-red-500 text-xs mt-1';
                        client.hidden = true;
                        (field.closest('label') || field).insertAdjacentElement('afterend', client);
                    }
                    // Keep native component feedback inside its fieldset, beside the control.
                    if (field.closest('fieldset')) field.insertAdjacentElement('afterend', client);
                    const server = [...container.querySelectorAll('p')].filter(node => node !== client && !node.hasAttribute('data-enom-client-error') && (node.hasAttribute('data-enom-server-error') || (field.closest('fieldset') && node.classList.contains('text-red-500'))));
                    server.forEach(node => { if (!node.id) node.id = uniqueId('enom-server-error-'); });
                    const serverIds = new Set(server.map(node => node.id));
                    const hints = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && !serverIds.has(id) && id !== client.id);
                    const secret = field.name === 'epp';
                    const initial = secret ? null : (field.type === 'checkbox' ? field.defaultChecked : (field.tagName === 'SELECT' ? ([...field.options].find(option => option.defaultSelected) || field.options[0])?.value : field.defaultValue));
                    states.push({field, client, server, hints, label, initial, secret, edited: false, touched: false, message: '', timer: null});
                });
                const countryState = states.find(state => state.field.dataset.enomContact === 'country');
                const phoneState = states.find(state => state.field.dataset.enomContact === 'phone');
                const countryInitial = countryState?.initial;
                const current = state => state.field.type === 'checkbox' ? state.field.checked : state.field.value;
                const eligible = state => state.field.willValidate && state.field.isConnected;
                const unchanged = state => state.secret ? !state.edited : current(state) === state.initial && (state !== phoneState || !countryState || current(countryState) === countryInitial);
                const paint = (state, message) => {
                    state.message = message;
                    const serverVisible = unchanged(state);
                    state.server.forEach(node => { node.hidden = !serverVisible; });
                    state.client.textContent = message;
                    state.client.hidden = !message;
                    const shownServer = serverVisible && state.server.length > 0;
                    if (message || shownServer) state.field.setAttribute('aria-invalid', 'true');
                    else state.field.removeAttribute('aria-invalid');
                    const ids = [...state.hints, ...(shownServer ? state.server.map(node => node.id) : []), ...(message ? [state.client.id] : [])];
                    if (ids.length) state.field.setAttribute('aria-describedby', ids.join(' '));
                    else state.field.removeAttribute('aria-describedby');
                };
                const error = state => {
                    if (!eligible(state)) return '';
                    const field = state.field;
                    const contact = field.dataset.enomContact;
                    if (field.dataset.enomSupportedDomain === 'ordinary-com') {
                        // Same edge trimming as the request middleware. The pattern attribute is the no-script check, so skip it here.
                        const message = supportedDomainError(field, normalize(field.value));
                        if (message) return message;
                        return field.validity.patternMismatch ? '' : nativeError(field);
                    }
                    if (!contact) return nativeError(field); // Includes EPP; never read its value.
                    const value = normalize(field.value);
                    const trimmed = phpTrim(value);
                    if (/[\u0000-\u001f\u007f]/.test(value) || (window.TextEncoder && new window.TextEncoder().encode(value).length > rules.maxBytes)) return rules.messages.text;
                    if (rules.required.includes(contact) && !trimmed) return rules.messages.required;
                    if (contact === 'country') {
                        const code = trimmed.toUpperCase();
                        const listed = [...field.options].filter(option => option.value && !option.hasAttribute('data-enom-unlisted')).map(option => option.value);
                        if (!/^[A-Z]{2}$/.test(code) || (listed.length && !listed.includes(code))) return rules.messages.country;
                    }
                    if (contact === 'phone' && trimmed) {
                        const country = countryState ? phpTrim(normalize(countryState.field.value)).toUpperCase() : '';
                        if (!phoneMatches(trimmed, country, rules)) return !country || (countryState && error(countryState)) ? rules.messages.phone_country : rules.messages.phone;
                    }
                    return nativeError(field);
                };
                const rebuildSummary = () => {
                    list.replaceChildren();
                    states.filter(state => state.message && eligible(state)).forEach(state => {
                        const item = document.createElement('li');
                        const link = document.createElement('a');
                        link.href = '#' + state.field.id;
                        link.className = 'underline';
                        link.textContent = state.label + ': ' + state.message;
                        link.addEventListener('click', event => { event.preventDefault(); focus(state.field); });
                        item.append(link); list.append(item);
                    });
                    summary.hidden = !list.children.length;
                };
                const flush = state => { clearTimeout(state.timer); state.timer = null; };
                const update = (state, announce = false) => {
                    flush(state);
                    const message = error(state);
                    paint(state, message);
                    if (attempted) rebuildSummary();
                    if (announce && message) announcer.textContent = state.label + ': ' + message;
                };
                states.forEach(state => {
                    paint(state, '');
                    state.field.addEventListener('blur', () => {
                        try {
                            flush(state);
                            if (attempted || state.edited || (!state.secret && current(state) !== state.initial)) { state.touched = true; update(state, true); }
                        } catch (_) { failOpen(); }
                    });
                    const onEdit = () => {
                        try {
                            state.edited = true;
                            flush(state);
                            paint(state, state.message);
                            if (state.message || state.server.length || state.touched || attempted) {
                                const message = error(state);
                                if (!message) { paint(state, ''); if (attempted) rebuildSummary(); }
                                else state.timer = setTimeout(() => { if (!state.field.isConnected) return; try { update(state); } catch (_) { failOpen(); } }, 300);
                            }
                            if (state === countryState && phoneState && (phoneState.touched || attempted || normalize(phoneState.field.value))) update(phoneState);
                        } catch (_) { failOpen(); }
                    };
                    state.field.addEventListener('input', onEdit);
                    state.field.addEventListener('change', onEdit);
                });
                form.addEventListener('submit', event => {
                    try {
                        attempted = true;
                        states.forEach(flush);
                        // Complete checks before preventing any submission. PHP remains authoritative.
                        const results = states.map(state => {
                            if (!eligible(state)) return [state, ''];
                            reveal(state.field);
                            return [state, state.field.getClientRects().length ? error(state) : ''];
                        });
                        results.forEach(([state, message]) => paint(state, message));
                        rebuildSummary();
                        const invalid = results.filter(([, message]) => message);
                        announcer.textContent = invalid.length ? invalid.length + (invalid.length === 1 ? ' field needs review.' : ' fields need review.') : '';
                        if (invalid.length) { focus(invalid[0][0].field); event.preventDefault(); }
                    } catch (_) { failOpen(); }
                });
                bound.add(form);
                form.noValidate = true;
            } catch (_) { failOpen(); }
        });
    };
    window.EnomFormValidation = {mount};
    document.addEventListener('livewire:navigated', mount);
    window.addEventListener('pageshow', mount);
    mount();
})();
</script>
@endverbatim
