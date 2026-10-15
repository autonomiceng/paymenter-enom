@component('layouts.app', ['title' => ($quote['action'] === 'transfer' ? 'Transfer ' : 'Register ').$quote['domain'], 'sidebar' => true])
@php
    $controller = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class;
    $isTransfer = $quote['action'] === 'transfer';
    $free = !empty($quote['complimentary']);
    $price = $controller::money($quote['amount'], $quote['currency']);
    $renewal = $controller::money($quote['renewal_amount'], $quote['currency']);
    $years = max(1, (int) ($quote['years'] ?? $quote['term'] ?? 1));
    $minYears = max(1, (int) ($quote['min_years'] ?? 1));
    $maxYears = max($minYears, (int) ($quote['max_years'] ?? $minYears));
    $termOptions = array_values(array_filter(array_map('intval', (array) ($quote['terms'] ?? range($minYears, $maxYears))), fn ($n) => $n >= 1 && $n <= 10)) ?: [$years];
    $termLabel = $controller::yearsLabel($years);
    // Totals for other terms, from this quote: the first year at its price, each later year at the renewal price.
    $firstMinor = (int) round((float) $quote['amount'] * 100) - (int) round((float) $quote['renewal_amount'] * 100) * ($years - 1);
    // Prefer the backend's per-term totals (computed with the client's tax rule) over re-deriving them here.
    $termTotal = fn (int $n) => $free ? null : $controller::money(isset($quote['totals'][$n]) ? (string) $quote['totals'][$n]
        : number_format(($firstMinor + (int) round((float) $quote['renewal_amount'] * 100) * ($n - 1)) / 100, 2, '.', ''), $quote['currency']);
    $expiry = $isTransfer ? null : ($quote['new_expiry_estimate'] ?? now()->addYears($years)->toDateString());
    $retry = old('_enom_form') === $formId;
    $chosenYears = $retry && is_numeric(old('years')) ? (int) old('years') : $years;
    $same = $retry ? old('same_contact', '1') === '1' : true;
    $nsMode = $retry ? (old('ns_mode') === 'custom' ? 'custom' : 'default') : 'default';
    $defaults = $quote['default_nameservers'] ?? [];
    if (!$defaults) { $nsMode = $retry && old('ns_mode') === 'custom' ? 'custom' : 'default'; }
    $roleErrors = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'roles.'));
    if ($roleErrors) { $same = false; }
    $back = route($isTransfer ? 'enom.domains.transfer' : 'enom.domains.search', ['name' => $quote['domain']]);
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $quote['test_environment'] ?? false])
    <div>
        <a class="enom-back" href="{{ $back }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />{{ $isTransfer ? 'Transfer' : 'Search' }}</a>
        <h1 class="enom-title">{{ $isTransfer ? 'Transfer' : 'Register' }} {{ $quote['domain'] }}</h1>
        <p class="enom-lede">{{ $free ? 'Complimentary' : $price.' for '.($isTransfer ? 'the transfer' : $termLabel) }}. Check the details below, then continue.</p>
    </div>
    @include('enom::partials.notices', ['fieldKeys' => ['contact', 'roles', 'epp', 'nameservers', 'registry', 'years']])

    <form method="POST" action="{{ route('enom.domains.place') }}" class="enom-order" data-enom-form>
        @csrf
        <input type="hidden" name="_request_key" value="{{ $orderKey }}">
        <input type="hidden" name="intent_id" value="{{ $quote['intent_id'] }}">
        <div class="enom-panel">
            @if ($isTransfer)
                <section class="enom-section" aria-labelledby="enom-epp-heading">
                    <div class="enom-section__head"><div><h2 id="enom-epp-heading" class="enom-h2">Transfer code</h2><p>Get this from your current registrar. It’s sometimes called an auth or EPP code.</p></div></div>
                    <div data-field style="max-width:26rem">
                        <label for="enom-epp" class="enom-label">Transfer code</label>
                        <input id="enom-epp" name="epp" type="text" class="enom-input" required maxlength="255" autocomplete="off" spellcheck="false" autocapitalize="none"
                            @if ($errors->has('epp')) aria-invalid="true" aria-describedby="enom-epp-error" @endif>
                        @if ($errors->has('epp'))<p id="enom-epp-error" class="enom-error">{{ $errors->first('epp') }}</p>@endif
                    </div>
                </section>
            @endif

            <section class="enom-section" aria-labelledby="enom-owner-heading">
                <div class="enom-section__head"><div><h2 id="enom-owner-heading" class="enom-h2">Domain owner</h2><p>The registry lists this person or organization as the owner. We’ve filled it in from your account.</p></div></div>
                @include('enom::partials.contact', ['prefix' => 'contact', 'errorPrefix' => 'contact', 'idPrefix' => 'owner', 'values' => $prefill, 'useOld' => $retry])
                <div style="margin-top:1.25rem">
                    <input type="hidden" name="same_contact" value="0">
                    <label class="enom-checkbox"><input type="checkbox" id="enom-same" name="same_contact" value="1" @checked($same)><span>Use this contact for the administrative, technical and billing contacts too</span></label>
                </div>
            </section>

            <section class="enom-section" id="enom-roles" data-enom-reveal="#enom-same" data-enom-reveal-inverse aria-labelledby="enom-roles-heading" @if ($same) data-start-hidden @endif>
                <div class="enom-section__head"><div><h2 id="enom-roles-heading" class="enom-h2">Other contacts</h2><p>Leave a contact blank to use the owner’s details.</p></div></div>
                <div class="enom-stack">
                    @foreach (['admin' => 'Administrative contact', 'tech' => 'Technical contact', 'auxbilling' => 'Billing contact'] as $role => $roleLabel)
                        @php $open = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'roles.'.$role.'.')) || ($retry && array_filter((array) old('roles.'.$role, []), fn ($v, $k) => $k !== 'country' && is_string($v) && trim($v) !== '', ARRAY_FILTER_USE_BOTH)); @endphp
                        <details class="enom-more" @if ($open) open @endif>
                            <summary>{{ $roleLabel }}</summary>
                            <div style="margin-top:.75rem">
                                @include('enom::partials.contact', ['prefix' => 'roles['.$role.']', 'errorPrefix' => 'roles.'.$role, 'idPrefix' => 'role-'.$role, 'values' => [], 'useOld' => $retry, 'required' => false, 'section' => 'section-'.$role])
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>

            @if ($registryFields)
                @include('enom::partials.registry', ['fields' => $registryFields, 'tld' => substr($quote['domain'], strpos($quote['domain'], '.') + 1), 'useOld' => $retry])
            @endif

            @if (!$isTransfer)
                <section class="enom-section" aria-labelledby="enom-ns-heading">
                    <div class="enom-section__head"><div><h2 id="enom-ns-heading" class="enom-h2">Nameservers</h2><p>Nameservers decide where your website and email are hosted. You can change them later.</p></div></div>
                    <div class="enom-choices" role="radiogroup" aria-labelledby="enom-ns-heading">
                        <label class="enom-choice">
                            <input type="radio" name="ns_mode" value="default" id="enom-ns-default" @checked($nsMode === 'default')>
                            <div>
                                <strong>Use our default nameservers</strong>
                                @if ($defaults)
                                    <span>{{ implode(', ', $defaults) }}</span>
                                @else
                                    <span>Your domain starts with the registrar’s standard nameservers.</span>
                                @endif
                            </div>
                        </label>
                        <label class="enom-choice">
                            <input type="radio" name="ns_mode" value="custom" id="enom-ns-custom" @checked($nsMode === 'custom')>
                            <div><strong>Use custom nameservers</strong><span>For example, the ones your web host gave you.</span></div>
                        </label>
                    </div>
                    <div data-enom-reveal="#enom-ns-custom" data-field style="margin-top:1rem;max-width:30rem">
                        <label for="enom-nameservers" class="enom-label">Custom nameservers</label>
                        <textarea id="enom-nameservers" name="nameservers" class="enom-input" rows="4" data-enom-nameservers spellcheck="false" autocapitalize="none"
                            placeholder="ns1.example.com&#10;ns2.example.com" aria-describedby="enom-ns-hint{{ $errors->has('nameservers') ? ' enom-ns-error' : '' }}" @if ($errors->has('nameservers')) aria-invalid="true" @endif>{{ $retry ? old('nameservers') : '' }}</textarea>
                        <p id="enom-ns-hint" class="enom-hint">2 to 12 hostnames, one per line.</p>
                        @if ($errors->has('nameservers'))<p id="enom-ns-error" class="enom-error">{{ $errors->first('nameservers') }}</p>@endif
                    </div>
                </section>
            @else
                <section class="enom-section" aria-labelledby="enom-ns-heading">
                    <h2 id="enom-ns-heading" class="enom-h2">Nameservers</h2>
                    <p class="enom-small enom-muted" style="margin-top:.25rem">Your current nameservers stay in place, so your website and email keep working during the transfer.</p>
                </section>
            @endif
        </div>

        <aside class="enom-panel enom-summary" aria-labelledby="enom-summary-heading">
            <div class="enom-section">
                <h2 id="enom-summary-heading" class="enom-small enom-muted" style="font-weight:600">Order summary</h2>
                <p class="enom-domain" style="font-size:1.125rem;margin:.25rem 0 1rem">{{ $quote['domain'] }}</p>
                @if (!$isTransfer && count($termOptions) > 1)
                    <div class="enom-termpick" data-field>
                        <label for="enom-years" class="enom-label">Registration term</label>
                        <div class="enom-termpick__row">
                            <select id="enom-years" name="years" class="enom-input" data-enom-term="{{ $years }}" @if ($errors->has('years')) aria-invalid="true" aria-describedby="enom-years-error" @endif>
                                @foreach ($termOptions as $n)
                                    <option value="{{ $n }}" @selected($n === $chosenYears)>{{ $controller::yearsLabel($n) }}@if (!$free), {{ $termTotal($n) }}@endif</option>
                                @endforeach
                            </select>
                            <button type="submit" name="requote" value="1" formnovalidate class="enom-btn enom-btn--secondary" data-enom-requote>Update</button>
                        </div>
                        @if ($errors->has('years'))<p id="enom-years-error" class="enom-error">{{ $errors->first('years') }}</p>@endif
                    </div>
                @else
                    <input type="hidden" name="years" value="{{ $years }}">
                @endif
                <dl>
                    <dt>{{ $isTransfer ? 'Transfer, adds 1 year' : 'Registration, '.$termLabel }}</dt><dd>{{ $free ? 'Complimentary' : $price }}</dd>
                    <dt>Then renews at</dt><dd>{{ $free ? 'No charge' : $renewal.'/yr' }}</dd>
                    @if ($expiry)
                        <dt>Expires about</dt><dd>@include('enom::partials.date', ['date' => $expiry])</dd>
                    @endif
                </dl>
                <dl class="enom-total"><dt>Due today</dt><dd>{{ $free ? 'Nothing' : $price }}</dd></dl>
                <div style="margin-top:1.25rem">
                    <button type="submit" class="enom-btn enom-btn--primary">{{ $free ? ($isTransfer ? 'Start transfer' : 'Register domain') : 'Continue to payment' }}</button>
                </div>
                <p class="enom-small enom-muted" style="margin-top:.75rem">
                    @if ($free)
                        This domain is complimentary on your account. Nothing to pay.
                    @elseif ($isTransfer)
                        You’ll pay on the next page. The transfer starts once payment clears.
                    @else
                        You’ll pay on the next page. Registration starts once payment clears.
                    @endif
                </p>
            </div>
        </aside>
    </form>
</div>
</div>
@include('enom::partials.client-script')
@endcomponent
