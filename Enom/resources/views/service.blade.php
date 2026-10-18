{{-- Shown on the native service page. Management lives on the domain page. --}}
<div class="enom" style="max-width:none">
    @include('enom::partials.styles')
    <div class="enom-panel enom-section enom-row" style="justify-content:space-between">
        <div style="min-width:0">
            <p class="enom-domain" style="font-size:1.125rem">{{ $snapshot['domain'] }}</p>
            <div class="enom-dhead__meta" style="margin-top:.25rem">
                @include('enom::partials.badge', ['status' => $snapshot['status'] ?? null, 'progress' => $snapshot['progress'] ?? null])
                @if (!empty($snapshot['provider_expires_at']))<span>Expires <strong>@include('enom::partials.date', ['date' => $snapshot['provider_expires_at']])</strong></span>@endif
                @if (($snapshot['status'] ?? null) === 'active')<span>Auto-renew <strong>{{ ($snapshot['renewal_enabled'] ?? true) ? 'on' : 'off' }}</strong></span>@endif
            </div>
        </div>
        <a class="enom-btn enom-btn--primary" href="{{ route('enom.domains.show', $snapshot['id']).(($snapshot['status'] ?? null) === 'transfer_pending' ? '#transfer-code' : '') }}">{{ ($snapshot['progress']['label'] ?? null) === 'Needs your code' ? 'Enter transfer code' : 'Manage domain' }}</a>
    </div>
</div>
