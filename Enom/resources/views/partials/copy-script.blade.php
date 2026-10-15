{{-- Page-level fallback region, used only when an example has no result line of its own. --}}
<p data-enom-copy-status class="sr-only" aria-live="polite" aria-atomic="true"></p>
@verbatim
<script>
(() => {
    if (window.EnomCopyExamples) { window.EnomCopyExamples.mount(); return; }
    const bound = new WeakSet();
    const regionFor = example => example.querySelector('[data-enom-copy-result]') || document.querySelector('[data-enom-copy-status]');
    const announce = (region, message) => {
        if (!region) return;
        // Clear first so a repeated message is announced again.
        region.textContent = '';
        setTimeout(() => { region.textContent = message; }, 50);
    };
    const select = code => {
        const selection = window.getSelection?.();
        if (!selection) return false;
        const range = document.createRange();
        range.selectNodeContents(code);
        selection.removeAllRanges();
        selection.addRange(range);
        return true;
    };
    const fallback = (code, region) => {
        code.closest('pre')?.focus({preventScroll: true});
        announce(region, select(code)
            ? 'Could not copy. The example is selected. Press Ctrl+C or Command+C to copy it.'
            : 'Could not copy. Select the example text and copy it.');
    };
    const mount = () => {
        document.querySelectorAll('[data-enom-example]').forEach(example => {
            const button = example.querySelector('[data-enom-copy]');
            const code = example.querySelector('[data-enom-example-code]');
            if (!button || !code || bound.has(button)) return;
            bound.add(button);
            const region = regionFor(example);
            const label = button.textContent;
            let reset;
            button.hidden = false;
            button.addEventListener('click', () => {
                const text = code.textContent;
                if (navigator.clipboard?.writeText && window.isSecureContext) {
                    // Success is reported only after the browser confirms the write. Nothing reads the clipboard.
                    navigator.clipboard.writeText(text).then(() => {
                        clearTimeout(reset);
                        button.textContent = 'Copied';
                        reset = setTimeout(() => { button.textContent = label; }, 2000);
                        announce(region, 'Copied to the clipboard.');
                    }, () => fallback(code, region));
                } else {
                    fallback(code, region);
                }
            });
        });
    };
    window.EnomCopyExamples = {mount};
    document.addEventListener('livewire:navigated', mount);
    mount();
})();
</script>
@endverbatim
