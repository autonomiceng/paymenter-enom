<h2>Issue and due days</h2>
<p>Both count back from the confirmed registrar expiry. Issue days are 2 to 365 and must be more than due days. Due days are 1 to 364. The server defaults are 21 and 7.</p>
<p>Changes apply to invoices that have not been issued yet. An issued invoice keeps its own due date, amount and locked policy.</p>
<h2>Zero retail price</h2>
<p>A domain with a zero retail price gets no customer invoice. The issue-day setting becomes the review start day, counted back from the confirmed registrar expiry. There is no invoice due date and no sponsorship deadline.</p>
<p>From the review start day, the daily billing run can request a sponsored renewal only under an approval for every future renewal. A one-time approval needs the manual Buy renewal with reseller funds action. See Sponsorship help.</p>
<p>The saved due-day offset and the customer credit choice apply only if the retail renewal price becomes positive and a customer invoice is issued.</p>
<h2>Late or missed invoices</h2>
<p>If the due day passes before an invoice is issued, the billing run does not issue a late invoice. It records Invoice deadline missed, and a new invoice needs staff review. No new invoice is created after the expiry date.</p>
<p>Allow paid renewals during grace permits eligible paid or explicitly sponsored renewals after expiry, subject to fresh registrar eligibility and complete cost checks. Test expired-domain renewal before enabling this setting. When off, the billing run cancels eligible unpaid invoices with no recorded transactions or registrar attempt after expiry.</p>
<h2>Customer credit</h2>
<p>With Pay from customer credit on, credit is applied when the invoice is issued, and only if the customer's credit balance covers the whole invoice. Otherwise the invoice stays unpaid.</p>
<h2>Emails</h2>
<p>Managed renewal invoices send the stock invoice email unless mail is disabled. Order invoices, privacy invoices and renewal invoices for unmanaged domains created from the domain pages are not emailed.</p>
