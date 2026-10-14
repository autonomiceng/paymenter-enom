// The shared client script (partials/client-script.blade.php) on pages rendered by tests/Enom/client-views.php:
// the availability check queue, the confirmation dialog, nameserver validation and local-time timestamps.
// Local jsdom only; fetch and dialog are synthetic. No browser or network.
process.env.TZ = 'America/New_York';
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {JSDOM, VirtualConsole} = require('jsdom');

const pages = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/client-views.php', '--emit'], {maxBuffer: 64e6}));
let count = 0;
const check = (condition, message) => { assert.ok(condition, message); count++; };
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/** Load a page, run only the extension's script, and capture console errors and submissions. */
function mount(html, {fetch, dialog = true, confirm} = {}) {
    const errors = [];
    const virtualConsole = new VirtualConsole();
    virtualConsole.on('error', message => errors.push(String(message)));
    virtualConsole.on('jsdomError', error => errors.push(error.message));
    const dom = new JSDOM(html, {url: 'http://enom-tests.invalid/domains', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole});
    const w = dom.window, d = w.document;
    w.fetch = fetch || (() => Promise.reject(new Error('Network forbidden')));
    w.XMLHttpRequest = function () { throw new Error('Network forbidden'); };
    w.Element.prototype.scrollIntoView = function () {}; // Not implemented by jsdom.
    w.confirm = confirm || (() => { throw new Error('window.confirm used'); });
    const node = d.getElementById('enom-confirm');
    if (node) {
        if (dialog) {
            node.showModal = function () { this.open = true; };
            node.close = function (value) { if (value !== undefined) this.returnValue = value; this.open = false; this.dispatchEvent(new w.Event('close')); };
        } else {
            node.showModal = undefined;
        }
    }
    const submits = [];
    // Runs after the script's document listener; records the outcome and stops jsdom's unimplemented navigation.
    w.addEventListener('submit', event => { submits.push({form: event.target, prevented: event.defaultPrevented}); event.preventDefault(); });
    const scripts = [...d.querySelectorAll('script:not([src])')].map(s => s.textContent).filter(t => t.includes("classList.add('enom-js')"));
    check(scripts.length === 1, 'Page carries the client script exactly once');
    w.eval(scripts[0]);
    return {w, d, errors, submits, dom};
}
// The primary button: the order form's term "Update" button (formnovalidate) re-prices without validating.
const submit = (w, form, submitter) => form.requestSubmit(submitter || form.querySelector('button[type="submit"]:not([formnovalidate])'));

(async () => {
    // ------------------------------------------------------------ availability check queue
    {
        let inFlight = 0, peak = 0;
        const calls = [];
        const answers = {};
        const rows = [...new JSDOM(pages.search).window.document.querySelectorAll('[data-enom-result]')].map(r => r.dataset.domain);
        check(rows.length === 12, 'Search renders at most 12 endings: ' + rows.length);
        answers[rows[0]] = [{status: 'available', complimentary: false, price: '$12.00', transfer_price: '$13.00', renew_price: '$14.00'}];
        answers[rows[1]] = [{status: 'transferable', complimentary: false, price: '$15.00', transfer_price: '$15.50', renew_price: '$16.00'}];
        answers[rows[2]] = [{status: 'error', message: 'Couldn’t check this name.'}, {status: 'available', complimentary: false, price: '$20.00', transfer_price: '$20.00', renew_price: '$21.00'}];
        answers[rows[3]] = [{status: 'error'}, {status: 'error'}, {status: 'taken', complimentary: false}];
        answers[rows[4]] = [{status: 'yours', url: 'http://enom-tests.invalid/domains/7'}];
        answers[rows[5]] = [{status: 'available', complimentary: false, price: '<img src=x onerror="window.pwned=1">', renew_price: '$1'}];
        answers[rows[6]] = ['429'];
        answers[rows[7]] = [{status: 'something-new'}];
        const fetch = async (url, options) => {
            const body = JSON.parse(options.body);
            calls.push({url, options, domain: body.domain});
            inFlight++; peak = Math.max(peak, inFlight);
            await sleep(15);
            inFlight--;
            const queue = answers[body.domain] || [{status: 'taken', complimentary: false}];
            const next = queue.length > 1 ? queue.shift() : queue[0];
            if (next === '429') return {status: 429, json: async () => ({message: 'Too Many Attempts.'})};
            return {status: 200, json: async () => ({domain: body.domain, ...next})};
        };
        const {w, d, errors} = mount(pages.search, {fetch});
        await sleep(1700); // Covers the one 1.2 s retry pause.
        const token = d.querySelector('meta[name="csrf-token"]').content;
        check(peak <= 2 && peak === 2, 'Check queue runs exactly two lookups at a time: peak ' + peak);
        check(calls.every(c => c.url === 'http://enom-tests.invalid/domains/check' && c.options.method === 'POST' && c.options.headers['X-CSRF-TOKEN'] === token
            && c.options.headers['Content-Type'] === 'application/json' && c.options.credentials === 'same-origin'), 'Lookups post JSON with the page CSRF token');
        const perDomain = name => calls.filter(c => c.domain === name).length;
        check(calls.length === 14 && rows.every(r => perDomain(r) === (r === rows[2] || r === rows[3] ? 2 : 1)), 'Each ending is checked once, failures retried exactly once: ' + calls.length);
        const row = name => d.querySelector('[data-enom-result][data-domain="' + name + '"]');
        check(row(rows[0]).dataset.state === 'available' && row(rows[0]).querySelector('[data-slot="price"] strong').textContent === '$12.00/yr'
            && row(rows[0]).querySelector('[data-slot="price"] small').textContent === 'Renews at $14.00/yr', 'Available ending shows its yearly and renewal price');
        check(row(rows[1]).dataset.state === 'transferable' && row(rows[1]).querySelector('[data-slot="price"] strong').textContent === '$15.50'
            && row(rows[1]).querySelector('[data-slot="price"] small').textContent === 'Transfer, adds 1 year', 'Registered ending offers the transfer price');
        check(row(rows[2]).dataset.state === 'available', 'A failed lookup succeeds on its one retry');
        check(row(rows[3]).dataset.state === 'error' && row(rows[3]).querySelector('[data-slot="status"]').textContent === 'Couldn’t check', 'Two failures show the error state');
        check(row(rows[4]).dataset.state === 'yours' && row(rows[4]).querySelector('[data-slot="yours"]').getAttribute('href') === 'http://enom-tests.invalid/domains/7', 'Own domain links to its page');
        check(!d.querySelector('[data-enom-results] img') && w.pwned === undefined && row(rows[5]).querySelector('[data-slot="price"] strong').textContent.startsWith('<img'), 'Answer text is inserted as text, never markup');
        check(row(rows[6]).dataset.state === 'error' && row(rows[6]).querySelector('[data-slot="retry-note"]').textContent === 'Too many searches. Wait a minute, then retry.', 'Rate limit tells the client to wait');
        check(row(rows[7]).dataset.state === 'error', 'Unknown answer is shown as an error, not trusted');
        check(d.getElementById('enom-live').textContent === '3 of 12 names are available.', 'Result count is announced: ' + d.getElementById('enom-live').textContent);
        const before = calls.length;
        answers[rows[3]] = [{status: 'available', complimentary: false, price: '$20.00', renew_price: '$21.00'}];
        row(rows[3]).querySelector('[data-enom-retry]').click();
        await sleep(60);
        check(calls.length === before + 1 && row(rows[3]).dataset.state === 'available', 'Retry checks that ending once more');
        check(errors.length === 0, 'Search page has no console errors: ' + errors.join(' | '));
        w.close();
    }
    {
        // Complimentary answers show no yearly suffix or renewal note.
        const fetch = async (url, options) => ({status: 200, json: async () => ({domain: JSON.parse(options.body).domain, status: 'available', complimentary: true, price: 'Complimentary', transfer_price: 'Complimentary', renew_price: null})});
        const {w, d, errors} = mount(pages.search, {fetch});
        await sleep(200);
        const first = d.querySelector('[data-enom-result]');
        check(first.querySelector('[data-slot="price"] strong').textContent === 'Complimentary' && !first.querySelector('[data-slot="price"] small'), 'Complimentary price has no renewal note');
        check(errors.length === 0, 'Complimentary search has no console errors');
        w.close();
    }
    {
        // Term picker: the ending's terms replace the options, and the price follows the chosen term.
        const fetch = async (url, options) => ({status: 200, json: async () => ({domain: JSON.parse(options.body).domain, status: 'available', complimentary: false,
            price: '$12.00', transfer_price: '$13.00', renew_price: '$14.00', min_years: 2, max_years: 3, terms: {2: '$26.00', 3: '$40.00'}})});
        const {w, d, errors} = mount(pages.search, {fetch});
        await sleep(200);
        const first = d.querySelector('[data-enom-result]');
        const years = first.querySelector('select[data-slot="years"]');
        check([...years.options].map(o => o.value).join(',') === '2,3' && years.value === '2' && !years.hidden, 'Term picker starts at the ending minimum');
        check(first.querySelector('[data-slot="price"] strong').textContent === '$26.00' && first.querySelector('[data-slot="price"] small').textContent === 'For 2 years, then $14.00/yr', 'Price shows the total for the chosen term');
        years.value = '3';
        years.dispatchEvent(new w.Event('change'));
        check(first.querySelector('[data-slot="price"] strong').textContent === '$40.00', 'Changing the term updates the total');
        check(errors.length === 0, 'Term picker has no console errors');
        w.close();
    }

    // ------------------------------------------------------------ confirmation dialog
    {
        const {w, d, errors, submits} = mount(pages.show);
        const lock = [...d.querySelectorAll('form')].find(f => f.dataset.confirmTitle === 'Turn off the transfer lock?');
        const dialog = d.getElementById('enom-confirm');
        submit(w, lock);
        await sleep(0);
        check(submits.length === 1 && submits[0].prevented && dialog.open, 'Risky action waits for confirmation');
        check(d.getElementById('enom-confirm-title').textContent === 'Turn off the transfer lock?' && d.getElementById('enom-confirm-ok').textContent === 'Turn off lock'
            && d.getElementById('enom-confirm-ok').className.includes('enom-btn--danger') && d.getElementById('enom-confirm-body').textContent.includes('active-site.com'), 'Dialog shows the action, its consequence and a danger button');
        check(d.activeElement === d.getElementById('enom-confirm-ok'), 'Confirm button takes focus');
        dialog.close('cancel');
        await sleep(0);
        check(submits.length === 1 && lock.dataset.confirmed !== '1', 'Cancel sends nothing');
        submit(w, lock);
        await sleep(0);
        dialog.close('ok');
        await sleep(0);
        check(submits.length === 3 && submits[2].form === lock && !submits[2].prevented, 'Confirm submits the form once');
        check([...lock.querySelectorAll('button[type="submit"]')].every(b => b.dataset.busy === '1'), 'Submitted form marks its button busy');
        await sleep(5);
        check(lock.querySelector('button[type="submit"]').disabled, 'Submitted button is disabled against double submits');
        w.dispatchEvent(new w.Event('pageshow'));
        check(!lock.querySelector('button[type="submit"]').disabled, 'Back navigation re-enables the button');
        // A plain form needs no confirmation.
        const refresh = [...d.querySelectorAll('form')].find(f => f.action.endsWith('/refresh'));
        submit(w, refresh);
        await sleep(0);
        check(!submits[submits.length - 1].prevented && submits[submits.length - 1].form === refresh, 'Unconfirmed actions submit directly');
        // Contacts confirm only when the owner identity changes.
        const owner = [...d.querySelectorAll('form')].find(f => f.dataset.confirmWhenChanged);
        submit(w, owner);
        await sleep(0);
        check(!submits[submits.length - 1].prevented && !dialog.open, 'Unchanged owner contact saves without a dialog');
        owner.querySelector('[name="contact[last_name]"]').value = 'Newname';
        submit(w, owner);
        await sleep(0);
        check(submits[submits.length - 1].prevented && dialog.open && d.getElementById('enom-confirm-title').textContent === 'Change the domain owner?', 'Changing the owner asks first');
        dialog.close('cancel');
        check(errors.length === 0, 'Domain page has no console errors: ' + errors.join(' | '));
        w.close();
    }
    {
        // Without <dialog> support the script falls back to window.confirm.
        let asked = null;
        const {w, d, submits, errors} = mount(pages.show, {dialog: false, confirm: message => { asked = message; return false; }});
        const lock = [...d.querySelectorAll('form')].find(f => f.dataset.confirmTitle === 'Turn off the transfer lock?');
        submit(w, lock);
        await sleep(0);
        check(asked && asked.startsWith('Turn off the transfer lock?') && submits.length === 1 && submits[0].prevented, 'Fallback confirm is used and declining sends nothing');
        check(errors.length === 0, 'Fallback confirm has no console errors');
        w.close();
    }

    // ------------------------------------------------------------ tabs
    {
        const {w, d} = mount(pages.show);
        const tabs = [...d.querySelectorAll('[data-enom-tab]')];
        check(tabs[0].getAttribute('aria-selected') === 'true' && d.getElementById('nameservers').hidden && !d.getElementById('overview').hidden, 'First tab is shown, others hidden');
        tabs[2].click();
        check(tabs[2].getAttribute('aria-selected') === 'true' && !d.getElementById('contacts').hidden && d.getElementById('overview').hidden && w.location.hash === '#contacts', 'Clicking a tab shows it and updates the address');
        tabs[2].dispatchEvent(new w.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));
        check(d.activeElement === tabs[3] && tabs[3].getAttribute('aria-selected') === 'true', 'Arrow keys move between tabs');
        w.close();
    }

    // ------------------------------------------------------------ nameserver validation on the order page
    {
        const {w, d, submits, errors} = mount(pages.order);
        const form = d.querySelector('form[data-enom-form]');
        const area = d.getElementById('enom-nameservers');
        const custom = d.getElementById('enom-ns-custom');
        const section = area.closest('[data-enom-reveal]');
        check(section.hidden && area.disabled, 'Custom nameservers start hidden and disabled');
        check(d.getElementById('enom-roles').hidden && [...d.querySelectorAll('#enom-roles input')].every(i => i.disabled), 'Separate contacts start hidden while one contact is used');
        custom.checked = true; custom.dispatchEvent(new w.Event('change', {bubbles: true}));
        check(!section.hidden && !area.disabled, 'Choosing custom nameservers reveals the field');
        const cases = [
            ['ns1.example.com', 'Enter at least 2 nameservers, one per line.'],
            [Array.from({length: 13}, (_, i) => 'ns' + i + '.example.com').join('\n'), 'Enter no more than 12 nameservers.'],
            ['192.0.2.1\nns2.example.com', 'Use hostnames like ns1.example.com, not IP addresses.'],
            ['2001:db8::1\nns2.example.com', 'Use hostnames like ns1.example.com, not IP addresses.'],
            ['ns1.example.com\nbad_host', '“bad_host” isn’t a valid hostname.'],
            ['ns1.example.com\nNS1.EXAMPLE.COM.', 'Each nameserver can only be listed once.'],
        ];
        for (const [value, message] of cases) {
            area.value = value;
            const before = submits.length;
            submit(w, form);
            await sleep(0);
            const error = d.getElementById('enom-nameservers-client-error');
            check(submits.length === before + 1 && submits[before].prevented && error && !error.hidden && error.textContent === message && area.getAttribute('aria-invalid') === 'true'
                && area.getAttribute('aria-describedby').split(' ').includes(error.id), 'Nameservers ' + JSON.stringify(value) + ' → ' + message);
            check(d.activeElement === area, 'Focus moves to the invalid nameserver field');
        }
        area.value = 'ns1.example.com\nns2.example.com, ns3.example.com';
        area.dispatchEvent(new w.Event('input'));
        check(d.getElementById('enom-nameservers-client-error').hidden && !area.hasAttribute('aria-invalid'), 'Fixing the list clears the error');
        submit(w, form);
        await sleep(0);
        check(!submits[submits.length - 1].prevented, 'Valid nameservers submit');
        check(errors.length === 0, 'Order page has no console errors: ' + errors.join(' | '));
        w.close();
    }
    {
        // Default nameservers: the hidden textarea is disabled and never blocks the order.
        const {w, d, submits} = mount(pages.order);
        d.getElementById('enom-nameservers').value = 'not valid';
        submit(w, d.querySelector('form[data-enom-form]'));
        await sleep(0);
        check(!submits[0].prevented, 'Hidden custom nameservers are not validated');
        // A required owner field left empty blocks the submit and is announced.
        d.getElementById('owner-first_name').value = '';
        submit(w, d.querySelector('form[data-enom-form]'));
        await sleep(0);
        check(submits[1].prevented && d.getElementById('owner-first_name').getAttribute('aria-invalid') === 'true', 'Empty required contact field blocks the order');
        await sleep(40);
        check(d.getElementById('enom-live').textContent === 'Check the highlighted fields.', 'Validation problems are announced');
        w.close();
    }

    // ------------------------------------------------------------ local time for timestamps, plain calendar dates
    {
        const {w, d, errors} = mount(pages.show);
        const stamps = [...d.querySelectorAll('time[data-enom-time]')];
        const updated = stamps.find(t => t.getAttribute('datetime') === '2026-10-14T08:30:00+00:00');
        const full = new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date('2026-10-14T08:30:00Z'));
        check(updated && updated.title === full && full.includes('4:30'), 'Timestamp title is shown in the viewer’s time zone: ' + (updated && updated.title));
        check(updated.dataset.enomTime === 'relative' && /ago|in |yesterday|today|now/.test(updated.textContent) && !updated.textContent.includes('UTC'), 'Relative timestamp replaces the server text: ' + updated.textContent);
        const activity = stamps.filter(t => t.dataset.enomTime !== 'relative');
        check(activity.length > 0 && activity.every(t => t.textContent === new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(t.getAttribute('datetime')))), 'Full timestamps use the viewer’s locale and zone');
        const calendar = [...d.querySelectorAll('time:not([data-enom-time])')];
        check(calendar.length > 0 && calendar.every(t => /^\d{4}-\d{2}-\d{2}$/.test(t.getAttribute('datetime'))) && calendar.some(t => t.textContent === 'Dec 1, 2026'), 'Calendar dates are left exactly as served');
        check(errors.length === 0, 'Timestamp formatting has no console errors');
        w.close();
    }
    {
        const {w, d, errors} = mount(pages.cooldown);
        const next = [...d.querySelectorAll('#transfer time[data-enom-time]')];
        check(next.length === 1 && !next[0].textContent.includes('UTC') && next[0].title !== '', 'Cooldown end time is shown in local time');
        check(d.querySelector('#transfer button[disabled]') && !d.querySelector('#transfer form[action$="/manage/epp_email"]'), 'Transfer-code email stays disabled during the cooldown');
        check(errors.length === 0, 'Cooldown page has no console errors');
        w.close();
    }

    console.log('Client script: ' + count + ' passed; local jsdom, synthetic fetch and dialog.');
})().catch(error => { console.error(error); process.exit(1); });
