# Domain lifecycle

Targets Paymenter 1.5.9. [Ordering](ordering.md) owns initial native-cart binding and transfer-code deadlines; [catalogue and pricing](catalogue-and-pricing.md) owns prices and product publication.

## Managed renewal

Every extension-created domain has one managed native Service with null native expiry. Confirmed registrar expiry is the renewal anchor. Native billing dates never drive renewal and are kept null. Only the assigned owner, service, server and registrar account can authorize work.

Scheduled invoices use one-year terms. Issue and due dates default to 21 and 7 days before confirmed expiry. Manual renewal supports 1–10 years, bounded by registry limits and the maximum future expiry. Grace renewal is one year, needs provider support and defaults off. Cancellation disables renewal preference; unresolved requests block competing obligations.

A paid request freezes its invoice item, amount, currency, owner, service, server, term, account fingerprint, authority generation and confirmed revision. Funding requires real successful net transactions covering the whole invoice. A staff-free renewal instead freezes explicit complimentary authority and a cost cap; it creates no customer invoice. Ordinary zero-price obligations are held for review. Retained invoices and request evidence cannot be rewritten by later pricing changes.

Fresh renewal admission checks account membership, domain anchor, unchanged expiry, registrar hold, ordinary-domain status, extension limits and provider auto-renew being off. Complete quotes need sufficient registrar balance. Complimentary and grace renewals require complete USD quotes within their frozen limits; grace also needs positive grace eligibility. An ordinary paid pre-expiry quote may remain unknown; missing cost is never invented as zero.

Initial orders retain the exact-term wholesale cap against the paid retail amount. Registration readback must confirm the purchased years. Confirmed completion leaves native expiry null and records actual costs. A complimentary overrun pauses further automatic spending.

## Claims, uncertainty and refunds

Every execution uses a claim token and final dispatch seal. A stale worker cannot publish or send under a newer claim. Presence of a dispatch seal, execution evidence or provider order ID means a request is potentially sent, including null-valued evidence keys. An interrupted proven-unsent claim becomes held; anything possibly sent becomes uncertain. State labels cannot erase evidence or authorize replay.

Pending and uncertain requests are reconciled with provider reads. Read-only observation records freshness and drift without changing confirmed billing authority. Staff provider disposition records an explicit outcome with retained history; it does not resend the original request. Customer projections use allowlists that exclude credentials, private payloads and costs.

A definitely rejected paid registration, transfer, renewal or privacy request refunds its validated line amount once to Paymenter account credit. The paid invoice and transactions remain intact. Missed credits are retried. Uncertain requests never refund automatically. Staff **Refund as credit** is limited to held, proven-unsent paid requests and requires both `admin.enom.manage` and native invoice-update permission. It closes the request and records the actor and reason. Complimentary requests have no customer payment to refund.

## Management and registry details

Owned active domains support nameservers, all four contact roles, registrar lock, privacy and child nameservers. Changes are confirmed by readback; a provider acknowledgement alone is insufficient. DNS record, SRV and DNSSEC tools appear only when enabled. Nameserver and DNS changes preserve their validated payloads and byte-sensitive values.

Incoming registry details use the normalized `GetExtAttributes` schema. Native checkout collects choice-list and free-text fields, including conditional child fields. Server validation strips unknown and inactive fields; only supported fields reach Purchase or TP_CreateOrder. Registration-time unsupported requirements remain blocked.

Glue hosts must be beneath the owned domain and have one public IPv4 or IPv6 address. Private, shared and documentation ranges are refused. RegisterNameServer, UpdateNameServer and DeleteNameServer are confirmed through CheckNSStatus. Glue operations leave the registrar lock unchanged; Enom may require the owner to unlock first.

Enom cannot return an outgoing auth code for display. `SynchAuthInfo EmailEPP=True` requests registrar email to the registrant, with a 10-minute cooldown and masked destination. Acceptance does not establish delivery. Incoming transfer codes stay encrypted on the request and never enter URLs, Service properties or emails.

ID Protect purchases and renewals always use separate invoice lines and server-only pricing. Renewal needs confirmed privacy expiry. Privacy readback and renewal do not set native Service expiry.

Calendar dates display without timezone conversion. Event timestamps use viewer-local time through `<time datetime>`.

## Reminders

Native editable templates `enom_domain_expiring` and `enom_domain_expired` respect client notification preferences. Defaults are 60, 30, 7 and 1 days before expiry, and 3 days after. Claims deduplicate each domain, expiry cycle and offset; failures thrown during sending release the claim for a retry within the catch-up window.

Reminders require a matching active Service and an active, expired or grace domain. Open invoices show payment guidance. Domains needing nothing from the client get only the 30-day notice; an already queued or completed renewal for that expiry suppresses reminders. Renewal-off notices explain expiry and link to renewal. Missed offsets have a two-day catch-up window; newer due notices supersede older ones.

A process crash after claiming and before sending can lose a reminder. Reminder claims lock the domain, not the native Service row. These are retained limitations. Transfer-code emails use their separate durable enqueue contract in [ordering](ordering.md).
