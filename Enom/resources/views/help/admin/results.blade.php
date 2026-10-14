@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $billingCodes = ['not_due', 'invoiced', 'sponsored', 'sponsorship_review', 'review', 'grace_review', 'cancelled', 'cancellation_pending',
        'cancellation_review', 'invoice_deadline_missed', 'configuration_review', 'disabled', 'staff_review'];
@endphp
<h2>Registrar check results</h2>
<ul class="enom-list space-y-1">
    @foreach (['fresh', 'stale', 'unknown'] as $checkState)
        <li><span class="font-semibold">{{ $present::observationStatus($checkState) }}.</span>@if ($checkState === 'fresh') Every part of the last check succeeded within 24 hours.@elseif ($checkState === 'stale') The last check is older than 24 hours or did not complete.@else No successful check is recorded.@endif</li>
    @endforeach
</ul>
<p>A check only reads data. It never changes the confirmed expiry, the service, invoices or requests. If the registrar-reported expiry differs from the confirmed expiry, billing for the domain waits for review.</p>
<h2>Billing run results</h2>
<p>The daily billing run records one result per managed domain. Each domain page shows the latest one.</p>
<ul class="enom-list space-y-1">
    @foreach ($billingCodes as $billingCode)
        <li>{{ $present::billingResult($billingCode) }}</li>
    @endforeach
</ul>
