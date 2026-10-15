# Offline development tests

Use PHP 8.3 or 8.4, Python 3.10+, and Node 18+ with the pinned local development dependency:

```bash
npm ci --ignore-scripts
python3 scripts/test.py --suite lint --suite package --suite provider
```

The native and UI suites require an explicit pristine Paymenter 1.5.9 development release with its Composer dependencies. Set `PAYMENTER_TEST_APP` to its canonical absolute path. The guard checks the Paymenter Composer identity and literal `config/app.php` version. It must have no `.env` or cached configuration and cannot be an installed `/var/www/` application. No dependency download occurs during tests.

For an upstream source checkout, prepare a disposable copy as described in [CONTRIBUTING](../CONTRIBUTING.md#set-up). The Git tag uses a `development` version marker, and the source needs its theme assets built. The test copy must have a literal `1.5.9` marker and `public/default/manifest.json`.

```bash
export PAYMENTER_TEST_APP=/absolute/path/to/pristine-paymenter-1.5.9
export PAYMENTER_TEST_PHP=php
python3 scripts/test.py
```

The runner gives each child a small environment, blocks PHP URL streams and network transport functions, and checks that every runtime byte remains unchanged after each command. JavaScript tests use local jsdom and synthetic fetch/clipboard/DOM boundaries. Real provider responses are simulated. SQLite integration tests use new disposable application directories and in-memory databases. Reports and failed outputs stay under `.scratch/tests-*`.

Omitting `--suite` runs lint, package, provider, native and UI checks. Missing dependencies or failed suites return a nonzero exit code. Selecting fewer suites records the omitted suites explicitly; it does not establish their coverage. `provider` includes provider response contracts, money normalization, contact readbacks and pure input contracts. `native` covers native billing, ownership, registrar lifecycle, admission and funding gates, plus the 0.4 features: multi-year terms (`registrar-terms.php`, multi-year admission in `renewal-admission.php`), registry details (`registry-details.php`), child nameservers (`glue-records.php`), refunds to account credit (`refunds.php`) and renewal reminders (`reminders.php`). `ui` covers the client area through real routes and middleware (`client-controller.php`), every client view and its escaping (`client-views.php`), staff policies, `StaffDomains` permissions, the legacy `/admin/enom` redirects and the Filament pages (`admin-staff.php`), the logged-out search, price list and per-address check limit (`public-search.php`), and the client script in jsdom (`client-script.cjs`).

The portable fixtures are synthetic or sanitized protocol shapes. The DNSSEC fixture carries a public DS tuple without a domain/account identifier; the Purchase XML uses synthetic IDs/dates. `tests/fixtures/enom-test-api/` holds trimmed copies of Enom TEST API catalogue responses (`GetTLDList`, `PE_GetDomainPricing`, `GetExtAttributes`, `GetTLDDetails`, `GetBalance`) with tracking keys, request times and balances removed. Endings without their own `GetTLDDetails` capture reuse the `.com` shape under their own name. The `.us` `GetExtAttributes` capture keeps its three fields with the country list cut to four real entries. The child-nameserver captures (`RegisterNameServer`, `UpdateNameServer`, `DeleteNameServer`, a refused IPv6 registration and `CheckNSStatus` for a missing host) use a placeholder domain and address. `glue-records.php` uses a synthetic successful `CheckNSStatus` response.

`tests/public-addresses.php` generates IPv4 and IPv6 inputs for successful glue validation without storing real server addresses. CIDR boundary tests derive adjacent addresses from blocked ranges. All requests use injected transports; these addresses are never contacted.

New native tests share `tests/enom-native-app.php` (disposable app with the extension booted) and `tests/enom-domain-page.php` (renders the client domain page through `DomainController::show()` and lists its form targets). The harness boots the extension after the application, so tests that render notifications call `refreshNameLookups()` to resolve the extension's named routes. A `knownBug()` check records a known failure when the expected behavior is absent. It prints `KNOWN RUNTIME BUG <id>` to stderr and includes the failure in the summary without stopping the suite. Passing checks count toward the total.

UI tests add three helpers. `tests/enom-http.php` sends requests through the native HTTP kernel with Paymenter's own session login, per-user cookie jars (named guest jars and client addresses through the `jar` and `ip` options), real form keys and optional production CSRF checking; plain `throttle` routes share one per-user bucket, so long flows call `enomResetThrottle()`. `tests/enom-client-fixture.php` provides one Enom server, a catalogue with Enom costs that must never reach clients, and an injected `Check`-only transport. `tests/enom-admin-panel.php` registers the extension's Filament resources and pages on the admin panel (panel discovery runs before the test installs the extension) and renders them with `Livewire::test()`. Without PHP `intl`, a one-line view replaces Filament's pagination, and the TLD table, whose bulk-selection counter needs `intl`, is checked through its table definition instead of rendered HTML.

This repository's passing local checks do not claim native upload acceptance, MariaDB concurrency, actual browser/mobile behavior, real registrar writes, payment acceptance, production compatibility, or compatibility beyond Paymenter 1.5.9.

Build a fresh native package with:

```bash
python3 scripts/build.py --output-dir dist/release
```

The builder reads only `Enom/`, requires its MIT `LICENSE`, and produces a single `Enom/` ZIP wrapper plus a member-hash manifest. Tests, docs, dependencies and private files cannot enter it. It uses fixed timestamps, order, permissions and compression settings, verifies archive readback and refuses existing artifacts. Reproducibility is checked within the same Python/zlib environment. Building never uploads or activates the extension.
