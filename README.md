# Enom for Paymenter

Sell and manage domain names through an Enom reseller account in Paymenter.
Autonomic Engineering maintains this extension under the [MIT license](LICENSE).
It uses Paymenter's native services, cart, invoices and admin panel.

## Requirements

- An unmodified Paymenter 1.5.9 installation with PHP 8.3 or 8.4. Other versions are untested.
- An Enom reseller account with API access, sufficient balance and the server's outbound IP allowed for the selected environment.
- Paymenter's scheduler and queue worker. Set queue `retry_after` above the job timeout; 480 seconds is suitable.

## Install

1. Obtain a release ZIP or [build from source](CONTRIBUTING.md#build-and-release).
2. Back up the database and installed extension before replacing an installation.
3. Upload the ZIP through Paymenter's extension uploader. Its single `Enom/` directory installs under `extensions/Servers/Enom`.
4. Verify the installed version and three extension tables. The uploader can hide hook failures; check application logs.

This release requires a fresh installation. It contains no conversion for earlier development schemas.
Uninstall retains domain and financial history. Rollback requires matching database and extension-file backups.

## Configure

1. Add Enom under **Extensions → Servers**. Choose **Test** and save the account ID and API token; credentials are encrypted.
2. Open **Domains → Enom setup** and test the connection.
3. Under **Domains → TLDs & pricing**, import endings and costs, configure retail rules and enable supported endings.
4. Set nameservers and renewal settings. Blank nameservers use Enom DNS. Enable DNS tools only when needed.
5. Enable **Process orders and changes** when ready. For live use, save Live and confirm it in Enom setup; requests are bound to that account, environment and site URL.

Use a separate server for another account or environment. Grant `admin.enom.manage` for management, `admin.enom.fund` for complimentary renewals and `admin.enom.costs` for reseller costs. Staff refunds also require Paymenter's invoice permission.

## Use

Clients search under **Domains** and open native checkout for registration or transfer. Domains and hosting can share one cart and invoice. Registration supports priced terms from 1–10 years within registry limits; transfers add one year. Every domain line must remain positive after coupons, and successful payments must cover the entire invoice before dispatch.

Contacts come from the account. Invalid details require explicit recovery on the domain page. Transfer codes are saved there before or after payment. A paid transfer missing its code waits up to 30 days; a proven unsent expiry refunds that line once to account credit.

Manage contacts, nameservers, child nameservers, lock and ID Protect on the domain page. Enom emails outgoing transfer codes to the registrant. Optional DNS tools support DNS records, SRV and DNSSEC.

Renewal invoices default to 21 days before expiry and are due 7 days before expiry. Scheduled renewals use one year. Staff can approve per-domain complimentary renewals subject to a cost cap. ID Protect is always invoiced. Review held and uncertain work under **Registrar requests**; uncertain requests require reconciliation with Enom.

Cart domain labels and hosting offers require the [custom-theme handoff](docs/design/0.5-theme-handoff.md).

## Limitations and development

USD only; premium, EAP and internationalised names are unsupported. Registry fields support choice lists and free text. Refunds use account credit; gateway refunds and chargebacks need separate handling. Complimentary limits apply per request, with no account-wide budget.

Grace-period renewals and incoming transfer completion lack end-to-end provider verification. See [test coverage limits](docs/testing.md) and [lifecycle contracts](docs/design/domain-lifecycle.md) before deployment.

For setup, checks and release commands, read [CONTRIBUTING](CONTRIBUTING.md). Contributors should also read [extension conventions](docs/paymenter-extension-conventions.md). Dependencies retain their own licenses; see [NOTICES](NOTICES).
