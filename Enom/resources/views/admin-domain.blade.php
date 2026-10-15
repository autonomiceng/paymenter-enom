@component('layouts.app', ['title' => $domain['domain'].' renewal billing', 'sidebar' => true])
@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $policy = $domain['policy'];
    $sponsorship = $domain['sponsorship'] ?? [];
    $flags = is_array($domain['server_flags'] ?? null) ? $domain['server_flags'] : null;
    $managed = !empty($domain['managed']);
    $policyOn = !empty($policy['enabled']);
    $expiryDay = $present::day($domain['provider_expires_at'] ?? null);
    $issueDay = $present::day($domain['calendar']['issue_at'] ?? null);
    $dueDay = $present::day($domain['calendar']['due_at'] ?? null);
    $canEnable = !empty($domain['eligibility']['can_enable']);
    $eligibilityReason = $domain['eligibility']['reason'] ?? null;

    $zeroRetail = !empty($domain['zero_retail']);
    $issuing = false;
    if (!$managed) { $billingLine = 'Not in managed billing. This domain renews through its native service billing until it is enrolled below.'; }
    elseif (!$policyOn) { $billingLine = 'Off for this domain.'; }
    elseif ($zeroRetail) { $billingLine = !empty($domain['renewal_cancelled']) ? 'No customer invoice. The retail price is zero and the customer turned renewal off.' : 'No customer invoice. The retail price is zero, so renewal depends on a sponsorship approval.'; }
    elseif ($flags !== null && !$flags['server_enabled']) { $billingLine = 'Not being issued. The Enom server is disabled.'; }
    elseif ($flags !== null && !$flags['billing_enabled']) { $billingLine = 'Not being issued. Schedule domain renewal invoices is off on the Enom server.'; }
    elseif ($flags !== null && !$flags['operations_enabled']) { $billingLine = 'Not being issued. Allow registrar changes is off on the Enom server.'; }
    elseif (!empty($domain['renewal_cancelled'])) { $billingLine = 'Not being issued. The customer turned renewal off.'; }
    elseif (empty($domain['enabled'])) { $billingLine = 'Not being issued. The native service is not active, or a staff hold applies.'; }
    else { $issuing = true; $billingLine = 'On. Next invoice '.($issueDay ?? 'not scheduled').', due '.($dueDay ?? 'not scheduled').'.'; }

    if ($flags === null) { $readsLine = 'Unknown. The server settings could not be read.'; }
    elseif (!$flags['sandbox']) { $readsLine = 'Off. Sandbox only is off on the Enom server, so every registrar request is refused.'; }
    elseif (!$flags['reads_enabled']) { $readsLine = 'Off. Registrar checks, enrollment and charge checks are not available.'; }
    else { $readsLine = 'On.'; }
    $changesLine = $flags === null ? 'Unknown.' : ($flags['operations_enabled'] && $flags['sandbox'] ? 'On. The worker can send queued requests that pass their checks.' : 'Off. Nothing is sent to the registrar.');
    $dailyLine = $flags === null ? 'Unknown.' : ($flags['observations_enabled'] ? 'On.' : 'Off. Dates update only when someone checks the registrar.');

    $approvalOn = !empty($sponsorship['enabled']);
    $approvalCovers = ($sponsorship['current_cycle'] ?? false) === true;
    $approvalBaseDay = $present::day($sponsorship['base_expires_at'] ?? null);
    $limitText = isset($sponsorship['limit_amount']) ? ($sponsorship['currency'] ?? 'USD').' '.$sponsorship['limit_amount'] : null;
    if (!$approvalOn) { $approvalLine = 'None. No spending is allowed for this domain.'; }
    elseif ($approvalCovers && !empty($sponsorship['standing'])) { $approvalLine = 'Every future renewal, accepting quotes up to '.($limitText ?? 'the saved limit').' each.'; }
    elseif ($approvalCovers) { $approvalLine = 'This renewal only, for expiry '.($expiryDay ?? 'on record').', accepting a quote up to '.($limitText ?? 'the saved limit').'.'; }
    else { $approvalLine = 'None for the current cycle. The one-time approval was for the renewal from '.($approvalBaseDay ?? 'an earlier expiry').' and does not cover the current expiry'.($expiryDay ? ' of '.$expiryDay : '').'.'; }
@endphp
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    <a class="enom-link underline" href="{{ route('enom.admin.index') }}">Back to Enom administration</a>
    <div class="flex flex-wrap justify-between items-end gap-4">
        <div>
            <h1 class="text-2xl font-semibold">{{ $domain['domain'] }}</h1>
            <p class="text-sm">Renewal billing and registrar data</p>
        </div>
        @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => null, 'label' => 'Staff help'])
    </div>
    @include('enom::partials.messages')

    <section class="border border-neutral p-5 rounded-lg space-y-3" aria-labelledby="enom-next-heading">
        <h2 id="enom-next-heading" class="text-xl font-semibold">What happens next</h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div><dt class="font-semibold">Renewal invoices</dt><dd>{{ $billingLine }}</dd></div>
            <div><dt class="font-semibold">Registrar reads</dt><dd>{{ $readsLine }}</dd></div>
            <div><dt class="font-semibold">Registrar changes</dt><dd>{{ $changesLine }}</dd></div>
            <div><dt class="font-semibold">Daily checks</dt><dd>{{ $dailyLine }}</dd></div>
        </dl>
        @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => 'setup', 'label' => 'Server settings and setup order'])
    </section>

    <section class="border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-registrar-heading">
        <h2 id="enom-registrar-heading" class="text-xl font-semibold">Registrar data</h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div><dt>Status</dt><dd class="font-semibold">{{ $domain['progress']['label'] ?? $present::progress($domain['status'] ?? null, null) }}</dd></div>
            <div><dt>Confirmed expiry</dt><dd class="font-semibold">{{ $expiryDay ?? 'Not confirmed' }}</dd></div>
            <div><dt>Registrar-reported expiry</dt><dd class="font-semibold">{{ $present::day($domain['observed_expires_at'] ?? null) ?? 'Not checked yet' }}{{ !empty($domain['observed_expires_at']) && ($domain['observed_expires_at'] === ($domain['provider_expires_at'] ?? null)) ? ', matches' : '' }}</dd></div>
            <div><dt>Last check</dt><dd>{{ $present::moment($domain['observed_at'] ?? null) ?? 'Not checked yet' }}</dd></div>
            <div><dt>Check result</dt><dd>{{ $present::observationStatus($domain['observation_status'] ?? 'unknown') }}</dd></div>
            <div><dt>Native service</dt><dd>{{ $domain['service_id'] ? '#'.$domain['service_id'] : 'Not linked' }}</dd></div>
        </dl>
        @if (($domain['observation_problem'] ?? null) === 'Observed expiry differs from confirmed registrar expiry.')
            <p class="enom-warning border border-neutral p-3 rounded-lg text-sm" role="note">The registrar-reported expiry differs from the confirmed expiry. Billing waits for review, and nothing changes the confirmed date automatically.</p>
        @elseif (!empty($domain['observation_problem']))
            <p class="text-sm" role="note">The last registrar check is out of date or incomplete. Use Check registrar now.</p>
        @endif
        <form method="POST" action="{{ route('enom.admin.refresh', $domain['id']) }}" data-enom-validate class="space-y-2">
            @include('enom::partials.validation-feedback')
            @csrf
            <input type="hidden" name="_request_key" value="{{ $keys['refresh'] }}">
            <p class="text-sm">Reads status and expiry from Enom{{ ($domain['permissions']['costs'] ?? false) ? ', and the renewal price' : '' }}. It does not buy, approve or change anything.@if ($flags !== null && (!$flags['reads_enabled'] || !$flags['sandbox'])) Registrar reads are off, so this check will not run until they are turned on.@endif</p>
            <div class="max-w-[300px]"><x-button.secondary type="submit">Check registrar now</x-button.secondary></div>
        </form>
    </section>

    <section class="border border-neutral bg-background-secondary p-5 rounded-lg space-y-4" aria-labelledby="enom-billing-heading">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 id="enom-billing-heading" class="text-xl font-semibold">Renewal billing</h2>
            @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => $managed ? 'calendar' : 'enrollment', 'label' => $managed ? 'Calendar help' : 'Enrollment help'])
        </div>
        @if ($managed && $zeroRetail)
            <p class="text-sm">No customer invoice, because the retail price is zero. Renewal needs a sponsorship approval.</p>
            <p class="text-sm">Configured renewal review starts: {{ $issueDay ?? 'not scheduled' }}. Registrar expiry: {{ $expiryDay ?? 'not confirmed' }}.</p>
        @elseif ($managed)
            <p class="text-sm">Configured invoice date: {{ $issueDay ?? 'not scheduled' }}. Payment due: {{ $dueDay ?? 'not scheduled' }}.@unless ($issuing) Invoices are not being issued now. These dates apply only after that changes. See What happens next.@endunless</p>
        @endif
        <p class="text-sm">
            @if (!empty($domain['billing_health']['attempted_at']))
                Last billing run {{ $present::moment($domain['billing_health']['attempted_at']) }}: {{ $present::billingResult($domain['billing_health']['result'] ?? null) }}.
            @else
                No billing run recorded yet.
            @endif
        </p>
        @if (($domain['billing_health']['deadline_missed'] ?? null) === true)
            <p class="enom-warning border border-neutral p-3 rounded-lg text-sm" role="note">{{ $zeroRetail ? 'A previous invoice schedule needs review. No customer invoice is issued while the retail price is zero.' : 'The invoice issue date passed without an invoice for this expiry. Review eligibility and existing invoices before issuing a renewal invoice.' }}</p>
        @endif
        @if ((!$managed || !$policyOn) && !$canEnable)
            <div class="enom-warning border border-neutral p-3 rounded-lg text-sm space-y-1" role="note">
                <p>{{ $managed ? ($zeroRetail ? 'The renewal policy cannot be turned back on yet.' : 'Invoices cannot be turned back on yet.') : 'This domain cannot be enrolled yet.' }} {{ $present::eligibilityStep($eligibilityReason) ?? 'It needs an active one-year service, a confirmed future expiry and no open requests.' }}</p>
                @if ($eligibilityReason && $present::eligibilityStep($eligibilityReason) !== $eligibilityReason)<p class="text-xs">Recorded reason: {{ $eligibilityReason }}</p>@endif
            </div>
        @elseif (!$managed)
            <p class="text-sm">This domain passes the enrollment checks right now.</p>
        @endif
        @php $formAction = 'policy'; $formContext = 'staff-domain:'.$domain['id'].':'.$formAction; @endphp
        <form method="POST" action="{{ route('enom.admin.policy', $domain['id']) }}" data-enom-validate class="space-y-3">
            @include('enom::partials.validation-feedback')
            @csrf
            <input type="hidden" name="_request_key" value="{{ $keys['policy'] }}">
            @if ($managed)
                <input type="hidden" name="billing_managed" value="1">
                <p class="text-sm">This domain is in managed billing. Enrollment cannot be undone. {{ $zeroRetail ? 'Set the renewal policy to Off to stop new sponsorship requests under this policy.' : 'To stop invoices, set renewal invoices to Off.' }} The native service due date stays empty.</p>
            @else
                <input type="hidden" name="billing_managed" value="0">
                @php $enrollInvalid = old('_enom_form') === $formContext && $errors->has('billing_managed'); @endphp
                <label for="enroll-domain-{{ $domain['id'] }}" class="enom-check text-sm">
                    <input type="checkbox" name="billing_managed" id="enroll-domain-{{ $domain['id'] }}" value="1" @checked(old('_enom_form') === $formContext && old('billing_managed') === '1') aria-describedby="enroll-domain-{{ $domain['id'] }}-hint{{ $enrollInvalid ? ' enroll-domain-'.$domain['id'].'-error' : '' }}" @if ($enrollInvalid) aria-invalid="true" @endif>
                    <span>Enroll this domain in managed renewal billing</span>
                </label>
                <p id="enroll-domain-{{ $domain['id'] }}-hint" class="text-xs">Needs a complete registrar check from the last 24 hours, registrar auto-renew off and no open requests. Enrolling clears the native service due date and cannot be undone. Set {{ $zeroRetail ? 'the renewal policy' : 'renewal invoices' }} to On together with enrollment.</p>
                @if ($enrollInvalid)<p id="enroll-domain-{{ $domain['id'] }}-error" data-enom-server-error class="text-red-500 text-xs">{{ $errors->first('billing_managed') }}</p>@endif
            @endif
            @include('enom::partials.automation-field', ['field' => 'enabled', 'label' => $zeroRetail ? 'Renewal policy for this domain' : 'Renewal invoices for this domain', 'value' => $policyOn ? '1' : '0', 'options' => ['0' => 'Off', '1' => 'On'], 'hint' => $zeroRetail ? 'A zero retail price creates no customer invoice. A sponsorship approval is required before any purchase.' : 'Invoices are issued only while the Enom server allows renewal invoices and registrar changes.'])
            @include('enom::partials.automation-field', ['field' => 'issue_days', 'label' => $zeroRetail ? 'Review starts, days before expiry' : 'Issue invoice, days before expiry', 'value' => $policy['issue_days'] ?? 21, 'type' => 'number', 'min' => 2, 'max' => 365, 'step' => 1, 'hint' => $zeroRetail ? '2 to 365. Must be more than the saved due-day offset. The usual setting is 21. A standing sponsorship may be considered from this date.' : '2 to 365. Must be more than the payment due days. The usual setting is 21.'])
            @include('enom::partials.automation-field', ['field' => 'due_days', 'label' => $zeroRetail ? 'Saved due-day offset, days before expiry' : 'Payment due, days before expiry', 'value' => $policy['due_days'] ?? 7, 'type' => 'number', 'min' => 1, 'max' => 364, 'step' => 1, 'hint' => $zeroRetail ? '1 to 364. Saved for customer invoices if the retail renewal price becomes positive. This does not set a sponsorship deadline.' : '1 to 364. The usual setting is 7.'])
            @include('enom::partials.automation-field', ['field' => 'credits_auto_use', 'label' => $zeroRetail ? 'Customer credit for future paid renewals' : 'Pay from customer credit', 'value' => !empty($policy['credits_auto_use']) ? '1' : '0', 'options' => ['0' => 'Off', '1' => 'On, when credit covers the whole invoice'], 'hint' => $zeroRetail ? 'Applies only if a positive retail price creates a customer renewal invoice.' : null])
            @include('enom::partials.confirm', ['confirmation' => 'Save these renewal settings. I checked the registrar data above and the open requests for this domain.'])
            <div class="max-w-[300px]"><x-button.primary type="submit">Save renewal settings</x-button.primary></div>
        </form>
        @component('enom::partials.disclosure', ['summary' => 'How changes apply'])
            <p>Changes apply to invoices that have not been issued yet. Invoices already issued keep their own dates and amounts, and existing approvals keep their own terms.</p>
            <p>{{ $zeroRetail ? 'The review start date is counted back from the confirmed registrar expiry. There is no customer invoice or sponsorship due-day deadline at a zero retail price. The saved due-day offset and credit choice apply only if the retail renewal price becomes positive.' : 'An invoice is issued on the issue day and is due on the due day, both counted back from the confirmed expiry.' }}</p>
        @endcomponent
    </section>

    @if ($domain['permissions']['fund'] ?? false)
        <section class="border border-neutral bg-background-secondary p-5 rounded-lg space-y-4" aria-labelledby="enom-sponsorship-heading">
            <div class="flex flex-wrap justify-between items-center gap-3">
                <h2 id="enom-sponsorship-heading" class="text-xl font-semibold">Sponsorship</h2>
                @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => 'sponsorship', 'label' => 'Sponsorship help'])
            </div>
            <p class="text-sm">For domains with a zero retail price. A zero price does not allow spending by itself. Each domain needs its own approval and limit. Saving an approval does not buy anything.</p>
            <p class="text-sm"><span class="font-semibold">Current approval:</span> {{ $approvalLine }}</p>
            @php $formAction = 'sponsorship'; $formContext = 'staff-domain:'.$domain['id'].':'.$formAction; @endphp
            <form method="POST" action="{{ route('enom.admin.sponsorship', $domain['id']) }}" data-enom-validate class="space-y-3">
                @include('enom::partials.validation-feedback')
                @csrf
                <input type="hidden" name="_request_key" value="{{ $keys['sponsorship'] }}">
                @include('enom::partials.automation-field', ['field' => 'enabled', 'label' => 'Approval', 'value' => $approvalCovers ? '1' : '0', 'options' => ['0' => 'Not approved, withdraws any current approval', '1' => 'Approved for quotes within the limit below'], 'hint' => 'Not approved withdraws any saved approval. This form still asks for a limit and reason, but a withdrawal does not save them.'.($approvalOn && $approvalCovers ? ' Withdraw approval below needs only a confirmation.' : '')])
                @include('enom::partials.automation-field', ['field' => 'standing', 'label' => 'Applies to', 'value' => !empty($sponsorship['standing']) ? '1' : '0', 'options' => ['0' => 'This renewal only'.($expiryDay ? ', expiry '.$expiryDay : ''), '1' => 'Every future renewal'], 'hint' => 'This renewal only requires the separate purchase action below. Every future renewal allows scheduled requests within the approved quote limit. The final charge can differ.'])
                @include('enom::partials.automation-field', ['field' => 'limit_amount', 'label' => 'Maximum approved renewal quote (USD)', 'value' => $sponsorship['limit_amount'] ?? '', 'pattern' => '[0-9]+([.][0-9]{1,2})?', 'type' => 'text', 'inputmode' => 'decimal', 'hint' => 'The largest registrar quote this approval accepts, for example 15.00. The final Enom charge can differ.'])
                @include('enom::partials.automation-field', ['field' => 'currency', 'label' => 'Currency', 'value' => $sponsorship['currency'] ?? 'USD', 'options' => ['USD' => 'USD']])
                @include('enom::partials.automation-field', ['field' => 'term', 'label' => 'Renewal term', 'value' => $sponsorship['term'] ?? 1, 'options' => ['1' => 'One year']])
                @include('enom::partials.automation-field', ['field' => 'reason', 'label' => 'Reason', 'value' => $sponsorship['reason'] ?? '', 'hint' => '10 to 500 characters. Recorded with the approval.'])
                @include('enom::partials.confirm', ['confirmation' => 'Save this approval and limit. This does not buy a renewal.'])
                <div class="max-w-[300px]"><x-button.primary type="submit">Save approval</x-button.primary></div>
            </form>
            @if ($approvalOn && $approvalCovers)
                <form method="POST" action="{{ route('enom.admin.sponsorship', $domain['id']) }}" data-enom-validate class="border-t border-neutral pt-4 space-y-2">
                    @include('enom::partials.validation-feedback')
                    @csrf
                    <input type="hidden" name="_request_key" value="{{ $keys['sponsorship'] }}">
                    <input type="hidden" name="enabled" value="0">
                    <h3 class="font-semibold">Withdraw approval</h3>
                    <p class="text-sm">Stops future spending under this approval, including a queued request that has not been sent. Request records and payment history stay. A request that was already sent still needs registrar review.</p>
                    @include('enom::partials.confirm', ['confirmation' => 'Withdraw the sponsorship approval for '.$domain['domain'].'.'])
                    <div class="max-w-[300px]"><x-button.secondary type="submit">Withdraw approval</x-button.secondary></div>
                </form>
            @endif
            <div class="border border-neutral p-4 rounded-lg space-y-2">
                <h3 class="font-semibold">Buy renewal with reseller funds</h3>
                <p class="text-sm">The approved limit caps the accepted quote. Enom does not cap the final charge. A confirmed charge above the limit pauses further automatic spending for this domain.</p>
                @if ($approvalOn && $approvalCovers)
                    <form method="POST" action="{{ route('enom.admin.sponsored-renewal', $domain['id']) }}" data-enom-validate class="space-y-2">
                        @include('enom::partials.validation-feedback')
                        @csrf
                        <input type="hidden" name="_request_key" value="{{ $keys['sponsored-renewal'] }}">
                        <p class="text-sm">This is separate from saving the approval. It first reads a fresh renewal quote, then requests a one-year renewal only if the quote is complete and at or under {{ $limitText ?? 'the limit' }}, the domain is eligible, and spending is not paused. A repeat request for the same expiry returns the existing request.</p>
                        @include('enom::partials.confirm', ['confirmation' => 'Request a one-year renewal of '.$domain['domain'].' using reseller funds when its quote is at or under '.($limitText ?? 'the approved limit').'. The final registrar charge can differ.'])
                        <div class="max-w-[300px]"><x-button.secondary type="submit">Buy renewal with reseller funds</x-button.secondary></div>
                    </form>
                @elseif ($approvalOn)
                    <p class="text-sm">Not available. The saved approval does not cover the current expiry. Save a new approval for this cycle first.</p>
                @else
                    <p class="text-sm">Not available. Save an approval first.</p>
                @endif
            </div>
        </section>
    @endif

    @if ($domain['permissions']['costs'] ?? false)
        <section class="border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-costs-heading">
            <div class="flex flex-wrap justify-between items-center gap-3">
                <h2 id="enom-costs-heading" class="text-xl font-semibold">Registrar costs, internal</h2>
                @include('enom::partials.help-link', ['route' => 'enom.admin.help', 'topic' => 'costs', 'label' => 'Quotes and charges help'])
            </div>
            @if (!empty($domain['automatic_spend_paused']))
                <p class="enom-warning border border-neutral p-3 rounded-lg text-sm" role="note">Automatic registrar spending is paused for this domain. Review the confirmed charge and the approved limit before any further sponsored or late renewal.</p>
            @endif
            @if (!empty($domain['cost_health']['state']))
                <p class="text-sm">Cost review: {{ $present::costHealth($domain['cost_health']['state']) }}, request {{ isset($domain['cost_health']['operation_id']) ? '#'.$domain['cost_health']['operation_id'] : 'not recorded' }}, checked {{ $present::moment($domain['cost_health']['observed_at'] ?? null) ?? 'at an unrecorded time' }}.</p>
            @endif
            <p class="text-sm">Latest renewal quote: {{ isset($domain['renewal_quote']['amount']) ? ($domain['renewal_quote']['currency'] ?? 'currency not confirmed').' '.$domain['renewal_quote']['amount'] : 'none recorded' }}. {{ $present::quoteState($domain['renewal_quote']['state'] ?? 'unconfirmed') }}.</p>
            @component('enom::partials.disclosure', ['summary' => 'Quote or confirmed charge'])
                <p>A quote is the price read before a renewal is sent. It decides whether a sponsored renewal may be sent. It is not the charge.</p>
                <p>A charge is confirmed only when the registrar order total matches the single line item for this domain. If the order total is missing or different, the charge stays unconfirmed, even when the line amount or quote looks right. A balance change alone does not confirm a charge.</p>
            @endcomponent
            @if (!empty($domain['costs']))
                <div class="overflow-x-auto">
                    <table class="enom-table w-full text-sm">
                        <caption class="sr-only">Registrar costs for registration, transfer, renewal and privacy requests</caption>
                        <thead>
                            <tr class="border-b border-neutral">
                                <th scope="col" class="text-left p-2">Request</th>
                                <th scope="col" class="text-left p-2">Quote</th>
                                <th scope="col" class="text-left p-2">Confirmed charge</th>
                                <th scope="col" class="text-left p-2">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($domain['costs'] as $cost)
                                <tr class="border-b border-neutral">
                                    <td class="p-2">#{{ $cost['operation_id'] }} {{ $present::action($cost['action'] ?? null) }}@if (!empty($cost['state']))<br><span class="text-xs">{{ $present::state($cost['state']) }}</span>@endif</td>
                                    <td class="p-2">{{ isset($cost['quoted']['amount']) ? $cost['quoted']['currency'].' '.$cost['quoted']['amount'] : 'None' }}@if (isset($cost['quoted']['amount']))<br><span class="text-xs">{{ $present::quoteState($cost['quoted']['state'] ?? null) }}</span>@endif</td>
                                    <td class="p-2">{{ isset($cost['confirmed']['amount']) ? $cost['confirmed']['currency'].' '.$cost['confirmed']['amount'] : 'Not confirmed' }}</td>
                                    <td class="p-2 space-y-1">
                                        @if (isset($cost['poll_attempts']))<p>Charge checks: {{ $cost['poll_attempts'] }} of {{ \Paymenter\Extensions\Servers\Enom\Services\DomainOperations::COST_POLL_LIMIT }}</p>@endif
                                        @if (!empty($cost['poll_reason']))<p>{{ $present::costReason($cost['poll_reason']) }}</p>@endif
                                        @if (!empty($cost['checkable']) && isset($keys['charge'][$cost['operation_id']]))<p>Charge check available below the table.</p>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs">Only registrations, transfers, renewals and privacy purchases are listed. DNS, contact, lock and other management requests have no registrar charge.</p>
                {{-- Same eligibility test as before. Forms sit outside the scrolling table so they stay reachable on narrow screens. --}}
                @php $chargeChecks = array_values(array_filter($domain['costs'], fn ($cost) => !empty($cost['checkable']) && isset($keys['charge'][$cost['operation_id']]))); @endphp
                @if ($chargeChecks)
                    <div class="border-t border-neutral pt-4 space-y-3">
                        <h3 class="font-semibold">Charge checks</h3>
                        <p class="text-sm">Each check reads the registrar order for one completed request. It does not resend the request. You return to Enom administration after the check.</p>
                        @foreach ($chargeChecks as $cost)
                            <form method="POST" action="{{ route('enom.admin.reconcile', $cost['operation_id']) }}" class="border border-neutral p-4 rounded-lg space-y-2" aria-labelledby="enom-charge-check-{{ $cost['operation_id'] }}">
                                @csrf
                                <input type="hidden" name="_request_key" value="{{ $keys['charge'][$cost['operation_id']] }}">
                                <p id="enom-charge-check-{{ $cost['operation_id'] }}" class="text-sm font-semibold">Request #{{ $cost['operation_id'] }} {{ $present::action($cost['action'] ?? null) }}</p>
                                <p class="text-sm">Quote: {{ isset($cost['quoted']['amount']) ? $cost['quoted']['currency'].' '.$cost['quoted']['amount'] : 'None' }}. Confirmed charge: not confirmed.@if (isset($cost['poll_attempts'])) Charge checks so far: {{ $cost['poll_attempts'] }} of {{ \Paymenter\Extensions\Servers\Enom\Services\DomainOperations::COST_POLL_LIMIT }}.@endif</p>
                                @include('enom::partials.confirm', ['confirmation' => 'Read the registrar order for request #'.$cost['operation_id'].'. This does not resend it.'])
                                <div class="max-w-[300px]"><x-button.secondary type="submit">Check charge evidence</x-button.secondary></div>
                            </form>
                        @endforeach
                    </div>
                @endif
            @else
                <p class="text-sm">No registration, transfer, renewal or privacy requests are recorded for this domain.</p>
            @endif
        </section>
    @endif

    @include('enom::partials.sandbox')
</div>
@include('enom::partials.form-validation')
@endcomponent
