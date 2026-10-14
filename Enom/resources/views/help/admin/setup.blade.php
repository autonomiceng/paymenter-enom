<h2>Safe order</h2>
<ol class="enom-steps space-y-2">
    <li>Create the Enom server in Paymenter. Leave the login ID and API token blank. Keep Sandbox only on and every other permission off.</li>
    <li>Open Edit server and enter the login ID and API token. Edit server stores them encrypted. Registrar requests refuse credentials that are not encrypted, which is why they are not entered on the create form.</li>
    <li>Create the domain product on this server. Keep it hidden, with one yearly recurring plan priced in USD, no setup fee, no configurable options and no upgrades. Set the .com ending and the registration and transfer prices. The yearly plan price is the renewal price. Customers order on the Domains page, not in the shop.</li>
    <li>Turn on Allow registrar reads. If domains are already mapped, open each one in Enom administration and use Check registrar now. An empty domain list is expected before the first registration.</li>
    <li>Review requests in Enom administration, especially anything under Needs review and Waiting.</li>
    <li>After reviewing setup and existing requests, enable Allow registrar changes for an authorized sandbox test. If the domain list is empty, place the first one-year .com test order from Domains. Check domains daily and Schedule domain renewal invoices have their own settings; review those before enabling them.</li>
</ol>
<h2>What Allow registrar changes does</h2>
<p>With it on, the worker considers every queued request, including older ones. A request is not sent just because the setting is on. It still waits while another request for the same domain is unresolved, and it must pass its own funding, eligibility and expiry checks first. Each request must pass its checks. Unresolved requests block later changes to the same domain. The held and reviewed readmission path applies to eligible managed renewals; other declined or uncertain requests need their own review.</p>
<h2>Settings that work together</h2>
<ul class="enom-list space-y-1">
    <li>Sandbox only must stay on. Turning it off stops every registrar request.</li>
    <li>Check domains daily also needs Allow registrar reads.</li>
    <li>Schedule domain renewal invoices issues invoices only while Allow registrar changes is also on. It can finish renewal cancellations on its own.</li>
    <li>Use registrar expiry for new domains applies only to orders placed after it is turned on. Existing domains are enrolled one at a time.</li>
    <li>Disabling the server prevents registrar changes. The saved Allow registrar changes setting remains available when the server is enabled again.</li>
</ul>
