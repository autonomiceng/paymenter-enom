@component('layouts.app', ['title' => 'Transfer a domain', 'sidebar' => true])
@php
    $typed = strtolower(trim($name));
    $typedSld = str_contains($typed, '.') ? strstr($typed, '.', true) : $typed;
    $typedEnding = str_contains($typed, '.') ? substr(strstr($typed, '.'), 1) : null;
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $testEnvironment])
    <div>
        <a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>
        <div class="enom-head">
            <div>
                <h1 class="enom-title">Transfer a domain</h1>
                <p class="enom-lede">Move a domain you own from another registrar. A transfer adds one year to its expiry date.</p>
            </div>
            <a class="enom-btn enom-btn--ghost" href="{{ route('enom.domains.search') }}">Register a new domain instead</a>
        </div>
    </div>
    @include('enom::partials.notices', ['fieldKeys' => ['domain'], 'summary' => false])

    @if (!$endings)
        <div class="enom-notice enom-notice--warn" role="status"><x-ri-information-fill aria-hidden="true" /><div>Domain transfers aren’t open yet. Check back soon.</div></div>
    @else
        <form method="POST" action="{{ route('enom.domains.quote') }}">
            @csrf
            <input type="hidden" name="_request_key" value="{{ $quoteKey }}">
            <input type="hidden" name="action" value="transfer">
            <div class="enom-searchbar">
                <label for="enom-transfer-name" class="enom-sr">Domain name</label>
                <input id="enom-transfer-name" name="domain" type="text" value="{{ $typedSld }}" placeholder="yourbusiness" autocomplete="off" autocapitalize="none"
                    spellcheck="false" maxlength="253" required autofocus @if ($errors->has('domain')) aria-invalid="true" aria-describedby="enom-transfer-error" @endif>
                <label for="enom-transfer-tld" class="enom-sr">Ending</label>
                <select id="enom-transfer-tld" name="tld" class="enom-input">
                    @foreach ($endings as $ending)
                        <option value="{{ $ending['tld'] }}" @selected($ending['tld'] === $typedEnding)>.{{ $ending['tld'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="enom-btn enom-btn--primary">Continue</button>
            </div>
            @if ($errors->has('domain'))<p id="enom-transfer-error" class="enom-error" role="alert">{{ $errors->first('domain') }}</p>@endif
        </form>

        <div class="enom-panel">
            <section class="enom-section" aria-labelledby="enom-before-heading">
                <div class="enom-section__head"><div><h2 id="enom-before-heading" class="enom-h2">Before you start</h2><p>Do these at your current registrar.</p></div></div>
                <ol class="enom-steps2">
                    <li><span>Turn off the domain’s transfer lock (sometimes called registrar lock).</span></li>
                    <li><span>Get the transfer code, also called an auth or EPP code. You’ll enter it on the next page.</span></li>
                    <li><span>Make sure the owner email address is current. The registrar may send an approval email there.</span></li>
                </ol>
                <p class="enom-small enom-muted" style="margin-top:1rem">Domains registered or transferred in the last 60 days can’t be transferred yet. Most transfers finish within 5 to 7 days.</p>
            </section>
            @if (collect($endings)->every(fn ($e) => $e['price'] === 'Complimentary'))
            <section class="enom-section"><p class="enom-small enom-muted">Transfers are complimentary on your account. We offer {{ \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::listEndings(array_column($endings, 'tld')) }}.</p></section>
            @else
            <section class="enom-section" aria-labelledby="enom-prices-heading">
                <div class="enom-section__head"><h2 id="enom-prices-heading" class="enom-h2">Transfer prices</h2></div>
                <div class="enom-tablewrap">
                    <table class="enom-tbl">
                        <thead><tr><th scope="col">Ending</th><th scope="col" class="enom-end">Transfer, includes 1 year</th></tr></thead>
                        <tbody>
                            @foreach ($endings as $ending)
                                <tr><td class="enom-domain">.{{ $ending['tld'] }}</td><td class="enom-end enom-num">{{ $ending['price'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
            @endif
        </div>
    @endif
</div>
</div>
@include('enom::partials.client-script')
@endcomponent
