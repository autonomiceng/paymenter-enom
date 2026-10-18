{{-- Scoped styles. Stock Paymenter builds only compile Tailwind classes the theme itself uses, so Enom ships its own
     small component layer built on the theme's colour tokens (--color-*), which follow the light/dark switch. --}}
@once
<style>
/* Legacy helpers still used by staff pages. */
.enom-ui { overflow-wrap: anywhere; }
.enom-ui :is(table, pre) { overflow-wrap: normal; }
.enom-ui [hidden] { display: none !important; }
.enom-ui :is(button, select, summary, .enom-target),
.enom-ui input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]) { min-height: 44px; }
.enom-ui summary { cursor: pointer; padding-block: 0.5rem; }
.enom-ui .enom-link { display: inline-flex; align-items: center; min-height: 44px; }
.enom-ui .enom-check { display: flex; align-items: flex-start; gap: 0.5rem; min-height: 44px; padding-block: 0.75rem; cursor: pointer; }
.enom-ui .enom-check input[type="checkbox"], .enom-ui .enom-box { width: 1.25rem; height: 1.25rem; flex: none; margin-top: 0.125rem; }
.enom-ui :is(a, button, input, select, textarea, summary, pre):focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
.enom-ui .enom-warning { border-color: var(--color-yellow-500); background-color: color-mix(in srgb, var(--color-yellow-500) 10%, transparent); }
.enom-ui .enom-table th, .enom-ui .enom-table td { vertical-align: top; }
.enom-ui ol.enom-steps { list-style: decimal; padding-left: 1.25rem; }
.enom-ui ul.enom-list { list-style: disc; padding-left: 1.25rem; }
.enom-ui .enom-nav { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; }
.enom-ui .enom-prose { max-width: 72ch; }
.enom-ui .enom-prose > * + * { margin-top: 0.75rem; }
.enom-ui .enom-prose h2 { font-size: 1.125rem; font-weight: 600; margin-top: 1.5rem; }

/* ---------- Client area ---------- */
.enom {
    --e-fg: hsl(var(--color-base));
    --e-muted: hsl(var(--color-muted));
    --e-line: hsl(var(--color-neutral));
    --e-bg: hsl(var(--color-background));
    --e-panel: hsl(var(--color-background-secondary));
    --e-accent: hsl(var(--color-primary));
    --e-field: var(--e-panel);
    --e-ok: color-mix(in srgb, hsl(var(--color-success)) 70%, black);
    --e-info: color-mix(in srgb, hsl(var(--color-info)) 75%, black);
    --e-warn: color-mix(in srgb, hsl(var(--color-warning)) 78%, black);
    --e-bad: color-mix(in srgb, hsl(var(--color-error)) 80%, black);
    --e-quiet: hsl(var(--color-muted));
    max-width: 72rem; margin-inline: auto; color: var(--e-fg); overflow-wrap: anywhere;
}
.dark .enom {
    --e-ok: color-mix(in srgb, hsl(var(--color-success)) 80%, white);
    --e-info: color-mix(in srgb, hsl(var(--color-info)) 80%, white);
    --e-warn: color-mix(in srgb, hsl(var(--color-warning)) 85%, white);
    --e-bad: color-mix(in srgb, hsl(var(--color-error)) 82%, white);
}
.enom [hidden] { display: none !important; }
.enom > * + * { margin-top: 1.5rem; }
.enom :is(a, button, input, select, textarea, summary):focus-visible { outline: 2px solid var(--e-accent); outline-offset: 2px; }
.enom .enom-i { width: 1rem; height: 1rem; flex: none; }
.enom .enom-muted { color: var(--e-muted); }
.enom .enom-small { font-size: .8125rem; line-height: 1.25rem; }
.enom .enom-num { font-variant-numeric: tabular-nums; }
.enom .enom-nowrap { white-space: nowrap; }
.enom .enom-a { color: var(--e-accent); font-weight: 600; text-underline-offset: 3px; }
.enom .enom-a:hover { text-decoration: underline; }
.enom .enom-back { display: inline-flex; align-items: center; gap: .25rem; font-size: .875rem; color: var(--e-muted); min-height: 2rem; }
.enom .enom-back:hover { color: var(--e-fg); }
.enom .enom-stack > * + * { margin-top: 1rem; }
.enom .enom-stack-sm > * + * { margin-top: .5rem; }
.enom .enom-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem .75rem; }
.enom-sr, .enom .enom-sr { position: absolute !important; width: 1px !important; height: 1px !important; padding: 0 !important; margin: -1px !important; border: 0 !important; overflow: hidden !important; clip: rect(0 0 0 0) !important; clip-path: inset(50%) !important; white-space: nowrap !important; }

/* Page header */
.enom .enom-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.enom .enom-title { font-size: 1.625rem; line-height: 2.125rem; font-weight: 700; letter-spacing: -.015em; }
.enom .enom-lede { margin-top: .25rem; color: var(--e-muted); font-size: .9375rem; max-width: 60ch; }
.enom h2.enom-h2 { font-size: 1.0625rem; font-weight: 700; line-height: 1.5rem; }
.enom h3.enom-h3 { font-size: .9375rem; font-weight: 700; }

@media (max-width: 639px) { .enom .enom-head > .enom-btn--ghost { margin-left: -.5rem; } }

/* Buttons */
.enom .enom-btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2.5rem; padding: 0 1rem;
    border-radius: .375rem; border: 1px solid transparent; font-size: .875rem; font-weight: 600; line-height: 1.25rem; white-space: nowrap;
    cursor: pointer; transition: background-color .15s, border-color .15s, color .15s; text-decoration: none; }
.enom .enom-btn--primary { background: var(--e-accent); color: #fff; }
.enom .enom-btn--primary:hover { background: hsl(var(--color-primary) / .85); }
.enom .enom-btn--secondary { background: var(--e-panel); border-color: var(--e-line); color: var(--e-fg); }
.enom .enom-panel .enom-btn--secondary { background: var(--e-bg); }
.enom .enom-btn--secondary:hover { border-color: color-mix(in srgb, var(--e-fg) 30%, var(--e-line)); }
.enom .enom-btn--ghost { background: transparent; color: var(--e-accent); padding-inline: .5rem; }
.enom .enom-btn--ghost:hover { background: hsl(var(--color-primary) / .08); }
.enom .enom-btn--danger { background: hsl(var(--color-error)); color: #fff; }
.enom .enom-btn--danger:hover { background: hsl(var(--color-error) / .85); }
.enom .enom-btn--sm { min-height: 2rem; padding: 0 .75rem; font-size: .8125rem; }
.enom .enom-btn:disabled, .enom .enom-btn[aria-disabled="true"] { opacity: .5; cursor: not-allowed; }
@media (pointer: coarse) { .enom .enom-btn { min-height: 2.75rem; } .enom .enom-btn--sm { min-height: 2.5rem; } }

/* Panels and sections */
.enom .enom-panel { --e-field: var(--e-bg); background: var(--e-panel); border: 1px solid var(--e-line); border-radius: .75rem; }
.enom .enom-section { padding: 1.25rem 1.5rem; }
.enom .enom-section + .enom-section { border-top: 1px solid var(--e-line); }
.enom .enom-section__head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem 1rem; margin-bottom: 1rem; }
.enom .enom-section__head:last-child { margin-bottom: 0; }
.enom .enom-section__head p { color: var(--e-muted); font-size: .875rem; margin-top: .125rem; max-width: 62ch; }
@media (max-width: 639px) { .enom .enom-section { padding: 1rem; } }

/* Badges */
.enom .enom-badge { display: inline-flex; align-items: center; gap: .375rem; padding: .125rem .625rem; border-radius: 999px; font-size: .75rem;
    font-weight: 700; line-height: 1.25rem; white-space: nowrap; color: var(--e-quiet); background: color-mix(in srgb, currentColor 13%, transparent); }
.enom .enom-badge::before { content: ""; width: .375rem; height: .375rem; border-radius: 50%; background: currentColor; }
.enom .enom-tone-ok { color: var(--e-ok); }
.enom .enom-tone-info { color: var(--e-info); }
.enom .enom-tone-warn { color: var(--e-warn); }
.enom .enom-tone-bad { color: var(--e-bad); }
.enom .enom-tone-muted { color: var(--e-quiet); }

/* Notices */
.enom .enom-notice { display: flex; align-items: flex-start; gap: .75rem; padding: .875rem 1rem; border-radius: .5rem; font-size: .875rem; line-height: 1.375rem;
    border: 1px solid color-mix(in srgb, var(--tone, var(--e-info)) 35%, transparent); background: color-mix(in srgb, var(--tone, var(--e-info)) 8%, transparent); }
.enom .enom-notice > svg { width: 1.25rem; height: 1.25rem; flex: none; color: var(--tone, var(--e-info)); margin-top: .0625rem; }
.enom .enom-notice--ok { --tone: var(--e-ok); }
.enom .enom-notice--warn { --tone: var(--e-warn); }
.enom .enom-notice--bad { --tone: var(--e-bad); }
.enom .enom-notice strong { font-weight: 700; }
.enom .enom-notice ul { margin-top: .25rem; }
.enom .enom-testbar { display: flex; align-items: center; gap: .5rem; padding: .5rem .875rem; font-size: .8125rem; border-radius: .5rem;
    color: var(--e-warn); border: 1px dashed color-mix(in srgb, var(--e-warn) 55%, transparent); background: color-mix(in srgb, var(--e-warn) 7%, transparent); }
.enom .enom-testbar span { color: var(--e-fg); }

/* Forms */
.enom .enom-label { display: block; font-size: .8125rem; font-weight: 600; margin-bottom: .375rem; }
.enom .enom-label .enom-opt { font-weight: 400; color: var(--e-muted); }
.enom .enom-input { display: block; width: 100%; min-height: 2.625rem; padding: .5rem .75rem; font-size: .875rem; line-height: 1.5rem; color: var(--e-fg);
    background: var(--e-field); border: 1px solid var(--e-line); border-radius: .375rem; transition: border-color .15s, box-shadow .15s; }
.enom textarea.enom-input { min-height: 6.5rem; resize: vertical; }
.enom select.enom-input { appearance: none; padding-right: 2.25rem;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23888'%3E%3Cpath d='M12 15.6 6.4 10l1.4-1.4 4.2 4.2 4.2-4.2 1.4 1.4z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right .5rem center; background-size: 1.25rem; }
.enom .enom-input::placeholder { color: var(--e-muted); opacity: .8; }
.enom .enom-input:focus { outline: none; border-color: var(--e-accent); box-shadow: 0 0 0 3px hsl(var(--color-primary) / .18); }
.enom .enom-input[aria-invalid="true"] { border-color: var(--e-bad); }
.enom .enom-input:disabled { opacity: .55; }
.enom .enom-hint { font-size: .75rem; line-height: 1.125rem; color: var(--e-muted); margin-top: .375rem; }
.enom .enom-error { font-size: .75rem; line-height: 1.125rem; color: var(--e-bad); margin-top: .375rem; font-weight: 600; }
.enom .enom-fields { display: grid; gap: 1rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.enom .enom-fields .enom-span { grid-column: 1 / -1; }
@media (max-width: 639px) { .enom .enom-fields { grid-template-columns: minmax(0, 1fr); } }
.enom .enom-checkbox { display: flex; align-items: flex-start; gap: .625rem; font-size: .875rem; cursor: pointer; padding-block: .25rem; }
.enom .enom-checkbox input, .enom .enom-choice input { width: 1.125rem; height: 1.125rem; flex: none; margin-top: .125rem; accent-color: var(--e-accent); }
.enom .enom-choice { display: flex; align-items: flex-start; gap: .75rem; padding: .875rem 1rem; border: 1px solid var(--e-line); border-radius: .5rem; cursor: pointer; background: var(--e-field); }
.enom .enom-choice:has(input:checked) { border-color: var(--e-accent); box-shadow: inset 0 0 0 1px var(--e-accent); }
.enom .enom-choice strong { display: block; font-size: .875rem; }
.enom .enom-choice span { font-size: .8125rem; color: var(--e-muted); }
.enom .enom-choices { display: grid; gap: .5rem; }
.enom details.enom-more > summary { cursor: pointer; font-size: .8125rem; font-weight: 600; color: var(--e-accent); list-style: none; display: inline-flex; align-items: center; gap: .25rem; min-height: 2rem; }
.enom details.enom-more > summary::-webkit-details-marker { display: none; }
.enom details.enom-more > summary::before { content: "+"; width: 1rem; text-align: center; }
.enom details.enom-more[open] > summary::before { content: "−"; }

/* Switch */
.enom .enom-switch { display: inline-flex; align-items: center; gap: .625rem; font-size: .875rem; font-weight: 600; cursor: pointer; background: none; border: 0; padding: .25rem 0; color: var(--e-fg); }
.enom .enom-switch__track { position: relative; width: 2.5rem; height: 1.375rem; border-radius: 999px; background: color-mix(in srgb, var(--e-muted) 45%, transparent); transition: background-color .15s; flex: none; }
.enom .enom-switch__track::after { content: ""; position: absolute; top: .1875rem; left: .1875rem; width: 1rem; height: 1rem; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgb(0 0 0 / .3); transition: transform .15s; }
.enom .enom-switch[aria-checked="true"] .enom-switch__track { background: var(--e-accent); }
.enom .enom-switch[aria-checked="true"] .enom-switch__track::after { transform: translateX(1.125rem); }
.enom .enom-switch:disabled { cursor: not-allowed; opacity: .55; }

/* Tables */
.enom .enom-tablewrap { overflow-x: auto; }
.enom table.enom-tbl { width: 100%; border-collapse: separate; border-spacing: 0; font-size: .875rem; }
.enom .enom-tbl th { text-align: left; font-weight: 600; font-size: .75rem; color: var(--e-muted); padding: .625rem 1rem; border-bottom: 1px solid var(--e-line); white-space: nowrap; }
.enom .enom-tbl th a { display: inline-flex; align-items: center; gap: .25rem; color: inherit; }
.enom .enom-tbl th a:hover, .enom .enom-tbl th a[aria-current] { color: var(--e-fg); }
.enom .enom-tbl td { padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); vertical-align: middle; }
.enom .enom-tbl tbody tr:last-child td { border-bottom: 0; }
.enom .enom-tbl tbody tr:hover td { background: color-mix(in srgb, var(--e-line) 22%, transparent); }
.enom .enom-tbl .enom-end { text-align: right; }
.enom .enom-domain { font-weight: 700; letter-spacing: -.005em; color: var(--e-fg); }
.enom a.enom-domain:hover { color: var(--e-accent); }
@media (max-width: 719px) {
    .enom table.enom-tbl.enom-stackable, .enom .enom-stackable tbody { display: block; }
    .enom .enom-stackable thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .enom .enom-stackable tr { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .375rem .75rem; padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); }
    .enom .enom-stackable tbody tr:last-child { border-bottom: 0; }
    .enom .enom-stackable td { display: block; padding: 0; border: 0; }
    .enom .enom-stackable tbody tr:hover td { background: none; }
    .enom .enom-stackable td[data-label]::before { content: attr(data-label) " "; color: var(--e-muted); font-size: .75rem; }
    .enom .enom-stackable .enom-cell-wide { grid-column: 1 / -1; }
    .enom .enom-stackable .enom-end { text-align: left; }
}

/* Toolbar and chips */
.enom .enom-toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid var(--e-line); }
.enom .enom-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
.enom .enom-chip { display: inline-flex; align-items: center; gap: .375rem; min-height: 2rem; padding: 0 .75rem; border-radius: 999px; border: 1px solid var(--e-line);
    font-size: .8125rem; font-weight: 600; color: var(--e-muted); background: var(--e-bg); }
.enom .enom-chip:hover { color: var(--e-fg); }
.enom .enom-chip[aria-current="true"] { color: var(--e-accent); border-color: hsl(var(--color-primary) / .5); background: hsl(var(--color-primary) / .08); }
.enom .enom-chip small { font-size: .75rem; font-weight: 600; opacity: .75; }
.enom .enom-filter { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.enom .enom-filter .enom-input { min-height: 2.25rem; width: auto; }
.enom .enom-search-mini { position: relative; }
.enom .enom-search-mini svg { position: absolute; left: .625rem; top: 50%; transform: translateY(-50%); color: var(--e-muted); pointer-events: none; }
.enom .enom-search-mini .enom-input { padding-left: 2rem; width: 15rem; max-width: 100%; }
@media (max-width: 639px) { .enom .enom-search-mini, .enom .enom-search-mini .enom-input { width: 100%; } .enom .enom-filter { width: 100%; } }

/* Empty state */
.enom .enom-empty { padding: 3rem 1.5rem; text-align: center; }
.enom .enom-empty__mark { display: inline-flex; padding: .75rem; border-radius: .75rem; background: hsl(var(--color-primary) / .1); color: var(--e-accent); margin-bottom: 1rem; }
.enom .enom-empty__mark svg { width: 1.75rem; height: 1.75rem; }
.enom .enom-empty p { color: var(--e-muted); max-width: 42ch; margin: .375rem auto 1.25rem; font-size: .9375rem; }

/* Search */
.enom .enom-searchbar { display: flex; align-items: stretch; gap: .375rem; padding: .375rem; border: 1px solid var(--e-line); border-radius: .875rem;
    background: var(--e-panel); transition: border-color .15s, box-shadow .15s; }
.enom .enom-searchbar:focus-within { border-color: var(--e-accent); box-shadow: 0 0 0 4px hsl(var(--color-primary) / .14); }
.enom .enom-searchbar input { flex: 1 1 auto; min-width: 0; border: 0; background: transparent; color: var(--e-fg); font-size: 1.1875rem; font-weight: 600; padding: .625rem .875rem; letter-spacing: -.005em; }
.enom .enom-searchbar input::placeholder { color: var(--e-muted); font-weight: 400; }
.enom .enom-searchbar :is(input, select):focus { outline: none; box-shadow: none; }
.enom .enom-searchbar :is(input, select):focus-visible { outline: 2px solid currentColor; outline-offset: -3px; }
.enom .enom-searchbar select { flex: none; border: 0; border-left: 1px solid var(--e-line); border-radius: 0; background-color: transparent; color: var(--e-fg);
    font-size: 1rem; font-weight: 700; padding: 0 2.25rem 0 1rem; min-height: 3rem; width: auto; }
.enom .enom-searchbar .enom-btn { min-height: 3rem; padding: 0 1.375rem; border-radius: .5rem; font-size: .9375rem; }
@media (max-width: 639px) {
    .enom .enom-searchbar { flex-wrap: wrap; }
    .enom .enom-searchbar input { flex-basis: 100%; }
    .enom .enom-searchbar select { flex: 1 1 auto; border-left: 0; border-top: 1px solid var(--e-line); border-radius: .375rem; }
    .enom .enom-searchbar .enom-btn { flex: 1 1 auto; }
}
.enom .enom-endings { display: flex; flex-wrap: wrap; gap: .25rem .875rem; font-size: .8125rem; color: var(--e-muted); margin-top: .75rem; }
.enom .enom-endings b { color: var(--e-fg); font-weight: 700; }

.enom .enom-results { border: 1px solid var(--e-line); border-radius: .875rem; background: var(--e-panel); overflow: hidden; }
.enom .enom-result { display: grid; grid-template-columns: minmax(12rem, 1fr) max-content 12rem 9.5rem; align-items: center; gap: .5rem 1.25rem; padding: 1rem 1.25rem; }
.enom .enom-result + .enom-result { border-top: 1px solid var(--e-line); }
.enom .enom-result__name { min-width: 0; overflow-wrap: anywhere; font-size: 1.0625rem; font-weight: 700; letter-spacing: -.01em; }
.enom .enom-result__note { grid-column: 1 / -1; max-width: 96ch; margin: 0; line-height: 1.5; }
.enom .enom-result__note:empty { display: none; }
.enom .enom-result__name .enom-tld { color: var(--e-accent); }
.enom .enom-result.is-first { padding-block: 1.375rem; background: color-mix(in srgb, var(--e-bg) 40%, transparent); }
.enom .enom-result.is-first .enom-result__name { font-size: 1.375rem; }
.enom .enom-result__price { text-align: right; font-variant-numeric: tabular-nums; line-height: 1.25rem; }
.enom .enom-result__price strong { font-size: 1rem; }
.enom .enom-result__price small { display: block; font-size: .75rem; color: var(--e-muted); }
.enom .enom-result__action { display: flex; justify-content: flex-end; }
.enom .enom-result__action > * { display: flex; }
.enom .enom-result__action form { width: 100%; }
.enom .enom-result__action .enom-retry { flex-direction: column; align-items: flex-end; gap: .25rem; }
.enom .enom-result__action .enom-btn { width: 100%; }
.enom .enom-result[data-state="taken"] .enom-result__name, .enom .enom-result[data-state="premium"] .enom-result__name,
.enom .enom-result[data-state="managed"] .enom-result__name, .enom .enom-result[data-state="invalid"] .enom-result__name { color: var(--e-muted); }
.enom .enom-result[data-state="taken"] .enom-tld, .enom .enom-result[data-state="premium"] .enom-tld,
.enom .enom-result[data-state="managed"] .enom-tld, .enom .enom-result[data-state="invalid"] .enom-tld { color: inherit; }
.enom .enom-checking { display: inline-flex; align-items: center; gap: .5rem; font-size: .8125rem; color: var(--e-muted); }
.enom .enom-checking::before { content: ""; width: .875rem; height: .875rem; border-radius: 50%; border: 2px solid color-mix(in srgb, var(--e-muted) 35%, transparent);
    border-top-color: var(--e-accent); animation: enom-spin .8s linear infinite; }
@keyframes enom-spin { to { transform: rotate(360deg); } }
.enom:not(.enom-js) [data-js-only] { display: none !important; }
.enom.enom-js [data-nojs-only] { display: none !important; }
.enom.enom-js .enom-result [data-when] { display: none; }
.enom.enom-js .enom-result[data-state="available"] [data-when~="available"],
.enom.enom-js .enom-result[data-state="transferable"] [data-when~="transferable"],
.enom.enom-js .enom-result[data-state="taken"] [data-when~="taken"],
.enom.enom-js .enom-result[data-state="yours"] [data-when~="yours"],
.enom.enom-js .enom-result[data-state="error"] [data-when~="error"] { display: flex; }
@media (max-width: 959px) {
    .enom .enom-result { grid-template-columns: minmax(0, 1fr) auto; }
    .enom .enom-result__name { grid-column: 1 / -1; }
    .enom .enom-result.is-first .enom-result__name { font-size: 1.1875rem; }
    .enom .enom-result__price { text-align: left; }
    .enom .enom-result__status { grid-column: 1; grid-row: 2; }
    .enom .enom-result__price { grid-column: 1; grid-row: 3; }
    .enom .enom-result__price:empty { display: none; }
    .enom .enom-result__action { grid-column: 2; grid-row: 2 / span 2; align-self: center; min-width: 7.5rem; }
    /* Without a price the note moves up into row 3, so no empty track is left. */
    .enom .enom-result:has(> .enom-result__price:empty) .enom-result__action { grid-row: 2; }
}

/* Term picker on search rows */
.enom .enom-buy { display: flex; gap: .5rem; }
.enom .enom-buy .enom-btn { flex: 1 1 auto; width: auto; }
.enom select.enom-term { width: 7.25rem; flex: none; min-height: 2.5rem; padding-block: 0; font-size: .8125rem; font-weight: 600; }
@media (max-width: 639px) {
    .enom .enom-buy { flex-direction: column; gap: .375rem; }
    .enom .enom-buy select.enom-term { width: 100%; }
}

/* Registration term in the order summary */
.enom .enom-termpick { margin-bottom: 1rem; }
.enom .enom-termpick__row { display: flex; gap: .5rem; }
.enom .enom-termpick__row select { flex: 1 1 auto; }
.enom .enom-summary .enom-termpick .enom-btn { width: auto; min-height: 2.625rem; font-size: .875rem; }

/* Renew now */
.enom .enom-renew { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.enom .enom-renew select.enom-term { width: auto; min-width: 11rem; font-size: .875rem; }
@media (max-width: 639px) { .enom .enom-renew, .enom .enom-renew select.enom-term { width: 100%; } .enom .enom-renew .enom-btn { flex: 1; } }

/* Child nameservers */
.enom .enom-glue { border: 1px solid var(--e-line); border-radius: .5rem; background: var(--e-bg); margin-bottom: 1.25rem; }
.enom .enom-glue > li + li { border-top: 1px solid var(--e-line); }
.enom .enom-glue__item > summary, .enom .enom-glue__static { display: grid; grid-template-columns: minmax(0, 1fr) auto 3.5rem; align-items: center; gap: 1rem;
    padding: .75rem 1rem; list-style: none; }
.enom .enom-glue__static { grid-template-columns: minmax(0, 1fr) auto; }
.enom .enom-glue__item > summary { cursor: pointer; border-radius: .5rem; }
.enom .enom-glue__item > summary::-webkit-details-marker { display: none; }
.enom .enom-glue__item > summary:hover { background: color-mix(in srgb, var(--e-line) 22%, transparent); }
.enom .enom-glue__host { font-weight: 700; font-size: .9375rem; }
@media (max-width: 639px) {
    .enom .enom-glue__item > summary, .enom .enom-glue__static { grid-template-columns: minmax(0, 1fr) auto; gap: .125rem 1rem; }
    .enom .enom-glue__host { grid-column: 1; }
    .enom .enom-glue__ip { grid-column: 1; grid-row: 2; }
    .enom .enom-glue__edit { grid-column: 2; grid-row: 1 / span 2; }
}
.enom .enom-glue__ip { color: var(--e-muted); font-size: .875rem; }
.enom .enom-glue__edit { font-size: .8125rem; font-weight: 600; color: var(--e-accent); text-align: right; }
.enom .enom-glue__edit::before { content: "Edit"; }
.enom .enom-glue__item[open] .enom-glue__edit { color: var(--e-muted); }
.enom .enom-glue__item[open] .enom-glue__edit::before { content: "Close"; }
.enom .enom-glue__panel { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem 1rem; padding: .25rem 1rem 1rem; }
.enom .enom-glue__form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: .5rem; flex: 1 1 20rem; }
.enom .enom-glue__form [data-field] { flex: 1 1 14rem; max-width: 20rem; }
.enom .enom-btn--danger-text { color: var(--e-bad); }
.enom .enom-btn--danger-text:hover { background: color-mix(in srgb, var(--e-bad) 10%, transparent); }
.enom .enom-glue__new .enom-h3 { margin-bottom: .75rem; }
.enom .enom-glue__fields { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto; gap: 1rem; align-items: start; }
.enom .enom-glue__submit { padding-top: 1.6875rem; }
@media (max-width: 799px) { .enom .enom-glue__fields { grid-template-columns: minmax(0, 1fr); } .enom .enom-glue__submit { padding-top: 0; } }
.enom .enom-affix { display: flex; align-items: stretch; border: 1px solid var(--e-line); border-radius: .375rem; background: var(--e-field); transition: border-color .15s, box-shadow .15s; }
.enom .enom-affix:focus-within { border-color: var(--e-accent); box-shadow: 0 0 0 3px hsl(var(--color-primary) / .18); }
.enom .enom-affix:has([aria-invalid="true"]) { border-color: var(--e-bad); }
/* The typed part sits flush against the suffix, so it reads as one hostname. */
.enom .enom-affix .enom-input { border: 0; box-shadow: none; background: transparent; min-width: 3.5rem; width: 0; flex: 1 1 0%; padding-right: 0; text-align: right; }
.enom .enom-affix .enom-input:focus { box-shadow: none; }
.enom .enom-affix .enom-input::placeholder { text-align: right; }
.enom .enom-affix__text { display: block; align-self: center; padding-right: .75rem; font-size: .875rem; color: var(--e-muted); min-width: 0; max-width: 75%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 0 1 auto; }

/* Activity refund note */
.enom .enom-refund { display: block; margin-top: .125rem; font-size: .8125rem; color: var(--e-muted); }

/* Price list */
.enom .enom-pricelist tbody th { font-size: .9375rem; color: var(--e-fg); padding: .875rem 1rem; border-bottom: 1px solid var(--e-line); font-weight: 700; }
.enom .enom-pricelist tbody tr:last-child th { border-bottom: 0; }
@media (max-width: 719px) {
    .enom .enom-pricelist.enom-stackable tr { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .enom .enom-pricelist.enom-stackable tbody th { display: block; padding: 0; border: 0; grid-column: 1 / -1; }
    .enom .enom-pricelist.enom-stackable td[data-label]::before { display: block; }
}

/* Order */
.enom .enom-order { display: grid; grid-template-columns: minmax(0, 1fr) 21rem; gap: 1.5rem; align-items: start; }
.enom .enom-summary { position: sticky; top: 5.5rem; }
.enom .enom-summary dl { display: grid; grid-template-columns: 1fr auto; gap: .5rem 1rem; font-size: .875rem; }
.enom .enom-summary dt { color: var(--e-muted); }
.enom .enom-summary dd { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.enom .enom-summary dl.enom-total { border-top: 1px solid var(--e-line); padding-top: .875rem; margin-top: .875rem; font-size: 1rem; }
.enom .enom-summary .enom-total dt { color: var(--e-fg); font-weight: 700; }
.enom .enom-summary .enom-btn { width: 100%; min-height: 2.75rem; font-size: .9375rem; }
@media (max-width: 899px) { .enom .enom-order { grid-template-columns: minmax(0, 1fr); } .enom .enom-summary { position: static; } }

/* Domain page */
.enom .enom-dhead { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem 2rem; }
.enom .enom-dhead__meta { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; margin-top: .5rem; font-size: .875rem; color: var(--e-muted); }
.enom .enom-dhead__meta strong { color: var(--e-fg); font-weight: 600; }
.enom .enom-tabs { display: flex; gap: .25rem; border-bottom: 1px solid var(--e-line); overflow-x: auto; scrollbar-width: none; }
.enom .enom-tabs::-webkit-scrollbar { display: none; }
.enom .enom-tab { display: inline-flex; align-items: center; gap: .375rem; padding: .75rem .875rem; font-size: .875rem; font-weight: 600; color: var(--e-muted);
    border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
.enom .enom-tab:hover { color: var(--e-fg); }
.enom .enom-tab[aria-selected="true"] { color: var(--e-fg); border-bottom-color: var(--e-accent); }
.enom .enom-tab .enom-dot { width: .4375rem; height: .4375rem; border-radius: 50%; background: var(--e-warn); }
.enom .enom-tabpanel + .enom-tabpanel { margin-top: 1.5rem; }
.enom.enom-js .enom-tabpanel { margin-top: 1.25rem; }
.enom .enom-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); }
.enom .enom-facts { padding-block: .5rem; }
.enom .enom-facts:not(:last-child) { border-bottom: 1px solid var(--e-line); }
.enom .enom-fact { padding: .875rem 1.5rem; }
@media (max-width: 639px) { .enom .enom-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } .enom .enom-fact { padding: .75rem 1rem; } }
.enom .enom-fact dt { font-size: .8125rem; color: var(--e-muted); }
.enom .enom-fact dd { margin-top: .25rem; font-size: 1rem; font-weight: 700; }
.enom .enom-fact dd small { display: block; font-size: .8125rem; font-weight: 400; color: var(--e-muted); margin-top: .125rem; }
.enom .enom-hosts { display: grid; gap: .25rem; font-size: .9375rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.enom .enom-hosts li { display: flex; align-items: center; gap: .625rem; }
.enom .enom-hosts li::before { content: ""; width: .375rem; height: .375rem; border-radius: 50%; background: var(--e-line); }
.enom ol.enom-steps2 { counter-reset: s; display: grid; gap: .625rem; font-size: .875rem; }
.enom ol.enom-steps2 li { counter-increment: s; display: flex; gap: .75rem; align-items: baseline; }
.enom ol.enom-steps2 li::before { content: counter(s); flex: none; width: 1.375rem; height: 1.375rem; border-radius: 50%; display: inline-grid; place-items: center;
    font-size: .75rem; font-weight: 700; background: hsl(var(--color-primary) / .12); color: var(--e-accent); }
.enom .enom-status-card { padding: 1.75rem 1.5rem; }
.enom .enom-status-card h2 { font-size: 1.25rem; font-weight: 700; }
.enom .enom-status-card p { color: var(--e-muted); max-width: 60ch; margin-top: .375rem; }
.enom .enom-status-card .enom-input { max-width: 28rem; }
.enom .enom-progress { display: flex; gap: .375rem; margin-top: 1.25rem; max-width: 28rem; }
.enom .enom-progress span { flex: 1; height: .3125rem; border-radius: 999px; background: color-mix(in srgb, var(--e-muted) 25%, transparent); }
.enom .enom-progress span.is-done { background: var(--e-accent); }
.enom .enom-progress-labels { display: flex; justify-content: space-between; max-width: 28rem; font-size: .75rem; color: var(--e-muted); margin-top: .375rem; }

/* Dialog */
.enom-dialog { margin: auto; max-height: calc(100vh - 2rem); border: 0; padding: 0; border-radius: .75rem; width: min(28rem, calc(100vw - 2rem)); background: hsl(var(--color-background-secondary)); color: hsl(var(--color-base));
    box-shadow: 0 24px 60px rgb(0 0 0 / .35); }
.enom-dialog::backdrop { background: rgb(0 0 0 / .55); }
.enom-dialog form { padding: 1.5rem; }
.enom-dialog h2 { font-size: 1.125rem; font-weight: 700; }
.enom-dialog p { margin-top: .5rem; font-size: .9375rem; line-height: 1.5rem; color: hsl(var(--color-muted)); }
.enom-dialog .enom-dialog__actions { display: flex; flex-wrap: wrap-reverse; justify-content: flex-end; gap: .5rem; margin-top: 1.5rem; }
.enom-dialog .enom-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.5rem; padding: 0 1rem; border-radius: .375rem; font-size: .875rem; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.enom-dialog .enom-btn--secondary { background: transparent; border-color: hsl(var(--color-neutral)); color: hsl(var(--color-base)); }
.enom-dialog .enom-btn--primary { background: hsl(var(--color-primary)); color: #fff; }
.enom-dialog .enom-btn--danger { background: hsl(var(--color-error)); color: #fff; }
.enom-dialog :focus-visible { outline: 2px solid hsl(var(--color-primary)); outline-offset: 2px; }

/* Help */
.enom .enom-faq { max-width: 46rem; }
.enom .enom-faq details { border-bottom: 1px solid var(--e-line); }
.enom .enom-faq summary { cursor: pointer; padding: 1rem 0; font-weight: 700; list-style: none; display: flex; justify-content: space-between; gap: 1rem; }
.enom .enom-faq summary::-webkit-details-marker { display: none; }
.enom .enom-faq summary::after { content: "+"; color: var(--e-muted); font-weight: 400; }
.enom .enom-faq details[open] summary::after { content: "−"; }
.enom .enom-faq details > div { padding-bottom: 1rem; color: color-mix(in srgb, var(--e-fg) 78%, transparent); font-size: .9375rem; line-height: 1.5rem; }
.enom .enom-faq details > div > * + * { margin-top: .5rem; }

@media (prefers-reduced-motion: reduce) {
    .enom-ui *, .enom *, .enom-dialog { transition: none !important; animation: none !important; }
}
</style>
@endonce
