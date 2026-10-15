<h2>Nameservers</h2>
<p>Enter 2 to 12 hostnames, one per line. Use hostnames, not IP addresses. Saving replaces the whole list and moves DNS for the domain to those servers.</p>
@include('enom::partials.code-example', ['caption' => 'Two nameservers, one per line.', 'code' => "ns1.example.net\nns2.example.net"])
<h2>DNS records</h2>
<p>DNS records here work only while the domain uses Enom nameservers. Saving replaces every record in the list. Rows you remove or leave out are deleted.</p>
<ul class="enom-list space-y-1">
    <li>Use @ for the domain itself, or a name such as www.</li>
    <li>CNAME and MX targets need a final dot.</li>
    <li>Mail priority applies only to MX records.</li>
    <li>TXT values are saved exactly as you enter them, including spaces.</li>
    <li>The list can hold up to 50 records, and at least one must stay.</li>
    <li>TTL cannot be changed here.</li>
</ul>
@include('enom::partials.code-example', ['caption' => 'Common DNS records, one per row in the table.', 'code' => "Host: @     Type: A      Address: 192.0.2.10\nHost: www   Type: CNAME  Address: example.com.\nHost: @     Type: MX     Address: mail.example.com.   Mail priority: 10\nHost: @     Type: TXT    Address: example-verification=placeholder"])
<h2>SRV records</h2>
<p>Enter the service name without the leading underscore, choose TCP or UDP, and give a target with a final dot.</p>
@include('enom::partials.code-example', ['caption' => 'One SRV record for a SIP service.', 'code' => "Service: sip  Protocol: TCP  Priority: 10  Weight: 5  Port: 5060  Target: sip.example.com."])
<h2>DNSSEC</h2>
<p>A DS record publishes your DNS provider's signing key at the registry. It does not sign the zone. Copy the key tag, algorithm, digest type and digest from your DNS provider. DNSSEC changes are available only for some domains. If the DNSSEC section says changes are not available, contact support.</p>
<h2>After you save</h2>
<p>Each save is a request. It shows as Queued, then Completed once the registrar confirms the new records. Sandbox changes do not reach public DNS.</p>
