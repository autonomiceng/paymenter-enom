@php $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class; @endphp
<div class="enom-ui space-y-3 p-4">
    @include('enom::partials.styles')
    <h2 class="text-xl font-semibold">{{ $snapshot['domain'] }}</h2>
    <dl class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div><dt class="text-sm">Status</dt><dd class="font-semibold">{{ $snapshot['progress']['label'] ?? $present::progress($snapshot['status'] ?? null, null) }}</dd></div>
        <div><dt class="text-sm">Expires</dt><dd class="font-semibold">{{ $present::day($snapshot['provider_expires_at'] ?? null) ?? 'Not confirmed yet' }}</dd></div>
        <div><dt class="text-sm">Renewal</dt><dd class="font-semibold">{{ ($snapshot['renewal_enabled'] ?? true) ? 'On' : 'Off' }}</dd></div>
    </dl>
    <a class="enom-link underline" href="{{ route('enom.domains.show', $snapshot['id']) }}">Manage domain</a>
    @include('enom::partials.automation-info')
</div>
