{{-- Body of the domain page status card while a transfer still accepts a code (before or after payment). --}}
@php
    $transfer = \Paymenter\Extensions\Servers\Enom\Models\Operation::where('domain_id', $domain->id)->where('action', 'transfer')->latest('id')->first();
    $transferOwner = auth()->id() && (int) auth()->id() === (int) $domain->user_id;
    $transferOpen = $transfer && in_array($transfer->state, ['awaiting_payment', 'awaiting_code', 'queued', 'held'], true)
        && \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::unsent($transfer) && $domain->status === 'transfer_pending';
    $transferDeadline = $transfer ? \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($transfer) : null;
    $transferReceived = $transfer && !app(\Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class)->needsCode($transfer);
    $transferPaid = $transfer && \App\Models\Invoice::whereKey($transfer->invoice_id)->value('status') === \App\Models\Invoice::STATUS_PAID;
    $transferAmount = $transfer ? \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::money($transfer->amount, $transfer->currency ?: 'USD') : null;
    $transferAccepting = (!$transferDeadline || $transferDeadline->isFuture()) && (!$transferPaid || $transferDeadline);
@endphp
@if ($transferOwner && $transferOpen)
<div id="transfer-code">
    @if ($transferAccepting)
        <p>Your current registrar gives you this code. It’s also called an auth code or EPP code.</p>
        @if ($transferReceived)
            <p class="enom-small" style="margin-top:.5rem"><strong>Code saved.</strong> {{ $transferPaid ? 'You can change it until the transfer is sent.' : 'The transfer starts once the invoice is paid.' }}</p>
        @endif
        <form style="margin-top:1rem" method="post" action="{{ route('enom.domains.transfer-code', $domain->id) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="_request_key" value="{{ \Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue(request(), 'domain:'.$domain->id.':transfer_code') }}">
            <label for="transfer-code-input" class="enom-label">{{ $transferReceived ? 'Change code' : 'Transfer code' }}</label>
            <input id="transfer-code-input" name="transfer_code" class="enom-input" type="text" required maxlength="512" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="transfer-code-hint{{ $errors->has('transfer_code') ? ' transfer-code-error' : '' }}" @if ($errors->has('transfer_code')) aria-invalid="true" @endif>
            <p id="transfer-code-hint" class="enom-hint">Paste it exactly as given. Codes are case-sensitive.</p>
            @error('transfer_code')<p id="transfer-code-error" class="enom-error" role="alert">{{ $message }}</p>@enderror
            <div class="enom-row" style="margin-top:1rem">
                <button type="submit" class="enom-btn enom-btn--primary">{{ $transferPaid ? 'Start transfer' : 'Save code' }}</button>
                @if (!$transferPaid && $transfer->invoice_id)
                    <a class="enom-btn enom-btn--secondary" href="{{ route('invoices.show', $transfer->invoice_id) }}">Pay invoice</a>
                @endif
            </div>
        </form>
        <details class="enom-more" style="margin-top:1rem"><summary>Where do I find it?</summary><p>Sign in at your current registrar and open {{ $domain->domain }}’s settings. Look for “Transfer out”, “Auth code” or “EPP code”. Some registrars email the code to the owner. While you’re there, turn off the transfer lock.</p></details>
        @if (!$transferPaid)
            <p style="margin-top:1rem">You can enter the code now. The transfer starts once the invoice is paid.</p>
        @elseif (!$transferReceived)
            <p style="margin-top:1rem">Enter it by <strong><time datetime="{{ $transferDeadline->toIso8601String() }}">{{ $transferDeadline->format('M j, Y') }}</time></strong>. If we don’t have it by then, we’ll cancel the transfer and add {{ $transferAmount }} to your account credit.</p>
        @endif
        <p class="enom-small enom-muted" style="margin-top:.75rem">Next, your current registrar may email the domain owner to approve the transfer. Most transfers finish within 5 to 7 days. Your website and email keep working.</p>
        @if ($transfer->policy['transfer_lookup_unknown'] ?? false)
            <p class="enom-small enom-muted" style="margin-top:.5rem">We couldn’t check {{ $domain->domain }}’s transfer status in advance. If its current registrar refuses the transfer, you get the full amount back as account credit.</p>
        @endif
    @elseif ($transferPaid && !$transferDeadline)
        <p>We couldn’t confirm the payment date for this transfer. Contact support and we’ll sort it out.</p>
    @elseif (!$transferReceived)
        <p>The deadline for the transfer code has passed. We’ll cancel the transfer and add {{ $transferAmount }} to your account credit.</p>
    @endif
</div>
@endif
