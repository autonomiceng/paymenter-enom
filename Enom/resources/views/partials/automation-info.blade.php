@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $automation = $snapshot['automation'] ?? [];
    $renewalManaged = !empty($automation['managed']);
    $renewalScheduled = !empty($automation['enabled']);
    $renewalOn = ($snapshot['renewal_enabled'] ?? true) === true;
    $zeroRetail = ($snapshot['zero_retail'] ?? false) === true;
    $zeroManaged = $renewalManaged && $zeroRetail;
    $expiryText = $present::day($snapshot['provider_expires_at'] ?? null);
    $issueAt = $automation['calendar']['issue_at'] ?? null;
    $dueAt = $automation['calendar']['due_at'] ?? null;
    $driftProblem = ($automation['observation_problem'] ?? null) === 'Observed expiry differs from confirmed registrar expiry.';
    $pendingOrder = in_array($snapshot['status'] ?? null, ['registration_pending', 'transfer_pending'], true);
    $pendingKind = (is_array($snapshot['progress'] ?? null) ? ($snapshot['progress']['kind'] ?? null) : null) ?? (($snapshot['status'] ?? null) === 'transfer_pending' ? 'transfer' : 'registration');
    $hasInvoices = !empty($automation['invoices']);
@endphp
<div class="space-y-3">
    <p>
        @if ($pendingOrder && !$renewalOn)
            Renewal is off. If the {{ $pendingKind }} has not been sent to the registrar yet, it will not be sent while renewal is off. Contact support if it should go ahead.
        @elseif ($pendingOrder)
            Renewal settings apply after the registrar confirms the {{ $pendingKind }}. Until then there is no confirmed expiry date, so no renewal is scheduled.
        @elseif (!$renewalOn && $zeroManaged)
            Renewal is off. The operator will not start a new renewal for this domain.{{ $expiryText ? ' The expiry date on record is '.$expiryText.'.' : '' }}
        @elseif (!$renewalOn)
            Renewal is off. {{ $expiryText ? 'The domain expires on '.$expiryText.' unless you turn renewal back on.' : 'The domain will not be renewed unless you turn renewal back on.' }}
        @elseif ($zeroManaged)
            The operator handles renewal for this domain, so you will not get new renewal invoices for it.{{ $hasInvoices ? ' Earlier renewal invoices are listed below.' : '' }} Contact support if you have questions about its renewal.
        @elseif ($renewalManaged && $renewalScheduled && $issueAt && $dueAt && !$present::isPastDay($issueAt))
            We will send a renewal invoice on {{ $present::day($issueAt) }}, due {{ $present::day($dueAt) }}. The domain renews after the invoice is paid in full and the registrar confirms the new expiry date.
        @elseif ($renewalManaged && $renewalScheduled && $issueAt && $dueAt)
            The renewal invoice for this period was scheduled for {{ $present::day($issueAt) }}, due {{ $present::day($dueAt) }}. If it is not listed below, contact support.
        @elseif ($renewalManaged)
            Renewal invoices are not being scheduled right now. Contact support if the domain needs to renew{{ $expiryText ? ' before '.$expiryText : '' }}.
        @elseif (!empty($snapshot['billing_due_at']))
            This domain renews through its service billing. The billing due date is {{ $present::day($snapshot['billing_due_at']) }}. The domain renews after that invoice is paid in full and the registrar confirms the new expiry date.
        @else
            No billing date is set for this domain. Contact support if it needs to renew.
        @endif
    </p>
    @if ($renewalManaged && $driftProblem)
        <p class="enom-warning border border-neutral p-3 rounded-lg text-sm" role="note">The registrar reported a different expiry date from the one on record. Support will review it before any renewal.</p>
    @endif
    @if (!$pendingOrder || $hasInvoices)
        <h3 class="font-semibold">{{ $zeroManaged ? 'Earlier renewal invoices' : 'Renewal invoices' }}</h3>
        @if ($zeroManaged && $hasInvoices)
            <p class="text-sm">Earlier invoices keep their recorded dates, amounts and status.</p>
        @endif
        <p class="text-sm">Invoice payment and renewal confirmation are shown separately. A paid invoice does not mean the domain has renewed. Check the registrar request column for renewal confirmation.</p>
        @if ($hasInvoices)
            <div class="overflow-x-auto">
                <table class="enom-table w-full text-sm">
                    <caption class="sr-only">{{ $zeroManaged ? 'Earlier renewal invoices' : 'Renewal invoices' }} for {{ $snapshot['domain'] }}</caption>
                    <thead>
                        <tr class="border-b border-neutral">
                            <th scope="col" class="text-left p-2">Invoice</th>
                            <th scope="col" class="text-left p-2">Invoice status</th>
                            <th scope="col" class="text-left p-2">Registrar request</th>
                            <th scope="col" class="text-left p-2">Due</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($automation['invoices'] as $invoice)
                            <tr class="border-b border-neutral">
                                <td class="p-2"><a class="enom-link underline" href="{{ route('invoices.show', $invoice['id']) }}">Invoice #{{ $invoice['id'] }}</a></td>
                                <td class="p-2">{{ $present::invoiceStatus($invoice['status'] ?? null) }}</td>
                                <td class="p-2">{{ array_key_exists('request_state', $invoice) ? $present::state($invoice['request_state'], $invoice['request_outcome'] ?? null) : 'See recent requests' }}</td>
                                <td class="p-2">{{ $present::day($invoice['due_at'] ?? null) ?? 'Not set' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm">{{ $zeroManaged ? 'No earlier renewal invoices.' : 'No renewal invoices yet.' }}</p>
        @endif
    @endif
</div>
