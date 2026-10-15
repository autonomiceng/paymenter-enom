// Actual Blade Help pages with jsdom. No browser, network or installed application.
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const pages = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/uiux-help-render.php'], {maxBuffer: 3e6}));
let count = 0;
const check = (condition, message) => {assert.ok(condition, message); count++;};
const delay = () => new Promise(resolve => setTimeout(resolve, 80));
(async () => {
    let copyPage = null;
    for (const [name, html] of Object.entries(pages)) {
        const dom = new JSDOM(html, {url: 'https://enom-uiux.invalid', runScripts: 'outside-only'});
        const d = dom.window.document;
        const ids = [...d.querySelectorAll('[id]')].map(node => node.id);
        check(ids.length === new Set(ids).size, name + ' has unique IDs');
        for (const node of d.querySelectorAll('[aria-labelledby],[aria-describedby]')) {
            for (const attr of ['aria-labelledby', 'aria-describedby']) {
                check((node.getAttribute(attr) || '').split(/\s+/).filter(Boolean).every(id => d.getElementById(id)), name + ' ARIA reference resolves');
            }
        }
        if (name.startsWith('customer:')) {
            check(!d.querySelector('a[href*="/admin/enom"]'), name + ' excludes admin links');
            check(!/approved_by|limit_minor|account_fingerprint|cost_health/.test(d.body.textContent), name + ' excludes private fields');
        }
        check([...d.querySelectorAll('a')].every(node => !/^javascript:/i.test(node.getAttribute('href') || '')), name + ' links work without JavaScript');
        for (const example of d.querySelectorAll('[data-enom-example]')) {
            check(example.querySelector('pre code[data-enom-example-code]')?.textContent.length > 0, name + ' copy example is visible and selectable');
            check(example.querySelector('button[type="button"][data-enom-copy]')?.hidden, name + ' hides inactive copy button before mount');
            copyPage ??= html;
        }
        dom.window.close();
    }
    check(!!copyPage, 'Help has actual copyable documentation examples');
    for (const mode of ['success', 'rejection', 'unavailable']) {
        const dom = new JSDOM(copyPage, {url: 'https://enom-uiux.invalid', runScripts: 'outside-only'});
        const w = dom.window, d = w.document;
        w.fetch = () => {throw Error('Network forbidden');};
        w.XMLHttpRequest = function () {throw Error('Network forbidden');};
        Object.defineProperty(w, 'isSecureContext', {value: true});
        let writes = [];
        if (mode !== 'unavailable') Object.defineProperty(w.navigator, 'clipboard', {value: {writeText: value => {writes.push(value); return mode === 'success' ? Promise.resolve() : Promise.reject(Error('Denied'));}}});
        const scripts = [...d.querySelectorAll('script')].map(node => node.textContent).filter(text => text.includes('EnomCopyExamples'));
        check(scripts.length === 1, 'Exactly one copy enhancement script');
        w.eval(scripts[0]); w.eval(scripts[0]);
        const button = d.querySelector('[data-enom-copy]'), code = button.closest('[data-enom-example]').querySelector('[data-enom-example-code]');
        check(!button.hidden, 'Copy enhancement shows the button');
        button.click(); await delay();
        const status = button.closest('[data-enom-example]').querySelector('[role="status"][aria-live]') || d.querySelector('[data-enom-copy-status]');
        check(status?.getAttribute('aria-live') === 'polite', 'Copy result is announced');
        check(status?.getAttribute('aria-atomic') === 'true', 'Copy result is announced as a complete message');
        check(!status?.hidden && !status?.classList.contains('sr-only') && !status?.classList.contains('hidden'), 'Copy success and failure are visible to sighted users');
        if (mode === 'success') {
            check(writes.length === 1 && writes[0] === code.textContent, 'Remount copies exact example bytes once');
            check(/^Copied(?:\.| to the clipboard\.)$/.test(status.textContent), 'Successful clipboard write is announced truthfully');
            check(button.textContent === 'Copied', 'Successful copy has visible button feedback');
            await new Promise(resolve => setTimeout(resolve, 2050));
            check(button.textContent === 'Copy example', 'Copy button returns to its action label');
        } else {
            check(status.textContent.includes('Could not copy') && !status.textContent.includes('Copied.'), 'Copy failure is announced truthfully');
            check(w.getSelection().toString() === code.textContent, 'Clipboard failure selects exact example bytes');
            check(d.activeElement === code.closest('pre'), 'Fallback focuses the selectable example');
        }
        dom.window.close();
    }
    console.log(`UI/UX Help and copy DOM: ${count} passed`);
})().catch(error => {console.error(error); process.exit(1);});
