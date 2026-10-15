<h2>Records do not change</h2>
<p>Completed, declined, retired and closed requests keep their recorded state, invoice and funding. Nothing on these pages rewrites them. Never resend a request to fix it. A repeat of the same request returns the same record.</p>
<h2>Held renewals</h2>
<p>A held request was never sent to the registrar. Resume verified unsent renewal appears only for a managed renewal that is fully funded by a paid invoice or a current sponsorship, was never sent, still matches the confirmed expiry, and has no other open renewal for the domain. Resuming queues the same request and invoice. No new invoice or payment is created, and the worker checks everything again before any send.</p>
<h2>Pending and uncertain requests</h2>
<p>Reconcile reads the registrar order or current data and never repeats the request. A queued request has nothing to reconcile until it is sent.</p>
<h2>Post-send provider facts</h2>
<p>For a managed renewal that was sent but could not be confirmed, staff can record authenticated provider facts. If the facts are fresh and match, a disposition closes the request, keeps manual financial review, and pauses all writes and renewal billing for the domain until a separate binding review.</p>
<h2>Payments on retired renewal invoices</h2>
<p>When a customer turns renewal off, clean unpaid renewal invoices are cancelled. If a transaction later appears on one of them, it is listed on Enom administration. That payment never authorizes a renewal. Handle refunds or credit as a separate financial review.</p>
