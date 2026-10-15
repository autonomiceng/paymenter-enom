# Catalogue, pricing and domain management

These contracts describe version 0.3.

## 1. Environment, credentials and settings

Server settings, grouped in this order: connection, processing, renewals, domain defaults, limits.

| Key | Label | Notes |
| --- | --- | --- |
| `environment` | Enom environment | `test` (resellertest.enom.com) or `live` (reseller.enom.com). |
| `uid` | Enom account ID | Encrypted before its first write, including on native Create. |
| `token` | API token | Same. |
| `operations_enabled` | Process orders and changes | Master switch for registrar writes. When off, work waits and is not lost. |
| `observations_enabled` | Check domain status daily | |
| `billing_enabled` | Send renewal invoices | |
| `issue_days` / `due_days` | Invoice / due days before expiry | 21 / 7. |
| `credits_auto_use` | Pay renewals from account credit | |
| `grace_renewals_enabled` | Allow renewal after expiry | Off by default. |
| `default_nameservers` | Default nameservers | 2 to 12 hostnames. If blank, Enom's default DNS is used, and the order page says so. |
| `dns_tools` | Show Enom DNS tools | Off: DNS records, SRV and DNSSEC are hidden from clients and staff. |
| `privacy_price` | ID Protect price per year | Blank hides ID Protect purchases. |
| `complimentary_cost_cap` | Complimentary cost limit (USD) | Per request. Default 50. |
| `observer_requests_per_minute` | Enom requests per minute | Advanced. |

Rules:

- Reads (search, status, prices) need credentials. Writes also need `operations_enabled`.
- **Account binding.** The first time a server takes an order, it records its account fingerprint (environment and account ID) as `enom_account_binding`. Later requests refuse if the account or environment differs: "This server's Enom account or environment changed after domains were added. Restore the original settings or add a new server." Token rotation is allowed.
- **Live site pin.** Turning on `live` records the current `APP_URL` as `enom_live_url`. Live requests refuse when the running site's URL differs, so a copied production database on staging cannot send live requests.
- **Legacy settings.** On upgrade, a missing `environment` becomes `test`. An explicit legacy `sandbox=false` forces `operations_enabled=false`. Unknown environment values refuse requests rather than falling back.
- The test-environment banner is shown only when `environment=test`.

## 2. TLD catalogue

Table `enom_tlds`: `server_id, tld, enabled, pricing_mode, markup, register/renew/transfer_price, cost_register/renew/transfer, costs_synced_at, sort_order`.

- **Import from Enom** (`GetTLDList` + `PE_GetDomainPricing`) adds every ending, disabled, with a default 30% markup.
- **Sync costs** refreshes reseller costs. Endings missing from the response keep their previous cost. Markup modes recompute retail; fixed prices are never touched. The notification reports how many prices changed.
- Modes: `fixed`, `markup_amount`, `markup_percent` (a percentage of cost). Retail rounds half-up to the cent.
- **Enabling** requires all three prices, no other server selling the same ending, no required registry attributes (`GetExtAttributes`, which blocks e.g. `.us` nexus), and a one-year minimum term. The admin table flags prices below cost.
- Disabling an ending stops new orders only. Existing domains still renew at its renewal price.
- The extension provisions one hidden native product per server: category **Domains**, product **Domain name**, yearly plan. Existing per-TLD products keep their services. The native checkout and cart guards stay.

## 3. Who pays: standard, custom, complimentary

`enom_domains.price_mode`: `null` (follow the client's default), `standard`, `custom`, `complimentary`.

Effective mode: an explicit domain mode wins. Otherwise, a complimentary client means complimentary; otherwise standard.

- **Standard**: the catalogue renewal price for the ending, tax-adjusted for the client the same way native checkout does. The service price is kept equal to it for future renewals.
- **Custom**: the service price, which must be positive.
- **Complimentary**: no charge.
- **Existing domains** are backfilled to `custom`, so every current price is preserved exactly. A zero-price legacy domain stays held for review until staff picks a mode.
- **Obligations are frozen.** An issued invoice or created request keeps its amount and funding. Changing modes affects the next cycle. Switching a domain to complimentary retires clean unpaid renewal invoices through the existing retirement path; paid or partly paid history is never altered.
- **Client grant.** The grant is stored as a raw native user property `enom_complimentary` (JSON: granted_by, granted_at, max_domains). It is only honoured when no CustomProperty uses that key, so clients cannot submit it. Granting and revoking need `admin.enom.fund`. The optional `max_domains` limits how many complimentary domains a client can hold.
- **Scope.** Complimentary covers registration, transfer and scheduled renewal. Clients cannot request manual complimentary renewals; staff can. ID Protect is always invoiced.
- **Cost cap.** Before sending, a complimentary request needs a fresh Enom price at or under `complimentary_cost_cap`; otherwise it is held. The actual charge is recorded afterwards, and an overrun pauses automatic spending for that domain. The cap applies to the quote, not the final charge.
- **Dispatch boundary.** After a request is sent, revoking complimentary status does not stop its outcome from being recorded.
- **Native lifecycle.** A complimentary order gets a paid $0.00 native invoice, marked "complimentary". This keeps Paymenter's unpaid-order cancellation from cancelling slow transfers, and gives the client a record. The registrar request itself carries `funding_basis=complimentary` with no invoice binding.

## 4. Service contract for the UI

Client side (`DomainController` + views):

- `DomainOrders::check(User, string $name): array` — availability and price for one name. The search page calls it per ending, a few at a time, and stops after 12 endings.
- `DomainOrders::quote(User, string $domain, 'register'|'transfer'): array`, then `pendingQuote`, then `place(User, $intentId, array $contacts, ?string $epp, array $nameservers): Invoice|Domain`. A `Domain` result means a complimentary order was queued.
- `DomainManager::snapshot(User, int $id): array` — the domain page model. Additions: `price` (mode, source, amount), `default_nameservers`, `dns_tools`, `test_environment`, `registrant_email_masked`, `epp_email_available_at`.
- `DomainManager::request(User, int $id, string $action, array $payload)` — actions: `nameservers`, `contacts` (`contact_type` may be `all`), `lock`, `epp_email`, `privacy_enable`, `privacy_disable`, plus `hosts`, `srv`, `dnssec_add` and `dnssec_delete` only when DNS tools are on.

Staff side (Filament, `Services/StaffDomains.php`):

- `requestChange(User $staff, int $domainId, string $action, array $payload): Operation` — same actions, recorded as staff.
- `renewNow(User $staff, int $domainId): Invoice|Operation`.
- `setPricing(User $staff, int $domainId, string $mode, ?string $price)`.
- `setClientComplimentary(User $staff, User $client, bool $on, ?int $maxDomains)`.
- `testConnection(int $serverId): array{ok, environment, balance?, message}`.
- `Catalogue::import/syncCosts/enable`, `DomainOperations::reconcile/readmitHeld/observeProviderFact/disposeProviderFact` (existing).

## 5. Admin (Filament, navigation group Domains)

- **Domains** resource: domain, client, status, expiry, renews (on/off), who pays and last check. Search by domain and client email or name. Filters: status, expiring within 7/30/60/90 days, expired, complimentary, renewal off, needs attention. Default sort by expiry. Globally searchable. The view page has overview, registrar, billing and activity sections, with staff actions.
- **TLDs & pricing** resource with import and sync actions, and bulk enable/disable/markup.
- **Registrar requests** resource. Actions: "Check with Enom" (reconcile, never resends), "Resume unsent request" (only where dispatch evidence proves it was never sent) and "Record outcome" (disposition). Uncertain requests are reconcile-only.
- **Enom setup** page: environment, connection test, balance, checklist, binding status.
- Every action checks its own permission: manage, `admin.enom.fund` for complimentary, `admin.enom.costs` for costs. Unsupported create/delete actions are denied.
- Old `/admin/enom*` client-layout pages redirect into Filament.

## 6. Client area

- **My domains**: a table with domain, status, expiry, auto-renew and Manage. Search, status filter, sort by name or expiry, pagination.
- **Register**: enter a name and see the enabled endings with availability and the yearly price. Endings that aren't enabled can't be entered.
- **Order**: contact prefilled from the account profile, with "Use this contact for all roles" checked. The default nameservers are shown with a "Use different nameservers" option.
- **Domain page**:
  - Overview: status, expiry date, renewal on/off with what that means, renew now, price.
  - Nameservers: current list, "Use default nameservers", validation.
  - Contacts: one contact when all roles match, otherwise per role; "Apply to all roles" is an explicit choice.
  - Transfer: lock switch, "Email transfer code" (shows the masked registrant address, 10-minute cooldown), and transfer-out guidance.
  - ID Protect and activity. DNS tools appear only when enabled.
- **Dates**: calendar dates such as expiry and due dates display as dates without time-zone conversion. Timestamps such as last check and request times display in the viewer's local zone through `<time datetime>` and a small script.

## 7. Transfer codes

Enom cannot return an auth code through its API. `SynchAuthInfo EmailEPP=True` generates one and emails it to the registrant. The UI says "Enom sent the transfer code to j•••@example.com" once Enom accepts the request; delivery is not claimed. The parser accepts the email-specific response.

## Scope at version 0.3

Multi-year terms, IDN/premium names, a cart, glue records, URL/email forwarding, refund/chargeback accounting, customer notification emails for blocked requests, fair-queue observation scheduling, and account-wide complimentary spending budgets beyond per-request caps and domain counts. See the README for support and limitations.
