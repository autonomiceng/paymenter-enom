@component('layouts.app', ['title' => $snapshot['domain'], 'sidebar' => true])
@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $domainId = $snapshot['id'];
    $domainName = $snapshot['domain'];
    $cached = $snapshot['cached'];
    $capabilities = $snapshot['capabilities'];
    $available = fn ($action) => ($capabilities[$action] ?? false) === true;
    $automation = $snapshot['automation'] ?? [];
    $managed = !empty($automation['managed']);
    $renewalOn = ($snapshot['renewal_enabled'] ?? true) === true;
    $zeroRetail = ($snapshot['zero_retail'] ?? false) === true;
    $expiryText = $present::day($snapshot['provider_expires_at'] ?? null);
    $progress = is_array($snapshot['progress'] ?? null) ? $snapshot['progress'] : [];
    $checkedAt = $present::latest($snapshot['last_synced_at'] ?? null, $automation['observed_at'] ?? null);
    $driftProblem = ($automation['observation_problem'] ?? null) === 'Observed expiry differs from confirmed registrar expiry.';
    $paused = !empty($snapshot['writes_blocked']);
    $pausedText = 'Changes are paused while a registrar request is checked.';
    $unavailable = fn (string $reason) => $paused ? $pausedText : $reason;
@endphp
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    <a class="enom-link underline" href="{{ route('enom.domains.index') }}">Back to domains</a>
    <div class="flex flex-wrap justify-between items-center gap-4">
        <h1 class="text-2xl font-semibold">{{ $domainName }}</h1>
        <div class="flex flex-wrap gap-4">
            @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => null, 'label' => 'Domain help'])
            <a class="enom-link underline text-sm" href="{{ route('services.show', $snapshot['service_id']) }}">View billing service</a>
        </div>
    </div>
    @include('enom::partials.messages')
    @if ($paused)
        <div class="enom-warning border border-neutral p-4 rounded-lg" role="note">
            <p>{{ !empty($snapshot['needs_review']) ? 'A registrar request needs review by support. Changes to this domain are paused until then.' : 'A registrar request is still being checked. Changes to this domain are paused until it finishes.' }} Your invoices are still available.</p>
        </div>
    @endif

    <section class="border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-summary-heading">
        <h2 id="enom-summary-heading" class="sr-only">Summary</h2>
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div><dt class="text-sm">Status</dt><dd class="font-semibold">{{ $progress['label'] ?? $present::progress($snapshot['status'] ?? null, null) }}</dd></div>
            <div><dt class="text-sm">Expires</dt><dd class="font-semibold">{{ $expiryText ?? 'Not confirmed yet' }}</dd></div>
            <div><dt class="text-sm">Renewal</dt><dd class="font-semibold">{{ $renewalOn ? 'On' : 'Off' }}</dd></div>
        </dl>
        @if (!empty($progress['awaiting_payment']) && !empty($progress['invoice_id']))
            <p class="text-sm">Pay <a class="enom-link underline" href="{{ route('invoices.show', $progress['invoice_id']) }}">invoice #{{ $progress['invoice_id'] }}</a> in full to start the {{ $progress['kind'] ?? 'request' }}. Nothing is sent to the registrar before then.</p>
        @endif
        <p class="text-sm">Registrar details checked: {{ $present::moment($checkedAt) ?? 'Not checked yet' }}.</p>
        @if ($managed && in_array($automation['observation_status'] ?? null, ['stale', 'unknown'], true) && !$driftProblem)
            <p class="text-sm">The registrar dates have not been confirmed recently. The dates shown may be out of date.</p>
        @endif
        <form method="POST" action="{{ route('enom.domains.refresh', $domainId) }}" class="space-y-2">
            @include('enom::partials.form-key', ['action' => 'refresh'])
            <p class="text-sm">Reads the latest details from the registrar. It does not change anything.</p>
            <div class="max-w-[300px]"><x-button.secondary type="submit">Refresh status</x-button.secondary></div>
        </form>
    </section>

    <section class="bg-background-secondary border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-renewal-heading">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 id="enom-renewal-heading" class="text-xl font-semibold">Renewal and invoices</h2>
            @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => 'renewal', 'label' => 'Renewal help'])
        </div>
        @include('enom::partials.automation-info')

        @if ($renewalOn && !$zeroRetail)
            @if ($available('renew'))
                <form method="POST" action="{{ route('enom.domains.renew', $domainId) }}" class="border-t border-neutral pt-4 space-y-2">
                    @include('enom::partials.form-key', ['action' => 'renew'])
                    <h3 class="font-semibold">Renew now</h3>
                    <p class="text-sm">Creates an invoice for one year at your service renewal price. The renewal is sent to the registrar after the invoice is paid in full.</p>
                    @include('enom::partials.confirm', ['confirmation' => 'Create a one-year renewal invoice for '.$domainName.'.'])
                    <div class="max-w-[300px]"><x-button.primary type="submit">Create renewal invoice</x-button.primary></div>
                </form>
            @else
                <p class="text-sm border-t border-neutral pt-4">{{ $unavailable('Renewing now is not available for this domain.') }} Contact support if it needs to renew{{ $expiryText ? ' before '.$expiryText : '' }}.</p>
            @endif
        @endif

        <form method="POST" action="{{ route('enom.domains.preference', $domainId) }}" class="border-t border-neutral pt-4 space-y-2">
            @include('enom::partials.form-key', ['action' => 'renewal_preference'])
            <input type="hidden" name="enabled" value="{{ $renewalOn ? '0' : '1' }}">
            @if ($renewalOn)
                <h3 class="font-semibold">Turn off renewal</h3>
                @if ($managed)
                    <p class="text-sm">Eligible unpaid renewal invoices are cancelled right away. Contact support to review any remaining renewal invoice. Do not pay a renewal invoice while renewal is off, because it will not be sent to the registrar.</p>
                @else
                    <p class="text-sm">The service is set to cancel at the end of its period, and no renewal is sent to the registrar.</p>
                @endif
                @include('enom::partials.confirm', ['confirmation' => 'Turn off renewal for '.$domainName.'.'])
                <div class="max-w-[300px]"><x-button.secondary type="submit">Turn off renewal</x-button.secondary></div>
            @else
                <h3 class="font-semibold">Turn renewal back on</h3>
                <p class="text-sm">Cancelled invoices stay cancelled. This needs an active domain with a future expiry and no request still being checked. If the payment due date has passed, contact support for a new invoice.</p>
                @include('enom::partials.confirm', ['confirmation' => 'Turn renewal back on for '.$domainName.'.'])
                <div class="max-w-[300px]"><x-button.secondary type="submit">Turn renewal back on</x-button.secondary></div>
            @endif
        </form>

        @component('enom::partials.disclosure', ['summary' => 'Expiry date and invoice due date'])
            <p>The expiry date is when the registration ends at the registrar. The invoice due date is earlier, so the renewal can be sent before the domain expires.</p>
            <p>Paying an invoice starts the renewal request. The new expiry date appears here only after the registrar confirms it.</p>
        @endcomponent
    </section>

    <details class="bg-background-secondary border border-neutral p-5 rounded-lg" open>
        <summary class="text-xl font-semibold">Nameservers</summary>
        <div class="mt-4 space-y-4">
            <p class="text-sm">Changing nameservers moves DNS for this domain to the servers you list.</p>
            @if ($available('nameservers'))
                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'nameservers']) }}" data-enom-validate class="space-y-3">
                    @include('enom::partials.validation-feedback')
                    @include('enom::partials.form-key', ['action' => 'nameservers'])
                    <x-form.textarea name="nameservers" label="Nameservers, one per line" required rows="4" aria-describedby="enom-nameservers-hint">{{ old('_enom_form') === 'domain:'.$domainId.':nameservers' ? old('nameservers') : implode("\n", $cached['nameservers']) }}</x-form.textarea>
                    <p id="enom-nameservers-hint" class="text-xs">Enter 2 to 12 hostnames, one per line, such as ns1.example.net. Do not use IP addresses.</p>
                    <p data-enom-client-error data-enom-client-for="nameservers" class="text-red-500 text-xs mt-1" hidden></p>
                    @include('enom::partials.confirm', ['confirmation' => 'Replace the whole nameserver list with these nameservers.'])
                    <div class="max-w-[300px]"><x-button.primary type="submit">Save nameservers</x-button.primary></div>
                </form>
                @component('enom::partials.disclosure', ['summary' => 'Nameserver example'])
                    @include('enom::partials.code-example', ['caption' => 'Two nameservers, one per line.', 'code' => "ns1.example.net\nns2.example.net"])
                @endcomponent
            @else
                <p class="text-sm">{{ $unavailable('Nameserver changes are not available right now. Refresh status to check again.') }}</p>
                @forelse ($cached['nameservers'] as $server)<p>{{ $server }}</p>@empty<p class="text-sm">No nameservers on record.</p>@endforelse
            @endif
        </div>
    </details>

    @include('enom::partials.dns')

    <details class="bg-background-secondary border border-neutral p-5 rounded-lg" @if (old('_enom_form') === 'domain:'.$domainId.':contacts') open @endif>
        <summary class="text-xl font-semibold">Contacts</summary>
        <div class="mt-4 space-y-4">
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div><dt>Contact verification</dt><dd class="font-semibold">{{ $cached['contact_status']['pending_verification'] === null ? 'Not confirmed' : ($cached['contact_status']['pending_verification'] ? 'Waiting for registrar verification' : 'Nothing waiting') }}</dd></div>
                <div><dt>60-day transfer restriction after a registrant change</dt><dd class="font-semibold">{{ $cached['contact_status']['transfer_locked'] === null ? 'Not confirmed' : ($cached['contact_status']['transfer_locked'] ? 'On' : 'Off') }}</dd></div>
            </dl>
            <p class="text-sm">Each form replaces one whole contact. The other contacts stay as they are. A registrant change can need email verification and can block transfers to another registrar for 60 days.</p>
            @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => 'contacts', 'label' => 'Contacts help'])
            @if ($available('contacts'))
                @foreach (['registrant' => 'Registrant', 'admin' => 'Administrative', 'tech' => 'Technical', 'auxbilling' => 'Billing'] as $type => $label)
                    <details class="border border-neutral p-4 rounded-lg" @if (old('_enom_form') === 'domain:'.$domainId.':contacts' && old('contact_type') === $type) open @endif>
                        <summary class="font-semibold">{{ $label }} contact</summary>
                        <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'contacts']) }}" data-enom-validate class="mt-4 space-y-4">
                            @include('enom::partials.validation-feedback')
                            @include('enom::partials.form-key', ['action' => 'contacts'])
                            <input type="hidden" name="contact_type" value="{{ $type }}">
                            @include('enom::partials.contact', ['contact' => $cached['contacts'][$type] ?? [], 'contactId' => $type, 'contactForm' => 'domain:'.$domainId.':contacts', 'contactType' => $type])
                            @include('enom::partials.confirm', ['confirmation' => 'Save this whole '.strtolower($label).' contact and accept any verification the registrar needs.'])
                            <div class="max-w-[300px]"><x-button.primary type="submit">Save {{ strtolower($label) }} contact</x-button.primary></div>
                        </form>
                    </details>
                @endforeach
            @else
                <p class="text-sm">{{ $unavailable('Contact changes are not available right now. Refresh status to check again.') }}</p>
            @endif
        </div>
    </details>

    <details class="bg-background-secondary border border-neutral p-5 rounded-lg">
        <summary class="text-xl font-semibold">Contact privacy</summary>
        <div class="mt-4 space-y-4">
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div><dt>Privacy subscription</dt><dd class="font-semibold">{{ $cached['privacy']['exists'] === null ? 'Not confirmed' : ($cached['privacy']['exists'] ? 'Purchased' : 'Not purchased') }}</dd></div>
                <div><dt>Privacy</dt><dd class="font-semibold">{{ $cached['privacy']['enabled'] === null ? 'Not confirmed' : ($cached['privacy']['enabled'] ? 'On' : 'Off') }}</dd></div>
            </dl>
            <p class="text-sm">Privacy is bought one year at a time through an invoice and does not renew by itself. Turning privacy off keeps your paid subscription.</p>
            @php $privacyShown = false; @endphp
            @foreach (['purchase' => 'Buy contact privacy, 1 year', 'renew' => 'Renew contact privacy, 1 year'] as $privacyAction => $privacyLabel)
                @if ($available('privacy_'.$privacyAction))
                    @php $privacyShown = true; @endphp
                    <form method="POST" action="{{ route('enom.domains.privacy', [$domainId, $privacyAction]) }}" class="space-y-2">
                        @include('enom::partials.form-key', ['action' => 'privacy_'.$privacyAction])
                        <p class="text-sm">Creates an invoice. Privacy is requested from the registrar after the invoice is paid in full.</p>
                        @include('enom::partials.confirm', ['confirmation' => 'Create an invoice for one year of contact privacy.'])
                        <div class="max-w-[300px]"><x-button.secondary type="submit">{{ $privacyLabel }}</x-button.secondary></div>
                    </form>
                @endif
            @endforeach
            @if ($cached['privacy']['exists'] === true && $cached['privacy']['enabled'] !== null)
                @php $privacyToggle = $cached['privacy']['enabled'] ? 'privacy_disable' : 'privacy_enable'; @endphp
                @if ($available($privacyToggle))
                    @php $privacyShown = true; @endphp
                    <form method="POST" action="{{ route('enom.domains.manage', [$domainId, $privacyToggle]) }}" class="space-y-2">
                        @include('enom::partials.form-key', ['action' => $privacyToggle])
                        @include('enom::partials.confirm', ['confirmation' => $cached['privacy']['enabled'] ? 'Turn off privacy for '.$domainName.'. The subscription stays paid.' : 'Turn on privacy for '.$domainName.'.'])
                        <div class="max-w-[300px]"><x-button.secondary type="submit">{{ $cached['privacy']['enabled'] ? 'Turn off privacy' : 'Turn on privacy' }}</x-button.secondary></div>
                    </form>
                @endif
            @endif
            @if (!$privacyShown)
                <p class="text-sm">{{ $unavailable('Privacy is not available for this domain right now.') }}</p>
            @endif
        </div>
    </details>

    <details class="bg-background-secondary border border-neutral p-5 rounded-lg">
        <summary class="text-xl font-semibold">Transfer lock and code</summary>
        <div class="mt-4 space-y-4">
            <p>Registrar lock: <strong>{{ $cached['locked'] === null ? 'Not confirmed' : ($cached['locked'] ? 'On' : 'Off') }}</strong></p>
            <p class="text-sm">The registrar lock is separate from the 60-day restriction after a registrant change. Turning the lock off does not by itself make the domain ready to transfer.</p>
            @if ($available('lock') && $cached['locked'] !== null)
                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'lock']) }}" class="space-y-2">
                    @include('enom::partials.form-key', ['action' => 'lock'])
                    <input type="hidden" name="locked" value="{{ $cached['locked'] ? '0' : '1' }}">
                    @include('enom::partials.confirm', ['confirmation' => $cached['locked'] ? 'Turn off the registrar lock for '.$domainName.'.' : 'Turn on the registrar lock for '.$domainName.'.'])
                    <div class="max-w-[300px]"><x-button.secondary type="submit">{{ $cached['locked'] ? 'Turn off registrar lock' : 'Turn on registrar lock' }}</x-button.secondary></div>
                </form>
            @elseif ($cached['locked'] === null)
                <p class="text-sm">{{ $unavailable('The lock status is not confirmed. Refresh status to check it.') }}</p>
            @else
                <p class="text-sm">{{ $unavailable('Lock changes are not available right now. Refresh status to check again.') }}</p>
            @endif
            @if ($available('epp_sync'))
                <form method="POST" action="{{ route('enom.domains.manage', [$domainId, 'epp_sync']) }}" class="border-t border-neutral pt-4 space-y-2">
                    @include('enom::partials.form-key', ['action' => 'epp_sync'])
                    <h3 class="font-semibold">Sync transfer code</h3>
                    <p class="text-sm">Asks the registrar to sync this domain's transfer code with the registry. This does not show or email the code.</p>
                    @include('enom::partials.confirm', ['confirmation' => 'Sync the transfer code without showing or emailing it.'])
                    <div class="max-w-[300px]"><x-button.secondary type="submit">Sync transfer code</x-button.secondary></div>
                </form>
            @else
                <p class="text-sm border-t border-neutral pt-4">{{ $unavailable('Transfer code sync is not available for this domain.') }}</p>
            @endif
            <p class="text-sm">Viewing or emailing your transfer code is not available in this portal. Contact support if you plan to move this domain to another registrar.</p>
            @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => 'transfers', 'label' => 'Transfer code help'])
        </div>
    </details>

    <section class="border border-neutral p-5 rounded-lg space-y-4" aria-labelledby="enom-requests-heading">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 id="enom-requests-heading" class="text-xl font-semibold">Recent requests</h2>
            @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => 'statuses', 'label' => 'What statuses mean'])
        </div>
        @if (count($snapshot['operations']))
            <div class="overflow-x-auto">
                <table class="enom-table w-full text-sm">
                    <caption class="sr-only">Recent registrar requests for {{ $domainName }}</caption>
                    <thead>
                        <tr class="border-b border-neutral">
                            <th scope="col" class="text-left p-2">Request</th>
                            <th scope="col" class="text-left p-2">Status</th>
                            <th scope="col" class="text-left p-2">What it means</th>
                            <th scope="col" class="text-left p-2">Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshot['operations'] as $operation)
                            <tr class="border-b border-neutral">
                                <td class="p-2">
                                    {{ $present::action($operation['action'] ?? null) }}
                                    @if (!empty($operation['invoice_id']))<br><a class="enom-link underline" href="{{ route('invoices.show', $operation['invoice_id']) }}">Invoice #{{ $operation['invoice_id'] }}</a>@endif
                                </td>
                                <td class="p-2 font-semibold">{{ $present::state($operation['state'] ?? null, $operation['outcome'] ?? null) }}</td>
                                <td class="p-2">{{ $present::stateMeaning($operation['state'] ?? null, $operation['outcome'] ?? null, $operation['action'] ?? null) }}</td>
                                <td class="p-2 whitespace-nowrap">{{ $present::moment($operation['created_at'] ?? null) ?? 'Not recorded' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm">No requests yet.</p>
        @endif
    </section>

    @include('enom::partials.sandbox')
</div>
@include('enom::partials.form-validation')
@include('enom::partials.copy-script')
@endcomponent
