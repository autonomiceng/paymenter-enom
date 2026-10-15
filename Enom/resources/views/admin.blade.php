@component('layouts.app', ['title' => 'Enom administration', 'sidebar' => true])
@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $stateGroups = \Paymenter\Extensions\Servers\Enom\Http\Controllers\AdminController::STATE_GROUPS;
    $groupTitles = ['review' => 'Needs review', 'waiting' => 'Waiting', 'history' => 'History', 'other' => 'Other'];
    $groupNotes = [
        'review' => 'Uncertain, sent, running, held and closed requests. Check these first.',
        'waiting' => 'Awaiting payment or queued. Nothing in this group has been sent to the registrar.',
        'history' => 'Completed, declined and retired requests. You can still check charge evidence for a completed request on its domain page.',
        'other' => 'Requests with a state this page does not recognise.',
    ];
    $currentShow = $show ?? null;
    $grouped = ['review' => [], 'waiting' => [], 'history' => [], 'other' => []];
    foreach ($operations as $listed) {
        $placed = 'other';
        foreach ($stateGroups as $groupKey => $groupStates) {
            if (in_array($listed->state, $groupStates, true)) { $placed = $groupKey; break; }
        }
        $grouped[$placed][] = $listed;
    }
@endphp
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Enom administration</h1>
        <div class="flex flex-wrap gap-4">
            @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => null, 'label' => 'Staff help'])
            <a class="enom-link underline" href="{{ route('filament.admin.pages.dashboard') }}">Back to administration</a>
        </div>
    </div>
    @include('enom::partials.messages')
    <p>{{ $domainCount }} domains. {{ $reviewCount }} uncertain registrar {{ (int) $reviewCount === 1 ? 'outcome' : 'outcomes' }}. Pending and held requests are listed under Needs review.</p>
    <p class="text-sm">Reconcile reads registrar evidence and never repeats a purchase. While a request is unresolved, other changes for that domain wait. Customer renewals need a fully paid invoice, and sponsorship needs an approval for each domain.</p>

    <section class="border border-neutral p-5 rounded-lg space-y-3" aria-labelledby="enom-retired-heading">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 id="enom-retired-heading" class="text-xl font-semibold">Payments on retired renewal invoices</h2>
            @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => 'review', 'label' => 'Review help'])
        </div>
        <p class="text-sm">Lists transactions recorded on renewal invoices that were cancelled when a customer turned renewal off. A payment here never authorizes a renewal. Review refunds or credit separately.</p>
        @forelse ($retiredInvoicePayments ?? [] as $review)
            <div class="enom-warning border border-neutral p-3 rounded-lg text-sm" role="note">
                <p>Request #{{ $review['operation_id'] }}: {{ $review['domain'] ?? 'Domain not linked' }}. <a class="underline" href="{{ route('invoices.show', $review['invoice_id']) }}">Invoice #{{ $review['invoice_id'] }}</a>: {{ $present::invoiceStatus($review['invoice_status'] ?? null) }}, {{ $review['transaction_count'] }} {{ (int) $review['transaction_count'] === 1 ? 'transaction' : 'transactions' }}.</p>
                <p>{{ $review['review'] }}</p>
            </div>
        @empty
            <p class="text-sm">No payments recorded on retired renewal invoices.</p>
        @endforelse
    </section>

    <section class="border border-neutral p-5 rounded-lg space-y-3" aria-labelledby="enom-domains-heading">
        <h2 id="enom-domains-heading" class="text-xl font-semibold">Domains</h2>
        <p class="text-sm">Open a domain to see its registrar data and renewal billing. Existing domains keep their current billing until you enroll them one at a time.</p>
        @forelse ($domains ?? [] as $mapping)
            <div class="flex flex-wrap justify-between items-center gap-3 border-t border-neutral pt-1">
                <a class="enom-link underline" href="{{ route('enom.admin.domain', $mapping->id) }}">{{ $mapping->domain }}</a>
                <span class="text-sm">{{ $domainProgress[$mapping->id]['label'] ??$present::progress($mapping->status, null) }}</span>
            </div>
        @empty
            <p class="text-sm">No domains yet.</p>
        @endforelse
    </section>

    <nav aria-label="Filter requests" class="enom-nav text-sm">
        <a class="enom-link underline" href="{{ route('enom.admin.index') }}" @if ($currentShow === null) aria-current="page" @endif>All requests</a>
        @foreach (['review', 'waiting', 'history'] as $groupKey)
            <a class="enom-link underline" href="{{ route('enom.admin.index', ['show' => $groupKey]) }}" @if ($currentShow === $groupKey) aria-current="page" @endif>{{ $groupTitles[$groupKey] }} ({{ $groupCounts[$groupKey] ?? 0 }})</a>
        @endforeach
    </nav>

    @if ($operations->isEmpty())
        <p class="border border-neutral p-4 rounded-lg">{{ $currentShow ? 'No requests in this group.' : 'No registrar requests have been recorded.' }}</p>
    @endif

    @foreach ($grouped as $groupKey => $groupOperations)
        @continue(count($groupOperations) === 0)
        <section class="space-y-4" aria-labelledby="enom-group-{{ $groupKey }}">
            <div>
                <h2 id="enom-group-{{ $groupKey }}" class="text-xl font-semibold">{{ $groupTitles[$groupKey] }}</h2>
                @php $groupTotal = $groupCounts[$groupKey] ?? null; $groupOnPage = count($groupOperations); @endphp
                <p class="text-sm">{{ $groupNotes[$groupKey] }}
                    @if ($groupTotal !== null && $groupOnPage < $groupTotal)
                        {{ $groupOnPage }} of {{ $groupTotal }} on this page.
                        @if ($currentShow === $groupKey)
                            Use the page links below for the rest.
                        @else
                            Open <a class="enom-link underline" href="{{ route('enom.admin.index', ['show' => $groupKey]) }}">{{ $groupTitles[$groupKey] }} ({{ $groupTotal }})</a> to see all.
                        @endif
                    @elseif ($groupTotal === null && $operations->lastPage() > 1)
                        {{ $groupOnPage }} on this page.
                    @endif
                </p>
            </div>
            @foreach ($groupOperations as $operation)
                <section class="border border-neutral bg-background-secondary p-5 rounded-lg space-y-4" aria-labelledby="enom-request-{{ $operation->id }}">
                    <div class="flex flex-wrap justify-between gap-3">
                        <h3 id="enom-request-{{ $operation->id }}" class="font-semibold">Request #{{ $operation->id }}: {{ $present::action($operation->action) }}</h3>
                        <strong>{{ $present::state($operation->state, $present::outcome($operation->safe_code ?? null)) }}</strong>
                    </div>
                    <p class="text-sm">{{ $present::staffStateMeaning($operation->state) }}</p>
                    <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div><dt>Domain</dt><dd>{{ $operation->domain?->domain ?? 'Domain not linked' }}</dd></div>
                        <div><dt>Funding</dt><dd>{{ $present::funding($operation->funding_basis) }}</dd></div>
                        <div><dt>Amount</dt><dd>{{ $operation->currency }} {{ $operation->amount }}</dd></div>
                        <div><dt>Native invoice</dt><dd>{{ $operation->invoice_id ? '#'.$operation->invoice_id : 'None' }}</dd></div>
                        <div><dt>Created</dt><dd>{{ $present::moment($operation->created_at) ?? 'Not recorded' }}</dd></div>
                        <div><dt>Confirmed expiry</dt><dd>{{ $present::day($operation->confirmed_expires_at) ?? 'Not confirmed' }}</dd></div>
                    </dl>
                    @php $providerReview = $providerReviews[$operation->id] ?? []; $providerFact = $providerReview['fact'] ?? []; $disposition = $providerReview['disposition'] ?? []; @endphp
                    @if (!empty($disposition))
                        <div class="enom-warning border border-neutral p-3 rounded-lg text-sm space-y-2" role="note">
                            <p>Staff provider disposition: {{ str_replace('_', ' ', $disposition['outcome']) }}. Financial disposition: {{ str_replace('_', ' ', $disposition['financial']) }}.</p>
                            <p>Reviewed by staff #{{ $disposition['staff_id'] }}: {{ $disposition['reason'] }}</p>
                            <p>All domain writes and renewal billing stay paused. Recorded facts do not change the native service status or confirmed expiry, and grant no spending authority.</p>
                        </div>
                    @endif
                    @if (!empty($providerReview['eligible']))
                        <section class="border-t border-neutral pt-4 space-y-3">
                            <h4 class="font-semibold">Post-send provider and financial review</h4>
                            <p class="text-sm">Records authenticated account, domain and expiry facts, even if payment authority or the service changed after the send. The original request stays as it is. A known order still needs exact completion evidence.</p>
                            <form method="POST" action="{{ route('enom.admin.provider-fact', $operation->id) }}" class="space-y-2">
                                @csrf
                                <input type="hidden" name="_request_key" value="{{ $keys[$operation->id]['provider-fact'] }}">
                                @include('enom::partials.confirm', ['confirmation' => 'Read authenticated provider facts without resending the request or approving funds.'])
                                <div class="max-w-[300px]"><x-button.secondary type="submit">Record current provider facts</x-button.secondary></div>
                            </form>
                            @if (!empty($providerFact))
                                <p class="text-sm">Observed expiry: {{ $providerFact['expires_at'] }}. Checked: {{ \Illuminate\Support\Carbon::parse($providerFact['observed_at'])->utc()->format('M j, Y H:i') }} UTC. {{ $providerFact['known_order'] ? ($providerFact['order_completed'] ? 'The exact known order has matching completed evidence.' : 'The known order still needs matching completed evidence.') : 'No known order identifies the original renewal outcome. An observed expiry alone does not prove completion.' }}</p>
                                @if (!$providerFact['known_order'] || $providerFact['order_completed'])
                                    <form method="POST" action="{{ route('enom.admin.disposition', $operation->id) }}" data-enom-validate class="space-y-3">
                                        @include('enom::partials.validation-feedback')
                                        @csrf
                                        <input type="hidden" name="_request_key" value="{{ $keys[$operation->id]['disposition'] }}">
                                        <input type="hidden" name="fact_digest" value="{{ $providerFact['digest'] }}">
                                        <input type="hidden" name="outcome" value="{{ $providerFact['known_order'] ? 'provider_completed' : 'unattributed_provider_fact' }}">
                                        <input type="hidden" name="financial" value="manual_review_required">
                                        <p class="text-sm">Closes the request as {{ $providerFact['known_order'] ? 'a recorded provider completion' : 'an unattributed provider fact' }} and keeps manual financial review for any payment, refund, credit or reseller expense. This pauses all domain writes and renewal billing for a separate binding review.</p>
                                        @php $dispositionInvalid = old('_enom_form') === 'staff:'.$operation->id.':disposition' && $errors->has('reason'); @endphp
                                        <label for="disposition-reason-{{ $operation->id }}" class="block text-sm">Provider and financial disposition reason</label>
                                        <textarea name="reason" id="disposition-reason-{{ $operation->id }}" minlength="10" maxlength="500" required rows="3" @if ($dispositionInvalid) aria-invalid="true" aria-describedby="disposition-reason-{{ $operation->id }}-error" @endif class="w-full bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5 focus:border-secondary">{{ old('_enom_form') === 'staff:'.$operation->id.':disposition' ? old('reason', '') : '' }}</textarea>
                                        <p data-enom-client-error data-enom-client-for="reason" class="text-red-500 text-xs mt-1" hidden></p>
                                        @if ($dispositionInvalid)<p id="disposition-reason-{{ $operation->id }}-error" data-enom-server-error class="text-red-500 text-xs">{{ $errors->first('reason') }}</p>@endif
                                        @include('enom::partials.confirm', ['confirmation' => 'Record this provider fact and keep manual financial review. Pause all further domain writes and billing without changing services or payments.'])
                                        <div class="max-w-[300px]"><x-button.primary type="submit">Record disposition and pause domain</x-button.primary></div>
                                    </form>
                                @endif
                            @endif
                        </section>
                    @endif
                    @if (in_array($operation->state, ['uncertain', 'pending', 'running']))
                        <form method="POST" action="{{ route('enom.admin.reconcile', $operation->id) }}" class="space-y-2">
                            @csrf
                            <input type="hidden" name="_request_key" value="{{ $keys[$operation->id]['reconcile'] }}">
                            @if ($operation->state === 'running')<p class="text-sm">Reconcile does nothing until the worker's claim on this request expires.</p>@endif
                            @include('enom::partials.confirm', ['confirmation' => 'Check registrar evidence for this request without repeating it.'])
                            <div class="max-w-[300px]"><x-button.secondary type="submit">Reconcile request</x-button.secondary></div>
                        </form>
                    @elseif ($operation->state === 'queued')
                        <p class="text-sm">Reconcile is not offered for queued requests. There is no registrar evidence to read until the request is sent.</p>
                    @endif
                    @if (($readmissionEligible[$operation->id] ?? false) && $operation->state === 'held' && $operation->action === 'renew')
                        <details class="border-t border-neutral pt-4" @if (old('_enom_form') === 'staff:'.$operation->id.':readmit') open @endif>
                            <summary class="font-semibold">Resume verified unsent renewal</summary>
                            <form method="POST" action="{{ route('enom.admin.readmit', $operation->id) }}" data-enom-validate class="mt-4 space-y-3">
                                @include('enom::partials.validation-feedback')
                                @csrf
                                <input type="hidden" name="_request_key" value="{{ $keys[$operation->id]['readmit'] }}">
                                <p class="text-sm">For a fully funded managed renewal that was held before anything was sent to the registrar. Check its paid invoice or current sponsorship and clear the cause of the hold first. This queues the same invoice and request. No new invoice or payment is created. The worker checks the account, expiry, eligibility and cost again before any send.</p>
                                @php $readmitInvalid = old('_enom_form') === 'staff:'.$operation->id.':readmit' && $errors->has('reason'); @endphp
                                <label for="readmit-reason-{{ $operation->id }}" class="block text-sm">Reason for resuming this renewal</label>
                                <textarea name="reason" id="readmit-reason-{{ $operation->id }}" minlength="10" maxlength="500" required rows="3" @if ($readmitInvalid) aria-invalid="true" aria-describedby="readmit-reason-{{ $operation->id }}-error" @endif class="w-full bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5 focus:border-secondary">{{ old('_enom_form') === 'staff:'.$operation->id.':readmit' ? old('reason', '') : '' }}</textarea>
                                <p data-enom-client-error data-enom-client-for="reason" class="text-red-500 text-xs mt-1" hidden></p>
                                @if ($readmitInvalid)<p id="readmit-reason-{{ $operation->id }}-error" data-enom-server-error class="text-red-500 text-xs">{{ $errors->first('reason') }}</p>@endif
                                @include('enom::partials.confirm', ['confirmation' => 'I reviewed the resolved hold and verified this renewal was never sent. Resume its existing funded obligation with current registrar checks.'])
                                <div class="max-w-[300px]"><x-button.primary type="submit">Resume verified unsent renewal</x-button.primary></div>
                            </form>
                        </details>
                    @elseif ($operation->state === 'held' && $operation->action === 'renew')
                        <p class="text-sm">This held renewal cannot be resumed from here. Only a fully funded renewal that was never sent, with matching expiry and no other open renewal, can resume.</p>
                    @endif
                    @if (($canFund ?? false) && $operation->invoice_id === null && in_array($operation->state, ['awaiting_payment', 'held']) && !$operation->started_at && (int) $operation->term === 1 && (float) $operation->amount > 0 && in_array($operation->action, ['register', 'transfer', 'renew', 'privacy_purchase', 'privacy_renew']))
                        @if ($fundingBlockers[$operation->id] ?? false)
                            <p class="text-sm" role="note">Funding is not available while another registrar request for this domain is unresolved, or the domain is not linked. Review that first.</p>
                        @else
                        <details class="border-t border-neutral pt-4" @if (old('_enom_form') === 'staff:'.$operation->id.':fund') open @endif>
                            <summary class="font-semibold">Authorize Operator funding</summary>
                            <form method="POST" action="{{ route('enom.admin.fund', $operation->id) }}" data-enom-validate class="mt-4 space-y-3">
                                @include('enom::partials.validation-feedback')
                                @csrf
                                <input type="hidden" name="_request_key" value="{{ $keys[$operation->id]['fund'] }}">
                                <input type="hidden" name="amount" value="{{ $operation->amount }}">
                                <input type="hidden" name="currency" value="{{ $operation->currency }}">
                                <p class="text-sm">Authorizes Operator to fund this exact {{ strtolower($present::action($operation->action)) }} for one year and queues it. The recorded amount is {{ $operation->currency }} {{ $operation->amount }}. It does not cap the final Enom charge, which can differ. The worker still runs its own checks before sending. This does not record a customer payment.</p>
                                <label for="funding-reason-{{ $operation->id }}" class="block text-sm">Funding reason <span class="text-red-500">*</span></label>
                                @php $fundingInvalid = old('_enom_form') === 'staff:'.$operation->id.':fund' && $errors->has('reason'); @endphp
                                <textarea @if ($fundingInvalid) aria-invalid="true" aria-describedby="funding-reason-{{ $operation->id }}-error" @endif name="reason" id="funding-reason-{{ $operation->id }}" minlength="10" maxlength="500" required rows="3" class="w-full bg-background-secondary border border-neutral rounded-md px-2.5 py-2.5 focus:border-secondary">{{ old('_enom_form') === 'staff:'.$operation->id.':fund' ? old('reason', '') : '' }}</textarea>
                                <p data-enom-client-error data-enom-client-for="reason" class="text-red-500 text-xs mt-1" hidden></p>
                                @if ($fundingInvalid)<p id="funding-reason-{{ $operation->id }}-error" data-enom-server-error class="text-red-500 text-xs">{{ $errors->first('reason') }}</p>@endif
                                @include('enom::partials.confirm', ['confirmation' => 'The operator will fund this exact action. The final Enom charge can differ from the recorded amount. I have reviewed registrar and payment evidence.'])
                                <div class="max-w-[300px]"><x-button.primary type="submit">Authorize funding</x-button.primary></div>
                            </form>
                        </details>
                        @endif
                    @endif
                </section>
            @endforeach
        </section>
    @endforeach
    {{ $operations->links() }}
    @include('enom::partials.sandbox')
</div>
@include('enom::partials.form-validation')
@endcomponent
