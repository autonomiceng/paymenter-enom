# Enom for Paymenter

An MIT-licensed Enom registrar extension for Paymenter 1.5.9 by Autonomic Engineering.
This development version manages domains through native services, invoices and registrar requests.

## Development

Install PHP 8.3 or 8.4, Python 3.10+ and Node 18+, then run `npm ci --ignore-scripts`.
For the full suite, set `PAYMENTER_TEST_APP` to a pristine Paymenter 1.5.9 checkout with Composer dependencies, no `.env` and no cached configuration. Set `PAYMENTER_TEST_PHP` to your PHP executable and run `python3 scripts/test.py`.

Build the native ZIP with `python3 scripts/build.py --output-dir dist/release` in a fresh output directory. Paymenter installs the `Enom/` wrapper as a Server extension. Test installation and registrar operations in a disposable application before deployment.

See [CONTRIBUTING.md](CONTRIBUTING.md), [testing](docs/testing.md) and [extension conventions](docs/paymenter-extension-conventions.md). Paymenter and development dependencies retain their own licenses. The extension uses [MIT](LICENSE).
