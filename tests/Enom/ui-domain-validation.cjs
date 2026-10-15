// Native rendered fields + shipped script against authoritative PHP middleware/admission vectors.
// Run: node tests/Enom/ui-domain-validation.cjs
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const pages = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/ui-live-validation-render.php'], {maxBuffer: 2e6}));
const vectors = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/ui-domain-validation-vectors.php']));
let checks = 0;
const check = (ok, message) => { assert.ok(ok, message); checks++; };
function harness(enhance = true) {
    const dom = new JSDOM(pages.index, {url: 'https://enom-validation.invalid', runScripts: 'outside-only'});
    const w = dom.window, d = w.document;
    w.TextEncoder = TextEncoder;
    w.HTMLElement.prototype.getClientRects = function () { return [{}]; };
    w.HTMLElement.prototype.scrollIntoView = function () {};
    w.fetch = () => { throw Error('Network forbidden'); };
    w.XMLHttpRequest = function () { throw Error('Network forbidden'); };
    const form = d.querySelector('form[data-enom-validate]'), field = form.elements.namedItem('domain');
    const script = [...d.querySelectorAll('script:not([type])')].find(node => node.textContent.includes('window.EnomFormValidation')).textContent;
    let posts = 0;
    // Observe whether validation allowed a submit event to reach the simulated POST sink.
    if (enhance) w.eval(script);
    form.addEventListener('submit', event => { if (!event.defaultPrevented) posts++; });
    const submit = () => { const event = new w.Event('submit', {bubbles: true, cancelable: true}); form.dispatchEvent(event); return event.defaultPrevented; };
    const edit = value => { field.value = value; field.dispatchEvent(new w.Event('input', {bubbles: true})); };
    return {dom, w, d, form, field, script, submit, edit, posts: () => posts};
}
for (const vector of vectors) {
    const h = harness();
    h.field.value = vector.value;
    const before = h.field.value;
    const blocked = h.submit();
    check(blocked === vector.frontend_rejects, 'Native pipeline/browser contract: ' + vector.case);
    check(h.field.value === before, 'Validation retains the entered value: ' + vector.case);
    check(h.posts() === (blocked ? 0 : 1), 'No simulated POST for rejected value: ' + vector.case);
    if (blocked) {
        check(h.d.activeElement === h.field, 'Invalid field focused: ' + vector.case);
        const slot = h.d.getElementById(h.field.id + '-client-error');
        check(slot && !slot.hidden && h.field.getAttribute('aria-invalid') === 'true', 'Inline error exposed: ' + vector.case);
        const summary = h.form.querySelector('[data-enom-summary]');
        check(!summary.hidden && summary.querySelector('a')?.hash === '#' + h.field.id, 'Summary links exact invalid field: ' + vector.case);
    }
    h.dom.window.close();
    const native = harness(false);
    native.field.value = vector.value;
    for (const flag of ['u', 'v']) {
        const pattern = new RegExp('^(?:' + native.field.pattern + ')$', flag);
        check(pattern.test(native.field.value) === !vector.frontend_rejects,
            'Native HTML pattern compiles/matches under ' + flag + ': ' + vector.case);
    }
    check(native.form.checkValidity() === !vector.frontend_rejects,
        'JS-off native constraints/browser input format: ' + vector.case);
    native.dom.window.close();
}
{
    const h = harness();
    h.field.value = 'example.net';
    check(h.submit() && h.posts() === 0, 'Unsupported TLD blocked before POST');
    check(/\.com/i.test(h.d.getElementById(h.field.id + '-client-error').textContent), 'Unsupported TLD names supported .com');
    h.edit('EXAMPLE.COM');
    check(!h.field.hasAttribute('aria-invalid') && h.form.querySelector('[data-enom-summary]').hidden, 'Correction immediately clears error/summary');
    check(!h.submit() && h.posts() === 1 && h.field.value === 'EXAMPLE.COM', 'Corrected name permits exactly one POST without rewriting input');
    const key = h.form.elements.namedItem('_request_key').value;
    const slots = h.form.querySelectorAll('[data-enom-client-error]').length;
    h.w.eval(h.script); h.d.dispatchEvent(new h.w.Event('livewire:navigated'));
    check(!h.submit() && h.posts() === 2 && h.form.querySelectorAll('[data-enom-client-error]').length === slots, 'Repeated mount avoids duplicate submission handlers/error slots');
    check(h.form.elements.namedItem('_request_key').value === key, 'Validation preserves original request key');
    const filter = h.d.querySelector('form[method="GET"]');
    check(filter && !filter.noValidate && !filter.elements.namedItem('search').hasAttribute('pattern'), 'Domain filter remains native and accepts arbitrary search text');
    h.dom.window.close();
}
{
    const h = harness(false);
    check(!h.form.noValidate && h.field.required && h.field.hasAttribute('pattern'), 'JS-off native constraints survive');
    h.field.value = 'example.net';
    check(!h.form.checkValidity(), 'JS-off native pattern blocks unsupported ending');
    h.field.value = 'Example.COM';
    check(h.form.checkValidity(), 'JS-off ordinary mixed-case .com allowed');
    h.field.value = 'a'.repeat(63) + '.com';
    check(h.form.checkValidity(), 'JS-off 63-character label allowed');
    h.field.value = 'a'.repeat(64) + '.com';
    check(!h.form.checkValidity(), 'JS-off 64-character label blocked');
    h.dom.window.close();
}
console.log(JSON.stringify({complete: true, checks, native_pipeline_vectors: vectors.length,
    deliberate_ascii_browser_policy: true, provider_calls: 0, 
    limits: 'JSDOM submit sink is synthetic. Actual HTTP, browser HTML-pattern engine, CSP and handset behavior remain separate.'}));
