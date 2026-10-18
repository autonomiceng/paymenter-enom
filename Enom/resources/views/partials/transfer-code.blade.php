{{-- Body of the domain page status card while a transfer still accepts a code (before or after payment). --}}
@php
    $transfer = \Paymenter\Extensions\Servers\Enom\Models\Operation::where('domain_id', $domain->id)->where('action', 'transfer')->latest('id')->first();
    $transferOwner = auth()->id() && (int) auth()->id() === (int) $domain->user_id;
    $transferOpen = $transfer && \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::codeRejection($transfer, false) === null;
    $transferDeadline = $transfer ? \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::deadline($transfer) : null;
    $transferReceived = $transfer && !app(\Paymenter\Extensions\Servers\Enom\Services\TransferOrders::class)->needsCode($transfer);
    $transferPaid = $transfer && \App\Models\Invoice::whereKey($transfer->invoice_id)->value('status') === \App\Models\Invoice::STATUS_PAID;
    $transferAmount = $transfer ? \Paymenter\Extensions\Servers\Enom\Http\Controllers\DomainController::money($transfer->amount, $transfer->currency ?: 'USD') : null;
    $transferAccepting = $transfer && \Paymenter\Extensions\Servers\Enom\Services\TransferOrders::codeRejection($transfer) === null;
    // Paid with a code saved: nothing more to do, so changing the code is tucked away.
    $transferChange = $transferReceived && $transferPaid;
@endphp
@if ($transferOwner && $transferOpen)
<div id="transfer-code">
    @if ($transferAccepting)
        @if (!$transferChange)<p>Your current registrar gives you this code. It’s also called an auth code or EPP code.</p>@endif
        @if ($transferReceived)
            <p class="enom-small" style="margin-top:{{ $transferChange ? '1rem' : '.5rem' }}"><strong>Code saved.</strong> {{ $transferPaid ? 'You can change it until the transfer is sent.' : 'The transfer starts once the invoice is paid.' }}</p>
        @endif
        @if ($transferChange)<details class="enom-more" style="margin-top:.25rem" @if ($errors->has('transfer_code')) open @endif><summary>Change code</summary>@endif
        <form style="margin-top:{{ $transferChange ? '.5rem' : '1rem' }}" method="post" action="{{ route('enom.domains.transfer-code', $domain->id) }}" autocomplete="off">
            @csrf
            <input type="hidden" name="_request_key" value="{{ \Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue(request(), 'domain:'.$domain->id.':transfer_code') }}">
            <label for="transfer-code-input" class="enom-label">{{ $transferChange ? 'New transfer code' : ($transferReceived ? 'Change code' : 'Transfer code') }}</label>
            <input id="transfer-code-input" name="transfer_code" class="enom-input" type="text" required maxlength="512" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="transfer-code-hint{{ $errors->has('transfer_code') ? ' transfer-code-error' : '' }}" @if ($errors->has('transfer_code')) aria-invalid="true" @endif>
            <p id="transfer-code-hint" class="enom-hint">Paste it exactly as given. Codes are case-sensitive.</p>
            @error('transfer_code')<p id="transfer-code-error" class="enom-error" role="alert">{{ $message }}</p>@enderror
            <div class="enom-row" style="margin-top:1rem">
                <button type="submit" class="enom-btn enom-btn--primary">{{ $transferChange ? 'Save new code' : ($transferPaid ? 'Start transfer' : 'Save code') }}</button>
                @if (!$transferPaid && $transfer->invoice_id)
                    <a class="enom-btn enom-btn--secondary" href="{{ route('invoices.show', $transfer->invoice_id) }}">Pay invoice</a>
                @endif
            </div>
        </form>
        @if ($transferChange)</details>@else
        <details class="enom-more" style="margin-top:1rem"><summary>Where do I find it?</summary><p>Sign in at your current registrar and open {{ $domain->domain }}’s settings. Look for “Transfer out”, “Auth code” or “EPP code”. Some registrars email the code to the owner. While you’re there, turn off the transfer lock.</p></details>
        @endif
        @if (!$transferPaid)
            <p style="margin-top:1rem">You can enter the code now. The transfer starts once the invoice is paid.</p>
        @elseif (!$transferReceived)
            <p style="margin-top:1rem">Enter it by <strong>@include('enom::partials.moment', ['at' => $transferDeadline->utc(), 'style' => 'zone'])</strong>. If we don’t have it by then, we’ll cancel the transfer and add {{ $transferAmount }} to your account credit.</p>
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
