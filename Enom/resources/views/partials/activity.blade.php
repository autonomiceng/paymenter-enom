@php
    $controller = \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::class;
    // A new registration is created on the registrar's default DNS, then a follow-up request sets the chosen nameservers.
    $registeredAt = collect($operations)->firstWhere('action', 'register')['created_at'] ?? null;
    $followUp = $registeredAt ? collect($operations)
        ->filter(fn ($op) => ($op['action'] ?? null) === 'nameservers' && !empty($op['created_at'])
            && \Carbon\CarbonImmutable::parse($op['created_at'])->gte(\Carbon\CarbonImmutable::parse($registeredAt))
            && \Carbon\CarbonImmutable::parse($op['created_at'])->diffInMinutes(\Carbon\CarbonImmutable::parse($registeredAt), true) <= 120)
        ->sortBy('id')->first()['id'] ?? null : null;
    $rows = collect($operations)->map(function ($operation) use ($controller, $followUp) {
        $row = $controller::activity($operation);
        if ($followUp !== null && ($operation['id'] ?? null) === $followUp) { $row[0] = 'Set nameservers'; }
        return [$operation, ...$row];
    });
    $states = $rows->pluck(2)->unique();
@endphp
<section class="enom-panel" aria-labelledby="enom-activity-heading">
    <div class="enom-section" style="padding-bottom:.25rem">
        <h2 id="enom-activity-heading" class="enom-h2">Activity</h2>
    </div>
    @if ($rows->isEmpty())
        <div class="enom-section" style="border-top:0"><p class="enom-small enom-muted">Nothing yet. Changes you make appear here.</p></div>
    @else
        <div class="enom-tablewrap">
            <table class="enom-tbl enom-stackable">
                <thead><tr><th scope="col">Change</th><th scope="col">Status</th><th scope="col">When</th><th scope="col" class="enom-end"><span class="enom-sr">Invoice</span></th></tr></thead>
                <tbody>
                    @foreach ($rows as [$operation, $what, $label, $tone])
                        <tr>
                            <td><span style="font-weight:600">{{ $what }}</span>
                                @if (is_array($operation['refund'] ?? null) && ($refunded = $controller::money($operation['refund']['amount'] ?? null, $operation['refund']['currency'] ?? 'USD')))
                                    <span class="enom-refund">Refunded {{ $refunded }} to your @if (\Illuminate\Support\Facades\Route::has('account.credits'))<a class="enom-a" href="{{ route('account.credits') }}">account credit</a>@else account credit @endif</span>
                                @endif
                            </td>
                            <td><span class="enom-badge enom-tone-{{ $tone }}">{{ $label }}</span></td>
                            <td class="enom-muted enom-nowrap">@include('enom::partials.moment', ['at' => $operation['created_at'] ?? null, 'fallback' => '—'])</td>
                            <td class="enom-end">@if (!empty($operation['invoice_id']))<a class="enom-a enom-small" href="{{ route('invoices.show', $operation['invoice_id']) }}">Invoice #{{ $operation['invoice_id'] }}</a>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($states->contains('In progress') || $states->contains('Being checked'))
            <div class="enom-section">
                <p class="enom-small enom-muted">
                    @if ($states->contains('In progress'))<strong>In progress</strong> means we’ve sent the change and are waiting for the registrar to confirm it. @endif
                    @if ($states->contains('Being checked'))<strong>Being checked</strong> means we couldn’t confirm the result yet, so our team is checking it. Please don’t submit it again.@endif
                </p>
            </div>
        @endif
    @endif
</section>
