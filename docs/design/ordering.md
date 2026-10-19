# Ordering

Targets Paymenter 1.5.9. The schema is fresh-install only. [Catalogue and pricing](catalogue-and-pricing.md) owns exact-term costs, sale rules and native plan publication; [lifecycle](domain-lifecycle.md) owns renewals and provider outcomes.

## Native checkout

Products use `enom_catalogue=1`, `enom_tld_id` and `enom_action` markers. Search and transfer links use `products.checkout`, with an available `plan` and `config[domain]`. Hidden products remain accessible by direct URL. The native picker lists ending-wide plans; name-specific minimum and maximum terms are validated without changing shared plans.

Checkout fields are flat: canonical lowercase ASCII `domain` and `enom_registry_<schema name>`. A bare label gets the product ending. Labels must contain 2–63 characters; mismatched endings, subdomains, IDNs and premium names fail. Conditional registry fields are validated against the complete configuration. Unknown and inactive fields are stripped.

Cart add/edit and Order Creating listeners recheck availability, terms, current prices, quantity one, USD, ownership and duplicate registrar names. Hosting may use the same domain. Every domain line must remain positive after coupons; a zero or negative domain line rolls back the whole checkout, including a mixed order.

The native cart creates one Order and one Invoice for the checkout, with a Service for each line. An InvoiceItem Creating listener gives initial domain lines the description `Register example.com (3 years)` or `Transfer example.com (adds 1 year)`. A synchronous InvoiceItem Created listener binds inside checkout's transaction. Failure propagates and rolls back every new obligation, including account binding. No provider write occurs at checkout.

Binding validates product markers and plan ownership, locks product then ending, creates the unique Domain and initial Operation, snapshots contacts and registry details, and freezes purchased plan, years, currency and discounted invoice amount. Replaying the same binding returns the same Operation; conflicts fail closed.

The Service label is the full domain. Its plan becomes that product's one-year billing plan, its price becomes the tax-adjusted annual renewal amount, and its managed expiry remains null. If one-year registration is unavailable, the annual plan exists without a sale price. The initial Operation keeps the purchased term separately. Keep `coupon_id` for native usage limits, but do not apply it to renewal prices or invoices.

## Payment and registrar requests

Funding requires the matching owned Service and invoice line, quantity one, an unchanged positive frozen amount, matching currency, paid invoice status, no remaining debt, and successful transaction **net sum covering the entire invoice**. Negative successful adjustments count; overpayments are accepted. A line amount need not equal the annual Service price or the undiscounted sale price.

Native activation does not establish registrar success. Payment queues only ready work. Transfers without a code wait in `awaiting_code`; invalid contacts hold with `contact_details_required`. Recheck contacts, code, current action, registry fields, terms, account and funding before claim and dispatch. Registration readback must confirm the purchased years, not merely a successful acknowledgement.

Contacts come from the account's name, email, company, address and phone properties. Copy all four registrar roles once. Convert country labels to ISO codes and validate required state and phone fields. Later edits do not replace a valid snapshot. An owner must explicitly use **Use updated account details** after fixing an invalid account; staff with `admin.enom.manage` may use the same explicit recovery from Registrar requests. Staff recovery records the actor, reason, time and previous hold in encrypted policy. It requires a proven-unsent initial contact hold, validates the owner’s current contacts and replaces only contacts before the ordinary initial gate. Funding, ownership, registrar account, transfer code and original paid deadline remain binding. The customer route remains strictly owner-only.

Transfer codes are submitted through an owner-only POST on the domain page, before or after payment. Codes never enter checkout URLs or Service properties. A verified paid transition starts the 30-day deadline. Missing-code transfers that are proven unsent are cancelled and refunded once to account credit. Send one native email after payment without a code and one seven days before the deadline. Encrypted policy markers suppress replays. Disabled templates and rendering failures leave sends retryable; templates receive only the domain, page link, deadline and this line’s credit amount. Codes never enter emails. Submitted uncertain requests are reconciled rather than refunded automatically.

Registration uses registrar default DNS. Confirmation persists a separate idempotent nameserver request with its encrypted payload in the same transaction, before purging the registration snapshot. Dispatch its job after commit; the sweep recovers a persisted request if dispatch is interrupted. Its failure cannot undo registration or refund it. Registration-time nameserver requirements such as `.de` remain unsupported.

Native unpaid cleanup uses the configured threshold and can cancel a shared pending invoice. Observe cancelled Services and Invoices, retire unsent initial work and purge private payloads. Late payment cannot revive it. Preserve every Domain and request as history. A definitely rejected or definitely unsent cancelled initial attempt releases its unique current-name reservation at a new checkout. Active or uncertain requests still block a reorder.

The extension ships no hosting upsell or native cart-field display. Those require the custom theme described in [the theme handoff](0.5-theme-handoff.md). No Paymenter core or stock theme patch is included.

Sale flags, allowlist changes and sale holds apply to cart and checkout admission only. Paid initial requests still pass funding, ownership, contact, code/deadline, code-level ending support, registry/name-term and exact wholesale cost-cap checks before dispatch. Missing sale prices do not revoke paid fulfillment.

A committed contact-only correction at or after the original transfer code deadline keeps a
valid saved code, paid time, deadline and funding. It holds with `transfer_setup_review_required`
and never enqueues execution. Before the deadline, ordinary ready work still resumes after
commit. Payment callbacks and duplicate workers cannot release the late staff review gate.
