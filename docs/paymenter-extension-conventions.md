# Paymenter extension conventions

## Native classification

The entry point is `Paymenter\Extensions\Servers\Enom\Enom`, extending `App\Classes\Extension\Server`. Its `ExtensionMeta` declares name, description, version and author. Keep metadata compatible with Paymenter's actual attribute signature; a custom licence or compatibility argument is not part of the tested signature. Declare MIT through the included licence and supported versions through release documentation.

Paymenter's v1.5.9 [ExtensionResource](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Admin/Resources/ExtensionResource.php) excludes `server` and `gateway` types. Its [Server model](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Models/Server.php) queries the native extensions table for type `server`. This explains the configuration location under Extensions → Servers. A second Others extension is unnecessary for discovery.

Paymenter's `AdminPanelProvider` discovers `Admin/Resources` and `Admin/Pages` for server extensions. The extension ships the Domains, TLDs & pricing and Registrar requests resources and the Enom setup page there, under a **Domains** navigation group. Paymenter does not enable Filament strict authorization, so each model has a policy in `Policies/` (registered from `Enom::boot()`), and every action re-checks its permission: `admin.enom.manage`, `admin.enom.fund` for complimentary authority and `admin.enom.costs` for Enom costs. Costs are hidden from model serialisation and must not enter Livewire state for users without the costs permission. The Domains resource is the only route into Paymenter's admin global search; core offers no search hook.

## ZIP and lifecycle hooks

Paymenter v1.5.9's [UploadExtensionService](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Services/Extensions/UploadExtensionService.php) accepts a ZIP containing the extension entry point directly or inside one wrapper directory. This project's ZIP uses `Enom/`. A full `extensions/Servers/Enom/` archive nesting is too deep for that native importer.

The uploader detects the Server subclass, places files at `extensions/Servers/Enom`, and invokes native install or upgrade. `installed()` runs extension migrations and verifies the four-table schema. `upgraded()` preserves the existing data and checks the schema. `uninstalled()` retains registrations and the financial/operation ledger.

The tested uploader may pass null as the prior version and may hide lifecycle-hook failures. Verify source, schema, retained data and fresh-process behavior after native upload. Do not assume the upload notification alone proves a successful upgrade. The tested upload limit is 10 MB.

Use only extension-owned runtime files in the ZIP. Include `LICENSE`; keep tests, development dependencies, private operational scripts and application/vendor files outside it.

Paymenter only boots server extensions that already have a server row. Anything that must run during the first server's native Create (the credential-encryption hook) is also registered from `getConfig()`.

## Native billing and registrar ownership

Each domain is one native Service in a hidden **Domain name** product that the extension provisions per Enom server (category **Domains**, yearly plan, marked by the product setting `enom_catalogue`). Prices come from the `enom_tlds` catalogue and the domain's who-pays mode, and are written to the Service price; issued invoices keep their own amounts. The Enom domain record adds registrar state and links to the native billing identity; it does not create another customer subscription.

The product stays hidden because ordering needs domain search, contacts and a bound quote, which native checkout cannot collect. The checkout and cart guards refuse direct native orders. Complimentary orders get a paid $0.00 native invoice so Paymenter's unpaid-order cleanup leaves slow transfers alone.

Only registrations explicitly assigned to Enom can be observed, billed or renewed by this integration. Hosting domain properties are not registrar assignments. A future provider adapter must define cross-provider ownership and transfer handover before sharing customer domain routes. Separate navigation entries alone do not solve duplicate renewal authority.

## Tested scope

The runtime is tested against official Paymenter 1.5.9. It owns `enom_domains`, `enom_operations`, `enom_order_intents` and `enom_tlds`, without altering native table definitions or application/auth/gateway/theme files. The complimentary client grant is a raw native user property (`enom_complimentary`), not a CustomProperty. Future Paymenter versions need ordinary compatibility testing.

`Services/RuntimeGuard.php` selects Enom's test or live endpoint from the server's `environment` setting. It refuses unencrypted credentials, an account or environment that differs from the server's recorded binding, and live requests from any site URL other than the one that confirmed live mode. Writes also need **Process orders and changes**; reads only need credentials. Processing is off by default.
