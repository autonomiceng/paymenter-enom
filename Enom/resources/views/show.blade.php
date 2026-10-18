@component('layouts.app', ['title' => $snapshot['domain'], 'sidebar' => true])
@php
    $controller = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class;
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $s = $snapshot;
    $domainId = $s['id'];
    $name = $s['domain'];
    $cached = $s['cached'];
    $capabilities = $s['capabilities'] ?? [];
    $can = fn (string $action) => ($capabilities[$action] ?? false) === true;
    $status = $s['status'] ?? null;
    $progress = is_array($s['progress'] ?? null) ? $s['progress'] : null;
    $pending = in_array($status, ['registration_pending', 'transfer_pending'], true);
    $cancelled = $status === 'cancelled';
    $initial = collect($s['operations'] ?? [])->first(fn ($op) => in_array($op['action'] ?? null, ['register', 'transfer'], true));
    $codeTimedOut = ($initial['outcome'] ?? null) === 'code_timeout';
    $failed = in_array($status, ['registration_rejected', 'transfer_rejected'], true);
    $isTransfer = ($initial['action'] ?? null) === 'transfer' || in_array($status, ['transfer_pending', 'transfer_rejected'], true);
    $paused = !empty($s['writes_blocked']);
    $renewOn = ($s['renewal_enabled'] ?? true) === true;
    $price = is_array($s['price'] ?? null) ? $s['price'] : [];
    $free = ($price['mode'] ?? null) === 'complimentary';
    $amount = $controller::money($price['amount'] ?? null, $price['currency'] ?? 'USD');
    $automation = $s['automation'] ?? [];
    $calendar = $automation['calendar'] ?? [];
    $expires = $s['provider_expires_at'] ?? null;
    $checkedAt = $present::latest($s['last_synced_at'] ?? null, $automation['observed_at'] ?? null);
    $defaults = array_values($s['default_nameservers'] ?? []);
    $current = array_values($cached['nameservers'] ?? []);
    $norm = fn (array $list) => collect($list)->map(fn ($h) => rtrim(strtolower($h), '.'))->sort()->values()->all();
    $usingDefault = $defaults && $current && $norm($defaults) === $norm($current);
    $locked = $cached['locked'] ?? null;
    $privacy = $cached['privacy'] ?? [];
    $registrant = $cached['contacts']['registrant'] ?? [];
    $invoiceId = ($progress['invoice_id'] ?? null) ?: ($s['pending_invoice_id'] ?? null);
    if ($codeTimedOut) { $progress = array_replace($progress ?? [], ['safe_code' => 'transfer_code_timeout']); }
    [$badgeLabel] = $controller::badge($status, $progress);
    $manageable = in_array($status, ['active', 'expired', 'grace', 'redemption', 'needs_review'], true);
    $showPrivacy = $privacy['exists'] === true || $privacy['allowed'] === true || $can('privacy_purchase') || $can('privacy_renew');
    $dnsTools = !empty($s['dns_tools']);
    $eppAt = $s['epp_email_available_at'] ?? null;
    $eppWaiting = $eppAt && \Carbon\CarbonImmutable::parse($eppAt)->isFuture();
    $retryFor = fn (string $action) => old('_enom_form') === 'domain:'.$domainId.':'.$action;
    $tabs = ['overview' => 'Overview', 'nameservers' => 'Nameservers', 'contacts' => 'Contacts', 'transfer' => 'Transfer'];
    if ($showPrivacy) { $tabs['privacy'] = 'ID Protect'; }
    if ($dnsTools) { $tabs['dns'] = 'DNS'; }
    $tabs['activity'] = 'Activity';
    // Enom keeps only the roles a registry collects (for .com, just the registrant).
    $heldRoles = is_array($s['contact_roles'] ?? null) && $s['contact_roles'] ? $s['contact_roles'] : ['registrant', 'admin', 'tech', 'auxbilling'];
    $roles = array_intersect_key(['admin' => 'Administrative', 'tech' => 'Technical', 'auxbilling' => 'Billing'], array_flip($heldRoles));
    $registrantOnly = !$roles;
    $glue = collect(is_array($s['glue'] ?? null) ? $s['glue'] : ($cached['glue'] ?? []))
        ->filter(fn ($g) => is_array($g) && is_string($g['host'] ?? null))->values();
    $glueErrors = collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'glue'))->flatten()->unique();
    $renewOptions = $renewal['options'] ?? [];
    $openRenewal = $renewal['open_invoice'] ?? null;
    $credits = $creditsUrl ?? null;
@endphp
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    @include('enom::partials.test-banner', ['test' => $s['test_environment'] ?? false])

    <div>
        <a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>
        <div class="enom-dhead">
            <div style="min-width:0">
                <h1 class="enom-title">{{ $name }}</h1>
                <div class="enom-dhead__meta">
                    @include('enom::partials.badge', ['status' => $status, 'progress' => $progress])
                    @if ($expires)<span>{{ in_array($status, ['expired', 'grace', 'redemption'], true) ? 'Expired' : 'Expires' }} <strong>@include('enom::partials.date', ['date' => $expires])</strong></span>@endif
                    @if (!$pending && !$failed && !$cancelled)
                        <form method="POST" action="{{ route('enom.domains.refresh', $domainId) }}" class="enom-row" style="gap:.25rem">
                            @include('enom::partials.form-key', ['action' => 'refresh'])
                            <span>Updated @include('enom::partials.moment', ['at' => $checkedAt, 'style' => 'relative', 'fallback' => 'never'])</span>
                            <button type="submit" class="enom-btn enom-btn--ghost enom-btn--sm"><x-ri-refresh-line class="enom-i" aria-hidden="true" />Refresh</button>
                        </form>
                    @endif
                </div>
            </div>
            @if ($manageable)
                <form method="POST" action="{{ route('enom.domains.preference', $domainId) }}"
                    @if ($renewOn) data-confirm-title="Turn off auto-renew?" data-confirm-ok="Turn off auto-renew" data-confirm-tone="danger"
                        data-confirm-body="{{ $name }} will expire on {{ $expires ? \Carbon\CarbonImmutable::parse($expires)->format('M j, Y') : 'its expiry date' }} unless you renew it. Unpaid renewal invoices for it will be cancelled." @endif>
                    @include('enom::partials.form-key', ['action' => 'renewal_preference'])
                    <input type="hidden" name="enabled" value="{{ $renewOn ? '0' : '1' }}">
                    <button type="submit" class="enom-switch" role="switch" aria-checked="{{ $renewOn ? 'true' : 'false' }}">
                        <span class="enom-switch__track" aria-hidden="true"></span>Auto-renew
                    </button>
                </form>
            @endif
        </div>
    </div>

    @include('enom::partials.notices', ['fieldKeys' => ['contact', 'nameservers', 'records', 'ds', 'host', 'ip', 'glue']])

    @if ($paused && !$pending)
        <div class="enom-notice enom-notice--warn" role="status"><x-ri-time-line aria-hidden="true" />
            <div>
                @if (!empty($s['needs_review']))
                    <strong>Editing is paused.</strong> We’re checking a recent change with the registrar. You can edit this domain again once that’s done.
                    @if ($supportUrl) <a class="enom-a" href="{{ $supportUrl }}">Contact support</a>@endif
                @else
                    <strong>A change is in progress.</strong> You can make more changes once it finishes. Refresh to check.
                @endif
            </div>
        </div>
    @endif
    @if (in_array($status, ['expired', 'grace'], true))
        <div class="enom-notice enom-notice--bad" role="status"><x-ri-error-warning-fill aria-hidden="true" /><div><strong>This domain has expired.</strong> {{ $can('renew') ? 'Renew it now to keep it.' : 'Contact support to renew it.' }}</div></div>
    @elseif ($status === 'redemption')
        <div class="enom-notice enom-notice--bad" role="status"><x-ri-error-warning-fill aria-hidden="true" /><div><strong>This domain is in redemption.</strong> Contact support to restore it before it’s released.</div></div>
    @endif

    @include('enom::partials.registration-setup', ['domain' => $domain, 'part' => 'notice'])
    @if ($cancelled || $pending || $failed)
        {{-- A registration or transfer that isn't live yet: one focused status card instead of disabled settings. --}}
        @php
            $kind = $isTransfer ? 'transfer' : 'registration';
            $awaiting = $badgeLabel === 'Awaiting payment';
            $delayed = $badgeLabel === 'Delayed';
            $codeReceived = $isTransfer && $initial && !app(\Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class)->needsCode(\Paymenter\Extensions\Servers\Enom\Models\Operation::find($initial['id']));
            $steps = $isTransfer ? ['Ordered' => true, 'Paid' => !$awaiting, 'Code' => $codeReceived, 'Transferred' => false]
                : ['Ordered' => true, 'Paid' => !$awaiting, 'Registered' => false];
            $refund = is_array($initial['refund'] ?? null) ? $initial['refund'] : null;
            $refunded = $refund ? $controller::money($refund['amount'] ?? null, $refund['currency'] ?? 'USD') : null;
            $retryUrl = $isTransfer ? route('enom.domains.transfer', ['name' => $name]) : route('enom.domains.search', ['name' => $name]);
            $creditButton = $credits ? '<a class="enom-btn enom-btn--primary" href="'.e($credits).'">View account credit</a>' : '';
        @endphp
        <section class="enom-panel enom-status-card" aria-labelledby="enom-status-heading">
            @if ($cancelled)
                <h2 id="enom-status-heading">Order cancelled</h2>
                <p>The invoice wasn’t paid, so this order was cancelled. Nothing was charged.</p>
                <div style="margin-top:1.25rem"><a class="enom-btn enom-btn--primary" href="{{ $retryUrl }}">{{ $isTransfer ? 'Try again' : 'Search again' }}</a></div>
            @elseif ($codeTimedOut)
                @php $deadline = \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline(\Paymenter\Extensions\Servers\Enom\Models\Operation::find($initial['id'])); @endphp
                <h2 id="enom-status-heading">Transfer cancelled</h2>
                @if ($refunded)
                    <p>We didn’t receive the transfer code by {{ $deadline ? $deadline->format('M j, Y') : 'the deadline' }}, so we cancelled the transfer and added {{ $refunded }} to your account credit.</p>
                @else
                    <p>We didn’t receive the transfer code in time, so we cancelled the transfer. The refund to your account credit is on its way.</p>
                @endif
                <div class="enom-row" style="margin-top:1.25rem">{!! $refunded ? $creditButton : '' !!}<a class="enom-btn enom-btn--secondary" href="{{ $retryUrl }}">Try again</a></div>
            @elseif ($failed)
                <h2 id="enom-status-heading">The {{ $kind }} didn’t go through</h2>
                @if ($refunded)
                    <p>{{ $isTransfer ? 'Your current registrar refused the transfer' : 'The registry didn’t accept this registration' }}, so we added {{ $refunded }} to your account credit.
                        {{ $isTransfer ? 'Common reasons: the domain is locked, the code was wrong, or the owner declined.' : 'It’s used automatically on your next invoice.' }}</p>
                @elseif ($free || ($initial && empty($initial['invoice_id'])))
                    <p>{{ $isTransfer ? 'Your current registrar refused the transfer.' : 'The registry didn’t accept this registration.' }} Nothing was charged.</p>
                @else
                    <p>{{ $isTransfer ? 'Your current registrar refused the transfer.' : 'The registry didn’t accept this registration.' }} Contact support and we’ll sort out your payment.</p>
                @endif
                <div class="enom-row" style="margin-top:1.25rem">
                    @if ($refunded || $free || ($initial && empty($initial['invoice_id'])))
                        {!! $refunded ? $creditButton : '' !!}
                        @if ($isTransfer)
                            <a class="enom-btn enom-btn--secondary" href="{{ $retryUrl }}">Try again</a>
                        @else
                            <a class="enom-btn enom-btn--secondary" href="{{ route('enom.domains.search') }}">Search for another name</a>
                        @endif
                    @elseif ($supportUrl)
                        <a class="enom-btn enom-btn--primary" href="{{ $supportUrl }}">Contact support</a>
                    @endif
                </div>
            @elseif ($awaiting && $isTransfer)
                <h2 id="enom-status-heading">{{ $codeReceived ? 'Pay the invoice to start the transfer' : 'Pay the invoice and enter the code to start the transfer' }}</h2>
                @if (!$renewOn)<p>Auto-renew is off, so contact support before paying if you still want this domain.</p>@endif
                @include('enom::partials.transfer-code', ['domain' => $domain])
            @elseif ($awaiting)
                <h2 id="enom-status-heading">Pay the invoice to register {{ $name }}</h2>
                <p>Registration starts as soon as the payment clears.@if (!$renewOn) Auto-renew is off, so contact support before paying if you still want this domain.@endif</p>
                @if ($invoiceId)<div style="margin-top:1.25rem"><a class="enom-btn enom-btn--primary" href="{{ route('invoices.show', $invoiceId) }}">Pay invoice</a></div>@endif
            @elseif ($delayed)
                <h2 id="enom-status-heading">This {{ $kind }} is taking longer than usual</h2>
                <p>We’re checking it and will update this page. You don’t need to do anything.</p>
                @if ($supportUrl)<div style="margin-top:1.25rem"><a class="enom-btn enom-btn--secondary" href="{{ $supportUrl }}">Contact support</a></div>@endif
            @elseif ($badgeLabel === 'Needs your code')
                <h2 id="enom-status-heading">Enter your transfer code</h2>
                @include('enom::partials.transfer-code', ['domain' => $domain])
            @elseif ($badgeLabel === 'Needs your details')
                <h2 id="enom-status-heading">We need a few details to {{ $isTransfer ? 'transfer' : 'register' }} {{ $name }}</h2>
                @include('enom::partials.registration-setup', ['domain' => $domain, 'part' => 'hold'])
            @elseif ($isTransfer)
                <h2 id="enom-status-heading">Transfer in progress</h2>
                <p>Your current registrar may email the domain owner to approve the transfer. Approving it there can speed things up. Most transfers finish within 5 to 7 days. Your website and email keep working.</p>
            @else
                <h2 id="enom-status-heading">Registering {{ $name }}</h2>
                <p>This usually takes a few minutes. Refresh this page to see when it’s ready.</p>
                <div style="margin-top:1.25rem"><a class="enom-btn enom-btn--secondary" href="{{ route('enom.domains.show', $domainId) }}"><x-ri-refresh-line class="enom-i" aria-hidden="true" />Refresh</a></div>
            @endif
            @if ($pending)
                @php
                    $done = array_keys(array_filter($steps));
                    $next = array_key_first(array_filter($steps, fn ($d) => !$d));
                    $stepText = match ($next) {
                        'Paid' => 'waiting for payment', 'Code' => 'waiting for the transfer code',
                        'Transferred' => 'waiting for the transfer to finish', default => 'waiting for the registration to finish',
                    };
                @endphp
                <div class="enom-progress" aria-hidden="true">@foreach ($steps as $isDone)<span @class(['is-done' => $isDone])></span>@endforeach</div>
                <div class="enom-progress-labels" aria-hidden="true">@foreach ($steps as $label => $isDone)<span>{{ $label }}</span>@endforeach</div>
                <p class="enom-sr">Step {{ count($done) + 1 }} of {{ count($steps) }}: {{ $stepText }}.</p>
            @endif
        </section>
        @if (count($s['operations'] ?? []))
            @include('enom::partials.activity', ['operations' => $s['operations']])
        @endif
    @else
        <nav class="enom-tabs" data-enom-tabs aria-label="Domain settings">
            @foreach ($tabs as $tabKey => $tabLabel)
                <a href="#{{ $tabKey }}" id="tab-{{ $tabKey }}" class="enom-tab" data-enom-tab="{{ $tabKey }}">{{ $tabLabel }}
                    @if ($tabKey === 'contacts' && ($cached['contact_status']['pending_verification'] ?? null) === true)<span class="enom-dot" title="Needs verification"></span>@endif
                </a>
            @endforeach
        </nav>

        {{-- Overview --}}
        <div class="enom-panel enom-tabpanel" id="overview">
            <dl class="enom-facts">
                <div class="enom-fact"><dt>Expires</dt><dd>@include('enom::partials.date', ['date' => $expires, 'fallback' => 'Not confirmed yet'])</dd></div>
                <div class="enom-fact"><dt>Auto-renew</dt><dd>{{ $renewOn ? 'On' : 'Off' }}
                    <small>
                        @if (!$renewOn)
                            The domain expires unless you turn this on.
                        @elseif ($free)
                            Renews at no cost.
                        @elseif (!empty($calendar['issue_at']))
                            @if (\Carbon\CarbonImmutable::parse($calendar['issue_at'])->isFuture())
                                We’ll invoice you on @include('enom::partials.date', ['date' => $calendar['issue_at']]), due @include('enom::partials.date', ['date' => $calendar['due_at']]).
                            @else
                                Invoiced @include('enom::partials.date', ['date' => $calendar['issue_at']]), due @include('enom::partials.date', ['date' => $calendar['due_at']]).
                            @endif
                        @else
                            We’ll invoice you before it expires.
                        @endif
                    </small></dd></div>
                <div class="enom-fact"><dt>Renewal price</dt><dd>{{ $free ? 'Complimentary' : ($amount && !($s['zero_retail'] ?? false) ? $amount.'/yr' : 'Not set — contact support') }}</dd></div>
                <div class="enom-fact"><dt>Transfer lock</dt><dd>{{ $locked === null ? 'Unknown' : ($locked ? 'On' : 'Off') }}</dd></div>
                @if ($showPrivacy)<div class="enom-fact"><dt>ID Protect</dt><dd>{{ $privacy['enabled'] === true ? 'On' : 'Off' }}</dd></div>@endif
                <div class="enom-fact"><dt>Nameservers</dt><dd>{{ !$current ? 'Unknown' : ($usingDefault ? 'Default' : 'Custom') }}<small>{{ implode(', ', array_slice($current, 0, 2)) }}{{ count($current) > 2 ? ' +'.(count($current) - 2) : '' }}</small></dd></div>
            </dl>
            @if ($renewOn && !$free && $can('renew'))
                <section class="enom-section" aria-labelledby="enom-renew-heading" style="border-top:0">
                    <div class="enom-section__head" style="margin-bottom:0;align-items:center">
                        <div><h2 id="enom-renew-heading" class="enom-h2">Renew now</h2>
                            <p>@if ($openRenewal)Your renewal invoice for {{ $controller::yearsLabel((int) $openRenewal['years']) }} is ready. Pay it and the domain renews.@elseif ($renewOptions)Add years now instead of waiting for the renewal invoice.@else This domain is already renewed as far ahead as the registry allows, 10 years from today.@endif</p></div>
                        @if ($openRenewal)<a class="enom-btn enom-btn--primary" href="{{ $openRenewal['url'] }}">Pay invoice</a>@endif
                        @if ($renewOptions)
                            @php $confirmFor = fn ($o) => 'We’ll create an invoice for '.$o['total'].'. '.$name.' renews for '.$controller::yearsLabel($o['years']).' once it’s paid.'; @endphp
                            <form method="POST" action="{{ route('enom.domains.renew', $domainId) }}" class="enom-renew" data-confirm-title="Create a renewal invoice?" data-confirm-ok="Create invoice"
                                data-confirm-body="{{ $confirmFor($renewOptions[0]) }}">
                                @include('enom::partials.form-key', ['action' => 'renew'])
                                @if (count($renewOptions) > 1)
                                    <label for="enom-renew-years" class="enom-sr">Renewal term</label>
                                    <select id="enom-renew-years" name="years" class="enom-input enom-term">
                                        @foreach ($renewOptions as $option)
                                            <option value="{{ $option['years'] }}" data-confirm-body="{{ $confirmFor($option) }}">{{ $controller::yearsLabel($option['years']) }}, {{ $option['total'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="enom-btn enom-btn--primary">Renew</button>
                                @else
                                    <input type="hidden" name="years" value="{{ $renewOptions[0]['years'] }}">
                                    <button type="submit" class="enom-btn enom-btn--primary">Renew for {{ $controller::yearsLabel($renewOptions[0]['years']) }}, {{ $renewOptions[0]['total'] }}</button>
                                @endif
                            </form>
                        @endif
                    </div>
                </section>
            @endif
            @if (!empty($automation['invoices']))
                <section class="enom-section" aria-labelledby="enom-invoices-heading">
                    <h2 id="enom-invoices-heading" class="enom-h2" style="margin-bottom:.5rem">Renewal invoices</h2>
                    <div class="enom-tablewrap">
                        <table class="enom-tbl">
                            <thead><tr><th scope="col">Invoice</th><th scope="col">Status</th><th scope="col">Due</th></tr></thead>
                            <tbody>
                                @foreach (array_slice($automation['invoices'], 0, 5) as $invoice)
                                    @php [$invLabel, $invTone] = match ($invoice['status'] ?? null) { 'paid' => ['Paid', 'ok'], 'pending' => ['Unpaid', 'warn'], 'cancelled' => ['Cancelled', 'muted'], default => ['Unknown', 'muted'] }; @endphp
                                    <tr>
                                        <td><a class="enom-a" href="{{ route('invoices.show', $invoice['id']) }}">#{{ $invoice['id'] }}</a></td>
                                        <td><span class="enom-badge enom-tone-{{ $invTone }}">{{ $invLabel }}</span></td>
                                        <td class="enom-num">@include('enom::partials.date', ['date' => $invoice['due_at'] ?? null, 'fallback' => '—'])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        {{-- Nameservers --}}
        <div class="enom-panel enom-tabpanel" id="nameservers">
            <section class="enom-section" aria-labelledby="enom-ns-heading">
                <div class="enom-section__head">
                    <div><h2 id="enom-ns-heading" class="enom-h2">Nameservers</h2><p>These decide where your website and email are hosted. Changes can take up to 48 hours to reach everyone.</p></div>
                    @if ($usingDefault)<span class="enom-badge enom-tone-info">Using defaults</span>@endif
                </div>
                @if ($current)
                    <ul class="enom-hosts">@foreach ($current as $host)<li>{{ $host }}</li>@endforeach</ul>
                @else
                    <p class="enom-muted enom-small">No nameservers on record. Refresh to load them.</p>
                @endif
                @if ($can('nameservers') && $defaults && !$usingDefault)
                    <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'nameservers']) }}" style="margin-top:1.25rem"
                        data-confirm-title="Switch to the default nameservers?" data-confirm-ok="Use defaults"
                        data-confirm-body="Your nameservers will change to {{ implode(', ', $defaults) }}. If your website or email is hosted elsewhere, it may stop working.">
                        @include('enom::partials.form-key', ['action' => 'nameservers'])
                        <input type="hidden" name="use_default" value="1">
                        <button type="submit" class="enom-btn enom-btn--secondary">Use default nameservers</button>
                        <p class="enom-hint">{{ implode(', ', $defaults) }}</p>
                    </form>
                @endif
            </section>
            @if ($can('nameservers'))
                <section class="enom-section" aria-labelledby="enom-ns-edit-heading">
                    <h2 id="enom-ns-edit-heading" class="enom-h2" style="margin-bottom:.75rem">Use custom nameservers</h2>
                    <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'nameservers']) }}" data-enom-form style="max-width:30rem">
                        @include('enom::partials.form-key', ['action' => 'nameservers'])
                        <div data-field>
                            <label for="enom-ns" class="enom-label">Nameservers, one per line</label>
                            <textarea id="enom-ns" name="nameservers" rows="4" class="enom-input" data-enom-nameservers spellcheck="false" autocapitalize="none"
                                aria-describedby="enom-ns-hint{{ $retryFor('nameservers') && $errors->has('nameservers') ? ' enom-ns-error' : '' }}" @if ($retryFor('nameservers') && $errors->has('nameservers')) aria-invalid="true" @endif>{{ $retryFor('nameservers') ? old('nameservers') : implode("\n", $current) }}</textarea>
                            <p id="enom-ns-hint" class="enom-hint">2 to 12 hostnames, such as ns1.example.com.</p>
                            @if ($retryFor('nameservers') && $errors->has('nameservers'))<p id="enom-ns-error" class="enom-error">{{ $errors->first('nameservers') }}</p>@endif
                        </div>
                        <div style="margin-top:1rem"><button type="submit" class="enom-btn enom-btn--primary">Save nameservers</button></div>
                    </form>
                </section>
            @elseif (!$paused)
                <section class="enom-section"><p class="enom-small enom-muted">Nameservers can’t be changed right now. Refresh and try again.</p></section>
            @endif
            @include('enom::partials.glue')
        </div>

        {{-- Contacts --}}
        <div class="enom-panel enom-tabpanel" id="contacts">
            <section class="enom-section" aria-labelledby="enom-contacts-heading">
                <div class="enom-section__head"><div><h2 id="enom-contacts-heading" class="enom-h2">{{ $registrantOnly ? 'Registrant contact' : 'Domain owner' }}</h2><p>{{ $registrantOnly ? 'The registry lists this person or organization as the domain’s owner. It’s the only contact this domain needs.' : 'The registry lists this person or organization as the owner.' }}</p></div></div>
                <div class="enom-stack">
                    @if (($cached['contact_status']['pending_verification'] ?? null) === true)
                        <div class="enom-notice enom-notice--warn"><x-ri-mail-line aria-hidden="true" /><div><strong>Verify the owner’s email.</strong> Open the verification email sent to {{ $s['registrant_email_masked'] ?? 'the owner’s address' }} and follow its link, or the domain may be suspended.</div></div>
                    @endif
                    @if (($cached['contact_status']['transfer_locked'] ?? null) === true)
                        <div class="enom-notice"><x-ri-lock-line aria-hidden="true" /><div>The owner changed recently, so this domain can’t move to another registrar for 60 days.</div></div>
                    @endif
                    @if ($can('contacts'))
                        @php
                            $ownerRetry = $retryFor('contacts') && in_array(old('contact_type'), ['registrant', ''], true);
                            $applyAll = $ownerRetry ? old('apply_all') === '1' : ($s['contacts_match'] ?? true);
                        @endphp
                        <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'contacts']) }}" data-enom-form
                            data-confirm-title="Change the domain owner?" data-confirm-ok="Save changes"
                            data-confirm-when-changed="contact[first_name],contact[last_name],contact[organization_name],contact[email_address]"
                            data-confirm-body="Changing the owner’s name, organization or email may need email verification, and blocks transfers to another registrar for 60 days.">
                            @include('enom::partials.form-key', ['action' => 'contacts'])
                            <input type="hidden" name="contact_type" value="registrant">
                            @include('enom::partials.contact', ['prefix' => 'contact', 'errorPrefix' => 'contact', 'idPrefix' => 'owner', 'values' => $registrant, 'useOld' => $ownerRetry])
                            @if ($registrantOnly)
                                <input type="hidden" name="apply_all" value="1">
                            @else
                                <input type="hidden" name="apply_all" value="0">
                                <label class="enom-checkbox" style="margin-top:1.25rem"><input type="checkbox" name="apply_all" value="1" @checked($applyAll)><span>Use this contact for the administrative, technical and billing contacts too</span></label>
                            @endif
                            <div style="margin-top:1rem"><button type="submit" class="enom-btn enom-btn--primary">Save contact</button></div>
                        </form>
                    @else
                        <dl class="enom-small" style="display:grid;gap:.25rem">
                            <dt class="enom-sr">Owner</dt>
                            <dd><strong>{{ trim(($registrant['first_name'] ?? '').' '.($registrant['last_name'] ?? '')) ?: 'Not loaded yet' }}</strong>{{ !empty($registrant['organization_name']) ? ', '.$registrant['organization_name'] : '' }}</dd>
                            @if (!empty($registrant['email_address']))<dd class="enom-muted">{{ $registrant['email_address'] }}</dd>@endif
                            @if (!$paused)<dd class="enom-muted">Contacts can’t be changed right now. Refresh and try again.</dd>@endif
                        </dl>
                    @endif
                </div>
            </section>
            @if ($can('contacts') && !$registrantOnly)
                <section class="enom-section" aria-labelledby="enom-roles-heading">
                    <div class="enom-section__head"><div><h2 id="enom-roles-heading" class="enom-h2">Other contacts</h2>
                        <p>{{ ($s['contacts_match'] ?? true) ? 'All contacts currently match the owner.' : 'Some contacts differ from the owner.' }} Edit one here to change only that contact.</p></div></div>
                    <div class="enom-stack-sm">
                        @foreach ($roles as $role => $roleLabel)
                            @php
                                $roleContact = $cached['contacts'][$role] ?? [];
                                $roleRetry = $retryFor('contacts') && old('contact_type') === $role;
                                $preview = trim(($roleContact['first_name'] ?? '').' '.($roleContact['last_name'] ?? ''));
                            @endphp
                            <details class="enom-more" @if ($roleRetry) open @endif>
                                <summary>{{ $roleLabel }}@if ($preview) <span class="enom-muted" style="font-weight:400">· {{ $preview }}</span>@endif</summary>
                                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'contacts']) }}" data-enom-form style="margin-top:.75rem">
                                    @include('enom::partials.form-key', ['action' => 'contacts'])
                                    <input type="hidden" name="contact_type" value="{{ $role }}">
                                    @include('enom::partials.contact', ['prefix' => 'contact', 'errorPrefix' => $roleRetry ? 'contact' : 'contact-'.$role, 'idPrefix' => 'c-'.$role, 'values' => $roleContact, 'useOld' => $roleRetry, 'section' => 'section-'.$role])
                                    <div style="margin-top:1rem"><button type="submit" class="enom-btn enom-btn--secondary">Save {{ strtolower($roleLabel) }} contact</button></div>
                                </form>
                            </details>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        {{-- Transfer --}}
        <div class="enom-panel enom-tabpanel" id="transfer">
            <section class="enom-section" aria-labelledby="enom-lock-heading">
                <div class="enom-section__head" style="align-items:center">
                    <div><h2 id="enom-lock-heading" class="enom-h2">Transfer lock</h2><p>Stops anyone moving this domain to another registrar. Leave it on unless you’re moving the domain.</p></div>
                    @if ($can('lock') && $locked !== null)
                        <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'lock']) }}"
                            @if ($locked) data-confirm-title="Turn off the transfer lock?" data-confirm-ok="Turn off lock" data-confirm-tone="danger"
                                data-confirm-body="Anyone with the transfer code could then move {{ $name }} to another registrar. Turn the lock back on if you’re not transferring." @endif>
                            @include('enom::partials.form-key', ['action' => 'lock'])
                            <input type="hidden" name="locked" value="{{ $locked ? '0' : '1' }}">
                            <button type="submit" class="enom-switch" role="switch" aria-checked="{{ $locked ? 'true' : 'false' }}"><span class="enom-switch__track" aria-hidden="true"></span>{{ $locked ? 'On' : 'Off' }}</button>
                        </form>
                    @else
                        <strong>{{ $locked === null ? 'Unknown' : ($locked ? 'On' : 'Off') }}</strong>
                    @endif
                </div>
            </section>
            <section class="enom-section" aria-labelledby="enom-code-heading">
                <div class="enom-section__head" style="align-items:center">
                    <div><h2 id="enom-code-heading" class="enom-h2">Transfer code</h2>
                        <p>Your new registrar asks for this code. We email it to the domain owner{{ !empty($s['registrant_email_masked']) ? ' at '.$s['registrant_email_masked'] : '' }}.</p></div>
                    @if ($can('epp_email'))
                        @if ($eppWaiting)
                            <button type="button" class="enom-btn enom-btn--secondary" disabled>Email transfer code</button>
                        @else
                            <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'epp_email']) }}">
                                @include('enom::partials.form-key', ['action' => 'epp_email'])
                                <button type="submit" class="enom-btn enom-btn--secondary"><x-ri-mail-send-line class="enom-i" aria-hidden="true" />Email transfer code</button>
                            </form>
                        @endif
                    @endif
                </div>
                @if ($can('epp_email') && $eppWaiting)
                    <p class="enom-small enom-muted">Transfer code requested. You can ask again after @include('enom::partials.moment', ['at' => $eppAt]).</p>
                @elseif (!$can('epp_email') && !$paused)
                    <p class="enom-small enom-muted">Transfer codes can’t be requested right now. Refresh and try again.</p>
                @endif
            </section>
            <section class="enom-section" aria-labelledby="enom-out-heading">
                <h2 id="enom-out-heading" class="enom-h2" style="margin-bottom:.75rem">Moving to another registrar</h2>
                <ol class="enom-steps2">
                    <li><span>Turn off the transfer lock above.</span></li>
                    <li><span>Email yourself the transfer code.</span></li>
                    <li><span>Start the transfer at your new registrar and enter the code.</span></li>
                </ol>
                <p class="enom-small enom-muted" style="margin-top:1rem">Domains can’t move within 60 days of registration, a previous transfer or an owner change.</p>
            </section>
        </div>

        {{-- ID Protect --}}
        @if ($showPrivacy)
            <div class="enom-panel enom-tabpanel" id="privacy">
                <section class="enom-section" aria-labelledby="enom-privacy-heading">
                    <div class="enom-section__head" style="align-items:center">
                        <div><h2 id="enom-privacy-heading" class="enom-h2">ID Protect</h2><p>Hides the owner’s name, address, phone and email from public WHOIS lookups.</p></div>
                        @php $toggle = $privacy['exists'] === true && $privacy['enabled'] !== null ? ($privacy['enabled'] ? 'privacy_disable' : 'privacy_enable') : null; @endphp
                        @if ($toggle && $can($toggle))
                            <form method="POST" action="{{ route('enom.domains.manage', [$domainId, $toggle]) }}"
                                @if ($toggle === 'privacy_disable') data-confirm-title="Turn off ID Protect?" data-confirm-ok="Turn off" data-confirm-tone="danger"
                                    data-confirm-body="The owner’s name, address, phone and email will be visible in public WHOIS lookups. Your subscription stays active." @endif>
                                @include('enom::partials.form-key', ['action' => $toggle])
                                <button type="submit" class="enom-switch" role="switch" aria-checked="{{ $privacy['enabled'] ? 'true' : 'false' }}"><span class="enom-switch__track" aria-hidden="true"></span>{{ $privacy['enabled'] ? 'On' : 'Off' }}</button>
                            </form>
                        @else
                            <strong>{{ $privacy['enabled'] === true ? 'On' : ($privacy['exists'] === true ? 'Off' : 'Not added') }}</strong>
                        @endif
                    </div>
                    @foreach (['purchase' => 'Add ID Protect', 'renew' => 'Renew ID Protect'] as $privacyAction => $privacyLabel)
                        @if ($can('privacy_'.$privacyAction) && $privacyPrice)
                            <form method="POST" action="{{ route('enom.domains.privacy', [$domainId, $privacyAction]) }}" style="margin-top:.5rem"
                                data-confirm-title="{{ $privacyLabel }} for {{ $privacyPrice }}?" data-confirm-ok="Create invoice"
                                data-confirm-body="We’ll create an invoice for one year. ID Protect {{ $privacyAction === 'purchase' ? 'turns on' : 'renews' }} once it’s paid.">
                                @include('enom::partials.form-key', ['action' => 'privacy_'.$privacyAction])
                                <button type="submit" class="enom-btn enom-btn--primary">{{ $privacyLabel }} · {{ $privacyPrice }}/yr</button>
                            </form>
                        @endif
                    @endforeach
                    @if ($privacy['exists'] === true)<p class="enom-small enom-muted" style="margin-top:1rem">ID Protect renews separately from the domain, one year at a time.</p>@endif
                </section>
            </div>
        @endif

        {{-- DNS (only when the server offers Enom DNS) --}}
        @if ($dnsTools)
            <div class="enom-panel enom-tabpanel" id="dns">
                @include('enom::partials.dns')
            </div>
        @endif

        {{-- Activity --}}
        <div class="enom-tabpanel" id="activity">
            @include('enom::partials.activity', ['operations' => $s['operations'] ?? []])
        </div>
    @endif
</div>
</div>
@include('enom::partials.client-script')
@endcomponent
