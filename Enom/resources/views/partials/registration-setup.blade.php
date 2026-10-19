{{-- $part: 'hold' renders the status card body for an order waiting on account details, 'notice' the nameserver notice. --}}
@php
    $setupId = (int) $domain['id'];
    $setup = \Paymenter\Extensions\Servers\Enom\Services\RegistrationSetup::viewData($setupId);
    $part ??= null;
@endphp
@if ($setup['held'] && $part !== 'notice')
    <div id="registration-contacts">
        <p>The registry needs a full address and phone number for the domain owner.@if ($setup['fields']) Your account is missing: {{ implode(', ', $setup['fields']) }}.@endif</p>
        <p style="margin-top:.5rem">Add them to your account, then come back here and choose <strong>Use updated account details</strong>.</p>
        <div class="enom-row" style="margin-top:1rem">
            <a class="enom-btn enom-btn--secondary" href="{{ route('account') }}">Update your details</a>
            <form method="POST" action="{{ route('enom.domains.registration-contacts', $setupId) }}">
                @csrf
                <input type="hidden" name="_request_key" value="{{ \Paymenter\Extensions\Servers\Enom\Http\FormKeys::issue(request(), 'domain:'.$setupId.':registration-contacts') }}">
                <button class="enom-btn enom-btn--primary" type="submit">Use updated account details</button>
            </form>
        </div>
        <p class="enom-small enom-muted" style="margin-top:.75rem">Your updated details will be checked. The order may need staff review before it can continue.</p>
    </div>
@endif
@if ($setup['nameservers_failed'] && $part !== 'hold')
    <div class="enom-notice" role="status">
        <div><strong>Registered; nameservers need attention</strong>
            <p>Your domain is registered. The nameserver change could not be confirmed. Contact support for help.</p>
        </div>
    </div>
@endif
