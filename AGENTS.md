# Developer guide

Read [CONTRIBUTING.md](CONTRIBUTING.md) for setup and release steps. Before native integration or packaging changes, read [extension conventions](docs/paymenter-extension-conventions.md). Before running checks, read [testing](docs/testing.md).

Runtime belongs in `Enom/`. Keep Paymenter core, authentication, gateways, themes and native table definitions upstream. Preserve extension tables through upgrades and uninstall. Registrar writes require verified funding, current ownership and a resolved prior outcome. Preserve invoices, sponsorship cycles and uncertain-request history.

Install mise, system PHP 8.3 or 8.4 and Composer. Run `mise install`, `mise run bootstrap`, then `mise run test`. Bootstrap prepares `.cache/paymenter-1.5.9`; the test task sets canonical dependency paths. Use `mise run check` for fast offline checks. Every selected command must pass with runtime bytes unchanged. See CONTRIBUTING for manual setup.

Build with `mise run build`, then run `mise run check:public` with full Git history. Inspect the ZIP and member manifest in `dist/release`. Follow CONTRIBUTING's release steps to update metadata, test installation and upgrade, and prepare the version tag, archive and release notes. Keep README limitations accurate.

Use synthetic fixtures and disposable databases for development. Keep credentials and customer records out of source, fixtures, archives and logs. Use simple language and Conventional Commits.
