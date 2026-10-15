@component('layouts.app', ['title' => 'Domain order', 'sidebar' => true])
@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $isTransfer = $quote['action'] === 'transfer';
@endphp
<div class="enom-ui container mt-14 space-y-6">
    @include('enom::partials.styles')
    <a class="enom-link underline" href="{{ route('enom.domains.index') }}">Back to domains</a>
    <h1 class="text-2xl font-semibold">{{ $isTransfer ? 'Transfer' : 'Register' }} {{ $quote['domain'] }}</h1>
    @include('enom::partials.messages')
    <section class="border border-neutral p-5 rounded-lg space-y-2" aria-labelledby="enom-price-heading">
        <h2 id="enom-price-heading" class="sr-only">Price</h2>
        <p class="text-xl font-semibold">{{ $isTransfer ? 'Transfer' : 'Registration' }}, one year: {{ $quote['currency'] }} {{ $quote['amount'] }}</p>
        <p>Renews at {{ $quote['currency'] }} {{ $quote['renewal_amount'] }} per year.</p>
        <p class="text-sm">Quote expires {{ $present::moment($quote['expires_at'] ?? null) ?? 'soon' }}. Availability can change before you pay. The invoice shows tax and the final total.</p>
    </section>
    @if ($isTransfer)
        <section class="border border-neutral p-5 rounded-lg space-y-2" aria-labelledby="enom-transfer-heading">
            <h2 id="enom-transfer-heading" class="font-semibold">Before you transfer</h2>
            <ol class="enom-steps text-sm space-y-1">
                <li>Turn off the registrar lock at your current registrar.</li>
                <li>Get the transfer code, also called the auth code, from your current registrar.</li>
            </ol>
            <p class="text-sm">A transfer can stay in progress for several days while it is approved.</p>
        </section>
    @endif
    <form method="POST" action="{{ route('enom.domains.place') }}" data-enom-validate class="bg-background-secondary border border-neutral p-5 rounded-lg space-y-5">
        @include('enom::partials.validation-feedback')
        @csrf
        <input type="hidden" name="_request_key" value="{{ $orderKey }}">
        <input type="hidden" name="intent_id" value="{{ $quote['intent_id'] }}">
        <h2 class="text-xl font-semibold">Registrant contact</h2>
        <p class="text-sm">We use these details for all four domain contacts: registrant, administrative, technical and billing. You can review each one on the domain page later. Contact changes can need verification.</p>
        @include('enom::partials.contact', ['contact' => [], 'contactId' => 'order-contact', 'contactForm' => 'order:'.$quote['intent_id']])
        @if ($isTransfer)
            <x-form.input name="epp" value="" label="Transfer code (auth code)" type="password" required maxlength="255" autocomplete="new-password" aria-describedby="epp-hint" />
            <p id="epp-hint" class="text-xs">From your current registrar. It is stored encrypted only until the transfer finishes. We clear this field after any error, so enter it again before you continue.</p>
            <p data-enom-client-error data-enom-client-for="epp" class="text-red-500 text-xs mt-1" hidden></p>
        @endif
        @include('enom::partials.confirm', ['confirmation' => 'I confirm the domain, contact details and one-year charge.'])
        <p class="text-sm">Continuing creates an invoice due in 7 days. We do not email it. Paying it in full starts the registrar request, and your domain page shows when the registrar confirms it.</p>
        <div class="max-w-[300px]"><x-button.primary type="submit">Continue to invoice</x-button.primary></div>
        @include('enom::partials.help-link', ['route' => 'enom.help', 'topic' => $isTransfer ? 'ordering' : 'payment', 'label' => $isTransfer ? 'Transfer help' : 'Payment help'])
    </form>
    @include('enom::partials.sandbox')
</div>
@include('enom::partials.form-validation')
@endcomponent
