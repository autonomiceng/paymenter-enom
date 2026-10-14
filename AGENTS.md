# Developer guide

Read [CONTRIBUTING.md](CONTRIBUTING.md) for setup and release steps. Before native integration or packaging changes, read [extension conventions](docs/paymenter-extension-conventions.md). Before running checks, read [testing](docs/testing.md).

Runtime belongs in `Enom/`. Keep Paymenter core, authentication, gateways, themes and native table definitions upstream. Preserve extension tables through upgrades and uninstall. Registrar writes require verified funding, current ownership and a resolved prior outcome. Preserve invoices, sponsorship cycles and uncertain-request history.

Set up PHP 8.3 or 8.4, Python 3.10+ and Node 18+. Run `npm ci --ignore-scripts`. Prepare a pristine Paymenter 1.5.9 checkout with Composer dependencies, no `.env` and no cached configuration. Set `PAYMENTER_TEST_APP` to its canonical absolute path and `PAYMENTER_TEST_PHP` to the PHP executable. Run `python3 scripts/test.py` for the full suite; every selected command must pass with runtime bytes unchanged.

Build with `python3 scripts/build.py --output-dir dist/release` using a fresh directory. Inspect the ZIP and member manifest. Follow CONTRIBUTING's build and release steps to update metadata, test installation and upgrade, and prepare the version tag, archive and release notes. Keep README limitations accurate.

Use synthetic fixtures and disposable databases for development. Keep credentials and customer records out of source, fixtures, archives and logs. Use simple language and Conventional Commits.
