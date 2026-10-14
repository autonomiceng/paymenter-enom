# Offline development tests

Use PHP 8.3 or 8.4, Python 3.10+, and Node 18+ with the pinned local development dependency:

```bash
npm ci --ignore-scripts
python3 scripts/test.py --suite lint --suite package --suite provider
```

The native and UI suites require an explicit pristine Paymenter 1.5.9 development release with its Composer dependencies. Set `PAYMENTER_TEST_APP` to its canonical absolute path. The guard checks the Paymenter Composer identity and literal `config/app.php` version. It must have no `.env` or cached configuration and cannot be an installed `/var/www/` application. No dependency download occurs during tests.

```bash
export PAYMENTER_TEST_APP=/absolute/path/to/pristine-paymenter-1.5.9
export PAYMENTER_TEST_PHP=php
python3 scripts/test.py
```

The runner gives each child a small environment, blocks PHP URL streams and network transport functions, and checks that every runtime byte remains unchanged after each command. JavaScript tests use local jsdom and synthetic fetch/clipboard/DOM boundaries. Real provider responses are simulated. SQLite integration tests use new disposable application directories and in-memory databases. Reports and failed outputs stay under `.scratch/tests-*`.

Omitting `--suite` runs lint, package, provider, native and UI checks. Missing dependencies or failed suites return a nonzero exit code. Selecting fewer suites records the omitted suites explicitly; it does not establish their coverage. `provider` includes provider response contracts, money normalization, contact readbacks and pure input contracts. `native` covers native billing, ownership, registrar lifecycle, admission and funding gates. `ui` covers native requests/DTOs, Blade compilation, the Filament hub, validation and jsdom interactions.

The portable fixtures are synthetic or sanitized protocol shapes. The DNSSEC fixture carries a public DS tuple without a domain/account identifier; the Purchase XML uses synthetic IDs/dates. `tests/fixtures/enom-test-api/` holds trimmed copies of Enom TEST API catalogue responses (`GetTLDList`, `PE_GetDomainPricing`, `GetExtAttributes`, `GetTLDDetails`, `GetBalance`) with tracking keys, request times and balances removed. Three source-only 0.2.4 Blade fixtures support the retained presentation regression comparisons.

New native tests share `tests/enom-native-app.php` (disposable app with the extension booted) and `tests/enom-domain-page.php` (renders the client domain page through `DomainController::show()` and lists its form targets). A `knownBug()` check records a known failure when the expected behavior is absent. It prints `KNOWN RUNTIME BUG <id>` to stderr and includes the failure in the summary without stopping the suite. Passing checks count toward the total.

The harness registers Filament namespaces for Blade compilation. Domain validation tests require unsupported snowman IDN input to fail before any provider request.

This repository's passing local checks do not claim native upload acceptance, MariaDB concurrency, actual browser/mobile behavior, real registrar writes, payment acceptance, production compatibility, or compatibility beyond Paymenter 1.5.9.

Build a fresh native package with:

```bash
python3 scripts/build.py --output-dir dist/release
```

The builder reads only `Enom/`, requires its MIT `LICENSE`, and produces a single `Enom/` ZIP wrapper plus a member-hash manifest. Tests, docs, dependencies and private files cannot enter it. It uses fixed timestamps, order, permissions and compression settings, verifies archive readback and refuses existing artifacts. Reproducibility is checked within the same Python/zlib environment. Building never uploads or activates the extension.
