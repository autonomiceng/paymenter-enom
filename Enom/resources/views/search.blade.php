@component('layouts.app', ['title' => $guest ? 'Find a domain' : 'Register a domain', 'sidebar' => !$guest, 'description' => 'Search for a domain name and see prices for every ending we offer.'])
@php
    $tldNames = array_column($endings, 'tld');
    $nameValue = $sld ?? (string) old('domain', $name);
    $termLabel = fn (int $n) => $n.' '.($n === 1 ? 'year' : 'years');
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $testEnvironment])
    <div>
        @unless ($guest)<a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>@endunless
        <div class="enom-head">
            <div>
                <h1 class="enom-title">{{ $guest ? 'Find a domain' : 'Register a domain' }}</h1>
                <p class="enom-lede">Type the name you want. We’ll check it across every ending we offer.</p>
            </div>
            <a class="enom-btn enom-btn--ghost" href="{{ route('enom.domains.transfer') }}">Transfer a domain instead</a>
        </div>
    </div>
    @include('enom::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])
    @if ($resume)
        <div class="enom-notice" role="status"><x-ri-information-fill aria-hidden="true" /><div>Choose <strong>Register</strong> to continue with {{ $resume }}.</div></div>
    @endif

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
            <p class="enom-endings" aria-label="{{ $allFree ? 'Endings' : 'Endings and first-year prices' }}">
                @foreach ($endings as $ending)
                    <span><b>.{{ $ending['tld'] }}</b>@if (!$allFree && $ending['price']) {{ $ending['price'] }}/yr @endif</span>
                @endforeach
                @if ($allFree)<span>Domains are complimentary on your account.</span>@endif
                <a class="enom-a" href="{{ route('enom.domains.pricing') }}">All prices</a>
            </p>
        </form>

        @if ($notice)
            <div class="enom-notice enom-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>{{ $notice }} Here’s <strong>{{ $sld }}</strong> with each of them.</div></div>
        @endif

        @if ($results)
            <section aria-labelledby="enom-results-heading">
                <h2 id="enom-results-heading" class="enom-sr">Results for {{ $sld }}</h2>
                <ul class="enom-results" data-enom-results="{{ route('enom.domains.check') }}" @if ($years) data-years="{{ $years }}" @endif>
                    @foreach ($results as $index => $result)
                        @php $rowYears = $years && in_array($years, $result['terms'], true) ? $years : $result['terms'][0]; @endphp
                        <li class="enom-result {{ $index === 0 ? 'is-first' : '' }}" data-enom-result data-domain="{{ $result['domain'] }}" data-state="idle" @if ($resume === $result['domain']) data-resume @endif>
                            <div class="enom-result__name"><span>{{ $result['sld'] }}</span><span class="enom-tld">.{{ $result['tld'] }}</span></div>
                            <div class="enom-result__status">
                                <div data-slot="status" data-js-only></div>
                            </div>
                            <div class="enom-result__price" data-slot="price"></div>
                            <div class="enom-result__action">
                                {{-- Guests choose here, sign in, and come back to this row with the term kept. --}}
                                <form method="{{ $guest ? 'GET' : 'POST' }}" action="{{ $guest ? route('enom.domains.search') : route('enom.domains.quote') }}" data-when="available" class="enom-buy">
                                    @if ($guest)
                                        <input type="hidden" name="name" value="{{ $result['sld'] }}">
                                        <input type="hidden" name="tld" value="{{ $result['tld'] }}">
                                        <input type="hidden" name="go" value="1">
                                    @else
                                        @csrf
                                        <input type="hidden" name="_request_key" value="{{ $quoteKey }}">
                                        <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                        <input type="hidden" name="action" value="register">
                                    @endif
                                    <label for="enom-years-{{ $index }}" class="enom-sr">Registration term for {{ $result['domain'] }}</label>
                                    <select id="enom-years-{{ $index }}" name="years" class="enom-input enom-term" data-slot="years" @if (count($result['terms']) < 2) hidden @endif>
                                        @foreach ($result['terms'] as $term)
                                            <option value="{{ $term }}" @selected($term === $rowYears)>{{ $termLabel($term) }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="enom-btn enom-btn--primary" aria-label="Register {{ $result['domain'] }}">Register</button>
                                </form>
                                <form method="{{ $guest ? 'GET' : 'POST' }}" action="{{ route($guest ? 'enom.domains.transfer' : 'enom.domains.quote') }}" data-when="transferable">
                                    @if ($guest)
                                        <input type="hidden" name="name" value="{{ $result['domain'] }}">
                                    @else
                                        @csrf
                                        <input type="hidden" name="_request_key" value="{{ $quoteKey }}">
                                        <input type="hidden" name="domain" value="{{ $result['domain'] }}">
                                        <input type="hidden" name="action" value="transfer">
                                    @endif
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
                <p class="enom-small enom-muted" style="margin-top:.75rem">
                    @if ($guest)
                        Prices plus tax where applicable. You’ll sign in or create an account before ordering.
                    @else
                        Prices include any tax that applies to your account.
                    @endif
                    Premium names aren’t available here.
                </p>
            </section>
        @endif
    @endif
</div>
</div>
@include('enom::partials.client-script')
@endcomponent
