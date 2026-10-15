# Contributing

## Set up

Install [mise](https://mise.jdx.dev/getting-started.html), Git, system PHP 8.3 or 8.4 and Composer 2. PHP needs bcmath, ctype, curl, DOM/XML (including XMLReader and XMLWriter), fileinfo, GD, intl, mbstring, OpenSSL, PDO MySQL, PDO SQLite, sodium, SQLite3, tokenizer and zip. PHP stays on the system because mise PHP support varies by platform. Python, Node and gitleaks are pinned in `mise.toml`.

```bash
mise install
mise run bootstrap
```

Bootstrap installs the locked npm dependencies with scripts disabled. It clones official [Paymenter v1.5.9](https://github.com/Paymenter/Paymenter/tree/v1.5.9) into `.cache/paymenter-1.5.9`, runs `composer install --no-scripts --no-plugins`, builds the default theme and changes only the version marker to literal `1.5.9`. The app must have no `.env` or cached configuration. Repeated bootstrap runs reuse valid dependencies and assets. Only tool installation and bootstrap need network access.

## Test

```bash
mise run test
```

The complete suite runs lint, package, provider, native and UI checks. For quick offline checks:

```bash
mise run check
```

Read [testing](docs/testing.md) for dependencies, fixtures and coverage limits. Include the commands and results in pull requests.

Without mise, use Python 3.10+, Node 22.23.1, system PHP 8.3/8.4 and Composer 2. Run `python3 scripts/bootstrap.py`, then `python3 scripts/dev.py test`. For a separately prepared pristine app, set `PAYMENTER_TEST_APP` to its canonical absolute path and `PAYMENTER_TEST_PHP` to the PHP executable, then run `python3 scripts/test.py`. Manual fast checks use `python3 scripts/test.py --suite lint --suite package --suite provider`.

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
   mise run build
   mise run check:public
   ```

4. Inspect the ZIP and `manifest.json` in `dist/release`. The archive must contain only the `Enom/` runtime and its MIT license. The build task preserves previous output under `dist/release-<timestamp>`. Public checks scan full Git history and the unpacked ZIP with gitleaks. Shallow clones must fetch full history first. Without mise, use `python3 scripts/dev.py build` and `python3 scripts/dev.py public` with gitleaks 8.30.1 on PATH. The underlying builder accepts a fresh output path with `python3 scripts/build.py --output-dir <path>` and refuses existing artifacts.
5. Test installation and retained-data upgrade in a disposable Paymenter application. Verify migrations and the installed version after upload. Record any acceptance checks still missing.
6. Create a version tag and attach the ZIP, manifest and release notes to the release when publication is authorized.

Builds do not upload or activate the extension. Back up both database and extension files before deploying. A rollback requires matching database and file backups because migrations are additive and uninstall retains data.
