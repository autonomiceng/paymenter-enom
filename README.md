# Enom for Paymenter

Sell and manage domain names through an Enom reseller account in Paymenter.
Version 0.4.0. Autonomic Engineering develops and maintains this extension under the [MIT license](LICENSE).
The extension uses Paymenter's native services, invoices and admin panel.

## Features

- Search available names and view prices without logging in. Clients can register names for 1–10 years or transfer them for one year, subject to registry limits.
- Manage contacts, nameservers, child nameservers, registrar lock and ID Protect. Enom emails transfer codes to the registrant.
- Collect registry details for endings such as `.us`. Optional DNS tools support DNS records, SRV and DNSSEC.
- Import the TLD catalogue and reseller costs. Set fixed prices, fixed markups or percentage markups. Assign standard, custom or complimentary pricing to domains.
- Issue renewal invoices before expiry and send configurable reminder emails. Refund definite paid-order failures to account credit.
- Review registrar requests in the admin panel. Requests with uncertain outcomes are checked with Enom before any further action.

## Requirements

- An unmodified Paymenter 1.5.9 installation with its PHP requirements. Other Paymenter versions are untested.
- An Enom reseller account with API access and enough account balance for orders.
- Your server's outbound IP allowed by Enom for the selected test or live environment.
- Paymenter's scheduler and queue worker. Jobs can take about five minutes; configure the queue's `retry_after` above the job timeout, for example 480 seconds.

## Install and upgrade

1. Obtain a release ZIP, or [build one from source](CONTRIBUTING.md#build-and-release).
2. Back up the database and installed extension before an upgrade.
3. Upload the ZIP through Paymenter's extension uploader. The archive contains one `Enom/` directory. Paymenter installs it under `extensions/Servers/Enom`.
4. Verify the installed version and extension tables after upload. The uploader can hide lifecycle-hook errors, so check the application logs if installation fails.

Upgrades and uninstall retain `enom_domains`, `enom_operations`, `enom_order_intents` and `enom_tlds`. Uninstall does not cancel domains or remove financial history. Restore both the database backup and matching extension files for a rollback; replacing files alone does not reverse migrations.

## Configure

1. Add an Enom server under **Extensions → Servers**. Choose **Test** and enter the Enom account ID and API token. Paymenter stores the credentials encrypted.
2. Open **Domains → Enom setup** and test the connection.
3. Open **Domains → TLDs & pricing**. Import endings and costs, set retail prices, then enable supported endings.
4. Set default nameservers and renewal settings on the server. Leave nameservers blank to use Enom DNS. Enable DNS tools only if you offer them.
5. Turn on **Process orders and changes** when configuration is complete. Configure daily status checks and renewal invoices as needed.
6. For live use, save the Live environment and confirm it under **Domains → Enom setup**. The extension binds the account and environment to the server and checks the site URL for live requests. Use a separate Enom server for another account or environment.

Grant staff `admin.enom.manage` for domain management, `admin.enom.fund` for complimentary pricing and `admin.enom.costs` for reseller costs. Staff refunds also require Paymenter's invoice permission.

## Use

Clients open **Domains**, search for a name, choose Register or Transfer, enter contact and registry details, and pay the native invoice. The queue sends the registrar request after verified payment. Complimentary orders use a paid zero-value invoice and a per-request cost limit.

Each domain has a native Paymenter Service in a hidden **Domain name** product. Clients order from the Domains pages, which collect the quote and registrar details. Staff manage domains, prices and requests from the **Domains** admin group. Changing a price affects future billing; issued invoices retain their amounts.

Renewal invoices default to 21 days before expiry, with payment due 7 days before expiry. Automatic renewals use one-year terms. Clients can choose longer terms with Renew now when renewal is enabled. Reminders default to 60, 30, 7 and 1 days before expiry and 3 days after.

## Troubleshoot

| Symptom | Check |
| --- | --- |
| Enom is missing from the general Extensions list | Open Extensions → Servers. Paymenter classifies this extension as a server. |
| Connection is refused | Check the environment, encrypted credentials, Enom IP allowlist, account binding and live-site URL. |
| Orders remain queued | Check Process orders and changes, the queue worker, scheduler and account balance. |
| A paid invoice does not fund an order | A paid status alone is insufficient. The invoice needs successful transactions covering its full amount. |
| A TLD cannot be enabled | Check all three prices, registry term limits and supported registry fields. |
| A request is uncertain | Use Check with Enom under Registrar requests. Preserve the request and invoice history; do not resend it as a new order. |
| Registration succeeded but nameservers did not change | Registration uses Enom DNS first, then sends a separate nameserver change. Check that request and the nameserver hosts. |
| A glue address is refused | Registries require a public address. Private, shared and documentation ranges are rejected. |

## Limitations

- USD only. Premium, EAP and internationalised names are unsupported.
- Transfers add one year. Scheduled and grace-period renewals use one year.
- Only choice-list and free-text registry fields are supported.
- Refunds use Paymenter account credit. External gateway refunds and chargebacks are not reconciled automatically.
- Transfer codes are emailed by Enom; the API cannot return them for display.
- Complimentary limits apply per request and optionally per client domain count. There is no account-wide spending budget.
- Grace-period renewals and incoming transfer completion lack end-to-end provider verification.
- Offline tests do not establish live payment or registrar acceptance, MariaDB concurrency, native upload acceptance or browser/mobile compatibility.
- A crash after claiming a reminder but before sending it can lose that reminder. Reminder claims do not lock the native service row.

## Development

`Enom/` contains the distributable runtime. `tests/` contains offline tests and sanitized fixtures. `scripts/` contains the test runner and ZIP builder. `docs/` contains design, integration and testing guidance.

Read [CONTRIBUTING.md](CONTRIBUTING.md), [extension conventions](docs/paymenter-extension-conventions.md) and [testing](docs/testing.md). Paymenter and development dependencies retain their own licenses; see [NOTICES](NOTICES).
