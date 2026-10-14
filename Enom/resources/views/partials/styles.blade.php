{{-- Extension-scoped fallbacks for utilities the compiled theme does not ship (min-h-11, focus-visible outline, warning tint). Uses theme tokens only.
     overflow-wrap: anywhere lets long domain names break inside headings, links and rows, and it lowers min-content so flex items can shrink to the viewport.
     Tables and pre keep the default so cell widths, TXT values and preformatted text behave as before. --}}
<style>
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
@media (prefers-reduced-motion: reduce) { .enom-ui * { transition: none !important; } }
</style>
