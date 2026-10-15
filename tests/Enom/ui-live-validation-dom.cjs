// Run: node tests/Enom/ui-live-validation-dom.cjs
// Install pinned local development dependencies with npm ci.
const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const pages = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/ui-live-validation-render.php'], {maxBuffer: 2e6}));
const vectors = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['tests/Enom/ui-live-validation-server-vectors.php']));
let count = 0;
const check = (condition, message) => { assert.ok(condition, message); count++; };
function harness(page, before) {
    const dom = new JSDOM(pages[page], {url: 'https://enom-validation.invalid', runScripts: 'outside-only'});
    const w = dom.window;
    w.TextEncoder = TextEncoder;
    w.HTMLElement.prototype.getClientRects = function () { return this.dataset.testHidden ? [] : [{}]; };
    w.HTMLElement.prototype.scrollIntoView = function () {};
    w.fetch = () => { throw Error('Network validation forbidden'); };
    w.XMLHttpRequest = function () { throw Error('Network validation forbidden'); };
    const script = [...w.document.querySelectorAll('script:not([type])')].find(node => node.textContent.includes('window.EnomFormValidation')).textContent;
    before?.(w.document);
    const slots=w.document.querySelectorAll('[data-enom-client-error]').length;
    w.eval(script);
    check(w.document.querySelectorAll('[data-enom-client-error]').length===slots,'Server-rendered error slots are reused');
    for(const form of w.document.querySelectorAll('form[data-enom-validate]')){
        const fields=[...form.querySelectorAll('input,select,textarea')].filter(field=>field.type!=='hidden');
        check(form.querySelectorAll('[data-enom-client-error]').length===fields.length && fields.every(field=>w.document.getElementById(field.id+'-client-error')?.hasAttribute('data-enom-client-error')),'Each opted-in field binds exactly one existing client slot');
    }
    return {dom, w, d: w.document, script};
}
function event(w, field, type) { field.dispatchEvent(new w.Event(type, {bubbles: true})); }
function submit(w, form) { const e = new w.Event('submit', {bubbles: true, cancelable: true}); form.dispatchEvent(e); return e.defaultPrevented; }
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
(async () => {
    {
        const {dom, w, d, script} = harness('order');
        const form = d.querySelector('form');
        const field = name => form.elements.namedItem(name);
        const phone = field('contact[phone]'), country = field('contact[country]');
        const server = d.getElementById('order-contact-phone-error');
        const initialValues = [...form.elements].filter(e => e.type !== 'password').map(e => e.value);
        check(form.noValidate, 'Opted-in form enhances after successful mount');
        check(form.querySelector('[data-enom-summary]').hidden, 'No initial summary');
        event(w, phone, 'blur'); check(d.getElementById(phone.id+'-client-error').hidden, 'Unchanged initially invalid field has no client error on tab-through');
        check(!server.hidden && phone.getAttribute('aria-describedby').includes(phone.id+'-hint'), 'Server error and phone hint remain');
        country.value = 'US'; event(w, country, 'change');
        check(server.hidden && !phone.getAttribute('aria-describedby').includes(server.id), 'Country edit hides dependent phone server message and its ARIA reference');
        country.value = 'United States'; event(w, country, 'change'); check(!server.hidden, 'Country revert restores phone server message');
        country.value = 'US'; phone.value = '(555) 234-5678'; event(w, phone, 'input'); event(w, phone, 'blur');
        check(!phone.hasAttribute('aria-invalid'), 'Accepted phone clears feedback with no validity badge');
        country.value = 'GB'; event(w, country, 'change'); check(phone.getAttribute('aria-invalid') === 'true', 'Country dependency revalidates nonempty national phone');
        phone.value = '+44 20 7946 0123'; event(w, phone, 'input'); check(!phone.hasAttribute('aria-invalid'), 'Correcting error clears immediately');
        phone.value = 'wrong'; event(w, phone, 'input'); check(!phone.hasAttribute('aria-invalid'), 'New errors debounce');
        await delay(340); check(phone.getAttribute('aria-invalid') === 'true', 'Debounced phone error appears');
        phone.value = '+44 20 7946 0123'; event(w, phone, 'input');
        const epp = field('epp');
        epp.value = 'PRIVATE-EPP-SENTINEL';
        // The production script must never read the EPP value/defaultValue.
        Object.defineProperty(epp, 'value', {get() { throw Error('Secret read'); }, set() { throw Error('Secret write'); }});
        Object.defineProperty(epp, 'defaultValue', {get() { throw Error('Secret default read'); }});
        field('confirmed').checked = true;
        check(!submit(w, form), 'Valid form proceeds once without EPP read');
        check(!d.body.textContent.includes('PRIVATE-EPP-SENTINEL'), 'EPP is absent from messages/summary/metadata');
        const first = field('contact[first_name]'); first.value = 'é'.repeat(128); event(w, first, 'input');
        check(submit(w, form), 'UTF8 byte limit blocks on submit');
        check(d.activeElement === first, 'Submit focuses first invalid field');
        check(form.querySelector('[data-enom-summary] a').textContent.includes('First name'), 'Summary has label and actionable copy');
        check(!form.querySelector('[data-enom-summary]').textContent.includes(first.value), 'Raw value not copied into summary');
        const slotCount = form.querySelectorAll('[data-enom-summary]').length;
        w.eval(script); event(w, d, 'livewire:navigated');
        check(form.querySelectorAll('[data-enom-summary]').length === slotCount, 'Repeated script/navigation binds once');
        check(form.querySelectorAll('[id]').length === new Set([...form.querySelectorAll('[id]')].map(e => e.id)).size, 'IDs unique');
        const oldEncoder = w.TextEncoder; w.TextEncoder = class {encode() {throw Error('Injected fault');}};
        check(!submit(w, form) && !form.noValidate, 'Runtime exception fails open and restores native validation'); w.TextEncoder = oldEncoder;
        check(initialValues.length > 10, 'Native contact/EPP form rendered');
        dom.window.close();
    }
    {
        const {dom,w,d} = harness('order');
        const form = d.querySelector('form'), first = form.elements.namedItem('contact[first_name]');
        first.value = ''; event(w, first, 'input'); event(w, first, 'blur'); check(first.getAttribute('aria-invalid') === 'true', 'Typed then empty required field shows feedback');
        dom.window.close();
        const {dom:blank,w:bw,d:bd} = harness('show', document => { const field=document.querySelector('[data-enom-contact="first_name"]'); field.value=''; field.defaultValue=''; });
        const contact = bd.querySelector('[data-enom-contact="first_name"]');
        event(bw, contact, 'blur'); check(!contact.hasAttribute('aria-invalid'), 'Untouched empty required field does not show feedback');
        blank.window.close();
    }
    {
        const {dom,w,d} = harness('show');
        const forms = [...d.querySelectorAll('form[data-enom-validate]')];
        check(forms.filter(form => form.querySelector('[data-enom-contact]') || form.elements.namedItem('nameservers')).length === 5, 'Nameservers and four contact forms enhanced');
        check(d.querySelectorAll('[data-enom-announcer][aria-live="polite"]').length===1,'One validation announcer per page');
        const dns = [...d.querySelectorAll('form')].filter(f => /\/manage\/(hosts|srv|dnssec_)/.test(f.action));
        check(dns.length >= 3 && dns.every(f => !f.noValidate && !f.hasAttribute('data-enom-validate')), 'DNS/SRV/DS untouched');
        const form = forms.find(form => form.querySelector('[data-enom-contact]')), first = form.elements.namedItem('contact[first_name]');
        const others = forms.filter(f => f!==form).map(f => f.outerHTML);
        first.value='é'.repeat(128); form.elements.namedItem('confirmed').checked=true;
        check(submit(w,form), 'Nested contact invalid submit blocked');
        check(d.activeElement === first && [...(function*(){for(let n=first.parentElement;n;n=n.parentElement)if(n.tagName==='DETAILS')yield n;})()].every(n=>n.open), 'Nested details open and invalid control focused');
        check(forms.filter(f=>f!==form).every((f,i)=>f.outerHTML===others[i]), 'Validation is isolated to submitted contact');
        first.dataset.testHidden='1'; check(!submit(w,form), 'Unreachable control skipped'); delete first.dataset.testHidden;
        first.disabled=true; check(!submit(w,form), 'Disabled control skipped'); first.disabled=false;
        first.readOnly=true; check(!submit(w,form), 'Readonly control skipped'); first.readOnly=false;
        const link=form.querySelector('[data-enom-summary] a');
        check(!link, 'Summary cleared when fields skipped');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('order-server-valid'), form=d.querySelector('form');
        form.elements.namedItem('confirmed').checked=true;
        check(!submit(w,form), 'Unchanged PHP server errors alone never block a client-valid resubmission');
        check(!d.getElementById('order-contact-phone-error').hidden, 'Unchanged server error remains visible after permitted resubmission');
        const phone=form.elements.namedItem('contact[phone]');
        phone.value='+1.5552345678';event(w,phone,'input');check(d.getElementById('order-contact-phone-error').hidden,'Edited server phone error hidden');
        phone.value=phone.defaultValue;event(w,phone,'input');check(!d.getElementById('order-contact-phone-error').hidden,'Phone revert restores server error');
        w.TextEncoder=undefined;const first=form.elements.namedItem('contact[first_name]');first.value='é'.repeat(128);event(w,first,'input');event(w,first,'blur');
        check(!first.hasAttribute('aria-invalid'),'Missing TextEncoder fails open for byte checks');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('admin-server'),form=d.querySelector('form[data-enom-validate]'),reason=form.elements.namedItem('reason'),server=d.getElementById(reason.id+'-error');
        check(!server.hidden && reason.getAttribute('aria-describedby').includes(server.id),'Funding native server error is bound accessibly');
        reason.value='Reviewed new cost';event(w,reason,'input');check(server.hidden&&!reason.getAttribute('aria-describedby')?.includes(server.id),'Funding edit hides server error reference');
        reason.value=reason.defaultValue;event(w,reason,'input');check(!server.hidden,'Funding revert restores server error');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('order-server-valid',document=>{
            document.querySelector('label[for="order-contact-first_name"]').textContent='<img src=x onerror=alert(1)>';
        });
        const form=d.querySelector('form'),first=form.elements.namedItem('contact[first_name]');first.value='';form.elements.namedItem('confirmed').checked=true;
        check(submit(w,form),'Empty contact blocks safely with malicious label text');
        check(!form.querySelector('[data-enom-summary] img')&&form.querySelector('[data-enom-summary]').textContent.includes('<img'),'Label text stays inert in summary');
        const link=form.querySelector('[data-enom-summary] a');d.querySelector('button').focus();link.dispatchEvent(new w.MouseEvent('click',{bubbles:true,cancelable:true}));
        check(d.activeElement===first,'Summary link focuses its exact field');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('index');
        const form=d.querySelector('form[data-enom-validate]'), filter=d.querySelector('form[method="GET"]');
        check(filter && !filter.noValidate, 'GET filter remains native');
        check(submit(w,form), 'Native required quote error reaches summary');
        form.elements.namedItem('domain').value='☃.com'; check(submit(w,form), 'Ordinary ASCII domain input rejects unsupported IDN upfront');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('admin'), form=d.querySelector('form[data-enom-validate]');
        check(submit(w,form), 'Native staff required feedback');
        form.elements.namedItem('reason').value='Reviewed cost'; form.elements.namedItem('confirmed').checked=true;
        check(!submit(w,form), 'Native staff valid feedback permits submit');
        dom.window.close();
    }
    {
        const {dom,w,d}=harness('show'), form=d.querySelector('form[data-enom-validate] [data-enom-contact]').form;
        const country=form.elements.namedItem('contact[country]');
        for(const vector of vectors){
            const field=form.elements.namedItem('contact['+vector.field+']');
            let option=[...country.options].find(o=>o.value===vector.country);
            if(!option){option=d.createElement('option');option.value=vector.country;option.dataset.enomUnlisted='';country.append(option);}
            country.value=vector.country;
            field.value=vector.value; event(w,field,'input'); event(w,field,'blur');
            const rejected=!d.getElementById(field.id+'-client-error').hidden;
            check(rejected===vector.rejects, 'Native middleware/PHP differential '+vector.field+' '+JSON.stringify(vector));
        }
        // Invalid surrogate cannot be supplied as valid UTF8 to PHP; replacement encoding should count 3 bytes.
        const first=form.elements.namedItem('contact[first_name]');first.value='a'.repeat(253)+'\uD800';event(w,first,'input');event(w,first,'blur');check(first.hasAttribute('aria-invalid'),'Lone-surrogate byte check uses replacement encoding');
        dom.window.close();
    }
    {
        const dom=new JSDOM(pages.order), d=dom.window.document;
        check(!d.querySelector('form').noValidate, 'JS-off/CSP blocked fallback has native validation');
        check(d.querySelector('[name="contact[first_name]"]').required && d.querySelector('[name="epp"]').required, 'JS-off required constraints retained');
        const rules=JSON.parse(d.querySelector('[data-enom-validation-rules]').textContent);
        check(!JSON.stringify(rules).includes('Demo') && !JSON.stringify(rules).includes('fixture'), 'Rules contain only static metadata');
        check(d.querySelectorAll('[data-enom-contact][required]').length===rules.required.length, 'Blade and PHP required list share metadata');
        dom.window.close();
    }
    console.log('Enom live validation DOM/differential: '+count+' passed; '+vectors.length+' native pipeline vectors');
})().catch(error=>{console.error(error);process.exitCode=1;});
