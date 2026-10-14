# 0.4 registrar features

Extends [catalogue, pricing and domain management](catalogue-and-pricing.md).

## 1. Multi-year terms

- Registration: 1–10 years, within the ending's registry limits (`enom_tlds.min_years`/`max_years`, from `GetTLDDetails`; default 1–10). Price = registration price + renewal price × (years − 1). The first year is at the registration price because promotional first-year prices must not apply to later years.
- Manual renewal: 1–10 years at renewal price × years. Refused if the new expiry would be more than 10 years from today (registry rule). Automatic renewal invoices stay 1 year.
- Transfer: always adds 1 year (registry rule). No term choice.
- Endings with a minimum above 1 year (for example `.ai`) can now be enabled; their term picker starts at the minimum.
- The term is frozen on the quote, invoice line ("Register example.com (3 years)"), operation (`term`) and expected expiry.
- Complimentary requests compare the total quote for all years against the cost cap.

Contract: `DomainOrders::check(?User, string $name)` returns `min_years`, `max_years` and per-year prices. `DomainOrders::quote(User, $domain, $action, int $years)`. `DomainOrders::renew(User, int $domainId, ?string $nonce, int $years = 1)`. `DomainOrders::renewalOptions(User, int $domainId): array{years: list<int>, price_per_year: string}`.

## 2. Registry details (for example `.us`)

- When an ending is enabled, `GetExtAttributes` is stored on `enom_tlds.registry_fields` as a normalized schema: `[{name, label, required (bool), parent?, options: [{value, label, requires: [names]}], text (bool)}]`. Enabling now succeeds when every required attribute is a choice list or free text.
- The order form shows these fields for that ending. A child field appears only when the selected parent option requires it (`.us`: foreign entities must choose a country).
- The server validates against the stored schema and sends the values on Purchase/TP_CreateOrder as `name=value`.
- Contract: `Catalogue::attributesFor(Tld): array` (the schema). Payload key `registry` on `place()`: `place(User, $intent, $contacts, $epp, $nameservers, array $registry = [])`. Validation errors use `registry.<name>`.

## 3. Glue records (child nameservers)

- Hosts under the domain itself (`ns1.example.com`) with one public IPv4 or IPv6 address each. Documentation and private ranges are refused (the registry refuses them too).
- Actions: `glue_add` (`host`, `ip`), `glue_update` (`host`, `ip`), `glue_delete` (`host`). Enom commands are RegisterNameServer, UpdateNameServer and DeleteNameServer, confirmed by CheckNSStatus.
- Glue actions leave the registrar lock unchanged. Disable the lock if Enom requires it. Provider errors are shown to staff.
- The snapshot carries `glue: [{host, ip}]` (hosts the portal created or found by CheckNSStatus), refreshed with the domain.
- Contract: actions go through `DomainManager::request` / `StaffDomains::requestChange` like other changes. Capability `glue` = active domain.

## 4. Public domain search

- `/domains/search` works without logging in: same search UI, enabled endings and public (pre-tax) prices, labelled "plus tax where applicable". Clicking Register or Transfer asks a guest to log in or sign up, then returns them to the order with their choice kept.
- The JSON check endpoint for guests is rate limited per IP (30/min) with a short result cache. Logged-in clients keep their own limit and prices, including complimentary.
- Top navigation gets **Domains** for everyone, linking to the search.
- A public price list at `/domains/pricing` shows enabled endings with registration, renewal and transfer prices.
- Contract: `DomainOrders::check(?User $user, string $name)`; `Catalogue::publicPrices(): list<{tld, register, renew, transfer, min_years, max_years}>`.

## 5. Renewal reminder emails

- Native notification templates (editable in Paymenter → Notification Templates) are created on install/upgrade: `enom_domain_expiring` and `enom_domain_expired`.
- A daily run sends one reminder per domain per expiry cycle per offset. Offsets come from the server settings `reminder_days` (default `60,30,7,1`) and `expired_reminder_days` (default `3`). Sent offsets are stored on `enom_domains.reminders` so a cycle never repeats one.
- Content depends on the domain's state:
  - Renewal on and invoice open: pay the invoice by its due date.
  - Renewal on, paid or complimentary: renews automatically, no action needed. Complimentary and paid domains get only the 30-day reminder.
  - Renewal off: expires on the date unless renewed (link to renew).
  - After expiry: renew soon; the domain may stop working.
- Reminders go only for active domains whose service belongs to the client, and respect the client's notification preferences.

## 6. Refunds as account credit

- When a **paid** registration, transfer, renewal or ID Protect request ends **rejected** (Enom definitely refused it, or it never reached Enom), the paid amount is added to the client's Paymenter account credit automatically. That happens once per request (`cost.refund`). The client gets the `enom_domain_order_refunded` notification.
- Uncertain or pending requests are never refunded automatically.
- Staff action **Refund as credit** on a held, never-sent paid request: it closes the request (`rejected`, `refunded_by_staff`) and credits the client. It needs `admin.enom.manage` plus Paymenter's invoice permission; complimentary requests have nothing to refund.
- The invoice stays paid (the money was received). The credit shows on the client's account and is used by future invoices as usual.
- Contract: `Refunds::creditRejected(Operation): ?array{amount, currency}` (idempotent) and `StaffDomains::refundHeld(User $staff, int $operationId, string $reason)`. Operation display data carries `refund: {amount, currency, at}` when credited.
