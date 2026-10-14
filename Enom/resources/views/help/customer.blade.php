@component('layouts.app', ['title' => 'Domain help', 'sidebar' => true])
<div class="container mt-14 mb-10">
<div class="enom">
    @include('enom::partials.styles')
    <div>
        <a class="enom-back" href="{{ route('enom.domains.index') }}"><x-ri-arrow-left-s-line class="enom-i" aria-hidden="true" />Domains</a>
        <h1 class="enom-title">Domain help</h1>
    </div>
    <div class="enom-faq">
        <details open>
            <summary>How do I point my domain to my website?</summary>
            <div><p>Open the domain, go to <strong>Nameservers</strong>, and enter the nameservers your web host gave you. Changes can take up to 48 hours to reach everyone.</p></div>
        </details>
        <details>
            <summary>When am I charged for renewal?</summary>
            <div><p>With auto-renew on, we send a renewal invoice a few weeks before the domain expires. The domain renews for a year once it’s paid. The <strong>Overview</strong> tab shows the dates.</p>
                <p>Turn auto-renew off if you want the domain to expire. <strong>Renew now</strong> adds one or more years straight away, up to 10 years ahead.</p></div>
        </details>
        <details>
            <summary>Can I register a domain for more than a year?</summary>
            <div><p>Yes. Choose the term next to <strong>Register</strong>, from 1 to 10 years for most endings. The first year is at the registration price and each extra year at the renewal price. See <a class="enom-a" href="{{ route('enom.domains.pricing') }}">domain prices</a>.</p></div>
        </details>
        <details>
            <summary>What are child nameservers?</summary>
            <div><p>Nameservers named under your own domain, like ns1.example.com, need their IP address stored at the registry. Add them on the domain’s <strong>Nameservers</strong> tab, then use them as nameservers for any domain.</p></div>
        </details>
        <details>
            <summary>What happens if a registration fails?</summary>
            <div><p>If the registry refuses a paid registration, transfer or renewal, we add what you paid to your @if (\Illuminate\Support\Facades\Route::has('account.credits'))<a class="enom-a" href="{{ route('account.credits') }}">account credit</a>@else account credit @endif. It’s used automatically on your next invoice.</p></div>
        </details>
        <details>
            <summary>How do I move my domain to another registrar?</summary>
            <div><p>On the domain’s <strong>Transfer</strong> tab, turn off the transfer lock and email yourself the transfer code. Then start the transfer at the new registrar and enter the code.</p></div>
        </details>
        <details>
            <summary>How do I transfer a domain here?</summary>
            <div><p>Unlock the domain at your current registrar and get its transfer code. Then use <a class="enom-a" href="{{ route('enom.domains.transfer') }}">Transfer a domain</a>. Most transfers finish within 5 to 7 days and add a year to the expiry date.</p></div>
        </details>
        <details>
            <summary>Why is my domain “Awaiting payment”?</summary>
            <div><p>Registration and transfers start once the invoice is paid. Open the domain and choose <strong>Pay invoice</strong>.</p></div>
        </details>
        <details>
            <summary>Why do I need to verify my email?</summary>
            <div><p>Registries require the owner’s email to be confirmed after a new registration or an owner change. Follow the link in the verification email, or the domain can be suspended.</p></div>
        </details>
    </div>
</div>
</div>
@endcomponent
