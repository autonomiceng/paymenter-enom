@component('layouts.app', ['title' => 'Domains', 'sidebar' => true])
@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    // Native check for the quote field, used when the page script does not run. Laravel's TrimStrings removes
    // Unicode whitespace and Str::INVISIBLE_CHARACTERS at both ends before the controller sees the value, so the
    // pattern allows them there. The core is one ASCII label of 1 to 63 characters plus .com: no leading or trailing
    // hyphen, no hyphens as third and fourth characters (this also rejects xn--). Hyphens inside classes are escaped for the v flag.
    $invisible = preg_replace_callback('/\\\\x\{([0-9A-Fa-f]+)\}/', fn ($match) => '\u{'.$match[1].'}', \Illuminate\Support\Str::INVISIBLE_CHARACTERS);
    $invisible = is_string($invisible) && preg_match('/\A(?:\\\\u\{[0-9A-Fa-f]{1,6}\})*\z/', $invisible) ? $invisible : '';
    $domainEdge = '[\p{White_Space}\u{0}'.$invisible.']*';
    $domainPattern = $domainEdge.'(?![A-Za-z0-9\-]{2}--)[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?\.[Cc][Oo][Mm]'.$domainEdge;
@endphp
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    <div class="flex flex-wrap justify-between items-center gap-4">
        <h1 class="text-2xl font-semibold">Domains</h1>
        @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => null, 'label' => 'Domain help'])
    </div>
    @include('enom::partials.messages')
    <section class="bg-background-secondary border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-order-heading">
        <h2 id="enom-order-heading" class="text-xl font-semibold">Register or transfer a domain</h2>
        <p class="text-sm">Only .com domains, one year at a time, priced in USD. Premium domains are not available. Availability can change before you pay.</p>
        <form method="POST" action="{{ route('enom.domains.quote') }}" data-enom-validate class="space-y-4">
            @include('enom::partials.validation-feedback')
            @csrf
            <input type="hidden" name="_request_key" value="{{ $searchKey }}">
            {{-- One grid cell per control, holding its own feedback, so errors never push the next control to another row. --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-start">
                <div class="min-w-0">
                    <x-form.input name="domain" :value="old('domain', '')" label="Domain name" placeholder="example.com" required maxlength="253" autocomplete="off"
                        autocapitalize="none" spellcheck="false" :pattern="$domainPattern" aria-describedby="enom-domain-hint"
                        title="Enter a .com name such as example.com. Use letters a to z, digits and hyphens, without www or http://."
                        data-enom-supported-domain="ordinary-com" data-enom-pattern-message="Enter a .com name such as example.com, using only letters a to z, digits and hyphens." />
                    <p data-enom-client-error data-enom-client-for="domain" class="text-red-500 text-xs mt-1" hidden></p>
                    <p id="enom-domain-hint" class="text-xs mt-1">Enter the full name ending in .com, such as example.com. Use letters a to z, digits 0 to 9 and hyphens. Leave out www and http://.</p>
                </div>
                <div class="min-w-0">
                    <x-form.select name="action" label="Register or transfer" required><option value="register" @selected(old('action', 'register') === 'register')>Register a new domain</option><option value="transfer" @selected(old('action') === 'transfer')>Transfer from another registrar</option></x-form.select>
                    <p data-enom-client-error data-enom-client-for="action" class="text-red-500 text-xs mt-1" hidden></p>
                </div>
            </div>
            <div class="max-w-[300px]"><x-button.primary type="submit">Check availability</x-button.primary></div>
        </form>
        @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => 'ordering', 'label' => 'How ordering and transfers work'])
    </section>
    <section class="space-y-4" aria-labelledby="enom-list-heading">
        <h2 id="enom-list-heading" class="text-xl font-semibold">Your domains</h2>
        <form method="GET" action="{{ route('enom.domains.index') }}" class="flex flex-wrap items-end gap-3">
            <div class="flex-1"><x-form.input name="search" label="Filter your domains" :value="$search" maxlength="253" /></div>
            <div class="max-w-[300px]"><x-button.secondary type="submit">Filter</x-button.secondary></div>
        </form>
        @forelse ($domains as $domain)
            <a class="block bg-background-secondary border border-neutral p-4 rounded-lg hover:bg-background-secondary/80" href="{{ route('enom.domains.show', $domain->id) }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="font-semibold">{{ $domain->domain }}</span>
                    <span class="text-sm">{{ $progress[$domain->id]['label'] ??$present::progress($domain->status, null) }}</span>
                </div>
                <p class="text-sm mt-2">Expires: {{ $present::day($domain->provider_expires_at) ?? 'Not confirmed yet' }}</p>
            </a>
        @empty
            <p class="border border-neutral p-4 rounded-lg">{{ $search !== '' ? 'No domains match this filter.' : 'You have no domains yet. Use the form above to register or transfer one.' }}</p>
        @endforelse
        {{ $domains->links() }}
    </section>
    @include('enom::partials.sandbox')
</div>
@include('enom::partials.form-validation')
@endcomponent
