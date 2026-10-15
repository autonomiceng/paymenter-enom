# Paymenter extension conventions

## Native classification

The entry point is `Paymenter\Extensions\Servers\Enom\Enom`, extending `App\Classes\Extension\Server`. Its `ExtensionMeta` declares name, description, version and author. Keep metadata compatible with Paymenter's actual attribute signature; a custom licence or compatibility argument is not part of the tested signature. Declare MIT through the included licence and supported versions through release documentation.

Paymenter's v1.5.9 [ExtensionResource](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Admin/Resources/ExtensionResource.php) excludes `server` and `gateway` types. Its [Server model](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Models/Server.php) queries the native extensions table for type `server`. This explains the configuration location under Extensions → Servers. A second Others extension is unnecessary for discovery.

The extension-owned `Admin/Pages/Domains.php` supplies a discoverable native Filament page. Keep its permission check before business queries and return safe view data. Registrar credentials and buy-cost data require their own permissions and must not enter unauthorized Livewire payloads.

## ZIP and lifecycle hooks

Paymenter v1.5.9's [UploadExtensionService](https://github.com/Paymenter/Paymenter/blob/v1.5.9/app/Services/Extensions/UploadExtensionService.php) accepts a ZIP containing the extension entry point directly or inside one wrapper directory. This project's ZIP uses `Enom/`. A full `extensions/Servers/Enom/` archive nesting is too deep for that native importer.

The uploader detects the Server subclass, places files at `extensions/Servers/Enom`, and invokes native install or upgrade. `installed()` runs extension migrations and verifies the three-table schema. `upgraded()` preserves the existing data and checks the schema. `uninstalled()` retains registrations and the financial/operation ledger.

The tested uploader may pass null as the prior version and may hide lifecycle-hook failures. Verify source, schema, retained data and fresh-process behavior after native upload. Do not assume the upload notification alone proves a successful upgrade. The tested upload limit is 10 MB.

Use only extension-owned runtime files in the ZIP. Include `LICENSE`; keep tests, development dependencies, private operational scripts and application/vendor files outside it. Verify archive members against the runtime source before installation.

## Native billing and registrar ownership

Use native Product → Plan → Price configuration and one Service per managed domain. Native invoice items refer to that Service. The Enom domain record adds registrar state and links to the native billing identity; it does not create another customer subscription.

The dedicated Domains flow keeps its product hidden from ordinary Shop checkout, which cannot collect or enforce the same domain quote/contact/ownership requirements. Hidden catalogue status does not remove existing Services from their management screens.

Only registrations explicitly assigned to Enom can be observed, billed or renewed by this integration. Hosting domain properties are not registrar assignments. A future provider adapter must define cross-provider ownership and transfer handover before sharing customer domain routes. Separate navigation entries alone do not solve duplicate renewal authority.

## Tested scope

The runtime supports Paymenter 1.5.9. It owns `enom_domains`, `enom_operations` and `enom_order_intents`, without altering native table definitions or application/auth/gateway/theme files. Future Paymenter versions need ordinary compatibility testing; compatibility beyond the tested version is not asserted.

RuntimeGuard selects the test or live environment. Requests require encrypted credentials and the recorded account binding. Live requests also require the confirmed site URL. Processing is off by default.