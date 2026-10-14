{{-- Visible, selectable example. The Copy button stays hidden until the copy script runs. Examples use reserved documentation names and numbers only. --}}
<figure class="space-y-2" data-enom-example>
    <figcaption class="text-sm">{{ $caption }} Example only.</figcaption>
    <pre class="overflow-x-auto bg-background border border-neutral rounded-md p-3 text-sm" tabindex="0"><code data-enom-example-code>{{ $code }}</code></pre>
    <button type="button" data-enom-copy hidden class="flex items-center justify-center bg-background-secondary text-sm font-semibold border border-neutral hover:bg-background-secondary/80 py-2.5 px-4.5 rounded-md cursor-pointer">Copy example</button>
    {{-- Visible copy result. Present and empty on load so screen readers announce later changes. --}}
    <p data-enom-copy-result class="text-sm" role="status" aria-live="polite" aria-atomic="true"></p>
</figure>
