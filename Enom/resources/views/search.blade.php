@component('layouts.app', ['title' => 'Register a domain', 'sidebar' => true])
@php
    $tldNames = array_column($endings, 'tld');
    $nameValue = $sld ?? (string) old('domain', $name);
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $testEnvironment])
    <div>
        <a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>
        <div class="enom-head">
            <div>
                <h1 class="enom-title">Register a domain</h1>
                <p class="enom-lede">Type the name you want. We’ll check it across every ending we offer.</p>
            </div>
            <a class="enom-btn enom-btn--ghost" href="{{ route('enom.domains.transfer') }}">Transfer a domain instead</a>
        </div>
    </div>
    @include('enom::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])

    @if (!$endings)
        <div class="enom-notice enom-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain registration isn’t open yet. Check back soon.</div></div>
    @else
        <form method="GET" action="{{ route('enom.domains.search') }}" role="search">
            <div class="enom-searchbar">
                <label for="enom-name" class="enom-sr">Domain name</label>
                <input id="enom-name" name="name" type="text" value="{{ $nameValue }}" placeholder="yourbusiness" autocomplete="off" autocapitalize="none"
                    spellcheck="false" maxlength="253" required @if ($error || $errors->has('domain')) aria-invalid="true" aria-describedby="enom-name-error" @endif @if (!$results) autofocus @endif>
                <label for="enom-tld" class="enom-sr">Ending</label>
                <select id="enom-tld" name="tld" class="enom-input">
                    @foreach ($endings as $ending)
                        <option value="{{ $ending['tld'] }}" @selected($ending['tld'] === $preferred)>.{{ $ending['tld'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="enom-btn enom-btn--primary"><x-ri-search-line class="enom-i" aria-hidden="true" />Search</button>
            </div>
            @if ($error || $errors->has('domain'))<p id="enom-name-error" class="enom-error" role="alert">{{ $error ?? $errors->first('domain') }}</p>@endif
            @php $allFree = collect($endings)->every(fn ($e) => $e['price'] === 'Complimentary'); @endphp
            <p class="enom-endings" aria-label="{{ $allFree ? 'Endings' : 'Endings and yearly prices' }}">
                @foreach ($endings as $ending)
                    <span><b>.{{ $ending['tld'] }}</b>@if (!$allFree && $ending['price']) {{ $ending['price'] }}/yr @endif</span>
                @endforeach
                @if ($allFree)<span>Domains are complimentary on your account.</span>@endif
            </p>
        </form>

        @if ($notice)
            <div class="enom-notice enom-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>{{ $notice }} Here’s <strong>{{ $sld }}</strong> with each of them.</div></div>
        @endif

        @if ($results)
            <section aria-labelledby="enom-results-heading">
                <h2 id="enom-results-heading" class="enom-sr">Results for {{ $sld }}</h2>
                <ul class="enom-results" data-enom-results="{{ route('enom.domains.check') }}">
                    @foreach ($results as $index => $result)
                        <li class="enom-result {{ $index === 0 ? 'is-first' : '' }}" data-enom-result data-domain="{{ $result['domain'] }}" data-state="idle">
                            <div class="enom-result__name"><span>{{ $result['sld'] }}</span><span class="enom-tld">.{{ $result['tld'] }}</span></div>
                            <div class="enom-result__status">
                                <div data-slot="status" data-js-only></div>
                            </div>
                            <div class="enom-result__price" data-slot="price"></div>
                            <div class="enom-result__action">
                                <form method="POST" action="{{ route('enom.domains.quote') }}" data-when="available">
                                    @csrf
                                    <input type="hidden" name="_request_key" value="{{ $quoteKey }}">
                                    <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                    <input type="hidden" name="action" value="register">
                                    <button type="submit" class="enom-btn enom-btn--primary" aria-label="Register {{ $result['domain'] }}">Register</button>
                                </form>
                                <form method="POST" action="{{ route('enom.domains.quote') }}" data-when="transferable">
                                    @csrf
                                    <input type="hidden" name="_request_key" value="{{ $quoteKey }}">
                                    <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                    <input type="hidden" name="action" value="transfer">
                                    <button type="submit" class="enom-btn enom-btn--secondary" aria-label="Transfer {{ $result['domain'] }} to us">Transfer it here</button>
                                </form>
                                <a data-when="yours" data-js-only data-slot="yours" class="enom-btn enom-btn--secondary" href="{{ route('enom.domains.index') }}">Manage</a>
                                <div data-when="error" data-js-only class="enom-retry">
                                    <button type="button" class="enom-btn enom-btn--secondary" data-enom-retry>Retry</button>
                                    <span class="enom-small enom-muted" data-slot="retry-note"></span>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <p class="enom-small enom-muted" style="margin-top:.75rem">Prices are for one year and include any tax that applies to your account. Premium names aren’t available here.</p>
            </section>
        @endif
    @endif
</div>
</div>
@include('enom::partials.client-script')
@endcomponent
