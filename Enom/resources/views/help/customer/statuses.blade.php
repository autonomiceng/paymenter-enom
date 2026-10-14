@php
    $present = \Paymenter\Extensions\Servers\Enom\Services\DomainPresentation::class;
    $rows = [['awaiting_payment', null], ['queued', null], ['running', null], ['pending', null], ['uncertain', null],
        ['held', null], ['succeeded', null], ['succeeded', 'acknowledged'], ['rejected', null], ['rejected', 'cancelled_unpaid'], ['disposed', null]];
@endphp
<p>Each change you make, and each registration, transfer or renewal, is a request. The domain page lists recent requests with one of these statuses.</p>
<div class="overflow-x-auto">
    <table class="enom-table w-full">
        <caption class="sr-only">Request statuses and what they mean</caption>
        <thead><tr class="border-b border-neutral"><th scope="col" class="text-left p-2">Status</th><th scope="col" class="text-left p-2">What it means</th></tr></thead>
        <tbody>
            @foreach ($rows as [$rowState, $rowOutcome])
                <tr class="border-b border-neutral">
                    <td class="p-2 font-semibold whitespace-nowrap">{{ $present::state($rowState, $rowOutcome) }}</td>
                    <td class="p-2">{{ $present::stateMeaning($rowState, $rowOutcome) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<h2>A paid invoice is not a renewed domain</h2>
<p>Paying starts the request. The domain renews, or the change takes effect, only when the request shows Completed. Renewal invoice rows on the domain page show both the invoice status and the registrar request status.</p>
<h2>New registrations and transfers</h2>
<p>A new domain shows Awaiting payment until its invoice is paid in full. After payment it shows waiting to be sent, then in progress while the registrar works on it. Transfers can take several days. The domain shows Active only after the registrar confirms it.</p>
<p>If a new domain shows needs review or status not confirmed, contact support. Do not order the domain again.</p>
<h2>Needs review</h2>
<p>If a request needs review, other changes to that domain pause until support checks it. Do not submit the same change again. Your invoices stay available.</p>
