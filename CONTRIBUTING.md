# Contributing

## Set up

Use PHP 8.3 or 8.4, Python 3.10+ and Node 18+. PHP needs SQLite, XML and mbstring for the offline tests, plus Paymenter's normal extensions for native integration.

Install the development dependency:

```bash
npm ci --ignore-scripts
```

Prepare a separate Paymenter 1.5.9 release with its locked Composer dependencies. Run `composer install --no-scripts --no-plugins` there. The guard requires the literal version `1.5.9` in `config/app.php`. The upstream Git tag uses `development`; when using that source, change only the version marker in a disposable test copy. Keep the copy free of `.env` and cached configuration. Set `PAYMENTER_TEST_APP` to its canonical absolute path and `PAYMENTER_TEST_PHP` to the PHP executable.

When using the upstream source checkout, build its theme assets in that disposable copy:

```bash
npm ci --ignore-scripts
npm run build
```

Use a Node version supported by the upstream lock file; Node 22.22.3 was used for this setup. Confirm `public/default/manifest.json` exists before running the full suite. A packaged release may already include these assets.

## Test

```bash
export PAYMENTER_TEST_APP=/absolute/path/to/pristine-paymenter-1.5.9
export PAYMENTER_TEST_PHP=php
python3 scripts/test.py
```

The complete suite runs lint, package, provider, native and UI checks. For quick offline checks:

```bash
python3 scripts/test.py --suite lint --suite package --suite provider
```

Read [testing](docs/testing.md) for dependencies, fixtures and coverage limits. Include the commands and results in pull requests.

## Change code

Read [extension conventions](docs/paymenter-extension-conventions.md) before changing native integration or packaging. Put runtime code in `Enom/`. Keep Paymenter core, authentication, gateways, themes and native schema upstream. The extension retains its own tables and retains them during upgrades and uninstall.

Use native services and invoices. Registrar writes require verified funding, current ownership and a resolved prior outcome. Preserve invoices, sponsorship cycles and uncertain-request history. Use encrypted credentials and bounded provider responses. Keep API tokens, transfer codes, contact payloads and reseller costs out of unauthorized views and logs.

Cover financial changes with unpaid, partially paid and settled invoices, cancellation, changed prices and uncertain outcomes. Check UI permissions, escaping, retained input, keyboard access and narrow layouts. Tests use injected provider responses and disposable databases.

Use simple language in docs, UI text and comments. Use Conventional Commits, for example `fix(validation): retain domain input after rejection`. Keep attribution and dependency licenses accurate.

## Build and release

1. Update the literal version in `Enom/Enom.php`, `package.json` and the root package entry in `package-lock.json`. Update the README and release notes with features and known limitations.
2. Run the complete test suite above.
3. Build into a fresh directory:

   ```bash
   python3 scripts/build.py --output-dir dist/release
   ```

4. Inspect the ZIP and `manifest.json`. The archive must contain only the `Enom/` runtime and its MIT license. The builder verifies each member against source and refuses to overwrite artifacts.
5. Test installation and retained-data upgrade in a disposable Paymenter application. Verify migrations and the installed version after upload. Record any acceptance checks still missing.
6. Create a version tag and attach the ZIP, manifest and release notes to the release when publication is authorized.

Builds do not upload or activate the extension. Back up both database and extension files before deploying. A rollback requires matching database and file backups because migrations are additive and uninstall retains data.
