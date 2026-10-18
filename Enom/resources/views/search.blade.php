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
            <p class="enom-endings" aria-label="Endings and first-year prices">
                @foreach ($endings as $ending)
                    <span><b>.{{ $ending['tld'] }}</b>@if ($ending['price']) {{ $ending['price'] }}/yr @endif</span>
                @endforeach
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
                                                <li class="enom-result {{ $index === 0 ? 'is-first' : '' }}" data-enom-result data-domain="{{ $result['domain'] }}" data-state="idle">
                            <div class="enom-result__name"><span>{{ $result['sld'] }}</span><span class="enom-tld">.{{ $result['tld'] }}</span></div>
                            <div class="enom-result__status">
                                <div data-slot="status" data-js-only></div>
                            </div>
                            <div class="enom-result__price" data-slot="price"></div>
                            <div class="enom-result__action">
                                <a data-when="available" data-slot="register" class="enom-btn enom-btn--primary" href="{{ $result['register_url'] ?? '#' }}" aria-label="Register {{ $result['domain'] }}">Register</a>
                                <a data-when="transferable" data-slot="transfer" class="enom-btn enom-btn--secondary" href="{{ $result['transfer_url'] ?? '#' }}" aria-label="Transfer {{ $result['domain'] }} to us">Transfer</a>
                                <a data-when="in_cart" data-js-only data-slot="cart" class="enom-btn enom-btn--secondary" href="{{ route('cart') }}">View cart</a>
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
                        Prices plus tax where applicable. You’ll sign in or create an account at checkout.
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
