# Paymenter extension conventions

## Native classification

The entry point is `Paymenter\Extensions\Servers\Enom\Enom`, extending `App\Classes\Extension\Server`. Its `ExtensionMeta` declares name, description, version and author. Keep metadata compatible with Paymenter's actual attribute signature; a custom licence or compatibility argument is not part of the tested signature. Declare MIT through the included licence and supported versions through release documentation.

Paymenter's v1.5.9 [ExtensionResource](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Admin/Resources/ExtensionResource.php) excludes `server` and `gateway` types. Its [Server model](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Models/Server.php) queries the native extensions table for type `server`. This explains the configuration location under Extensions → Servers. A second Others extension is unnecessary for discovery.

Paymenter's `AdminPanelProvider` discovers `Admin/Resources` and `Admin/Pages` for server extensions. The extension ships the Domains, TLDs & pricing and Registrar requests resources and the Enom setup page there, under a **Domains** navigation group. Paymenter does not enable Filament strict authorization, so each model has a policy in `Policies/` (registered from `Enom::boot()`), and every action re-checks its permission: `admin.enom.manage`, `admin.enom.fund` for complimentary authority and `admin.enom.costs` for Enom costs. Costs are hidden from model serialisation and must not enter Livewire state for users without the costs permission. The Domains resource is the only route into Paymenter's admin global search; core offers no search hook.

## ZIP and lifecycle hooks

Paymenter v1.5.9's [UploadExtensionService](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Services/Extensions/UploadExtensionService.php) accepts a ZIP containing the extension entry point directly or inside one wrapper directory. This project's ZIP uses `Enom/`. A full `extensions/Servers/Enom/` archive nesting is too deep for that native importer.

The uploader detects the Server subclass, places files at `extensions/Servers/Enom`, and invokes native install or upgrade. `installed()` runs the single fresh-install migration and verifies `enom_domains`, `enom_operations` and `enom_tlds`, including their uniqueness constraints. The nullable unique `reservation_name` reserves a current name while retaining historical Domain rows. `upgraded()` repeats install/template/schema checks; it contains no earlier-schema conversion. `uninstalled()` retains registrations and the financial/operation ledger.

The tested uploader may pass null as the prior version and may hide lifecycle-hook failures. Verify source, schema, retained data and fresh-process behavior after native upload. Do not assume the upload notification alone proves a successful upgrade. The tested upload limit is 10 MB.

Use only extension-owned runtime files in the ZIP. Include `LICENSE`; keep tests, development dependencies, private operational scripts and application/vendor files outside it.

Paymenter only boots server extensions that already have a server row. Anything that must run during the first server's native Create (the credential-encryption hook) is also registered from `getConfig()`.

## Native billing and registrar ownership

The [ordering contract](design/ordering.md) owns product/plan identity, transactional InvoiceItem binding, frozen first-term evidence and full-invoice funding. Managed services keep null native expiry; registrar success comes from confirmed readback. [Lifecycle](design/domain-lifecycle.md) owns renewals and uncertain requests; [catalogue and pricing](design/catalogue-and-pricing.md) owns exact-term costs and native prices.

Only registrations explicitly assigned to Enom can be observed, billed or renewed here. Hosting domain properties are not registrar assignments. A future provider adapter must define ownership and transfer handover before sharing customer domain routes; navigation alone cannot prevent duplicate renewal authority.

## Tested scope

The runtime is tested against official Paymenter 1.5.9. It owns `enom_domains`, `enom_operations` and `enom_tlds`, without altering native table definitions or application/auth/gateway/theme files. Customer properties cannot grant complimentary domain orders or custom prices. Future Paymenter versions need ordinary compatibility testing.

`Api/RegistrarClient.php` selects the test or live transport endpoint. `Services/RuntimeGuard.php` validates configuration and refuses unencrypted credentials, an account or environment that differs from the server's recorded binding, and live requests from any site URL other than the one that confirmed live mode. Writes also need **Process orders and changes**. Processing is off by default.
